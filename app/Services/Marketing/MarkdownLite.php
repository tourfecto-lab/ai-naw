<?php

/**
 * Tourfecto - تحويل Markdown بسيط لـ HTML آمن (لمقالات المدونة)
 *
 * كل النص بيتعمله escape الأول، وبعدين بس الصيغ المسموحة بتتحوّل:
 * # عناوين، **عريض**، *مائل*، `كود`، [لينك](https://...)، ![صورة](https://...)،
 * قوائم (- أو 1.)، > اقتباس، --- فاصل. اللينكات والصور لازم تبدأ بـ http(s) أو / (مفيش javascript:).
 */
class MarkdownLite
{
    public static function toHtml(string $md): string
    {
        $md = str_replace(["\r\n", "\r"], "\n", $md);
        $lines = explode("\n", $md);
        $out = [];
        $para = [];
        $list = null; // 'ul' | 'ol'
        $items = [];
        $quote = [];

        $flushPara = function () use (&$para, &$out) {
            if ($para) {
                $out[] = '<p>' . implode('<br>', array_map([self::class, 'inline'], $para)) . '</p>';
                $para = [];
            }
        };
        $flushList = function () use (&$list, &$items, &$out) {
            if ($list) {
                $out[] = '<' . $list . '>' . implode('', array_map(fn ($i) => '<li>' . self::inline($i) . '</li>', $items)) . '</' . $list . '>';
                $list = null;
                $items = [];
            }
        };
        $flushQuote = function () use (&$quote, &$out) {
            if ($quote) {
                $out[] = '<blockquote>' . implode('<br>', array_map([self::class, 'inline'], $quote)) . '</blockquote>';
                $quote = [];
            }
        };
        $flushAll = function () use ($flushPara, $flushList, $flushQuote) {
            $flushPara();
            $flushList();
            $flushQuote();
        };

        foreach ($lines as $raw) {
            $line = rtrim($raw);
            $t = trim($line);
            if ($t === '') {
                $flushAll();
                continue;
            }
            if (preg_match('/^(#{1,3})\s+(.+)$/u', $t, $m)) {
                $flushAll();
                $lvl = strlen($m[1]) + 1; // # → h2 (العنوان الرئيسي h1 هو عنوان المقال)
                $out[] = "<h{$lvl}>" . self::inline($m[2]) . "</h{$lvl}>";
                continue;
            }
            if (preg_match('/^(-{3,}|\*{3,})$/', $t)) {
                $flushAll();
                $out[] = '<hr>';
                continue;
            }
            if (preg_match('/^>\s?(.*)$/u', $t, $m)) {
                $flushPara();
                $flushList();
                $quote[] = $m[1];
                continue;
            }
            if (preg_match('/^[-*•]\s+(.+)$/u', $t, $m)) {
                $flushPara();
                $flushQuote();
                if ($list !== 'ul') {
                    $flushList();
                    $list = 'ul';
                }
                $items[] = $m[1];
                continue;
            }
            if (preg_match('/^\d+[.)]\s+(.+)$/u', $t, $m)) {
                $flushPara();
                $flushQuote();
                if ($list !== 'ol') {
                    $flushList();
                    $list = 'ol';
                }
                $items[] = $m[1];
                continue;
            }
            if (preg_match('/^!\[([^\]]*)\]\(([^)\s]+)\)$/u', $t, $m) && self::safeUrl($m[2])) {
                $flushAll();
                $out[] = '<figure><img src="' . self::e($m[2]) . '" alt="' . self::e($m[1]) . '" loading="lazy"></figure>';
                continue;
            }
            $flushList();
            $flushQuote();
            $para[] = $t;
        }
        $flushAll();
        return implode("\n", $out);
    }

    /** تنسيق جوه السطر - النص بيتعمله escape الأول */
    public static function inline(string $s): string
    {
        // نطلّع اللينكات والكود قبل الـ escape عشان نتحكم فيهم بأمان
        $tokens = [];
        $s = preg_replace_callback('/`([^`]+)`/u', function ($m) use (&$tokens) {
            $tokens[] = '<code>' . self::e($m[1]) . '</code>';
            return "\x01" . (count($tokens) - 1) . "\x02";
        }, $s);
        $s = preg_replace_callback('/!?\[([^\]]+)\]\(([^)\s]+)\)/u', function ($m) use (&$tokens) {
            if (!self::safeUrl($m[2])) {
                return $m[1];
            }
            if ($m[0][0] === '!') {
                $tokens[] = '<img src="' . self::e($m[2]) . '" alt="' . self::e($m[1]) . '" loading="lazy">';
            } else {
                $ext = preg_match('#^https?://#i', $m[2]) && strpos($m[2], self::host()) === false;
                $tokens[] = '<a href="' . self::e($m[2]) . '"' . ($ext ? ' target="_blank" rel="noopener nofollow"' : '') . '>' . self::e($m[1]) . '</a>';
            }
            return "\x01" . (count($tokens) - 1) . "\x02";
        }, (string) $s);
        $s = self::e((string) $s);
        $s = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s);
        $s = preg_replace('/(?<![\*\w])\*(?!\s)(.+?)(?<!\s)\*(?![\*\w])/u', '<em>$1</em>', (string) $s);
        return (string) preg_replace_callback('/\x01(\d+)\x02/', fn ($m) => $tokens[(int) $m[1]] ?? '', (string) $s);
    }

    public static function safeUrl(string $u): bool
    {
        return (bool) preg_match('#^(https?://[^\s"<>]+|/[^\s"<>]*)$#i', $u) && strpos($u, '//') !== 0;
    }

    /** نص عادي من الـ Markdown (للوصف ووقت القراءة) */
    public static function plain(string $md): string
    {
        $s = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', $md);
        $s = preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', (string) $s);
        $s = preg_replace('/[#>*`_-]+/u', ' ', (string) $s);
        return trim((string) preg_replace('/\s+/u', ' ', (string) $s));
    }

    private static function host(): string
    {
        return (string) parse_url(defined('APP_URL') ? (string) APP_URL : 'https://tourfecto.pro', PHP_URL_HOST);
    }

    private static function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}

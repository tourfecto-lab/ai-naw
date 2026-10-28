<?php

/**
 * Tourfecto - فحص ما قبل النشر (Pre-flight) للوسائط والنص.
 *
 * بيمنع أغلب أخطاء "media download failed" قبل ما نكلّم Meta:
 *  - https فقط، ومش عنوان داخلي (حماية SSRF)
 *  - HEAD على الرابط: 200، نوع الملف، الحجم
 *  - robots.txt: Meta بترفض تسحب ملفات من مواقع بتمنعها
 *  - حدود الكابشن (فيسبوك 63,206 / إنستجرام 2,200 و30 هاشتاج)
 * أخطاء الفحص = فشل نهائي بسبب واضح (مش uncertain). مشاكل الشبكة في الفحص نفسه
 * بتبقى تحذيرات بس، عشان عطل لحظي ما يوقّفش النشر.
 * @version 1.0.0
 */
final class MediaPreflight
{
    public const FB_CAPTION_MAX = 63206;
    public const IG_CAPTION_MAX = 2200;
    public const IG_HASHTAGS_MAX = 30;
    public const IG_IMAGE_MAX_BYTES = 8 * 1024 * 1024;
    public const FB_IMAGE_MAX_BYTES = 10 * 1024 * 1024;
    public const VIDEO_MAX_BYTES = 1024 * 1024 * 1024;

    private const META_AGENTS = ['facebookexternalhit', 'facebot', 'meta-externalagent'];

    /** @var callable|null */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    /**
     * @param array $spec ['platform'=>facebook|instagram, 'caption'=>string, 'media_url'=>?string, 'is_video'=>bool]
     * @return array ['ok'=>bool,'errors'=>string[],'warnings'=>string[],'info'=>array]
     */
    public function check(array $spec): array
    {
        $platform = (string) ($spec['platform'] ?? '');
        $caption = (string) ($spec['caption'] ?? '');
        $url = isset($spec['media_url']) ? (string) $spec['media_url'] : '';
        $isVideo = !empty($spec['is_video']);
        $errors = [];
        $warnings = [];
        $info = [];

        $errors = array_merge($errors, self::checkCaption($platform, $caption));

        if ($url !== '' && $platform !== 'youtube') {
            $urlErrors = self::checkUrl($url, $this->transport === null);
            if ($urlErrors) {
                $errors = array_merge($errors, $urlErrors);
            } else {
                $this->checkRemoteFile($platform, $url, $isVideo, $errors, $warnings, $info);
                if (in_array($platform, ['facebook', 'instagram'], true)) {
                    $this->checkRobots($url, $errors, $warnings);
                }
            }
        }

        return ['ok' => !$errors, 'errors' => $errors, 'warnings' => $warnings, 'info' => $info];
    }

    /** @return string[] */
    public static function checkCaption(string $platform, string $caption): array
    {
        $len = mb_strlen($caption);
        $errors = [];
        if ($platform === 'facebook' && $len > self::FB_CAPTION_MAX) {
            $errors[] = "النص أطول من الحد المسموح في فيسبوك ({$len} من " . self::FB_CAPTION_MAX . ' حرف).';
        }
        if ($platform === 'instagram') {
            if ($len > self::IG_CAPTION_MAX) {
                $errors[] = "النص أطول من الحد المسموح في إنستجرام ({$len} من " . self::IG_CAPTION_MAX . ' حرف).';
            }
            $tags = preg_match_all('/(?<![\p{L}\p{N}_])#[\p{L}\p{N}_]+/u', $caption);
            if ($tags > self::IG_HASHTAGS_MAX) {
                $errors[] = "إنستجرام بيسمح بـ " . self::IG_HASHTAGS_MAX . " هاشتاج كحد أقصى (النص فيه {$tags}).";
            }
        }
        return $errors;
    }

    /** @return string[] */
    public static function checkUrl(string $url, bool $resolveDns = true): array
    {
        $p = parse_url($url);
        if (!$p || empty($p['host']) || ($p['scheme'] ?? '') !== 'https') {
            return ['رابط الوسائط لازم يكون https عام. الرابط الحالي غير صالح لـ Meta.'];
        }
        $host = strtolower(trim($p['host'], '[]')); // IPv6 literal بييجي بأقواس
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return ['رابط الوسائط داخلي (localhost) - Meta مش هتقدر توصله.'];
        }
        $ip = null;
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ip = $host;
        } elseif ($resolveDns) {
            $resolved = gethostbyname($host);
            $ip = $resolved !== $host ? $resolved : null; // لو ما اتحلّش نسيبه لـ Meta
        }
        if ($ip !== null && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['رابط الوسائط بيشاور على عنوان داخلي/خاص - Meta مش هتقدر توصله.'];
        }
        return [];
    }

    private function checkRemoteFile(string $platform, string $url, bool $isVideo, array &$errors, array &$warnings, array &$info): void
    {
        $r = $this->http('HEAD', $url);
        if ($r['error'] === null && in_array($r['http_code'], [403, 405, 501], true)) {
            // بعض السيرفرات ما بتدعمش HEAD: نجرب GET لأول بايت بس
            $r = $this->http('GET', $url, ['Range: bytes=0-0']);
        }
        if ($r['error'] !== null || $r['http_code'] === 0) {
            $warnings[] = 'تعذّر فحص رابط الوسائط من السيرفر (' . ($r['error'] ?? 'no response') . ') - هنكمّل النشر وMeta هتتحقق بنفسها.';
            return;
        }
        $code = $r['http_code'];
        if ($code === 404 || $code === 410) {
            $errors[] = 'ملف الصورة/الفيديو مش موجود على السيرفر (HTTP ' . $code . ').';
            return;
        }
        if ($code >= 400) {
            $errors[] = "السيرفر رفض إرجاع ملف الوسائط (HTTP {$code}) - غالبًا حماية WAF/Cloudflare أو صلاحيات الملف.";
            return;
        }
        $h = array_change_key_case($r['headers'], CASE_LOWER);
        $type = strtolower(trim(explode(';', (string) ($h['content-type'] ?? ''))[0]));
        $size = isset($h['content-range']) && preg_match('#/(\d+)$#', (string) $h['content-range'], $m)
            ? (int) $m[1]
            : (isset($h['content-length']) ? (int) $h['content-length'] : 0);
        $info = ['content_type' => $type, 'size' => $size, 'http_code' => $code];

        if ($isVideo) {
            if ($type !== '' && !str_starts_with($type, 'video/') && $type !== 'application/octet-stream') {
                $errors[] = "نوع الملف ({$type}) مش فيديو. ارفع MP4.";
            }
            if ($size > self::VIDEO_MAX_BYTES) {
                $errors[] = 'حجم الفيديو أكبر من الحد المسموح (' . self::fmtMb(self::VIDEO_MAX_BYTES) . ').';
            }
            return;
        }
        if ($type !== '' && !str_starts_with($type, 'image/')) {
            $errors[] = "نوع الملف ({$type}) مش صورة.";
            return;
        }
        if ($platform === 'instagram' && $type !== '' && $type !== 'image/jpeg') {
            $errors[] = "إنستجرام بيقبل صور JPG بس (الملف {$type}). ارفع الصورة من فورم المنشور والمنصة هتحولها.";
        }
        $max = $platform === 'instagram' ? self::IG_IMAGE_MAX_BYTES : self::FB_IMAGE_MAX_BYTES;
        if ($size > $max) {
            $errors[] = 'حجم الصورة (' . self::fmtMb($size) . ') أكبر من الحد المسموح (' . self::fmtMb($max) . ').';
        }
    }

    private function checkRobots(string $url, array &$errors, array &$warnings): void
    {
        $p = parse_url($url);
        $robotsUrl = 'https://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . '/robots.txt';
        $r = $this->http('GET', $robotsUrl);
        if ($r['error'] !== null || $r['http_code'] !== 200) {
            return; // مفيش robots.txt = مسموح
        }
        $path = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
        if (!self::robotsAllows((string) $r['body'], $path, self::META_AGENTS)) {
            $errors[] = 'ملف robots.txt في موقعك بيمنع Meta من سحب الملف ده. اسمح لـ facebookexternalhit و Meta بفولدر الوسائط (/uploads).';
        }
    }

    /**
     * robots.txt: أخص User-agent يكسب (agents ثم *)، وأطول قاعدة تتطابق تكسب، والـ Allow يكسب لو التعادل.
     */
    public static function robotsAllows(string $robots, string $path, array $agents): bool
    {
        $groups = [];
        $current = [];
        $lastWasAgent = false;
        foreach (preg_split('/\r?\n/', $robots) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            $k = strtolower($k);
            if ($k === 'user-agent') {
                if (!$lastWasAgent) {
                    $current = [];
                    $groups[] = ['agents' => [], 'rules' => []];
                }
                $groups[count($groups) - 1]['agents'][] = strtolower($v);
                $lastWasAgent = true;
            } elseif (in_array($k, ['allow', 'disallow'], true) && $groups) {
                $groups[count($groups) - 1]['rules'][] = [$k, $v];
                $lastWasAgent = false;
            }
        }
        $rules = null;
        foreach ($groups as $g) {
            if (array_intersect($g['agents'], $agents)) {
                $rules = array_merge($rules ?? [], $g['rules']);
            }
        }
        if ($rules === null) {
            foreach ($groups as $g) {
                if (in_array('*', $g['agents'], true)) {
                    $rules = array_merge($rules ?? [], $g['rules']);
                }
            }
        }
        $best = ['len' => -1, 'allow' => true];
        foreach ($rules ?? [] as [$type, $pattern]) {
            if ($pattern === '') {
                continue; // Disallow: فاضية = مسموح
            }
            $re = '#^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($pattern, '#')) . '#';
            if (preg_match($re, $path)) {
                $len = strlen($pattern);
                $allow = $type === 'allow';
                if ($len > $best['len'] || ($len === $best['len'] && $allow)) {
                    $best = ['len' => $len, 'allow' => $allow];
                }
            }
        }
        return $best['allow'];
    }

    private static function fmtMb(int $bytes): string
    {
        return round($bytes / 1048576, 1) . ' MB';
    }

    /** @return array ['http_code'=>int,'headers'=>array,'body'=>string,'error'=>?string] */
    private function http(string $method, string $url, array $headers = []): array
    {
        if ($this->transport !== null) {
            $r = call_user_func($this->transport, ['method' => $method, 'url' => $url, 'headers' => $headers]);
            return [
                'http_code' => (int) ($r['http_code'] ?? 0),
                'headers' => (array) ($r['headers'] ?? []),
                'body' => (string) ($r['body'] ?? ''),
                'error' => isset($r['error']) ? (string) $r['error'] : null,
            ];
        }
        $respHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'facebookexternalhit/1.1 (Tourfecto preflight)',
            CURLOPT_HTTPHEADER => $headers,
            // ما نحمّلش ملف كامل: نقطع لو الرد أكبر من 512KB (robots.txt صغير، والملفات بنقرا هيدراتها بس)
            CURLOPT_WRITEFUNCTION => static function ($c, string $d) use (&$buf) {
                $buf = ($buf ?? '') . $d;
                return strlen($buf) > 524288 ? 0 : strlen($d);
            },
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$respHeaders): int {
                $p = explode(':', $line, 2);
                if (count($p) === 2) {
                    $respHeaders[trim($p[0])] = trim($p[1]);
                }
                return strlen($line);
            },
        ]);
        $buf = '';
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_errno($ch) && $code === 0 ? curl_error($ch) : null;
        curl_close($ch);
        return ['http_code' => $code, 'headers' => $respHeaders, 'body' => (string) $buf, 'error' => $err];
    }
}

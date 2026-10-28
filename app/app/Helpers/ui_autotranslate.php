<?php

/**
 * Tourfecto - ترجمة تلقائية للنصوص العربية المكتوبة مباشرة في الكود
 *
 * المشكلة: أغلب صفحات المنصة (Auto SEO, Email Marketing, Ads, CRM ...)
 * نصوصها مكتوبة عربي مباشرة جوه الـ Controllers من غير ما تعدي على t()،
 * فلما المستخدم يختار English كانت القائمة الجانبية بس اللي تتغير وباقي
 * الصفحة يفضل عربي.
 *
 * الحل: لما اللغة الحالية مش عربي، بنمرر الرد النهائي (HTML / JSON / نص)
 * على قاموس app/Lang/auto_en.php (عربي => إنجليزي) اللي متجمّع من كل
 * النصوص العربية الموجودة في الكود.
 *
 * حماية المحتوى: الترجمة بتتعمل على "جمل كاملة" بس. أي جملة عربية مش
 * معروفة بالكامل للقاموس (زي مقال اتولد بالذكاء الاصطناعي، أو بيانات
 * العميل نفسه) بتفضل زي ما هي من غير أي تعديل.
 */

if (!defined('TF_AUTOTRANSLATE_AR')) {
    define('TF_AUTOTRANSLATE_AR', '\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}');
}

if (!function_exists('tf_autotranslate_run_regex')) {
    function tf_autotranslate_run_regex(): string
    {
        $ar = TF_AUTOTRANSLATE_AR;
        return '/[' . $ar . '](?:[' . $ar . '0-9A-Za-z \t\.,:;!\?\-\(\)%\/+«»،؛؟…–—_’]*[' . $ar . '])?/u';
    }
}

if (!function_exists('tf_autotranslate_dict')) {
    function tf_autotranslate_dict(): array
    {
        static $dict = null;
        if ($dict !== null) {
            return $dict;
        }
        $dict = [];
        $file = dirname(__DIR__) . '/Lang/auto_en.php';
        if (is_file($file)) {
            $loaded = require $file;
            if (is_array($loaded)) {
                $dict = $loaded;
            }
        }
        return $dict;
    }
}

if (!function_exists('tf_autotranslate_path_excluded')) {
    /**
     * صفحات الزوار والعملاء النهائيين (مواقع العملاء، الويدجت، الويبهوك،
     * التصدير والتحميل) لازم تفضل بلغتها الأصلية - مش واجهة المنصة.
     */
    function tf_autotranslate_path_excluded(string $path): bool
    {
        $path = '/' . ltrim(strtolower($path), '/');
        $prefixes = [
            '/sites/', '/r/', '/assets/', '/favicon', '/robots.txt', '/sitemap', '/.well-known',
            '/webhooks', '/api/webhook', '/api/chat/webhook', '/api/edge', '/embed.js',
            '/api/crm/email-track', '/api/crm/public/', '/api/email-marketing/track',
            '/api/email-marketing/unsubscribe', '/seo-proxy', '/widget', '/api/widget',
        ];
        foreach ($prefixes as $p) {
            if (strpos($path, $p) === 0) {
                return true;
            }
        }
        foreach (['/export', '/download', 'webhook', '.gif', '.xml', '.txt'] as $needle) {
            if (strpos($path, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('tf_autotranslate_phrase')) {
    /**
     * ترجمة جملة عربية واحدة (run). بيرجع null لو الجملة مش معروفة كفاية.
     */
    function tf_autotranslate_phrase(string $run, array $dict): ?string
    {
        if (isset($dict[$run])) {
            return $dict[$run];
        }

        // تقسيم لكلمات ومطابقة أطول عبارة معروفة من كل نقطة (عشان النصوص
        // اللي جواها أرقام أو اسم متغير زي "تم حذف 5 عناصر").
        $words = preg_split('/\s+/u', trim($run));
        if (!$words) {
            return null;
        }
        $n = count($words);
        $out = [];
        $unknownArabicWords = 0;
        $matched = 0;
        $singleWordMatches = 0;
        $matchedWords = 0;
        $punctMap = ['،' => ',', '؛' => ';', '؟' => '?'];
        $i = 0;
        while ($i < $n) {
            $found = false;
            for ($j = min($n, $i + 30); $j > $i; $j--) {
                $cand = implode(' ', array_slice($words, $i, $j - $i));
                $lead = '';
                $trail = '';
                if (!isset($dict[$cand])) {
                    if (preg_match('/^([\(«\-]*)(.*?)([\.,:;!\?،؛؟…\)»\(\-]*)$/u', $cand, $m)
                        && ($m[1] !== '' || $m[3] !== '') && $m[2] !== '' && isset($dict[$m[2]])) {
                        $lead = $m[1];
                        $cand = $m[2];
                        $trail = strtr($m[3], $punctMap);
                    } else {
                        continue;
                    }
                }
                $out[] = $lead . $dict[$cand] . $trail;
                $matched++;
                $matchedWords += ($j - $i);
                if (($j - $i) < 2) {
                    $singleWordMatches++;
                }
                $i = $j;
                $found = true;
                break;
            }
            if (!$found) {
                $w = $words[$i];
                if (preg_match('/[' . TF_AUTOTRANSLATE_AR . ']/u', $w)) {
                    $unknownArabicWords++;
                }
                $out[] = strtr($w, $punctMap);
                $i++;
            }
        }

        // لو كل الكلمات معروفة نترجم. لو فيه كلمات مش معروفة (غالبًا اسم أو
        // قيمة متغيرة جوه الجملة) نقبل بس لو المطابقات كلها عبارات كاملة
        // (كلمتين أو أكتر) - عشان منطلعش جملة مكسّرة نص عربي ونص إنجليزي.
        if ($matched === 0) {
            return null;
        }
        if ($unknownArabicWords > 0
            && ($unknownArabicWords > 2 || $singleWordMatches > 0 || $unknownArabicWords >= $matchedWords)) {
            return null;
        }
        return implode(' ', $out);
    }
}

if (!function_exists('tf_autotranslate_text')) {
    /**
     * @param bool $jsSafe الترجمة هتتحط جوه كود JavaScript (<script> أو
     *   onclick="..."): الجملة العربية كانت جوه string بين '...' أو "..."
     *   أو `...`، والترجمة الإنجليزي فيها علامات زي ' (Couldn't) كانت بتقفل
     *   الـ string وتوقّف الصفحة كلها. فبنبدّل العلامات دي بشكلها المطبعي.
     */
    function tf_autotranslate_text(string $text, bool $jsSafe = false): string
    {
        if ($text === '' || !preg_match('/[' . TF_AUTOTRANSLATE_AR . ']/u', $text)) {
            return $text;
        }
        $dict = tf_autotranslate_dict();
        if (!$dict) {
            return $text;
        }
        $res = preg_replace_callback(tf_autotranslate_run_regex(), static function (array $m) use ($dict, $jsSafe): string {
            $t = tf_autotranslate_phrase($m[0], $dict);
            if ($t === null) {
                return $m[0];
            }
            return $jsSafe ? tf_autotranslate_js_safe($t) : $t;
        }, $text);
        return is_string($res) ? $res : $text;
    }
}

if (!function_exists('tf_autotranslate_js_safe')) {
    function tf_autotranslate_js_safe(string $t): string
    {
        return strtr($t, ["'" => '’', '"' => '”', '`' => '’', '\\' => '/', '${' => '$ {', "\n" => ' ', "\r" => ' ', '</' => '<\/']);
    }
}

if (!function_exists('tf_autotranslate_html')) {
    /** ترجمة صفحة HTML: كود الـ JavaScript بيتترجم بشكل آمن، والباقي عادي */
    function tf_autotranslate_html(string $html): string
    {
        $js = static fn (array $m): string => $m[1] . tf_autotranslate_text($m[2], true) . $m[3];
        // بحث نصي عادي بدل regex: سكربتات الصفحات الكبيرة (زي الشات) كانت
        // بتعدّي حد الـ backtracking فالـ regex يفشل والترجمة ترجع للطريقة
        // غير الآمنة.
        $out = '';
        $pos = 0;
        $lower = strtolower($html);
        while (($open = strpos($lower, '<script', $pos)) !== false) {
            $tagEnd = strpos($html, '>', $open);
            $close = $tagEnd === false ? false : strpos($lower, '</script>', $tagEnd);
            if ($close === false) {
                break;
            }
            $out .= substr($html, $pos, $tagEnd + 1 - $pos)
                . tf_autotranslate_text(substr($html, $tagEnd + 1, $close - $tagEnd - 1), true);
            $pos = $close;
        }
        $out .= substr($html, $pos);
        $out2 = preg_replace_callback('/(\son[a-z]+\s*=\s*")([^"]*)(")/iu', $js, $out);
        if (is_string($out2)) {
            $out = $out2;
        }
        $out2 = preg_replace_callback("/(\\son[a-z]+\\s*=\\s*')([^']*)(')/iu", $js, $out);
        if (is_string($out2)) {
            $out = $out2;
        }
        return tf_autotranslate_text($out);
    }
}

if (!function_exists('tf_autotranslate_walk')) {
    function tf_autotranslate_walk($value)
    {
        // API data may be customer content in any language. Translate only response-envelope messages.
        if (is_array($value)) {
            foreach (['message','error'] as $key) if (isset($value[$key]) && is_string($value[$key])) $value[$key]=tf_autotranslate_text($value[$key]);
        }
        return $value;
    }
}

if (!function_exists('tf_autotranslate_buffer')) {
    /**
     * Output buffer callback.
     */
    function tf_autotranslate_buffer(string $buffer, int $phase = PHP_OUTPUT_HANDLER_FINAL): string
    {
        try {
            if ($buffer === '' || !preg_match('/[' . TF_AUTOTRANSLATE_AR . ']|\\\\u06/u', $buffer)) {
                return $buffer;
            }

            $isJson = false;
            foreach (headers_list() as $h) {
                $lh = strtolower($h);
                if (strpos($lh, 'content-length:') === 0 || strpos($lh, 'content-disposition:') === 0) {
                    return $buffer; // تحميل ملف أو طول ثابت - منغيّرش حاجة
                }
                if (strpos($lh, 'content-type:') === 0) {
                    if (strpos($lh, 'json') !== false) {
                        $isJson = true;
                    } elseif (!preg_match('#text/html|text/plain|javascript#', $lh)) {
                        return $buffer;
                    }
                }
            }

            $trim = ltrim($buffer);
            if ($isJson || ($trim !== '' && ($trim[0] === '{' || $trim[0] === '['))) {
                $decoded=json_decode($buffer,true);
                if(json_last_error()===JSON_ERROR_NONE&&is_array($decoded)){
                    $enc=json_encode(tf_autotranslate_walk($decoded),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                    return is_string($enc)?$enc:$buffer;
                }
                return $buffer;
            }

            // بيانات المستخدم داخل حقول الإدخال (value="..." و <textarea>) تفضل
            // زي ما هي - عشان لو اتحفظت تاني متتحوّلش لإنجليزي من غير قصد.
            $protected = [];
            $masked = preg_replace_callback(
                '/(\bvalue\s*=\s*"[^"]*")|(\bvalue\s*=\s*\'[^\']*\')|(<textarea\b[^>]*>)(.*?)(<\/textarea>)/isu',
                static function (array $m) use (&$protected): string {
                    $key = "\x01TFP" . count($protected) . "\x02";
                    if (isset($m[3]) && $m[3] !== '') {
                        // نحمي محتوى الـ textarea بس - الـ placeholder بتاعها يتترجم عادي
                        $protected[$key] = $m[4];
                        return $m[3] . $key . $m[5];
                    }
                    $protected[$key] = $m[0];
                    return $key;
                },
                $buffer
            );
            if (!is_string($masked)) {
                $masked = $buffer;
                $protected = [];
            }

            $out = tf_autotranslate_html($masked);
            if ($protected) {
                $out = strtr($out, $protected);
            }

            // صفحات قليلة كانت مكتوب فيها <html lang="ar" dir="rtl"> ثابت
            $lang = function_exists('current_lang') ? current_lang() : 'en';
            $out = preg_replace(
                '/<html lang="ar" dir="rtl">/',
                '<html lang="' . htmlspecialchars($lang, ENT_QUOTES) . '" dir="ltr">',
                $out,
                1
            ) ?? $out;

            return $out;
        } catch (\Throwable $e) {
            return $buffer;
        }
    }
}

if (!function_exists('tf_autotranslate_start')) {
    function tf_autotranslate_start(string $path): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        $lang = function_exists('current_lang') ? current_lang() : 'ar';
        if ($lang === 'ar' || tf_autotranslate_path_excluded($path)) {
            return;
        }
        if (!is_file(dirname(__DIR__) . '/Lang/auto_en.php')) {
            return;
        }
        ob_start('tf_autotranslate_buffer');
    }
}

if (!function_exists('tf_output_started')) {
    /**
     * بديل headers_sent() بيراعي الـ output buffer بتاع الترجمة: قبل الترجمة
     * كان أي echo من الـ Controller بيبعت الهيدرز فورًا، فـ send_response
     * مكانتش بتغيّر Content-Type بعدها. مع الـ buffer الهيدرز بتتأجل، فلازم
     * نعتبر إن الإخراج بدأ لو فيه محتوى في الـ buffer - عشان الصفحات تفضل
     * text/html زي ما كانت ومتتحوّلش لـ JSON.
     */
    function tf_output_started(): bool
    {
        if (headers_sent()) {
            return true;
        }
        foreach (ob_get_status(true) as $st) {
            if (($st['name'] ?? '') === 'tf_autotranslate_buffer') {
                return ob_get_length() > 0;
            }
        }
        return false;
    }
}

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
        // ترجمات ناقصة اتضافت بعدين (رسايل السيرفر وصفحات كانت بتطلع عربي في الواجهة الإنجليزي)
        $extra = dirname(__DIR__) . '/Lang/auto_en_extra.php';
        if (is_file($extra)) {
            $more = require $extra;
            if (is_array($more)) {
                $dict += $more;
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
        // The printable monthly document has its own Arabic language; account UI translation must not rewrite it.
        if ($path === '/api/local-seo/report') return true;
        $prefixes = [
            '/sites/', '/r/', '/assets/', '/favicon', '/robots.txt', '/sitemap', '/.well-known',
            '/webhooks', '/api/webhook', '/api/chat/webhook', '/api/edge', '/embed.js',
            '/api/crm/email-track', '/api/crm/public/', '/api/email-marketing/track',
            '/api/email-marketing/unsubscribe', '/seo-proxy', '/widget', '/api/widget',
            // تسويق المنصة: تقرير الشركة وإلغاء الاشتراك بلغة الشركة نفسها (عربي/إنجليزي/تركي)
            '/p/',
            // صفحة لينك الدفع بتختار لغة السائح بنفسها (6 لغات)
            '/pay/',
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
        if (tf_autotranslate_path_excluded($path)) {
            return;
        }
        if ($lang === 'ar') {
            if (is_file(dirname(__DIR__) . '/Lang/auto_en.php')) {
                ob_start('tf_autotranslate_ar_buffer');
            }
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
            if (in_array($st['name'] ?? '', ['tf_autotranslate_buffer', 'tf_autotranslate_ar_buffer'], true)) {
                return ob_get_length() > 0;
            }
        }
        return false;
    }
}

/*
 * ترجمة عكسية (إنجليزي => عربي) للواجهة العربية
 *
 * صفحات كتير (صندوق الرسائل، تسويق الإيميل والواتساب، التشغيل...) نصوصها
 * اتكتبت إنجليزي في الكود، فكانت بتطلع إنجليزي للعميل العربي. القاموس هنا
 * هو auto_en.php معكوس (النص الإنجليزي ده أصلًا ترجمة لجملة عربية) + إضافات
 * يدوية في auto_ar.php. الترجمة بتتعمل على "نص كامل" بس (نص عنصر كامل،
 * placeholder، أو string كاملة في الـ JavaScript) - فبيانات العميل (أسماء
 * صفحات، مقالات، وصف صور) عمرها ما بتتغير.
 */
if (!function_exists('tf_autotranslate_ar_dict')) {
    function tf_autotranslate_ar_dict(): array
    {
        static $dict = null;
        if ($dict !== null) {
            return $dict;
        }
        $dict = [];
        foreach (tf_autotranslate_dict() as $ar => $en) {
            $en = trim((string) $en);
            $ar = trim((string) $ar);
            if ($en === '' || $ar === '' || isset($dict[$en]) || !preg_match('/[A-Za-z]/', $en) || preg_match('/[' . TF_AUTOTRANSLATE_AR . ']/u', $en)) {
                continue;
            }
            $dict[$en] = $ar;
        }
        // أزواج عربي/إنجليزي من الكود نفسه ($ar ? '...' : '...') + ترجمات ناقصة: نص اتحفظ بالإنجليزي (مهمة سيو مثلًا) يتعرض عربي
        $pairs = dirname(__DIR__) . '/Lang/auto_ar_extra.php';
        if (is_file($pairs)) {
            $more = require $pairs;
            if (is_array($more)) {
                $dict = $more + $dict;
            }
        }
        $extra = dirname(__DIR__) . '/Lang/auto_ar.php';
        if (is_file($extra)) {
            $more = require $extra;
            if (is_array($more)) {
                $dict = $more + $dict;
            }
        }
        return $dict;
    }
}

if (!function_exists('tf_autotranslate_ar_lookup')) {
    /**
     * ترجمة نص كامل أو null. كلمة واحدة لازم تبدأ بحرف كبير (Search, Settings)
     * عشان القيم الداخلية والأسماء القصيرة (to, open, en) متتغيرش.
     */
    function tf_autotranslate_ar_lookup(string $text, bool $js = false): ?string
    {
        $t = trim(preg_replace('/\s+/u', ' ', $text));
        if ($t === '' || strlen($t) < 3 || strlen($t) > 400 || !preg_match('/[A-Za-z]{2}/', $t) || preg_match('/[' . TF_AUTOTRANSLATE_AR . ']/u', $t)) {
            return null;
        }
        $single = strpos($t, ' ') === false;
        if ($single && (!preg_match('/^\P{L}*\p{Lu}\p{Ll}/u', $t) || preg_match('/[_\/.@]/', rtrim($t, '.:…!?')))) {
            return null;
        }
        if ($js && $single) {
            return null; // كلمة واحدة في الـ JavaScript ممكن تكون قيمة بتتقارن (status, channel)
        }
        $dict = tf_autotranslate_ar_dict();
        if (isset($dict[$t])) {
            return $dict[$t];
        }
        // رموز في الأول (📅 ✨ + ←) وعلامات في الآخر (: … .) حوالين جملة معروفة
        if (preg_match('/^([^\p{L}\p{N}]*)(.*?)([\s.:…!?)]*)$/u', $t, $m) && $m[2] !== '' && ($m[1] !== '' || $m[3] !== '') && isset($dict[$m[2]])) {
            return $m[1] . $dict[$m[2]] . strtr($m[3], ['?' => '؟']);
        }
        return null;
    }
}

if (!function_exists('tf_autotranslate_ar_html')) {
    function tf_autotranslate_ar_html(string $html): string
    {
        $markup = static function (string $part): string {
            // <option> من غير value: النص هو القيمة اللي بتتبعت، فبنثبّتها بالإنجليزي قبل ما نترجم النص
            $part = preg_replace_callback('/<option\b((?:(?!\bvalue\s*=|\x01TFQ)[^>])*)>([^<]*[A-Za-z][^<]*)<\/option>/iu', static function (array $m): string {
                $ar = tf_autotranslate_ar_lookup($m[2]);
                if ($ar === null) {
                    return $m[0];
                }
                return '<option' . $m[1] . ' value="' . htmlspecialchars(trim(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8')), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($ar, ENT_NOQUOTES, 'UTF-8', false) . '</option>';
            }, $part) ?? $part;
            $part = preg_replace_callback('/>([^<>]*[A-Za-z][^<>]*)</u', static function (array $m): string {
                $ar = tf_autotranslate_ar_lookup($m[1]);
                if ($ar === null) {
                    return $m[0];
                }
                preg_match('/^\s*/u', $m[1], $lead);
                preg_match('/\s*$/u', $m[1], $trail);
                return '>' . $lead[0] . htmlspecialchars($ar, ENT_NOQUOTES, 'UTF-8', false) . $trail[0] . '<';
            }, $part) ?? $part;
            return preg_replace_callback('/\b(placeholder|title|aria-label)="([^"]*[A-Za-z][^"]*)"/u', static function (array $m): string {
                $ar = tf_autotranslate_ar_lookup(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
                return $ar === null ? $m[0] : $m[1] . '="' . htmlspecialchars($ar, ENT_QUOTES, 'UTF-8') . '"';
            }, $part) ?? $part;
        };
        $out = '';
        $pos = 0;
        $lower = strtolower($html);
        while (($open = strpos($lower, '<script', $pos)) !== false) {
            $tagEnd = strpos($html, '>', $open);
            $close = $tagEnd === false ? false : strpos($lower, '</script>', $tagEnd);
            if ($close === false) {
                break;
            }
            $tag = substr($html, $open, $tagEnd + 1 - $open);
            $body = substr($html, $tagEnd + 1, $close - $tagEnd - 1);
            $out .= $markup(substr($html, $pos, $open - $pos)) . $tag . $body;
            $pos = $close;
        }
        return $out . $markup(substr($html, $pos));
    }
}

if (!function_exists('tf_autotranslate_ar_buffer')) {
    function tf_autotranslate_ar_buffer(string $buffer, int $phase = PHP_OUTPUT_HANDLER_FINAL): string
    {
        try {
            if ($buffer === '' || !preg_match('/[A-Za-z]/', $buffer)) {
                return $buffer;
            }
            $isJson = false;
            foreach (headers_list() as $h) {
                $lh = strtolower($h);
                if (strpos($lh, 'content-length:') === 0 || strpos($lh, 'content-disposition:') === 0) {
                    return $buffer;
                }
                if (strpos($lh, 'content-type:') === 0) {
                    if (strpos($lh, 'json') !== false) {
                        $isJson = true;
                    } elseif (strpos($lh, 'text/html') === false) {
                        return $buffer;
                    }
                }
            }
            $trim = ltrim($buffer);
            if ($isJson || ($trim !== '' && ($trim[0] === '{' || $trim[0] === '['))) {
                $d = json_decode($buffer, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($d)) {
                    return $buffer;
                }
                $changed = false;
                foreach (['message', 'error'] as $k) {
                    if (isset($d[$k]) && is_string($d[$k]) && ($ar = tf_autotranslate_ar_lookup($d[$k])) !== null) {
                        $d[$k] = $ar;
                        $changed = true;
                    }
                }
                $enc = $changed ? json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
                return is_string($enc) ? $enc : $buffer;
            }
            $protected = [];
            $masked = preg_replace_callback(
                '/(\bvalue\s*=\s*"[^"]*")|(\bvalue\s*=\s*\'[^\']*\')|(<textarea\b[^>]*>)(.*?)(<\/textarea>)|(<(code|pre)\b[^>]*>.*?<\/\7>)/isu',
                static function (array $m) use (&$protected): string {
                    $key = "\x01TFQ" . count($protected) . "\x02";
                    if (isset($m[3]) && $m[3] !== '') {
                        $protected[$key] = $m[4];
                        return $m[3] . $key . $m[5];
                    }
                    $protected[$key] = $m[0];
                    return $key;
                },
                $buffer
            );
            if (!is_string($masked)) {
                return $buffer;
            }
            $out = tf_autotranslate_ar_html($masked);
            return $protected ? strtr($out, $protected) : $out;
        } catch (\Throwable $e) {
            return $buffer;
        }
    }
}

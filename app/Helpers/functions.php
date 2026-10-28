<?php

/**
 * Tourfecto - Functions Helper
 * دوال مساعدة عامة للاستخدام في جميع أنحاء التطبيق
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

if (!function_exists('dd')) {
    /**
     * Dump and Die - عرض محتويات المتغير وإيقاف التنفيذ
     * @param mixed ...$vars
     */
    function dd(...$vars): void
    {
        echo '<pre style="background: #1a1a1a; color: #f8f8f8; padding: 20px; border-radius: 8px; font-family: monospace; direction: ltr; text-align: left;">';
        foreach ($vars as $var) {
            var_dump($var);
            echo "\n\n";
        }
        echo '</pre>';
        die;
    }
}

if (!function_exists('dump')) {
    /**
     * Dump - عرض محتويات المتغير
     * @param mixed ...$vars
     */
    function dump(...$vars): void
    {
        echo '<pre style="background: #1a1a1a; color: #f8f8f8; padding: 20px; border-radius: 8px; font-family: monospace; direction: ltr; text-align: left;">';
        foreach ($vars as $var) {
            var_dump($var);
            echo "\n\n";
        }
        echo '</pre>';
    }
}

if (!function_exists('is_real_secret')) {
    /**
     * هل القيمة مفتاح حقيقي؟ القيم المؤقتة اللي جاية من .env.example
     * (your-google-maps-api-key, xoxb-your-...) كانت بتتحسب "مضبوطة"، فالعميل
     * بيشوف "متصل ✅" وبعدين الربط يفشل.
     */
    function is_real_secret($value): bool
    {
        $v = trim((string) $value);
        return $v !== '' && !preg_match('/^(your[-_]|xoxb-your|replace[-_]|change[-_]?me)/i', $v);
    }
}

if (!function_exists('env')) {
    /**
     * الحصول على قيمة من متغيرات البيئة
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function env(string $key, $default = null)
    {
        // ملاحظة مهمة: على استضافات مشتركة كتير (زي Hostinger) بتكون دالة putenv()
        // ممنوعة (disable_functions) لأسباب أمنية. مكتبة vlucas/phpdotenv بتكتشف
        // ده تلقائيًا وبترجع None لأدابتر الـ putenv (من غير أي خطأ)، فبتفضل بس
        // تكتب القيم في $_ENV و $_SERVER. يعني getenv() لوحدها بترجع false دايمًا
        // في الحالة دي حتى لو .env اتحمّل صح فعلاً — فلازم ندوّر في $_ENV/$_SERVER
        // الأول قبل ما نلجأ لـ getenv() كخيار أخير.
        if (array_key_exists($key, $_ENV)) {
            $value = $_ENV[$key];
        } elseif (array_key_exists($key, $_SERVER)) {
            $value = $_SERVER[$key];
        } else {
            $value = getenv($key);
        }

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        // تحويل القيم المنطقية
        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'null':
            case '(null)':
                return null;
        }

        // إزالة علامات الاقتباس
        if (strpos($value, '"') === 0 && strrpos($value, '"') === strlen($value) - 1) {
            $value = substr($value, 1, -1);
        }

        if (strpos($value, "'") === 0 && strrpos($value, "'") === strlen($value) - 1) {
            $value = substr($value, 1, -1);
        }

        return $value;
    }
}

if (!function_exists('config')) {
    /**
     * الحصول على قيمة من ملفات التكوين
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function config(string $key, $default = null)
    {
        static $config = [];

        $parts = explode('.', $key);
        $file = $parts[0] ?? '';
        $configKey = $parts[1] ?? null;

        if (!isset($config[$file])) {
            $path = TOURFECTO_APP . "/Config/{$file}.php";
            if (file_exists($path)) {
                $config[$file] = require $path;
            } else {
                return $default;
            }
        }

        if ($configKey === null) {
            return $config[$file];
        }

        return $config[$file][$configKey] ?? $default;
    }
}

if (!function_exists('json_response')) {
    /**
     * إرجاع استجابة JSON
     * @param mixed $data
     * @param int $statusCode
     * @param bool $pretty
     */
    function json_response($data, int $statusCode = 200, bool $pretty = false): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        $flags = JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        echo json_encode($data, $flags);
        exit;
    }
}

if (!function_exists('success_response')) {
    /**
     * إرجاع استجابة نجاح
     * @param array $data
     * @param string $message
     * @param int $statusCode
     */
    function success_response(array $data = [], string $message = 'Success', int $statusCode = 200): void
    {
        json_response([
            'success' => true,
            'message' => $message,
            'data' => $data
        ], $statusCode);
    }
}

if (!function_exists('error_response')) {
    /**
     * إرجاع استجابة خطأ
     * @param string $message
     * @param int $statusCode
     * @param array $errors
     */
    function error_response(string $message = 'Error', int $statusCode = 400, array $errors = []): void
    {
        $response = [
            'success' => false,
            'error' => $message,
            'code' => $statusCode
        ];

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        json_response($response, $statusCode);
    }
}

if (!function_exists('generate_uuid')) {
    /**
     * توليد UUID v4
     * @return string
     */
    function generate_uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('generate_random_string')) {
    /**
     * توليد سلسلة عشوائية
     * @param int $length
     * @param string $characters
     * @return string
     */
    function generate_random_string(int $length = 32, string $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'): string
    {
        $string = '';
        $max = strlen($characters) - 1;

        for ($i = 0; $i < $length; $i++) {
            $string .= $characters[random_int(0, $max)];
        }

        return $string;
    }
}

if (!function_exists('generate_secure_token')) {
    /**
     * توليد توكن آمن
     * @param int $length
     * @return string
     */
    function generate_secure_token(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }
}

if (!function_exists('is_valid_email')) {
    /**
     * التحقق من صحة البريد الإلكتروني
     * @param string $email
     * @return bool
     */
    function is_valid_email(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('is_valid_url')) {
    /**
     * التحقق من صحة الرابط
     * @param string $url
     * @return bool
     */
    function is_valid_url(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}

if (!function_exists('is_valid_phone')) {
    /**
     * التحقق من صحة رقم الهاتف
     * @param string $phone
     * @return bool
     */
    function is_valid_phone(string $phone): bool
    {
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        return strlen($phone) >= 8 && strlen($phone) <= 15;
    }
}

if (!function_exists('is_valid_ip')) {
    /**
     * التحقق من صحة عنوان IP
     * @param string $ip
     * @return bool
     */
    function is_valid_ip(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}

if (!function_exists('get_client_ip')) {
    /**
     * الحصول على IP العميل
     * @return string
     */
    function get_client_ip(): string
    {
        $ips = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            $_SERVER['HTTP_X_REAL_IP'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        ];

        foreach ($ips as $ip) {
            if ($ip) {
                $ip = explode(',', $ip)[0];
                $ip = trim($ip);

                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }
}

if (!function_exists('get_user_agent')) {
    /**
     * الحصول على User Agent
     * @return string
     */
    function get_user_agent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    }
}

if (!function_exists('is_ajax_request')) {
    /**
     * التحقق من طلب AJAX
     * @return bool
     */
    function is_ajax_request(): bool
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}

if (!function_exists('is_https')) {
    /**
     * التحقق من اتصال HTTPS
     * @return bool
     */
    function is_https(): bool
    {
        return isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }
}

if (!function_exists('get_current_url')) {
    /**
     * الحصول على الرابط الحالي
     * @param bool $withQuery
     * @return string
     */
    function get_current_url(bool $withQuery = true): string
    {
        $protocol = is_https() ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        if (!$withQuery) {
            $uri = strtok($uri, '?');
        }

        return $protocol . $host . $uri;
    }
}

if (!function_exists('redirect')) {
    /**
     * إعادة توجيه
     * @param string $url
     * @param int $statusCode
     */
    function redirect(string $url, int $statusCode = 302): void
    {
        http_response_code($statusCode);
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('back')) {
    /**
     * العودة إلى الصفحة السابقة
     */
    function back(): void
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '/';
        redirect($referer);
    }
}

if (!function_exists('get_file_size')) {
    /**
     * الحصول على حجم الملف بصيغة قابلة للقراءة
     * @param int $bytes
     * @param int $precision
     * @return string
     */
    function get_file_size(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

if (!function_exists('get_time_ago')) {
    /**
     * الحصول على الوقت المنقضي بصيغة قابلة للقراءة
     * @param string $datetime
     * @return string
     */
    function get_time_ago(string $datetime): string
    {
        $time = strtotime($datetime);
        $diff = time() - $time;

        if ($diff < 60) {
            return 'منذ ' . $diff . ' ثانية' . ($diff != 1 ? 'ات' : '');
        }

        $diff = floor($diff / 60);
        if ($diff < 60) {
            return 'منذ ' . $diff . ' دقيقة' . ($diff != 1 ? '' : '');
        }

        $diff = floor($diff / 60);
        if ($diff < 24) {
            return 'منذ ' . $diff . ' ساعة' . ($diff != 1 ? '' : '');
        }

        $diff = floor($diff / 24);
        if ($diff < 30) {
            return 'منذ ' . $diff . ' يوم' . ($diff != 1 ? '' : '');
        }

        $diff = floor($diff / 30);
        if ($diff < 12) {
            return 'منذ ' . $diff . ' شهر' . ($diff != 1 ? '' : '');
        }

        $diff = floor($diff / 12);
        return 'منذ ' . $diff . ' سنة' . ($diff != 1 ? '' : '');
    }
}

if (!function_exists('format_datetime')) {
    /**
     * تنسيق التاريخ والوقت
     * @param string $datetime
     * @param string $format
     * @return string
     */
    function format_datetime(string $datetime, string $format = 'Y-m-d H:i:s'): string
    {
        return date($format, strtotime($datetime));
    }
}

if (!function_exists('format_date')) {
    /**
     * تنسيق التاريخ
     * @param string $date
     * @param string $format
     * @return string
     */
    function format_date(string $date, string $format = 'Y-m-d'): string
    {
        return date($format, strtotime($date));
    }
}

if (!function_exists('format_time')) {
    /**
     * تنسيق الوقت
     * @param string $time
     * @param string $format
     * @return string
     */
    function format_time(string $time, string $format = 'H:i:s'): string
    {
        return date($format, strtotime($time));
    }
}

if (!function_exists('get_days_between')) {
    /**
     * الحصول على عدد الأيام بين تاريخين
     * @param string $start
     * @param string $end
     * @return int
     */
    function get_days_between(string $start, string $end): int
    {
        $start = strtotime($start);
        $end = strtotime($end);
        $diff = $end - $start;
        return (int) floor($diff / (60 * 60 * 24));
    }
}

if (!function_exists('get_age')) {
    /**
     * حساب العمر من تاريخ الميلاد
     * @param string $birthdate
     * @return int
     */
    function get_age(string $birthdate): int
    {
        $birth = new DateTime($birthdate);
        $now = new DateTime();
        $age = $now->diff($birth);
        return $age->y;
    }
}

if (!function_exists('truncate_text')) {
    /**
     * قص النص إلى طول معين
     * @param string $text
     * @param int $length
     * @param string $suffix
     * @return string
     */
    function truncate_text(string $text, int $length = 100, string $suffix = '...'): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length) . $suffix;
    }
}

if (!function_exists('strip_html')) {
    /**
     * إزالة علامات HTML
     * @param string $text
     * @return string
     */
    function strip_html(string $text): string
    {
        return strip_tags($text);
    }
}

if (!function_exists('sanitize_text')) {
    /**
     * تنظيف النص
     * @param string $text
     * @param bool $stripTags
     * @return string
     */
    function sanitize_text(string $text, bool $stripTags = true): string
    {
        if ($stripTags) {
            $text = strip_tags($text);
        }

        $text = trim($text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        return $text;
    }
}

if (!function_exists('slugify')) {
    /**
     * تحويل النص إلى Slug
     * @param string $text
     * @param string $separator
     * @return string
     */
    function slugify(string $text, string $separator = '-'): string
    {
        // تحويل النص إلى أحرف صغيرة
        $text = mb_strtolower($text, 'UTF-8');

        // استبدال الأحرف العربية بأحرف لاتينية
        $arabicToLatin = [
            'ا' => 'a', 'ب' => 'b', 'ت' => 't', 'ث' => 'th', 'ج' => 'j',
            'ح' => 'h', 'خ' => 'kh', 'د' => 'd', 'ذ' => 'dh', 'ر' => 'r',
            'ز' => 'z', 'س' => 's', 'ش' => 'sh', 'ص' => 's', 'ض' => 'd',
            'ط' => 't', 'ظ' => 'z', 'ع' => 'a', 'غ' => 'gh', 'ف' => 'f',
            'ق' => 'q', 'ك' => 'k', 'ل' => 'l', 'م' => 'm', 'ن' => 'n',
            'ه' => 'h', 'و' => 'w', 'ي' => 'y', 'ى' => 'a', 'ة' => 'h'
        ];

        $text = str_replace(array_keys($arabicToLatin), array_values($arabicToLatin), $text);

        // إزالة الأحرف غير المسموحة
        $text = preg_replace('/[^a-z0-9\s-]/', '', $text);

        // استبدال المسافات بالفاصل
        $text = preg_replace('/[\s-]+/', $separator, $text);

        // إزالة الفاصل من البداية والنهاية
        $text = trim($text, $separator);

        return $text;
    }
}

if (!function_exists('icon_svg')) {
    /**
     * أيقونة SVG inline (نظام Feather/Lucide - stroke 2, 24x24) بدل الـ emoji
     * في السايدبار والتوببار. بتستخدم stroke=currentColor فبتاخد لون النص
     * المحيط تلقائيًا وبتتظبط مع focus/active states.
     * لو الاسم مش موجود بترجع مربع placeholder عشان يبان إن في أيقونة
     * ناقصة بسرعة بدل ما تختفي بصمت.
     * @param string $name اسم الأيقونة من الخريطة
     * @param int $size الحجم بالبكسل
     * @return string
     */
    function icon_svg(string $name, int $size = 16): string
    {
        static $icons = [
            'activity' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
            'alarm' => '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2"/><path d="M5 3 2 6"/><path d="m22 6-3-3"/><path d="M6.38 18.7 4 21"/><path d="M17.64 18.67 20 21"/>',
            'bar-chart' => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/>',
            'bar-chart-2' => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
            'bot' => '<rect x="3" y="11" width="18" height="10" rx="2"/><circle cx="12" cy="5" r="2"/><path d="M12 7v4"/><line x1="8" y1="16" x2="8.01" y2="16"/><line x1="16" y1="16" x2="16.01" y2="16"/>',
            'book' => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
            'brain' => '<path d="M9.5 2A2.5 2.5 0 0 1 12 4.5v15a2.5 2.5 0 0 1-4.96.44 2.5 2.5 0 0 1-2.96-3.08 3 3 0 0 1-.34-5.58 2.5 2.5 0 0 1 1.32-4.24 2.5 2.5 0 0 1 1.98-3A2.5 2.5 0 0 1 9.5 2z"/><path d="M14.5 2A2.5 2.5 0 0 0 12 4.5v15a2.5 2.5 0 0 0 4.96.44 2.5 2.5 0 0 0 2.96-3.08 3 3 0 0 0 .34-5.58 2.5 2.5 0 0 0-1.32-4.24 2.5 2.5 0 0 0-1.98-3A2.5 2.5 0 0 0 14.5 2z"/>',
            'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
            'briefcase' => '<rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
            'building' => '<rect x="4" y="2" width="16" height="20" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/>',
            'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
            'compass' => '<circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/>',
            'credit-card' => '<rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
            'dollar' => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
            'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
            'eye' => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
            'file-text' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
            'flag' => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>',
            'flask' => '<path d="M10 2v6L4 20a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2L14 8V2"/><path d="M8.5 2h7"/><path d="M7 16h10"/>',
            'folder' => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
            'globe' => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
            'inbox' => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
            'key' => '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0 3 3L22 7l-3-3m-3.5 3.5L19 4"/>',
            'layout' => '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/>',
            'lightbulb' => '<path d="M9 18h6"/><path d="M10 22h4"/><path d="M15.09 14c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0 0 18 8 6 6 0 0 0 6 8c0 1 .23 2.23 1.5 3.5.76.76 1.23 1.52 1.41 2.5"/>',
            'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
            'lock' => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
            'log-out' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
            'mail' => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
            'map-pin' => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
            'megaphone' => '<path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
            'bell' => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
            'menu' => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
            'arrow-left' => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
            'home' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
            'message' => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
            'monitor' => '<rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>',
            'palette' => '<circle cx="13.5" cy="6.5" r=".5"/><circle cx="17.5" cy="10.5" r=".5"/><circle cx="8.5" cy="7.5" r=".5"/><circle cx="6.5" cy="12.5" r=".5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>',
            'plug' => '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8z"/>',
            'search' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
            'send' => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
            'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
            'smartphone' => '<rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/>',
            'sparkles' => '<path d="M12 3l1.9 5.8a2 2 0 0 0 1.3 1.3L21 12l-5.8 1.9a2 2 0 0 0-1.3 1.3L12 21l-1.9-5.8a2 2 0 0 0-1.3-1.3L3 12l5.8-1.9a2 2 0 0 0 1.3-1.3z"/>',
            'star' => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
            'target' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
            'tools' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
            'trending-up' => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
            'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'zap' => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
        ];

        $name = $icons[$name] ?? '<circle cx="12" cy="12" r="8"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>';

        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $name . '</svg>';
    }
}

if (!function_exists('tourfecto_password_algo')) {
    /**
     * خوارزمية تشفير كلمات المرور. Argon2id لو PHP مبني بيها، وإلا bcrypt.
     * بعض نسخ PHP على لوحات الاستضافة (aaPanel وغيرها) مبنية من غير Argon2،
     * فاستخدام PASSWORD_ARGON2ID مباشرة كان بيوقع الموقع كله (Undefined constant).
     */
    function tourfecto_password_algo()
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }
}

if (!function_exists('tourfecto_password_options')) {
    function tourfecto_password_options(): array
    {
        return defined('PASSWORD_ARGON2ID')
            ? ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1]
            : ['cost' => 12];
    }
}

if (!function_exists('tourfecto_password_verify')) {
    /**
     * password_verify مع بديل لو السيرفر مفيهوش Argon2: كلمات المرور القديمة
     * متخزنة Argon2id، وpassword_verify بترجع false عليها في الحالة دي فمحدش
     * يعرف يسجل دخول. libsodium بتقدر تتحقق من نفس صيغة الهاش.
     */
    function tourfecto_password_verify(string $password, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }
        if (password_verify($password, $hash)) {
            return true;
        }
        if (str_starts_with($hash, '$argon2id$')
            && !defined('PASSWORD_ARGON2ID')
            && function_exists('sodium_crypto_pwhash_str_verify')) {
            try {
                return sodium_crypto_pwhash_str_verify($hash, $password);
            } catch (\Throwable $e) {
                return false;
            }
        }
        return false;
    }
}

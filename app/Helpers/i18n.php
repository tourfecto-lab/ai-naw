<?php

/**
 * Tourfecto - i18n / Translation Helper
 * نظام ترجمة بسيط وسريع: ملفات لغة PHP (مش JSON، أسرع في التحميل)،
 * كشف اللغة من ?lang= أو كوكي، ودعم RTL/LTR تلقائي.
 * الإنجليزي هو اللغة الافتراضية للموقع (base language).
 * @version 1.0.0
 */

if (!defined('UI_SUPPORTED_LANGUAGES')) {
    define('UI_SUPPORTED_LANGUAGES', ['en', 'ar', 'fr', 'de']);
}
if (!defined('UI_DEFAULT_LANGUAGE')) {
    define('UI_DEFAULT_LANGUAGE', 'en');
}
if (!defined('UI_RTL_LANGUAGES')) {
    define('UI_RTL_LANGUAGES', ['ar']);
}

if (!function_exists('current_lang')) {
    /**
     * تحديد اللغة الحالية: ?lang= في الرابط (وبيحفظها في كوكي)، أو الكوكي
     * المحفوظة من زيارة سابقة، أو الإنجليزي كافتراضي.
     * @return string
     */
    function current_lang(): string
    {
        static $lang = null;
        if ($lang !== null) {
            return $lang;
        }

        $requested = $_GET['lang'] ?? null;
        if ($requested && in_array($requested, UI_SUPPORTED_LANGUAGES, true)) {
            $lang = $requested;
            if (!headers_sent()) {
                setcookie('tf_lang', $lang, time() + 60 * 60 * 24 * 365, '/');
            }
            return $lang;
        }

        $cookie = $_COOKIE['tf_lang'] ?? null;
        if ($cookie && in_array($cookie, UI_SUPPORTED_LANGUAGES, true)) {
            $lang = $cookie;
            return $lang;
        }

        $accountLanguage=$_SESSION['user']['language'] ?? null;
        $lang=in_array($accountLanguage,UI_SUPPORTED_LANGUAGES,true)?$accountLanguage:UI_DEFAULT_LANGUAGE;
        return $lang;
    }
}

if (!function_exists('current_dir')) {
    /**
     * اتجاه الصفحة (rtl/ltr) حسب اللغة الحالية.
     * @return string
     */
    function current_dir(): string
    {
        return in_array(current_lang(), UI_RTL_LANGUAGES, true) ? 'rtl' : 'ltr';
    }
}

if (!function_exists('load_translations')) {
    /**
     * تحميل قاموس ترجمة لغة معيّنة (مع cache في نفس الـ request).
     * @param string $lang
     * @return array
     */
    function load_translations(string $lang): array
    {
        static $cache = [];
        if (isset($cache[$lang])) {
            return $cache[$lang];
        }

        $path = __DIR__ . '/../Lang/' . $lang . '.php';
        if (!file_exists($path)) {
            $path = __DIR__ . '/../Lang/' . UI_DEFAULT_LANGUAGE . '.php';
        }

        $cache[$lang] = file_exists($path) ? require $path : [];
        return $cache[$lang];
    }
}

if (!function_exists('t')) {
    /**
     * ترجمة مفتاح لنص باللغة الحالية (أو لغة محدّدة).
     * بيرجع المفتاح نفسه لو الترجمة مش موجودة (بدل ما يفضل فاضي)،
     * عشان يبقى واضح في الواجهة إن في مفتاح ناقص لسه، مش نص مخفي.
     * دعم متغيرات بسيط: t('welcome', ['name' => 'Ahmed']) بيستبدل {name}.
     * @param string $key
     * @param array $vars
     * @param string|null $langOverride
     * @return string
     */
    function t(string $key, array $vars = [], ?string $langOverride = null): string
    {
        $lang = $langOverride ?? current_lang();
        $dict = load_translations($lang);

        $value = $dict[$key] ?? null;

        // English is the canonical base fallback. Never fall back from French/German
        // to Arabic just because Arabic is the default UI language; that caused mixed-language pages.
        if ($value === null && $lang !== 'en') {
            $value = load_translations('en')[$key] ?? null;
        }

        // Last-resort fallback to the configured default only when English itself lacks the key.
        if ($value === null && UI_DEFAULT_LANGUAGE !== 'en' && $lang !== UI_DEFAULT_LANGUAGE) {
            $value = load_translations(UI_DEFAULT_LANGUAGE)[$key] ?? null;
        }

        if ($value === null) {
            return $key;
        }

        if (!empty($vars)) {
            foreach ($vars as $k => $v) {
                $value = str_replace('{' . $k . '}', (string) $v, $value);
            }
        }

        return $value;
    }
}

if (!function_exists('language_switcher_links')) {
    /**
     * بيرجّع array من [code, label, url] لكل لغة مدعومة، بنفس الرابط
     * الحالي + ?lang= جديد، لاستخدامها في قائمة تبديل اللغة.
     * @return array
     */
    function language_switcher_links(): array
    {
        $labels = ['en' => 'English', 'ar' => 'العربية', 'fr' => 'Français', 'de' => 'Deutsch'];
        $current = current_lang();
        $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');

        $links = [];
        foreach (UI_SUPPORTED_LANGUAGES as $code) {
            $links[] = [
                'code' => $code,
                'label' => $labels[$code] ?? strtoupper($code),
                'url' => $path . '?lang=' . $code,
                'active' => $code === $current,
            ];
        }
        return $links;
    }

    /**
     * رقم إصدار لكسر التخزين المؤقت (cache-busting) لأي أصل ثابت (CSS/JS).
     * بيرجع وقت آخر تعديل حقيقي للملف - يعني أي تعديل مستقبلي في الملف
     * بيغيّر الرقم ده تلقائيًا، فالمتصفح يعرف يجيب النسخة الجديدة رغم
     * التخزين المؤقت الطويل (30 يوم) اللي ضفناه في .htaccess.
     * @param string $relativePath زي '/assets/css/panel.css'
     */
    function asset_v(string $relativePath): string
    {
        $fullPath = defined('ROOT_PATH') ? ROOT_PATH . '/public_html' . $relativePath : (__DIR__ . '/../../public_html' . $relativePath);
        $version = @filemtime($fullPath);
        return $relativePath . ($version ? '?v=' . $version : '');
    }

    /**
     * رقم واتساب الدعم/الاشتراكات - بيقرا من إعدادات النظام القابلة
     * للتعديل من لوحة الأدمن الأول، ويرجع لـ .env كاحتياط آمن.
     */
    function support_whatsapp_number(): string
    {
        $default = defined('SUPPORT_WHATSAPP_NUMBER') ? SUPPORT_WHATSAPP_NUMBER : '';
        if (!class_exists('SystemSettingsService')) {
            return $default;
        }
        try {
            return (new SystemSettingsService())->get('support_whatsapp_number', $default);
        } catch (Exception $e) {
            return $default;
        }
    }

    /**
     * اسم الموقع - بيقرا من إعدادات النظام القابلة للتعديل من لوحة
     * الأدمن الأول، ويرجع لـ APP_NAME كاحتياط آمن.
     */
    function site_name(): string
    {
        $default = defined('APP_NAME') ? APP_NAME : 'Tourfecto';
        if (!class_exists('SystemSettingsService')) {
            return $default;
        }
        try {
            return (new SystemSettingsService())->get('site_name', $default);
        } catch (Exception $e) {
            return $default;
        }
    }

    /**
     * HTML جاهز للوجو/اسم الموقع - لو فيه لوجو مرفوع بيعرضه بالارتفاع
     * المحدّد من الأدمن، وإلا بيرجع الاسم بس (بدون أيقونة إيموجي ثابتة).
     * @param bool $withEmoji لو true وملقاش لوجو، يحط 🌍 قبل الاسم (زي الشكل الأصلي)
     */
    function site_brand_html(bool $withEmoji = true): string
    {
        $name = htmlspecialchars(site_name(), ENT_QUOTES, 'UTF-8');

        // تخصيص الوكالات (White-Label): لو المستخدم الحالي عميل/مالك
        // وكالة وعندها لوجو مخصّص، نستخدمه بدل لوجو النظام العام.
        if (function_exists('current_user_agency_branding')) {
            $agencyBranding = current_user_agency_branding();
            if ($agencyBranding && !empty($agencyBranding['logo_path'])) {
                $logoUrl = htmlspecialchars($agencyBranding['logo_path'], ENT_QUOTES, 'UTF-8');
                return "<img src=\"{$logoUrl}\" alt=\"{$name}\" style=\"height:32px;width:auto;vertical-align:middle;\">";
            }
        }

        if (!class_exists('SystemSettingsService')) {
            return $withEmoji ? "🌍 {$name}" : $name;
        }
        try {
            $settings = new SystemSettingsService();
            $logoUrl = $settings->get('site_logo_url', '');
            $logoHeight = (int) $settings->get('site_logo_height', '32') ?: 32;
            // لوجو اترفع قبل الهوية الجديدة (اسم الملف فيه وقت الرفع: logo_<unix>_...): الهوية الجديدة مكانه.
            // أي لوجو يترفع بعدها من لوحة الأدمن بيرجع يظهر عادي.
            if ($logoUrl && preg_match('#/logo_(\d{9,11})_#', (string) $logoUrl, $lm) && (int) $lm[1] < 1792800000) {
                $logoUrl = '';
            }
            if ($logoUrl) {
                $logoUrlEsc = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
                // اللوجو + اسم الموقع (Tourfecto) جنبه
                return "<span style=\"display:inline-flex;align-items:center;gap:8px;\">"
                    . "<img src=\"{$logoUrlEsc}\" alt=\"{$name}\" style=\"height:{$logoHeight}px;width:auto;vertical-align:middle;\">"
                    . "<span style=\"font-weight:700;line-height:1;white-space:nowrap;color:#D4AF37;\">{$name}</span>"
                    . "</span>";
            }
        } catch (Exception $e) {
            // تجاهل - نرجع للاسم بس
        }
        // هوية Tourfecto: العلامة (T بخط رحلة ونجمة) + الاسم
        return site_brand_lockup_html(32);
    }

    /** العلامة + الاسم (الاسم بياخد لون النص، فبيشتغل في الوضع الفاتح والغامق) */
    function site_brand_lockup_html(int $size = 32): string
    {
        $name = htmlspecialchars(site_name(), ENT_QUOTES, 'UTF-8');
        // Tourfecto بلونين (Tour + fecto)؛ أي اسم تاني بيتعرض زي ما هو
        // &#8288; (Word Joiner) بعد Tour: من غيره الترجمة العربي التلقائية بتترجم "Tour" لوحدها ("رحلة سياحيةfecto")
        $word = strcasecmp(site_name(), 'Tourfecto') === 0 ? 'Tour&#8288;<span style="color:#C99A3B">fecto</span>' : $name;
        return '<span class="tf-brand-lockup" translate="no" data-no-translate style="display:inline-flex;align-items:center;gap:9px;">'
            . '<img src="/assets/brand/tf-mark.svg?v=tf-brand-20261024" alt="" width="' . $size . '" height="' . $size . '" style="display:block;width:' . $size . 'px;height:' . $size . 'px;border-radius:' . (int) round($size * .23) . 'px;">'
            . '<span style="font-weight:800;letter-spacing:-.3px;line-height:1;white-space:nowrap;font-size:' . (int) round($size * .62) . 'px;">' . $word . '</span></span>';
    }

    /** اللوجو المتحرك (تحميل، توليد، وبين الصفحات) - بيتحط في <head> كل القوالب مع الأيقونة */
    function site_brand_assets_html(): string
    {
        $css = function_exists('asset_v') ? asset_v('/assets/css/tf-brand.css') : '/assets/css/tf-brand.css?v=tf-brand-20261024';
        $js = function_exists('asset_v') ? asset_v('/assets/js/tf-brand.js') : '/assets/js/tf-brand.js?v=tf-brand-20261024';
        return '<link rel="stylesheet" href="' . htmlspecialchars($css, ENT_QUOTES, 'UTF-8') . '">' . "\n    "
            . '<script src="' . htmlspecialchars($js, ENT_QUOTES, 'UTF-8') . '" defer></script>';
    }

    function site_favicon_html(): string
    {
        return site_favicon_tags_html() . "\n    " . site_brand_assets_html();
    }

    /**
     * وسوم <link> جاهزة لأيقونة تبويب المتصفح (Favicon) - لو فيه أيقونة
     * مرفوعة من لوحة الأدمن بيستخدمها، وإلا بيرجع الملفات الثابتة
     * الافتراضية (نفس الموجودة في public_html/assets/icons).
     */
    function site_favicon_tags_html(): string
    {
        $v = 'tf-brand-20261024';
        $default = '<link rel="icon" type="image/svg+xml" href="/assets/brand/tf-mark.svg?v=' . $v . '">' . "\n"
            // جوجل بيعرض أيقونة الموقع في النتايج من مقاس 48 أو مضاعفاته
            . '    <link rel="icon" type="image/png" sizes="48x48" href="/assets/icons/favicon-48.png?v=' . $v . '">' . "\n"
            . '    <link rel="icon" type="image/png" sizes="96x96" href="/assets/icons/favicon-96.png?v=' . $v . '">' . "\n"
            . '    <link rel="icon" type="image/png" sizes="192x192" href="/assets/icons/icon-192.png?v=' . $v . '">' . "\n"
            . '    <link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32.png?v=' . $v . '">' . "\n"
            . '    <link rel="icon" type="image/png" sizes="16x16" href="/assets/icons/favicon-16.png?v=' . $v . '">' . "\n"
            . '    <link rel="apple-touch-icon" sizes="180x180" href="/assets/icons/apple-touch-icon.png?v=' . $v . '">' . "\n"
            . '    <meta name="theme-color" content="#0A1A2E">';

        // تخصيص الوكالات (White-Label): لو الوكالة عندها أيقونة مخصّصة نستخدمها
        if (function_exists('current_user_agency_branding')) {
            $agencyBranding = current_user_agency_branding();
            if ($agencyBranding && !empty($agencyBranding['favicon_path'])) {
                $esc = htmlspecialchars($agencyBranding['favicon_path'], ENT_QUOTES, 'UTF-8');
                return "<link rel=\"icon\" href=\"{$esc}\">\n    <link rel=\"apple-touch-icon\" href=\"{$esc}\">";
            }
        }

        if (!class_exists('SystemSettingsService')) {
            return $default;
        }
        try {
            $settings = new SystemSettingsService();
            $faviconUrl = $settings->get('site_favicon_url', '');
            // الأيقونة القديمة الثابتة (tourfecto-favicon.png) = الافتراضي: الهوية الجديدة بكل مقاساتها
            if ($faviconUrl && !str_starts_with((string) $faviconUrl, '/assets/icons/tourfecto-favicon.png')) {
                $esc = htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8');
                return "<link rel=\"icon\" href=\"{$esc}\">\n    <link rel=\"apple-touch-icon\" href=\"{$esc}\">";
            }
        } catch (Exception $e) {
            // تجاهل - نرجع للأيقونة الافتراضية
        }
        return $default;
    }

    /**
     * تخصيص الوكالة (White-Label): بيرجّع سجل agency_branding الخاص
     * بوكالة المستخدم الحالي - بيكتشفها من agency_clients لو المستخدم
     * عميل نشط لوكالة، أو من agencies لو هو مالك الوكالة (نفس نمط
     * Controller::loadAuthenticatedUser: $_SESSION['user'] أو
     * $_SERVER['auth_user']). بيرجّع null لو مفيش وكالة/براندنج، وبيخزن
     * في static كاش جوه نفس الطلب (كل صفحات الموقع بتستخدمه مرة واحدة).
     * @return array|null
     */
    function current_user_agency_branding(): ?array
    {
        static $cached = false;
        if ($cached !== false) {
            return $cached;
        }
        $cached = null;

        $user = $_SESSION['user'] ?? ($_SERVER['auth_user'] ?? null);
        if (!is_array($user) || empty($user['id'])) {
            return $cached;
        }
        if (!class_exists('Database') || !class_exists('AgencyClient') || !class_exists('AgencyBranding')) {
            return $cached;
        }
        try {
            $db = Database::getInstance();
            $uid = (int) $user['id'];

            // وكالة المستخدم: عميل نشط (الوكالة اللي بيعمل بيها) أو مالك وكالة.
            $rows = $db->query(
                "SELECT a.id AS agency_id
                 FROM agencies a
                 JOIN agency_clients ac ON ac.agency_id = a.id
                 WHERE ac.client_user_id = ? AND ac.status = 'active'
                 UNION
                 SELECT a2.id AS agency_id
                 FROM agencies a2
                 WHERE a2.owner_user_id = ?
                 ORDER BY agency_id DESC
                 LIMIT 1",
                [$uid, $uid]
            );
            if (empty($rows)) {
                return $cached;
            }

            $branding = $db->query(
                'SELECT agency_id, logo_path, favicon_path, primary_color, secondary_color, custom_css, support_email
                 FROM agency_branding
                 WHERE agency_id = ? LIMIT 1',
                [(int) $rows[0]['agency_id']]
            );
            if (!empty($branding)) {
                $cached = $branding[0];
            }
        } catch (Exception $e) {
            $cached = null;
        }
        return $cached;
    }
}
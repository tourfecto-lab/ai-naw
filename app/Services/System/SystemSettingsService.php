<?php

/**
 * Tourfecto - System Settings Service
 * القراءة والكتابة الآمنة لكل إعدادات النظام (مفاتيح API، بيانات الربط)
 * القابلة للتعديل من لوحة الأدمن. القيم الحساسة بتتشفّر في قاعدة
 * البيانات، ومفيش قيمة حساسة كاملة بترجع للواجهة الأمامية أبدًا.
 * @version 1.0.0
 */
class SystemSettingsService
{
    /** @var Database */
    private $db;
    /** @var Encryption */
    private $encryption;

    /** @var array السجل الكامل لكل إعدادات النظام المعروفة */
    private static array $registry = [
        'geo_enabled'=>['category'=>'geo','label'=>'تفعيل قياس ظهور AI (1 فقط)','is_secret'=>false,'constant'=>''],
        'geo_openai_enabled'=>['category'=>'geo','label'=>'openai تفعيل','is_secret'=>false,'constant'=>''],
        'geo_openai_key'=>['category'=>'geo','label'=>'openai API Key','is_secret'=>true,'constant'=>''],
        // الاسم اللي بيقراه قياس الظهور وبتحفظه شاشة الإعدادات فعلًا؛ من غير تسجيله هنا كان بيتحفظ كنص صريح غير مشفّر
        'geo_openai_api_key'=>['category'=>'geo','label'=>'openai GEO API Key','is_secret'=>true,'constant'=>''],
        'geo_openai_model'=>['category'=>'geo','label'=>'openai اسم الموديل الدقيق','is_secret'=>false,'constant'=>''],
        'geo_openai_monthly_budget_usd'=>['category'=>'geo','label'=>'openai ميزانية شهرية USD','is_secret'=>false,'constant'=>''],
        'geo_openai_input_usd_per_million'=>['category'=>'geo','label'=>'openai سعر مليون input tokens USD','is_secret'=>false,'constant'=>''],
        'geo_openai_output_usd_per_million'=>['category'=>'geo','label'=>'openai سعر مليون output tokens USD','is_secret'=>false,'constant'=>''],
        'geo_openai_search_usd_per_unit'=>['category'=>'geo','label'=>'openai سعر وحدة البحث USD','is_secret'=>false,'constant'=>''],
        'geo_openai_reserve_usd_per_check'=>['category'=>'geo','label'=>'openai أقصى احتياطي للسؤال USD (الحد الأدنى 0.10)','is_secret'=>false,'constant'=>''],
        'geo_gemini_enabled'=>['category'=>'geo','label'=>'gemini تفعيل','is_secret'=>false,'constant'=>''],
        'geo_gemini_key'=>['category'=>'geo','label'=>'gemini API Key','is_secret'=>true,'constant'=>''],
        // الاسم اللي بيقراه قياس الظهور وبتحفظه شاشة الإعدادات فعلًا؛ من غير تسجيله هنا كان بيتحفظ كنص صريح غير مشفّر
        'geo_gemini_api_key'=>['category'=>'geo','label'=>'gemini GEO API Key','is_secret'=>true,'constant'=>''],
        'geo_gemini_model'=>['category'=>'geo','label'=>'gemini اسم الموديل الدقيق','is_secret'=>false,'constant'=>''],
        'geo_gemini_monthly_budget_usd'=>['category'=>'geo','label'=>'gemini ميزانية شهرية USD','is_secret'=>false,'constant'=>''],
        'geo_gemini_input_usd_per_million'=>['category'=>'geo','label'=>'gemini سعر مليون input tokens USD','is_secret'=>false,'constant'=>''],
        'geo_gemini_output_usd_per_million'=>['category'=>'geo','label'=>'gemini سعر مليون output tokens USD','is_secret'=>false,'constant'=>''],
        'geo_gemini_search_usd_per_unit'=>['category'=>'geo','label'=>'gemini سعر وحدة البحث USD','is_secret'=>false,'constant'=>''],
        'geo_gemini_reserve_usd_per_check'=>['category'=>'geo','label'=>'gemini أقصى احتياطي للسؤال USD (الحد الأدنى 0.10)','is_secret'=>false,'constant'=>''],
        'geo_perplexity_enabled'=>['category'=>'geo','label'=>'perplexity تفعيل','is_secret'=>false,'constant'=>''],
        'geo_perplexity_key'=>['category'=>'geo','label'=>'perplexity API Key','is_secret'=>true,'constant'=>''],
        // الاسم اللي بيقراه قياس الظهور وبتحفظه شاشة الإعدادات فعلًا؛ من غير تسجيله هنا كان بيتحفظ كنص صريح غير مشفّر
        'geo_perplexity_api_key'=>['category'=>'geo','label'=>'perplexity GEO API Key','is_secret'=>true,'constant'=>''],
        'geo_perplexity_model'=>['category'=>'geo','label'=>'perplexity اسم الموديل الدقيق','is_secret'=>false,'constant'=>''],
        'geo_perplexity_monthly_budget_usd'=>['category'=>'geo','label'=>'perplexity ميزانية شهرية USD','is_secret'=>false,'constant'=>''],
        'geo_perplexity_input_usd_per_million'=>['category'=>'geo','label'=>'perplexity سعر مليون input tokens USD','is_secret'=>false,'constant'=>''],
        'geo_perplexity_output_usd_per_million'=>['category'=>'geo','label'=>'perplexity سعر مليون output tokens USD','is_secret'=>false,'constant'=>''],
        'geo_perplexity_search_usd_per_unit'=>['category'=>'geo','label'=>'perplexity سعر وحدة البحث USD','is_secret'=>false,'constant'=>''],
        'geo_perplexity_reserve_usd_per_check'=>['category'=>'geo','label'=>'perplexity أقصى احتياطي للسؤال USD (الحد الأدنى 0.10)','is_secret'=>false,'constant'=>''],

        'serp_monthly_budget_usd'=>['category'=>'seo','label'=>'ميزانية DataForSEO الشهرية USD (مطلوبة)','is_secret'=>false,'constant'=>''],
        'serp_daily_budget_usd'=>['category'=>'seo','label'=>'سقف يومي USD لكل صرف DataForSEO (0 أو فاضي = من غير سقف يومي)','is_secret'=>false,'constant'=>''],
        'serp_daily_keywords_per_site'=>['category'=>'seo','label'=>'أقصى عدد كلمات بتتفحص يوميًا لكل موقع وسوق (5 إلى 500، الافتراضي 60)','is_secret'=>false,'constant'=>''],
        'serp_signup_daily_usd'=>['category'=>'seo','label'=>'سقف يومي USD لتحليل المنافسين في التسجيل الذكي (افتراضي 1)','is_secret'=>false,'constant'=>''],
        'serp_reserve_per_unit_usd'=>['category'=>'seo','label'=>'احتياطي أقصى تكلفة للكلمة USD (الحد الأدنى 0.10)','is_secret'=>false,'constant'=>''],
        'serp_enabled' => ['category'=>'seo','label'=>'تفعيل تتبع الترتيب المدفوع (1 فقط)','is_secret'=>false,'constant'=>''],
        'serp_login' => ['category'=>'seo','label'=>'DataForSEO API Login','is_secret'=>true,'constant'=>''],
        'serp_password' => ['category'=>'seo','label'=>'DataForSEO API Password','is_secret'=>true,'constant'=>''],
        'serp_location_code' => ['category'=>'seo','label'=>'SERP Location Code','is_secret'=>false,'constant'=>''],
        'serp_language_code' => ['category'=>'seo','label'=>'SERP Language Code','is_secret'=>false,'constant'=>''],
        'serp_device' => ['category'=>'seo','label'=>'SERP Device (desktop/mobile)','is_secret'=>false,'constant'=>''],

        'gemini_api_key' => ['category' => 'ai', 'label' => 'Gemini API Key', 'is_secret' => true, 'constant' => 'GEMINI_API_KEY'],

        'google_client_id' => ['category' => 'google', 'label' => 'Google Client ID', 'is_secret' => false, 'constant' => 'GOOGLE_CLIENT_ID'],
        'google_client_secret' => ['category' => 'google', 'label' => 'Google Client Secret', 'is_secret' => true, 'constant' => 'GOOGLE_CLIENT_SECRET'],
        'google_maps_api_key' => ['category' => 'google', 'label' => 'Google Maps / Places API Key', 'is_secret' => true, 'constant' => 'GOOGLE_MAPS_API_KEY'],

        'meta_app_id' => ['category' => 'meta', 'label' => 'Meta App ID', 'is_secret' => false, 'constant' => 'META_APP_ID'],
        'meta_app_secret' => ['category' => 'meta', 'label' => 'Meta App Secret', 'is_secret' => true, 'constant' => 'META_APP_SECRET'],
        'meta_login_config_id' => ['category' => 'meta', 'label' => 'Facebook Login for Business - Config ID (ربط الإعلانات والصفحات)', 'is_secret' => false, 'constant' => ''],
        'meta_signin_config_id' => ['category' => 'meta', 'label' => 'Config ID لتسجيل الدخول بفيسبوك', 'is_secret' => false, 'constant' => ''],
        'meta_wa_config_id' => ['category' => 'meta', 'label' => 'WhatsApp Embedded Signup - Config ID', 'is_secret' => false, 'constant' => ''],
        // === واتساب بمسح QR: بوابة Evolution API متعددة الأرقام (الأدمن بيظبطها مرة، العملاء بيمسحوا الكود بس) ===
        'wa_qr_enabled' => ['category' => 'whatsapp_qr', 'label' => 'تفعيل ربط واتساب بـ QR (1 فقط)', 'is_secret' => false, 'constant' => ''],
        'wa_qr_base_url' => ['category' => 'whatsapp_qr', 'label' => 'رابط بوابة واتساب (Evolution API)', 'is_secret' => false, 'constant' => ''],
        'wa_qr_api_key' => ['category' => 'whatsapp_qr', 'label' => 'المفتاح العام للبوابة (AUTHENTICATION_API_KEY)', 'is_secret' => true, 'constant' => ''],
        // === Stripe Connect (العميل بيربط حساب Stripe بتاعه بتسجيل الدخول) ===
        'stripe_connect_client_id' => ['category' => 'stripe', 'label' => 'Stripe Connect Client ID (ca_...)', 'is_secret' => false, 'constant' => 'STRIPE_CONNECT_CLIENT_ID'],
        'stripe_platform_secret_key' => ['category' => 'stripe', 'label' => 'Stripe Secret Key بتاع المنصة', 'is_secret' => true, 'constant' => 'STRIPE_API_KEY'],
        // === TikTok (تسجيل دخول العميل لحسابه) ===
        'tiktok_client_key' => ['category' => 'tiktok', 'label' => 'TikTok Client Key', 'is_secret' => false, 'constant' => 'TIKTOK_CLIENT_KEY'],
        'tiktok_client_secret' => ['category' => 'tiktok', 'label' => 'TikTok Client Secret', 'is_secret' => true, 'constant' => 'TIKTOK_CLIENT_SECRET'],
        'meta_oauth_scopes' => ['category' => 'meta', 'label' => 'صلاحيات Meta المطلوبة (لو مش Login for Business)', 'is_secret' => false, 'constant' => ''],

        // === Google Ads (بيستخدم google_client_id/secret فوق لتسجيل الدخول، ومحتاج Developer Token خاص بيه) ===
        'google_ads_developer_token' => ['category' => 'google_ads', 'label' => 'Google Ads Developer Token', 'is_secret' => true, 'constant' => 'GOOGLE_ADS_DEVELOPER_TOKEN'],
        'google_ads_login_customer_id' => ['category' => 'google_ads', 'label' => 'Manager Account ID (MCC) - اختياري', 'is_secret' => false, 'constant' => 'GOOGLE_ADS_LOGIN_CUSTOMER_ID'],

        'support_whatsapp_number' => ['category' => 'whatsapp', 'label' => 'رقم واتساب الدعم/الاشتراكات', 'is_secret' => false, 'constant' => 'SUPPORT_WHATSAPP_NUMBER'],

        // === تسجيل الدخول الاجتماعي (Google/Facebook بيستخدموا نفس بيانات الاعتماد فوق) ===
        'oauth_microsoft_client_id' => ['category' => 'oauth_login', 'label' => 'Microsoft Client ID', 'is_secret' => false, 'constant' => ''],
        'oauth_microsoft_client_secret' => ['category' => 'oauth_login', 'label' => 'Microsoft Client Secret', 'is_secret' => true, 'constant' => ''],
        'oauth_microsoft_tenant' => ['category' => 'oauth_login', 'label' => 'Microsoft Tenant (افتراضي: common)', 'is_secret' => false, 'constant' => ''],
        'oauth_apple_client_id' => ['category' => 'oauth_login', 'label' => 'Apple Services ID (client_id)', 'is_secret' => false, 'constant' => ''],
        'oauth_apple_team_id' => ['category' => 'oauth_login', 'label' => 'Apple Team ID', 'is_secret' => false, 'constant' => ''],
        'oauth_apple_key_id' => ['category' => 'oauth_login', 'label' => 'Apple Key ID', 'is_secret' => false, 'constant' => ''],
        'oauth_apple_private_key' => ['category' => 'oauth_login', 'label' => 'Apple Private Key (.p8)', 'is_secret' => true, 'constant' => ''],

        'mail_host' => ['category' => 'mail', 'label' => 'SMTP Host', 'is_secret' => false, 'constant' => 'MAIL_HOST'],
        'mail_port' => ['category' => 'mail', 'label' => 'SMTP Port', 'is_secret' => false, 'constant' => 'MAIL_PORT'],
        'mail_username' => ['category' => 'mail', 'label' => 'SMTP Username', 'is_secret' => false, 'constant' => 'MAIL_USERNAME'],
        'mail_password' => ['category' => 'mail', 'label' => 'SMTP Password', 'is_secret' => true, 'constant' => 'MAIL_PASSWORD'],
        'mail_encryption' => ['category' => 'mail', 'label' => 'SMTP Encryption (tls/ssl)', 'is_secret' => false, 'constant' => 'MAIL_ENCRYPTION'],
        'mail_from_address' => ['category' => 'mail', 'label' => 'Mail From Address', 'is_secret' => false, 'constant' => 'MAIL_FROM_ADDRESS'],
        'mail_from_name' => ['category' => 'mail', 'label' => 'Mail From Name', 'is_secret' => false, 'constant' => 'MAIL_FROM_NAME'],

        // === هوية الموقع (الاسم، اللوجو، بيانات التواصل) ===
        'site_name' => ['category' => 'branding', 'label' => 'اسم الموقع', 'is_secret' => false, 'constant' => 'APP_NAME'],
        'site_logo_url' => ['category' => 'branding', 'label' => 'رابط اللوجو', 'is_secret' => false, 'constant' => ''],
        'site_logo_height' => ['category' => 'branding', 'label' => 'ارتفاع اللوجو (بكسل)', 'is_secret' => false, 'constant' => ''],
        'site_favicon_url' => ['category' => 'branding', 'label' => 'رابط أيقونة المتصفح (Favicon)', 'is_secret' => false, 'constant' => ''],
        'contact_phone' => ['category' => 'branding', 'label' => 'رقم الهاتف العام', 'is_secret' => false, 'constant' => ''],
        'contact_email' => ['category' => 'branding', 'label' => 'البريد الإلكتروني العام', 'is_secret' => false, 'constant' => ''],
        'site_address' => ['category' => 'branding', 'label' => 'العنوان', 'is_secret' => false, 'constant' => ''],

        // === المحتوى القانوني (شروط الخدمة وسياسة الخصوصية) ===
        'terms_content' => ['category' => 'legal', 'label' => 'شروط الخدمة', 'is_secret' => false, 'constant' => ''],
        'privacy_content' => ['category' => 'legal', 'label' => 'سياسة الخصوصية', 'is_secret' => false, 'constant' => ''],
        'refund_policy_content' => ['category' => 'legal', 'label' => 'سياسة الاسترجاع', 'is_secret' => false, 'constant' => ''],
    ];

    /** هل المفتاح ده إعداد معروف في صفحة الإعدادات (google_client_id ...) */
    public static function hasKey(string $key): bool
    {
        return isset(self::$registry[$key]);
    }

    public function getConnectionValue(string $key): string
    {
        $value=$this->get($key);if($value!=='')return $value;
        $value=$this->get('integration_'.$key);if($value!=='')return $value;
        $constant=self::$registry[$key]['constant']??'';if($constant!==''&&defined($constant))return (string)constant($constant);
        $envKey=strtoupper($key);$value=function_exists('env')?env($envKey):getenv($envKey);return is_string($value)?$value:'';
    }
    public static function connectionKeys(): array
    {
        $keys=AdminConnectionTester::fieldKeys();
        foreach(self::$registry as $key=>$meta)if(in_array($meta['category'],['geo','seo','mail','oauth_login'],true))$keys[]=$key;
        return array_values(array_unique($keys));
    }
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->encryption = new Encryption();
    }

    /**
     * قراءة إعداد - بيفحص قاعدة البيانات الأول، ولو مش موجود أو فاضي
     * بيرجع للقيمة الافتراضية (عادةً القيمة الحالية في .env). آمن 100%
     * حتى لو الجدول مش موجود أو فاضي.
     */
    public function get(string $key, string $default = ''): string
    {
        try {
            $rows = $this->db->query("SELECT setting_value, is_secret FROM system_settings WHERE setting_key = ? LIMIT 1", [$key]);
            if (empty($rows) || $rows[0]['setting_value'] === null || $rows[0]['setting_value'] === '') {
                return $default;
            }
            $value = $rows[0]['setting_value'];
            $plain = (bool) $rows[0]['is_secret'] ? $this->encryption->decrypt($value) : $value;
            // قيمة سرية اتحفظت قبل التسجيل كنص صريح: نشفّرها مكانها من أول قراءة
            if (!(bool) $rows[0]['is_secret'] && !empty(self::$registry[$key]['is_secret'])) {
                $this->db->exec('UPDATE system_settings SET setting_value = ?, is_secret = 1 WHERE setting_key = ?', [$this->encryption->encrypt($plain), $key]);
            }

            // إصلاح: مفتاح Gemini المحفوظ من لوحة الأدمن لو كان قيمة غلط/قصيرة
            // (مفاتيح Google أطول من 20 حرف دايمًا) بيتجاهَل ونرجع لمفتاح .env بدل
            // ما كل طلبات الذكاء الاصطناعي تفشل بـ "API key not valid".
            if ($key === 'gemini_api_key' && strlen(trim($plain)) < 20) {
                return $default;
            }
            return $plain;
        } catch (Exception $e) {
            return $default;
        }
    }

    /** حفظ إعداد واحد - بيشفّر القيمة تلقائيًا لو الإعداد ده معروف إنه حساس */
    public function set(string $key, string $value): void
    {
        $meta = self::$registry[$key] ?? ['category' => 'general', 'is_secret' => false];
        // مفاتيح التكاملات (integration_openai_api_key ...) مش في الـ registry
        // فكانت بتتخزّن نص صريح. أي حاجة فيها مفتاح/سر/توكن تتشفّر.
        if (!isset(self::$registry[$key]) && strpos($key, 'integration_') === 0) {
            $meta = [
                'category' => 'integrations',
                'is_secret' => (bool) preg_match('/(key|secret|token|password|pass)$/', $key),
            ];
        }
        $isSecret = (bool) $meta['is_secret'];
        $storedValue = $isSecret && $value !== '' ? $this->encryption->encrypt($value) : $value;

        $this->db->exec(
            "INSERT INTO system_settings (setting_key, setting_value, is_secret, category) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_secret = VALUES(is_secret)",
            [$key, $storedValue, $isSecret ? 1 : 0, $meta['category']]
        );
    }

    /**
     * كل الإعدادات المعروفة مجهّزة لعرضها في لوحة الأدمن، مجمّعة حسب
     * التصنيف. القيم الحساسة بترجع مقنّعة (مفيش عرض خالص) - الأدمن
     * بيكتب قيمة جديدة كاملة لو عايز يغيّرها، مش بيشوف القديمة أبدًا.
     */
    public function getAllForAdmin(): array
    {
        $grouped = [];
        foreach (self::$registry as $key => $meta) {
            $default = '';
            if ($key === 'terms_content' && class_exists('LegalController')) {
                $default = LegalController::getDefaultTermsHtml();
            } elseif ($key === 'privacy_content' && class_exists('LegalController')) {
                $default = LegalController::getDefaultPrivacyHtml();
            } elseif (defined($meta['constant'])) {
                $default = (string) constant($meta['constant']);
            }

            $currentValue = $this->get($key, $default);
            $isSet = $currentValue !== '';

            $displayValue = ($isSet && !$meta['is_secret']) ? $currentValue : '';

            $grouped[$meta['category']][] = [
                'key' => $key,
                'label' => $meta['label'],
                'is_secret' => $meta['is_secret'],
                'is_set' => $isSet,
                'value' => $displayValue,
            ];
        }
        return $grouped;
    }
}

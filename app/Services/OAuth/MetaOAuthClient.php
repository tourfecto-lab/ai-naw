<?php

/**
 * Tourfecto - Meta (Facebook) OAuth Client
 * تدفّق OAuth 2.0 القياسي من Meta لربط حسابات Meta Ads الإعلانية
 * @version 1.0.0
 *
 * المتطلبات قبل ما ده يشتغل (خارج الكود، من Meta for Developers):
 *  1) تطبيق Meta من نوع "Business" على developers.facebook.com، مربوط
 *     بحساب Business Manager بتاعك.
 *  2) إضافة منتج "Marketing API" للتطبيق.
 *  3) صلاحيات (Permissions) مطلوبة: شوف SCOPE_GROUPS تحت (إعلانات + نشر صفحات + إنستجرام + رسايل)
 *     - في وضع Development دول شغالين بس على
 *     حسابات الإعلانات اللي انت admin عليها؛ عشان تشتغل مع عملاء تانيين
 *     لازم App Review رسمي من Meta.
 *  4) Valid OAuth Redirect URI في إعدادات التطبيق، لازم يطابق
 *     META_OAUTH_REDIRECT_URI تحت بالظبط.
 *  5) القيم دي في .env: META_APP_ID, META_APP_SECRET, META_OAUTH_REDIRECT_URI
 *
 * ملحوظة نسخة الـ API: Meta بتصدر نسخة جديدة كل ~3 شهور وبتوقف نسخ
 * قديمة بعد ~سنتين. النسخة الحالية وقت كتابة الكود ده v25.0 - لو
 * حصل خطأ "Unsupported API version" في المستقبل، حدّث META_API_VERSION
 * في .env لأحدث نسخة من developers.facebook.com/docs/graph-api/changelog.
 */
class MetaOAuthClient
{
    private string $apiVersion;
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    public function __construct()
    {
        $this->apiVersion = self::apiVersion();
        $this->clientId = self::setting('meta_app_id', 'META_APP_ID');
        $this->clientSecret = self::setting('meta_app_secret', 'META_APP_SECRET');
        // لو مش متحدد: نفس دومين المنصة (كان لازم يتكتب في .env وإلا الربط يقول "مش مفعّل")
        $this->redirectUri = self::setting('meta_oauth_redirect_uri', 'META_OAUTH_REDIRECT_URI') ?: self::defaultRedirectUri();
    }

    /** إعدادات الأدمن الأول، وبعدين .env (كان بيقرا .env بس - فلو اتكتبت من لوحة الأدمن الربط كان يقول مش مفعّل) */
    public static function setting(string $key, string $envKey): string
    {
        $v = '';
        if (class_exists('SystemSettingsService')) {
            try {
                $v = (string) (new SystemSettingsService())->get($key, '');
            } catch (Throwable $e) {
            }
        }
        if ($v === '' && function_exists('env')) {
            $v = (string) (env($envKey) ?: '');
        }
        if ($v === '' && defined($envKey)) {
            $v = (string) constant($envKey);
        }
        // قيم المثال (your-... / yourdomain.com) كأنها مش موجودة
        return (strpos($v, 'your-') === 0 || stripos($v, 'yourdomain') !== false) ? '' : trim($v);
    }

    public static function apiVersion(): string
    {
        $v = function_exists('env') ? (string) (env('META_API_VERSION') ?: '') : '';
        return preg_match('/^v\d+\.\d+$/', $v) ? $v : 'v25.0';
    }

    public static function baseUrl(): string
    {
        $u = defined('APP_URL') ? rtrim((string) APP_URL, '/') : '';
        return $u !== '' ? $u : 'https://tourfecto.pro';
    }

    public static function defaultRedirectUri(): string
    {
        return self::baseUrl() . '/ads/connect/meta/callback';
    }

    /**
     * Facebook Login for Business (تطبيقات نوع Business): Meta بتطلب config_id (إعداد بالصلاحيات بتتعمل في لوحة Meta)
     * بدل قايمة الصلاحيات. لو متحدد بنستخدمه، وإلا بنبعت scope (تطبيقات Facebook Login العادية).
     */
    public static function loginConfigId(): string
    {
        return preg_replace('/\D/', '', self::setting('meta_login_config_id', 'META_LOGIN_CONFIG_ID'));
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '' && $this->redirectUri !== '';
    }

    /**
     * بناء رابط "موافقة Meta" اللي هنوجّه العميل ليه.
     * @param string $state قيمة عشوائية موقّعة نتحقق منها وقت الرجوع (CSRF protection)
     */
    /**
     * الصلاحيات حسب الميزة. كانت الإعلانات بس - فالنشر على الصفحات وإنستجرام والرد على ماسنجر
     * كانوا بيفشلوا بخطأ صلاحيات حتى بعد الربط.
     * ⚠️ صلاحيات النشر والرسايل محتاجة App Review من Meta عشان تشتغل مع عملاء من بره فريق التطبيق.
     */
    public const SCOPE_GROUPS = [
        'ads' => ['ads_management', 'ads_read', 'business_management'],
        'pages' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'],
        'instagram' => ['instagram_basic', 'instagram_content_publish'],
        'messaging' => ['pages_messaging', 'instagram_manage_messages', 'pages_manage_metadata'],
    ];

    /** الصلاحيات اللي هنطلبها - قابلة للتغيير من META_OAUTH_SCOPES (مفصولة بفاصلة) لو Meta لسه ماوافقتش على بعضها */
    public static function scopes(): array
    {
        $custom = '';
        if (class_exists('SystemSettingsService')) {
            try {
                $custom = (string) (new SystemSettingsService())->get('meta_oauth_scopes', '');
            } catch (Throwable $e) {
            }
        }
        if ($custom === '' && function_exists('env')) {
            $custom = (string) env('META_OAUTH_SCOPES', '');
        }
        if (trim($custom) !== '') {
            $list = array_filter(array_map('trim', explode(',', $custom)), fn ($s) => preg_match('/^[a-z_]+$/', $s));
            // الإعلانات أساس الربط - مانشيلهاش حتى لو اتنست في الإعداد
            return array_values(array_unique(array_merge(self::SCOPE_GROUPS['ads'], ['pages_show_list'], $list)));
        }
        return array_values(array_unique(array_merge(...array_values(self::SCOPE_GROUPS))));
    }

    /**
     * صلاحيات "ربط السوشيال" بس (نشر فيسبوك + إنستجرام) - من غير الإعلانات (ads_management/business_management).
     * أقل صلاحيات = موافقة أسهل من العميل ومراجعة App Review أسرع. صفحات الـ Business Portfolio ممكن ما تظهرش
     * من غير business_management؛ لو محتاجها ضيفها في META_SOCIAL_EXTRA_SCOPES.
     * META_SOCIAL_EXTRA_SCOPES: صلاحيات إضافية مفصولة بفاصلة (مثلًا pages_manage_engagement,instagram_manage_comments لأول تعليق تلقائي)
     */
    public const SOCIAL_SCOPES = ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'instagram_basic', 'instagram_content_publish'];

    public static function socialScopes(): array
    {
        $extra = function_exists('env') ? (string) env('META_SOCIAL_EXTRA_SCOPES', '') : '';
        $list = array_filter(array_map('trim', explode(',', $extra)), fn ($s) => preg_match('/^[a-z_]+$/', $s));
        return array_values(array_unique(array_merge(self::SOCIAL_SCOPES, $list)));
    }

    /**
     * @param bool $rerequest لما العميل رفض صلاحية قبل كده - Meta مش بتسأله تاني إلا بالباراميتر ده
     * @param string $mode 'full' (الافتراضي: إعلانات + سوشيال + رسايل) أو 'social' (نشر بس)
     */
    public function buildAuthUrl(string $state, bool $rerequest = false, string $mode = 'full'): string
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'state' => $state,
        ];
        if ($mode === 'social') {
            // config_id الرئيسي غالبًا فيه صلاحيات الإعلانات: نستخدم config مخصص للسوشيال لو موجود، وإلا scopes صريحة
            $socialConfig = self::setting('meta_login_config_id_social', 'META_LOGIN_CONFIG_ID_SOCIAL');
            if ($socialConfig !== '') {
                $params['config_id'] = $socialConfig;
            } else {
                $params['scope'] = implode(',', self::socialScopes());
            }
        } elseif (($config = self::loginConfigId()) !== '') {
            $params['config_id'] = $config; // الصلاحيات متحددة في الإعداد ده على Meta
        } else {
            $params['scope'] = implode(',', self::scopes());
        }
        if ($rerequest) {
            $params['auth_type'] = 'rerequest';
        }

        return "https://www.facebook.com/{$this->apiVersion}/dialog/oauth?" . http_build_query($params);
    }

    /**
     * الصلاحيات اللي العميل وافق عليها فعلاً (Meta بتسمحله يشيل علامة من أي صلاحية وقت الموافقة).
     * @return array{success:bool, granted:array, declined:array, error:?string}
     */
    public function grantedPermissions(string $accessToken, ?callable $transport = null): array
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/me/permissions?access_token=" . rawurlencode($accessToken);
        try {
            if ($transport) {
                [$code, $body] = $transport($url);
            } else {
                $ch = curl_init($url);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
                $body = (string) curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
            }
            $data = json_decode((string) $body, true) ?: [];
            if ($code !== 200 || !isset($data['data'])) {
                return ['success' => false, 'granted' => [], 'declined' => [], 'error' => $data['error']['message'] ?? "HTTP {$code}"];
            }
            $granted = $declined = [];
            foreach ($data['data'] as $p) {
                if (($p['status'] ?? '') === 'granted') {
                    $granted[] = (string) $p['permission'];
                } else {
                    $declined[] = (string) $p['permission'];
                }
            }
            return ['success' => true, 'granted' => $granted, 'declined' => $declined, 'error' => null];
        } catch (Throwable $e) {
            return ['success' => false, 'granted' => [], 'declined' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * إيه الميزات اللي مش هتشتغل بسبب صلاحيات ناقصة (للعرض للعميل بالعربي)
     * @return array<string, array{label:string, missing:array}>
     */
    public static function missingFeatures(array $granted): array
    {
        $labels = [
            'pages' => 'النشر على صفحات فيسبوك',
            'instagram' => 'النشر على إنستجرام',
            'messaging' => 'الرد على رسايل ماسنجر وإنستجرام',
            'ads' => 'الإعلانات',
        ];
        $requested = self::scopes();
        $out = [];
        foreach (self::SCOPE_GROUPS as $g => $perms) {
            $missing = array_values(array_diff(array_intersect($perms, $requested), $granted));
            if ($missing) {
                $out[$g] = ['label' => $labels[$g], 'missing' => $missing];
            }
        }
        return $out;
    }

    /**
     * تبديل authorization code بتوكن وصول قصير المدى، وبعدين تلقائيًا
     * بتوكن طويل المدى (~60 يوم) عشان مانحتاجش نطلب من العميل يوافق
     * تاني كل شوية.
     * @return array ['success'=>bool, 'access_token'=>?, 'expires_in'=>?, 'error'=>?]
     */
    public function exchangeCodeForTokens(string $code): array
    {
        $shortLived = $this->httpGet('oauth/access_token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'code' => $code,
        ]);

        if (!$shortLived['success']) {
            return $shortLived;
        }

        // تبديل التوكن قصير المدى بتوكن طويل المدى (long-lived token)
        return $this->httpGet('oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'fb_exchange_token' => $shortLived['access_token'],
        ]);
    }

    /**
     * طلب GET عام لـ Graph API، مستخدم هنا لتبادل التوكنات.
     * @return array
     */
    private function httpGet(string $path, array $query): array
    {
        try {
            $url = "https://graph.facebook.com/{$this->apiVersion}/{$path}?" . http_build_query($query);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                return ['success' => false, 'error' => 'cURL Error: ' . $curlError];
            }

            $data = json_decode($response, true);

            if ($httpCode !== 200 || !isset($data['access_token'])) {
                return [
                    'success' => false,
                    'error' => $data['error']['message'] ?? "Meta OAuth error (HTTP {$httpCode})",
                ];
            }

            return [
                'success' => true,
                'access_token' => $data['access_token'],
                'expires_in' => (int) ($data['expires_in'] ?? 5184000), // ~60 يوم افتراضي للتوكن الطويل
            ];
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('Meta OAuth token request failed', ['error' => $e->getMessage()]);
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

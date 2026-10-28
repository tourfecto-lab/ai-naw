<?php

/**
 * Tourfecto - Google OAuth Client
 * تدفّق OAuth 2.0 القياسي من Google (Authorization Code flow)
 * @version 1.0.0
 *
 * المتطلبات قبل ما ده يشتغل (خارج الكود، من Google Cloud Console):
 *  1) مشروع Google Cloud + شاشة موافقة OAuth (OAuth consent screen).
 *  2) تفعيل "Google Business Profile API" (محتاج طلب وصول رسمي من Google
 *     نفسها، مش مجرد enable - شوف docs/GOOGLE_BUSINESS_SETUP.md).
 *  3) إنشاء OAuth 2.0 Client ID (نوع Web application) وتحديد الـ
 *     Authorized redirect URI بالظبط زي GOOGLE_OAUTH_REDIRECT_URI تحت.
 *  4) القيم دي في .env: GOOGLE_OAUTH_CLIENT_ID, GOOGLE_OAUTH_CLIENT_SECRET,
 *     GOOGLE_OAUTH_REDIRECT_URI
 */
class GoogleOAuthClient
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** الافتراضي (Google Business Profile) - محفوظ زي ما هو عشان الاستدعاءات القديمة متتكسرش */
    public const SCOPE_BUSINESS = 'https://www.googleapis.com/auth/business.manage';
    /** قراءة بيانات الأداء/الفهرسة من Search Console - readonly، مش محتاج تعديل */
    public const SCOPE_SEARCH_CONSOLE_WRITE = 'https://www.googleapis.com/auth/webmasters';
    public const SCOPE_SEARCH_CONSOLE = 'https://www.googleapis.com/auth/webmasters.readonly';
    /** إدارة كاملة لحسابات/حملات Google Ads (سحب حملات، إنشاء، تعديل ميزانية...) */
    public const SCOPE_ADS = 'https://www.googleapis.com/auth/adwords';
    /** قراءة بيانات Google Analytics 4 (GA4 Data API) - readonly */
    public const SCOPE_ANALYTICS = 'https://www.googleapis.com/auth/analytics.readonly';

    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private string $scope;

    /**
     * نفس عميل Google Cloud (client_id/secret) مستخدم لكل تكاملات Google،
     * لكن الـ scope بيتحدد حسب المنتج (Business Profile أو Search Console).
     * @param string $scope واحدة من ثوابت SCOPE_* فوق. افتراضيًا business.manage
     *   عشان الاستدعاءات القديمة (new GoogleOAuthClient() من غير args) تفضل شغالة زي ما هي.
     */
    public function __construct(string $scope = self::SCOPE_BUSINESS, ?string $redirectUri = null)
    {
        // تصحيح: كنا بنقرا القيم بـ getenv() مباشرة، وده بيرجع false دايمًا
        // على استضافات زي Hostinger لما putenv() تكون معطّلة (نفس المشكلة
        // الموثّقة في دالة env() بملف app/Helpers/functions.php). استخدام
        // دالة env() هنا بيضمن القراءة الصحيحة من $_ENV/$_SERVER كمان.
        $envClientId = defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : (env('GOOGLE_CLIENT_ID') ?: '');
        $envClientSecret = defined('GOOGLE_CLIENT_SECRET') ? GOOGLE_CLIENT_SECRET : (env('GOOGLE_CLIENT_SECRET') ?: '');
        // تصحيح: بيقرا من إعدادات النظام القابلة للتعديل من لوحة الأدمن
        // الأول، ويرجع لـ .env كاحتياط آمن.
        if (class_exists('SystemSettingsService')) {
            $settings = new SystemSettingsService();
            $this->clientId = $settings->get('google_client_id', $envClientId);
            $this->clientSecret = $settings->get('google_client_secret', $envClientSecret);
        } else {
            $this->clientId = $envClientId;
            $this->clientSecret = $envClientSecret;
        }
        $this->redirectUri = $redirectUri ?? (defined('GOOGLE_OAUTH_REDIRECT_URI') ? GOOGLE_OAUTH_REDIRECT_URI : (env('GOOGLE_OAUTH_REDIRECT_URI') ?: ''));
        $this->scope = $scope;
    }

    public function isConfigured(): bool
    {
        return is_real_secret($this->clientId) && is_real_secret($this->clientSecret) && $this->redirectUri !== '';
    }

    /**
     * بناء رابط "موافقة Google" اللي هنوجّه العميل ليه.
     * @param string $state قيمة عشوائية موقّعة نتحقق منها وقت الرجوع (CSRF protection)
     */
    public function buildAuthUrl(string $state): string
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => $this->scope,
            'access_type' => 'offline',   // مطلوب عشان ناخد refresh_token
            'prompt' => 'consent',        // يضمن رجوع refresh_token حتى لو العميل وافق قبل كده
            'state' => $state,
        ];

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * تبديل authorization code (اللي Google بترجعه في الـ callback) بتوكنات وصول حقيقية.
     * @return array ['success'=>bool, 'access_token'=>?, 'refresh_token'=>?, 'expires_in'=>?, 'error'=>?]
     */
    public function exchangeCodeForTokens(string $code): array
    {
        return $this->postToken([
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
        ]);
    }

    /**
     * تجديد access_token باستخدام refresh_token المحفوظ (access_token له
     * عمر قصير عادة ساعة واحدة، فلازم يتجدد بانتظام).
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        return $this->postToken([
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token',
        ]);
    }

    private function postToken(array $fields): array
    {
        try {
            $ch = curl_init(self::TOKEN_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($fields),
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
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
                    'error' => $data['error_description'] ?? $data['error'] ?? "Google OAuth error (HTTP {$httpCode})",
                ];
            }

            return [
                'success' => true,
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? null, // بيرجع بس أول مرة عادة
                'scope' => $data['scope'] ?? null, // الصلاحيات اللي العميل وافق عليها فعلًا (Google بيسمح يشيل علامة صلاحية)
                'expires_in' => (int) ($data['expires_in'] ?? 3600),
            ];
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('Google OAuth token request failed', ['error' => $e->getMessage()]);
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

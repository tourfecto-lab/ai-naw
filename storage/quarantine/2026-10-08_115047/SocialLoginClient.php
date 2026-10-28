<?php

/**
 * Tourfecto - Social Login Client (تسجيل الدخول بواسطة Google / Facebook / Microsoft)
 * تدفّق OAuth 2.0 القياسي (Authorization Code) لأغراض تسجيل الدخول فقط
 * (openid/email/profile) - مش نفس الـ OAuth بتاع ربط تكاملات Google
 * Business Profile أو Meta Ads (اللي ليهم عملاء منفصلين في GoogleOAuthClient
 * و MetaOAuthClient)، لكن بيعيد استخدام نفس بيانات اعتماد Google/Meta
 * (google_client_id/secret و meta_app_id/secret) لأن غالبًا نفس تطبيق
 * Google Cloud / Meta for Developers بيدعم أكتر من منتج (Business Profile
 * API + Google Sign-In، أو Marketing API + Facebook Login) - المطلوب بس
 * إضافة رابط الـ Redirect URI الخاص بتسجيل الدخول (تحت) في لوحة كل منصة.
 *
 * المتطلبات (خارج الكود):
 *  - Google: من Google Cloud Console، ضيف الرابط اللي بيرجّعه
 *    redirectUri('google') هنا كـ "Authorized redirect URI" على نفس
 *    الـ OAuth Client Id المستخدم بالفعل (google_client_id/secret من
 *    إعدادات لوحة الأدمن).
 *  - Facebook: من Meta for Developers، فعّل منتج "Facebook Login" على نفس
 *    التطبيق، وضيف نفس الرابط كـ "Valid OAuth Redirect URI"
 *    (meta_app_id/secret من إعدادات لوحة الأدمن).
 *  - Microsoft: من Azure Portal (App registrations)، سجّل تطبيق ويب،
 *    وضيف الرابط كـ Redirect URI، واحفظ oauth_microsoft_client_id /
 *    oauth_microsoft_client_secret من لوحة الأدمن (oauth_microsoft_tenant
 *    اختياري - افتراضيًا "common" يعني أي حساب Microsoft شخصي أو عمل).
 * @version 1.0.0
 */
class SocialLoginClient
{
    private const PROVIDERS = [
        'google' => [
            'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'userinfo_url' => 'https://www.googleapis.com/oauth2/v3/userinfo',
            'scope' => 'openid email profile',
            'client_id_key' => 'google_client_id',
            'client_secret_key' => 'google_client_secret',
        ],
        'facebook' => [
            'auth_url' => 'https://www.facebook.com/v21.0/dialog/oauth',
            'token_url' => 'https://graph.facebook.com/{META_API_VERSION}/oauth/access_token',
            'userinfo_url' => 'https://graph.facebook.com/me',
            'scope' => 'email public_profile',
            'client_id_key' => 'meta_app_id',
            'client_secret_key' => 'meta_app_secret',
        ],
        'microsoft' => [
            // {tenant} بيتعوّض ديناميكيًا في buildAuthUrl/postToken
            'auth_url' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize',
            'token_url' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token',
            'userinfo_url' => 'https://graph.microsoft.com/oidc/userinfo',
            'scope' => 'openid email profile',
            'client_id_key' => 'oauth_microsoft_client_id',
            'client_secret_key' => 'oauth_microsoft_client_secret',
        ],
    ];

    private string $provider;
    private array $config;
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private string $tenant;

    /**
     * @var callable|null حقنة اختيارية للاختبارات - بتستقبل وصف الطلب وترجع
     * رد محاكى ['body'=>string,'http_code'=>int,'error'=>?string] بدل curl.
     */
    private $transport;

    public function __construct(string $provider, ?callable $transport = null)
    {
        if (!isset(self::PROVIDERS[$provider])) {
            throw new InvalidArgumentException("منصة تسجيل دخول غير مدعومة: {$provider}");
        }
        $this->provider = $provider;
        $this->config = self::PROVIDERS[$provider];
        // إصدار Meta الموحّد (META_API_VERSION) - كان v21.0 ثابت
        $this->config['token_url'] = str_replace('{META_API_VERSION}', env('META_API_VERSION') ?: 'v25.0', $this->config['token_url']);
        $this->transport = $transport;

        $settings = class_exists('SystemSettingsService') ? new SystemSettingsService() : null;
        [$this->clientId, $this->clientSecret] = $this->resolveClientCredentials($settings);
        $this->tenant = $settings ? ($settings->get('oauth_microsoft_tenant', '') ?: 'common') : 'common';
        $this->redirectUri = self::redirectUri($provider);
    }

    private function resolveClientCredentials(?SystemSettingsService $settings): array
    {
        $envMap = [
            'google' => ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET'],
            'facebook' => ['META_APP_ID', 'META_APP_SECRET'],
        ];

        if (isset($envMap[$this->provider])) {
            [$idKey, $secretKey] = $envMap[$this->provider];
            $envClientId = defined($idKey) ? (string) constant($idKey) : (string) (env($idKey) ?: '');
            $envClientSecret = defined($secretKey) ? (string) constant($secretKey) : (string) (env($secretKey) ?: '');
        } else {
            $envClientId = '';
            $envClientSecret = '';
        }

        if (!$settings) {
            return [$envClientId, $envClientSecret];
        }

        $settingsClientId = $settings->get($this->config['client_id_key'], '');
        $settingsClientSecret = $settings->get($this->config['client_secret_key'], '');

        return [
            is_real_secret($settingsClientId) ? $settingsClientId : $envClientId,
            is_real_secret($settingsClientSecret) ? $settingsClientSecret : $envClientSecret,
        ];
    }

    public static function redirectUri(string $provider): string
    {
        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
        return "{$base}/auth/{$provider}/callback";
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    private function url(string $key): string
    {
        return str_replace('{tenant}', $this->tenant, $this->config[$key]);
    }

    public function buildAuthUrl(string $state): string
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => $this->config['scope'],
            'state' => $state,
        ];
        if ($this->provider === 'google') {
            $params['prompt'] = 'select_account';
        }
        return $this->url('auth_url') . '?' . http_build_query($params);
    }

    /** @return array ['success'=>bool, 'access_token'=>?, 'error'=>?] */
    public function exchangeCodeForToken(string $code): array
    {
        $fields = [
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
        ];

        try {
            $result = $this->httpRequest(
                'POST',
                $this->url('token_url'),
                ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
                http_build_query($fields)
            );

            if ($result['error']) {
                return ['success' => false, 'error' => 'cURL Error: ' . $result['error']];
            }

            $data = json_decode($result['body'], true);
            if ($result['http_code'] !== 200 || !isset($data['access_token'])) {
                return ['success' => false, 'error' => $data['error_description'] ?? $data['error'] ?? "OAuth token error (HTTP {$result['http_code']})"];
            }

            return ['success' => true, 'access_token' => $data['access_token']];
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('SocialLoginClient token exchange failed', ['provider' => $this->provider, 'error' => $e->getMessage()]);
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array|null ['id'=>string,'email'=>?string,'name'=>?string] أو null لو فشل */
    public function fetchProfile(string $accessToken): ?array
    {
        $url = $this->url('userinfo_url');
        if ($this->provider === 'facebook') {
            $url .= '?' . http_build_query(['fields' => 'id,name,email', 'access_token' => $accessToken]);
        }

        try {
            $headers = ['Accept: application/json'];
            if ($this->provider !== 'facebook') {
                $headers[] = 'Authorization: Bearer ' . $accessToken;
            }
            $result = $this->httpRequest('GET', $url, $headers);

            if ($result['error'] || $result['http_code'] !== 200) {
                return null;
            }
            $data = json_decode($result['body'], true);
            if (!is_array($data)) {
                return null;
            }

            $id = (string) ($data['sub'] ?? $data['id'] ?? '');
            if ($id === '') {
                return null;
            }

            return [
                'id' => $id,
                'email' => $data['email'] ?? null,
                'name' => $data['name'] ?? trim(($data['given_name'] ?? '') . ' ' . ($data['family_name'] ?? '')) ?: null,
            ];
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('SocialLoginClient fetchProfile failed', ['provider' => $this->provider, 'error' => $e->getMessage()]);
            }
            return null;
        }
    }

    /**
     * تنفيذ طلب HTTP عبر الـ transport الوهمي (لو محقون) أو curl العادي.
     * نفس بنية خيارات curl السابقة تمامًا - لا تغيير في سلوك الإنتاج.
     * @return array ['body'=>string,'http_code'=>int,'error'=>?string]
     */
    private function httpRequest(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if ($this->transport !== null) {
            $fake = call_user_func($this->transport, [
                'method' => $method,
                'url' => $url,
                'headers' => $headers,
                'body' => $body,
            ]);
            return [
                'body' => (string) ($fake['body'] ?? ''),
                'http_code' => (int) ($fake['http_code'] ?? 0),
                'error' => isset($fake['error']) ? (string) $fake['error'] : null,
            ];
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body ?? '';
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        return [
            'body' => (string) $response,
            'http_code' => (int) $httpCode,
            'error' => $curlError ?: null,
        ];
    }
}

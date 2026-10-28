<?php

/**
 * Tourfecto - SEO: Google Indexing API Service (G3)
 * @version 1.0.0
 *
 * يسد فجوة "الفهرسة لدى Google" (أهم محرك لقطاع السياحة) عبر Google
 * Indexing API الرسمية:
 *   POST https://indexing.googleapis.com/v3/urlNotifications:publish
 *   { "url": "...", "type": "URL_UPDATED" }
 *
 * المصادقة: OAuth 2.0 عبر Service Account (JWT RS256 مُوقّع بـ openssl).
 * الإعداد: متغير البيئة `GOOGLE_SERVICE_ACCOUNT_JSON` (base64 للـ JSON
 * الخاص بحساب الخدمة - client_email + private_key + token_uri).
 *
 * الـ Guardrail (NO FAKE DATA): لو المفتاح مش متظبط، كل دالة ترجع
 * available=false بسبب واضح "google_service_account_not_configured"
 * من غير أي محاولة تخمين/اختلاق. **لم يُختبَر** (بيحتاج حساب خدمة Google
 * حقيقي + تفعيل Indexing API) - مُوثّق في COMPETITIVE_ANALYSIS.
 */
class GoogleIndexingService
{
    private const ENDPOINT = 'https://indexing.googleapis.com/v3/urlNotifications:publish';
    private const SCOPE = 'https://www.googleapis.com/auth/indexing';

    /** @var array|null creds محللة من GOOGLE_SERVICE_ACCOUNT_JSON */
    private $creds;

    public function __construct()
    {
        $raw = (getenv('GOOGLE_SERVICE_ACCOUNT_JSON') ?: (isset($_ENV['GOOGLE_SERVICE_ACCOUNT_JSON']) ? (string) $_ENV['GOOGLE_SERVICE_ACCOUNT_JSON'] : false));
        if (!is_string($raw) || $raw === '') {
            $this->creds = null;
            return;
        }
        $decoded = json_decode(base64_decode($raw), true);
        if (is_array($decoded) && !empty($decoded['client_email']) && !empty($decoded['private_key'])) {
            $this->creds = $decoded;
        } else {
            $this->creds = null;
        }
    }

    /** هل حساب خدمة Google مهيأ فعلًا (creds صالحة) ويمكن استخدامه؟ */
    public function isConfigured(): bool
    {
        return $this->creds !== null && function_exists('openssl_sign');
    }

    /** سبب عدم التهيئة (للواجهة). */
    public function configReason(): ?string
    {
        if ($this->creds !== null) {
            return function_exists('openssl_sign') ? null : 'openssl extension missing';
        }
        return 'google_service_account_not_configured';
    }

    /**
     * إبلاغ Google بفهرسة URL واحد.
     * @return array{available:bool, reason:?string, success:bool, status:?int, error:?string}
     */
    public function notify(string $url, string $type = 'URL_UPDATED'): array
    {
        if (!$this->isConfigured()) {
            return ['available' => false, 'reason' => $this->configReason(), 'success' => false, 'status' => null, 'error' => 'Google Indexing API غير مهيأ (GOOGLE_SERVICE_ACCOUNT_JSON)'];
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ['available' => true, 'reason' => null, 'success' => false, 'status' => null, 'error' => 'رابط غير صالح'];
        }

        $token = $this->fetchAccessToken();
        if ($token === null) {
            return ['available' => true, 'reason' => null, 'success' => false, 'status' => null, 'error' => 'فشل الحصول على OAuth token من حساب الخدمة'];
        }

        $payload = json_encode(['url' => $url, 'type' => $type === 'URL_DELETED' ? 'URL_DELETED' : 'URL_UPDATED']);

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['available' => true, 'reason' => null, 'success' => false, 'status' => $code, 'error' => $error ?: 'network error'];
        }
        if ($code >= 200 && $code < 300) {
            return ['available' => true, 'reason' => null, 'success' => true, 'status' => $code, 'error' => null];
        }
        $decoded = json_decode((string) $body, true);
        $errMsg = $decoded['error']['message'] ?? $body;
        return ['available' => true, 'reason' => null, 'success' => false, 'status' => $code, 'error' => mb_substr((string) $errMsg, 0, 300)];
    }

    /**
     * إبلاغ Google بفهرسة موقع كامل (الرئيسية + آخر صفحات الزحف إن وُجدت).
     * يحترم عزل التينانت (تحقق website_id + user_id) و google_indexing_enabled.
     *
     * @param Database $db
     * @param int      $websiteId
     * @param int      $userId
     * @param string[] $extraUrls روابط إضافية (اختياري)
     * @return array{available:bool, reason:?string, success:bool, submitted:int, results:array, error:?string}
     */
    public function submitSite(Database $db, int $websiteId, int $userId, array $extraUrls = []): array
    {
        $sites = $db->query(
            "SELECT id, user_id, main_url, google_indexing_enabled FROM websites WHERE id = ? AND user_id = ? LIMIT 1",
            [$websiteId, $userId]
        );
        if (empty($sites)) {
            return ['available' => false, 'reason' => null, 'success' => false, 'submitted' => 0, 'results' => [], 'error' => 'الموقع غير موجود'];
        }
        $site = $sites[0];

        if (!(int) ($site['google_indexing_enabled'] ?? 0)) {
            return ['available' => false, 'reason' => 'google_indexing_disabled_for_website', 'success' => false, 'submitted' => 0, 'results' => [], 'error' => 'Google Indexing مش مفعّل على الموقع ده'];
        }

        $urls = [rtrim((string) $site['main_url'], '/') . '/'];
        foreach ($extraUrls as $u) {
            if (filter_var($u, FILTER_VALIDATE_URL)) {
                $urls[] = $u;
            }
        }
        $urls = array_values(array_unique($urls));

        $results = [];
        $submitted = 0;
        foreach ($urls as $u) {
            $res = $this->notify($u, 'URL_UPDATED');
            if (!$res['available']) {
                return $res;
            }
            $results[] = ['url' => $u, 'status' => $res['status'], 'error' => $res['error']];
            if ($res['success']) {
                $submitted++;
            }
        }

        if ($submitted > 0) {
            $db->exec("UPDATE websites SET last_google_indexed_at = NOW() WHERE id = ? AND user_id = ?", [$websiteId, $userId]);
        }

        return [
            'available' => true,
            'reason' => null,
            'success' => $submitted > 0,
            'submitted' => $submitted,
            'results' => $results,
            'error' => $submitted > 0 ? null : 'لم يتم قبول أي رابط',
        ];
    }

    /**
     * توليد OAuth access token من creds حساب الخدمة (JWT RS256).
     */
    public function fetchAccessToken(): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }
        $clientEmail = (string) $this->creds['client_email'];
        $privateKey = (string) $this->creds['private_key'];
        $tokenUri = (string) ($this->creds['token_uri'] ?? 'https://oauth2.googleapis.com/token');

        $now = time();
        $header = $this->b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->b64url(json_encode([
            'iss' => $clientEmail,
            'scope' => self::SCOPE,
            'aud' => $tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        $signingInput = $header . '.' . $claims;

        $signature = '';
        if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            return null;
        }
        $jwt = $signingInput . '.' . $this->b64url($signature);

        $ch = curl_init($tokenUri);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]),
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code < 200 || $code >= 300) {
            return null;
        }
        $decoded = json_decode((string) $body, true);
        return is_array($decoded) && !empty($decoded['access_token']) ? (string) $decoded['access_token'] : null;
    }

    /** Base64url (RFC 4648 §5) - بلا padding. */
    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

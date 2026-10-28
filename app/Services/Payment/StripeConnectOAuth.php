<?php
/**
 * Tourfecto - ربط حساب Stripe بتاع صاحب النشاط بتسجيل الدخول (Stripe Connect OAuth)
 *
 * الأدمن بيحط Client ID بتاع Connect + المفتاح السري للمنصة مرة واحدة. العميل بيضغط
 * "اربط Stripe"، يسجّل دخول في Stripe ويوافق، وبنستلم مفتاح خاص بحسابه أوتوماتيك -
 * من غير ما ينسخ أي مفتاح بإيده. الفلوس بتروح لحسابه هو مباشرة.
 */
final class StripeConnectOAuth
{
    private const AUTHORIZE = 'https://connect.stripe.com/oauth/authorize';
    private const TOKEN = 'https://connect.stripe.com/oauth/token';
    private const DEAUTHORIZE = 'https://connect.stripe.com/oauth/deauthorize';

    /** @var callable|null fn(string $url, array $form, string $platformKey): array{code:int, body:string} */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    public static function clientId(): string
    {
        return class_exists('MetaOAuthClient') ? MetaOAuthClient::setting('stripe_connect_client_id', 'STRIPE_CONNECT_CLIENT_ID') : '';
    }

    public static function platformKey(): string
    {
        return class_exists('MetaOAuthClient') ? MetaOAuthClient::setting('stripe_platform_secret_key', 'STRIPE_API_KEY') : '';
    }

    public static function configured(): bool
    {
        return preg_match('/^ca_[A-Za-z0-9]+$/', self::clientId()) === 1 && preg_match('/^sk_(live|test)_/', self::platformKey()) === 1;
    }

    public static function redirectUri(): string
    {
        return rtrim(defined('APP_URL') ? (string) APP_URL : '', '/') . '/payments/stripe/callback';
    }

    public static function authorizeUrl(string $state): string
    {
        return self::AUTHORIZE . '?' . http_build_query(['response_type' => 'code', 'client_id' => self::clientId(), 'scope' => 'read_write', 'state' => $state, 'redirect_uri' => self::redirectUri()]);
    }

    /** @return array{ok:bool, access_token?:string, account_id?:string, livemode?:bool, error?:string} */
    public function exchange(string $code): array
    {
        if (!preg_match('/^ac_[A-Za-z0-9]+$/', $code)) return ['ok' => false, 'error' => 'invalid_code'];
        $r = $this->post(self::TOKEN, ['grant_type' => 'authorization_code', 'code' => $code]);
        return self::parseToken($r);
    }

    public function deauthorize(string $accountId): bool
    {
        if (!preg_match('/^acct_[A-Za-z0-9]+$/', $accountId)) return false;
        $r = $this->post(self::DEAUTHORIZE, ['client_id' => self::clientId(), 'stripe_user_id' => $accountId]);
        return $r['code'] >= 200 && $r['code'] < 300;
    }

    public static function parseToken(array $r): array
    {
        $j = json_decode((string) ($r['body'] ?? ''), true);
        if (($r['code'] ?? 0) < 200 || ($r['code'] ?? 0) >= 300 || !is_array($j) || isset($j['error'])) {
            return ['ok' => false, 'error' => is_array($j) ? (string) ($j['error_description'] ?? $j['error'] ?? 'stripe_error') : 'stripe_unreachable'];
        }
        $token = (string) ($j['access_token'] ?? '');
        $acct = (string) ($j['stripe_user_id'] ?? '');
        if (!preg_match('/^(sk|rk)_(live|test)_[A-Za-z0-9]+$/', $token) || !preg_match('/^acct_[A-Za-z0-9]+$/', $acct)) {
            return ['ok' => false, 'error' => 'unexpected_response'];
        }
        return ['ok' => true, 'access_token' => $token, 'account_id' => $acct, 'livemode' => (bool) ($j['livemode'] ?? false)];
    }

    private function post(string $url, array $form): array
    {
        $key = self::platformKey();
        if ($this->transport) return ($this->transport)($url, $form, $key);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key]]);
        $out = curl_exec($ch);
        $r = ['code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => is_string($out) ? $out : ''];
        curl_close($ch);
        return $r;
    }
}

<?php

/**
 * Tourfecto - Threads API (Meta) - ربط حساب Threads ونشر نص/صورة/فيديو.
 *
 * التدفق الرسمي: OAuth (threads_basic + threads_content_publish) ← توكن قصير ← توكن 60 يوم (بيتجدد).
 * النشر: إنشاء container ‏(POST /{user}/threads) ← (فيديو/صورة: متابعة الحالة لحد FINISHED) ← POST /{user}/threads_publish.
 * حد النص 500 حرف. الإعدادات: THREADS_APP_ID / THREADS_APP_SECRET / THREADS_REDIRECT_URI.
 * @version 1.0.0
 */
final class ThreadsAPI
{
    public const SCOPES = 'threads_basic,threads_content_publish';
    public const TEXT_LIMIT = 500;
    private const BASE = 'https://graph.threads.net';

    /** @var callable|null fn(string $method, string $url, array $form): array{code:int,body:string,error:?string} */
    public static $transport = null;

    private string $token;
    private string $userId;

    public function __construct(string $accessToken, string $userId = 'me')
    {
        $this->token = $accessToken;
        $this->userId = $userId;
    }

    public static function configured(): bool
    {
        return (string) env('THREADS_APP_ID', '') !== '' && (string) env('THREADS_APP_SECRET', '') !== '';
    }

    public static function redirectUri(): string
    {
        return (string) (env('THREADS_REDIRECT_URI') ?: rtrim(defined('APP_URL') ? (string) APP_URL : '', '/') . '/social/connect/threads/callback');
    }

    public static function authorizeUrl(string $state): string
    {
        return 'https://threads.net/oauth/authorize?' . http_build_query([
            'client_id' => (string) env('THREADS_APP_ID', ''),
            'redirect_uri' => self::redirectUri(),
            'scope' => self::SCOPES,
            'response_type' => 'code',
            'state' => $state,
        ]);
    }

    /** كود ← توكن قصير ← توكن طويل (60 يوم) + المستخدم */
    public static function exchangeCode(string $code): array
    {
        $short = self::call('POST', self::BASE . '/oauth/access_token', [
            'client_id' => (string) env('THREADS_APP_ID', ''),
            'client_secret' => (string) env('THREADS_APP_SECRET', ''),
            'grant_type' => 'authorization_code',
            'redirect_uri' => self::redirectUri(),
            'code' => $code,
        ]);
        if (!$short['success'] || empty($short['data']['access_token'])) {
            return ['success' => false, 'error' => $short['error'] ?? 'Threads token exchange failed'];
        }
        $long = self::call('GET', self::BASE . '/access_token?' . http_build_query([
            'grant_type' => 'th_exchange_token',
            'client_secret' => (string) env('THREADS_APP_SECRET', ''),
            'access_token' => (string) $short['data']['access_token'],
        ]));
        $token = $long['success'] && !empty($long['data']['access_token']) ? (string) $long['data']['access_token'] : (string) $short['data']['access_token'];
        $expires = $long['success'] ? (int) ($long['data']['expires_in'] ?? 5184000) : 3600;
        return ['success' => true, 'access_token' => $token, 'expires_in' => $expires, 'user_id' => (string) ($short['data']['user_id'] ?? '')];
    }

    /** تجديد التوكن الطويل (لازم يكون عمره أكتر من 24 ساعة وأقل من 60 يوم) */
    public static function refresh(string $token): array
    {
        $r = self::call('GET', self::BASE . '/refresh_access_token?' . http_build_query(['grant_type' => 'th_refresh_token', 'access_token' => $token]));
        if (!$r['success'] || empty($r['data']['access_token'])) {
            return ['success' => false, 'error' => $r['error'] ?? 'refresh failed'];
        }
        return ['success' => true, 'access_token' => (string) $r['data']['access_token'], 'expires_in' => (int) ($r['data']['expires_in'] ?? 5184000)];
    }

    public function me(): array
    {
        $r = self::call('GET', self::BASE . '/v1.0/me?' . http_build_query(['fields' => 'id,username,name,threads_profile_picture_url', 'access_token' => $this->token]));
        $pic = (string) ($r['data']['threads_profile_picture_url'] ?? '');
        return $r['success'] ? ['success' => true, 'id' => (string) ($r['data']['id'] ?? ''), 'username' => (string) ($r['data']['username'] ?? ''), 'avatar' => str_starts_with($pic, 'https://') ? $pic : null] : $r;
    }

    /** إنشاء container: نص بس، أو صورة، أو فيديو. بيرجّع container_id */
    public function createContainer(string $text, ?string $imageUrl = null, ?string $videoUrl = null): array
    {
        $form = ['media_type' => $videoUrl ? 'VIDEO' : ($imageUrl ? 'IMAGE' : 'TEXT'), 'text' => mb_substr($text, 0, self::TEXT_LIMIT), 'access_token' => $this->token];
        if ($imageUrl && !$videoUrl) {
            $form['image_url'] = $imageUrl;
        }
        if ($videoUrl) {
            $form['video_url'] = $videoUrl;
        }
        $r = self::call('POST', self::BASE . '/v1.0/' . rawurlencode($this->userId) . '/threads', $form);
        if (!$r['success'] || empty($r['data']['id'])) {
            return ['success' => false, 'error' => $r['error'] ?? 'Threads rejected the post', 'definitive' => $r['definitive'] ?? false];
        }
        return ['success' => true, 'container_id' => (string) $r['data']['id']];
    }

    /** حالة الـ container: IN_PROGRESS | FINISHED | ERROR | EXPIRED | PUBLISHED */
    public function status(string $containerId): array
    {
        $r = self::call('GET', self::BASE . '/v1.0/' . rawurlencode($containerId) . '?' . http_build_query(['fields' => 'status,error_message', 'access_token' => $this->token]));
        return $r['success'] ? ['success' => true, 'status' => (string) ($r['data']['status'] ?? 'IN_PROGRESS'), 'error' => $r['data']['error_message'] ?? null] : $r;
    }

    public function publish(string $containerId): array
    {
        $r = self::call('POST', self::BASE . '/v1.0/' . rawurlencode($this->userId) . '/threads_publish', ['creation_id' => $containerId, 'access_token' => $this->token]);
        if (!$r['success'] || empty($r['data']['id'])) {
            return ['success' => false, 'error' => $r['error'] ?? 'Threads publish failed', 'definitive' => $r['definitive'] ?? false];
        }
        return ['success' => true, 'post_id' => (string) $r['data']['id']];
    }

    /** طلب HTTP: success/data/error/definitive (4xx = رفض واضح، شبكة/5xx = غامض) */
    private static function call(string $method, string $url, array $form = []): array
    {
        if (self::$transport) {
            $res = (self::$transport)($method, $url, $form);
        } else {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
            }
            $body = curl_exec($ch);
            $res = ['code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => is_string($body) ? $body : '', 'error' => $body === false ? curl_error($ch) : null];
            curl_close($ch);
        }
        if (!empty($res['error'])) {
            return ['success' => false, 'error' => 'Network error: ' . $res['error'], 'definitive' => false];
        }
        $data = json_decode((string) $res['body'], true);
        if ($res['code'] >= 200 && $res['code'] < 300 && is_array($data) && !isset($data['error'])) {
            return ['success' => true, 'data' => $data];
        }
        $msg = is_array($data) ? (string) ($data['error']['message'] ?? $data['error_message'] ?? 'HTTP ' . $res['code']) : 'HTTP ' . $res['code'];
        return ['success' => false, 'error' => $msg, 'definitive' => $res['code'] >= 400 && $res['code'] < 500, 'http_code' => $res['code']];
    }
}

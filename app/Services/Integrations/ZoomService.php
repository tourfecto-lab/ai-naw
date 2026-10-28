<?php

/**
 * Tourfecto - Zoom Integration (Server-to-Server OAuth)
 * @version 1.0.0
 *
 * إنشاء لقاءات Zoom برمجيًا (لاجتماعات الـ CRM أو مواعيد العملاء).
 * بيستخدم Server-to-Server OAuth (Account ID + Client ID + Client Secret)
 * ويخزّن الـ access_token في كاش ملف بسيط عشان مايطلبش توكن جديد مع كل نداء.
 */

class ZoomService extends BaseIntegrationService
{
    public function key(): string
    {
        return 'zoom';
    }

    public function isConfigured(): bool
    {
        return $this->conf('ZOOM_ACCOUNT_ID', 'ZOOM_ACCOUNT_ID') !== ''
            && $this->conf('ZOOM_CLIENT_ID', 'ZOOM_CLIENT_ID') !== ''
            && $this->conf('ZOOM_CLIENT_SECRET', 'ZOOM_CLIENT_SECRET') !== '';
    }

    private function cacheFile(): string
    {
        $dir = defined('TOURFECTO_STORAGE') ? TOURFECTO_STORAGE . '/cache' : sys_get_temp_dir() . '/tourfecto';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/zoom_access_token.json';
    }

    /**
     * الحصول على access_token (من الكاش لو لسه ساري، أو بطلب توكن جديد).
     */
    public function getAccessToken(): string
    {
        $file = $this->cacheFile();
        if (is_file($file)) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached) && ($cached['expires_at'] ?? 0) > (time() + 60)) {
                return (string) ($cached['access_token'] ?? '');
            }
        }

        $clientId = $this->conf('ZOOM_CLIENT_ID', 'ZOOM_CLIENT_ID');
        $clientSecret = $this->conf('ZOOM_CLIENT_SECRET', 'ZOOM_CLIENT_SECRET');
        $accountId = $this->conf('ZOOM_ACCOUNT_ID', 'ZOOM_ACCOUNT_ID');

        $result = $this->httpForm('POST', 'https://zoom.us/oauth/token', [
            'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
        ], [
            'grant_type' => 'account_credentials',
            'account_id'  => $accountId,
        ]);

        if (!$result['success'] || empty($result['data']['access_token'])) {
            $this->log('error', 'Zoom token exchange failed', $result['data'] ?? []);
            return '';
        }

        $token = (string) $result['data']['access_token'];
        $expiresIn = (int) ($result['data']['expires_in'] ?? 3600);
        @file_put_contents($file, json_encode([
            'access_token' => $token,
            'expires_at'   => time() + $expiresIn,
        ]));

        return $token;
    }

    /**
     * إنشاء لقاء Zoom.
     * @param array $params topic, start_time (YYYY-MM-DDTHH:MM:SSZ), duration (دقيقة), agenda, ...
     */
    public function createMeeting(array $params = []): array
    {
        $token = $this->getAccessToken();
        if ($token === '') {
            return ['success' => false, 'data' => null, 'error' => 'تعذر الحصول على Zoom access token', 'http_code' => 0];
        }

        $body = [
            'topic'      => $params['topic'] ?? 'Tourfecto Meeting',
            'type'       => 2, // scheduled meeting
            'start_time' => $params['start_time'] ?? date('c', time() + 3600),
            'duration'   => (int) ($params['duration'] ?? 30),
            'timezone'   => $params['timezone'] ?? 'UTC',
            'settings'   => [
                'host_video'        => true,
                'participant_video' => false,
                'join_before_host'  => true,
            ],
        ];
        if (!empty($params['agenda'])) {
            $body['agenda'] = $params['agenda'];
        }

        return $this->httpJson('POST', 'https://api.zoom.us/v2/users/me/meetings', [
            'Authorization: Bearer ' . $token,
        ], $body);
    }

    public function request(string $action, array $params = [], array $context = []): array
    {
        switch ($action) {
            case 'create_meeting':
                return $this->createMeeting($params);
            case 'test':
                $token = $this->getAccessToken();
                if ($token === '') {
                    return ['success' => false, 'data' => null, 'error' => 'تعذر الحصول على Zoom access token', 'http_code' => 0];
                }
                return $this->httpJson('GET', 'https://api.zoom.us/v2/users/me', [
                    'Authorization: Bearer ' . $token,
                ]);
            default:
                return ['success' => false, 'data' => null, 'error' => "action '{$action}' غير مدعوم في ZoomService", 'http_code' => 0];
        }
    }
}

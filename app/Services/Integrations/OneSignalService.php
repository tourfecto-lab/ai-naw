<?php

/**
 * Tourfecto - OneSignal Integration
 * @version 1.1.0
 *
 * إرسال إشعارات Push (Web + Mobile) لمستخدمي موقعك.
 * كل عميل بيتسجّل في المتصفح بـ external_id = tf_{user_id} (ThirdPartyHead)، فأي إشعار
 * من جرس المنصة بيوصله هو بس على الموبايل/المتصفح (Notification::notify ← pushToUser).
 */

class OneSignalService extends BaseIntegrationService
{
    private const API_URL = 'https://api.onesignal.com/notifications?c=push';

    public function key(): string
    {
        return 'onesignal';
    }

    public function isConfigured(): bool
    {
        return $this->conf('ONESIGNAL_APP_ID', 'ONESIGNAL_APP_ID') !== ''
            && $this->conf('ONESIGNAL_REST_API_KEY', 'ONESIGNAL_REST_API_KEY') !== '';
    }

    public static function externalId(int $userId): string
    {
        return 'tf_' . $userId;
    }

    /** المفاتيح الجديدة (os_v2_...) بتتبعت بـ "Key"، والقديمة (Legacy REST key) بـ "Basic" */
    public static function authHeader(string $restKey): string
    {
        return 'Authorization: ' . (strpos($restKey, 'os_v2_') === 0 ? 'Key ' : 'Basic ') . $restKey;
    }

    /**
     * إرسال إشعار.
     * @param array $params headings, contents, include_aliases, url, data...
     */
    public function sendNotification(array $params = []): array
    {
        $body = array_merge([
            'app_id' => $this->conf('ONESIGNAL_APP_ID', 'ONESIGNAL_APP_ID'),
        ], $params);
        // قبل كده من غير جمهور محدد كان بيبعت لكل المشتركين؛ دلوقتي لازم يتحدد صراحة
        if (empty($body['include_aliases']) && empty($body['included_segments']) && empty($body['include_subscription_ids'])) {
            return ['success' => false, 'data' => null, 'error' => 'لازم تحدد مين يستلم الإشعار', 'http_code' => 0];
        }

        return $this->httpJson('POST', self::API_URL, [self::authHeader($this->conf('ONESIGNAL_REST_API_KEY', 'ONESIGNAL_REST_API_KEY'))], $body);
    }

    /** نفس إشعار الجرس على موبايل/متصفح العميل ده بس */
    public function pushToUser(int $userId, string $title, string $body = '', string $link = ''): array
    {
        $base = rtrim(defined('APP_URL') ? (string) APP_URL : '', '/');
        $params = [
            'include_aliases' => ['external_id' => [self::externalId($userId)]],
            'target_channel' => 'push',
            'headings' => ['en' => mb_substr($title, 0, 120)],
            'contents' => ['en' => mb_substr($body !== '' ? $body : $title, 0, 240)],
        ];
        if ($link !== '' && $base !== '') {
            $params['url'] = preg_match('#^https?://#i', $link) ? $link : $base . '/' . ltrim($link, '/');
        }
        return $this->sendNotification($params);
    }

    public function request(string $action, array $params = [], array $context = []): array
    {
        switch ($action) {
            case 'send_notification':
                return $this->sendNotification($params);
            case 'test':
                // إشعار لمستلم وهمي: المفتاح الغلط بيرجع 401/403، والصح بيرجع 200 من غير ما يوصل لحد.
                // (GET /apps/{id} القديم كان محتاج مفتاح الحساب مش مفتاح التطبيق، فالاختبار كان بيفشل بمفتاح سليم)
                return $this->sendNotification([
                    'include_aliases' => ['external_id' => ['tf_connection_test']],
                    'target_channel' => 'push',
                    'headings' => ['en' => 'Tourfecto test'],
                    'contents' => ['en' => 'Connection test'],
                ]);
            default:
                return ['success' => false, 'data' => null, 'error' => "action '{$action}' غير مدعوم في OneSignalService", 'http_code' => 0];
        }
    }
}

<?php

/**
 * Tourfecto - Zapier Integration
 * @version 1.0.0
 *
 * طلّع Webhook لأي Zap في حسابك على Zapier (عشان تربط أحداث الموقع
 * بأكتر من 6000 تطبيق من غير كود إضافي).
 */

class ZapierService extends BaseIntegrationService
{
    public function key(): string
    {
        return 'zapier';
    }

    public function isConfigured(): bool
    {
        return $this->conf('ZAPIER_WEBHOOK_URL', 'ZAPIER_WEBHOOK_URL') !== '';
    }

    /**
     * تشغيل webhook (Catch Hook) في Zapier.
     * @param string $event   اسم الحدث (مثل user.registered / review.received)
     * @param array  $payload البيانات اللي هتوصل لـ Zap
     */
    public function trigger(string $event, array $payload = []): array
    {
        $url = $this->conf('ZAPIER_WEBHOOK_URL', 'ZAPIER_WEBHOOK_URL');
        $body = array_merge(['event' => $event, 'sent_at' => date('c')], $payload);

        return $this->httpJson('POST', $url, [], $body);
    }

    public function request(string $action, array $params = [], array $context = []): array
    {
        switch ($action) {
            case 'trigger':
                return $this->trigger($params['event'] ?? 'generic', $params['payload'] ?? []);
            case 'test':
                $url = $this->conf('ZAPIER_WEBHOOK_URL', 'ZAPIER_WEBHOOK_URL');
                if ($url === '') {
                    return ['success' => false, 'data' => null, 'error' => 'Zapier Webhook URL غير مضبوط', 'http_code' => 0];
                }
                return $this->httpJson('POST', $url, [], ['event' => 'connection.test', 'sent_at' => date('c')]);
            default:
                return ['success' => false, 'data' => null, 'error' => "action '{$action}' غير مدعوم في ZapierService", 'http_code' => 0];
        }
    }
}

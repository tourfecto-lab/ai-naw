<?php

/**
 * Tourfecto - Slack Integration
 * @version 1.0.0
 *
 * إرسال إشعارات لسلاف فريقك (تسجيل جديد، مراجعة واردة، تنبيه منافس...).
 */

class SlackService extends BaseIntegrationService
{
    public function key(): string
    {
        return 'slack';
    }

    public function isConfigured(): bool
    {
        return $this->conf('SLACK_BOT_TOKEN', 'SLACK_BOT_TOKEN') !== '';
    }

    /**
     * إرسال رسالة نصية لقناة معيّنة (أو القناة الافتراضية في .env).
     * @param string $text    نص الرسالة
     * @param string|null $channel  مثل #general أو C01234... أو user id
     * @param array|null $blocks    Blocks بتنسيق Slack Block Kit (اختياري)
     */
    public function sendMessage(string $text, ?string $channel = null, ?array $blocks = null): array
    {
        $channel = $channel ?: $this->conf('SLACK_DEFAULT_CHANNEL', 'SLACK_DEFAULT_CHANNEL') ?: '#general';

        $body = [
            'channel' => $channel,
            'text'    => $text,
        ];
        if ($blocks !== null) {
            $body['blocks'] = $blocks;
        }

        $result = $this->httpJson('POST', 'https://slack.com/api/chat.postMessage', [
            'Authorization: Bearer ' . $this->conf('SLACK_BOT_TOKEN', 'SLACK_BOT_TOKEN'),
        ], $body);

        if ($result['success'] && isset($result['data']['ok']) && $result['data']['ok'] !== true) {
            return ['success' => false, 'data' => $result['data'], 'error' => $result['data']['error'] ?? 'Slack API error', 'http_code' => $result['http_code']];
        }

        return $result;
    }

    public function request(string $action, array $params = [], array $context = []): array
    {
        switch ($action) {
            case 'send_message':
                return $this->sendMessage($params['text'] ?? '', $params['channel'] ?? null, $params['blocks'] ?? null);
            case 'test':
                return $this->httpJson('POST', 'https://slack.com/api/auth.test', [
                    'Authorization: Bearer ' . $this->conf('SLACK_BOT_TOKEN', 'SLACK_BOT_TOKEN'),
                ]);
            default:
                return ['success' => false, 'data' => null, 'error' => "action '{$action}' غير مدعوم في SlackService", 'http_code' => 0];
        }
    }
}

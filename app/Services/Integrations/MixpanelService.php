<?php

/**
 * Tourfecto - Mixpanel Integration
 * @version 1.0.0
 *
 * تتبع أحداث المستخدمين (Server-side) عبر Mixpanel /track endpoint.
 */

class MixpanelService extends BaseIntegrationService
{
    public function key(): string
    {
        return 'mixpanel';
    }

    public function isConfigured(): bool
    {
        return $this->conf('MIXPANEL_TOKEN', 'MIXPANEL_TOKEN') !== '';
    }

    /**
     * تسجيل حدث واحد في Mixpanel.
     * @param string $event       اسم الحدث (مثل dashboard_viewed)
     * @param array  $properties  خصائص إضافية للحدث
     * @param string|null $distinctId معرف المستخدم (email أو id)
     */
    public function track(string $event, array $properties = [], ?string $distinctId = null): array
    {
        $token = $this->conf('MIXPANEL_TOKEN', 'MIXPANEL_TOKEN');
        $eventData = [
            'event'      => $event,
            'properties' => array_merge([
                'token' => $token,
                'time'  => time(),
            ], $properties),
        ];
        if ($distinctId !== null) {
            $eventData['properties']['distinct_id'] = $distinctId;
        }

        $data = base64_encode(json_encode([$eventData], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $raw = $this->rawRequest('POST', 'https://api.mixpanel.com/track', [], 'data=' . $data);
        $response = $raw['body'];
        $httpCode = $raw['http_code'];
        $curlError = $raw['error'];

        if ($curlError !== '') {
            return ['success' => false, 'data' => null, 'error' => $curlError, 'http_code' => 0];
        }

        $ok = ($httpCode < 400) && (trim((string) $response) === '1');
        return [
            'success'   => $ok,
            'data'      => ['response' => $response],
            'error'     => $ok ? null : "HTTP {$httpCode}",
            'http_code' => $httpCode,
        ];
    }

    public function request(string $action, array $params = [], array $context = []): array
    {
        switch ($action) {
            case 'track':
                return $this->track($params['event'] ?? 'generic', $params['properties'] ?? [], $params['distinct_id'] ?? null);
            case 'test':
                return $this->track('connection.test', [], 'test@tourfecto.local');
            default:
                return ['success' => false, 'data' => null, 'error' => "action '{$action}' غير مدعوم في MixpanelService", 'http_code' => 0];
        }
    }
}

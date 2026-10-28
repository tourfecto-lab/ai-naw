<?php

/**
 * Tourfecto - Calendly Integration
 * @version 1.0.0
 *
 * قراءة أنواع الاجتماعات وروابط الحجز من حساب Calendly (Personal Access Token).
 */

class CalendlyService extends BaseIntegrationService
{
    private const BASE = 'https://api.calendly.com';

    public function key(): string
    {
        return 'calendly';
    }

    public function isConfigured(): bool
    {
        return $this->conf('CALENDLY_API_TOKEN', 'CALENDLY_API_TOKEN') !== '';
    }

    private function headers(): array
    {
        return ['Authorization: Bearer ' . $this->conf('CALENDLY_API_TOKEN', 'CALENDLY_API_TOKEN')];
    }

    /**
     * بيانات المستخدم الحالي (الـ uri بتاعه وبيانات المؤسسة).
     */
    public function me(): array
    {
        $result = $this->httpJson('GET', self::BASE . '/users/me', $this->headers());
        if ($result['success'] && isset($result['data']['resource'])) {
            return ['success' => true, 'data' => $result['data']['resource'], 'error' => null, 'http_code' => $result['http_code']];
        }
        return $result;
    }

    /**
     * قائمة أنواع الاجتماعات (event types) للمستخدم.
     */
    public function getEventTypes(?string $userUri = null): array
    {
        $userUri = $userUri ?: $this->resolveUserUri();
        if ($userUri === '') {
            return ['success' => false, 'data' => null, 'error' => 'تعذر تحديد user uri', 'http_code' => 0];
        }
        return $this->httpJson('GET', self::BASE . '/event_types?user=' . rawurlencode($userUri), $this->headers());
    }

    /**
     * قائمة روابط الحجز (scheduling links).
     */
    public function getSchedulingLinks(?string $userUri = null): array
    {
        $userUri = $userUri ?: $this->resolveUserUri();
        if ($userUri === '') {
            return ['success' => false, 'data' => null, 'error' => 'تعذر تحديد user uri', 'http_code' => 0];
        }
        return $this->httpJson('GET', self::BASE . '/scheduling_links?user=' . rawurlencode($userUri), $this->headers());
    }

    private function resolveUserUri(): string
    {
        $me = $this->me();
        return $me['success'] ? (string) ($me['data']['uri'] ?? '') : '';
    }

    public function request(string $action, array $params = [], array $context = []): array
    {
        switch ($action) {
            case 'me':
                return $this->me();
            case 'event_types':
                return $this->getEventTypes($params['user_uri'] ?? null);
            case 'scheduling_links':
                return $this->getSchedulingLinks($params['user_uri'] ?? null);
            case 'test':
                return $this->me();
            default:
                return ['success' => false, 'data' => null, 'error' => "action '{$action}' غير مدعوم في CalendlyService", 'http_code' => 0];
        }
    }
}

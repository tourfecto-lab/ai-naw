<?php

/**
 * Tourfecto - Base Integration Service
 * @version 1.0.0
 *
 * أساس مشترك لكل خدمات الطرف الثالث (Algolia, Slack, Zapier, HubSpot,
 * Zoom, Mixpanel, OneSignal, Calendly). بيوفّر helper موحّد لطلبات HTTP
 * (JSON) وتسجيل الأخطاء، بحيث كل Service يركّز على منطق الـ API الخاص به.
 *
 * كل Service بيعمل implement لـ IntegrationInterface عشان يشتغل مع
 * IntegrationManager لو احتجناه لاحقًا، لكنه كمان قابل للاستخدام مباشرة.
 */

abstract class BaseIntegrationService implements IntegrationInterface
{
    /**
     * @var callable|null حقنة اختيارية للاختبارات - بتستقبل وصف الطلب
     * ['method','url','headers','body'] وترجع رد محاكى
     * ['body'=>string,'http_code'=>int,'error'=>?string] بدل curl.
     */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    /** الاسم الفريد للمنصة */
    abstract public function key(): string;

    /** هل الإعدادات الأساسية موجودة في .env؟ */
    abstract public function isConfigured(): bool;

    public function authType(): string
    {
        return 'api_key';
    }

    /**
     * تنفيذ طلب HTTP عام (JSON) - يدعم GET/POST/PUT/DELETE.
     * @return array ['success'=>bool, 'data'=>mixed, 'error'=>?string, 'http_code'=>int]
     */
    protected function httpJson(string $method, string $url, array $headers = [], ?array $body = null): array
    {
        $payload = $body !== null
            ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        return $this->dispatch(
            strtoupper($method),
            $url,
            array_merge(['Content-Type: application/json'], $headers),
            $payload
        );
    }

    protected function httpForm(string $method, string $url, array $headers = [], array $fields = []): array
    {
        return $this->dispatch(
            strtoupper($method),
            $url,
            $headers,
            http_build_query($fields)
        );
    }

    /**
     * طلب HTTP خام عبر الـ transport الوهمي (لو محقون) أو curl العادي.
     * @return array ['body'=>string,'http_code'=>int,'error'=>string]
     */
    protected function rawRequest(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if ($this->transport !== null) {
            $fake = call_user_func($this->transport, [
                'method' => strtoupper($method),
                'url' => $url,
                'headers' => $headers,
                'body' => $body,
            ]);
            return [
                'body' => (string) ($fake['body'] ?? ''),
                'http_code' => (int) ($fake['http_code'] ?? 0),
                'error' => isset($fake['error']) ? (string) $fake['error'] : '',
            ];
        }

        $ch = curl_init($url);
        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];
        if ($body !== null) {
            $curlOpts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $curlOpts);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        return [
            'body' => (string) $response,
            'http_code' => $httpCode,
            'error' => $curlError,
        ];
    }

    /**
     * تنفيذ طلب HTTP ثم معالجة الاستجابة الموحّدة (فك JSON + معالجة الأخطاء).
     * @return array ['success'=>bool, 'data'=>mixed, 'error'=>?string, 'http_code'=>int]
     */
    private function dispatch(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $raw = $this->rawRequest($method, $url, $headers, $body);
        $response = $raw['body'];
        $httpCode = $raw['http_code'];
        $curlError = $raw['error'];

        if ($curlError !== '') {
            $this->log('error', "cURL error [{$this->key()}]: {$curlError}");
            return ['success' => false, 'data' => null, 'error' => $curlError, 'http_code' => 0];
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 400) {
            $this->log('warning', "API error [{$this->key()}] HTTP {$httpCode}", ['response' => $response]);
            return ['success' => false, 'data' => $decoded, 'error' => "HTTP {$httpCode}", 'http_code' => $httpCode];
        }

        return ['success' => true, 'data' => $decoded, 'error' => null, 'http_code' => $httpCode];
    }

    protected function log(string $level, string $message, array $context = []): void
    {
        if (class_exists('Logger')) {
            try {
                Logger::{$level}($message, $context);
            } catch (Throwable $e) {
                error_log("[integrations][{$level}] {$message}");
            }
        } else {
            error_log("[integrations][{$level}] {$message}");
        }
    }

    /** ربط متغير بيئة بأمان (ثابت أو env أو system_settings) */
    protected function conf(string $const, string $envKey): string
    {
        // الأولوية: الإعداد المحفوظ من لوحة الأدمن (system_settings)
        // وده الشكل اللي بينضبط منه فعليًا بعد ما وحدة التكاملات اتربطت.
        if (class_exists('SystemSettingsService')) {
            try {
                $dbValue = (new SystemSettingsService())->get('integration_' . strtolower($envKey), '');
                if ($dbValue !== '') {
                    return $dbValue;
                }
            } catch (Throwable $e) {
                // الجدول مش موجود لسه - نكمل على الـ env/const
            }
        }

        if (defined($const)) {
            return (string) constant($const);
        }
        return (string) (env($envKey) ?: '');
    }
}

<?php

/**
 * Tourfecto - Base API-Key Integration
 * @version 1.0.0
 *
 * أي API بيتفعّل بمفتاح واحد ثابت (مش OAuth لكل عميل) يورث من الكلاس ده
 * ويحدد بس baseUrl() وbuildHeaders() وdoRequest() الخاصة به. منطق الـ
 * cURL والـ error handling والـ logging مشترك هنا مرة واحدة.
 */
abstract class BaseApiKeyIntegration implements IntegrationInterface
{
    abstract protected function baseUrl(): string;

    /** الهيدرز المطلوبة (Authorization: Bearer ... إلخ) */
    abstract protected function buildHeaders(): array;

    protected function httpRequest(string $method, string $path, array $body = []): array
    {
        $ch = curl_init($this->baseUrl() . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $this->buildHeaders(),
            CURLOPT_TIMEOUT        => 15,
        ]);
        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->log('error', "cURL error [{$this->key()}]: {$curlError}");
            return ['success' => false, 'data' => null, 'error' => $curlError];
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 400) {
            $this->log('warning', "API error [{$this->key()}] HTTP {$httpCode}", ['response' => $response]);
            return ['success' => false, 'data' => $decoded, 'error' => "HTTP {$httpCode}"];
        }

        return ['success' => true, 'data' => $decoded, 'error' => null];
    }

    protected function log(string $level, string $message, array $context = []): void
    {
        if (function_exists('app_log')) {
            app_log($level, $message, $context);
        }
    }
}

<?php

/**
 * Tourfecto - Viator Partner API Client
 * ربط حساب الشريك (Affiliate/Merchant) في Viator - مفتاح وصول واحد
 * (exp-api-key) بيتحصّل عليه العميل من حسابه في Viator Partner Portal.
 * التوثيق الرسمي: https://docs.viator.com/partner-api/
 * @version 1.0.0
 */
class ViatorAPI
{
    private const BASE_URL = 'https://api.viator.com/partner';

    private string $apiKey;
    private string $language;

    /** @var callable|null transport قابل للحقن في الاختبارات بدل curl الحقيقي */
    private $transport;

    /**
     * @param string      $apiKey    مفتاح الشريك (exp-api-key)
     * @param string      $language  لغة الاستجابة (Accept-Language)
     * @param callable|null $transport fn(string $method, string $url, array $headers, ?string $body)
     *                                  => array{response:?string, http_code:int, error:?string}
     *                                  - اختياري للاختبارات فقط؛ الإنتاج يبقى curl كالمعتاد.
     */
    public function __construct(string $apiKey, string $language = 'en-US', ?callable $transport = null)
    {
        $this->apiKey = $apiKey;
        $this->language = $language;
        $this->transport = $transport;
    }

    /**
     * تحقق إن المفتاح صحيح فعلاً قبل ما نحفظه - بنستخدم endpoint خفيف
     * (قائمة الوجهات) بدل ما نصدّق المفتاح من غير اختبار.
     */
    public function verifyToken(): array
    {
        $result = $this->request('GET', '/destinations');
        if (!$result['success']) {
            $status = (int) ($result['http_code'] ?? 0);
            $error = match (true) {
                $status === 401 => 'Invalid or expired credential',
                $status === 403 => 'Your account does not have the required API permission',
                $status === 429 => 'Provider rate limit reached. Try again later.',
                $status >= 500 => 'Provider is temporarily unavailable. Connection not verified.',
                $status === 404 => 'Verification endpoint is unavailable for this account. Connection not verified.',
                default => 'Unable to verify the connection. Check settings and try again.',
            };
            return ['success' => false, 'error' => $error, 'http_code' => $status];
        }
        return ['success' => true, 'http_code' => (int) $result['http_code']];
    }

    /** بحث عن منتجات (جولات/أنشطة) الشريك */
    public function searchProducts(array $filters = []): array
    {
        return $this->request('POST', '/products/search', [], $filters);
    }

    /** جلب حجز واحد بالتفصيل */
    public function getBooking(string $bookingRef): array
    {
        return $this->request('GET', '/bookings/' . rawurlencode($bookingRef) . '/status');
    }

    private function request(string $method, string $path, array $query = [], array $body = []): array
    {
        $url = self::BASE_URL . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Accept: application/json;version=2.0',
            'Content-Type: application/json',
            'Accept-Language: ' . $this->language,
            'exp-api-key: ' . $this->apiKey,
        ];
        $requestBody = !empty($body) ? json_encode($body) : null;

        if ($this->transport !== null) {
            $result = call_user_func($this->transport, strtoupper($method), $url, $headers, $requestBody);
            $response = $result['response'] ?? null;
            $httpCode = (int) ($result['http_code'] ?? 0);
            $curlError = $result['error'] ?? null;
        } else {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => strtoupper($method),
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT => 15,
            ]);
            if ($requestBody !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
            }

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
        }

        if ($curlError) {
            $this->log('error', "Viator cURL error: {$curlError}");
            return ['success' => false, 'data' => null, 'error' => $curlError, 'http_code' => 0];
        }

        $decoded = json_decode((string) $response, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            $this->log('warning', 'OTA API unsuccessful response', ['http_code' => $httpCode]);
            return ['success' => false, 'data' => null, 'error' => 'Provider request failed', 'http_code' => $httpCode];
        }
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return ['success' => false, 'data' => null, 'error' => 'Invalid provider response', 'http_code' => $httpCode];
        }
        if (!empty($decoded['error']) || !empty($decoded['errors']) || ($decoded['success'] ?? true) === false) {
            return ['success' => false, 'data' => null, 'error' => 'Provider rejected request', 'http_code' => $httpCode];
        }

        return ['success' => true, 'data' => $decoded, 'error' => null, 'http_code' => $httpCode];
    }

    private function log(string $level, string $message, array $context = []): void
    {
        // الترحيل: الاعتماد الأساسي على Logger الموجود في المشروع؛ مع fallback
        // للـ app_log القديمة (غير معرّفة في الكود الحالي فتعمل كـ no-op).
        if (class_exists('Logger')) {
            if ($level === 'error') {
                Logger::error($message, $context);
            } else {
                Logger::warning($message, $context);
            }
            return;
        }
        if (function_exists('app_log')) {
            app_log($level, $message, $context);
        }
    }
}

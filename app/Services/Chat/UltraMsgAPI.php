<?php

/**
 * Tourfecto - UltraMsg API Integration
 * تكامل مع UltraMsg لخدمات WhatsApp
 * @version 2.0.0
 *
 * تصحيح جذري (2026-07-13): كان بياخد instanceId/apiKey من متغيرات .env
 * ثابتة - يعني رقم واتساب واحد للموقع كله. ده غلط لمنتج SaaS كل عميل
 * محتاج رقمه هو (نفس مشكلة GoogleBusinessAPI بالظبط). دلوقتي الكلاس
 * بياخدهم كـ arguments، جايين من صف platform_connections الخاص بكل عميل.
 *
 * تصحيح تاني: رابط API الحقيقي لـ UltraMsg بيتضمن instanceId جوه المسار
 * نفسه (https://api.ultramsg.com/{instanceId}/...) - مش base URL منفصل
 * كان بيتحط من .env بشكل غير منطقي.
 */
class UltraMsgAPI
{
    private string $instanceId;
    private string $apiKey;
    private string $baseUrl;
    private int $timeout = 30;

    /**
     * @param string $instanceId معرف المثيل الخاص بحساب هذا العميل على UltraMsg
     * @param string $apiKey توكن الحساب الخاص بهذا العميل
     */
    public function __construct(string $instanceId = '', string $apiKey = '')
    {
        $this->instanceId = $instanceId;
        $this->apiKey = $apiKey;
        $this->baseUrl = 'https://api.ultramsg.com/' . $instanceId;
    }

    public function isConfigured(): bool
    {
        return $this->instanceId !== '' && $this->apiKey !== '';
    }

    /**
     * إرسال رسالة نصية
     * @param string $phoneNumber - رقم المستلم
     * @param string $message - نص الرسالة
     * @return bool
     */
    public function sendMessage(string $phoneNumber, string $message): bool
    {
        if (!$this->isConfigured()) {
            Logger::warning('UltraMsg not configured for this connection');
            return false;
        }

        try {
            $phoneNumber = $this->cleanPhoneNumber($phoneNumber);

            $url = $this->baseUrl . '/messages/chat';

            $params = [
                'token' => $this->apiKey,
                'to' => $phoneNumber,
                'body' => $message,
                'priority' => 1
            ];

            $response = $this->makeRequest('POST', $url, $params);

            return $response['success'] && ($response['data']['sent'] ?? false);

        } catch (Exception $e) {
            Logger::error('UltraMsg Send Message Error', [
                'phone' => $phoneNumber,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * جلب حالة الاتصال (متصل/QR مطلوب/إلخ) - مفيد لعرض حالة الربط للعميل.
     */
    public function getInstanceStatus(): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'UltraMsg غير مظبوط'];
        }

        $url = $this->baseUrl . '/instance/status';
        $response = $this->makeRequest('GET', $url, ['token' => $this->apiKey]);

        if (!$response['success']) {
            return $response;
        }

        return [
            'success' => true,
            'status' => $response['data']['accountStatus']['status'] ?? 'unknown',
        ];
    }

    private function makeRequest(string $method, string $url, array $params = []): array
    {
        $ch = curl_init();

        $fullUrl = $url . '?' . http_build_query($params);
        curl_setopt($ch, CURLOPT_URL, $fullUrl);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Tourfecto/1.0'
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'cURL Error: ' . $curlError];
        }

        $decoded = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            return ['success' => false, 'error' => "UltraMsg API Error ({$httpCode})", 'http_code' => $httpCode];
        }

        return ['success' => true, 'data' => $decoded, 'http_code' => $httpCode];
    }

    private function cleanPhoneNumber(string $phoneNumber): string
    {
        $cleaned = preg_replace('/[^0-9+]/', '', $phoneNumber);
        $cleaned = ltrim($cleaned, '+');

        if (strlen($cleaned) < 10) {
            $cleaned = '20' . $cleaned;
        }

        return $cleaned;
    }
}

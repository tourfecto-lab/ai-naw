<?php

/**
 * Tourfecto - WhatsApp API Integration
 * تكامل مع WhatsApp Cloud API و Business API
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class WhatsAppAPI
{
    /**
     * @var string $accessToken - رمز الوصول
     */
    private $accessToken;

    /**
     * @var string $phoneId - معرف رقم الهاتف
     */
    private $phoneId;

    /**
     * @var string $baseUrl - رابط API الأساسي
     */
    private $baseUrl;

    /**
     * @var int $timeout - مهلة الطلب
     */
    private $timeout = 30;

    /**
     * @var string $webhookVerifyToken - رمز التحقق من Webhook
     */
    private $webhookVerifyToken;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->accessToken = WHATSAPP_ACCESS_TOKEN;
        $this->baseUrl = WHATSAPP_CLOUD_BASE_URL; // app/Config/whatsapp.php (WHATSAPP_API_VERSION)
        $this->phoneId = WHATSAPP_PHONE_ID;
        $this->webhookVerifyToken = WHATSAPP_WEBHOOK_VERIFY_TOKEN;
    }

    /**
     * إرسال رسالة نصية
     * @param string $phoneNumber - رقم المستلم
     * @param string $message - نص الرسالة
     * @return bool
     */
    public function sendMessage(string $phoneNumber, string $message): bool
    {
        try {
            $phoneNumber = $this->cleanPhoneNumber($phoneNumber);

            $endpoint = "/{$this->phoneId}/messages";
            $url = $this->baseUrl . $endpoint;

            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $phoneNumber,
                'type' => 'text',
                'text' => [
                    'body' => $message,
                    'preview_url' => false
                ]
            ];

            $response = $this->makeRequest('POST', $url, $payload);

            return $response['success'];

        } catch (Exception $e) {
            Logger::error('WhatsApp Send Message Error', [
                'phone' => $phoneNumber,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * إرسال رسالة قالب
     * @param string $phoneNumber
     * @param string $templateName
     * @param array $parameters
     * @return bool
     */
    public function sendTemplate(string $phoneNumber, string $templateName, array $parameters = []): bool
    {
        try {
            $phoneNumber = $this->cleanPhoneNumber($phoneNumber);

            $endpoint = "/{$this->phoneId}/messages";
            $url = $this->baseUrl . $endpoint;

            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $phoneNumber,
                'type' => 'template',
                'template' => [
                    'name' => $templateName,
                    'language' => [
                        'code' => 'ar'
                    ],
                    'components' => $this->buildTemplateComponents($parameters)
                ]
            ];

            $response = $this->makeRequest('POST', $url, $payload);

            return $response['success'];

        } catch (Exception $e) {
            Logger::error('WhatsApp Send Template Error', [
                'phone' => $phoneNumber,
                'template' => $templateName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * إرسال طلب إلى API
     * @param string $method
     * @param string $url
     * @param array $data
     * @return array
     */
    private function makeRequest(string $method, string $url, array $data = []): array
    {
        $ch = curl_init($url);

        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Tourfecto/1.0'
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return [
                'success' => false,
                'error' => 'cURL Error: ' . $curlError
            ];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $errorData = json_decode($response, true);
            $errorMessage = $errorData['error']['message'] ?? 'Unknown error';

            return [
                'success' => false,
                'error' => "WhatsApp API Error ({$httpCode}): {$errorMessage}",
                'http_code' => $httpCode
            ];
        }

        return [
            'success' => true,
            'data' => json_decode($response, true),
            'http_code' => $httpCode
        ];
    }

    /**
     * تنظيف رقم الهاتف
     * @param string $phoneNumber
     * @return string
     */
    private function cleanPhoneNumber(string $phoneNumber): string
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phoneNumber);

        if (strlen($cleaned) < 10) {
            $cleaned = '20' . $cleaned;
        }

        return $cleaned;
    }

    /**
     * بناء مكونات القالب
     * @param array $parameters
     * @return array
     */
    private function buildTemplateComponents(array $parameters): array
    {
        $components = [];

        if (!empty($parameters)) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(function ($param) {
                    return ['type' => 'text', 'text' => $param];
                }, $parameters)
            ];
        }

        return $components;
    }
}

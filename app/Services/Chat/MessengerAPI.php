<?php

/**
 * Tourfecto - AI Chat Platform
 * تكامل Facebook Messenger (بند 1: Integration Architecture كاملة بدون
 * وضع API Keys حقيقية - كل شركة تربط صفحتها لاحقًا من لوحة الإدارة عبر
 * PlatformConnection بنفس أسلوب قنوات المراجعات الحالية).
 *
 * الاستقبال (Webhook) موجود ومفعّل بالفعل في WebhookController - هذا
 * الكلاس يوفّر فقط الإرسال الصادر (Outbound) المفقود عبر Facebook Send API.
 *
 * @version 1.0.0
 */

class MessengerAPI
{
    /** @var string */
    private $pageAccessToken;

    /** @var string */
    private $baseUrl;

    /**
     * @param string $pageAccessToken Page Access Token الخاص بصفحة الشركة على فيسبوك
     */
    public function __construct(string $pageAccessToken)
    {
        $this->pageAccessToken = $pageAccessToken;
        // نفس إصدار Meta الموحّد (META_API_VERSION) بدل v18.0 الثابت اللي Meta وقفته
        $this->baseUrl = 'https://graph.facebook.com/' . (env('META_API_VERSION') ?: 'v25.0');
    }

    /**
     * @return bool
     */
    public function isConfigured(): bool
    {
        return !empty($this->pageAccessToken);
    }

    /**
     * إرسال رسالة نصية لعميل عبر Messenger.
     * @param string $recipientPsid معرف العميل (Page-Scoped ID) المستلَم من الـWebhook
     * @param string $message
     * @return bool
     */
    public function sendMessage(string $recipientPsid, string $message): bool
    {
        if (!$this->isConfigured()) {
            Logger::warning('MessengerAPI: not configured, skipping send');
            return false;
        }

        try {
            $url = $this->baseUrl . '/me/messages?access_token=' . urlencode($this->pageAccessToken);

            $payload = [
                'recipient' => ['id' => $recipientPsid],
                'message' => ['text' => $message],
                'messaging_type' => 'RESPONSE',
            ];

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError || $httpCode !== 200) {
                Logger::error('MessengerAPI: send failed', [
                    'http_code' => $httpCode, 'curl_error' => $curlError, 'response' => $response,
                ]);
                return false;
            }

            return true;
        } catch (Exception $e) {
            Logger::error('MessengerAPI: exception during send', ['error' => $e->getMessage()]);
            return false;
        }
    }
}

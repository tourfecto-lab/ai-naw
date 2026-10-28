<?php

/**
 * Tourfecto - AI Chat Platform
 * تكامل Instagram Messaging (بند 1: Integration Architecture كاملة بدون
 * API Keys حقيقية). Instagram Messaging API يستخدم نفس بنية Facebook
 * Graph API لكن عبر IG Business Account Access Token منفصل عن Messenger،
 * لذلك كلاس مستقل (بدل مشاركة MessengerAPI) يسهّل ربط/فك كل قناة على حدة.
 *
 * @version 1.0.0
 */

class InstagramAPI
{
    /** @var string */
    private $igAccessToken;

    /** @var string */
    private $baseUrl;

    /**
     * @param string $igAccessToken Access Token الخاص بحساب انستجرام التجاري للشركة
     */
    public function __construct(string $igAccessToken)
    {
        $this->igAccessToken = $igAccessToken;
        // نفس إصدار Meta الموحّد (META_API_VERSION) بدل v18.0 الثابت اللي Meta وقفته
        $this->baseUrl = 'https://graph.facebook.com/' . (env('META_API_VERSION') ?: 'v25.0');
    }

    /**
     * @return bool
     */
    public function isConfigured(): bool
    {
        return !empty($this->igAccessToken);
    }

    /**
     * إرسال رسالة نصية لعميل عبر Instagram Direct.
     * @param string $recipientIgsid معرف العميل (Instagram-Scoped ID) المستلَم من الـWebhook
     * @param string $message
     * @return bool
     */
    public function sendMessage(string $recipientIgsid, string $message): bool
    {
        if (!$this->isConfigured()) {
            Logger::warning('InstagramAPI: not configured, skipping send');
            return false;
        }

        try {
            $url = $this->baseUrl . '/me/messages?access_token=' . urlencode($this->igAccessToken);

            $payload = [
                'recipient' => ['id' => $recipientIgsid],
                'message' => ['text' => $message],
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
                Logger::error('InstagramAPI: send failed', [
                    'http_code' => $httpCode, 'curl_error' => $curlError, 'response' => $response,
                ]);
                return false;
            }

            return true;
        } catch (Exception $e) {
            Logger::error('InstagramAPI: exception during send', ['error' => $e->getMessage()]);
            return false;
        }
    }
}

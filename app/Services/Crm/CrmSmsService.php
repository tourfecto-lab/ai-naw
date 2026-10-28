<?php

/**
 * Tourfecto - CRM SMS Integration via Twilio (بند 15)
 * @version 1.0.0
 *
 * تكامل حقيقي وقابل للتشغيل الفعلي مع Twilio REST API الرسمي - وليس Mock،
 * بنفس نمط `CrmWhatsAppService` بالضبط: Credentials من `SystemSettingsService`
 * (بند 33: لا تنشئ أنظمة مكررة)، لا Secrets في الـFrontend، ولو الـCredentials
 * غير موجودة يرمي خطأ واضح بدل ادّعاء إرسال ناجح لم يحدث فعليًا (بند 16/17
 * بنفس الروح).
 *
 * لم يُختبر على حساب Twilio حقيقي في بيئة التنفيذ هذه (لا اتصال شبكة متاح) -
 * راجع "Tests Requiring Credentials" في CHANGELOG.
 */
class CrmSmsService
{
    private $settings;
    private $accountSid;
    private $authToken;
    private $fromNumber;

    public function __construct()
    {
        $this->settings = new SystemSettingsService();
        $this->accountSid = $this->settings->get('crm_sms_account_sid', '');
        $this->authToken = $this->settings->get('crm_sms_auth_token', '');
        $this->fromNumber = $this->settings->get('crm_sms_from_number', '');
    }

    public function isConfigured(): bool
    {
        return $this->accountSid !== '' && $this->authToken !== '' && $this->fromNumber !== '';
    }

    /** إرسال SMS فعلي عبر Twilio REST API */
    public function sendTextMessage(string $toPhoneE164, string $text): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'error' => 'تكامل SMS غير مُفعّل لهذا الحساب بعد - أضف Twilio Account SID وAuth Token ورقم الإرسال من إعدادات الأدمن أولًا',
            ];
        }

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json";
        $body = http_build_query([
            'To' => '+' . preg_replace('/[^0-9]/', '', $toPhoneE164),
            'From' => $this->fromNumber,
            'Body' => $text,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_USERPWD => $this->accountSid . ':' . $this->authToken,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 20,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            Logger::error('CrmSmsService: فشل الاتصال', ['error' => $curlError]);
            return ['success' => false, 'error' => 'تعذر الاتصال بـTwilio: ' . $curlError];
        }

        $data = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300 && isset($data['sid'])) {
            return ['success' => true, 'external_message_id' => $data['sid'], 'raw' => $data];
        }

        $errorMessage = $data['message'] ?? ('استجابة غير متوقعة من Twilio (HTTP ' . $httpCode . ')');
        Logger::error('CrmSmsService: فشل الإرسال', ['http_code' => $httpCode, 'response' => $response]);
        return ['success' => false, 'error' => $errorMessage];
    }

    /** إرسال SMS لجهة اتصال CRM، ويسجّله في محادثة (نفس منطق CrmWhatsAppService::sendToContact) */
    public function sendToContact(int $userId, int $contactId, string $text): array
    {
        $contact = (new CrmContact())->find($contactId);
        if (!$contact || (int) $contact->getAttribute('user_id') !== $userId) {
            return ['success' => false, 'error' => 'جهة الاتصال غير موجودة'];
        }
        $phone = $contact->getAttribute('phone');
        if (empty($phone)) {
            return ['success' => false, 'error' => 'لا يوجد رقم هاتف مسجّل لجهة الاتصال هذه'];
        }

        $normalizedPhone = preg_replace('/[^0-9]/', '', $phone);
        $conversation = (new CrmConversation())->findOrCreate($userId, $contactId, 'sms', $normalizedPhone);

        $result = $this->sendTextMessage($normalizedPhone, $text);

        $message = new CrmMessage([
            'conversation_id' => (int) $conversation->getAttribute('id'),
            'direction' => 'outbound',
            'sender_user_id' => $userId,
            'body' => $text,
            'status' => !empty($result['success']) ? 'sent' : 'failed',
            'external_message_id' => $result['external_message_id'] ?? null,
            'error' => $result['error'] ?? null,
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
        $message->save();

        $conversation->setAttribute('last_message_at', date('Y-m-d H:i:s'));
        $conversation->save();

        ActivityLog::record('crm', 'sms.sent', [
            'user_id' => $userId, 'subject_type' => 'crm_contacts', 'subject_id' => $contactId,
            'meta' => ['success' => !empty($result['success'])],
        ]);

        return array_merge($result, ['conversation_id' => (int) $conversation->getAttribute('id')]);
    }

    /**
     * التحقق من توقيع Webhook الوارد من Twilio (X-Twilio-Signature) - طريقة
     * التحقق الرسمية: HMAC-SHA1 لـ(الرابط الكامل + كل قيم الـPOST مرتّبة
     * أبجديًا ومُلحَقة بأسماء الحقول) باستخدام Auth Token كمفتاح.
     */
    public function verifyWebhookSignature(string $fullUrl, array $postParams, string $signatureHeader): bool
    {
        if ($this->authToken === '') {
            return false;
        }
        $data = $fullUrl;
        ksort($postParams);
        foreach ($postParams as $key => $value) {
            $data .= $key . $value;
        }
        $expected = base64_encode(hash_hmac('sha1', $data, $this->authToken, true));
        return hash_equals($expected, $signatureHeader);
    }
}

<?php

/**
 * Tourfecto - CRM SMS Webhook Controller (Twilio) (بند 15)
 * @version 1.0.0
 *
 * نفس نمط CrmWhatsAppWebhookController بالضبط - نقطة عامة بدون Auth، الأمان
 * عبر التحقق من توقيع Twilio الرسمي (X-Twilio-Signature) بدل جلسة مستخدم.
 */
class CrmSmsWebhookController extends Controller
{
    /** POST /webhooks/crm/sms - رسالة SMS واردة من Twilio */
    public function receive(array $params = []): array
    {
        $sms = new CrmSmsService();

        $fullUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
            . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
        $signature = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';

        if (!$sms->verifyWebhookSignature($fullUrl, $_POST, $signature)) {
            Logger::warning('CrmSmsWebhookController: توقيع Twilio غير صحيح - تم رفض الطلب');
            http_response_code(403);
            echo 'Invalid signature';
            exit;
        }

        try {
            $from = preg_replace('/[^0-9]/', '', $_POST['From'] ?? '');
            $body = (string) ($_POST['Body'] ?? '');
            $messageSid = (string) ($_POST['MessageSid'] ?? '');

            if (!empty($from)) {
                $this->handleIncomingSms($from, $body, $messageSid);
            }
        } catch (Exception $e) {
            Logger::error('CrmSmsWebhookController: فشل معالجة الرسالة', ['message' => $e->getMessage()]);
        }

        header('Content-Type: text/xml');
        echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>'; // استجابة TwiML فارغة رسمية مطلوبة من Twilio
        exit;
    }

    private function handleIncomingSms(string $phone, string $body, string $messageSid): void
    {
        $contactRows = $this->db->query("SELECT * FROM crm_contacts WHERE phone LIKE ? LIMIT 1", ['%' . substr($phone, -9)]);
        if (empty($contactRows)) {
            Logger::info('CrmSmsWebhookController: رسالة واردة من رقم غير مسجّل', ['phone' => $phone]);
            return;
        }
        $contact = $contactRows[0];
        $userId = (int) $contact['user_id'];

        $conversation = (new CrmConversation())->findOrCreate($userId, (int) $contact['id'], 'sms', $phone);

        $message = new CrmMessage([
            'conversation_id' => (int) $conversation->getAttribute('id'),
            'direction' => 'inbound',
            'body' => $body,
            'status' => 'delivered',
            'external_message_id' => $messageSid,
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
        $message->save();

        $conversation->setAttribute('last_message_at', date('Y-m-d H:i:s'));
        $conversation->setAttribute('unread_count', ((int) $conversation->getAttribute('unread_count')) + 1);
        $conversation->save();

        ActivityLog::record('crm', 'sms.received', [
            'user_id' => $userId, 'subject_type' => 'crm_contacts', 'subject_id' => (int) $contact['id'],
        ]);

        (new CrmAutomationService())->trigger('sms.message_received', $userId, [
            'contact_id' => (int) $contact['id'],
        ]);
    }
}

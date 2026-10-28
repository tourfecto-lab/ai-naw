<?php

/**
 * Tourfecto - CRM Email Inbound Webhook Controller (بند 17)
 * @version 1.0.0
 *
 * يستقبل الإيميلات الواردة عبر SendGrid Inbound Parse الرسمي (multipart/
 * form-data قياسي: from, subject, text/html...). هذا هو الجزء "Inbound"
 * اللي كان Architecture-only في المرحلة 3 (راجع تعليق CrmEmailService هناك)
 * - دلوقتي مُنفَّذ فعليًا.
 *
 * الأمان: SendGrid Inbound Parse مبيوقّعش الطلبات رسميًا زي Meta/Twilio -
 * الممارسة الموصى بها رسميًا من SendGrid نفسها هي تضمين Token سري في رابط
 * الـWebhook نفسه (Query String) والتحقق منه هنا. لازم الرابط المُسجَّل في
 * إعدادات SendGrid يكون فيه ?token=<crm_email_inbound_secret>.
 */
class CrmEmailWebhookController extends Controller
{
    /** POST /webhooks/crm/email-inbound?token=... */
    public function receive(array $params = []): array
    {
        $settings = new SystemSettingsService();
        $expectedToken = $settings->get('crm_email_inbound_secret', '');
        $providedToken = $_GET['token'] ?? '';

        if ($expectedToken === '' || !hash_equals($expectedToken, (string) $providedToken)) {
            Logger::warning('CrmEmailWebhookController: Token غير صحيح - تم رفض الطلب');
            http_response_code(403);
            echo 'Invalid token';
            exit;
        }

        try {
            $fromRaw = (string) ($_POST['from'] ?? '');
            $subject = (string) ($_POST['subject'] ?? '');
            $text = (string) ($_POST['text'] ?? ($_POST['html'] ?? ''));

            $fromEmail = $this->extractEmailAddress($fromRaw);
            if (!empty($fromEmail)) {
                $this->handleIncomingEmail($fromEmail, $subject, $text);
            }
        } catch (Exception $e) {
            Logger::error('CrmEmailWebhookController: فشل معالجة الإيميل الوارد', ['message' => $e->getMessage()]);
        }

        header('Content-Type: application/json');
        echo json_encode(['status' => 'received']);
        exit;
    }

    /** SendGrid بيبعت "From" بصيغة "الاسم <email@x.com>" أحيانًا - نستخرج الإيميل بس */
    private function extractEmailAddress(string $raw): ?string
    {
        if (preg_match('/<([^>]+)>/', $raw, $m)) {
            return trim($m[1]);
        }
        $raw = trim($raw);
        return filter_var($raw, FILTER_VALIDATE_EMAIL) ? $raw : null;
    }

    private function handleIncomingEmail(string $fromEmail, string $subject, string $body): void
    {
        $contactRows = $this->db->query("SELECT * FROM crm_contacts WHERE email = ? LIMIT 1", [$fromEmail]);
        if (empty($contactRows)) {
            Logger::info('CrmEmailWebhookController: إيميل وارد من عنوان غير مسجّل', ['email' => $fromEmail]);
            return;
        }
        $contact = $contactRows[0];
        $userId = (int) $contact['user_id'];

        $conversation = (new CrmConversation())->findOrCreate($userId, (int) $contact['id'], 'email', $fromEmail);

        $message = new CrmMessage([
            'conversation_id' => (int) $conversation->getAttribute('id'),
            'direction' => 'inbound',
            'subject' => $subject,
            'body' => $body,
            'status' => 'delivered',
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
        $message->save();

        $conversation->setAttribute('last_message_at', date('Y-m-d H:i:s'));
        $conversation->setAttribute('unread_count', ((int) $conversation->getAttribute('unread_count')) + 1);
        $conversation->save();

        ActivityLog::record('crm', 'email.received', [
            'user_id' => $userId, 'subject_type' => 'crm_contacts', 'subject_id' => (int) $contact['id'],
            'meta' => ['subject' => $subject],
        ]);

        (new CrmAutomationService())->trigger('email.received', $userId, [
            'contact_id' => (int) $contact['id'],
        ]);
    }
}

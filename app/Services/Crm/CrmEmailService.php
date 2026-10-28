<?php

/**
 * Tourfecto - CRM Email Integration (بند 17)
 * @version 1.0.0
 *
 * لا يوجد عميل SMTP جديد هنا - يُعاد استخدام `Services/Mailer.php` الموجود
 * بالفعل بالمشروع بالكامل (بند 33: لا تنشئ أنظمة مكررة). هذه الطبقة
 * تضيف فقط: ربط الإيميلات الصادرة بمحادثة/جهة اتصال CRM (Email History -
 * بند 17)، وتسجيلها في `crm_messages`.
 *
 * الإيميلات الواردة (Inbound) تحتاج معالجة IMAP/webhook من مزوّد البريد
 * (SendGrid Inbound Parse مثلًا) غير مبنية هنا - الـArchitecture جاهزة
 * (`crm_conversations`/`crm_messages` بنفس بنية WhatsApp) لإضافتها لاحقًا
 * دون تعديل هيكلي (بند 17: "Architecture لدعم Inbound/Outbound Emails").
 */
class CrmEmailService
{
    private $mailer;

    public function __construct(?Mailer $mailer = null)
    {
        $this->mailer = $mailer ?? new Mailer();
    }

    public function isConfigured(): bool
    {
        return $this->mailer->isConfigured();
    }

    /** يرسل إيميل فعلي لجهة اتصال، ويسجّله في محادثة CRM (Email History) */
    public function sendToContact(int $userId, int $contactId, string $subject, string $htmlBody): array
    {
        $contact = (new CrmContact())->find($contactId);
        if (!$contact || (int) $contact->getAttribute('user_id') !== $userId) {
            return ['success' => false, 'error' => 'جهة الاتصال غير موجودة'];
        }
        $toEmail = $contact->getAttribute('email');
        if (empty($toEmail)) {
            return ['success' => false, 'error' => 'لا يوجد بريد إلكتروني مسجّل لجهة الاتصال هذه'];
        }

        $conversation = (new CrmConversation())->findOrCreate($userId, $contactId, 'email', $toEmail);

        $result = $this->mailer->send($toEmail, (string) $contact->getAttribute('name'), $subject, $htmlBody);

        $message = new CrmMessage([
            'conversation_id' => (int) $conversation->getAttribute('id'),
            'direction' => 'outbound',
            'sender_user_id' => $userId,
            'subject' => $subject,
            'body' => $htmlBody,
            'status' => !empty($result['success']) ? 'sent' : 'failed',
            'error' => $result['error'] ?? null,
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
        $message->save();

        $conversation->setAttribute('last_message_at', date('Y-m-d H:i:s'));
        $conversation->save();

        ActivityLog::record('crm', 'email.sent', [
            'user_id' => $userId, 'subject_type' => 'crm_contacts', 'subject_id' => $contactId,
            'meta' => ['success' => !empty($result['success']), 'subject' => $subject],
        ]);

        return array_merge($result, ['conversation_id' => (int) $conversation->getAttribute('id')]);
    }
}

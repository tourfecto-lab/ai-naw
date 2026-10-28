<?php

/**
 * Tourfecto - AI Chat Platform
 * تكامل قناة الإيميل (بند 1). يعيد استخدام Mailer الموجود بالفعل في
 * المشروع (app/Services/Mailer.php) بدون أي تعديل عليه - فقط يوفّر واجهة
 * موحّدة (sendMessage) متوافقة مع باقي قنوات الشات (WhatsApp/Messenger/
 * Instagram) حتى يسهل التبديل بينها من ChatManager.
 *
 * @version 1.0.0
 */

class EmailChannelAPI
{
    /** @var Mailer */
    private $mailer;

    public function __construct()
    {
        $this->mailer = new Mailer();
    }

    /**
     * @return bool
     */
    public function isConfigured(): bool
    {
        return $this->mailer->isConfigured();
    }

    /**
     * إرسال رد على استفسار عميل عبر الإيميل.
     * @param string $toEmail
     * @param string $toName
     * @param string $message
     * @param string $subject
     * @return bool
     */
    public function sendMessage(string $toEmail, string $message, string $toName = '', string $subject = 'Re: Your inquiry'): bool
    {
        if (!$this->isConfigured()) {
            Logger::warning('EmailChannelAPI: Mailer not configured, skipping send');
            return false;
        }

        $htmlBody = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
        $result = $this->mailer->send($toEmail, $toName, $subject, $htmlBody);

        return !empty($result['success']);
    }
}

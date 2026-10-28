<?php

/**
 * Tourfecto - Competitor Intelligence: Send Alert Email Job
 * @version 1.0.0
 *
 * يرسل تنبيه Competitor Intelligence بالإيميل عبر Mailer الموحّد
 * الموجود بالفعل بالمشروع (مفيش تكرار SMTP client جديد). يُنفّذ من
 * الـ queue الحالي (jobs table + cron/process_queue.php) - نفس آلية كل
 * الـ Jobs الأخرى بالمشروع.
 */
class SendCompetitorAlertEmailJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        $userId = (int) ($payload['user_id'] ?? 0);
        $title = (string) ($payload['title'] ?? 'Competitor Alert');
        $message = (string) ($payload['message'] ?? '');

        $user = (new User())->find($userId);
        if (!$user || empty($user->getAttribute('email'))) {
            throw new Exception("SendCompetitorAlertEmailJob: user #{$userId} not found or has no email");
        }

        $mailer = new Mailer();
        if (!$mailer->isConfigured()) {
            // ما نفشلش الـ Job بشكل نهائي - نسجّل تحذير ونخرج، عشان محدش
            // يعتقد إن فيه Retry مستمر بلا فائدة لحساب SMTP مش مُفعَّل.
            if (class_exists('Logger')) {
                Logger::warning('SendCompetitorAlertEmailJob: Mailer not configured, skipping', ['user_id' => $userId]);
            }
            return;
        }

        $html = '<div style="font-family:Arial,sans-serif;line-height:1.7;">'
            . '<h2 style="margin:0 0 10px;">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>'
            . '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p style="color:#888;font-size:12px;margin-top:20px;">Tourfecto Competitor Intelligence</p>'
            . '</div>';

        $result = $mailer->send(
            (string) $user->getAttribute('email'),
            (string) ($user->getAttribute('company_name') ?: ''),
            $title,
            $html
        );

        if (!($result['success'] ?? false)) {
            throw new Exception('SendCompetitorAlertEmailJob: mail send failed - ' . ($result['error'] ?? 'unknown'));
        }
    }
}

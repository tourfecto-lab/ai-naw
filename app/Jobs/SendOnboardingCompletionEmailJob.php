<?php

/**
 * Tourfecto - Onboarding: Completion Email Job
 * @version 1.0.0
 *
 * يرسل إيميل "تحليل موقعك جاهز" بعد اكتمال الـOnboarding في الخلفية - نفس
 * إشعارات "Your analysis is ready" في المنصات العالمية (Ahrefs/Ubersuggest).
 * بيستخدم Mailer الموحّد (SMTP خالص بلا مكتبات خارجية) من غير ما نكرر أي
 * منطق. لو البريد مش مظبوط (MAIL_USERNAME/MAIL_PASSWORD في .env) بنتجاهل
 * بصمت عشان ما نفشلش الجوب في loop بلا فائدة.
 */
class SendOnboardingCompletionEmailJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        $userId = (int) ($payload['user_id'] ?? 0);
        $websiteId = (int) ($payload['website_id'] ?? 0);

        $user = (new User())->find($userId);
        if (!$user || empty($user->getAttribute('email'))) {
            return; // المستخدم اتشال - مش خطأ يستاهل Retry
        }

        // احترام تفضيل الإشعارات البريدية للمستخدم (نفس سياسة CI Alerts).
        if (!(bool) $user->getAttribute('notify_email')) {
            if (class_exists('Logger')) {
                Logger::info('Onboarding email skipped - user disabled email notifications', ['user_id' => $userId]);
            }
            return;
        }

        $mailer = new Mailer();
        if (!$mailer->isConfigured()) {
            if (class_exists('Logger')) {
                Logger::warning('SendOnboardingCompletionEmailJob: Mailer not configured, skipping', ['user_id' => $userId]);
            }
            return;
        }

        $lang = strtolower((string) ($user->getAttribute('language') ?: 'ar'));
        $lang = in_array($lang, ['ar', 'en', 'fr', 'de'], true) ? $lang : 'ar';

        if ($lang === 'ar') {
            $subject = 'تحليل موقعك جاهز!';
            $headline = 'جاهز نبدأ نموك';
            $body = 'خلصنا تحليل موقعك وبنينا خطتك الأولى. افتح لوحة النمو عشان تشوف نتيجتك وأهم خطوة تبدأ بيها.';
        } elseif ($lang === 'en') {
            $subject = 'Your website analysis is ready!';
            $headline = 'Ready to grow';
            $body = 'We finished analyzing your website and built your first growth plan. Open the Growth dashboard to see your score and your top priority fix.';
        } elseif ($lang === 'fr') {
            $subject = 'Votre analyse est prête !';
            $headline = 'Prêt à grandir';
            $body = 'Nous avons terminé l\'analyse de votre site et construit votre plan de croissance. Ouvrez le tableau de bord Croissance.';
        } else {
            $subject = 'Ihre Website-Analyse ist fertig!';
            $headline = 'Bereit zu wachsen';
            $body = 'Wir haben Ihre Website analysiert und Ihren Wachstumsplan erstellt. Öffnen Sie das Wachstums-Dashboard.';
        }

        $appName = defined('APP_NAME') ? APP_NAME : 'Tourfecto';
        $baseUrl = defined('APP_URL') ? APP_URL : 'https://tourfecto.com';
        $dashboardUrl = $baseUrl . '/dashboard/growth';
        $logo = '<a href="' . $baseUrl . '" style="text-decoration:none;font-family:Arial,sans-serif;font-size:18px;font-weight:700;color:#EFB05E;">' . htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') . '</a>';

        $html = '<div style="font-family:Arial,sans-serif;line-height:1.7;max-width:560px;margin:0 auto;background:#0F1A2C;padding:32px;border-radius:14px;color:#F2F4F8;">'
            . '<div style="text-align:center;margin-bottom:20px;">' . $logo . '</div>'
            . '<h2 style="margin:0 0 8px;color:#EFB05E;text-align:center;">' . htmlspecialchars($headline, ENT_QUOTES, 'UTF-8') . '</h2>'
            . '<p style="color:#B9C3D4;font-size:14px;text-align:center;">' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<div style="text-align:center;margin:26px 0;">'
            . '<a href="' . $dashboardUrl . '" style="display:inline-block;background:#EFB05E;color:#1A1200;text-decoration:none;font-weight:700;font-size:14px;padding:12px 28px;border-radius:10px;">' . htmlspecialchars($lang === 'ar' ? 'افتح لوحة النمو' : ($lang === 'en' ? 'Open growth dashboard' : ($lang === 'fr' ? 'Ouvrir le tableau de bord' : 'Wachstums-Dashboard öffnen')), ENT_QUOTES, 'UTF-8') . '</a>'
            . '</div>'
            . '<p style="color:#6B7686;font-size:12px;text-align:center;margin-top:24px;">' . htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') . '</p>'
            . '</div>';

        $result = $mailer->send(
            (string) $user->getAttribute('email'),
            (string) ($user->getAttribute('company_name') ?: ''),
            $subject,
            $html
        );

        if (!($result['success'] ?? false)) {
            throw new Exception('SendOnboardingCompletionEmailJob: mail send failed - ' . ($result['error'] ?? 'unknown'));
        }

        if (class_exists('Logger')) {
            Logger::info('Onboarding completion email sent', ['user_id' => $userId, 'website_id' => $websiteId]);
        }
    }
}

<?php

/**
 * Tourfecto - Send Revenue Digest Email Job
 * @version 1.0.0
 *
 * ملخص يومي لإيرادات المستخدم (Daily Revenue Digest - Baremetrics/Clari
 * style): يبعت إيميل HTML واحد فيه أرقام حقيقية محسوبة لحظيًا من
 * الخدمات (Overview + Forecast + أهم Risk/Anomaly عالي الخطورة) - لا
 * أرقام مخترعة. يُنفّذ من الـ queue الحالي (jobs + cron/process_queue.php)
 * بنفس آلية باقي الـ Jobs بالمشروع.
 *
 * لو الـ Mailer مش متظبط أو مفيش بيانات، يخرج بدون فشل دائم (زى
 * SendCompetitorAlertEmailJob) - لا إزعاج للمستخدم بمحاولات Retry بلا
 * جدوى ولا رسالة خاطئة.
 */
class SendRevenueDigestJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        $userId = (int) ($payload['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new Exception('SendRevenueDigestJob: missing/invalid user_id in payload');
        }

        $user = (new User())->find($userId);
        if (!$user || empty($user->getAttribute('email'))) {
            throw new Exception("SendRevenueDigestJob: user #{$userId} not found or has no email");
        }

        // Phase 16C: لو المستخدم قفّل الملخص اليومي من Settings >
        // Notifications، نتخطاه من غير ما نبعت إيميل ولا حتى ندخل على
        // حسابات البيانات (Togglable Daily Digest - GitHub/Stripe parity).
        // `digest_daily` هو المعرّف الرسمي اللي Settings بيستعمله.
        if (class_exists('Notification') && !Notification::digestEnabledFor($user, 'digest_daily')) {
            if (class_exists('Logger')) {
                Logger::info('SendRevenueDigestJob: user opted out of daily digest, skipping', ['user_id' => $userId]);
            }
            return;
        }

        $mailer = new Mailer();
        if (!$mailer->isConfigured()) {
            if (class_exists('Logger')) {
                Logger::warning('SendRevenueDigestJob: Mailer not configured, skipping', ['user_id' => $userId]);
            }
            return;
        }

        $overview = (new RevenueOverviewService())->getOverview($userId, 'monthly');
        if (!$overview['has_data']) {
            if (class_exists('Logger')) {
                Logger::info('SendRevenueDigestJob: no revenue data yet, skipping', ['user_id' => $userId]);
            }
            return;
        }

        $forecast = (new RevenueForecastService())->forecast($userId, 'monthly', false);

        $topRisks = [];
        $insights = (new RevenueInsightService())->getRisks($userId);
        foreach ($insights as $risk) {
            if (($risk['severity'] ?? null) === 'high' || ($risk['confidence'] ?? null) === 'high') {
                $topRisks[] = $risk['finding'];
            }
            if (count($topRisks) >= 3) {
                break;
            }
        }

        $subject = 'Revenue Digest — ' . date('Y-m-d');
        $html = self::buildDigestHtml($overview, $forecast, $topRisks);

        $result = $mailer->send(
            (string) $user->getAttribute('email'),
            (string) ($user->getAttribute('company_name') ?: ''),
            $subject,
            $html
        );

        if (!($result['success'] ?? false)) {
            throw new Exception('SendRevenueDigestJob: mail send failed - ' . ($result['error'] ?? 'unknown'));
        }
    }

    /** Pure function - يبني HTML الملخص من أرقام حقيقية فقط (قابل للاختبار). */
    public static function buildDigestHtml(array $overview, array $forecast, array $topRisks = []): string
    {
        $fmt = static function ($v) {
            return $v === null ? '—' : number_format((float) $v, 2);
        };

        $riskRows = '';
        foreach ($topRisks as $finding) {
            $riskRows .= '<li>' . htmlspecialchars((string) $finding, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        $riskBlock = $riskRows !== ''
            ? '<h3 style="margin:18px 0 6px;color:#B45309;">Key Risks</h3><ul style="margin:0;padding-left:18px;line-height:1.6;">' . $riskRows . '</ul>'
            : '<p style="color:#888;font-size:13px;margin:12px 0 0;">No high-severity risks detected.</p>';

        $forecastBlock = !empty($forecast['insufficient_data'])
            ? '<p style="color:#888;font-size:13px;margin:0;">Not enough data for a reliable forecast yet.</p>'
            : '<p style="margin:0;">Expected: <b>' . $fmt($forecast['expected_revenue'] ?? null) . '</b>'
                . ' (range ' . $fmt($forecast['forecast_range']['low'] ?? null)
                . ' – ' . $fmt($forecast['forecast_range']['high'] ?? null) . ')</p>';

        return '<div style="font-family:Arial,sans-serif;line-height:1.7;color:#1F2937;">'
            . '<h2 style="margin:0 0 14px;color:#111827;">Daily Revenue Digest</h2>'
            . '<div style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;padding:14px 16px;">'
            . '<p style="margin:0 0 4px;">Revenue (last 30 days): <b>' . $fmt($overview['total_revenue'] ?? null) . '</b></p>'
            . '<p style="margin:0 0 4px;">Growth vs previous period: <b>' . ($overview['growth_percent'] === null ? '—' : $overview['growth_percent'] . '%') . '</b></p>'
            . '<p style="margin:0;">Records: ' . (int) ($overview['revenue_records_count'] ?? 0) . '</p>'
            . '</div>'
            . '<h3 style="margin:18px 0 6px;color:#2563EB;">Forecast (next month)</h3>'
            . $forecastBlock
            . $riskBlock
            . '<p style="color:#888;font-size:12px;margin-top:20px;">All figures are computed from your real revenue records. Generated by Tourfecto AI Revenue Intelligence.</p>'
            . '</div>';
    }
}

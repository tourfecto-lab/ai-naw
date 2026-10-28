<?php

/**
 * Tourfecto - SEO Scheduled Reports Cron (G6)
 * @version 1.0.0
 *
 * إعداد Cron Job في cPanel (Hostinger) - كل ساعة:
 *   php /home/USERNAME/domains/YOURSITE.com/cron/seo_scheduled_reports.php >> /home/USERNAME/domains/YOURSITE.com/storage/logs/seo_scheduled_reports.log 2>&1
 *
 * يرسل تقارير SEO البريدية المستحقة (daily/weekly/monthly حسب
 * seo_report_schedules) عبر Mailer. لو البريد مش متظبط → تخطي آمن.
 */

require_once __DIR__ . '/bootstrap.php';

$startedAt = microtime(true);

try {
    $db = Database::getInstance();
    $service = new SeoScheduledReportService($db);
    $result = $service->sendDue(50);

    $durationMs = round((microtime(true) - $startedAt) * 1000);
    fwrite(STDOUT, sprintf(
        "[%s] SEO Scheduled Reports: %d attempted, %d sent, %d skipped (no mailer), %d errors (%dms)\n",
        date('Y-m-d H:i:s'),
        $result['attempted'],
        $result['sent'],
        $result['skipped_no_mailer'],
        count($result['errors']),
        $durationMs
    ));
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] SEO scheduled reports error: ' . $e->getMessage() . "\n");
    if (class_exists('Logger')) {
        Logger::error('SEO scheduled reports cron failed', ['error' => $e->getMessage()]);
    }
    exit(1);
}

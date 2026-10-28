<?php

/**
 * Tourfecto - Backlink Monitoring Cron (Item 2a)
 * @version 1.0.0
 *
 * إعداد Cron Job في cPanel (Hostinger) - يفضّل أسبوعيًا:
 *   php /home/USERNAME/domains/YOURSITE.com/cron/monitor_backlinks.php >> /home/USERNAME/domains/YOURSITE.com/storage/logs/monitor_backlinks.log 2>&1
 *
 * بيفحص كل الباك لينكس اللي عدّى على آخر فحص لها 7 أيام (أو لسه
 * متفحصةش) عبر BacklinkMonitorService::monitorDue() وبيحدّث حالتها
 * live/lost. بيستخدم نفس منطق الأمان (SSRF guard) الموجود في
 * WebsiteSnapshotFetcher.
 */

require_once __DIR__ . '/bootstrap.php';

$startedAt = microtime(true);

try {
    if (!class_exists('BacklinkMonitorService')) {
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] BacklinkMonitorService غير متاحة - تأكد من رفع ملفات الموديول وتشغيل الميجريشن:\n");
        fwrite(STDOUT, "  database/migrations/2026_08_31_000001_create_monitored_backlinks.sql\n");
        exit(0);
    }

    $service = new BacklinkMonitorService();
    $stats = $service->monitorDue(200);
    $durationMs = round((microtime(true) - $startedAt) * 1000);

    fwrite(STDOUT, sprintf(
        "[%s] Backlink Monitor: %d فحصوا، %d حي، %d فقد، %d فشل (%dms)\n",
        date('Y-m-d H:i:s'),
        $stats['scanned'],
        $stats['live'],
        $stats['lost'],
        $stats['failed'],
        $durationMs
    ));
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Backlink monitor error: ' . $e->getMessage() . "\n");
    if (class_exists('Logger')) {
        Logger::error('Backlink monitor cron failed', ['error' => $e->getMessage()]);
    }
    exit(1);
}

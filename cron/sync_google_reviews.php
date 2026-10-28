<?php

/**
 * Tourfecto - مزامنة دورية لمراجعات Google Business
 * @version 1.0.0
 *
 * إعداد Cron Job في cPanel (Hostinger):
 * ------------------------------------
 * Google مبيقدّمش webhook فوري لمراجعات جديدة، فلازم نسحبها بأنفسنا كل
 * فترة. مقترح: كل 6 ساعات (مش محتاج كل دقيقة زي طابور المهام العادي).
 *
 * Common Settings: Every 6 hours (0 star-slash-6 * * *)
 * Command (غيّر المسار حسب اسم الدومين الحقيقي عندك):
 *   php /home/USERNAME/domains/YOURSITE.com/cron/sync_google_reviews.php >> /home/USERNAME/domains/YOURSITE.com/storage/logs/google_sync.log 2>&1
 */

require_once __DIR__ . '/bootstrap.php';

$startedAt = microtime(true);

try {
    $service = new GoogleReviewSyncService();
    $summary = $service->syncAll();
    $durationMs = round((microtime(true) - $startedAt) * 1000);

    fwrite(STDOUT, sprintf(
        "[%s] Google Reviews Sync: %d اتصال اتزامن، %d مراجعة جديدة، %d خطأ (%dms)\n",
        date('Y-m-d H:i:s'),
        $summary['synced'],
        $summary['new_reviews'],
        $summary['errors'],
        $durationMs
    ));
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Google sync error: ' . $e->getMessage() . "\n");
    if (class_exists('Logger')) {
        Logger::error('Cron Google review sync failed', ['error' => $e->getMessage()]);
    }
    exit(1);
}

<?php

/**
 * Tourfecto - مزامنة دورية لمراجعات TripAdvisor
 * @version 1.0.0
 *
 * إعداد Cron Job في cPanel:
 * Command: php /home/USERNAME/domains/YOURSITE.com/cron/sync_tripadvisor_reviews.php >> /home/USERNAME/domains/YOURSITE.com/storage/logs/tripadvisor_sync.log 2>&1
 * Schedule: كل 6 ساعات (نفس فترة Google كفاية، TripAdvisor برضه مفيش عندها webhook فوري)
 */

require_once __DIR__ . '/bootstrap.php';

$startedAt = microtime(true);

try {
    $service = new TripAdvisorReviewSyncService();
    $summary = $service->syncAll();
    $durationMs = round((microtime(true) - $startedAt) * 1000);

    fwrite(STDOUT, sprintf(
        "[%s] TripAdvisor Reviews Sync: %d اتصال اتزامن، %d مراجعة جديدة، %d خطأ (%dms)\n",
        date('Y-m-d H:i:s'),
        $summary['synced'],
        $summary['new_reviews'],
        $summary['errors'],
        $durationMs
    ));
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] TripAdvisor sync error: ' . $e->getMessage() . "\n");
    if (class_exists('Logger')) {
        Logger::error('Cron TripAdvisor review sync failed', ['error' => $e->getMessage()]);
    }
    exit(1);
}

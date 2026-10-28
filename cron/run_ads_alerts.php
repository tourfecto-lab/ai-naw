<?php

/**
 * Tourfecto - Ad Alerts: Periodic Scheduler
 * @version 1.0.0
 *
 * بيشغّل AdAlertService::runForAllUsers() اللي بقيّم حملات كل العملاء
 * النشطة مقابل قواعد التنبيهات الاستباقية (budget_exhausted / cpc_spike /
 * ctr_drop / landing_page_down / budget_pacing) وبيولّد ad_alerts +
 * إشعارات داخلية عند أي مخالفة. كل التقييمات على بيانات أداء حقيقية.
 *
 * Common Settings: مرة كل ساعة كافية لمراقبة دورية معقولة.
 * Command (غيّر المسار حسب اسم الدومين الحقيقي عندك):
 *   php /home/USERNAME/domains/YOURSITE.com/cron/run_ads_alerts.php >> /home/USERNAME/domains/YOURSITE.com/storage/logs/ads_alerts.log 2>&1
 */

require_once __DIR__ . '/bootstrap.php';

$startedAt = microtime(true);

try {
    $service = new AdAlertService();
    $summary = $service->runForAllUsers();

    $durationMs = round((microtime(true) - $startedAt) * 1000);
    fwrite(STDOUT, sprintf(
        "[%s] Ads Alerts: users_evaluated=%d alerts_generated=%d errors=%d (%dms)\n",
        date('Y-m-d H:i:s'),
        $summary['users_evaluated'] ?? 0,
        $summary['alerts_generated'] ?? 0,
        $summary['errors'] ?? 0,
        $durationMs
    ));
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Ads Alerts error: ' . $e->getMessage() . "\n");
    if (class_exists('Logger')) {
        Logger::error('Ads Alerts scheduler failed', ['error' => $e->getMessage()]);
    }
    exit(1);
}

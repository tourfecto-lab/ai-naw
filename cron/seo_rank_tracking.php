<?php

/**
 * Tourfecto - SEO Rank Tracking Cron (G7)
 * @version 1.0.0
 *
 * إعداد Cron Job في cPanel (Hostinger) - يفضّل يوميًا:
 *   php /home/USERNAME/domains/YOURSITE.com/cron/seo_rank_tracking.php >> /home/USERNAME/domains/YOURSITE.com/storage/logs/seo_rank_tracking.log 2>&1
 *
 * يفحص ترتيب `tracked_keywords` للمواقع المستحقة (مرّ يوم منذ آخر فحص)
 * عبر KeywordRankingSourceInterface ويكتب التاريخ في seo_rank_tracking_history.
 * لو مفيش مصدر SERP مهيأ → يتخطى الموقع بأمان (لا اختلاق ترتيبات).
 */

require_once __DIR__ . '/bootstrap.php';

$startedAt = microtime(true);

try {
    $db = Database::getInstance();
    $service = new RankTrackingService();

    $checked = 0;
    $recorded = 0;
    $skippedNoSource = 0;
    $errors = 0;

    foreach ($service->dueWebsites($db, 50) as $site) {
        try {
            $res = $service->checkWebsite($db, (int) $site['id'], (int) $site['user_id']);
            if (!empty($res['available'])) {
                $checked += $res['checked'];
                $recorded += $res['recorded'];
            } else {
                $skippedNoSource++;
            }
        } catch (Throwable $e) {
            $errors++;
            if (class_exists('Logger')) {
                Logger::warning('SEO rank tracking failed', ['website_id' => $site['id'], 'error' => $e->getMessage()]);
            }
        }
    }

    $durationMs = round((microtime(true) - $startedAt) * 1000);
    fwrite(STDOUT, sprintf(
        "[%s] SEO Rank Tracking: %d checked, %d recorded, %d skipped (no source), %d errors (%dms)\n",
        date('Y-m-d H:i:s'),
        $checked,
        $recorded,
        $skippedNoSource,
        $errors,
        $durationMs
    ));
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] SEO rank tracking error: ' . $e->getMessage() . "\n");
    if (class_exists('Logger')) {
        Logger::error('SEO rank tracking cron failed', ['error' => $e->getMessage()]);
    }
    exit(1);
}

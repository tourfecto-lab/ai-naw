<?php

/**
 * Tourfecto - Auto SEO Scheduler (إعادة تدقيق + إعادة فهرسة دورية)
 * @version 1.0.0
 *
 * إعداد Cron Job في cPanel (Hostinger):
 *   كل ساعة:
 *   php /home/USERNAME/domains/YOURSITE.com/cron/auto_seo_scheduler.php >> /home/USERNAME/domains/YOURSITE.com/storage/logs/auto_seo_scheduler.log 2>&1
 *
 * الوظيفة:
 * 1) إعادة فهرسة (IndexNow): المواقع المفعّلة IndexNow بيعاد إرسال صفحاتها
 *    كل فترة (رخيص وآمن - POST واحد لمحركات البحث).
 * 2) إعادة تدقيق: المواقع المستحقة (حسب seo_audit_frequency) بيتضاف ليها
 *    Job لطابور المهام العادي (AutoSeoReauditJob)، اللي بينفّذه
 *    cron/process_queue.php - الفصل ده يمنع أي Cron واحد يفضل شغال طويلًا
 *    وهو بيعيد تدقيق عشرات المواقع.
 */

require_once __DIR__ . '/bootstrap.php';

$startedAt = microtime(true);

try {
    $db = Database::getInstance();
    $scheduler = new SeoSchedulerService($db);

    // ---------- 1) إعادة الفهرسة (IndexNow) ----------
    $reindexed = 0;
    $dueReindex = $scheduler->reindexDueSites(100);
    foreach ($dueReindex as $site) {
        try {
            $res = $scheduler->reindexSite($site);
            if (!empty($res['success'])) {
                $reindexed++;
            }
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('Auto SEO scheduler: reindex failed', ['website_id' => $site['id'], 'error' => $e->getMessage()]);
            }
        }
    }

    // ---------- 2) إعادة التدقيق (طابور المهام) ----------
    $enqueued = 0;
    $dueReaudit = $scheduler->reauditDueSites(50);
    if (!empty($dueReaudit)) {
        $queue = new QueueManager();
        foreach ($dueReaudit as $site) {
            $pushed = $queue->push('AutoSeoReauditJob', [
                'website_id' => (int) $site['id'],
                'user_id' => (int) $site['user_id'],
            ]);
            if ($pushed) {
                $enqueued++;
            }
        }
    }

    $durationMs = round((microtime(true) - $startedAt) * 1000);
    fwrite(STDOUT, sprintf(
        "[%s] Auto SEO Scheduler: %d reindexed, %d re-audit jobs enqueued (%dms)\n",
        date('Y-m-d H:i:s'),
        $reindexed,
        $enqueued,
        $durationMs
    ));
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Auto SEO scheduler error: ' . $e->getMessage() . "\n");
    if (class_exists('Logger')) {
        Logger::error('Auto SEO scheduler failed', ['error' => $e->getMessage()]);
    }
    exit(1);
}

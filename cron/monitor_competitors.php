<?php

/**
 * Tourfecto - Competitor Intelligence: Monitoring Scheduler
 * @version 1.0.0
 *
 * إعداد Cron Job في cPanel (Hostinger):
 * ------------------------------------
 * السكربت ده لا يجلب أي صفحة بنفسه - وظيفته الوحيدة إنه يحدد أي منافس
 * "مستحق" لدورة مراقبة جديدة (حسب monitoring_frequency وآخر
 * last_monitored_at) وياخد له Job لطابور المهام العادي
 * (MonitorCompetitorJob)، اللي بينفّذه cron/process_queue.php الموجود
 * بالفعل. الفصل ده يمنع أي طلب HTTP أو Cron واحد يفضل شغال لفترة
 * طويلة وهو بيراقب عشرات المنافسين.
 *
 * إضافة: نفس السكربت بيحسب Scorecards دورية (مرة كل يوم كحد أقصى لكل
 * منافس) عبر BenchmarkingService::computeScorecard() - ده استعلام
 * تجميعي على بيانات موجودة بالفعل (مفيش HTTP requests)، فمفيش داعي
 * لـ Job منفصل له.
 *
 * Common Settings: كل 30 دقيقة (كافية لتغطية daily/weekly/custom بدقة
 * معقولة بدون Overhead) - cron: كل 30 دقيقة كل ساعة
 * Command (غيّر المسار حسب اسم الدومين الحقيقي عندك):
 *   php /home/USERNAME/domains/YOURSITE.com/cron/monitor_competitors.php >> /home/USERNAME/domains/YOURSITE.com/storage/logs/ci_scheduler.log 2>&1
 */

require_once __DIR__ . '/bootstrap.php';

$startedAt = microtime(true);

try {
    $db = Database::getInstance();

    // مستحق للمراقبة لو: مش متوقف، نشط، ومفيش last_monitored_at أصلاً
    // (أول مرة)، أو عدّى المدة المناسبة لتكراره المختار.
    $due = $db->query(
        "SELECT id, monitoring_frequency, monitoring_interval_hours FROM competitors
         WHERE is_active = 1 AND monitoring_paused = 0 AND (
             last_monitored_at IS NULL
             OR (monitoring_frequency = 'daily' AND last_monitored_at <= DATE_SUB(NOW(), INTERVAL 1 DAY))
             OR (monitoring_frequency = 'weekly' AND last_monitored_at <= DATE_SUB(NOW(), INTERVAL 7 DAY))
             OR (monitoring_frequency = 'custom' AND monitoring_interval_hours IS NOT NULL
                 AND last_monitored_at <= DATE_SUB(NOW(), INTERVAL monitoring_interval_hours HOUR))
         )
         LIMIT 200" // سقف لكل دورة تشغيل - يمنع تراكم آلاف المهام مرة واحدة على تطبيق كبير
    );

    $queue = new QueueManager();
    $enqueued = 0;

    foreach ($due as $row) {
        $pushed = $queue->push('MonitorCompetitorJob', ['competitor_id' => (int) $row['id']], 'competitor_intelligence');
        if ($pushed) {
            $enqueued++;
        }
    }

    // ------------------------------------------------------------
    // Scorecards دورية (Benchmarking): مرة واحدة يوميًا كحد أقصى لكل
    // منافس (مش كل تشغيلة سكريبت كل 30 دقيقة) - نتحقق من ci_scorecards
    // نفسها بدل عمود إضافي، للحفاظ على مصدر حقيقة واحد. سقف 100 منافس
    // لكل دورة تشغيل لنفس سبب سقف المراقبة أعلاه.
    $needsScorecard = $db->query(
        "SELECT c.id FROM competitors c
         LEFT JOIN (SELECT competitor_id, MAX(computed_at) AS last_computed FROM ci_scorecards GROUP BY competitor_id) s
           ON s.competitor_id = c.id
         WHERE c.is_active = 1
           AND (s.last_computed IS NULL OR s.last_computed <= DATE_SUB(NOW(), INTERVAL 1 DAY))
         LIMIT 100"
    );

    $benchmarking = new BenchmarkingService();
    $scorecardsComputed = 0;
    foreach ($needsScorecard as $row) {
        try {
            $benchmarking->computeScorecard((int) $row['id']);
            $scorecardsComputed++;
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('CI scheduler: scorecard computation failed', ['competitor_id' => $row['id'], 'error' => $e->getMessage()]);
            }
        }
    }

    $durationMs = round((microtime(true) - $startedAt) * 1000);
    fwrite(STDOUT, sprintf(
        "[%s] Competitor Intelligence Scheduler: %d/%d منافس اتجدولوا للمراقبة، %d Scorecard اتحسبوا (%dms)\n",
        date('Y-m-d H:i:s'),
        $enqueued,
        count($due),
        $scorecardsComputed,
        $durationMs
    ));
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] CI scheduler error: ' . $e->getMessage() . "\n");
    if (class_exists('Logger')) {
        Logger::error('Competitor Intelligence scheduler failed', ['error' => $e->getMessage()]);
    }
    exit(1);
}

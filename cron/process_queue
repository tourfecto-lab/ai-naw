<?php
/**
 * Tourfecto - معالج طابور المهام (يُستدعى من Cron Job حقيقي)
 * @version 1.0.0
 *
 * إعداد Cron Job في cPanel (Hostinger):
 * ------------------------------------
 * 1) ادخل cPanel -> Cron Jobs
 * 2) Common Settings: Every Minute (*​ * * * *) - أو كل دقيقتين لو حابب تقلل الحمل
 * 3) Command (غيّر المسار حسب اسم الدومين الحقيقي عندك):
 *    php /home/USERNAME/domains/YOURSITE.com/cron/process_queue.php >> /home/USERNAME/domains/YOURSITE.com/storage/logs/queue.log 2>&1
 *
 * ملاحظة: ده مش "worker دائم" حقيقي - هو سكريبت بيشتغل، يعالج المهام
 * المستحقة المتاحة وقتها، وبعدين يقفل. الاستمرارية بتيجي من تكرار الكرون
 * كل دقيقة، مش process فاضل شغال. مناسب للاستضافة المشتركة الحالية.
 */

require_once __DIR__ . '/bootstrap.php';

$startedAt = microtime(true);

try {
    $queue = new QueueManager();

    if (!$queue->isReady()) {
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] jobs table غير موجود بعد. شغّل migration:\n");
        fwrite(STDOUT, "  database/migrations/2026_07_13_000001_create_jobs_table.sql\n");
        exit(0);
    }

    $result = $queue->processDue(20);
    $durationMs = round((microtime(true) - $startedAt) * 1000);

    fwrite(STDOUT, sprintf(
        "[%s] Queue run: %d نُفّذت، %d فشلت، من إجمالي %d (%dms)\n",
        date('Y-m-d H:i:s'),
        $result['processed'],
        $result['failed'],
        $result['total'] ?? 0,
        $durationMs
    ));
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Queue processing error: ' . $e->getMessage() . "\n");
    if (class_exists('Logger')) {
        Logger::error('Cron queue processing failed', ['error' => $e->getMessage()]);
    }
    exit(1);
}

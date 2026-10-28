<?php
/**
 * Tourfecto - مركز الأتمتة: الدورة الدورية
 * @version 1.0.0
 *
 * بيعمل حاجتين:
 *  1) الرد على تقييمات Google الجديدة لكل المواقع المربوطة (أوتوماتيك أو
 *     تجهيز الرد للموافقة حسب اختيار كل عميل).
 *  2) تنفيذ أي حاجة العميل وافق عليها ولسه ما اتنفذتش (مثلاً لو Google كان
 *     واقع وقت الموافقة) - بحد أقصى مرة كل ساعة لنفس الإجراء.
 *
 * إعداد Cron Job (كل 10 دقايق):
 *   *\/10 * * * *  php /home/USER/domains/SITE/cron/run_automations.php >> /home/USER/domains/SITE/storage/logs/automations.log 2>&1
 */

require_once __DIR__ . '/bootstrap.php';

$startedAt = microtime(true);
$lockFile = sys_get_temp_dir() . '/tourfecto_run_automations.lock';
$lock = @fopen($lockFile, 'c');
if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] دورة سابقة لسه شغالة - تخطّي\n");
    exit(0);
}

$summary = ['reviews' => null, 'retried' => 0, 'retry_failed' => 0];

try {
    if (!class_exists('AutomationEngine')) {
        fwrite(STDOUT, "AutomationEngine غير متاح - تأكد من رفع app/Services/Automation\n");
        exit(0);
    }

    // 1) التقييمات
    try {
        $summary['reviews'] = (new ReviewAutoReplyRunner())->runAll();
    } catch (Throwable $e) {
        fwrite(STDERR, 'reviews: ' . $e->getMessage() . "\n");
    }

    // 2) موافقات اتعملت ولسه ما اتنفذتش، أو تنفيذ فشل وينفع يتعاد
    $db = Database::getInstance();
    $engine = AutomationEngine::instance();
    $rows = $db->query(
        "SELECT id FROM automation_decisions
          WHERE executed_at IS NULL
            AND (
                  (status IN ('approved','approved_edited') AND decided_at <= NOW() - INTERVAL 2 MINUTE AND error_message IS NULL)
               OR (status IN ('approved','approved_edited') AND error_message IS NOT NULL AND updated_at <= NOW() - INTERVAL 1 HOUR AND decided_at >= NOW() - INTERVAL 3 DAY)
            )
          ORDER BY id ASC LIMIT 50"
    );
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $res = $engine->executeDecision($id);
        if (!empty($res['success'])) {
            $summary['retried']++;
        } else {
            $summary['retry_failed']++;
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] run_automations error: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    if ($lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

$elapsed = round(microtime(true) - $startedAt, 2);
fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] automations: ' . json_encode($summary, JSON_UNESCAPED_UNICODE) . " in {$elapsed}s\n");
exit(0);

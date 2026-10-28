<?php

/**
 * Tourfecto - Revenue Action Executor Cron (طبقة التنفيذ) v1.0.0
 *
 * بيحوّل توصيات الإيرادات (المخاطر/الفرص/الشذوذ) لإجراءات فعلية بشكل
 * تلقائي: إنشاء مهام CRM + إشعار داخلي للأعلى خطورة - مع منع التكرار.
 *
 * إعداد Cron Job في cPanel (Hostinger) - كل 15-30 دقيقة:
 *   php /home/USERNAME/domains/YOURSITE.com/cron/revenue_action_executor.php >> /home/USERNAME/domains/YOURSITE.com/storage/logs/revenue_action_executor.log 2>&1
 *
 * إيقاف مؤقت للتنفيذ التلقائي من غير لمس كود: ضع setting بقيمة 0 في جدول
 * system_settings (المفتاح: revai_auto_execute) - زرار "تنفيذ" اليدوي في
 * اللوحة بيشتغل دايما بغض النظر عن الإعداد ده.
 */

require_once __DIR__ . '/bootstrap.php';

$startedAt = microtime(true);

try {
    $db = Database::getInstance();

    // بوابة التنفيذ التلقائي (لو الجدول مش موجود أو الإعداد فاضي => مفتوح)
    $autoEnabled = true;
    try {
        $rows = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'revai_auto_execute' LIMIT 1");
        if (!empty($rows) && (string) ($rows[0]['setting_value'] ?? '') === '0') {
            $autoEnabled = false;
        }
    } catch (Throwable $e) {
        $autoEnabled = true;
    }

    if (!$autoEnabled) {
        echo '[' . date('Y-m-d H:i:s') . "] Revenue action executor: auto-execute disabled (revai_auto_execute=0). Skipping.\n";
        exit(0);
    }

    // المستخدمون النشطون إيراديًا آخر 60 يوم
    $userIds = $db->query(
        "SELECT DISTINCT user_id FROM rev_revenue_records
          WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)"
    );

    $executor = new RevenueActionExecutor($db);
    $totals = ['users' => 0, 'planned' => 0, 'executed' => 0, 'tasks_created' => 0, 'notifications_sent' => 0, 'skipped' => 0];

    foreach ($userIds as $row) {
        $userId = (int) $row['user_id'];
        try {
            $actions = (new RevenueActionService())->getNextBestActions($userId, 10);
            if (empty($actions)) {
                continue;
            }
            $summary = $executor->executeActions($userId, $actions, ['window_days' => 7]);
            $totals['users']++;
            foreach (['planned', 'executed', 'tasks_created', 'notifications_sent', 'skipped'] as $k) {
                $totals[$k] += $summary[$k];
            }
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('Revenue action executor: user failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            }
        }
    }

    $durationMs = round((microtime(true) - $startedAt) * 1000);
    fwrite(STDOUT, sprintf(
        "[%s] Revenue Action Executor: %d users, %d planned, %d executed, %d tasks, %d notifications, %d skipped (%dms)\n",
        date('Y-m-d H:i:s'),
        $totals['users'],
        $totals['planned'],
        $totals['executed'],
        $totals['tasks_created'],
        $totals['notifications_sent'],
        $totals['skipped'],
        $durationMs
    ));
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Revenue action executor error: ' . $e->getMessage() . "\n");
    if (class_exists('Logger')) {
        Logger::error('Revenue action executor failed', ['error' => $e->getMessage()]);
    }
    exit(1);
}

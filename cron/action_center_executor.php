<?php

/**
 * Tourfecto - Action Center Executor Cron (المنفّذ الموحّد) v1.0.0
 *
 * بيحوّل توصيات وحدات التحليل المجمّعة في Action Center (Competitor
 * Intelligence + CEO Advisor + Marketing Assistant) لإجراءات فعلية بشكل
 * تلقائي: إنشاء مهام CRM + إشعار داخلي للأولوية العالية - مع منع التكرار.
 *
 * إعداد Cron Job في cPanel (Hostinger) - كل 15-30 دقيقة:
 *   php /home/USERNAME/domains/YOURSITE.com/cron/action_center_executor.php >> /home/USERNAME/domains/YOURSITE.com/storage/logs/action_center_executor.log 2>&1
 *
 * إيقاف مؤقت للتنفيذ التلقائي من غير لمس كود: ضع setting بقيمة 0 في جدول
 * system_settings (المفتاح: action_center_auto_execute) - زرار "تنفيذ"
 * اليدوي في اللوحة بيشتغل دايما بغض النظر عن الإعداد ده.
 */

require_once __DIR__ . '/bootstrap.php';

$startedAt = microtime(true);

try {
    $db = Database::getInstance();

    // بوابة التنفيذ التلقائي (لو الجدول مش موجود أو الإعداد فاضي => مفتوح)
    $autoEnabled = true;
    try {
        $rows = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'action_center_auto_execute' LIMIT 1");
        if (!empty($rows) && (string) ($rows[0]['setting_value'] ?? '') === '0') {
            $autoEnabled = false;
        }
    } catch (Throwable $e) {
        $autoEnabled = true;
    }

    if (!$autoEnabled) {
        echo '[' . date('Y-m-d H:i:s') . "] Action center executor: auto-execute disabled (action_center_auto_execute=0). Skipping.\n";
        exit(0);
    }

    // المستخدمون النشطون من مصادر التحليل القابلة للتنفيذ
    $userIds = [];
    foreach (['ci_insights', 'ceo_risk_alerts', 'ceo_growth_opportunities', 'ai_assistant_interactions'] as $table) {
        try {
            $rows = $db->query(
                "SELECT DISTINCT user_id FROM {$table}
                  WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
            );
            foreach ($rows as $row) {
                $userIds[(int) $row['user_id']] = true;
            }
        } catch (Throwable $e) {
            // الجدول مش موجود محليًا/إنتاجيًا - نتخطاه بأمان
        }
    }

    if (empty($userIds)) {
        echo '[' . date('Y-m-d H:i:s') . "] Action center executor: no active users found.\n";
        exit(0);
    }

    $executor = new ActionCenterExecutor($db);
    $service = new ActionCenterExecutionService();
    $totals = ['users' => 0, 'planned' => 0, 'executed' => 0, 'tasks_created' => 0, 'notifications_sent' => 0, 'skipped' => 0];

    foreach (array_keys($userIds) as $userId) {
        try {
            $actions = $service->getNextBestActions($db, (int) $userId, null, 20);
            if (empty($actions)) {
                continue;
            }
            $summary = $executor->executeActions((int) $userId, $actions, ['window_days' => 7]);
            $totals['users']++;
            foreach (['planned', 'executed', 'tasks_created', 'notifications_sent', 'skipped'] as $k) {
                $totals[$k] += $summary[$k];
            }
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('Action center executor: user failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            }
        }
    }

    $durationMs = round((microtime(true) - $startedAt) * 1000);
    fwrite(STDOUT, sprintf(
        "[%s] Action Center Executor: %d users, %d planned, %d executed, %d tasks, %d notifications, %d skipped (%dms)\n",
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
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Action center executor error: ' . $e->getMessage() . "\n");
    if (class_exists('Logger')) {
        Logger::error('Action center executor failed', ['error' => $e->getMessage()]);
    }
    exit(1);
}

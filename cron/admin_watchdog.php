<?php
/**
 * Tourfecto - حارس لوحة الأدمن (كل 10 دقايق من المنظّم)
 *
 *  1) اختبار تلقائي للتكاملات المتظبطة (كل 6 ساعات لكل تكامل) - النتيجة في صفحة صحة المنصة.
 *  2) تقييم التنبيهات (AdminAlertService) والإبلاغ بالإيميل/الإشعارات/الواتساب.
 *  3) تنظيف سجل البريد القديم.
 */

require_once __DIR__ . '/bootstrap.php';

$out = ['integration_checks' => 0, 'alerts' => null];
try {
    // 1) التكاملات
    if (class_exists('IntegrationsController') && class_exists('IntegrationManager')) {
        $recent = [];
        try {
            foreach (Database::getInstance()->query('SELECT integration_key FROM integration_checks WHERE checked_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)') as $r) {
                $recent[$r['integration_key']] = true;
            }
        } catch (Throwable $e) {
        }
        $registry = require APP_PATH . '/Config/integrations.php';
        $ctrl = new IntegrationsController();
        foreach (array_keys($registry) as $key) {
            if (isset($recent[$key]) || $out['integration_checks'] >= 6) { // حد في الدورة عشان ماتطولش
                continue;
            }
            if ($ctrl->runAutomatedCheck($key) !== null) {
                $out['integration_checks']++;
            }
        }
    }

    // 2) التنبيهات
    if (class_exists('AdminAlertService')) {
        $out['alerts'] = (new AdminAlertService())->run();
    }

    // 3) تنظيف
    if (class_exists('MailLog') && (int) date('i') < 10) {
        MailLog::cleanup();
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'admin_watchdog: ' . $e->getMessage() . "\n");
    exit(1);
}
echo '[' . date('Y-m-d H:i:s') . '] watchdog: ' . json_encode($out, JSON_UNESCAPED_UNICODE) . "\n";
exit(0);

<?php

/**
 * TOURFECTO AI CEO — الدورة المجدولة (اختيارية).
 *
 * بتحدّث صورة المنصة، وتحفظ القرارات، وفي AUTO MODE بتوافق على القرارات منخفضة الخطر بس.
 * مفيش أي طلب ذكاء اصطناعي هنا (الأرقام من خدمات الأدمن). زرار الطوارئ بيوقفها.
 *
 * Cron (كل ساعة كفاية):
 *   php /www/wwwroot/SITE/cron/ai_ceo_cycle.php >> /www/wwwroot/SITE/storage/logs/ai_ceo_cycle.log 2>&1
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/Services/AiCeo/AiCeoStore.php';
require_once __DIR__ . '/../app/Services/AiCeo/PlatformSnapshot.php';

$db = Database::getInstance();
$store = new AiCeoStore($db);
if ($store->settings()['paused']) { echo gmdate('c') . ' ai ceo: emergency stop is on, nothing done' . PHP_EOL; exit(0); }

// المرحلة 3: نحفظ أي تحليل جديد لشركات السياحة في تاريخ التقارير، ونكمّل سجل إلغاء الاشتراك
$tbi = ['reports' => 0, 'unsubscribes' => 0];
try {
    require_once __DIR__ . '/../app/Services/AiCeo/TourismBusinessDb.php';
    $tdb = new TourismBusinessDb($db);
    $tbi = ['reports' => $tdb->captureReports(), 'unsubscribes' => $tdb->backfillUnsubscribes()];
    // المرحلة 4: اللي اتوافق عليه قبل البوابة ومش مسموح بيرجع "مستني موافقة" (حماية)، وتقارير المبيعات اليومية/الأسبوعية
    require_once __DIR__ . '/../app/Services/AiCeo/SalesEngine.php';
    $sales = new SalesEngine($db);
    $tbi['unapproved'] = $sales->reevaluateApproved();
    if ($tbi['unapproved']) $store->log(null, 'sales_reevaluated', ['unapproved' => $tbi['unapproved']]);
    $tbi['sales_reports'] = implode('+', $sales->writeReports($store));
    // المرحلة 5: فحص موقع Tourfecto مرة كل أسبوع (ببلاش)
    require_once __DIR__ . '/../app/Services/AiCeo/BrandSiteAudit.php';
    $ba = new BrandSiteAudit($db);
    $last = $ba->latest();
    if (!$last || strtotime($last['run_at'] . ' UTC') < time() - 7 * 86400) {
        $r = $ba->run();
        $store->log(null, 'brand_audit', ['score' => $r['summary']['score'], 'pages' => $r['summary']['pages']]);
    }
} catch (Throwable $e) {
    echo gmdate('c') . ' tourism db: ' . $e->getMessage() . PHP_EOL;
}
// المرحلة 7: فحص صفحات المنصة والحوادث وقائمة التطوير (كل دورة، من غير ذكاء اصطناعي)
try {
    require_once __DIR__ . '/../app/Services/AiCeo/OpsAdvisor.php';
    $ops = (new OpsAdvisor($db))->runCycle();
    if ($ops['failed'] || $ops['opened'] || $ops['resolved']) $store->log(null, 'ops_cycle', $ops);
} catch (Throwable $e) {
    echo gmdate('c') . ' ops: ' . $e->getMessage() . PHP_EOL;
}

$snap = (new PlatformSnapshot($db))->collect();
$db->query("INSERT INTO system_settings (setting_key, setting_value, is_secret, category) VALUES ('aiceo_snapshot', ?, 0, 'ai_ceo')
    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [json_encode($snap, JSON_UNESCAPED_UNICODE)]);
$decisions = $store->sync($snap);
$auto = $store->autoExecute();
$open = count(array_filter($decisions, static fn ($d) => in_array($d['status'], ['proposed', 'approved'], true)));
$missing = implode(',', array_keys(array_filter(array_diff_key($snap, ['generated_at' => 1]), static fn ($v) => $v === null)));
$store->log(null, 'cycle', ['open' => $open, 'auto_approved' => $auto, 'missing_sections' => $missing]);
echo gmdate('c') . " ai ceo: {$open} open decisions, {$auto} auto-approved, {$tbi['reports']} company reports saved" . ($missing ? ", unavailable: {$missing}" : '') . PHP_EOL;

<?php

/**
 * Tourfecto - دورة العقل الاستراتيجي
 *
 * لكل شركة فاتحة التشغيل الذاتي، أو عندها قرارات مستنية قياس: بيبني صورتها، ويحدّث قراراتها،
 * ويقيس نتيجة اللي اتنفّذ، ويحوّل الجديد اللي مافيهوش صرف لمهام وتنبيهات (لو التشغيل الذاتي مفتوح).
 * من غير السكربت ده العقل بيشتغل بس لما صاحب الشركة يفتح صفحة /brain.
 * مافيش أي طلب ذكاء اصطناعي ولا صرف.
 *
 * Cron (كل 6 ساعات):
 *   php /www/wwwroot/SITE/cron/brain_cycle.php >> /www/wwwroot/SITE/storage/logs/brain_cycle.log 2>&1
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/Services/Brain/CompanyBrainService.php';
require_once __DIR__ . '/../app/Services/Brain/BrainDecisionStore.php';
require_once __DIR__ . '/../app/Services/Brain/SharedIntelligence.php';

$db = Database::getInstance();
require_once __DIR__ . '/../app/Services/Brain/BrainConfig.php';
if (!BrainConfig::get('brain_enabled', $db)) { echo gmdate('c') . ' brain disabled by admin' . PHP_EOL; exit(0); }
$store = new BrainDecisionStore($db);
$brain = new CompanyBrainService($db);
$sharing = new SharedIntelligence($db);
$patterns = $sharing->patterns();

$users = array_map('intval', array_column($db->query("SELECT user_id FROM brain_settings WHERE autonomy = 1
    UNION SELECT DISTINCT user_id FROM brain_decisions WHERE status = 'done' AND outcome IS NULL") ?: [], 'user_id'));
$ok = 0; $failed = 0;
foreach ($users as $uid) {
    $shared = $sharing->optedIn($uid) ? $patterns : [];
    foreach ($brain->websites($uid) as $site) {
        try {
            $snapshot = $brain->snapshot($uid, (int) $site['id'], false);
            $store->sync($uid, (int) $site['id'], $snapshot, $shared);
            $ok++;
        } catch (Throwable $e) {
            $failed++;
            echo gmdate('c') . " user {$uid} site {$site['id']}: " . $e->getMessage() . PHP_EOL;
        }
    }
}
echo gmdate('c') . ' brain cycle: ' . count($users) . ' companies, ' . $ok . ' sites updated, ' . $failed . ' failed' . PHP_EOL;

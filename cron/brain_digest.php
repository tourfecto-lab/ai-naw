<?php

/**
 * Tourfecto - الملخص الأسبوعي للعقل الاستراتيجي
 *
 * بيبعت إيميل بخطة الأسبوع والأهداف ونتايج القرارات للشركات اللي فعّلت الملخص (مقفول افتراضيًا)،
 * مرة كل 6 أيام على الأكثر لكل شركة. مابيبعتش ملخص فاضي. مفيش أي طلب ذكاء اصطناعي ولا صرف.
 *
 * Cron (مرة في اليوم الصبح؛ السكربت نفسه بيمنع التكرار):
 *   php /www/wwwroot/SITE/cron/brain_digest.php >> /www/wwwroot/SITE/storage/logs/brain_digest.log 2>&1
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/Services/Brain/CompanyBrainService.php';
require_once __DIR__ . '/../app/Services/Brain/BrainDecisionStore.php';
require_once __DIR__ . '/../app/Services/Brain/SharedIntelligence.php';
require_once __DIR__ . '/../app/Services/Brain/BrainConfig.php';
require_once __DIR__ . '/../app/Services/Brain/BrainDigest.php';

$db = Database::getInstance();
if (!BrainConfig::get('brain_enabled', $db)) { echo gmdate('c') . ' brain disabled by admin' . PHP_EOL; exit(0); }
$store = new BrainDecisionStore($db);
$control = $store->control();
if (!$control->available()) { echo gmdate('c') . ' brain extras tables unavailable' . PHP_EOL; exit(0); }
$brain = new CompanyBrainService($db);
$sharing = new SharedIntelligence($db);
$patterns = $sharing->patterns();
$mailer = new Mailer();
if (!$mailer->isConfigured()) { echo gmdate('c') . ' mailer not configured' . PHP_EOL; exit(0); }

$rows = $db->query("SELECT p.user_id, u.email, u.company_name, u.language FROM brain_prefs p JOIN users u ON u.id = p.user_id
    WHERE p.digest_enabled = 1 AND u.email <> '' AND (p.digest_sent_at IS NULL OR p.digest_sent_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 6 DAY))") ?: [];
$sent = 0; $skipped = 0; $failed = 0;
foreach ($rows as $r) {
    $uid = (int) $r['user_id'];
    try {
        $site = ($brain->websites($uid)[0] ?? null);
        if (!$site) { $skipped++; continue; }
        $wid = (int) $site['id'];
        $snapshot = $brain->snapshot($uid, $wid, false);
        $decisions = $store->sync($uid, $wid, $snapshot, $sharing->optedIn($uid) ? $patterns : []);
        $goals = $control->goals($uid, $wid, $snapshot);
        $plan = BrainPlanner::weeklyPlan($decisions, BrainPlanner::goalAreas($goals), $control->excludedKeys($uid, $wid), $control->prefs($uid)['weekly_capacity']);
        $lang = strtolower((string) ($r['language'] ?? 'ar')) === 'en' ? 'en' : 'ar';
        $mail = BrainDigest::build((string) ($snapshot['website']['name'] ?? $r['company_name']), $plan, $goals, $store->results($uid, $wid), $decisions, $lang, rtrim((string) APP_URL, '/') . '/brain');
        if (!$mail) { $skipped++; continue; }
        $res = $mailer->send((string) $r['email'], (string) $r['company_name'], $mail['subject'], $mail['html']);
        if ($res['success'] ?? false) { $control->markDigestSent($uid); $control->log($uid, $wid, null, 'digest_sent'); $sent++; }
        else { $failed++; echo gmdate('c') . " user {$uid}: mail failed" . PHP_EOL; }
    } catch (Throwable $e) {
        $failed++;
        echo gmdate('c') . " user {$uid}: " . $e->getMessage() . PHP_EOL;
    }
}
echo gmdate('c') . ' brain digest: ' . count($rows) . ' due, ' . $sent . ' sent, ' . $skipped . ' skipped, ' . $failed . ' failed' . PHP_EOL;

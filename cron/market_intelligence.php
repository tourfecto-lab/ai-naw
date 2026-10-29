<?php

/**
 * Tourfecto - تحديث ذكاء السوق (اختياري)
 *
 * صفحة العقل بتحدّث مصدر واحد قديم في كل زيارة. السكربت ده بيحدّث كل المصادر لكل الوجهات والأسواق
 * اللي الشركات كاتباها، عشان البيانات تبقى جاهزة من غير ما الصفحة تستنى. المصادر مجانية ومن غير مفاتيح.
 *
 * Cron (مرة كل 6 ساعات كفاية):
 *   php /www/wwwroot/SITE/cron/market_intelligence.php >> /www/wwwroot/SITE/storage/logs/market_intelligence.log 2>&1
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../app/Services/Brain/MarketIntelligenceService.php';

$db = Database::getInstance();
$svc = new MarketIntelligenceService($db);
$scopes = [];
foreach ($db->query('SELECT facts_json FROM business_hub_profiles ORDER BY updated_at DESC LIMIT 500') ?: [] as $row) {
    $facts = json_decode((string) $row['facts_json'], true) ?: [];
    $p = MarketIntelligenceService::detect((string) ($facts['business_facts'] ?? ''), (string) ($facts['audience'] ?? ''));
    $scopes[$p['destination'] . '|' . implode(',', $p['markets'])] = $p;
}
if (!$scopes) $scopes['EG'] = MarketIntelligenceService::detect('', '');

foreach ($scopes as $p) {
    foreach (array_keys(MarketIntelligenceService::TTL) as $src) {
        // refreshOne بيتخطى المصادر اللي لسه صالحة أو اللي مالهاش بيانات للوجهة دي
        if ($svc->refreshOne($p['destination'], $p['markets']) === null) break;
    }
}
$errors = $db->query("SELECT source, error FROM market_fetch_log WHERE status = 'error'") ?: [];
echo gmdate('c') . ' market intelligence: ' . count($scopes) . ' scopes, ' . count($errors) . ' source errors' . PHP_EOL;
foreach ($errors as $e) echo '  ' . $e['source'] . ': ' . $e['error'] . PHP_EOL;

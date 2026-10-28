<?php
/**
 * Tourfecto - اختبارات ترقية خدمة الإعلانات (Ads Pro) - من غير شبكة ولا قاعدة بيانات.
 * التشغيل: php tests/ads_pro_test.php
 */

class Logger { public static $log = []; public static function error($m, $c = []) { self::$log[] = $m; } public static function warning($m, $c = []) {} }
if (!function_exists('env')) {
    function env($k, $d = null) { $v = getenv($k); return $v === false ? $d : $v; }
}

$root = dirname(__DIR__);
require_once $root . '/app/Services/Payment/CurrencyService.php';
require_once $root . '/app/Services/Ads/AdGeo.php';
require_once $root . '/app/Services/Ads/AdBudgetCurrency.php';
require_once $root . '/app/Services/Ads/MetaAdsAPI.php';
require_once $root . '/app/Services/Ads/GoogleAdsAPI.php';
require_once $root . '/app/Services/Ads/AdCopyGenerationService.php';
require_once $root . '/app/Services/Ads/AdSearchTermsService.php';

$pass = 0;
$fail = 0;
function check(string $name, bool $cond, string $extra = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; } else { $fail++; echo "FAIL  {$name} {$extra}\n"; }
}

// ============ 1) AdGeo ============
check('أسماء عربي/إنجليزي/أكواد => ISO', AdGeo::toIso2(['مصر', 'Germany', 'uk', 'FR', 'المانيا']) === ['EG', 'DE', 'GB', 'FR']);
check('همزات وتاء مربوطة: "السعودية" و"الإمارات"', AdGeo::toIso2(['السعودية', 'الإمارات']) === ['SA', 'AE']);
check('منطقة "الخليج" => 6 دول', AdGeo::toIso2(['الخليج']) === ['SA', 'AE', 'KW', 'QA', 'BH', 'OM']);
check('مفيش تطابق => الافتراضي', AdGeo::toIso2(['Atlantis'], ['EG']) === ['EG']);
check('Google geo: مصر 2818 / أمريكا 2840 / ألمانيا 2276', AdGeo::googleGeoTargetId('EG') === 2818 && AdGeo::googleGeoTargetId('us') === 2840 && AdGeo::googleGeoTargetId('DE') === 2276);
check('Google language: ar=1019 en=1000', AdGeo::googleLanguageId('ar') === 1019 && AdGeo::googleLanguageId('en-US') === 1000);
check('كشف اللغة: عربي', AdGeo::detectLanguage('رحلة الأقصر يوم كامل مع مرشد') === 'ar');
check('كشف اللغة: إنجليزي', AdGeo::detectLanguage('Luxor full day tour with private guide') === 'en');
check('كشف اللغة: ألماني', AdGeo::detectLanguage('Jetzt buchen: Tagesausflug nach Luxor mit der Familie und für Gruppen') === 'de');
check('كشف اللغة: روسي', AdGeo::detectLanguage('Экскурсия в Луксор на весь день') === 'ru');

// ============ 2) العملة ============
check('10 USD => 500 EGP (سعر احتياطي 50)', AdBudgetCurrency::convert(10, 'USD', 'EGP') === 500.0, (string) AdBudgetCurrency::convert(10, 'USD', 'EGP'));
check('نفس العملة من غير تحويل', AdBudgetCurrency::convert(12.5, 'EGP', 'egp') === 12.5);
check('عملة مش معروفة => null', AdBudgetCurrency::convert(10, 'USD', 'XYZ') === null);
check('500 EGP => 10 USD', AdBudgetCurrency::convert(500, 'EGP', 'USD') === 10.0);
check('أقل ميزانية بالجنيه ~ 50', AdBudgetCurrency::minimumDaily('EGP') === 50.0);

// ============ 3) تحويلات Meta من غير عدّ مزدوج ============
$actions = [
    ['action_type' => 'omni_purchase', 'value' => '2'], ['action_type' => 'purchase', 'value' => '2'],
    ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '2'],
    ['action_type' => 'lead', 'value' => '3'], ['action_type' => 'onsite_conversion.lead_grouped', 'value' => '3'],
    ['action_type' => 'link_click', 'value' => '40'],
];
check('omni+purchase+pixel = 2 مش 6، و lead مرة واحدة => 5', MetaAdsAPI::countConversions($actions) === 5.0, (string) MetaAdsAPI::countConversions($actions));
check('عملات من غير كسور: JPY ×1 / EGP ×100', MetaAdsAPI::budgetMultiplier('JPY') === 1 && MetaAdsAPI::budgetMultiplier('egp') === 100);

// ============ 4) Meta createCampaign ============
$calls = [];
$failAdsets = 0;
MetaAdsAPI::$testTransport = function ($method, $path, $fields) use (&$calls, &$failAdsets) {
    $calls[] = [$method, $path, $fields];
    if ($method === 'GET' && $path === 'search') {
        return [200, ['data' => [['id' => '600' . strlen($fields['q']), 'name' => ucfirst($fields['q'])]]]];
    }
    if (str_ends_with($path, '/campaigns')) return [200, ['id' => 'C' . count($calls)]];
    if (str_ends_with($path, '/adsets')) {
        if ($failAdsets > 0) { $failAdsets--; return [400, ['error' => ['message' => 'WhatsApp number not connected to page', 'code' => 100]]]; }
        return [200, ['id' => 'AS1']];
    }
    if (str_ends_with($path, '/adcreatives')) return [200, ['id' => 'CR' . count($calls)]];
    if (str_ends_with($path, '/ads')) return [200, ['id' => 'AD' . count($calls)]];
    return [200, ['success' => true]];
};
$meta = new MetaAdsAPI('tok');
$copies = [
    ['headline' => 'Luxor Day Tour', 'description' => 'Private guide', 'primary_text' => 'See Karnak and the Valley of the Kings.', 'call_to_action' => 'Book Now', 'variant_label' => 'A'],
    ['headline' => 'Rated 4.9 by guests', 'description' => 'Hotel pickup', 'primary_text' => 'Guests love our Egyptologists.', 'call_to_action' => 'Learn More', 'variant_label' => 'B'],
];
$r = $meta->createCampaign('act_1', 'PAGE1', ['name' => 'Luxor', 'objective' => 'leads', 'daily_budget' => 500], ['age_min' => 25, 'age_max' => 65, 'locations' => ['Germany', 'المملكة المتحدة'], 'interests' => ['travel', 'egypt']], $copies, 'https://ex.com/luxor', null, ['currency' => 'EGP', 'pixel_id' => 'PX9']);
$byPath = function ($suffix) use (&$calls) { return array_values(array_filter($calls, fn ($c) => $c[0] === 'POST' && str_ends_with($c[1], $suffix))); };
$camp = $byPath('/campaigns')[0][2] ?? [];
$adset = $byPath('/adsets')[0][2] ?? [];
$targeting = json_decode($adset['targeting'] ?? '{}', true);
check('Leads + Pixel => OUTCOME_LEADS', ($camp['objective'] ?? '') === 'OUTCOME_LEADS' && $r['success'] && $r['plan'] === 'leads_pixel', json_encode($r));
check('Ad Set: OFFSITE_CONVERSIONS + promoted_object(pixel LEAD)', $adset['optimization_goal'] === 'OFFSITE_CONVERSIONS' && json_decode($adset['promoted_object'], true) === ['pixel_id' => 'PX9', 'custom_event_type' => 'LEAD']);
check('ميزانية 500 جنيه => 50000 قرش', (int) $adset['daily_budget'] === 50000);
check('الدول اتحولت لأكواد DE, GB', ($targeting['geo_locations']['countries'] ?? []) === ['DE', 'GB']);
check('الاهتمامات اتحلت لمعرّفات Meta حقيقية', count($targeting['flexible_spec'][0]['interests'] ?? []) === 2);
check('Advantage+ Audience مفعّل (25-65)', ($targeting['targeting_automation']['advantage_audience'] ?? null) === 1);
check('الحملة PAUSED والمجموعة/الإعلانات ACTIVE', $camp['status'] === 'PAUSED' && $adset['status'] === 'ACTIVE' && ($byPath('/ads')[0][2]['status'] ?? '') === 'ACTIVE');
$creative = $byPath('/adcreatives')[0][2] ?? [];
check('UTM تلقائي على الإعلان', str_contains($creative['url_tags'] ?? '', 'utm_source=facebook'));
check('نسختين => إعلانين', count($r['ad_ids']) === 2);

// Leads من غير بيكسل => زيارات صفحة الهبوط + تحذير واضح
$calls = [];
$r = $meta->createCampaign('act_1', 'PAGE1', ['name' => 'Luxor', 'objective' => 'leads', 'daily_budget' => 10], ['locations' => ['EG']], $copies, 'https://ex.com', null, ['currency' => 'USD', 'interests' => []]);
$adset = $byPath('/adsets')[0][2] ?? [];
check('Leads من غير بيكسل => LANDING_PAGE_VIEWS + تحذير', $adset['optimization_goal'] === 'LANDING_PAGE_VIEWS' && str_contains((string) $r['warning'], 'Pixel'), json_encode($r));

// واتساب مرفوض (الرقم مش مربوط) => رجوع آمن + مسح الحملات الفاشلة
$calls = [];
$failAdsets = 2;
$r = $meta->createCampaign('act_1', 'PAGE1', ['name' => 'WA', 'objective' => 'whatsapp', 'daily_budget' => 10], ['locations' => ['EG']], $copies, 'https://ex.com', null, ['currency' => 'USD', 'interests' => []]);
$deleted = array_filter($calls, fn ($c) => $c[0] === 'POST' && ($c[2]['status'] ?? '') === 'DELETED');
check('واتساب فشل مرتين => رجع لزيارات + اتمسحت حملتين', $r['success'] && $r['plan'] === 'traffic_lpv' && count($deleted) === 2, json_encode($r));
$waCalls = [];
$calls = [];
$r = $meta->createCampaign('act_1', 'PAGE1', ['name' => 'WA', 'objective' => 'whatsapp', 'daily_budget' => 10], ['locations' => ['EG']], $copies, 'https://ex.com', null, ['currency' => 'USD', 'interests' => []]);
$cr = json_decode($byPath('/adcreatives')[0][2]['object_story_spec'] ?? '{}', true);
check('واتساب ناجح => CTA WHATSAPP_MESSAGE + destination WHATSAPP', ($cr['link_data']['call_to_action']['type'] ?? '') === 'WHATSAPP_MESSAGE' && ($byPath('/adsets')[0][2]['destination_type'] ?? '') === 'WHATSAPP');
MetaAdsAPI::$testTransport = null;

// ============ 5) Google createSearchCampaign ============
class FakeGoogleAds extends GoogleAdsAPI
{
    public array $calls = [];
    public array $failOn = [];
    public function __construct() { parent::__construct('tok'); }
    protected function request(string $method, string $path, ?array $body, ?string $loginId = null): array
    {
        $this->calls[] = [$path, $body];
        foreach ($this->failOn as $needle) {
            if (str_contains($path, $needle)) return ['success' => false, 'error' => 'boom ' . $needle];
        }
        if (str_ends_with($path, ':mutate')) {
            $res = [];
            preg_match('#customers/(\d+)/(\w+):mutate#', $path, $m);
            foreach ($body['operations'] as $i => $op) {
                $res[] = isset($op['create']) ? ['resourceName' => "customers/{$m[1]}/{$m[2]}/" . (100 + $i + count($this->calls))] : ['resourceName' => 'x'];
            }
            return ['success' => true, 'data' => ['results' => $res]];
        }
        return ['success' => true, 'data' => ['results' => []]];
    }
    public function ops(string $resource): array
    {
        $out = [];
        foreach ($this->calls as [$path, $body]) {
            if (str_contains($path, '/' . $resource . ':mutate')) foreach ($body['operations'] as $op) $out[] = $op;
        }
        return $out;
    }
}
$copiesG = [
    ['headline' => 'Luxor Private Day Tour From Hurghada', 'description' => 'Hotel pickup included', 'primary_text' => 'Explore Karnak with an Egyptologist. Book online in two minutes.'],
];
$assets = [
    'headlines' => ['Luxor Day Tour', 'Private Egyptologist Guide', 'Hotel Pickup Included', 'Valley of the Kings Trip', 'Book Your Luxor Tour Today'],
    'descriptions' => ['Explore Karnak and the Valley of the Kings with a private guide. Book now.', 'Comfortable A/C transport and hotel pickup from Hurghada. Reserve today.'],
    'paths' => ['luxor', 'day tour'],
    'sitelinks' => [['text' => 'Nile Cruises', 'description1' => 'Luxor to Aswan', 'description2' => '3 and 4 nights', 'url' => 'https://ex.com/cruises'], ['text' => 'Cairo Tours', 'url' => 'https://ex.com/cairo']],
    'callouts' => ['Free Hotel Pickup', 'Private Guide'],
    'snippet' => ['header' => 'Destinations', 'values' => ['Luxor', 'Aswan', 'Cairo']],
];
$g = new FakeGoogleAds();
$kw = [['keyword' => 'luxor day tour', 'match_type' => 'phrase'], ['keyword' => 'luxor tour price!', 'match_type' => 'exact'], ['keyword' => 'luxor jobs', 'match_type' => 'negative']];
$r = $g->createSearchCampaign('123-456-7890', ['name' => 'Luxor', 'daily_budget' => 300], $copiesG, $kw, 'https://ex.com/luxor', '', [
    'countries' => ['DE', 'GB'], 'languages' => ['en'], 'search_assets' => $assets, 'phone' => '+201000000000', 'phone_country' => 'EG',
]);
$campaignOp = $g->ops('campaigns')[0]['create'] ?? [];
check('Google: نجاح النشر', $r['success'] === true, json_encode($r, JSON_UNESCAPED_UNICODE));
check('Maximize Clicks = targetSpend (مش maximizeClicks)', isset($campaignOp['targetSpend']) && !isset($campaignOp['maximizeClicks']));
check('containsEuPoliticalAdvertising موجود', ($campaignOp['containsEuPoliticalAdvertising'] ?? '') === 'DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING');
check('استهداف PRESENCE', ($campaignOp['geoTargetTypeSetting']['positiveGeoTargetType'] ?? '') === 'PRESENCE');
$crit = $g->ops('campaignCriteria');
$geo = array_values(array_filter(array_map(fn ($o) => $o['create']['location']['geoTargetConstant'] ?? null, $crit)));
$lang = array_values(array_filter(array_map(fn ($o) => $o['create']['language']['languageConstant'] ?? null, $crit)));
$negs = array_values(array_filter(array_map(fn ($o) => !empty($o['create']['negative']) ? $o['create']['keyword']['text'] : null, $crit)));
check('استهداف ألمانيا وبريطانيا فعليًا', $geo === ['geoTargetConstants/2276', 'geoTargetConstants/2826'], json_encode($geo));
check('استهداف لغة الإعلان (إنجليزي)', $lang === ['languageConstants/1000']);
check('كلمات سلبية: بتاعة العميل + الافتراضية', in_array('luxor jobs', $negs, true) && in_array('salary', $negs, true) && !in_array('free', $negs, true));
$rsa = $g->ops('adGroupAds')[0]['create']['ad']['responsiveSearchAd'] ?? [];
$hl = array_column($rsa['headlines'] ?? [], 'text');
check('RSA: كل العناوين ≤30 ومفيش "…" ولا تكرار', $hl && max(array_map('mb_strlen', $hl)) <= 30 && !array_filter($hl, fn ($h) => str_contains($h, '…')) && count($hl) === count(array_unique(array_map('mb_strtolower', $hl))), json_encode($hl));
check('RSA: العنوان الطويل اتقص على كلمة كاملة من غير حرف جر معلّق', in_array('Luxor Private Day Tour', $hl, true) && !in_array('Luxor Private Day Tour From', $hl, true), json_encode($hl));
check('RSA: مسارات الرابط', ($rsa['path1'] ?? '') === 'luxor' && ($rsa['path2'] ?? '') === 'day-tour');
check('الإعلان ENABLED جوه حملة PAUSED', ($g->ops('adGroupAds')[0]['create']['status'] ?? '') === 'ENABLED' && $campaignOp['status'] === 'PAUSED');
$kwOps = array_map(fn ($o) => $o['create']['keyword']['text'], $g->ops('adGroupCriteria'));
check('الكلمات اتنضفت من الرموز ("price!" => "price")', in_array('luxor tour price', $kwOps, true) && !in_array('luxor jobs', $kwOps, true));
$assetTypes = array_map(fn ($o) => $o['create']['fieldType'], $g->ops('campaignAssets'));
check('الإضافات: Sitelinks + Callouts + Snippet + Call', count(array_keys($assetTypes, 'SITELINK')) === 2 && in_array('CALLOUT', $assetTypes, true) && in_array('STRUCTURED_SNIPPET', $assetTypes, true) && in_array('CALL', $assetTypes, true), json_encode($assetTypes));
check('ميزانية 300 => 300,000,000 micros', ($g->ops('campaignBudgets')[0]['create']['amountMicros'] ?? '') === '300000000');

// فشل الاستهداف => الحملة والميزانية بيتمسحوا (مفيش حملة بتصرف على العالم كله)
$g = new FakeGoogleAds();
$g->failOn = ['campaignCriteria'];
$r = $g->createSearchCampaign('1', ['name' => 'X', 'daily_budget' => 10], $copiesG, $kw, 'https://ex.com', '', ['countries' => ['EG'], 'languages' => ['ar'], 'search_assets' => $assets]);
$removes = array_filter(array_merge($g->ops('campaigns'), $g->ops('campaignBudgets')), fn ($o) => isset($o['remove']));
check('فشل الاستهداف => مسح الحملة والميزانية + خطأ', !$r['success'] && count($removes) === 2 && empty($r['external_campaign_id']), json_encode($r, JSON_UNESCAPED_UNICODE));

$g = new FakeGoogleAds();
$r = $g->createSearchCampaign('1', ['name' => 'X'], $copiesG, $kw, 'https://ex.com', '', ['search_assets' => $assets]);
check('من غير دول => رفض قبل أي طلب للـAPI', !$r['success'] && count($g->calls) === 0);

$g = new FakeGoogleAds();
$r = $g->createSearchCampaign('1', ['name' => 'X'], $copiesG, $kw, 'https://ex.com', '', ['countries' => ['EG'], 'bidding' => 'conversions', 'search_assets' => $assets]);
check('bidding=conversions => maximizeConversions', isset($g->ops('campaigns')[0]['create']['maximizeConversions']));

// ============ 6) أدوات النصوص ============
check('fitText: يقص على حدود كلمة ويشيل حرف الجر المعلّق', GoogleAdsAPI::fitText('Luxor Private Day Tour From Hurghada', 30) === 'Luxor Private Day Tour');
check('fitText: عربي', GoogleAdsAPI::fitText('رحلة الأقصر يوم كامل مع مرشد خاص من الغردقة', 30) === 'رحلة الأقصر يوم كامل مع مرشد', (string) GoogleAdsAPI::fitText('رحلة الأقصر يوم كامل مع مرشد خاص من الغردقة', 30));
check('fitText: لو القص هيبوّظ المعنى => null', GoogleAdsAPI::fitText('Supercalifragilisticexpialidocious tour', 30) === null);
check('sanitizeKeyword', GoogleAdsAPI::sanitizeKeyword('  "luxor" tour, price!!  ') === 'luxor tour price');
$rsaOnly = GoogleAdsAPI::buildRsaAssets([['headline' => 'Same', 'primary_text' => 'First sentence here. Second sentence here!']], ['headlines' => ['Same', 'same', 'Other']]);
check('buildRsaAssets: من غير تكرار + جمل النص الأساسي كأوصاف', $rsaOnly['headlines'] === ['Same', 'Other'] && $rsaOnly['descriptions'] === ['First sentence here.', 'Second sentence here!'], json_encode($rsaOnly));

$clean = AdCopyGenerationService::sanitizeSearchAssets([
    'headlines' => ['Luxor Tour!', 'Luxor Tour', str_repeat('x', 40)],
    'sitelinks' => [['text' => 'Cruises', 'url' => 'https://ex.com/cruises'], ['text' => 'Fake', 'url' => 'https://evil.com']],
    'snippet' => ['header' => 'Random', 'values' => ['a', 'b', 'c']],
], ['https://ex.com/cruises']);
check('sanitizeSearchAssets: شيل "!" والتكرار والطويل', $clean['headlines'] === ['Luxor Tour'], json_encode($clean['headlines']));
check('sanitizeSearchAssets: Sitelinks بروابط الموقع بس', count($clean['sitelinks']) === 1 && $clean['sitelinks'][0]['url'] === 'https://ex.com/cruises');
check('sanitizeSearchAssets: عنوان Snippet غير مسموح => فاضي', $clean['snippet']['header'] === '');

// ============ 7) الكلمات المهدرة ============
$terms = [
    ['term' => 'luxor tour', 'status' => 'NONE', 'clicks' => 30, 'impressions' => 900, 'cost' => 30.0, 'conversions' => 3],
    ['term' => 'luxor temple history', 'status' => 'NONE', 'clicks' => 5, 'impressions' => 200, 'cost' => 6.0, 'conversions' => 0],
    ['term' => 'luxor jobs', 'status' => 'NONE', 'clicks' => 9, 'impressions' => 100, 'cost' => 3.0, 'conversions' => 0],
    ['term' => 'cheap luxor', 'status' => 'NONE', 'clicks' => 2, 'impressions' => 50, 'cost' => 1.0, 'conversions' => 0],
    ['term' => 'old negative', 'status' => 'EXCLUDED', 'clicks' => 20, 'impressions' => 50, 'cost' => 20.0, 'conversions' => 0],
];
$w = AdSearchTermsService::findWasteful($terms);
check('المهدر: غالي من غير حجز + ضغطات كتير، والمستبعد قبل كده متجاهل', array_keys($w) === ['luxor temple history', 'luxor jobs'], json_encode(array_keys($w)));

// ============ 8) خطط أهداف Meta ============
$plans = MetaAdsAPI::objectivePlans('calls', ['phone' => '+20100']);
check('مكالمات + رقم => QUALITY_CALL ثم بدائل آمنة', $plans[0]['optimization'] === 'QUALITY_CALL' && end($plans)['optimization'] === 'LINK_CLICKS');
$plans = MetaAdsAPI::objectivePlans('sales', []);
check('مبيعات من غير بيكسل => أول خطة زيارات + تحذير', $plans[0]['label'] === 'traffic_lpv' && $plans[0]['warning'] !== null);

// ============ 9) توليد الحملة + المراجع الآلي (AI وهمي) ============
class ContentLanguageService { public static function forUser($u, $w = null) { return 'en'; } }
class GeminiClient
{
    public array $responses = [];
    public array $prompts = [];
    public function generateContent($prompt, $opts = []) { $this->prompts[] = $prompt; return array_shift($this->responses) ?? ['success' => false, 'error' => 'none']; }
}
require_once $root . '/app/Services/Ads/AdContextBuilder.php';
class FakeContext extends AdContextBuilder
{
    public function __construct() {}
    public function build(int $userId, ?int $websiteId, ?AdCampaign $campaign = null, ?string $landingUrl = null): array
    {
        return ['text' => "## تقييمات عملاء حقيقية\n- (5★ tripadvisor) Best guide in Luxor, Ahmed was amazing", 'facts' => []];
    }
}
$brief = ['campaign_name' => 'Luxor', 'target_audience' => ['age_min' => 25, 'age_max' => 70, 'locations' => ['Germany'], 'interests' => ['Travel']],
    'budget_recommendation' => ['recommended_daily_budget' => 20],
    'copies' => [
        ['angle' => 'value', 'headline' => 'Luxor tour', 'description' => 'd', 'primary_text' => 'weak text', 'call_to_action' => 'Book Now'],
        ['angle' => 'proof', 'headline' => 'Rated 5 stars', 'description' => 'd', 'primary_text' => 'p', 'call_to_action' => 'Nope'],
    ],
    'keywords' => [['keyword' => 'luxor day tour', 'match_type' => 'phrase'], ['keyword' => 'luxor jobs', 'match_type' => 'negative'], ['keyword' => 'x', 'match_type' => 'weird']]];
$review = ['copies' => [
    ['angle' => 'value', 'headline' => 'Luxor In One Day', 'description' => 'Private guide', 'primary_text' => 'Stand inside Karnak before the crowds arrive.', 'call_to_action' => 'Book Now', 'quality_score' => 9],
    ['angle' => 'proof', 'headline' => 'Guests Love Ahmed', 'description' => 'Top rated guide', 'primary_text' => '"Best guide in Luxor" - real guest review.', 'call_to_action' => 'Learn More', 'quality_score' => 8],
]];
$ai = new GeminiClient();
$ai->responses = [['success' => true, 'data' => json_encode($brief)], ['success' => true, 'data' => json_encode($review)]];
$svc = new AdCopyGenerationService($ai, new FakeContext());
$out = $svc->generateCampaignBrief('Luxor day tour from Hurghada', 'leads', 15, 1, null);
check('البرومبت فيه التقييمات الحقيقية', str_contains($ai->prompts[0], 'Best guide in Luxor'));
check('المراجع الآلي اتنادى واتستخدمت نسخه المحسّنة', count($ai->prompts) === 2 && $out['copies'][0]['headline'] === 'Luxor In One Day' && $out['copies'][0]['quality_score'] === 9);
check('السن اتظبط على 65 كحد أقصى', $out['audience']['age_max'] === 65);
check('الكلمات المقترحة اترجعت ونوع المطابقة اتنضف', count($out['keywords']) === 3 && $out['keywords'][2]['match_type'] === 'phrase' && $out['keywords'][1]['match_type'] === 'negative');

$ai = new GeminiClient();
$ai->responses = [['success' => true, 'data' => json_encode($brief)], ['success' => false, 'error' => 'busy']];
$out = (new AdCopyGenerationService($ai, new FakeContext()))->generateCampaignBrief('x', 'traffic', null, 1, null);
check('المراجع فشل => النسخ الأصلية بترجع عادي', $out['copies'][0]['headline'] === 'Luxor tour' && $out['copies'][1]['call_to_action'] === 'Book Now');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);

<?php
/**
 * اختبارات نقية (من غير DB أو شبكة) لإضافات السوشيال الاحترافي:
 * UTM، تصنيف التعليقات، أفضل المواعيد، تحليل نسخ الكاتب الذكي، توصيات التقرير، عنوان يوتيوب، الجدولة الذكية.
 * التشغيل: php tools/test_social_pro.php
 */
declare(strict_types=1);
$root = dirname(__DIR__);
if (!class_exists('Database')) { class Database { public static function getInstance() { throw new RuntimeException('no db in tests'); } } }
if (!interface_exists('QueueJobInterface')) { interface QueueJobInterface { public function handle(array $payload): void; } }
require_once $root . '/app/Services/SocialMedia/MetaApiError.php';
require_once $root . '/app/Services/SocialMedia/PublishPolicy.php';
require_once $root . '/app/Services/SocialMedia/SocialLinkTracker.php';
require_once $root . '/app/Services/SocialMedia/SocialCommentInbox.php';
require_once $root . '/app/Services/SocialMedia/SocialBestTimes.php';
require_once $root . '/app/Services/SocialMedia/SocialReport.php';

$fail = 0;
function check(string $name, bool $ok): void { global $fail; echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; if (!$ok) $fail++; }

// ---------- UTM ----------
$hosts = ['example-tours.com'];
$m = SocialLinkTracker::apply("احجز الآن: https://www.example-tours.com/trips/hurghada?x=1#top.\nو https://other.com/a", 'facebook', 'Oct Offer', $hosts);
check('UTM tags own domain with source/medium/campaign', str_contains($m, 'https://www.example-tours.com/trips/hurghada?x=1&utm_source=facebook&utm_medium=social&utm_campaign=oct-offer#top.'));
check('UTM leaves external links untouched', str_contains($m, 'https://other.com/a') && !str_contains($m, 'other.com/a?utm'));
check('UTM keeps existing utm links', SocialLinkTracker::apply('https://example-tours.com/?utm_source=x', 'instagram', 'c', $hosts) === 'https://example-tours.com/?utm_source=x');
check('UTM no campaign = unchanged', SocialLinkTracker::apply('https://example-tours.com/a', 'facebook', '', $hosts) === 'https://example-tours.com/a');
check('UTM subdomain counts as own', str_contains(SocialLinkTracker::apply('https://book.example-tours.com/x', 'tiktok', 'c', $hosts), 'utm_source=tiktok'));
check('UTM deterministic', SocialLinkTracker::apply('https://example-tours.com', 'facebook', 'c', $hosts) === SocialLinkTracker::apply('https://example-tours.com', 'facebook', 'c', $hosts));
check('UTM slug arabic ok', SocialLinkTracker::slug(' عرض الصيف 2026! ') === 'عرض-الصيف-2026');

// ---------- تصنيف التعليقات ----------
$cases = [
    'بكام الرحلة دي؟' => 'price', 'How much for 2 adults?' => 'price', 'Great trip!' => 'praise', 'عايز احجز لشخصين يوم الجمعة' => 'booking',
    'تحفة ❤' => 'praise', 'نصابين ومحدش رد عليا' => 'complaint', 'امتى الرحلة الجاية؟' => 'question', 'check my profile for promo' => 'spam',
    'what time is pickup' => 'question', 'السعر كام يا جماعة' => 'price', 'ممكن رقم الواتس' => 'booking', 'ok' => 'other',
];
foreach ($cases as $text => $want) {
    check("classify «{$text}» = {$want}", SocialCommentInbox::classify($text) === $want);
}

// ---------- أفضل المواعيد ----------
$samples = [];
for ($i = 0; $i < 6; $i++) { $samples[] = ['at' => '2026-10-0' . ($i % 7 + 1) . ' 17:00:00', 'score' => 50]; } // 19:00 القاهرة (UTC+2 / +3 صيفي)
for ($i = 0; $i < 6; $i++) { $samples[] = ['at' => '2026-10-1' . ($i % 7) . ' 06:00:00', 'score' => 5]; }
$rank = SocialBestTimes::rank($samples, 'Africa/Cairo', 3);
check('best times ranks high-engagement evening first', $rank[0]['avg'] >= 50 && in_array($rank[0]['hour'], [19, 20], true));
$pref = SocialBestTimes::preferencesFrom($samples, 'Africa/Cairo');
check('preferences measured when enough data', $pref['source'] === 'measured' && count($pref['times']) === 7);
check('preferences benchmark when little data', SocialBestTimes::preferencesFrom(array_slice($samples, 0, 3), 'UTC')['source'] === 'benchmark');

// ---------- الجدولة الذكية في الخطة الشهرية ----------
require_once $root . '/app/Services/SocialMedia/SocialMonthlyPlanService.php';
$now = new DateTimeImmutable('2026-10-20 10:00:00', new DateTimeZone('UTC'));
$slots = SocialMonthlyPlanService::schedule('2026-11', 'Africa/Cairo', 'smart', 8, $now, [0 => '20:00', 1 => '12:00', 2 => '12:00', 3 => '12:00', 4 => '12:00', 5 => '10:00', 6 => '10:00']);
$hours = array_map(static fn ($s) => (new DateTimeImmutable($s, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Africa/Cairo'))->format('w H:i'), $slots);
$okDay = true;
foreach ($hours as $h) { [$dow, $hm] = explode(' ', $h); $want = [0 => '20:00', 5 => '10:00', 6 => '10:00'][(int) $dow] ?? '12:00'; if ($hm !== $want) $okDay = false; }
check('smart schedule uses per-weekday best time', count($slots) === 8 && $okDay);
check('fixed clock schedule unchanged', count(SocialMonthlyPlanService::schedule('2026-11', 'UTC', '18:00', 4, $now)) === 4);

// ---------- الكاتب الذكي ----------
if (!class_exists('SocialPostService')) {
    // تحميل الكلاس من غير تبعيات التشغيل
    foreach (['SocialPost', 'SocialPostTarget', 'GenerationLifecycle', 'PrivateMedia', 'Container', 'QueueManager', 'ActivityLog', 'PublishSocialPostJob', 'AiText', 'GeminiClient'] as $c) { if (!class_exists($c)) eval("class {$c} {}"); }
    require_once $root . '/app/Services/SocialMedia/SocialPostService.php';
}
$v = SocialPostService::parseCaptionVariants("```json\n{\"variants\":[{\"angle\":\"story\",\"content\":\"رحلة رائعة للغردقة 🌊\\n\\n#Hurghada #RedSea\",\"hashtags\":[\"Egypt\"],\"first_comment\":\"احجز من هنا\"},{\"content\":\"\"},{\"content\":\"Second\",\"hashtags\":[\"#Travel\",\"bad tag!\"]}]}\n```");
check('variants parsed and empties skipped', count($v) === 2);
check('trailing hashtags moved out of content', $v[0]['content'] === 'رحلة رائعة للغردقة 🌊' && in_array('Hurghada', $v[0]['hashtags'], true) && in_array('Egypt', $v[0]['hashtags'], true));
check('invalid hashtags dropped', $v[1]['hashtags'] === ['Travel']);
check('legacy single content shape accepted', count(SocialPostService::parseCaptionVariants('{"content":"x","hashtags":[]}')) === 1);

// ---------- يوتيوب ----------
if (!class_exists('PublishSocialPostJob', false) || !method_exists('PublishSocialPostJob', 'youTubeMeta')) {
    $src = file_get_contents($root . '/app/Jobs/PublishSocialPostJob.php');
    preg_match('/public static function youTubeMeta\(string \$message\): array\s*\{.*?\n    \}\n/s', $src, $mm);
    eval('final class YtMetaProbe { ' . $mm[0] . ' }');
    [$title, $tags] = YtMetaProbe::youTubeMeta("#Shorts\n" . str_repeat('غوص في البحر الأحمر <رائع> ', 10) . "\nتفاصيل\n#RedSea #Diving");
} else {
    [$title, $tags] = PublishSocialPostJob::youTubeMeta("#Shorts\n" . str_repeat('غوص في البحر الأحمر <رائع> ', 10) . "\nتفاصيل\n#RedSea #Diving");
}
check('youtube title ≤100, no <>, from first text line', mb_strlen($title) <= 100 && !str_contains($title, '<') && str_starts_with($title, 'غوص'));
check('youtube tags from hashtags', $tags === ['Shorts', 'RedSea', 'Diving']);

// ---------- توصيات التقرير ----------
$base = ['published' => 10, 'failed' => 2, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'reach' => 0, 'measured' => 6, 'engagement' => 100, 'success_rate' => 83, 'avg_engagement' => 16.7, 'by_platform' => []];
$prev = ['engagement' => 200] + $base;
$ins = SocialReport::insights(['current' => $base, 'previous' => $prev, 'formats' => ['video' => ['avg_score' => 40.0, 'measured' => 3], 'photo' => ['avg_score' => 10.0, 'measured' => 3]], 'best_times' => [['dow' => 5, 'hour' => 19, 'avg' => 40, 'n' => 4]], 'comments' => ['total' => 10, 'answerable' => 10, 'replied' => 3, 'leads' => 4]]);
$all = implode(' | ', array_column($ins, 1));
check('insight: engagement drop detected', str_contains($all, 'fell -50%'));
check('insight: best format with ratio', str_contains($all, 'video/reels (×4'));
check('insight: success rate warning', str_contains($all, 'success rate is 83%'));
check('insight: unanswered comments with leads', str_contains($all, 'Only 30%') && str_contains($all, '4 asked'));
check('insight: empty month', count(SocialReport::insights(['current' => ['published' => 0] + $base, 'previous' => $prev])) === 1);

// ---------- الرد الخاص: تعبئة القالب ----------
check('template fills name/link/company', SocialCommentInbox::fillTemplate("Hi {name}!\nBook: {link}\n{company}", 'Ahmed Ali', 'Red Sea', 'https://x.example') === "Hi Ahmed!\nBook: https://x.example\nRed Sea");
check('template drops empty link line & bad name', SocialCommentInbox::fillTemplate("Hi {name}, thanks!\nBook: {link}", '_.99', '', '') === 'Hi, thanks!');

// ---------- روابط تتبّع الحجوزات ----------
require_once $root . '/app/Services/SocialMedia/SocialAttribution.php';
$mapped = SocialAttribution::replaceOwnLinks('Book https://www.example-tours.com/a?x=1, or https://other.com/b.', ['example-tours.com'], fn ($u) => 'https://app/go/CODE');
check('own links replaced, punctuation kept, external untouched', $mapped === 'Book https://app/go/CODE, or https://other.com/b.');
check('short code format', SocialAttribution::validCode(SocialAttribution::newCode()) && !SocialAttribution::validCode('bad code!'));

// ---------- نمو المتابعين ----------
require_once $root . '/app/Services/SocialMedia/SocialGrowth.php';
$g = SocialGrowth::summarize([['connection_id' => 1, 'platform' => 'facebook', 'name' => 'P', 'snap_date' => '2026-10-01', 'followers' => 1000], ['connection_id' => 1, 'platform' => 'facebook', 'name' => 'P', 'snap_date' => '2026-10-20', 'followers' => 1100]]);
check('growth change & percent', $g[0]['change'] === 100 && $g[0]['change_pct'] === 10.0 && $g[0]['current'] === 1100);

// ---------- المنافسين ----------
require_once $root . '/app/Services/SocialMedia/SocialCompetitors.php';
$media = [];
for ($i = 0; $i < 4; $i++) { $media[] = ['caption' => 'Trip #RedSea #Dive', 'likes' => 100 * ($i + 1), 'comments' => 10, 'timestamp' => gmdate('c', strtotime('2026-10-20') - $i * 3 * 86400), 'type' => $i ? 'VIDEO' : 'IMAGE', 'permalink' => 'https://instagram.com/p/' . $i]; }
$a = SocialCompetitors::analyze($media);
check('competitor avg engagement', $a['avg_engagement'] === 260.0);
check('competitor posts per week ≈ 2.3', abs($a['posts_per_week'] - 2.3) < 0.01);
check('competitor mix + top post + hashtags', $a['mix']['reels'] === 3 && $a['top'][0]['engagement'] === 410 && $a['hashtags'] === ['redsea', 'dive']);
$cmp = SocialCompetitors::compare(['posts_per_week' => 1.0, 'avg_engagement' => 50.0], [['username' => 'rival', 'avg_engagement' => 260.0, 'posts_per_week' => 2.3, 'mix' => $a['mix'], 'hashtags' => $a['hashtags']]]);
check('competitor insights: rhythm, reels, engagement gap, hashtags', count($cmp) === 4);

// ---------- LinkedIn little text ----------
require_once $root . '/app/Services/SocialMedia/LinkedInAPI.php';
check('linkedin escapes reserved chars & links hashtags', LinkedInAPI::littleText('Hi (all) @team #RedSea 50% *now*') === 'Hi \\(all\\) \\@team {hashtag|\\#|RedSea} 50% \\*now\\*');

// ---------- صلاحيات الفريق ----------
require_once $root . '/app/Services/SocialMedia/SocialTeam.php';
check('team matrix: support drafts+inbox but cannot publish/manage', SocialTeam::can(['can' => SocialTeam::MATRIX['support']], 'inbox') && !SocialTeam::can(['can' => SocialTeam::MATRIX['support']], 'publish') && !SocialTeam::can(['can' => SocialTeam::MATRIX['viewer']], 'draft') && SocialTeam::can(['can' => SocialTeam::MATRIX['manager']], 'manage'));

// ---------- باقات السوشيال (الكتالوج الافتراضي متسق) ----------
require_once $root . '/app/Services/SocialMedia/SocialPackages.php';
$d = SocialPackages::DEFAULTS; $ordered = true;
foreach (SocialPackages::LIMIT_KEYS as $k) {
    $prev = null;
    foreach (['free', 'basic', 'pro', 'agency'] as $t) { $v = $d[$t][$k] < 0 ? PHP_INT_MAX : $d[$t][$k]; if ($prev !== null && $v < $prev) $ordered = false; $prev = $v; }
}
check('packages: every limit grows with the tier', $ordered);
check('packages: higher tiers keep lower-tier features & platforms', !array_diff($d['basic']['platforms'], $d['pro']['platforms']) && !array_filter(SocialPackages::FEATURE_KEYS, fn ($f) => $d['basic'][$f] && !$d['pro'][$f]));
check('packages: yearly cheaper than 12 months', $d['basic']['price_yearly'] < 12 * $d['basic']['price_monthly'] && $d['agency']['price_yearly'] < 12 * $d['agency']['price_monthly']);
check('packages: platform plans map to tiers', SocialPackages::PLAN_MAP === ['starter' => 'basic', 'professional' => 'pro', 'enterprise' => 'agency']);

// ---------- رحلات الموسم (ذكاء الإيرادات) ----------
require_once $root . '/app/Services/RevenueIntelligence/SeasonTripPosts.php';
check('season: booking link appended only when missing', SeasonTripPosts::ensureLink('Trip', 'https://x.test/t', 'ar') === "Trip\n\n🔗 احجز من هنا: https://x.test/t" && SeasonTripPosts::ensureLink('Go https://x.test/t', 'https://x.test/t', 'ar') === 'Go https://x.test/t');
check('season: hashtags cleaned, deduped, topped up without generic words', SeasonTripPosts::cleanTags(['#Egypt', 'egypt'], ['name' => 'Luxor Day Trip from Hurghada']) === ['Egypt', 'Luxor', 'Hurghada']);

echo $fail ? "\n{$fail} check(s) failed.\n" : "\nAll social-pro checks passed.\n";
exit($fail ? 1 : 0);

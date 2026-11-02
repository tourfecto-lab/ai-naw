<?php
/** @var array $overview @var string $lang @var string $siteName */
$ar = $lang !== 'en';
$h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$t = static fn (string $a, string $e) => $ar ? $a : $e;
$conf = ['high' => $t('ثقة عالية', 'High confidence'), 'medium' => $t('ثقة متوسطة', 'Medium confidence'), 'low' => $t('ثقة منخفضة', 'Low confidence')];
$axes = ['seo' => ['SEO', $t('صحة صفحات الموقع', 'Page health')], 'aeo' => ['AEO', $t('تغطية الإجابات المباشرة', 'Direct-answer coverage')], 'geo' => ['GEO', $t('ظهور في إجابات الذكاء الاصطناعي', 'Visibility in AI answers')]];
$imp = $overview['impact'] ?? ['state' => 'not_measured'];
$pct = static fn ($v) => $v === null ? '' : ' (' . ($v > 0 ? '+' : '') . $v . '%)';
?>
<!doctype html>
<html lang="<?= $ar ? 'ar' : 'en' ?>" dir="<?= $ar ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title><?= $h($t('تقرير الظهور', 'Visibility report')) ?> · <?= $h($siteName) ?></title>
<style>
:root{--bg:#fff;--fg:#14213d;--muted:#5b6478;--line:#d9deea;--card:#f6f8fc}@media (prefers-color-scheme:dark){:root{--bg:#0f1626;--fg:#eef2fa;--muted:#a9b4c8;--line:#29354b;--card:#172037}}
body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.7 system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif}main{max-width:860px;margin:0 auto;padding:20px 16px 48px}
h1{font-size:1.5rem;margin:.2rem 0}h2{font-size:1.1rem;margin:1.6rem 0 .6rem}.muted{color:var(--muted);font-size:.9rem}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px 16px}
.score{font-size:2.2rem;font-weight:700;line-height:1.1}.badge{display:inline-block;border:1px solid var(--line);border-radius:999px;padding:0 10px;font-size:.8rem;margin-inline-end:6px}
button{font:inherit;border:1px solid var(--line);background:var(--card);color:var(--fg);border-radius:10px;padding:8px 14px;cursor:pointer}button:focus-visible{outline:2px solid #3b82f6;outline-offset:2px}
@media print{button{display:none}body{background:#fff;color:#000}.card{break-inside:avoid;background:#fff}}
</style>
</head>
<body><main>
<header><h1><?= $h($t('تقرير الظهور في البحث والإجابات', 'Search and answer visibility report')) ?></h1>
<div class="muted"><?= $h($siteName) ?> · <?= $h($t('بتاريخ ', 'Generated ')) ?><?= $h(substr((string) $overview['generated_at'], 0, 10)) ?></div>
<p><button type="button" onclick="window.print()"><?= $h($t('حفظ كـPDF / طباعة', 'Save as PDF / Print')) ?></button></p></header>

<h2><?= $h($t('الدرجات', 'Scores')) ?></h2>
<div class="grid">
<?php foreach ($axes as $k => [$name, $desc]): $s = $overview[$k]; $m = isset($s['score']) && $s['score'] !== null; ?>
<section class="card" aria-label="<?= $h($name) ?>"><div class="muted"><?= $h($name) ?> · <?= $h($desc) ?></div>
<?php if ($m): ?><div class="score"><?= (int) $s['score'] ?><small>/100</small></div>
<div><span class="badge"><?= $h($conf[$s['confidence']] ?? '') ?></span><?php if (!empty($s['stale'])): ?><span class="badge"><?= $h($t('قديم', 'Stale')) ?></span><?php endif; ?></div>
<div class="muted"><?= $h($t('بتاريخ ', 'As of ')) ?><?= $h(substr((string) ($s['as_of'] ?? '-'), 0, 10)) ?></div>
<?php else: ?><div class="score" aria-label="<?= $h($t('مش مقاس', 'Not measured')) ?>">—</div><div class="muted"><?= $h($t('لسه مش مقاس', 'Not measured yet')) ?></div><?php endif; ?></section>
<?php endforeach; ?>
</div>

<h2><?= $h($t('أثر التعديلات (قبل/بعد من Search Console)', 'Impact of changes (before/after, Search Console)')) ?></h2>
<?php if (($imp['state'] ?? '') === 'measured'): ?>
<div class="card">
<div><?= $h($t('النقرات: ', 'Clicks: ')) ?><strong><?= (int) $imp['clicks']['before'] ?> → <?= (int) $imp['clicks']['after'] ?></strong><?= $h($pct($imp['clicks']['change_pct'])) ?></div>
<div><?= $h($t('مرات الظهور: ', 'Impressions: ')) ?><strong><?= (int) $imp['impressions']['before'] ?> → <?= (int) $imp['impressions']['after'] ?></strong><?= $h($pct($imp['impressions']['change_pct'])) ?></div>
<div><span class="badge"><?= $h($conf[$imp['confidence']] ?? '') ?></span><?= (int) $imp['pages'] ?> <?= $h($t('صفحة قارنّاها', 'pages compared')) ?></div>
<?php if (!empty($imp['low_traffic'])): ?><div class="muted"><?= $h($t('الزيارات قليلة جدًا، فالفروق دي ممكن تكون ضوضاء.', 'Traffic is very low, so these differences may be noise.')) ?></div><?php endif; ?>
</div>
<?php else: ?><p class="muted"><?= $h($t('لسه مفيش مقارنات اتجمّعت. بتظهر بعد 7 و14 و28 يوم من تطبيق التعديلات.', 'No comparisons collected yet. They appear 7, 14 and 28 days after changes are applied.')) ?></p><?php endif; ?>

<h2><?= $h($t('ملاحظات', 'Notes')) ?></h2>
<ul class="muted">
<li><?= $h($t('SEO = نسبة الصفحات المفحوصة اللي مفيش عليها مهام مفتوحة، وهو مقياس صحة صفحات مش تقييم شامل.', 'SEO = share of audited pages with no open tasks: a page-health measure, not an overall rating.')) ?></li>
<li><?= $h($t('GEO من عينات API لإجابات الذكاء الاصطناعي مع بحث ويب، مش ترتيب تطبيقات المستهلك.', 'GEO comes from API samples of AI answers with web search, not consumer-app rankings.')) ?></li>
<li><?= $h($t('فروق قبل/بعد مش إثبات إن التعديل هو السبب: الموسم وتغييرات تانية بتأثر.', 'Before/after differences are not proof the changes caused them: seasonality and other changes also move these numbers.')) ?></li>
</ul>
</main></body></html>

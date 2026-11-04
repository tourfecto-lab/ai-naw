<?php
/**
 * عينة جودة موديلات الذكاء الاصطناعي على مهام سياحية (عربي/إنجليزي): دقة، حفظ حقائق، جودة لغة، تكلفة.
 *
 * مابتغيّرش أي إعداد: مابتحفظش route ولا بتلمس قاعدة البيانات. بتقرا مفاتيح السيرفر وبتنادي المزودين مباشرة.
 * بدون --confirm بتطبع الخطة بس (من غير أي صرف).
 *
 * الاستخدام (على السيرفر):
 *   php tools/ai_model_sample.php --models=gemini:gemini-flash-latest,openai:gpt-4o-mini,deepseek:deepseek-chat
 *   php tools/ai_model_sample.php --models=... --confirm [--max-usd=0.05] [--max-tokens=40000] [--out=storage/ai-sample.json]
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
if (!is_file($root . '/vendor/autoload.php')) { fwrite(STDERR, "Composer autoload is missing.\n"); exit(1); }
require $root . '/cron/bootstrap.php';
require_once $root . '/app/Services/AI/AiSampleChecks.php';

$opts = getopt('', ['models:', 'confirm', 'long', 'max-usd::', 'max-tokens::', 'out::']);
$models = array_values(array_filter(array_map('trim', explode(',', (string) ($opts['models'] ?? '')))));
if (!$models) { fwrite(STDERR, "Usage: --models=provider:model,provider:model [--confirm]\n"); exit(1); }
$maxUsd = (float) ($opts['max-usd'] ?? 0.05);
$maxTokens = (int) ($opts['max-tokens'] ?? 40000);
$confirm = array_key_exists('confirm', $opts);
$long = array_key_exists('long', $opts);

$A = 'AiSampleChecks';
/** حقائق مؤكدة: أي رقم تاني في الناتج = اختراع */
$cases = [
    ['id' => 'en_meta', 'lang' => 'en', 'max_tokens' => 120,
        'system' => 'You write search-engine meta descriptions. Use ONLY the facts provided. Never invent prices, durations, ratings, policies or availability. Reply with the description only.',
        'user' => "Write a meta description (between 100 and 155 characters) for this tour page.\nFacts: Two Hour Walking Tour of Manhattan; duration 2 hours; small groups; from EUR 160 per person; licensed local guide.",
        'check' => static fn (string $o) => $A::summarize([
            'length_100_155' => $A::lengthBetween($o, 100, 155),
            'names_tour' => $A::containsAll($o, ['Manhattan']),
            'keeps_price_or_omits' => $A::numbersSubset($o, ['2', '160']),
            'no_invented_promises' => $A::containsNone($o, ['free cancellation', 'guaranteed', 'best price', 'cheapest', '5-star', 'rated']),
            'english' => $A::arabicRatio($o) < 0.05,
        ])],
    ['id' => 'ar_meta', 'lang' => 'ar', 'max_tokens' => 200,
        'system' => 'أنت تكتب أوصاف ميتا لمحركات البحث. استخدم الحقائق المعطاة فقط. ممنوع اختراع أسعار أو مدد أو تقييمات أو سياسات. اكتب الوصف فقط.',
        'user' => "اكتب وصف ميتا (بين 90 و155 حرفًا) لصفحة هذه الرحلة بالعربية.\nالحقائق: رحلة نيلية بالفلوكة في القاهرة؛ المدة ساعتان؛ عشاء على متن المركب؛ من 45 دولارًا للفرد.",
        'check' => static fn (string $o) => $A::summarize([
            'length_90_155' => $A::lengthBetween($o, 90, 155),
            'arabic_output' => $A::arabicRatio($o) >= 0.7,
            'numbers_only_from_facts' => $A::numbersSubset($o, ['2', '45']),
            'mentions_cairo' => $A::containsAll($o, ['القاهرة']),
            'no_invented_promises' => $A::containsNone($o, ['إلغاء مجاني', 'مضمون', 'أفضل سعر', 'الأرخص', 'تقييم']),
        ])],
    ['id' => 'en_title', 'lang' => 'en', 'max_tokens' => 60,
        'system' => 'You write SEO page titles. Keep the brand and the main keyword. Reply with the title only.',
        'user' => "Write a page title of at most 60 characters.\nPage: Nile dinner cruise in Cairo by felucca. Brand: Nile Journeys. Main keyword: Cairo Nile dinner cruise.",
        'check' => static fn (string $o) => $A::summarize([
            'max_60_chars' => $A::lengthBetween($o, 20, 60),
            'keeps_brand' => $A::containsAll($o, ['Nile Journeys']),
            'keeps_keyword' => $A::containsAll($o, ['Nile', 'Cairo']),
            'no_numbers' => $A::numbersSubset($o, []),
        ])],
    ['id' => 'facts_json', 'lang' => 'en', 'max_tokens' => 300,
        'system' => 'You extract facts from page text into JSON. Return ONLY valid JSON with keys price, duration, child_policy. Use null when the text does not state the value. Never guess.',
        'user' => "Page text: 'Aswan Nubian Village Boat Trip. Duration: 4 hours. Pick-up from your hotel. From USD 60 per person. Cancellation: free up to 24 hours.'",
        'check' => static function (string $o) use ($A) {
            $t = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($o)));
            $j = json_decode($t, true);
            return $A::summarize([
                'valid_json' => is_array($j),
                'price_kept' => is_array($j) && $A::containsAll((string) json_encode($j['price'] ?? ''), ['60']),
                'duration_kept' => is_array($j) && $A::containsAll((string) json_encode($j['duration'] ?? ''), ['4']),
                'child_policy_not_invented' => is_array($j) && array_key_exists('child_policy', $j) && $j['child_policy'] === null,
            ]);
        }],
    ['id' => 'ar_translate', 'lang' => 'ar', 'max_tokens' => 220,
        'system' => 'You translate tourism copy from English to Arabic. Keep every number and proper noun exactly. Do not add or remove information. Reply with the translation only.',
        'user' => "Explore the Pyramids of Giza with a licensed Egyptologist on a 5 hour private tour from Cairo, with hotel pick-up included.",
        'check' => static fn (string $o) => $A::summarize([
            'arabic_output' => $A::arabicRatio($o) >= 0.7,
            'keeps_number_5' => $A::containsAll($o, ['5']),
            'no_new_numbers' => $A::numbersSubset($o, ['5']),
            'mentions_giza' => $A::containsAll($o, ['الجيزة']),
            'mentions_hotel_pickup' => $A::containsAll($o, ['فندق']),
        ])],
];

if ($long) {
    $plan = [
        'id' => 'plan_json', 'lang' => 'en', 'max_tokens' => 1400,
        'system' => 'You are an SEO expert for a tour operator. Return ONLY valid JSON: {"summary": string, "changes": [{"page": string, "field": "title"|"description", "value": string}], "client_tasks": [{"page": string, "title": string}]}. Rules: change a field ONLY when it is missing or longer than its limit (title 60 chars, description 155). Keep the brand "Nile Journeys". Use ONLY the given facts; never invent prices, durations, ratings, policies or availability. Leave good fields untouched.',
        'user' => "Facts: company Nile Journeys; tours in Cairo, Giza and Luxor; Cairo felucca dinner cruise lasts 2 hours, from USD 45; Giza pyramids private tour 5 hours with hotel pick-up.\nPages:\n"
            . "1. /tours/cairo-felucca-dinner | title: 'Cairo Felucca Dinner Cruise | Nile Journeys' (ok) | description: MISSING\n"
            . "2. /tours/giza-pyramids-private | title: 'Private Giza Pyramids Tour with Egyptologist, Hotel Pick-up and Lunch in Cairo | Nile Journeys' (too long, 95 chars) | description: 'Explore Giza with a licensed guide. 5 hours, hotel pick-up included.' (ok)\n"
            . "3. /tours/luxor-day-trip | title: MISSING | description: MISSING (no facts about this tour except that it is in Luxor)\n"
            . "4. /privacy-policy | title: 'Privacy Policy | Nile Journeys' (ok) | description: MISSING (legal page, not a priority)",
        'check' => static function (string $o) use ($A) {
            $t = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($o)));
            $j = json_decode($t, true);
            $changes = is_array($j) && is_array($j['changes'] ?? null) ? $j['changes'] : [];
            $byKey = [];
            foreach ($changes as $c) $byKey[($c['page'] ?? '') . '|' . ($c['field'] ?? '')] = (string) ($c['value'] ?? '');
            $all = implode(' ', array_values($byKey));
            $lens = true;
            foreach ($byKey as $k => $v) { $lens = $lens && mb_strlen($v) <= (str_ends_with($k, '|title') ? 60 : 155) && mb_strlen($v) >= 15; }
            return $A::summarize([
                'valid_json' => is_array($j),
                'adds_missing_felucca_description' => isset($byKey['/tours/cairo-felucca-dinner|description']),
                'shortens_giza_title' => isset($byKey['/tours/giza-pyramids-private|title']),
                'leaves_good_fields_untouched' => !isset($byKey['/tours/cairo-felucca-dinner|title']) && !isset($byKey['/tours/giza-pyramids-private|description']) && !isset($byKey['/privacy-policy|title']),
                'values_within_limits' => $lens,
                'keeps_brand_in_titles' => !isset($byKey['/tours/giza-pyramids-private|title']) || $A::containsAll($byKey['/tours/giza-pyramids-private|title'], ['Nile Journeys']),
                'numbers_only_from_facts' => $A::numbersSubset($all, ['2', '45', '5']),
                'no_invented_promises' => $A::containsNone($all, ['free cancellation', 'guaranteed', 'best price', 'cheapest', 'rated', '5-star']),
                'no_invented_luxor_facts' => !isset($byKey['/tours/luxor-day-trip|description']) || $A::numbersSubset($byKey['/tours/luxor-day-trip|description'], []),
            ]);
        }];
    $cases[] = $plan;
}

$make = [
    'gemini' => static fn () => new GeminiProvider(),
    'openai' => static fn () => new OpenAIProvider(),
    'deepseek' => static fn () => new DeepSeekProvider(),
    'kimi' => static fn () => new KimiProvider(),
    'anthropic' => static fn () => new AnthropicProvider(),
];

echo "AI model sample: " . count($models) . " model(s) x " . count($cases) . " tourism cases = " . (count($models) * count($cases)) . " calls\n";
echo "Budget guard: max " . $maxUsd . " USD estimated, max " . $maxTokens . " tokens. " . ($confirm ? "RUNNING (paid calls)." : "DRY RUN: add --confirm to call the providers.") . "\n";
foreach ($cases as $c) echo " - {$c['id']} ({$c['lang']})\n";
if (!$confirm) exit(0);

$rows = [];
$spentUsd = 0.0;
$spentTokens = 0;
foreach ($models as $spec) {
    [$provider, $model] = array_pad(explode(':', $spec, 2), 2, '');
    if (!isset($make[$provider]) || $model === '' || !preg_match('/^[A-Za-z0-9._-]{1,120}$/', $model)) { fwrite(STDERR, "Skipping invalid model spec: $spec\n"); continue; }
    try { $p = $make[$provider](); } catch (Throwable $e) { fwrite(STDERR, "$provider unavailable\n"); continue; }
    if (!$p->isConfigured()) { fwrite(STDERR, "$provider is not configured on this server (no API key); skipped.\n"); continue; }
    foreach ($cases as $c) {
        if ($spentUsd >= $maxUsd || $spentTokens >= $maxTokens) { echo "Budget guard reached: stopping.\n"; break 2; }
        $t0 = microtime(true);
        try {
            $r = $p->generateReply($c['system'], [['role' => 'user', 'content' => $c['user']]], ['model' => $model, '_routing_exact' => true, 'temperature' => 0.2, 'max_tokens' => $provider === 'gemini' ? max(1500, $c['max_tokens'] * 10) : $c['max_tokens']]);
        } catch (Throwable $e) {
            $r = ['success' => false, 'error' => get_class($e)];
        }
        $out = trim((string) ($r['content'] ?? ''));
        // سبب الفشل مختصر ومن غير أي مفتاح: بنشيل أي نص بيشبه مفتاح قبل العرض
        $err = !empty($r['success']) ? '' : (string) preg_replace('/(sk-|AIza|Bearer\s+)[A-Za-z0-9._-]{6,}/', '$1***', mb_substr((string) ($r['error'] ?? $r['technical_error'] ?? 'unknown'), 0, 110));
        $sum = !empty($r['success']) && $out !== '' ? ($c['check'])($out) : ['passed' => 0, 'total' => 1, 'score' => 0.0, 'failed' => ['call_failed']];
        $usd = (float) ($r['estimated_cost_usd'] ?? 0);
        $tok = (int) ($r['tokens_total'] ?? 0);
        $spentUsd += $usd;
        $spentTokens += $tok;
        $rows[] = ['provider' => $provider, 'model' => $model, 'case' => $c['id'], 'lang' => $c['lang'], 'score' => $sum['score'], 'failed' => $sum['failed'], 'tokens' => $tok, 'usd' => round($usd, 6), 'ms' => (int) round((microtime(true) - $t0) * 1000), 'error' => $err, 'output' => mb_substr($out, 0, 400)];
    }
}

echo "\n" . str_pad('model', 34) . str_pad('case', 14) . str_pad('score', 8) . str_pad('tokens', 8) . str_pad('usd', 10) . "failed\n";
foreach ($rows as $r) echo str_pad($r['provider'] . ':' . $r['model'], 34) . str_pad($r['case'], 14) . str_pad((string) $r['score'], 8) . str_pad((string) $r['tokens'], 8) . str_pad((string) $r['usd'], 10) . implode(',', $r['failed']) . "\n";
$shown = [];
foreach ($rows as $r) {
    $k = $r['provider'];
    if ($r['error'] !== '' && empty($shown[$k])) { echo "  ERROR {$r['provider']}:{$r['case']}: {$r['error']}\n"; $shown[$k] = true; }
}
echo "\nSummary per model (average score; cost per call):\n";
$by = [];
foreach ($rows as $r) { $k = $r['provider'] . ':' . $r['model']; $by[$k]['s'][] = $r['score']; $by[$k]['u'][] = $r['usd']; $by[$k][$r['lang']][] = $r['score']; }
foreach ($by as $k => $v) {
    $avg = static fn ($a) => $a ? round(array_sum($a) / count($a), 3) : 0;
    echo str_pad($k, 36) . 'all=' . $avg($v['s']) . '  en=' . $avg($v['en'] ?? []) . '  ar=' . $avg($v['ar'] ?? []) . '  usd/call=' . round(array_sum($v['u']) / max(1, count($v['u'])), 6) . "\n";
}
echo "\nTotal estimated spend: " . round($spentUsd, 6) . " USD, tokens: $spentTokens (estimates; not a billing source).\n";
if (!empty($opts['out'])) {
    $path = (string) $opts['out'];
    if (!str_starts_with($path, '/')) $path = $root . '/' . $path;
    file_put_contents($path, json_encode(['generated_at' => gmdate('c'), 'rows' => $rows, 'spent_usd' => $spentUsd, 'tokens' => $spentTokens], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "Saved: $path\n";
}

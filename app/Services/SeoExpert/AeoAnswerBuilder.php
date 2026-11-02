<?php
/**
 * مسودة أسئلة وإجابات (AEO) من حقول فعلية في صفحة الرحلة، وبناء FAQPage بعد مراجعة بشرية.
 * القواعد:
 *  1) كل إجابة مبنية من حقل موجود، ومعاها `source` (اسم الحقل/الصفحة) و`evidence` (نص حرفي من الصفحة).
 *  2) الإجابة بتقتبس القيمة حرفيًا: ما بنضيفش سعر أو موعد أو سياسة إلغاء أو رقم مش موجود في الدليل.
 *  3) الحقل المفقود ما بيتولّدش له إجابة، بيرجع في `missing` برسالة "بيانات ناقصة: أضف X".
 *  4) مفيش نشر هنا: الدالة بترجّع مسودة أو كود جاهز للنسخ فقط.
 * منطق صرف: بلا شبكة ولا قاعدة بيانات ولا AI. الاستخراج إنجليزي حاليًا؛ اللغات التانية unsupported_language.
 */
final class AeoAnswerBuilder
{
    private const MAX_PAIRS = 12;
    private const NOTE = 'FAQ rich results in Google are limited to few site types; the value here is clear on-page answers that AI answer engines can quote. Publish the same questions and answers visibly on the page.';
    /** kind => [عربي، English] لرسالة "بيانات ناقصة" */
    private const ADD = [
        'duration' => ['مدة الرحلة (مثال: "Duration: 4 hours")', 'the trip duration (e.g. "Duration: 4 hours")'],
        'price' => ['السعر في Schema الرحلة (offers.price)', 'the price in the trip schema (offers.price)'],
        'cancellation' => ['سياسة الإلغاء (مثال: "Cancellation: Free up to 24h")', 'the cancellation policy (e.g. "Cancellation: Free up to 24h")'],
        'pickup' => ['الاستقبال (مثال: "Pick-up: From your hotel")', 'pick-up details (e.g. "Pick-up: From your hotel")'],
        'guide' => ['المرشد (مثال: "Guide: Licensed Egyptologist")', 'the guide (e.g. "Guide: Licensed Egyptologist")'],
    ];

    /** @param array $page ناتج SeoExpertPageContext::fresh (lang,text,schema,url) */
    public static function draft(array $page): array
    {
        $lang = strtolower(substr((string) ($page['lang'] ?? ''), 0, 2));
        $url = (string) ($page['url'] ?? '');
        $base = ['url' => $url, 'readiness' => self::readiness($page), 'note' => self::NOTE];
        if ($lang !== '' && !in_array($lang, ['en', 'ar'], true)) {
            return $base + ['state' => 'unsupported_language', 'pairs' => [], 'missing' => []];
        }
        if ($lang === 'ar') {
            return self::draftArabic($page, $base);
        }
        $name = self::name($page);
        if ($name === '') {
            return $base + ['state' => 'no_trip_found', 'pairs' => [], 'missing' => []];
        }
        $text = trim(preg_replace('/\s+/u', ' ', (string) ($page['text'] ?? '')));
        $pairs = [];
        $add = static function (string $kind, string $q, string $a, string $source, string $evidence) use (&$pairs): void {
            $pairs[$kind] = ['id' => $kind, 'kind' => $kind, 'question' => $q, 'answer' => $a, 'source' => $source, 'evidence' => mb_substr(trim($evidence), 0, 200), 'status' => 'draft'];
        };

        if (preg_match('/\b(?:lasts?|duration)\s*:?\s*(\d+(?:\.\d+)?(?:\s*[-\x{2013}]\s*\d+(?:\.\d+)?)?\s*(?:hours?|days?|nights?|minutes?))\b/iu', $text, $m)) {
            $add('duration', "How long does $name last?", 'Duration: ' . trim($m[1]) . '.', 'page text: Duration (' . $url . ')', $m[0]);
        }
        $offer = self::offer($page);
        if ($offer !== null) {
            $per = preg_match('/per\s+(?:person|traveller|traveler|adult)/i', $text, $pm) === 1;
            $amount = trim($offer['currency'] . ' ' . $offer['price']);
            $add('price', "How much does $name cost?", 'From ' . $amount . ($per ? ' ' . strtolower($pm[0]) : '') . '.', 'schema: ' . $offer['field'], 'offers ' . $offer['field'] . ' = ' . $amount . ($per ? '; page text: ' . $pm[0] : ''));
        }
        if (preg_match('/cancell?ation\s*:?\s*(free\s+up\s+to\s+\d+\s*(?:h\b|hours?))/i', $text, $m)) {
            $add('cancellation', "What is the cancellation policy for $name?", 'Cancellation: ' . trim($m[1]) . '.', 'page text: Cancellation (' . $url . ')', $m[0]);
        }
        if (preg_match('/pick-?\s?up\s*:?\s*(from your hotel|from your accommodation)\b/i', $text, $m)) {
            $add('pickup', "What pick-up does $name offer?", 'Pick-up: ' . trim($m[1]) . '.', 'page text: Pick-up (' . $url . ')', $m[0]);
        }
        if (preg_match('/\bguide\s*:?\s*((?:licensed|certified|professional|private)\s+[A-Za-z]+)/iu', $text, $m)) {
            $add('guide', "Who guides $name?", 'Guide: ' . trim($m[1]) . '.', 'page text: Guide (' . $url . ')', $m[0]);
        }
        $missing = [];
        foreach (self::ADD as $kind => [$ar, $en]) {
            if (!isset($pairs[$kind])) {
                $missing[] = ['kind' => $kind, 'add_ar' => 'بيانات ناقصة: أضف ' . $ar, 'add_en' => 'Missing data: add ' . $en];
            }
        }
        return $base + ['state' => $pairs ? 'drafted' : 'no_stated_facts', 'trip' => $name, 'pairs' => array_values($pairs), 'missing' => $missing];
    }

    /**
     * استخراج عربي بصيغة "الحقل: القيمة" (المدة، الإلغاء، الاستقبال، المرشد) + السعر من الـSchema.
     * مبني على صيغ التسميات الشائعة ومجرَّب على نصوص اختبار فقط؛ لسه ما اتجرّبش على صفحة عربية حقيقية.
     */
    private static function draftArabic(array $page, array $base): array
    {
        $url = (string) ($page['url'] ?? '');
        $name = self::name($page);
        if ($name === '') {
            return $base + ['state' => 'no_trip_found', 'pairs' => [], 'missing' => []];
        }
        $text = trim(preg_replace('/\s+/u', ' ', (string) ($page['text'] ?? '')));
        $d = '[0-9\x{0660}-\x{0669}]';
        $pairs = [];
        $add = static function (string $kind, string $q, string $a, string $source, string $evidence) use (&$pairs): void {
            $pairs[$kind] = ['id' => $kind, 'kind' => $kind, 'question' => $q, 'answer' => $a, 'source' => $source, 'evidence' => mb_substr(trim($evidence), 0, 200), 'status' => 'draft'];
        };
        if (preg_match('/(?:المدة|مدة الرحلة)\s*:?\s*(' . $d . '+(?:[.,]' . $d . '+)?\s*(?:ساعات|ساعة|أيام|يوم|ليالي|ليلة|دقيقة|دقائق))/u', $text, $m)) {
            $add('duration', "كم مدة {$name}؟", 'المدة: ' . trim($m[1]) . '.', 'نص الصفحة: المدة (' . $url . ')', $m[0]);
        }
        $offer = self::offer($page);
        if ($offer !== null) {
            $per = preg_match('/للشخص|للفرد|لكل شخص/u', $text, $pm) === 1;
            $amount = trim($offer['currency'] . ' ' . $offer['price']);
            $add('price', "كم سعر {$name}؟", 'يبدأ من ' . $amount . ($per ? ' ' . $pm[0] : '') . '.', 'schema: ' . $offer['field'], 'offers ' . $offer['field'] . ' = ' . $amount . ($per ? '; نص الصفحة: ' . $pm[0] : ''));
        }
        if (preg_match('/(?:الإلغاء|إلغاء)\s*:?\s*(مجاني(?:ة)?\s+حتى\s+' . $d . '+\s*(?:ساعة|ساعات))/u', $text, $m)) {
            $add('cancellation', "ما سياسة الإلغاء في {$name}؟", 'الإلغاء: ' . trim($m[1]) . '.', 'نص الصفحة: الإلغاء (' . $url . ')', $m[0]);
        }
        if (preg_match('/(?:الاستقبال|الاستلام)\s*:?\s*(من (?:الفندق|مكان إقامتك))/u', $text, $m)) {
            $add('pickup', "هل يوجد استقبال في {$name}؟", 'الاستقبال: ' . trim($m[1]) . '.', 'نص الصفحة: الاستقبال (' . $url . ')', $m[0]);
        }
        if (preg_match('/المرشد\s*:?\s*((?:معتمد|مرخص|محترف)\s+[\p{Arabic}]+)/u', $text, $m)) {
            $add('guide', "من يرشدك في {$name}؟", 'المرشد: ' . trim($m[1]) . '.', 'نص الصفحة: المرشد (' . $url . ')', $m[0]);
        }
        $missing = [];
        foreach (self::ADD as $kind => [$ar, $en]) {
            if (!isset($pairs[$kind])) {
                $missing[] = ['kind' => $kind, 'add_ar' => 'بيانات ناقصة: أضف ' . $ar, 'add_en' => 'Missing data: add ' . $en];
            }
        }
        return $base + ['state' => $pairs ? 'drafted' : 'no_stated_facts', 'trip' => $name, 'pairs' => array_values($pairs), 'missing' => $missing];
    }

    /**
     * FAQPage من الأزواج اللي المستخدم راجعها. بيرفض: روابط، وأي رقم في الإجابة مش موجود حرفيًا في الدليل (evidence) أو السؤال.
     * @return array{count:int,jsonld:?array,validation:?array,script:?string,rejected:array,note:string}
     */
    public static function build(array $approved): array
    {
        $clean = static fn (string $s, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($s))), 0, $max);
        $entities = [];
        $rejected = [];
        foreach (array_slice($approved, 0, self::MAX_PAIRS) as $i => $p) {
            if (!is_array($p)) {
                continue;
            }
            $q = $clean((string) ($p['question'] ?? ''), 160);
            $a = $clean((string) ($p['answer'] ?? ''), 600);
            $ev = (string) ($p['evidence'] ?? '');
            if ($q === '' || $a === '') {
                $rejected[] = ['index' => $i, 'reason' => 'empty'];
            } elseif ($ev === '') {
                $rejected[] = ['index' => $i, 'reason' => 'no_evidence'];
            } elseif (preg_match('#https?://|www\.#i', $q . $a)) {
                $rejected[] = ['index' => $i, 'reason' => 'link'];
            } elseif (($bad = self::foreignNumbers($a, $ev . ' ' . $q)) !== []) {
                $rejected[] = ['index' => $i, 'reason' => 'number_not_in_evidence', 'numbers' => $bad];
            } else {
                $entities[] = ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]];
            }
        }
        $graph = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $entities];
        return ['count' => count($entities), 'jsonld' => $entities ? $graph : null,
            'validation' => $entities ? SeoSchemaValidator::validate($graph) : null,
            'script' => $entities ? SeoSchemaBuilder::scriptTag($graph) : null, 'rejected' => $rejected, 'note' => self::NOTE];
    }

    /** أرقام الإجابة اللي مش موجودة حرفيًا في الدليل. */
    public static function foreignNumbers(string $answer, string $evidence): array
    {
        preg_match_all('/[0-9\x{0660}-\x{0669}]+(?:[.,][0-9\x{0660}-\x{0669}]+)*/u', $answer, $m);
        preg_match_all('/[0-9\x{0660}-\x{0669}]+(?:[.,][0-9\x{0660}-\x{0669}]+)*/u', $evidence, $e);
        return array_values(array_diff(array_unique($m[0]), $e[0]));
    }

    /** جاهزية الصفحة للإجابات: FAQPage/HowTo موجودين؟ */
    public static function readiness(array $page): array
    {
        $types = [];
        $walk = static function ($n) use (&$walk, &$types): void {
            if (!is_array($n)) {
                return;
            }
            foreach ((array) ($n['@type'] ?? []) as $t) {
                if (is_string($t)) $types[$t] = true;
            }
            foreach ((array) ($n['@graph'] ?? []) as $c) {
                $walk($c);
            }
            if (array_is_list($n)) {
                foreach ($n as $c) $walk($c);
            }
        };
        foreach ((array) ($page['schema'] ?? []) as $d) {
            $walk($d);
        }
        return ['has_faq_schema' => isset($types['FAQPage']), 'has_howto_schema' => isset($types['HowTo']), 'existing_types' => array_keys($types)];
    }

    private static function name(array $page): string
    {
        $stack = [$page['schema'] ?? []];
        while ($stack) {
            $n = array_pop($stack);
            if (!is_array($n)) continue;
            if (isset($n['name']) && is_string($n['name']) && trim($n['name']) !== '' && array_intersect(array_map('strval', (array) ($n['@type'] ?? [])), ['TouristTrip', 'Trip', 'Product'])) {
                return mb_substr(trim($n['name']), 0, 120);
            }
            foreach ($n as $v) {
                if (is_array($v)) $stack[] = $v;
            }
        }
        return '';
    }

    /** السعر كنص حرفي زي ما هو في الـSchema (مش مقرّب ولا متحوّل). @return ?array{price:string,currency:string,field:string} */
    private static function offer(array $page): ?array
    {
        $stack = [$page['schema'] ?? []];
        while ($stack) {
            $n = array_pop($stack);
            if (!is_array($n)) continue;
            if (array_intersect(array_map('strval', (array) ($n['@type'] ?? [])), ['Offer', 'AggregateOffer'])) {
                foreach (['price', 'lowPrice'] as $field) {
                    $p = $n[$field] ?? null;
                    if (is_scalar($p) && is_numeric($p) && (float) $p > 0) {
                        return ['price' => (string) $p, 'currency' => is_string($n['priceCurrency'] ?? null) ? $n['priceCurrency'] : '', 'field' => 'offers.' . $field];
                    }
                }
            }
            foreach ($n as $v) {
                if (is_array($v)) $stack[] = $v;
            }
        }
        return null;
    }
}

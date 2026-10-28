<?php
require_once __DIR__.'/SeoClaimEvidence.php';

/**
 * Tourfecto - بوابة جودة خطة SEO
 *
 * الذكاء الاصطناعي كان بيعيد كتابة عناوين وأوصاف كويسة أصلًا وبيشيل منها
 * كلمات البحث المهمة واسم البراند (مثال حقيقي: "Luxury Egypt Tours & Nile
 * Cruises | Tourfecto Travel" ← "Explore Luxor, Aswan, and Cairo with
 * Tourfecto Travel")، رغم إن التعليمات بتقول متغيّرش الكويس. البوابة دي
 * بتطبق القاعدة في الكود: أي تعديل لعنوان/وصف لازم يصلّح مشكلة حقيقية
 * موجودة، ولازم يحافظ على البراند لو كان موجود.
 *
 * منطق خالص بدون DB.
 */
class SeoPlanQualityGate
{
    private const LIMITS = ['title' => [25, 70], 'description' => [70, 165]];

    /** مهام عامة من غير دليل من الموقع - مش بتفيد العميل */
    private const GENERIC_TASK = '/^(?:conduct|perform|do)\s+keyword research|^monitor(?:ing)?\s+(?:your\s+)?analytics|^improve\s+mobile\s+optimi[sz]ation|^optimi[sz]e\s+(?:site|page|website)\s+speed|^implement\s+local\s+seo|^enhance\s+local\s+content|^create\s+a\s+backlink|^build\s+backlinks|^verify\s+and\s+update\s+contact/i';

    /**
     * مشكلة حقيقية في القيمة الحالية (أو null لو القيمة كويسة).
     * @param string[] $sameFieldOnOtherPages نفس الحقل في باقي الصفحات المفحوصة
     */
    public static function issue(string $field, string $current, array $sameFieldOnOtherPages = []): ?string
    {
        if (!isset(self::LIMITS[$field])) {
            return 'not_gated';
        }
        $v = trim(preg_replace('/\s+/u', ' ', $current));
        if ($v === '') {
            return 'missing';
        }
        [$min, $max] = self::LIMITS[$field];
        $len = mb_strlen($v);
        if ($len < $min) {
            return 'too_short';
        }
        if ($len > $max) {
            return 'too_long';
        }
        if (preg_match('/\b(\p{L}{3,})\s+\1\b/iu', $v)) {
            return 'repeated_word';
        }
        if (preg_match('/^(home|untitled|index|new page|page)$/i', $v)) {
            return 'generic';
        }
        $template = self::template($v);
        foreach ($sameFieldOnOtherPages as $other) {
            $o = trim(preg_replace('/\s+/u', ' ', (string) $other));
            if ($o === '') {
                continue;
            }
            if (mb_strtolower($o) === mb_strtolower($v)) {
                return 'duplicate';
            }
            if ($template !== '' && self::template($o) === $template) {
                return 'shared_template';
            }
        }
        return null;
    }

    /** نفس الجملة بعد شيل الكلمات اللي بتتغير من صفحة للتانية (اسم القسم) */
    private static function template(string $v): string
    {
        $words = preg_split('/\s+/u', mb_strtolower($v));
        if (count($words) < 6) {
            return '';
        }
        // أول وآخر 3 كلمات ثابتين في القوالب ("Browse the best ... with prices, itineraries and instant booking.")
        return implode(' ', array_slice($words, 0, 3)) . ' … ' . implode(' ', array_slice($words, -4));
    }

    /**
     * بيرجّع التعديلات المقبولة بس.
     * @param array $changes [['page_index'=>int,'field'=>string,'value'=>string,'reason'=>string], ...]
     * @param array $pages الصفحات المفحوصة (title/description أو values[field][0])
     */
    public static function filterChanges(array $changes, array $pages, string $brand = '', array $confirmedFacts = []): array
    {
        $current = static function (array $p, string $field): string {
            return (string) ($p['values'][$field][0] ?? ($p[$field] ?? ''));
        };
        $kept = [];
        foreach ($changes as $c) {
            $field = (string) ($c['field'] ?? '');
            $page = $pages[$c['page_index'] ?? -1] ?? null;
            if ($page === null) {
                continue;
            }
            $now = $current($page, $field);
            $others = [];
            foreach ($pages as $i => $p) {
                if ($i !== ($c['page_index'] ?? -1)) {
                    $others[] = $current($p, $field);
                }
            }
            $issue = self::issue($field, $now, $others);
            if ($issue === null) {
                continue; // القيمة الحالية كويسة: مفيش سبب نغيّرها
            }
            $value = trim((string) ($c['value'] ?? ''));
            if(in_array($field,['title','description'],true)&&SeoClaimEvidence::review([$field=>$value],$confirmedFacts)['state']==='needs_confirmation')continue;
            if ($issue !== 'not_gated') {
                // البراند كان موجود في العنوان الحالي: لازم يفضل
                if ($field === 'title' && $brand !== '' && mb_stripos($now, $brand) !== false && mb_stripos($value, $brand) === false) {
                    $withBrand = $value . ' | ' . $brand;
                    if (mb_strlen($withBrand) > self::LIMITS['title'][1]) {
                        continue;
                    }
                    $value = $withBrand;
                }
                // التعديل نفسه لازم ميبقاش فيه نفس المشكلة
                if (in_array(self::issue($field, $value), ['missing', 'too_short', 'too_long', 'repeated_word', 'generic'], true)) {
                    continue;
                }
            }
            $c['value'] = $value;
            $c['issue'] = $issue;
            $kept[] = $c;
        }
        return $kept;
    }

    /**
     * بيشيل المهام العامة اللي مش مبنية على حاجة في الموقع. المهمة بتفضل بس
     * لو بتشاور على حاجة اتلقت فعلًا: صفحة محددة (/tours/cairo) أو عنصر تقني
     * (H1، noindex، canonical، redirect، 404، hreflang، schema، alt، sitemap...).
     * نصايح زي "حسّن السرعة" أو "اعمل باك لينكات" أو "انشر على السوشيال"
     * مش مهمة للعميل - والسوشيال والمنافسين وجوجل بيزنس خدمات المنصة نفسها.
     */
    public static function filterClientTasks(array $tasks,array $pages=[]): array
    {
        return array_values(array_filter($tasks, static function ($t)use($pages): bool {
            $title = trim((string) ($t['title'] ?? ''));
            if (preg_match(self::GENERIC_TASK, $title)) {
                return false;
            }
            $text = $title . ' ' . (string) ($t['instructions'] ?? '');
            $pointsToPage = (bool) preg_match('#(?:^|[\s(\'"])/[a-z0-9][a-z0-9\-/]*#i', $text);
            $technical = (bool) preg_match('/\b(h1|noindex|canonical|redirects?|404|hreflang|schema|alt[\s-]?text|sitemap|robots\.txt|lcp|cls|ttfb|https|mixed content)\b/i', $text);
            if($pages){
                require_once __DIR__.'/SeoPagePolicy.php';
                $page=$pages[$t['page_index']??-1]??null;if(!$page||!SeoPagePolicy::classify($page)['eligible'])return false;
                if(preg_match('/\b(?:schema|structured data|json-ld)\b/i',$text)&&!preg_match('/\b(?:invalid|error|malformed|duplicate)\b/i',$text)){
                    $types=[];$walk=static function($node)use(&$walk,&$types){if(!is_array($node))return;foreach((array)($node['@type']??[]) as $type)$types[]=$type;foreach($node as $v)if(is_array($v))$walk($v);};$walk($page['schema']??[]);
                    $requested=[];foreach(['Product','TouristTrip','Trip','TravelAgency','Organization','FAQPage','BreadcrumbList','Article','ItemList','CollectionPage'] as $type)if(preg_match('/\b'.preg_quote($type,'/').'\b/i',$text))$requested[]=$type;
                    if($types&&(!$requested||!array_diff($requested,$types)))return false;
                }
            }
            return $pointsToPage || $technical;
        }));
    }
}

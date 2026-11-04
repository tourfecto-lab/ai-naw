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
    /**
     * العنوان فيه البراند بالفعل؟ كلمة البراند الأولى كفاية («Tourfecto» جوه «Tourfecto Travel»): النموذج بيكتب الاسم المختصر،
     * ولو أضفنا الاسم الكامل فوقه بيطلع العنوان «... | Tourfecto | Tourfecto Travel» (اسم مكرر وطول زيادة).
     */
    public static function mentionsBrand(string $title, string $brand): bool
    {
        if (mb_stripos($title, $brand) !== false) {
            return true;
        }
        $first = preg_split('/\s+/u', trim($brand), -1, PREG_SPLIT_NO_EMPTY)[0] ?? '';
        return mb_strlen($first) >= 4 && mb_stripos($title, $first) !== false;
    }

    /**
     * عنوان فيه الاسم مرتين في آخره بعد الفواصل («Page | Tourfecto | Tourfecto Travel»): كلمة (5 حروف أو أكتر) متكررة
     * في جزئين مختلفين بعد أول جزء. أول جزء (عنوان الصفحة نفسه) مالوش علاقة، فـ«Luxor Tours | Cairo Tours» مش بتتعد.
     */
    public static function repeatedBrandSegments(string $title): bool
    {
        $parts = array_values(array_filter(array_map('trim', preg_split('/\s[|\x{2013}\x{2014}-]\s|\|/u', $title)), static fn ($p) => $p !== ''));
        if (count($parts) < 3) {
            return false;
        }
        $seen = [];
        foreach (array_slice($parts, 1) as $seg) {
            $words = array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($seg)), static fn ($w) => mb_strlen($w) >= 5));
            foreach ($words as $w) {
                if (isset($seen[$w])) {
                    return true;
                }
            }
            foreach ($words as $w) {
                $seen[$w] = true;
            }
        }
        return false;
    }

    /**
     * إصلاح حتمي (من غير ذكاء اصطناعي) لعنوان فيه اسم الشركة مرتين في آخره:
     * «Page | Tourfecto | Tourfecto Travel» ← «Page | Tourfecto Travel». بنشيل الجزء المكرر الأقصر اللي هو بداية جزء بعده.
     * بيرجّع null لو مفيش تكرار أو لو الناتج مش صالح كعنوان (أقصر من 25 حرف).
     */
    public static function collapseRepeatedBrand(string $title): ?string
    {
        if (!self::repeatedBrandSegments($title)) {
            return null;
        }
        $parts = array_values(array_filter(array_map('trim', explode('|', $title)), static fn ($p) => $p !== ''));
        $keep = [];
        foreach ($parts as $i => $seg) {
            $dup = false;
            for ($j = $i + 1; $j < count($parts) && $i > 0; $j++) {
                if (stripos($parts[$j], $seg) === 0 && mb_strlen($parts[$j]) > mb_strlen($seg)) {
                    $dup = true;
                    break;
                }
            }
            if (!$dup) {
                $keep[] = $seg;
            }
        }
        $out = implode(' | ', $keep);
        return ($out !== $title && count($keep) < count($parts) && mb_strlen($out) >= 25 && mb_strlen($out) <= 65) ? $out : null;
    }

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
        if ($field === 'title' && self::repeatedBrandSegments($v)) {
            return 'repeated_brand';
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
                if ($field === 'title' && $brand !== '' && mb_stripos($now, $brand) !== false && !self::mentionsBrand($value, $brand)) {
                    $withBrand = $value . ' | ' . $brand;
                    if (mb_strlen($withBrand) > self::LIMITS['title'][1]) {
                        continue;
                    }
                    $value = $withBrand;
                }
                // التعديل نفسه لازم ميبقاش فيه نفس المشكلة
                if (in_array(self::issue($field, $value), ['missing', 'too_short', 'too_long', 'repeated_word', 'repeated_brand', 'generic'], true)) {
                    continue;
                }
            }
            $c['value'] = $value;
            $c['issue'] = $issue;
            $kept[] = $c;
        }
        return self::dropDuplicateProposals($kept, $pages, $current);
    }

    /**
     * اقتراح نفس العنوان/الوصف لصفحتين (أو لقيمة موجودة فعلًا في صفحة تانية) بيعيد نفس التكرار اللي المهمة بتحاول تحله.
     * بنشيل كل المجموعة المتطابقة بدل ما نعدّل صفحة على حساب التانية؛ الصفحة بتفضل بقيمتها الحالية.
     * @param callable $current fn(array $page,string $field): string
     */
    public static function dropDuplicateProposals(array $kept, array $pages, callable $current): array
    {
        $norm = static fn (string $v): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $v)));
        $groups = [];
        foreach ($kept as $i => $c) {
            $f = (string) ($c['field'] ?? '');
            if (!in_array($f, ['title', 'description'], true)) continue;
            $groups[$f . '|' . $norm((string) ($c['value'] ?? ''))][] = $i;
        }
        $drop = [];
        foreach ($groups as $idx) {
            if (count($idx) > 1) foreach ($idx as $i) $drop[$i] = true;
        }
        foreach ($kept as $i => $c) {
            $f = (string) ($c['field'] ?? '');
            if (!in_array($f, ['title', 'description'], true) || isset($drop[$i])) continue;
            $mine = $c['page_index'] ?? -1;
            foreach ($pages as $pi => $p) {
                if ($pi !== $mine && $norm($current($p, $f)) !== '' && $norm($current($p, $f)) === $norm((string) ($c['value'] ?? ''))) { $drop[$i] = true; break; }
            }
        }
        return array_values(array_filter($kept, static fn ($c, $i) => !isset($drop[$i]), ARRAY_FILTER_USE_BOTH));
    }

    /**
     * بيشيل المهام العامة اللي مش مبنية على حاجة في الموقع. المهمة بتفضل بس
     * لو بتشاور على حاجة اتلقت فعلًا: صفحة محددة (/tours/cairo) أو عنصر تقني
     * (H1، noindex، canonical، redirect، 404، hreflang، schema، alt، sitemap...).
     * نصايح زي "حسّن السرعة" أو "اعمل باك لينكات" أو "انشر على السوشيال"
     * مش مهمة للعميل - والسوشيال والمنافسين وجوجل بيزنس خدمات المنصة نفسها.
     */
    /** robots.txt بيمنع الموقع كله (User-agent: * مع Disallow: /)؟ منطق خالص. */
    public static function robotsBlocksSite(string $robots): bool
    {
        $applies = false;
        foreach (preg_split('/\R/', $robots) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if (preg_match('/^user-agent:\s*(.+)$/i', $line, $m)) { $applies = trim($m[1]) === '*'; continue; }
            if ($applies && preg_match('/^disallow:\s*\/\s*$/i', $line)) return true;
        }
        return false;
    }

    /**
     * @param array $site دليل من الموقع: sitemap_valid (عدد sitemaps اتقرت صح) و robots_text (نص robots.txt لو اتقرا)
     */
    public static function filterClientTasks(array $tasks,array $pages=[],array $site=[]): array
    {
        return array_values(array_filter($tasks, static function ($t)use($pages,$site): bool {
            $title = trim((string) ($t['title'] ?? ''));
            if (preg_match(self::GENERIC_TASK, $title)) {
                return false;
            }
            // مهمة عامة بتطلب حاجة الموقع عنده فعلًا: مفيش دليل يبررها
            if (!empty($site['sitemap_valid']) && preg_match('/\b(?:create|generate|add|build|submit|set\s*up)\b.*\bsitemap|\bsitemap\b.*\b(?:create|generate)\b/i', $title)) {
                return false;
            }
            if (isset($site['robots_text']) && is_string($site['robots_text']) && $site['robots_text'] !== '' && !self::robotsBlocksSite($site['robots_text']) && preg_match('/robots\.txt/i', $title)) {
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

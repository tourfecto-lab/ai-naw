<?php
/**
 * Tourfecto - Schema السياحة (structured data) من بيانات الصفحة الحقيقية بس
 *
 * لكل صفحة رحلة: TouristTrip (الاسم، الوصف، الصورة، الشركة، والسعر لو مكتوب في الصفحة نفسها)
 * ولكل صفحة داخلية: BreadcrumbList (مسار الصفحة).
 * مفيش ذكاء صناعي هنا ومفيش تخمين: أي حاجة مش ظاهرة في الصفحة مابتتكتبش.
 * لو الصفحة فيها نفس النوع بالفعل (من Yoast / ثيم الرحلات) مابنكررهوش.
 */
class SeoSchemaBuilder
{
    /** أنواع الـ Schema اللي مسموح تتحقن (EdgeRules بيتحقق منها تاني قبل النشر) */
    public const TYPES = ['Article', 'Organization', 'LocalBusiness', 'TravelAgency', 'WebSite', 'Person', 'TouristTrip', 'Product', 'Offer', 'AggregateOffer', 'BreadcrumbList', 'ListItem', 'FAQPage', 'Question', 'Answer', 'TouristAttraction', 'TouristDestination', 'Place', 'ItemList', 'AggregateRating', 'ImageObject', 'Trip', 'Hotel', 'LodgingBusiness', 'Review', 'Rating', 'HowTo', 'HowToStep', 'PostalAddress', 'GeoCoordinates', 'Brand', 'ContactPoint'];

    private const TOUR_URL = '#/(?:tours?|trips?|excursions?|packages?|holidays?|cruises?|safaris?|activities|activity|day-tours?|tour-packages?|rahalat|رحلات|رحلة)(?:/|-|$)#iu';
    private const TOUR_WORDS = '/\b(?:tour|trip|excursion|cruise|safari|package|day trip|full day|half day|itinerary|snorkel(?:l?ing)?|diving|desert)\b|رحلة|جولة|رحلات/iu';
    private const PRICE = '/(?:(US\$|\$|€|£|EGP|USD|EUR|GBP|AED|SAR)\s?(\d{1,5}(?:[.,]\d{1,2})?))|(?:(\d{1,5}(?:[.,]\d{1,2})?)\s?(USD|EUR|GBP|EGP|AED|SAR|\$|€|£|جنيه|ج\.م))/u';

    /** مسار يشبه رحلة (من الرابط فقط، من غير قراءة الصفحة) */
    public static function isTourPath(string $path): bool
    {
        return preg_match(self::TOUR_URL, $path) === 1;
    }

    /** صفحة رحلة (منتج يتباع) مش صفحة قسم أو مقال */
    public static function isTourPage(array $page): bool
    {
        $path = (string) ($page['page_path'] ?? parse_url((string) ($page['url'] ?? ''), PHP_URL_PATH));
        if ($path === '' || $path === '/') {
            return false;
        }
        if (preg_match('#/(?:blog|news|guides?|category|tag|author|quiz|page/\d+)(?:/|$)#i', $path)) {
            return false;
        }
        $h1 = (string) ($page['h1'] ?? '');
        // A destination collection is not a single bookable trip, even with only one card.
        if (self::collectionSchema($page['schema'] ?? [])) return false;
        if (preg_match('/\b(?:tours|trips|excursions|cruises|packages)\b|رحلات/u', mb_strtolower($h1))
            && !preg_match('/\b(?:tour|trip|excursion|cruise|itinerary|day|days|night|nights)\b|جولة|رحلة|يوم|أيام/u', mb_strtolower($h1))) return false;
        $words = preg_match(self::TOUR_WORDS, $h1 . ' ' . ($page['title'] ?? '')) === 1;
        $urlLooks = preg_match(self::TOUR_URL, $path) === 1;
        // صفحة قسم فيها رحلات كتير (كروت بأسعار مختلفة) = قائمة مش رحلة واحدة.
        // إلا لو الصفحة نفسها رحلة: عندها Schema رحلة/منتج واحد، أو رابطها /tour/اسم-الرحلة (مفرد) -
        // الكروت هنا "رحلات ممكن تعجبك" تحت الرحلة، وكانت بتخلّي أهم صفحات الموقع تتحسب قوائم.
        if (count($page['card_prices'] ?? []) >= 3) {
            $single = self::tripSchema($page['schema'] ?? []) || preg_match('#^/(?:[a-z]{2}/)?(?:tour|trip|excursion|package|activity|cruise|safari)/[^/]+/?$#i', $path) === 1;
            if (!$single) {
                return false;
            }
        }
        // صفحة الرحلة الواحدة بيبقى رابطها أعمق من قسم الرحلات نفسه (/tours/xyz مش /tours)
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        if ($urlLooks && count($segments) < 2) {
            return false;
        }
        return $h1 !== '' && ($urlLooks || $words) && ($urlLooks || self::price($page) !== null);
    }

    /** Schema رحلة واحدة (TouristTrip أو Product) موجود على الصفحة */
    private static function tripSchema(array $schema): bool
    {
        foreach ($schema as $node) {
            if (!is_array($node)) continue;
            if (array_intersect((array) ($node['@type'] ?? []), ['TouristTrip', 'Product', 'Trip'])) return true;
            if (isset($node['@graph']) && is_array($node['@graph']) && self::tripSchema($node['@graph'])) return true;
        }
        return false;
    }

    private static function collectionSchema(array $schema): bool
    {
        foreach($schema as $node){if(!is_array($node))continue;
            if(array_intersect((array)($node['@type']??[]),['ItemList','CollectionPage']))return true;
            if(isset($node['@graph'])&&is_array($node['@graph'])&&self::collectionSchema($node['@graph']))return true;
        }
        // An itinerary ItemList nested inside a TouristTrip is not a collection page.
        return false;
    }

    /** Recheck old unexecuted proposals against the current public page too. */
    /** صفحة مقال أو دليل (مدونة): ينفع يتحط فيها روابط لرحلات ومقالات قريبة */
    public static function isArticlePage(array $page): bool
    {
        $path = (string) ($page['page_path'] ?? parse_url((string) ($page['url'] ?? ''), PHP_URL_PATH));
        return (bool) preg_match('~/(?:blog|guides?|articles?|news|stories|journal|magazine)/[^/]+~i', $path);
    }

    public static function taskCompatible(array $page,string $field,string $value): bool
    {
        if($field==='related_links')return self::isTourPage($page)||self::isArticlePage($page);
        $schema=$field==='jsonld'?json_decode($value,true):null;
        return !is_array($schema)||!self::hasType([$schema],['TouristTrip'])||self::isTourPage($page);
    }

    /** السعر المكتوب في الصفحة (أول سعر واضح) - ولو فيه أسعار مختلفة كتير ماناخدش ولا واحد */
    public static function price(array $page): ?array
    {
        $text = (string) ($page['text'] ?? '');
        if (!preg_match_all(self::PRICE, $text, $m, PREG_SET_ORDER)) {
            return null;
        }
        $found = [];
        foreach (array_slice($m, 0, 12) as $x) {
            $sym = $x[1] !== '' ? $x[1] : ($x[4] ?? '');
            $num = $x[2] !== '' ? $x[2] : ($x[3] ?? '');
            $cur = match (true) {
                in_array($sym, ['$', 'US$', 'USD'], true) => 'USD',
                in_array($sym, ['€', 'EUR'], true) => 'EUR',
                in_array($sym, ['£', 'GBP'], true) => 'GBP',
                in_array($sym, ['EGP', 'جنيه', 'ج.م'], true) => 'EGP',
                in_array($sym, ['AED', 'SAR'], true) => $sym,
                default => '',
            };
            $value = (float) str_replace(',', '.', $num);
            if ($cur === '' || $value < 1) {
                continue;
            }
            $found[$cur . ':' . $value] = ['price' => $value, 'currency' => $cur];
        }
        if (!$found) {
            return null;
        }
        $first = reset($found);
        // أسعار متعددة لنفس العملة (طفل / بالغ / فئات) = نطاق أسعار
        $same = array_values(array_filter($found, static fn ($f) => $f['currency'] === $first['currency']));
        // أسعار كتير مختلفة = غالبًا رحلات تانية ظاهرة في الصفحة (مقترحات) - مانكتبش سعر غلط
        if (count($found) > 4 || count($same) > 4) {
            return null;
        }
        if (count($same) > 1) {
            $vals = array_column($same, 'price');
            return ['low' => min($vals), 'high' => max($vals), 'currency' => $first['currency'], 'count' => count($vals)];
        }
        return $first;
    }

    private static function hasType(array $schema, array $types): bool
    {
        $walk = static function ($node) use (&$walk, $types): bool {
            if (!is_array($node)) {
                return false;
            }
            foreach ((array) ($node['@type'] ?? []) as $t) {
                if (in_array((string) $t, $types, true)) {
                    return true;
                }
            }
            foreach ($node as $v) {
                if (is_array($v) && $walk($v)) {
                    return true;
                }
            }
            return false;
        };
        foreach ($schema as $s) {
            if ($walk($s)) {
                return true;
            }
        }
        return false;
    }

    private static function clean(string $s, int $max): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
    }

    /** اسم مقروء لجزء من الرابط (/nile-cruises → Nile Cruises) */
    private static function label(string $segment): string
    {
        $s = urldecode($segment);
        $s = preg_replace('/\.(html?|php)$/i', '', $s);
        $s = trim(preg_replace('/[-_]+/', ' ', $s));
        return $s === '' ? '' : (preg_match('/[a-z]/i', $s) ? ucwords($s) : $s);
    }

    /**
     * Schema الصفحة كـ @graph (أو null لو مفيش حاجة مفيدة تتضاف).
     * @param array{business_name?:string, site_url:string, rating?:?array, verified_pages?:array} $ctx
     * @return array{graph:array, types:string[]}|null
     */
    public static function forPage(array $page, array $ctx): ?array
    {
        $url = (string) ($page['url'] ?? '');
        $path = (string) ($page['page_path'] ?? parse_url($url, PHP_URL_PATH));
        if ($url === '' || $path === '' || $path === '/' || !empty($page['schema_truncated']) || ($page['invalid_schema'] ?? 0) > 0) {
            return null;
        }
        $existing = $page['schema'] ?? [];
        $site = rtrim((string) $ctx['site_url'], '/');
        $business = self::clean((string) ($ctx['business_name'] ?? ''), 120);
        $graph = [];
        $types = [];

        if (self::isTourPage($page) && !self::hasType($existing, ['TouristTrip', 'Product', 'Trip', 'Event'])) {
            $name = self::clean((string) ($page['h1'] ?: $page['title']), 150);
            if ($business !== '') {
                $name = trim(preg_replace('/\s*[|\-–—]\s*' . preg_quote($business, '/') . '\s*$/iu', '', $name));
            }
            $trip = ['@type' => 'TouristTrip', '@id' => $url . '#trip', 'name' => $name, 'url' => $url];
            $desc = self::clean((string) ($page['description'] ?? ''), 300);
            if (mb_strlen($desc) >= 40) {
                $trip['description'] = $desc;
            }
            $img = (string) ($page['og']['og:image'] ?? $page['image'] ?? '');
            if (preg_match('#^https://[^\s"<>]{8,500}$#i', $img)) {
                $trip['image'] = $img;
            }
            if ($business !== '') {
                $trip['provider'] = ['@type' => 'TravelAgency', 'name' => $business, 'url' => $site . '/'];
            }
            $price = self::price($page);
            if ($price) {
                $trip['offers'] = isset($price['low'])
                    ? ['@type' => 'AggregateOffer', 'lowPrice' => $price['low'], 'highPrice' => $price['high'], 'priceCurrency' => $price['currency'], 'offerCount' => $price['count'], 'url' => $url]
                    : ['@type' => 'Offer', 'price' => $price['price'], 'priceCurrency' => $price['currency'], 'url' => $url, 'availability' => 'https://schema.org/InStock'];
            }
            $graph[] = $trip;
            $types[] = 'TouristTrip';
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
        if ($segments && !self::hasType($existing, ['BreadcrumbList'])) {
            // A URL directory is not proof that a navigable parent page exists.
            // Reuse successful crawl observations, including their exact final URLs.
            $parents = [];
            $origin = static fn(array $p): array => [strtolower($p['scheme'] ?? ''), strtolower($p['host'] ?? ''), $p['port'] ?? (strtolower($p['scheme'] ?? '') === 'https' ? 443 : 80)];
            $siteParts = parse_url($site);
            foreach ($ctx['verified_pages'] ?? [] as $observed) {
                if (!is_array($observed) || ($observed['ok'] ?? false) !== true || (int) ($observed['http_status'] ?? 0) !== 200 || !empty($observed['coming_soon'])) continue;
                $parentUrl = (string) ($observed['final_url'] ?? $observed['url'] ?? '');
                $parts = parse_url($parentUrl);
                if (!$siteParts || !$parts || !filter_var($parentUrl, FILTER_VALIDATE_URL) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                    || $origin($parts) !== $origin($siteParts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) continue;
                $name = self::clean((string) (($observed['h1'] ?? '') ?: ($observed['title'] ?? '')), 110);
                if ($name !== '') $parents[rtrim($parts['path'] ?? '/', '/')] = ['url' => $parentUrl, 'name' => $name];
            }
            $items = [['@type' => 'ListItem', 'position' => 1, 'name' => $business !== '' ? $business : 'Home', 'item' => $site . '/']];
            $acc = '';
            foreach ($segments as $i => $seg) {
                $acc .= '/' . $seg;
                $last = $i === count($segments) - 1;
                if (!$last && (!isset($parents[$acc]) || count($items) >= 5)) continue;
                $name = $last ? self::clean((string) (($page['h1'] ?? '') ?: ($page['title'] ?? '')), 110) : $parents[$acc]['name'];
                if ($name === '' || count($items) >= 6) {
                    continue;
                }
                $item = ['@type' => 'ListItem', 'position' => count($items) + 1, 'name' => $name];
                if (!$last) {
                    $item['item'] = $parents[$acc]['url'];
                } else {
                    $item['item'] = $url;
                }
                $items[] = $item;
            }
            if (count($items) >= 2) {
                $graph[] = ['@type' => 'BreadcrumbList', '@id' => $url . '#breadcrumb', 'itemListElement' => $items];
                $types[] = 'BreadcrumbList';
            }
        }

        if (!$graph) {
            return null;
        }
        return ['graph' => ['@context' => 'https://schema.org', '@graph' => $graph], 'types' => $types];
    }

    // ------------------------------------------------------------------ مواقع منشئ المنصة (عندنا البيانات كاملة)

    /** TravelAgency + الموقع + قايمة الرحلات للصفحة الرئيسية */
    public static function forBuilderHome(array $c, string $siteUrl, array $ratings = []): array
    {
        $site = rtrim($siteUrl, '/');
        $name = self::clean((string) ($c['business_name'] ?? ''), 120);
        $contact = $c['contact'] ?? [];
        $agency = ['@type' => 'TravelAgency', '@id' => $site . '/#org', 'name' => $name, 'url' => $site . '/'];
        if (!empty($c['about_text'])) {
            $agency['description'] = self::clean((string) $c['about_text'], 300);
        }
        if (!empty($contact['phone'])) {
            $agency['telephone'] = self::clean((string) $contact['phone'], 40);
        }
        if (!empty($contact['email']) && filter_var($contact['email'], FILTER_VALIDATE_EMAIL)) {
            $agency['email'] = (string) $contact['email'];
        }
        if (!empty($contact['address'])) {
            $agency['address'] = self::clean((string) $contact['address'], 200);
        }
        if (!empty($c['logo_url']) && preg_match('#^https://#', (string) $c['logo_url'])) {
            $agency['logo'] = (string) $c['logo_url'];
        }
        $rating = self::rating($ratings);
        if ($rating) {
            $agency['aggregateRating'] = $rating;
        }
        $list = [];
        foreach (array_slice($c['tours'] ?? [], 0, 30) as $i => $t) {
            if (empty($t['name']) || empty($t['slug'])) {
                continue;
            }
            $list[] = ['@type' => 'ListItem', 'position' => count($list) + 1, 'url' => $site . '/tours/' . rawurlencode((string) $t['slug']), 'name' => self::clean((string) $t['name'], 120)];
        }
        $graph = [$agency, ['@type' => 'WebSite', '@id' => $site . '/#website', 'url' => $site . '/', 'name' => $name, 'publisher' => ['@id' => $site . '/#org']]];
        if ($list) {
            $graph[] = ['@type' => 'ItemList', 'name' => $name . ' tours', 'itemListElement' => $list];
        }
        return ['@context' => 'https://schema.org', '@graph' => $graph];
    }

    /** TouristTrip كامل لصفحة رحلة في منشئ المنصة (البرنامج، السعر، التقييمات الحقيقية) + Breadcrumb */
    public static function forBuilderTour(array $tour, array $c, string $siteUrl, array $ratings = []): array
    {
        $site = rtrim($siteUrl, '/');
        $url = $site . '/tours/' . rawurlencode((string) ($tour['slug'] ?? ''));
        $name = self::clean((string) ($tour['name'] ?? ''), 150);
        $business = self::clean((string) ($c['business_name'] ?? ''), 120);
        $trip = ['@type' => 'TouristTrip', '@id' => $url . '#trip', 'name' => $name, 'url' => $url, 'provider' => ['@type' => 'TravelAgency', 'name' => $business, 'url' => $site . '/']];
        $desc = self::clean((string) ($tour['short_description'] ?? $tour['description'] ?? ''), 300);
        if ($desc !== '') {
            $trip['description'] = $desc;
        }
        if (!empty($tour['image_url']) && preg_match('#^https?://#', (string) $tour['image_url'])) {
            $trip['image'] = (string) $tour['image_url'];
        }
        $stops = [];
        foreach (array_slice($tour['itinerary'] ?? [], 0, 20) as $d) {
            $t = self::clean((string) ($d['title'] ?? ''), 120);
            if ($t !== '') {
                $stops[] = ['@type' => 'ListItem', 'position' => count($stops) + 1, 'item' => ['@type' => 'TouristAttraction', 'name' => $t, 'description' => self::clean((string) ($d['description'] ?? ''), 200) ?: null]];
            }
        }
        if ($stops) {
            $stops = array_map(static function ($x) { $x['item'] = array_filter($x['item'], static fn ($v) => $v !== null); return $x; }, $stops);
            $trip['itinerary'] = ['@type' => 'ItemList', 'numberOfItems' => count($stops), 'itemListElement' => $stops];
        }
        $price = self::price(['text' => (string) ($tour['price'] ?? '')]);
        if ($price && !isset($price['low'])) {
            $trip['offers'] = ['@type' => 'Offer', 'price' => $price['price'], 'priceCurrency' => $price['currency'], 'url' => $url, 'availability' => 'https://schema.org/InStock'];
        }
        $rating = self::rating($ratings);
        if ($rating) {
            $trip['aggregateRating'] = $rating;
        }
        $crumbs = ['@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => $business ?: 'Home', 'item' => $site . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $name, 'item' => $url],
        ]];
        return ['@context' => 'https://schema.org', '@graph' => [$trip, $crumbs]];
    }

    /** متوسط التقييمات الحقيقية المعتمدة (من 3 تقييمات فأكتر بس) */
    public static function rating(array $ratings): ?array
    {
        $ratings = array_values(array_filter(array_map('intval', $ratings), static fn ($r) => $r >= 1 && $r <= 5));
        if (count($ratings) < 3) {
            return null;
        }
        return ['@type' => 'AggregateRating', 'ratingValue' => round(array_sum($ratings) / count($ratings), 1), 'reviewCount' => count($ratings), 'bestRating' => 5, 'worstRating' => 1];
    }

    /** وسم الـ JSON-LD جاهز للحقن في الصفحة */
    public static function scriptTag(array $graph): string
    {
        return '<script type="application/ld+json">' . json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
    }

    /** التحقق من نوع كل عنصر قبل النشر (بيستخدمه EdgeRules) */
    public static function typesAllowed(array $j): bool
    {
        $nodes = isset($j['@graph']) && is_array($j['@graph']) ? $j['@graph'] : [$j];
        if (!$nodes || count($nodes) > 10) {
            return false;
        }
        $ok = static function ($node) use (&$ok): bool {
            if (!is_array($node)) {
                return true;
            }
            if (isset($node['@type'])) {
                foreach ((array) $node['@type'] as $t) {
                    if (!in_array((string) $t, self::TYPES, true)) {
                        return false;
                    }
                }
            }
            foreach ($node as $k => $v) {
                if (is_array($v) && !$ok($v)) {
                    return false;
                }
            }
            return true;
        };
        foreach ($nodes as $n) {
            if (!is_array($n) || empty($n['@type']) || !$ok($n)) {
                return false;
            }
        }
        return true;
    }
}

<?php
/**
 * تحقق من JSON-LD قبل النشر: بنية، أنواع، وخصائص مطلوبة/موصى بيها لأنواع السياحة.
 * منطق صرف (بلا شبكة ولا قاعدة بيانات). القواعد مبنية على متطلبات Google للـrich results،
 * وده فحص مساعد مش ضمان ظهور نتيجة مميزة في البحث.
 * كل ملاحظة: severity (error|warning)، code ثابت، path، ورسالة عربي/إنجليزي.
 */
final class SeoSchemaValidator
{
    private const MAX_BYTES = 200000;
    private const MAX_NODES = 10;

    /** type => [required[], recommended[]]. required ناقصة = error، recommended ناقصة = warning. */
    private const RULES = [
        'TouristTrip' => [['name'], ['description', 'image', 'offers', 'provider']],
        'Hotel' => [['name'], ['address', 'image', 'priceRange', 'telephone', 'aggregateRating']],
        'LodgingBusiness' => [['name'], ['address', 'image', 'priceRange', 'telephone', 'aggregateRating']],
        'LocalBusiness' => [['name'], ['address', 'telephone', 'image', 'openingHours']],
        'TravelAgency' => [['name'], ['address', 'telephone', 'image', 'url']],
        'Organization' => [['name'], ['url', 'logo']],
        'Product' => [['name'], ['image', 'description']],
        'Offer' => [['price', 'priceCurrency'], ['availability', 'url']],
        'AggregateOffer' => [['lowPrice', 'priceCurrency'], ['highPrice', 'offerCount']],
        'AggregateRating' => [['ratingValue'], []],
        'Review' => [['reviewRating', 'author'], ['datePublished']],
        'Article' => [['headline'], ['image', 'datePublished', 'author']],
        'FAQPage' => [['mainEntity'], []],
        'HowTo' => [['name', 'step'], ['totalTime', 'image']],
        'Question' => [['name', 'acceptedAnswer'], []],
        'Answer' => [['text'], []],
        'HowToStep' => [['text'], ['name']],
        'BreadcrumbList' => [['itemListElement'], []],
        'ListItem' => [['position', 'name'], []],
        'PostalAddress' => [[], ['streetAddress', 'addressLocality', 'addressCountry']],
    ];

    private const MSG = [
        'invalid_json' => ['JSON-LD مش صالح (خطأ في الصياغة)', 'Invalid JSON-LD (syntax error)'],
        'too_large' => ['الحجم أكبر من المسموح', 'Payload is too large'],
        'html_inside' => ['ممنوع وسوم HTML أو سكربت جوه JSON-LD', 'HTML or script tags are not allowed inside JSON-LD'],
        'bad_context' => ['@context لازم يكون https://schema.org', '@context must be https://schema.org'],
        'no_nodes' => ['مفيش عناصر Schema', 'No schema nodes found'],
        'too_many_nodes' => ['عدد العناصر أكتر من الحد المسموح للنشر الآلي (10)', 'More nodes than automated publishing allows (10)'],
        'missing_type' => ['عنصر من غير @type', 'Node has no @type'],
        'unknown_type' => ['النوع مش مدعوم للنشر الآلي حاليًا', 'Type is not supported for automated publishing yet'],
        'missing_required' => ['خاصية مطلوبة ناقصة', 'Required property is missing'],
        'missing_recommended' => ['خاصية موصى بيها ناقصة', 'Recommended property is missing'],
        'bad_price' => ['السعر لازم يكون رقم أكبر من أو يساوي صفر', 'Price must be a number greater than or equal to zero'],
        'bad_currency' => ['العملة لازم تكون كود ISO من 3 حروف (مثل USD)', 'Currency must be a 3-letter ISO code (e.g. USD)'],
        'bad_rating' => ['قيمة التقييم لازم تكون رقم', 'Rating value must be numeric'],
        'rating_count' => ['AggregateRating محتاج reviewCount أو ratingCount', 'AggregateRating needs reviewCount or ratingCount'],
        'empty_list' => ['القائمة فاضية', 'The list is empty'],
        'product_needs_offer' => ['Google بيطلب offers أو review أو aggregateRating للمنتج', 'Google requires offers, review or aggregateRating for a product'],
        'bad_position' => ['position لازم يكون رقم صحيح متسلسل يبدأ من 1', 'position must be sequential integers starting at 1'],
        'bad_url' => ['الرابط لازم يكون https صالح', 'URL must be a valid https URL'],
    ];

    /** @param string|array $input نص JSON-LD (ممكن جوه وسم script) أو مصفوفة جاهزة */
    public static function validate($input): array
    {
        $issues = [];
        $data = $input;
        if (is_string($input)) {
            $text = trim($input);
            if (strlen($text) > self::MAX_BYTES) {
                return self::result([self::issue('error', 'too_large', '$')], []);
            }
            if (preg_match('#^<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*)</script>$#is', $text, $m)) {
                $text = trim($m[1]);
            }
            if (preg_match('#<\s*/?\s*(script|iframe|object|embed|img|svg)\b#i', $text)) {
                return self::result([self::issue('error', 'html_inside', '$')], []);
            }
            $data = json_decode($text, true);
            if (!is_array($data)) {
                return self::result([self::issue('error', 'invalid_json', '$')], []);
            }
        }

        $ctx = $data['@context'] ?? null;
        if (!is_string($ctx) || !preg_match('#^https?://schema\.org/?$#i', $ctx)) {
            $issues[] = self::issue('error', 'bad_context', '@context');
        }
        $nodes = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : [$data];
        if (!$nodes || (count($nodes) === 1 && !array_filter($nodes[0], static fn ($v, $k) => $k !== '@context', ARRAY_FILTER_USE_BOTH))) {
            return self::result(array_merge($issues, [self::issue('error', 'no_nodes', '$')]), []);
        }
        if (count($nodes) > self::MAX_NODES) {
            $issues[] = self::issue('error', 'too_many_nodes', '@graph');
        }
        $types = [];
        foreach (array_values($nodes) as $i => $node) {
            if (is_array($node)) {
                self::node($node, isset($data['@graph']) ? "@graph[$i]" : '$', $issues, $types, true);
            }
        }
        return self::result($issues, array_values(array_unique($types)));
    }

    private static function node(array $node, string $path, array &$issues, array &$types, bool $top): void
    {
        if (!isset($node['@type'])) {
            if ($top) {
                $issues[] = self::issue('error', 'missing_type', $path);
            }
            self::children($node, $path, $issues, $types);
            return;
        }
        foreach ((array) $node['@type'] as $type) {
            $type = (string) $type;
            $types[] = $type;
            if (!in_array($type, SeoSchemaBuilder::TYPES, true)) {
                $issues[] = self::issue('warning', 'unknown_type', $path . '.@type', $type);
                continue;
            }
            [$required, $recommended] = self::RULES[$type] ?? [[], []];
            foreach ($required as $prop) {
                if (self::empty($node[$prop] ?? null)) {
                    $issues[] = self::issue('error', 'missing_required', "$path.$prop", "$type.$prop");
                }
            }
            foreach ($recommended as $prop) {
                if (self::empty($node[$prop] ?? null)) {
                    $issues[] = self::issue('warning', 'missing_recommended', "$path.$prop", "$type.$prop");
                }
            }
            self::typeChecks($type, $node, $path, $issues);
        }
        self::children($node, $path, $issues, $types);
    }

    private static function children(array $node, string $path, array &$issues, array &$types): void
    {
        foreach ($node as $key => $value) {
            if (!is_array($value) || $key === '@type') {
                continue;
            }
            if (array_is_list($value)) {
                foreach ($value as $i => $child) {
                    if (is_array($child)) {
                        self::node($child, "{$path}.{$key}[{$i}]", $issues, $types, false);
                    }
                }
            } else {
                self::node($value, "$path.$key", $issues, $types, false);
            }
        }
    }

    private static function typeChecks(string $type, array $n, string $path, array &$issues): void
    {
        foreach (['price', 'lowPrice', 'highPrice'] as $p) {
            if (isset($n[$p]) && (!is_numeric($n[$p]) || (float) $n[$p] < 0)) {
                $issues[] = self::issue('error', 'bad_price', "$path.$p");
            }
        }
        if (isset($n['priceCurrency']) && !preg_match('/^[A-Z]{3}$/', (string) $n['priceCurrency'])) {
            $issues[] = self::issue('error', 'bad_currency', "$path.priceCurrency");
        }
        foreach (['url', 'image', 'logo'] as $p) {
            if (isset($n[$p]) && is_string($n[$p]) && $n[$p] !== '' && !preg_match('#^https://[^\s"<>]{4,2000}$#i', $n[$p])) {
                $issues[] = self::issue('warning', 'bad_url', "$path.$p");
            }
        }
        if ($type === 'AggregateRating') {
            if (isset($n['ratingValue']) && !is_numeric($n['ratingValue'])) {
                $issues[] = self::issue('error', 'bad_rating', "$path.ratingValue");
            }
            if (self::empty($n['reviewCount'] ?? null) && self::empty($n['ratingCount'] ?? null)) {
                $issues[] = self::issue('error', 'rating_count', $path);
            }
        }
        if ($type === 'Product' && self::empty($n['offers'] ?? null) && self::empty($n['review'] ?? null) && self::empty($n['aggregateRating'] ?? null)) {
            $issues[] = self::issue('error', 'product_needs_offer', $path);
        }
        if (in_array($type, ['FAQPage'], true) && isset($n['mainEntity']) && is_array($n['mainEntity']) && !$n['mainEntity']) {
            $issues[] = self::issue('error', 'empty_list', "$path.mainEntity");
        }
        if ($type === 'HowTo' && isset($n['step']) && is_array($n['step']) && !$n['step']) {
            $issues[] = self::issue('error', 'empty_list', "$path.step");
        }
        if ($type === 'BreadcrumbList' && isset($n['itemListElement']) && is_array($n['itemListElement'])) {
            $pos = array_map(static fn ($e) => is_array($e) ? ($e['position'] ?? null) : null, array_values($n['itemListElement']));
            if (!$pos) {
                $issues[] = self::issue('error', 'empty_list', "$path.itemListElement");
            } elseif ($pos !== range(1, count($pos))) {
                $issues[] = self::issue('error', 'bad_position', "$path.itemListElement");
            }
        }
    }

    private static function empty($v): bool
    {
        return $v === null || $v === '' || $v === [];
    }

    private static function issue(string $severity, string $code, string $path, string $detail = ''): array
    {
        [$ar, $en] = self::MSG[$code];
        return ['severity' => $severity, 'code' => $code, 'path' => $path, 'detail' => $detail, 'message_ar' => $ar, 'message_en' => $en];
    }

    private static function result(array $issues, array $types): array
    {
        $errors = count(array_filter($issues, static fn ($i) => $i['severity'] === 'error'));
        return ['valid' => $errors === 0, 'publishable' => $errors === 0, 'errors' => $errors, 'warnings' => count($issues) - $errors,
            'types' => $types, 'issues' => $issues,
            'note' => 'Checks structure and Google rich-result requirements; passing does not guarantee a rich result.'];
    }
}

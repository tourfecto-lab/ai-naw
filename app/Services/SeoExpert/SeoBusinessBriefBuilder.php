<?php

/**
 * Tourfecto - بناء "تعريف النشاط" تلقائيًا من صفحات موقع العميل
 *
 * بدل ما نلزق نص الصفحات زي ما هو، بنطلع معلومات منظمة: الاسم، النبذة،
 * الرحلات/الخدمات، الوجهات، التواصل، اللغات والسوشيال - من الـ Schema
 * والعناوين والروابط الحقيقية في الموقع. مفيش أي معلومة متخترعة: كل سطر
 * جاي من صفحة اتقرت، والعميل بيراجع قبل ما يأكد.
 *
 * منطق خالص بدون DB أو شبكة.
 */
class SeoBusinessBriefBuilder
{
    /** كلمات بتدل على صفحات مهمة لفهم النشاط - بالترتيب */
    private const PRIORITY = [
        'about' => 10, 'who-we-are' => 10, 'من-نحن' => 10,
        'tour' => 9, 'trip' => 9, 'package' => 9, 'excursion' => 9, 'cruise' => 9, 'رحلات' => 9,
        'destination' => 8, 'service' => 8, 'خدمات' => 8,
        'contact' => 7, 'اتصل' => 7,
        'faq' => 5, 'blog' => 2,
    ];

    /** ترتيب روابط الصفحة الرئيسية: الصفحات اللي بتشرح النشاط الأول */
    public static function rankLinks(array $links): array
    {
        $scored = [];
        foreach ($links as $i => $link) {
            $url = is_string($link) ? $link : (string) ($link['url'] ?? '');
            $hay = strtolower($url . ' ' . (is_array($link) ? (string) ($link['title'] ?? '') : ''));
            $score = 0;
            foreach (self::PRIORITY as $needle => $points) {
                if (strpos($hay, $needle) !== false) {
                    $score = max($score, $points);
                }
            }
            $scored[] = ['url' => $url, 'score' => $score, 'i' => $i];
        }
        usort($scored, static fn ($a, $b) => [$b['score'], $a['i']] <=> [$a['score'], $b['i']]);
        return array_column($scored, 'url');
    }

    /**
     * @param array $contexts الصفحة الرئيسية أولًا ثم باقي الصفحات (ناتج PageContext)
     * @return array{business_name:string,business_facts:string,audience:string,goal:string}
     */
    public static function build(array $contexts, string $fallbackName, bool $ar): array
    {
        $home = $contexts[0] ?? [];
        $L = static fn (string $a, string $e): string => $ar ? $a : $e;
        $sep = $ar ? '، ' : ', ';

        // --- الهوية من الـ Schema
        $org = null;
        $offers = [];
        $places = [];
        $faqs = [];
        $prices = [];
        foreach ($contexts as $ctx) {
            self::walkSchema($ctx['schema'] ?? [], $org, $offers, $places, $faqs, $prices);
        }
        $name = trim((string) ($org['name'] ?? ($home['site_name'] ?? '')));
        if ($name === '') {
            $name = trim(preg_replace('/\s*[|\-–—].*$/u', '', (string) ($home['title'] ?? '')));
        }
        if ($name === '') {
            $name = $fallbackName;
        }

        $lines = [];

        // --- نبذة
        $about = trim((string) ($org['description'] ?? ($home['description'] ?? '')));
        if ($about !== '') {
            $lines[] = $L('نبذة: ', 'About: ') . $about;
        }

        // --- الخدمات / الرحلات: من الـ Schema ثم عناوين الصفحات المهمة ثم روابط القائمة
        $services = $offers;
        $tours = [];
        foreach (array_slice($contexts, 1) as $ctx) {
            $h1 = trim((string) ($ctx['h1'] ?? ''));
            if ($h1 !== '' && mb_strlen($h1) <= 90) {
                $services[] = $h1;
            }
            // أسماء الرحلات من كروت صفحات الأقسام
            foreach ($ctx['headings'] ?? [] as $heading) {
                if (self::looksLikeTrip((string) $heading)) {
                    $tours[] = $heading;
                }
            }
        }
        foreach ($home['links'] ?? [] as $link) {
            $title = is_array($link) ? trim((string) ($link['title'] ?? '')) : '';
            if ($title !== '' && preg_match('/tour|trip|cruise|package|excursion|safari|رحل|جول|كروز|باق/iu', $title)) {
                $services[] = $title;
            }
        }
        $services = self::unique(array_filter($services, [self::class, 'isRealService']), 12);
        if ($services) {
            $lines[] = $L('الخدمات: ', 'Services: ') . implode($sep, $services);
        }
        $tours = self::unique(array_filter($tours, [self::class, 'isRealService']), 10);

        // --- الوجهات: من الـ Schema ومن روابط زي /tours/luxor أو /destinations/aswan
        foreach ($contexts as $ctx) {
            $urls = [(string) ($ctx['final_url'] ?? '')];
            foreach ($ctx['links'] ?? [] as $link) {
                $urls[] = is_array($link) ? (string) ($link['url'] ?? '') : (string) $link;
            }
            foreach ($urls as $u) {
                if (preg_match('#/(?:tours|destinations?|trips|excursions)/([a-z][a-z\-]{2,24})/?$#i', (string) parse_url($u, PHP_URL_PATH), $dm)
                    && !preg_match('/^(all|search|book|booking|private|group|day|cheap|best|new|popular)|cruise|safari|balloon|diving|snorkel|transfer|package/i', $dm[1])) {
                    $places[] = ucwords(str_replace('-', ' ', strtolower($dm[1])));
                }
            }
        }
        $places = self::unique($places, 10);
        if ($places) {
            $lines[] = $L('الوجهات: ', 'Destinations: ') . implode($sep, $places);
        }

        if ($tours) {
            $lines[] = $L('رحلات معروضة على الموقع: ', 'Tours listed on the site: ') . implode($sep, $tours);
        }

        // --- التواصل والعنوان
        $contact = [];
        $phones = $emails = $whats = [];
        foreach ($contexts as $ctx) {
            $phones = array_merge($phones, $ctx['contacts']['phones'] ?? []);
            $emails = array_merge($emails, $ctx['contacts']['emails'] ?? []);
            $whats = array_merge($whats, $ctx['contacts']['whatsapp'] ?? []);
        }
        if (!empty($org['telephone']) && is_string($org['telephone'])) {
            array_unshift($phones, $org['telephone']);
        }
        if (!empty($org['email']) && is_string($org['email'])) {
            array_unshift($emails, $org['email']);
        }
        if ($phones = self::unique($phones, 2)) {
            $contact[] = $L('تليفون ', 'Phone ') . implode(' / ', $phones);
        }
        if ($whats = self::unique($whats, 1)) {
            $contact[] = $L('واتساب ', 'WhatsApp ') . $whats[0];
        }
        if ($emails = self::unique($emails, 2)) {
            $contact[] = $L('إيميل ', 'Email ') . implode(' / ', $emails);
        }
        $address = self::address($org['address'] ?? null);
        if (mb_strlen($address) > 3) {
            $contact[] = $L('العنوان ', 'Address ') . $address;
        }
        if ($contact) {
            $lines[] = $L('التواصل: ', 'Contact: ') . implode(' · ', $contact);
        }

        // --- أسئلة شائعة موجودة فعلًا على الموقع (معلومات مؤكدة من صاحب النشاط)
        if ($faqs = self::unique($faqs, 5)) {
            $lines[] = $L('أسئلة وإجابات من الموقع:', 'FAQ from the site:') . "\n- " . implode("\n- ", $faqs);
        }

        // --- السوشيال
        $social = [];
        foreach ($contexts as $ctx) {
            $social = array_merge($social, $ctx['social'] ?? []);
        }
        if ($social = self::unique($social, 6)) {
            $lines[] = $L('السوشيال: ', 'Social: ') . implode(' ', $social);
        }

        // --- لو مفيش بيانات منظمة كفاية، نكمّل بمقتطف قصير من نص الصفحة الرئيسية
        if (count($lines) < 3 && !empty($home['text'])) {
            $lines[] = $L('من الصفحة الرئيسية: ', 'From the homepage: ') . mb_substr(trim((string) $home['text']), 0, 600);
        }

        // --- الجمهور: من لغة الموقع
        $langs = [];
        foreach ($contexts as $ctx) {
            $lang = strtolower(substr((string) ($ctx['lang'] ?? ''), 0, 2));
            if ($lang !== '') {
                $langs[$lang] = $lang;
            }
        }
        $langNames = ['en' => ['الإنجليزي', 'English'], 'ar' => ['العربي', 'Arabic'], 'de' => ['الألماني', 'German'], 'fr' => ['الفرنساوي', 'French'],
            'es' => ['الإسباني', 'Spanish'], 'it' => ['الإيطالي', 'Italian'], 'ru' => ['الروسي', 'Russian'], 'zh' => ['الصيني', 'Chinese']];
        $spoken = array_map(static fn ($l) => $langNames[$l][$ar ? 0 : 1] ?? $l, array_values($langs));
        // دول مقترحة حسب لغة الموقع - اقتراح واضح إن العميل يعدّله
        $markets = ['en' => [['أمريكا', 'بريطانيا', 'كندا', 'أستراليا'], ['United States', 'United Kingdom', 'Canada', 'Australia']],
            'de' => [['ألمانيا', 'النمسا', 'سويسرا'], ['Germany', 'Austria', 'Switzerland']],
            'fr' => [['فرنسا', 'بلجيكا', 'كندا'], ['France', 'Belgium', 'Canada']],
            'es' => [['إسبانيا', 'المكسيك', 'الأرجنتين'], ['Spain', 'Mexico', 'Argentina']],
            'it' => [['إيطاليا'], ['Italy']], 'ru' => [['روسيا'], ['Russia']], 'zh' => [['الصين'], ['China']],
            'ar' => [['السعودية', 'الإمارات', 'الكويت', 'مصر'], ['Saudi Arabia', 'UAE', 'Kuwait', 'Egypt']]];
        $countries = [];
        foreach (array_values($langs) as $lang) {
            foreach ($markets[$lang][$ar ? 0 : 1] ?? [] as $c) {
                $countries[$c] = $c;
            }
        }
        $interest = $services ? implode($sep, array_slice($services, 0, 3)) : $L('الخدمات المعروضة', 'the listed services');
        $audience = $spoken
            ? $L('مسافرين بيتكلموا ', 'Travelers who speak ') . implode($L(' و', ' and '), $spoken) . $L(' ومهتمين بـ ', ' interested in ') . $interest . '.'
            : $L('مسافرين مهتمين بـ ', 'Travelers interested in ') . $interest . '.';
        $audience .= $countries
            ? $L(' دول مقترحة (عدّلها): ', ' Suggested countries (edit as needed): ') . implode($sep, array_values($countries)) . '.'
            : $L(' حدّد الدول المستهدفة.', ' Add your target countries.');

        // --- الهدف: حجز مباشر لو الموقع فيه حجز، وإلا استفسارات
        $allText = '';
        foreach ($contexts as $ctx) {
            $allText .= ' ' . ($ctx['text'] ?? '');
            foreach ($ctx['links'] ?? [] as $link) {
                $allText .= ' ' . (is_array($link) ? ($link['url'] ?? '') . ' ' . ($link['title'] ?? '') : $link);
            }
        }
        $hasBooking = (bool) preg_match('/book now|booking|checkout|reserve|احجز|حجز/iu', $allText);
        $goal = $hasBooking
            ? $L('زيادة الحجوزات المباشرة من الموقع', 'Increase direct bookings through the website')
            : $L('زيادة الاستفسارات المناسبة للخدمات المعروضة', 'Increase relevant inquiries for the listed services');
        if ($whats) {
            $goal .= $L(' ورسايل واتساب', ' and WhatsApp inquiries');
        }

        // --- حقول ملف الشركة: تليفون، إيميل، رابط الحجز، اللوجو، الأسعار المعلنة
        $siteUrl = (string) ($home['final_url'] ?? '');
        $cta = '';
        foreach ($contexts as $ctx) {
            foreach ($ctx['links'] ?? [] as $link) {
                $u = is_array($link) ? (string) ($link['url'] ?? '') : (string) $link;
                $title = is_array($link) ? (string) ($link['title'] ?? '') : '';
                if ($u !== '' && preg_match('#book|reserv|checkout|احجز|حجز#iu', $u . ' ' . $title)) {
                    $cta = $u;
                    break 2;
                }
            }
        }
        if ($cta === '' && $whats) {
            $cta = 'https://wa.me/' . ltrim($whats[0], '+');
        }
        if ($cta === '') {
            foreach ($home['links'] ?? [] as $link) {
                $u = is_array($link) ? (string) ($link['url'] ?? '') : (string) $link;
                if (preg_match('#contact|اتصل#iu', $u . ' ' . (is_array($link) ? ($link['title'] ?? '') : ''))) {
                    $cta = $u;
                    break;
                }
            }
        }
        $logo = '';
        $schemaLogo = $org['logo'] ?? '';
        if (is_array($schemaLogo)) {
            $schemaLogo = $schemaLogo['url'] ?? '';
        }
        $logoCandidates = $home['logo_candidates'] ?? [];
        // اللوجو الحقيقي قبل أيقونة المتصفح (favicon .ico)
        // ترتيب: لوجو حقيقي، ثم apple-touch-icon (كبير)، ثم أيقونات كبيرة، وأخيرًا الصغيرة و .ico
        $rank = static function (string $u): int {
            if (preg_match('/logo/i', $u)) { return 0; }
            if (preg_match('/apple-touch|180|192|256|512/i', $u)) { return 1; }
            if (preg_match('/\.ico(\?|$)|favicon|(?:^|[^0-9])(?:16|32|48)(?:[^0-9]|$)/i', $u)) { return 3; }
            return 2;
        };
        usort($logoCandidates, static fn ($a, $b) => $rank((string) $a) <=> $rank((string) $b));
        // أيقونة 32 بكسل مش لوجو: أحسن الخانة تفضل فاضية والعميل يحط لوجو حقيقي
        $logoCandidates = array_values(array_filter($logoCandidates, static fn ($u) => $rank((string) $u) < 3));
        foreach (array_merge([(string) $schemaLogo, (string) ($home['logo'] ?? '')], $logoCandidates) as $candidate) {
            if (preg_match('#^https://#i', (string) $candidate)) {
                $logo = $candidate;
                break;
            }
        }

        // أسعار مكتوبة في كروت الرحلات (لو مفيش في الـ Schema) - السعر زي ما هو على الموقع
        $cardPrices = [];
        foreach ($contexts as $ctx) {
            foreach ($ctx['card_prices'] ?? [] as $cp) {
                if (self::looksLikeTrip((string) $cp)) {
                    $cardPrices[] = $cp . $L(' (زي ما هو على الموقع — ضيف تاريخ انتهاء العرض)', ' (as shown on the site — add validity date)');
                }
            }
        }

        return [
            'phone' => $phones[0] ?? ($whats[0] ?? ''),
            'email' => $emails[0] ?? '',
            'cta' => mb_substr($cta, 0, 300),
            'logo_url' => mb_substr($logo, 0, 500),
            'offers' => mb_substr(implode("\n", self::unique($prices ?: $cardPrices, 10)), 0, 1500),
            'business_name' => mb_substr(strip_tags($name), 0, 150),
            'business_facts' => mb_substr(implode("\n", $lines), 0, 2500),
            'audience' => mb_substr($audience, 0, 700),
            'goal' => mb_substr($goal . '.', 0, 700),
        ];
    }

    /** عناوين الأزرار والفلاتر مش خدمات ("All Tours", "Find my tour" ...) */
    public static function isRealService($text): bool
    {
        $t = mb_strtolower(trim((string) $text));
        return $t !== '' && !preg_match('/^(all|our|view|see|browse|find|search|explore|discover|book now|more|popular|featured|latest|top)\b|\b(quiz|filter|sort by|results?)\b|^(tours?|trips?|services?|home|contact|about|blog|faq)$/iu', $t);
    }

    /** عنوان كارت شكله اسم رحلة */
    private static function looksLikeTrip(string $text): bool
    {
        if (preg_match('/^(choose|select|find|filter|search|pick|browse|view|see|all)\b/i', trim($text))) {
            return false;
        }
        return mb_strlen($text) >= 8
            && (bool) preg_match('/tour|trip|cruise|excursion|safari|day|night|temple|pyramid|balloon|felucca|valley|museum|snorkel|diving|desert|oasis|رحل|جول|كروز|معبد|أهرام|سفاري|منطاد/iu', $text)
            && !preg_match('/\?|why|how|what|reviews?|testimonials?|contact|newsletter|faq|لماذا|إزاي|آراء/iu', $text);
    }

    private static function walkSchema($node, ?array &$org, array &$offers, array &$places, array &$faqs, array &$prices): void
    {
        if (!is_array($node)) {
            return;
        }
        $types = array_map('strval', (array) ($node['@type'] ?? []));
        $name = isset($node['name']) && is_string($node['name']) ? trim($node['name']) : '';
        if ($org === null && $name !== '' && array_intersect($types, ['Organization', 'LocalBusiness', 'TravelAgency', 'TourOperator', 'Hotel'])) {
            $org = $node;
        }
        if ($name !== '' && array_intersect($types, ['TouristTrip', 'Product', 'Service', 'Offer', 'Trip'])) {
            $offers[] = $name;
        }
        // سعر معلن في الـ Schema بتاع الموقع نفسه (العميل يأكد ويضيف تاريخ الصلاحية)
        if ($name !== '' && array_intersect($types, ['TouristTrip', 'Product', 'Service', 'Trip'])) {
            $offer = $node['offers'] ?? null;
            if (is_array($offer) && isset($offer[0]) && is_array($offer[0])) {
                $offer = $offer[0];
            }
            $price = is_array($offer) ? ($offer['price'] ?? ($offer['lowPrice'] ?? null)) : null;
            if (is_scalar($price) && is_numeric($price) && (float) $price > 0) {
                $cur = is_array($offer) && is_string($offer['priceCurrency'] ?? null) ? $offer['priceCurrency'] : '';
                $valid = is_array($offer) && is_string($offer['priceValidUntil'] ?? null) ? ' (valid until ' . $offer['priceValidUntil'] . ')' : '';
                $prices[] = $name . ' — ' . trim($price . ' ' . $cur) . $valid;
            }
        }
        if ($name !== '' && array_intersect($types, ['TouristDestination', 'TouristAttraction', 'City', 'Place', 'LandmarksOrHistoricalBuildings'])) {
            $places[] = $name;
        }
        if (in_array('Question', $types, true) && $name !== '') {
            $answer = $node['acceptedAnswer']['text'] ?? '';
            if (is_string($answer) && $answer !== '') {
                $faqs[] = $name . ' — ' . mb_substr(trim(strip_tags($answer)), 0, 220);
            }
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                self::walkSchema($value, $org, $offers, $places, $faqs, $prices);
            }
        }
    }

    private static function address($a): string
    {
        if (is_string($a)) {
            return trim($a);
        }
        if (!is_array($a)) {
            return '';
        }
        $parts = [];
        foreach (['streetAddress', 'addressLocality', 'addressRegion', 'addressCountry'] as $k) {
            $v = $a[$k] ?? '';
            if (is_array($v)) {
                $v = $v['name'] ?? '';
            }
            if (is_string($v) && trim($v) !== '') {
                $parts[] = trim($v);
            }
        }
        return implode(', ', array_unique($parts));
    }

    private static function unique(array $items, int $max): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = trim(preg_replace('/\s+/u', ' ', $item));
            $key = mb_strtolower($item);
            if ($item === '' || isset($out[$key])) {
                continue;
            }
            $out[$key] = $item;
            if (count($out) >= $max) {
                break;
            }
        }
        return array_values($out);
    }
}

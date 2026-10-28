<?php

/**
 * Tourfecto - مراجعة صفحات الرحلات زي خبير سيو لشركات السياحة
 *
 * المسافر (وجوجل ومحركات الذكاء الاصطناعي) بيقارن صفحة الرحلة بصفحتها على GetYourGuide وViator:
 * السعر، المدة، البرنامج، المشمول وغير المشمول، نقطة الاستلام، سياسة الإلغاء، الأسئلة الشائعة،
 * التقييمات، وزرار الحجز. الصفحة اللي ناقصها كذا حاجة منهم بتخسر الترتيب والحجز مع بعض.
 * وكمان: الصفحة القصيرة أوي، وربط نسخ الموقع بلغاته (hreflang) للمواقع اللي بكذا لغة.
 *
 * منطق خالص: الإشارات بتتحسب من النص الكامل وقت الفحص (قبل ما يتقص)، والمهام من غير ذكاء اصطناعي.
 */
final class SeoTourContentAudit
{
    /** عنصر => [عربي, إنجليزي, regex بكذا لغة] */
    public const ELEMENTS = [
        'price' => ['السعر (يبدأ من…)', 'Price ("from …")', '/(?:[$€£¥]|usd|eur|gbp|egp|aed|sar|try)\s?\d|\d[\d.,]*\s?(?:[$€£]|usd|eur|gbp|egp|aed|sar|try|جنيه|دولار|يورو|ريال|درهم)\b|\b(?:from|ab|à partir de|desde|da|a partire da)\s+[$€£]?\s?\d|السعر|يبدأ من|per person|pro person|par personne|por persona|a persona|للفرد/iu'],
        'duration' => ['المدة', 'Duration', '/\b\d+(?:[.,]\d)?\s*(?:hours?|hrs?|days?|nights?|stunden|tage|nächte|heures?|jours?|nuits?|horas?|días|noches|ore|giorni|notti|saat|gün)\b|\b(?:full|half)[ -]day\b|\bduration\b|\bdauer\b|\bdurée\b|\bduración\b|\bdurata\b|المدة|ساعات|ساعة|أيام|يوم كامل|نص يوم/iu'],
        'itinerary' => ['البرنامج خطوة بخطوة', 'Itinerary', '/\b(?:itinerary|day\s?1|programme|program|schedule|what (?:you\'ll|you will) do|reiseverlauf|tag\s?1|ablauf|itinéraire|jour\s?1|itinerario|día\s?1|giorno\s?1|programma)\b|البرنامج|اليوم الأول|برنامج الرحلة|خط سير/iu'],
        'included' => ['المشمول في السعر', 'What is included', '/\b(?:includes?|included|inclusions?|what\'s included|inklusive|inbegriffen|im preis enthalten|inclus|incluido|incluye|incluso|compreso)\b|يشمل|المشمول|شامل/iu'],
        'excluded' => ['غير المشمول', 'What is not included', '/\b(?:not included|excludes?|excluded|exclusions?|nicht inbegriffen|nicht enthalten|non inclus|no incluido|non incluso|non compreso)\b|غير شامل|لا يشمل|غير المشمول|مش شامل/iu'],
        'pickup' => ['الاستلام أو نقطة اللقاء', 'Pickup or meeting point', '/\b(?:pick-?up|meeting point|hotel transfer|drop-?off|abholung|treffpunkt|prise en charge|point de rendez-vous|recogida|punto de encuentro|punto d\'incontro|prelievo)\b|الاستلام|نقطة اللقاء|من الفندق|التوصيل/iu'],
        'cancellation' => ['سياسة الإلغاء', 'Cancellation policy', '/\b(?:cancell?ation|refund|stornierung|kostenlos stornieren|annulation|remboursement|cancelación|reembolso|cancellazione|rimborso)\b|الإلغاء|إلغاء|استرداد/iu'],
        'faq' => ['أسئلة شائعة', 'FAQ', '/\b(?:faqs?|frequently asked|questions|häufige fragen|questions fréquentes|preguntas frecuentes|domande frequenti)\b|أسئلة شائعة|الأسئلة الشائعة|أسئلة متكررة/iu'],
        'reviews' => ['تقييمات العملاء على الصفحة', 'Guest reviews on the page', '/\b(?:reviews?|testimonials?|rated|stars|tripadvisor|bewertungen|avis|reseñas|opiniones|recensioni)\b|تقييم|آراء العملاء|قالوا عننا/iu'],
        'booking' => ['زرار الحجز', 'Booking button', '/\b(?:book now|book this|reserve|check availability|buchen|jetzt buchen|réserver|reservar|prenota)\b|احجز|احجز الآن|احجز دلوقتي|طلب حجز/iu'],
    ];

    /** الصفحات اللي بتتصدر في بحث الجولات عادةً فيها 500 كلمة على الأقل */
    public const THIN_WORDS = 300;

    /** الإشارات من النص الكامل للصفحة + روابط hreflang (بتتحسب وقت الفحص) */
    public static function signals(string $text, ?DOMXPath $xp = null): array
    {
        // عناوين الأقسام بتيجي لازقة في اللي بعدها لما الـ HTML مضغوط ("IncludedHotel pick-up", "FAQHow far"):
        // نفصلها قبل البحث عشان حدود الكلمات تشتغل
        $text = preg_replace(['/(\p{Ll})(\p{Lu})/u', '/(\p{Lu})(\p{Lu}\p{Ll})/u', '/(\d)(\p{L})/u'], '$1 $2', $text) ?? $text;
        $words = count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY));
        $has = [];
        foreach (self::ELEMENTS as $key => [, , $re]) {
            $has[$key] = $text !== '' && preg_match($re, $text) === 1;
        }
        $alternates = [];
        if ($xp) {
            foreach ($xp->query('//link[translate(@rel,"ALTERNATE","alternate")="alternate"][@hreflang][@href]') ?: [] as $l) {
                if (count($alternates) >= 40) break;
                $alternates[] = ['lang' => strtolower(trim((string) $l->getAttribute('hreflang'))), 'href' => trim((string) $l->getAttribute('href'))];
            }
            // أسئلة وتقييمات في الـ Schema تتحسب برضه
            foreach ($xp->query('//script[@type="application/ld+json"]') ?: [] as $s) {
                $j = (string) $s->textContent;
                if (stripos($j, 'FAQPage') !== false) $has['faq'] = true;
                if (stripos($j, 'aggregateRating') !== false || stripos($j, '"Review"') !== false) $has['reviews'] = true;
                if (stripos($j, '"offers"') !== false || stripos($j, '"price"') !== false) $has['price'] = true;
            }
        }
        return ['words' => $words, 'has' => $has, 'hreflang' => $alternates];
    }

    /**
     * مهام صفحات الرحلات: كل صفحة ناقصها عنصرين أو أكتر، أو قصيرة أوي.
     * @return array<int,array{page:array,title:string,instructions:string,category:string,missing:array}>
     */
    public static function tourTasks(array $pages, bool $ar, int $limit = 10): array
    {
        $out = [];
        foreach ($pages as $p) {
            $sig = $p['signals'] ?? null;
            if (!is_array($sig) || !class_exists('SeoSchemaBuilder') || !SeoSchemaBuilder::isTourPage($p)) {
                continue;
            }
            if (class_exists('SeoPagePolicy') && !SeoPagePolicy::classify($p)['eligible']) {
                continue;
            }
            // اللي موجود في الـ Schema بيتحسب برضه (الأسئلة والتقييمات والسعر والبرنامج)
            $has = array_merge($sig['has'] ?? [], array_filter(self::schemaHas($p['schema'] ?? [])));
            $missing = array_keys(array_filter($has, static fn ($v) => !$v));
            $thin = (int) ($sig['words'] ?? 0) > 0 && (int) $sig['words'] < self::THIN_WORDS;
            if (count($missing) < 2 && !$thin) {
                continue;
            }
            $names = array_map(static fn ($k) => self::ELEMENTS[$k][$ar ? 0 : 1], $missing);
            $name = trim((string) ($p['h1'] ?: $p['title']));
            $lines = [];
            if ($ar) {
                $lines[] = 'المسافر بيقارن الصفحة دي بنفس الرحلة على GetYourGuide وViator قبل ما يحجز، وجوجل ومحركات الذكاء الاصطناعي بتختار الصفحة اللي بتجاوب على أسئلته كاملة.';
                if ($missing) $lines[] = 'ناقص في الصفحة: ' . implode('، ', $names) . '.';
                if ($thin) $lines[] = 'الصفحة فيها ' . (int) $sig['words'] . ' كلمة بس. صفحات الرحلات اللي بتتصدر غالبًا فيها 500 كلمة أو أكتر: وصف الرحلة، البرنامج، والأسئلة.';
                $lines[] = 'اللي تضيفه (بمعلومات حقيقية بس):';
                foreach ($missing as $k) $lines[] = '• ' . self::advice($k, true);
                $lines[] = 'بعد ما تضيفهم، اضغط «خلصت» والمنصة هتفحص الصفحة تاني وتحدّث الـ Schema (السعر والمدة) لوحدها.';
            } else {
                $lines[] = 'Travellers compare this page with the same trip on GetYourGuide and Viator before booking, and Google and AI assistants pick the page that answers their questions completely.';
                if ($missing) $lines[] = 'Missing on the page: ' . implode(', ', $names) . '.';
                if ($thin) $lines[] = 'The page has only ' . (int) $sig['words'] . ' words. Tour pages that rank usually have 500+: description, itinerary and FAQs.';
                $lines[] = 'Add (real information only):';
                foreach ($missing as $k) $lines[] = '• ' . self::advice($k, false);
                $lines[] = 'When done, press "Done" and the platform re-checks the page and updates the structured data (price, duration) automatically.';
            }
            $out[] = [
                'page' => $p,
                'title' => ($ar ? 'كمّل صفحة الرحلة: ' : 'Complete the tour page: ') . mb_substr($name !== '' ? $name : (string) ($p['page_path'] ?? ''), 0, 80),
                'instructions' => implode("\n", $lines),
                'category' => 'aeo',
                'missing' => $missing,
                'score' => count($missing) + ($thin ? 2 : 0) + (in_array('price', $missing, true) ? 1 : 0) + (in_array('booking', $missing, true) ? 1 : 0),
            ];
        }
        usort($out, static fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($out, 0, $limit);
    }

    /** عناصر موجودة في الـ JSON-LD (FAQPage، aggregateRating، offers، itinerary) */
    public static function schemaHas(array $schema): array
    {
        $json = json_encode($schema) ?: '';
        return [
            'faq' => str_contains($json, 'FAQPage'),
            'reviews' => str_contains($json, 'aggregateRating') || str_contains($json, '"Review"'),
            'price' => str_contains($json, '"offers"') || str_contains($json, '"price"'),
            'itinerary' => str_contains($json, '"itinerary"'),
            'duration' => (bool) preg_match('/"duration"\s*:\s*"P/', $json),
        ];
    }

    private static function advice(string $k, bool $ar): string
    {
        $a = [
            'price' => ['السعر للفرد أو "يبدأ من …" بالعملة اللي بتبيع بيها. الصفحة من غير سعر بتخسر قدام المنافسين، وجوجل مابيعرضش السعر في النتايج.', 'Price per person or "from …" in your selling currency. Pages without a price lose against competitors and cannot show price in Google.'],
            'duration' => ['مدة الرحلة (مثلًا 8 ساعات أو 3 أيام / ليلتين).', 'Trip duration (e.g. 8 hours, or 3 days / 2 nights).'],
            'itinerary' => ['البرنامج بالترتيب: الأماكن والمواعيد التقريبية (اليوم الأول، الثاني…).', 'The itinerary in order: stops and approximate times (Day 1, Day 2…).'],
            'included' => ['قائمة "السعر يشمل": المواصلات، المرشد، التذاكر، الوجبات…', 'An "Included" list: transport, guide, tickets, meals…'],
            'excluded' => ['قائمة "غير شامل": البقشيش، المشروبات، التذاكر الاختيارية… (بتقلّل الشكاوى والتقييمات السلبية)', 'A "Not included" list: tips, drinks, optional tickets… (prevents complaints and bad reviews)'],
            'pickup' => ['الاستلام: من الفندق ولا نقطة لقاء، ومن أنهي مناطق، والميعاد.', 'Pickup: hotel pickup or meeting point, which areas, and the time.'],
            'cancellation' => ['سياسة الإلغاء بوضوح (مثلًا إلغاء مجاني قبل 24 ساعة).', 'A clear cancellation policy (e.g. free cancellation up to 24 hours before).'],
            'faq' => ['3 لـ 6 أسئلة شائعة بإجاباتها (مناسبة للأطفال؟ لبس إيه؟ الرحلة خاصة ولا جروب؟). دي أكتر حاجة محركات الذكاء الاصطناعي بتقتبس منها.', '3–6 FAQs with answers (kids? what to wear? private or group?). AI assistants quote these most.'],
            'reviews' => ['تقييمات حقيقية من عملاء الرحلة دي (أو ويدجت التقييمات من Tourfecto).', 'Real reviews from guests of this trip (or the Tourfecto reviews widget).'],
            'booking' => ['زرار حجز واضح فوق وتحت الصفحة ("احجز الآن" أو "شوف المواعيد المتاحة").', 'A clear booking button at the top and bottom ("Book now" or "Check availability").'],
        ];
        return $a[$k][$ar ? 0 : 1];
    }

    /**
     * hreflang على مستوى الموقع: موقع بكذا لغة من غير hreflang، أو روابط من غير رجوع، أو أكواد غلط، أو مافيش x-default.
     * @return array{title:string,instructions:string,page:array}|null
     */
    public static function hreflangTask(array $pages, bool $ar): ?array
    {
        $langs = [];
        $byUrl = [];
        $withAlt = 0;
        foreach ($pages as $p) {
            $u = rtrim(strtolower((string) ($p['url'] ?? '')), '/');
            if ($u === '') continue;
            $byUrl[$u] = $p;
            $l = strtolower(substr((string) ($p['lang'] ?? ''), 0, 2));
            if (preg_match('#^/([a-z]{2})(?:-[a-z]{2})?(?:/|$)#i', (string) ($p['page_path'] ?? ''), $m)) $l = strtolower($m[1]);
            if ($l !== '') $langs[$l] = ($langs[$l] ?? 0) + 1;
            if (!empty($p['signals']['hreflang'])) $withAlt++;
        }
        if (count($langs) < 2 || !$pages) {
            return null; // موقع بلغة واحدة: hreflang مش لازم
        }
        $problems = [];
        if ($withAlt === 0) {
            $problems[] = $ar ? 'الموقع فيه ' . count($langs) . ' لغات (' . implode('، ', array_keys($langs)) . ') ومافيش ولا صفحة فيها hreflang. جوجل ممكن يعرض النسخة الغلط للزائر (الإنجليزي للألماني مثلًا) أو يعتبرهم محتوى مكرر.'
                : 'The site has ' . count($langs) . ' languages (' . implode(', ', array_keys($langs)) . ') and no page has hreflang. Google may show the wrong version or treat them as duplicates.';
        } else {
            $noReturn = [];
            $badCode = [];
            $noDefault = 0;
            foreach ($pages as $p) {
                $alts = $p['signals']['hreflang'] ?? [];
                if (!$alts) continue;
                $self = rtrim(strtolower((string) $p['url']), '/');
                if (!array_filter($alts, static fn ($a) => $a['lang'] === 'x-default')) $noDefault++;
                foreach ($alts as $a) {
                    if ($a['lang'] !== 'x-default' && !preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $a['lang'])) $badCode[$a['lang']] = true;
                    $target = rtrim(strtolower($a['href']), '/');
                    if ($target === $self || !isset($byUrl[$target])) continue;
                    $back = array_filter($byUrl[$target]['signals']['hreflang'] ?? [], static fn ($b) => rtrim(strtolower($b['href']), '/') === $self);
                    if (!$back && count($noReturn) < 5) $noReturn[] = $p['url'] . ' → ' . $a['href'];
                }
            }
            if ($noReturn) {
                $problems[] = ($ar ? 'روابط hreflang من غير رابط راجع (جوجل بيتجاهلها لما الصفحة التانية ماتشاورش ترجع):' : 'hreflang links without a return link (Google ignores them):') . "\n" . implode("\n", $noReturn);
            }
            if ($badCode) {
                $problems[] = ($ar ? 'أكواد لغة غلط: ' : 'Invalid language codes: ') . implode(', ', array_keys($badCode)) . ($ar ? ' (لازم تبقى زي en أو de أو en-gb)' : ' (use codes like en, de, en-gb)');
            }
            if ($noDefault > 0) {
                $problems[] = $ar ? $noDefault . ' صفحة من غير x-default (النسخة اللي تظهر لأي لغة مش موجودة عندك).' : $noDefault . ' pages without x-default (the fallback version for other languages).';
            }
        }
        if (!$problems) {
            return null;
        }
        return [
            'page' => $pages[0],
            'title' => $ar ? 'ظبّط ربط نسخ الموقع باللغات (hreflang)' : 'Fix language versions (hreflang)',
            'instructions' => implode("\n\n", $problems) . "\n\n" . ($ar
                ? 'الحل: كل صفحة تشاور على نفسها وعلى كل نسخها باللغات التانية بـ <link rel="alternate" hreflang="..">، وكل نسخة تشاور ترجع، ونسخة واحدة x-default. لو موقعك WordPress بإضافة WPML أو Polylang، فعّل الخيار ده من إعداداتها.'
                : 'Fix: every page links to itself and to each translated version with <link rel="alternate" hreflang="..">, every version links back, plus one x-default. On WordPress, WPML or Polylang can do this from their settings.'),
        ];
    }
}

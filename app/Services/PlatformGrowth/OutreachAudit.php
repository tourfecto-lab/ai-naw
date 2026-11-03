<?php

/**
 * Tourfecto - الفحص الاحترافي لكل شركة في تسويق المنصة
 *
 * بيبني "صورة رقمية" كاملة للشركة من مصادر حقيقية بس:
 *   - موقعها: لحد 3 صفحات (الرئيسية + التواصل + الجولات/الحجز) وsitemap/robots
 *   - السوشيال: كل منصة لوحدها (فيسبوك، إنستجرام، تيك توك، يوتيوب، لينكدإن، X)
 *   - ملفها على جوجل (Places API): موجودة؟ تقييمها وعدد تقييماتها مقارنة بـ TripAdvisor، مواعيد العمل
 *   - المنافسين: نفس المدينة ونفس النشاط من نفس القائمة المستوردة
 * والنتيجة: "نواقص" دقيقة (كل نقص بأرقامه والخدمة اللي بتحله) + درجة رقمية من 100 على 7 محاور.
 * مفيش أي رقم بيتألّف: كل نص بيتبني من الحقائق المقاسة.
 */
class OutreachAudit
{
    public const PILLARS = ['reputation', 'website', 'booking', 'social', 'communication', 'google', 'tracking'];
    private const NETWORKS = ['facebook', 'instagram', 'tiktok', 'youtube', 'linkedin', 'x'];
    private const VISUAL = ['Boat Tours & Water Sports', 'Outdoor Activities', 'Nightlife', 'Food & Drink', 'Tours'];

    // ------------------------------------------------------------------ الموقع

    /**
     * فحص صفحة HTML واحدة (بيانات من الشبكة: بتتقري بس)
     */
    public static function parsePage(string $html, string $url): array
    {
        $h = substr($html, 0, 1500000);
        $has = static fn (string $re): bool => preg_match($re, $h) === 1;
        $social = [];
        $patterns = [
            'facebook' => '#href=["\']https?://(?:www\.|m\.|web\.)?facebook\.com/(?!sharer|plugins|tr\b|dialog|share)([A-Za-z0-9._\-/]+)#i',
            'instagram' => '#href=["\']https?://(?:www\.)?instagram\.com/(?!p/|explore|share)([A-Za-z0-9._]+)#i',
            'tiktok' => '#href=["\']https?://(?:www\.)?tiktok\.com/@([A-Za-z0-9._]+)#i',
            'youtube' => '#href=["\']https?://(?:www\.)?youtube\.com/(?:@|c/|channel/|user/)([A-Za-z0-9._\-]+)#i',
            'linkedin' => '#href=["\']https?://(?:[a-z]{2,3}\.)?linkedin\.com/(?:company|in)/([A-Za-z0-9._\-]+)#i',
            'x' => '#href=["\']https?://(?:www\.)?(?:twitter|x)\.com/(?!intent|share|home)([A-Za-z0-9_]+)#i',
        ];
        foreach ($patterns as $net => $re) {
            if (preg_match($re, $h, $m)) {
                $social[$net] = trim($m[1], '/');
            }
        }
        preg_match_all('/hreflang=["\']([a-z]{2})/i', $h, $hl);
        $langs = array_map('strtolower', $hl[1] ?? []);
        // روابط لغات من غير hreflang (/en/ /tr/ /ar/ /de/ /ru/ ...)
        if (preg_match_all('#href=["\'](?:https?://[^/"\']+)?/(en|tr|ar|de|ru|fr|es|it|zh|ja|fa|nl|pl)(?:/|["\'])#i', $h, $ll)) {
            $langs = array_merge($langs, array_map('strtolower', $ll[1]));
        }
        // تبديل اللغات بالـ JavaScript مابيظهرش في hreflang ولا في روابط /en/: أسماء اللغات في القايمة هي الدليل
        $names = ['english' => 'en', 'français' => 'fr', 'francais' => 'fr', 'español' => 'es', 'espanol' => 'es', 'deutsch' => 'de', 'italiano' => 'it', 'português' => 'pt', 'portugues' => 'pt', 'русский' => 'ru', 'türkçe' => 'tr', 'العربية' => 'ar', '中文' => 'zh', '日本語' => 'ja', 'nederlands' => 'nl', 'polski' => 'pl'];
        if (preg_match_all('#>\s*(English|Fran[cç]ais|Espa[nñ]ol|Deutsch|Italiano|Portugu[eê]s|Русский|Türkçe|العربية|中文|日本語|Nederlands|Polski)\s*<#iu', $h, $ln)) {
            foreach ($ln[1] as $label) {
                $langs[] = $names[mb_strtolower($label)] ?? mb_strtolower($label);
            }
        }
        $year = null;
        if (preg_match_all('/(?:©|&copy;|copyright)\s*(?:\d{4}\s*[-–]\s*)?(20\d{2})/iu', $h, $cy)) {
            $year = max(array_map('intval', $cy[1]));
        }
        preg_match_all('/<img\b[^>]*>/i', $h, $imgs);
        $noAlt = 0;
        foreach ($imgs[0] as $img) {
            if (!preg_match('/\balt=["\'][^"\']{2,}/i', $img)) {
                $noAlt++;
            }
        }
        $schema = [];
        if (preg_match_all('/"@type"\s*:\s*"([A-Za-z]+)"/', $h, $st)) {
            $schema = array_values(array_unique($st[1]));
        }
        preg_match('#<title[^>]*>\s*([^<]*)</title>#i', $h, $tm);
        $title = trim(html_entity_decode($tm[1] ?? '', ENT_QUOTES, 'UTF-8'));
        return [
            'social' => $social,
            'whatsapp' => $has('#(?:wa\.me/|api\.whatsapp\.com/send|whatsapp://send|web\.whatsapp\.com/send)#i'),
            'chat_widget' => $has('#(?:embed\.tawk\.to|client\.crisp\.chat|widget\.intercom\.io|livechatinc\.com|static\.zdassets\.com|code\.tidio\.co|respond\.io|getbutton\.io|elfsight\.com|jivosite\.com|smartsupp\.com|zopim)#i'),
            'booking_engine' => $has('#(?:fareharbor\.com|bokun\.io|widgets\.bokun|rezdy\.com|peek\.com|checkfront\.com|trekksoft\.com|xola\.com|ventrata\.com|bookingkit\.(?:net|de)|regiondo\.|tourcms\.com|orioly\.com|palisis|bookeo\.com|simplybook\.|turitop\.com|rezgo\.com|zaui\.|getyourguide\.com/[^"\']*widget|viator\.com/[^"\']*widget|woocommerce|wc-cart|/checkout/?["\']|/cart/?["\']|add-to-cart|shopify|book-now-form|booking-form)#i'),
            'payments' => $has('#(?:js\.stripe\.com|checkout\.stripe|paypal\.com/sdk|paypalobjects|iyzico|iyzipay|paytr\.com|checkout\.com|adyen|2checkout|paymob|tap\.company|payfort)#i'),
            'meta_pixel' => $has('#connect\.facebook\.net/[^"\']*/fbevents\.js|\bfbq\(\s*["\']init#i'),
            'google_tag' => $has('#googletagmanager\.com/(?:gtag/js|gtm\.js)|google-analytics\.com/(?:analytics|ga)\.js|\bgtag\(\s*["\']config#i'),
            // مدونة: لينك مدونة/أخبار/أدلة، أو RSS، أو صفحة فيها مقالات كتير
            'blog' => $has('#href=["\'][^"\']*/(?:blog|news|articles?|stories|travel-guides?|guides?|haberler|makaleler|category|journal|magazine|blog-[a-z]+)(?:/|["\'])#i')
                || $has('#type=["\']application/(?:rss|atom)\+xml["\']#i') || preg_match_all('/<article\b/i', $h) >= 3,
            'contact_form' => self::hasContactForm($h),
            // الـ HTML المضغوط بيكتب name=viewport من غير علامات تنصيص
            'viewport' => $has('#<meta[^>]+name=["\']?viewport\b#i'),
            'title' => $title,
            'meta_description' => $has('#<meta[^>]+name=["\']?description\b[^>]+content=["\']?[^"\'>]{30,}#i') || $has('#<meta[^>]+content=["\'][^"\']{30,}["\'][^>]+name=["\']?description\b#i'),
            'h1' => $has('#<h1\b[^>]*>\s*(?:<[^>]+>\s*)*[^<\s]#i'),
            'schema' => $schema,
            'langs' => array_values(array_unique($langs)),
            // إضافات الترجمة (GTranslate وWeglot وWPML...) بتبدّل اللغة من غير ما تظهر أسماؤها في الـ HTML
            'lang_widget' => $has('#(?:gtranslate|google_translate_element|translate\.google\.com/translate_a|weglot|wpml-ls|polylang|pll-switcher|trp-language|translatepress|transposh|lang(?:uage)?[-_]?(?:switch|select|menu|picker))#i'),
            'copyright_year' => $year,
            'images' => count($imgs[0]),
            'images_no_alt' => $noAlt,
            'bytes' => strlen($html),
            'links' => self::internalLinks($h, $url),
        ];
    }

    private static function hasContactForm(string $h): bool
    {
        if (!preg_match_all('#<form\b.*?</form>#is', substr($h, 0, 600000), $forms)) {
            return false;
        }
        foreach ($forms[0] as $f) {
            if (preg_match('#type=["\']email["\']|name=["\'][^"\']*mail#i', $f)) {
                return true;
            }
        }
        return false;
    }

    /** روابط داخلية مهمة: التواصل والجولات/الحجز */
    private static function internalLinks(string $h, string $url): array
    {
        $host = strtolower(preg_replace('/^www\./i', '', (string) parse_url($url, PHP_URL_HOST)));
        $base = (string) parse_url($url, PHP_URL_SCHEME) . '://' . (string) parse_url($url, PHP_URL_HOST);
        $out = ['contact' => null, 'tours' => null];
        if (!preg_match_all('#href=["\']([^"\'\#]+)["\']#i', $h, $m)) {
            return $out;
        }
        foreach ($m[1] as $href) {
            $abs = preg_match('#^https?://#i', $href) ? $href : (str_starts_with($href, '/') ? $base . $href : null);
            if (!$abs || strtolower(preg_replace('/^www\./i', '', (string) parse_url($abs, PHP_URL_HOST))) !== $host) {
                continue;
            }
            $path = strtolower((string) parse_url($abs, PHP_URL_PATH));
            if (!$out['contact'] && preg_match('#(contact|iletisim|bize-ulasin|تواصل|اتصل)#u', $path)) {
                $out['contact'] = $abs;
            } elseif (!$out['tours'] && preg_match('#(tours?|turlar|excursion|activities|book|rezervasyon|packages|trips)#', $path)) {
                $out['tours'] = $abs;
            }
        }
        return $out;
    }

    /** دمج الصفحات: أي حاجة موجودة في أي صفحة بتتحسب للموقع */
    public static function mergePages(array $pages, int $loadMs, bool $https, ?bool $sitemap): array
    {
        $home = $pages[0];
        $merged = $home;
        foreach (array_slice($pages, 1) as $p) {
            foreach (['whatsapp', 'chat_widget', 'booking_engine', 'payments', 'meta_pixel', 'google_tag', 'blog', 'contact_form', 'lang_widget'] as $k) {
                $merged[$k] = $merged[$k] || $p[$k];
            }
            $merged['social'] += $p['social'];
            $merged['langs'] = array_values(array_unique(array_merge($merged['langs'], $p['langs'])));
            $merged['schema'] = array_values(array_unique(array_merge($merged['schema'], $p['schema'])));
            $merged['copyright_year'] = max((int) $merged['copyright_year'], (int) $p['copyright_year']) ?: null;
        }
        unset($merged['links']);
        return $merged + ['ok' => true, 'load_ms' => $loadMs, 'https' => $https, 'sitemap' => $sitemap, 'pages' => count($pages)];
    }

    // ------------------------------------------------------------------ جوجل

    /** نتيجة Places "Find Place" ← حقائق ملف جوجل */
    /** أسماء الشركات المنسوخة من خرائط جوجل فيها علامات اتجاه مخفية (U+202A-202E وغيرها) بتبوّظ البحث عن الشركة وبتظهر في الرسالة */
    public static function cleanName(string $name): string
    {
        return trim((string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $name));
    }

    public static function googleFacts(?array $placesResponse): array
    {
        if ($placesResponse === null) {
            return ['checked' => false];
        }
        $c = $placesResponse['candidates'][0] ?? null;
        if (!$c) {
            return ['checked' => true, 'found' => false];
        }
        return [
            'checked' => true,
            'found' => true,
            'rating' => isset($c['rating']) ? (float) $c['rating'] : null,
            'reviews' => isset($c['user_ratings_total']) ? (int) $c['user_ratings_total'] : 0,
            'hours' => !empty($c['opening_hours']),
            'status' => (string) ($c['business_status'] ?? ''),
            'place_id' => (string) ($c['place_id'] ?? ''),
        ];
    }

    // ------------------------------------------------------------------ المنافسين

    /**
     * مقارنة بالمنافسين: نفس المدينة ونفس أول نشاط، من نفس القائمة
     * @param array $peers صفوف [company, reviews, rating, category, city]
     */
    public static function benchmark(array $lead, array $peers): array
    {
        $cat = strtolower(trim(explode(',', (string) ($lead['category'] ?? ''))[0]));
        $city = strtolower(trim((string) ($lead['city'] ?? '')));
        $same = array_values(array_filter($peers, static fn ($p) => strtolower(trim((string) ($p['city'] ?? ''))) === $city
            && strtolower(trim(explode(',', (string) ($p['category'] ?? ''))[0])) === $cat
            && (string) ($p['company'] ?? '') !== (string) ($lead['company'] ?? '')));
        if (count($same) < 5) {
            return ['peers' => count($same)];
        }
        $reviews = array_map(static fn ($p) => (int) ($p['reviews'] ?? 0), $same);
        sort($reviews);
        $median = $reviews[intdiv(count($reviews), 2)];
        $ratings = array_filter(array_map(static fn ($p) => (float) ($p['rating'] ?? 0), $same));
        usort($same, static fn ($a, $b) => (int) $b['reviews'] <=> (int) $a['reviews']);
        $mine = (int) ($lead['reviews'] ?? 0);
        $below = count(array_filter($reviews, static fn ($r) => $r < $mine));
        return [
            'peers' => count($same),
            'median_reviews' => $median,
            'avg_rating' => $ratings ? round(array_sum($ratings) / count($ratings), 1) : null,
            'percentile' => (int) round($below * 100 / count($reviews)),
            'top' => array_map(static fn ($p) => ['company' => (string) $p['company'], 'reviews' => (int) $p['reviews'], 'rating' => $p['rating'] !== null ? (float) $p['rating'] : null], array_slice($same, 0, 3)),
            'category' => (string) explode(',', (string) ($lead['category'] ?? ''))[0],
        ];
    }

    // ------------------------------------------------------------------ النواقص والدرجة

    /**
     * النواقص الدقيقة: [{id, pillar, track, sev, vars}] مرتبة بالأهمية
     * @param array|null $site null = الموقع ماتفحصش (أو رفض الزيارة)، ['ok'=>false] = واقع فعلًا
     */
    public static function gaps(array $lead, array $ta, ?array $site, array $google, array $bench): array
    {
        $g = [];
        $add = static function (string $id, string $pillar, string $track, int $sev, array $vars = []) use (&$g) {
            $g[] = ['id' => $id, 'pillar' => $pillar, 'track' => $track, 'sev' => $sev, 'vars' => $vars];
        };
        $reviews = (int) ($lead['reviews'] ?? 0);
        $rating = $lead['rating'] !== null && $lead['rating'] !== '' ? (float) $lead['rating'] : null;
        $subs = (array) ($ta['subcategories'] ?? []);
        $domain = (string) ($lead['domain'] ?? '');

        // السمعة
        // الخطورة على حسب النسبة مش العدد: 21 سلبي من 9,585 (0.2%) مش زي 21 من 150 (14%)
        $neg = (int) ($ta['negative'] ?? 0);
        if ($neg >= 3) {
            $ratio = $reviews > 0 ? $neg / $reviews : 1;
            $sev = $ratio >= 0.08 ? 10 : ($ratio >= 0.05 ? 8 : ($ratio >= 0.02 ? 6 : ($ratio >= 0.01 ? 4 : 2)));
            $add('negative_reviews', 'reputation', 'reputation', $sev, ['negative' => $neg, 'reviews' => $reviews]);
        }
        if (!empty($bench['median_reviews']) && $reviews < $bench['median_reviews'] * 0.5) {
            $add('few_reviews', 'reputation', 'reputation', 6, ['reviews' => $reviews, 'median' => (int) $bench['median_reviews'], 'category' => $bench['category'] ?? '']);
        }
        if ($rating !== null && !empty($bench['avg_rating']) && $rating < $bench['avg_rating'] - 0.2) {
            $add('rating_below', 'reputation', 'reputation', 5, ['rating' => $rating, 'avg' => $bench['avg_rating']]);
        }

        // الموقع
        if ($domain === '' || empty($lead['website'])) {
            $add('no_website', 'website', 'website', 10);
        } elseif ($site !== null && empty($site['ok'])) {
            $add('site_down', 'website', 'website', 9, ['domain' => $domain]);
        } elseif ($site !== null) {
            if (empty($site['viewport'])) {
                $add('no_mobile', 'website', 'website', 7, ['domain' => $domain]);
            }
            if ((int) $site['load_ms'] >= 3500) {
                $add('slow', 'website', 'seo', 6, ['domain' => $domain, 'load' => round($site['load_ms'] / 1000, 1)]);
            } elseif ((int) $site['bytes'] > 2500000) {
                $add('heavy', 'website', 'seo', 4, ['domain' => $domain, 'mb' => round($site['bytes'] / 1048576, 1)]);
            }
            if (empty($site['https'])) {
                $add('no_https', 'website', 'seo', 6, ['domain' => $domain]);
            }
            $missing = array_keys(array_filter(['title' => mb_strlen((string) $site['title']) < 10, 'description' => empty($site['meta_description']), 'h1' => empty($site['h1'])]));
            if ($missing) {
                $add('seo_basics', 'website', 'seo', 3 + count($missing), ['domain' => $domain, 'missing' => $missing]);
            }
            if (!array_intersect((array) $site['schema'], ['LocalBusiness', 'TravelAgency', 'TouristTrip', 'Product', 'Organization', 'TouristAttraction', 'Event'])) {
                $add('no_schema', 'website', 'seo', 3, ['domain' => $domain]);
            }
            if (count((array) $site['langs']) < 2 && empty($site['lang_widget'])) {
                $add('one_language', 'website', 'seo', 5, ['domain' => $domain]);
            }
            if (!empty($site['copyright_year']) && (int) $site['copyright_year'] < (int) date('Y') - 1) {
                $add('outdated', 'website', 'website', 4, ['domain' => $domain, 'year' => (int) $site['copyright_year']]);
            }
            if (empty($site['blog'])) {
                $add('no_blog', 'website', 'seo', 4, ['domain' => $domain]);
            }
            // الحجز والدفع
            if (empty($site['booking_engine']) && empty($site['payments'])) {
                $add('no_booking', 'booking', 'direct_booking', !empty($ta['viator']) ? 8 : 6, ['domain' => $domain]);
            }
            // التواصل
            if (empty($site['whatsapp']) && empty($site['chat_widget'])) {
                $add('no_chat', 'communication', 'chat', 7, ['domain' => $domain]);
            }
            // السوشيال: كل منصة لوحدها
            $have = array_keys((array) $site['social']);
            if (!$have) {
                $add('no_social', 'social', 'social', 7, ['domain' => $domain]);
            } else {
                $wanted = ['instagram', 'facebook'];
                if (array_intersect($subs, self::VISUAL) || !$subs) {
                    $wanted = array_merge($wanted, ['tiktok', 'youtube']);
                }
                $miss = array_values(array_diff($wanted, $have));
                if ($miss) {
                    $add('missing_social', 'social', 'social', in_array('instagram', $miss, true) ? 6 : 4, ['have' => $have, 'missing' => $miss]);
                }
            }
            // التتبع
            if (empty($site['meta_pixel']) && empty($site['google_tag'])) {
                $add('no_tracking', 'tracking', 'ads', 5, ['domain' => $domain]);
            } elseif (empty($site['meta_pixel'])) {
                $add('no_pixel', 'tracking', 'ads', 3, ['domain' => $domain]);
            }
        }
        if (!empty($ta['viator'])) {
            $add('viator', 'booking', 'direct_booking', 7);
        }

        // ملف جوجل
        if (!empty($google['checked'])) {
            if (empty($google['found'])) {
                $add('no_google', 'google', 'seo', 8);
            } else {
                if ($reviews >= 30 && (int) $google['reviews'] < $reviews * 0.3) {
                    $add('google_few_reviews', 'google', 'reputation', 6, ['g_reviews' => (int) $google['reviews'], 'ta_reviews' => $reviews]);
                }
                if (empty($google['hours'])) {
                    $add('google_no_hours', 'google', 'seo', 3);
                }
            }
        }

        // صور قليلة على TripAdvisor
        if (($ta['photos'] ?? 99) > 0 && ($ta['photos'] ?? 99) < 20) {
            $add('few_photos', 'social', 'social', 3, ['photos' => (int) $ta['photos']]);
        }
        // زباين كتير من غير متابعة
        if ($reviews >= 300) {
            $add('many_guests', 'communication', 'crm', 4, ['reviews' => $reviews]);
        }
        usort($g, static fn ($a, $b) => $b['sev'] <=> $a['sev']);
        return $g;
    }

    /** الدرجة الرقمية: كل محور 100 ناقص وزن النواقص فيه، والإجمالي متوسط موزون */
    public static function score(array $gaps, bool $siteChecked): array
    {
        $weights = ['reputation' => 20, 'website' => 20, 'booking' => 15, 'social' => 15, 'communication' => 10, 'google' => 10, 'tracking' => 10];
        $pillars = array_fill_keys(self::PILLARS, 100);
        foreach ($gaps as $gp) {
            $pillars[$gp['pillar']] = max(5, $pillars[$gp['pillar']] - $gp['sev'] * 7);
        }
        $total = 0;
        $sum = 0;
        foreach ($weights as $p => $w) {
            if (!$siteChecked && in_array($p, ['social', 'communication', 'tracking'], true) && $pillars[$p] === 100) {
                continue; // الموقع رفض الفحص: مانحسبش محاور معتمدة عليه
            }
            $total += $pillars[$p] * $w;
            $sum += $w;
        }
        return ['total' => $sum ? (int) round($total / $sum) : 0, 'pillars' => $pillars];
    }

    /** المسارات من النواقص: مجموع خطورة نواقص كل مسار، الأعلى الأول */
    public static function needsFromGaps(array $gaps): array
    {
        $by = [];
        foreach ($gaps as $gp) {
            $t = $gp['track'];
            $by[$t] ??= ['track' => $t, 'score' => 0, 'facts' => ['gaps' => []]];
            $by[$t]['score'] += $gp['sev'] * 10;
            $by[$t]['facts']['gaps'][] = $gp['id'];
        }
        usort($by, static fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_values($by);
    }

    // ------------------------------------------------------------------ بطاقة الحضور الرقمي والأولوية

    /**
     * بطاقة الشركة على المنصات: جوجل ماب، TripAdvisor، فيسبوك، إنستجرام، والموقع.
     * من اللي اتخزّن وقت الاستيراد والفحص بس (من غير طلبات جديدة). أي حاجة مش معروفة بترجع 'unknown' ومابتتكتبش في الرسالة،
     * عشان مانقولش للشركة معلومة مش متأكدين منها. فيسبوك وإنستجرام: «مربوط/مش مربوط بالموقع» (عدد المتابعين محتاج Meta API).
     */
    public static function scorecard(array $lead, array $data): array
    {
        $ta = (array) ($data['ta'] ?? []);
        $audit = (array) ($data['audit'] ?? []);
        $site = $data['site'] ?? null;
        $mapsUrl = (string) ($lead['maps_url'] ?? '');
        $rating = isset($lead['rating']) && $lead['rating'] !== '' && $lead['rating'] !== null ? (float) $lead['rating'] : null;
        $reviews = isset($lead['reviews']) && $lead['reviews'] !== '' && $lead['reviews'] !== null ? (int) $lead['reviews'] : null;
        // تقييم وعدد مراجعات الشركة في الاستيراد بيجوا من مصدر الملف: TripAdvisor أو Google Maps
        $fromTa = $ta !== [] || preg_match('#tripadvisor\.#i', $mapsUrl) === 1;
        $fromGoogle = !$fromTa && preg_match('#google\.[a-z.]+/maps|goo\.gl/maps|maps\.app\.goo\.gl#i', $mapsUrl) === 1;

        $g = (array) ($audit['google'] ?? []);
        if (!empty($g['checked'])) {
            $google = empty($g['found'])
                ? ['status' => 'missing', 'rating' => null, 'reviews' => null, 'hours' => null]
                : ['status' => 'found', 'rating' => isset($g['rating']) ? (float) $g['rating'] : null, 'reviews' => (int) ($g['reviews'] ?? 0), 'hours' => !empty($g['hours'])];
        } elseif ($fromGoogle && $rating !== null) {
            $google = ['status' => 'found', 'rating' => $rating, 'reviews' => $reviews ?? 0, 'hours' => null];
        } else {
            $google = ['status' => 'unknown', 'rating' => null, 'reviews' => null, 'hours' => null];
        }
        $google['median'] = isset($audit['bench']['median_reviews']) ? (int) $audit['bench']['median_reviews'] : null;

        $tripadvisor = $fromTa && ($rating !== null || $ta !== [])
            ? ['status' => 'found', 'rating' => $rating, 'reviews' => $reviews, 'ranking' => (string) ($ta['ranking'] ?? ''), 'award' => !empty($ta['award']), 'negative' => (int) ($ta['negative'] ?? 0),
                'url' => (string) ($ta['url'] ?? '') !== '' ? (string) $ta['url'] : ($fromTa && $mapsUrl !== '' ? $mapsUrl : '')]
            : ['status' => 'unknown', 'rating' => null, 'reviews' => null, 'ranking' => '', 'award' => false, 'negative' => 0, 'url' => ''];

        // الموقع اتفحص فعلًا (ok) = نعرف روابط السوشيال اللي فيه؛ غير كده مانعرفش
        $social = is_array($site) && !empty($site['ok']) && array_key_exists('social', $site) ? (array) $site['social'] : null;
        $net = static function (string $key, string $base) use ($social): array {
            if ($social === null) {
                return ['status' => 'unknown', 'url' => ''];
            }
            return isset($social[$key]) ? ['status' => 'linked', 'url' => $base . ltrim((string) $social[$key], '/')] : ['status' => 'missing', 'url' => ''];
        };

        if (empty($lead['website'])) {
            $website = ['status' => 'none'];
        } elseif (is_array($site) && array_key_exists('ok', $site)) {
            $website = ['status' => !empty($site['ok']) ? 'ok' : 'down', 'https' => isset($site['https']) ? (bool) $site['https'] : null, 'mobile' => isset($site['viewport']) ? (bool) $site['viewport'] : null, 'load_ms' => isset($site['load_ms']) ? (int) $site['load_ms'] : null];
        } else {
            $website = ['status' => 'unknown'];
        }
        $website['score'] = isset($audit['score']['total']) ? (int) $audit['score']['total'] : null;

        return ['google' => $google, 'tripadvisor' => $tripadvisor, 'facebook' => $net('facebook', 'https://www.facebook.com/'), 'instagram' => $net('instagram', 'https://www.instagram.com/'), 'website' => $website];
    }

    /**
     * أولوية الإرسال 0-100: مين محتاج فعلًا + مين شغّال فعلًا (تقييمات حقيقية) فيستاهل وقتنا.
     * - الاحتياج: مجموع خطورة النواقص الفعلية (سقف 60): شركة من غير نواقص مالهاش داعي نكلّمها.
     * - الشغل الفعلي: عدد التقييمات (سقف 25) وجودة التقييم (سقف 8).
     * tier: high (70+) · medium (45+) · low.
     */
    public static function priority(array $lead, array $data): array
    {
        $gaps = (array) ($data['audit']['gaps'] ?? []);
        $sev = 0;
        foreach ($gaps as $g) {
            $sev += max(0, (int) ($g['sev'] ?? 0));
        }
        if (!$gaps) {
            foreach ((array) (json_decode((string) ($lead['needs_json'] ?? ''), true) ?: []) as $n) {
                $sev += (int) round(((int) ($n['score'] ?? 0)) / 10);
            }
        }
        $need = min(60, (int) round($sev * 2.5));
        $reviews = max(0, (int) ($lead['reviews'] ?? 0));
        $traction = min(25, (int) round(log10($reviews + 1) * 9));
        $rating = isset($lead['rating']) && $lead['rating'] !== '' ? (float) $lead['rating'] : 0.0;
        $quality = $rating >= 4.5 ? 8 : ($rating >= 4.0 ? 5 : 0);
        $score = $need === 0 ? 0 : min(100, $need + $traction + $quality); // من غير احتياج مفيش أولوية مهما كانت شهرتها
        return ['score' => $score, 'tier' => self::tier($score), 'need' => $need, 'traction' => $traction];
    }

    public static function tier(int $score): string
    {
        return $score >= 70 ? 'high' : ($score >= 45 ? 'medium' : 'low');
    }

    // ------------------------------------------------------------------ النصوص

    public static function gapText(array $gap, array $lead, string $lang): string
    {
        $lang = isset(self::TEXT[$lang]) ? $lang : 'en';
        $tpl = self::TEXT[$lang][$gap['id']] ?? '';
        $v = $gap['vars'] + ['company' => (string) ($lead['company'] ?? ''), 'domain' => (string) ($lead['domain'] ?? '')];
        $net = self::NET_NAMES;
        $list = static fn (array $xs) => implode($lang === 'ar' ? ' و' : ($lang === 'tr' ? ', ' : ', '), array_map(static fn ($x) => $net[$x] ?? $x, $xs));
        $rep = [];
        foreach ($v as $k => $val) {
            if ($k === 'missing' && $gap['id'] === 'seo_basics') {
                $val = $list(array_map(static fn ($x) => self::SEO_PARTS[$lang][$x] ?? $x, (array) $val));
            } elseif (is_array($val)) {
                $val = $list($val);
            } elseif (is_int($val) && $val >= 1000 && $k !== 'year') {
                $val = number_format($val);
            }
            $rep['{' . $k . '}'] = (string) $val;
        }
        return strtr($tpl, $rep);
    }

    public static function pillarLabel(string $p, string $lang): string
    {
        return self::PILLAR_NAMES[$lang][$p] ?? self::PILLAR_NAMES['en'][$p] ?? $p;
    }

    private const NET_NAMES = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'youtube' => 'YouTube', 'linkedin' => 'LinkedIn', 'x' => 'X'];

    private const SEO_PARTS = [
        'ar' => ['title' => 'عنوان الصفحة', 'description' => 'وصف جوجل', 'h1' => 'العنوان الرئيسي (H1)'],
        'en' => ['title' => 'page title', 'description' => 'Google description', 'h1' => 'main heading (H1)'],
        'tr' => ['title' => 'sayfa başlığı', 'description' => 'Google açıklaması', 'h1' => 'ana başlık (H1)'],
    ];

    public const PILLAR_NAMES = [
        'ar' => ['reputation' => 'السمعة', 'website' => 'الموقع', 'booking' => 'الحجز المباشر', 'social' => 'السوشيال ميديا', 'communication' => 'التواصل', 'google' => 'جوجل', 'tracking' => 'تتبّع الإعلانات'],
        'en' => ['reputation' => 'Reputation', 'website' => 'Website', 'booking' => 'Direct booking', 'social' => 'Social media', 'communication' => 'Communication', 'google' => 'Google profile', 'tracking' => 'Ad tracking'],
        'tr' => ['reputation' => 'İtibar', 'website' => 'Web sitesi', 'booking' => 'Doğrudan rezervasyon', 'social' => 'Sosyal medya', 'communication' => 'İletişim', 'google' => 'Google profili', 'tracking' => 'Reklam takibi'],
    ];

    public const SCORE_LINE = [
        'ar' => 'الدرجة الرقمية لـ {company}: {score}/100{vs}.',
        'en' => "{company}'s digital score: {score}/100{vs}.",
        'tr' => '{company} dijital puanı: {score}/100{vs}.',
    ];
    public const SCORE_VS = [
        'ar' => ' (متوسط شركات {category} في {city}: {avg})',
        'en' => ' (average for {category} companies in {city}: {avg})',
        'tr' => ' ({city} içindeki {category} şirketlerinin ortalaması: {avg})',
    ];

    private const TEXT = [
        'ar' => [
            'negative_reviews' => 'عندكم {negative} تقييم بنجمة أو نجمتين من {reviews} على TripAdvisor، والمسافر الجديد بيقرا دول الأول.',
            'few_reviews' => 'عندكم {reviews} تقييم بس، ومتوسط شركات {category} في مدينتكم {median} تقييم.',
            'rating_below' => 'تقييمكم {rating} ومتوسط المنافسين في مدينتكم {avg}.',
            'no_website' => 'مالقيتش موقع لـ {company}، فالحجز كله بيعدّي على منصات تانية بعمولة.',
            'site_down' => 'موقع {domain} مابيفتحش دلوقتي، وأي مسافر بيدوّر عليكم بيقف هنا.',
            'no_mobile' => '{domain} مش متظبط للموبايل، وأغلب المسافرين بيحجزوا من الموبايل.',
            'slow' => '{domain} بياخد حوالي {load} ثانية عشان يفتح، والصفحة البطيئة بتخسر زوار وترتيب جوجل.',
            'heavy' => 'الصفحة الرئيسية لـ {domain} حجمها {mb} ميجا، وده بيبطّأها جدًا على الموبايل.',
            'no_https' => '{domain} مش آمن (من غير HTTPS)، والمتصفح بيحذّر الزوار قبل ما يدخلوا.',
            'seo_basics' => '{domain} ناقصه أساسيات جوجل: {missing}.',
            'no_schema' => '{domain} مافيهوش بيانات منظّمة (Schema)، فجوجل مابيعرضش جولاتكم بالأسعار والتقييمات.',
            'one_language' => '{domain} بلغة واحدة بس، والمسافرين جايين من دول كتير.',
            'outdated' => 'آخر تحديث ظاهر على {domain} سنة {year}، وده بيقلّل ثقة الزوار.',
            'no_blog' => '{domain} مافيهوش مدونة ولا أدلة سياحية، ودي أكتر طريقة شركات الجولات بتظهر بيها في جوجل.',
            'no_booking' => '{domain} مافيهوش حجز ودفع أونلاين، فالزبون لازم يراسلكم أو يحجز من منصة بعمولة.',
            'viator' => 'جولاتكم على TripAdvisor بتتباع عن طريق Viator، يعني عمولة على كل حجز.',
            'no_chat' => '{domain} مافيهوش زرار واتساب ولا شات، والمسافر بيحجز مع أول حد يرد عليه.',
            'no_social' => 'مالقيتش أي حسابات سوشيال ميديا على {domain}.',
            'missing_social' => 'موقعكم فيه {have}، بس مالقيتش {missing}، ودي أكتر أماكن المسافرين بيختاروا منها الجولات.',
            'no_tracking' => '{domain} مافيهوش Meta Pixel ولا Google Analytics، فمستحيل تعرفوا أي إعلان أو قناة بتجيب حجوزات.',
            'no_pixel' => '{domain} فيه Google Analytics بس مافيهوش Meta Pixel، فإعلانات فيسبوك وإنستجرام مش هتتقاس.',
            'no_google' => 'مالقيتش ملف لـ {company} على Google Maps، وده أول مكان المسافر بيدوّر فيه.',
            'google_few_reviews' => 'على Google Maps عندكم {g_reviews} تقييم بس، مقابل {ta_reviews} على TripAdvisor.',
            'google_no_hours' => 'ملفكم على Google Maps مافيهوش مواعيد العمل.',
            'few_photos' => 'عندكم {photos} صورة بس على TripAdvisor، والصور أول حاجة المسافر بيشوفها.',
            'many_guests' => 'بـ {reviews} تقييم، خدمتم آلاف الزباين، وأغلبهم مابيسمعوش منكم تاني بعد الرحلة.',
        ],
        'en' => [
            'negative_reviews' => 'You have {negative} reviews of 1–2 stars among {reviews} on TripAdvisor, and new travellers read those first.',
            'few_reviews' => 'You have {reviews} reviews, while {category} companies in your city have {median} on average.',
            'rating_below' => 'Your rating is {rating}, while competitors in your city average {avg}.',
            'no_website' => "I couldn't find a website for {company}, so every booking goes through other platforms with commission.",
            'site_down' => "{domain} isn't loading right now, and every traveller looking for you stops there.",
            'no_mobile' => "{domain} isn't set up for mobile, and most travellers book on their phones.",
            'slow' => '{domain} takes about {load} seconds to open, and slow pages lose visitors and Google ranking.',
            'heavy' => "{domain}'s homepage weighs {mb} MB, which makes it very slow on mobile.",
            'no_https' => "{domain} isn't secure (no HTTPS), so browsers warn visitors before they enter.",
            'seo_basics' => '{domain} is missing Google basics: {missing}.',
            'no_schema' => "{domain} has no structured data (Schema), so Google can't show your tours with prices and ratings.",
            'one_language' => '{domain} is in one language only, while your travellers come from many countries.',
            'outdated' => 'The latest year shown on {domain} is {year}, which lowers visitor trust.',
            'no_blog' => '{domain} has no blog or travel guides, which is how most tour companies get found on Google.',
            'no_booking' => "{domain} doesn't take bookings or payments online, so guests have to message you or book on a platform with commission.",
            'viator' => 'Your tours on TripAdvisor are sold through Viator, which means a commission on every booking.',
            'no_chat' => '{domain} has no WhatsApp button or live chat, and travellers book with whoever answers first.',
            'no_social' => "I couldn't find any social media accounts on {domain}.",
            'missing_social' => 'Your site links to {have}, but I couldn’t find {missing}, where many travellers choose their tours.',
            'no_tracking' => "{domain} has no Meta Pixel or Google Analytics, so you can't tell which ad or channel brings bookings.",
            'no_pixel' => "{domain} has Google Analytics but no Meta Pixel, so Facebook and Instagram ads can't be measured.",
            'no_google' => "I couldn't find a Google Maps profile for {company}, the first place travellers search.",
            'google_few_reviews' => 'On Google Maps you have only {g_reviews} reviews, versus {ta_reviews} on TripAdvisor.',
            'google_no_hours' => 'Your Google Maps profile has no opening hours.',
            'few_photos' => 'You have only {photos} photos on TripAdvisor, and photos are the first thing travellers look at.',
            'many_guests' => 'With {reviews} reviews you have served thousands of guests, and most never hear from you again after the trip.',
        ],
        'tr' => [
            'negative_reviews' => "TripAdvisor'daki {reviews} yorumunuzun {negative} tanesi 1–2 yıldızlı ve yeni gezginler önce bunları okuyor.",
            'few_reviews' => '{reviews} yorumunuz var; şehrinizdeki {category} şirketlerinin ortalaması {median}.',
            'rating_below' => 'Puanınız {rating}, şehrinizdeki rakiplerin ortalaması {avg}.',
            'no_website' => '{company} için bir web sitesi bulamadım; bu yüzden tüm rezervasyonlar komisyonlu platformlardan geçiyor.',
            'site_down' => '{domain} şu anda açılmıyor ve sizi arayan her gezgin burada takılıyor.',
            'no_mobile' => '{domain} mobil uyumlu değil, oysa gezginlerin çoğu telefondan rezervasyon yapıyor.',
            'slow' => "{domain} açılması yaklaşık {load} saniye sürüyor; yavaş sayfalar ziyaretçi ve Google sıralaması kaybettirir.",
            'heavy' => '{domain} ana sayfası {mb} MB; bu da mobilde çok yavaş açılmasına neden oluyor.',
            'no_https' => '{domain} güvenli değil (HTTPS yok), tarayıcılar ziyaretçileri girmeden önce uyarıyor.',
            'seo_basics' => '{domain} sitesinde Google temelleri eksik: {missing}.',
            'no_schema' => "{domain} üzerinde yapılandırılmış veri (Schema) yok; Google turlarınızı fiyat ve puanla gösteremiyor.",
            'one_language' => '{domain} yalnızca tek dilde, oysa misafirleriniz birçok ülkeden geliyor.',
            'outdated' => "{domain} üzerinde görünen son yıl {year}; bu, ziyaretçi güvenini azaltıyor.",
            'no_blog' => "{domain} üzerinde blog ya da gezi rehberi yok; tur şirketlerinin çoğu Google'da bu sayede bulunuyor.",
            'no_booking' => '{domain} online rezervasyon ve ödeme almıyor; misafirler size yazmak ya da komisyonlu bir platformdan almak zorunda.',
            'viator' => "TripAdvisor'daki turlarınız Viator üzerinden satılıyor, yani her rezervasyonda komisyon ödüyorsunuz.",
            'no_chat' => '{domain} üzerinde WhatsApp butonu ya da canlı sohbet yok; gezginler ilk yanıt verenle rezervasyon yapıyor.',
            'no_social' => '{domain} üzerinde hiçbir sosyal medya hesabı bulamadım.',
            'missing_social' => "Sitenizde {have} var ama {missing} bulamadım; gezginler turlarını en çok buralarda seçiyor.",
            'no_tracking' => '{domain} üzerinde Meta Pixel ya da Google Analytics yok; hangi reklamın veya kanalın rezervasyon getirdiğini bilemezsiniz.',
            'no_pixel' => "{domain} üzerinde Google Analytics var ama Meta Pixel yok; Facebook ve Instagram reklamları ölçülemiyor.",
            'no_google' => "{company} için Google Haritalar profili bulamadım; gezginlerin ilk baktığı yer orası.",
            'google_few_reviews' => "Google Haritalar'da yalnızca {g_reviews} yorumunuz var, TripAdvisor'da ise {ta_reviews}.",
            'google_no_hours' => 'Google Haritalar profilinizde çalışma saatleri yok.',
            'few_photos' => "TripAdvisor'da yalnızca {photos} fotoğrafınız var ve gezginin ilk baktığı şey fotoğraflar.",
            'many_guests' => '{reviews} yorumla binlerce misafir ağırladınız ve çoğu turdan sonra sizden bir daha haber almıyor.',
        ],
    ];
}

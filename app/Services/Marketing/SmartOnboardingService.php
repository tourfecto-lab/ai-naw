<?php

/**
 * الإعداد الذكي: العميل بيكتب رابط موقعه بس، وإحنا بنستخرج الباقي تلقائي.
 *  1) analyze(): بنقرا الموقع فعليًا + الذكاء الاصطناعي بيستخرج (من محتوى الصفحة بس، من غير تأليف):
 *     الاسم، الوصف، نوع النشاط، الخدمات، اللغة، الأسواق المستهدفة، العملة، جمل البحث.
 *  2) competitors(): المنافسين الحقيقيين - من نتائج جوجل (DataForSEO لو متوصل)، وإلا اقتراحات
 *     الذكاء الاصطناعي بعد ما نفتح كل موقع ونتأكد إنه شغال وإنه نشاط سياحي فعلًا.
 *     مابنعرضش أي أرقام (زي Domain Authority) مش مقاسة فعلًا.
 */
class SmartOnboardingService
{
    /** مواقع حجز/منصات كبيرة - مش منافسين مباشرين لشركة سياحة */
    private const NOT_COMPETITORS = [
        'tripadvisor', 'viator', 'getyourguide', 'expedia', 'airbnb', 'klook', 'civitatis', 'musement',
        'headout', 'tiqets', 'kayak', 'trivago', 'agoda', 'lonelyplanet', 'wikipedia', 'wikitravel', 'youtube',
        'nusuk.sa', 'haj.gov', '.gov.', 'facebook', 'instagram', 'tiktok', 'pinterest', 'reddit', 'quora', 'twitter', 'linkedin', 'google.',
        'yelp', 'trustpilot', 'tourradar', 'egypt.travel', 'skyscanner', 'cntraveler', 'timeout.com', 'culturetrip', 'nationalgeographic',
    ];
    /**
     * دومينات بالظبط (أو subdomain منها) - مجلات وموسوعات وأخبار بتشاركك كلمات معلوماتية بس مش بتبيع رحلات.
     * منفصلة عن القايمة اللي فوق عشان «x.com» أو «hotels.com» كجزء من الاسم كانت بتشيل شركات حقيقية زي egyptsphinx.com.
     */
    private const NOT_COMPETITOR_DOMAINS = [
        'x.com', 'booking.com', 'hotels.com', 'history.com', 'worldhistory.org', 'britannica.com', 'smithsonianmag.com', 'travelandleisure.com',
        'roughguides.com', 'fodors.com', 'frommers.com', 'afar.com', 'atlasobscura.com', 'worldatlas.com', 'bbc.com', 'bbc.co.uk', 'cnn.com',
        'nytimes.com', 'theguardian.com', 'forbes.com', 'dailymail.co.uk', 'livescience.com', 'ancient-origins.net', 'thoughtco.com',
        'medium.com', 'wikiwand.com', 'khanacademy.org', 'ancient.eu',
    ];
    /** موقع بعدد كلمات أكبر من كده و10 أضعاف موقعك = ناشر عملاق مش شركة رحلات زيك */
    private const PUBLISHER_KEYWORDS = 150000;
    /** الكلمات اللي بيكتبها اللي عايز يحجز، بلغة سوق البحث (8 بالظبط: حد فلاتر DataForSEO) */
    private const BOOKING_WORDS = [
        'en' => ['tour', 'trip', 'cruise', 'excursion', 'safari', 'holiday', 'package', 'travel'],
        'de' => ['reise', 'tour', 'ausflug', 'kreuzfahrt', 'urlaub', 'safari', 'ticket', 'buchen'],
        'fr' => ['voyage', 'circuit', 'excursion', 'croisière', 'séjour', 'safari', 'tour', 'visite'],
        'es' => ['viaje', 'tour', 'excursión', 'crucero', 'safari', 'vacaciones', 'paquete', 'visita'],
        'it' => ['viaggio', 'tour', 'escursion', 'crociera', 'safari', 'vacanz', 'pacchett', 'visita'],
        'ar' => ['رحلة', 'رحلات', 'جولة', 'سفاري', 'عمرة', 'حجز', 'عروض', 'فندق'],
    ];
    private const INFORMATIONAL_WORDS ='/\b(what|who|when|where|why|how|history|facts?|map|location|weather|population|queen|king|god|goddess|ancient|meaning|definition)\b|^(ما|من هو|من هي|تاريخ|أين|اين|كيف|معنى)\s/iu';
    private const TRAVEL_WORDS = '/tour|trip|travel|excursion|safari|cruise|holiday|vacation|transfer|sightseeing|day ?trip|umrah|umra|hajj|pilgrim|hotel|reise|ausflug|voyage|excursi|viaggi|escursion|viaje|رحل|سياح|جول|عطل|عمرة|العمره|حج|معتمر|فندق|فنادق|طيران|экскурс|тур/iu';
    /** لغة → سوق البحث الافتراضي (نفس SeoMarketService) */
    private const LOCATION_FOR_LANG = ['en' => 2840, 'ar' => 2818, 'de' => 2276, 'fr' => 2250, 'es' => 2724, 'it' => 2380, 'ru' => 2643, 'pt' => 2076, 'nl' => 2528, 'pl' => 2616];
    private const INDUSTRIES = ['tours', 'hotel', 'travel_agency', 'other'];

    public static function host(string $url): string
    {
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }
        return preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
    }

    /** بصمات أنظمة المواقع: [مفتاح, اسم, نمط في الـHTML أو الهيدرز] (الأدق الأول) */
    private const PLATFORMS = [
        ['wordpress', 'WordPress', '~/wp-content/|/wp-includes/|<meta[^>]+generator[^>]+WordPress|wp-json~i'],
        ['shopify', 'Shopify', '~cdn\.shopify\.com|Shopify\.theme|x-shopify~i'],
        ['wix', 'Wix', '~static\.wixstatic\.com|wix\.com|X-Wix-~i'],
        ['squarespace', 'Squarespace', '~static1\.squarespace\.com|squarespace-cdn|Squarespace~'],
        ['webflow', 'Webflow', '~data-wf-site|webflow\.js|assets\.website-files\.com~i'],
        ['duda', 'Duda', '~multiscreensite\.com|dudamobile|irp\.cdn-website\.com~i'],
        ['godaddy', 'GoDaddy Website Builder', '~img1\.wsimg\.com|godaddy~i'],
        ['hostinger', 'Hostinger Website Builder', '~zyrosite|zyro\.com|hostinger~i'],
        ['weebly', 'Weebly', '~weebly\.com|editmysite\.com~i'],
        ['joomla', 'Joomla', '~<meta[^>]+generator[^>]+Joomla|/media/jui/|/components/com_~i'],
        ['drupal', 'Drupal', '~Drupal\.settings|<meta[^>]+generator[^>]+Drupal|/sites/default/files/~i'],
        ['ghost', 'Ghost', '~<meta[^>]+generator[^>]+Ghost~i'],
        ['blogger', 'Blogger', '~blogger\.com|blogspot\.com~i'],
        ['framer', 'Framer', '~framerusercontent\.com|framer\.com/~i'],
        ['lovable', 'Lovable', '~lovable\.(app|dev)|gpteng~i'],
        ['nextjs', 'Next.js', '~__NEXT_DATA__|/_next/static/~i'],
        ['nuxt', 'Nuxt', '~__NUXT__|/_nuxt/~i'],
        ['react', 'React', '~<div id="root"></div>|data-reactroot~i'],
        ['laravel', 'Laravel', '~laravel_session|XSRF-TOKEN~i'],
    ];
    /** أنظمة الحجز السياحية (ميزة لمواقع السياحة) */
    private const BOOKING_ENGINES = [
        ['FareHarbor', '~fareharbor\.com~i'], ['Rezdy', '~rezdy\.com~i'], ['Bókun', '~bokun\.(io|is)|widgets\.bokun~i'],
        ['TrekkSoft', '~trekksoft~i'], ['Peek', '~peek\.com|peekpro~i'], ['Checkfront', '~checkfront~i'], ['TourCMS', '~tourcms~i'],
        ['Ventrata', '~ventrata~i'], ['Regiondo', '~regiondo~i'], ['bookingkit', '~bookingkit~i'], ['Xola', '~xola\.com~i'],
        ['WooCommerce', '~woocommerce~i'], ['WP Travel Engine', '~wp-travel-engine~i'], ['WP Travel', '~wp-travel/~i'],
        ['Viator widget', '~viator\.com/.*widget|widget\.viator~i'], ['GetYourGuide widget', '~widget\.getyourguide~i'],
        ['TripAdvisor widget', '~tripadvisor\.[a-z.]+/(WidgetEmbed|wejs)~i'], ['WhatsApp', '~wa\.me/|api\.whatsapp\.com~i'],
    ];

    /**
     * بنكتشف النظام اللي الموقع شغال عليه + نظام الحجز - بدل ما العميل يختار بنفسه (وغالبًا مايعرفش).
     * @return array{key:string,label:string,evidence:string,publish:string,booking:array}
     */
    public function detectPlatform(string $url): array
    {
        $out = ['key' => 'unknown', 'label' => '', 'evidence' => '', 'publish' => 'copy', 'booking' => []];
        if (!function_exists('curl_init') || (class_exists('SsrfGuard') && !SsrfGuard::isSafe($url))) return $out;
        $headers = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_ENCODING => '', CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TourfectoBot/1.0; +https://tourfecto.pro)',
            CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$headers) { if (strlen($headers) < 8000) $headers .= $h; return strlen($h); }]);
        $html = (string) curl_exec($ch);
        curl_close($ch);
        if ($html === '') return $out;
        $out = self::classify($headers . "\n" . substr($html, 0, 400000));
        // ووردبريس: نتأكد إن الـREST API مفتوح (ده اللي النشر التلقائي محتاجه)
        if ($out['key'] === 'wordpress' || $out['key'] === 'unknown') {
            $api = rtrim(preg_replace('~^(https?://[^/]+).*$~i', '$1', $url), '/') . '/wp-json/';
            $ch = curl_init($api);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_RANGE => '0-20000', CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TourfectoBot/1.0)']);
            $body = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code === 200 && str_contains($body, '"namespaces"')) {
                $booking = $out['booking'];
                $out = ['key' => 'wordpress', 'label' => 'WordPress', 'evidence' => $out['key'] === 'wordpress' ? $out['evidence'] : 'wp-json', 'publish' => 'wordpress', 'booking' => $booking];
            }
        }
        if ($out['key'] === 'wordpress') $out['publish'] = 'wordpress';
        // موقع PHP مبرمج خاص؟ يبقى أسهل ربط ملف واحد جنب index.php (tourfecto-connect.php) - ولو الملف متركّب أصلًا بيطبع meta tourfecto-connector
        $out['php'] = $out['key'] === 'laravel' || (bool) preg_match('~^(set-cookie:\s*PHPSESSID=|x-powered-by:\s*PHP)~im', $headers)
            || (bool) preg_match('~href="[^"#]+\.php(?:[?"])~i', substr($html, 0, 200000));
        $out['connector'] = stripos($html, 'name="tourfecto-connector"') !== false;
        // الموقع عنده مدونة بالفعل؟ (لينك /blog أو /news أو /guides في نفس الدومين) - عشان مانقترحش مدونة منفصلة عنها
        $out['blog_url'] = self::existingBlog($html, $url);
        return $out;
    }

    /** لينك مدونة الموقع نفسه من الـHTML (نفس الدومين بس) أو null */
    public static function existingBlog(string $html, string $base): ?string
    {
        $host = preg_replace('/^www\./', '', strtolower((string) parse_url($base, PHP_URL_HOST)));
        if ($host === '' || !preg_match_all('~href=["\']([^"\'#]+)["\']~i', $html, $m)) return null;
        foreach ($m[1] as $href) {
            $u = html_entity_decode(trim($href), ENT_QUOTES, 'UTF-8');
            if (str_starts_with($u, '/') && !str_starts_with($u, '//')) $u = rtrim(preg_replace('~^(https?://[^/]+).*$~i', '$1', $base), '/') . $u;
            $h = preg_replace('/^www\./', '', strtolower((string) parse_url($u, PHP_URL_HOST)));
            $path = strtolower((string) parse_url($u, PHP_URL_PATH));
            if ($h === $host && preg_match('~^/(?:[a-z]{2}/)?(blog|news|articles|guides|travel-guides?|stories|journal|magazine|مدونة)/?$~u', rawurldecode($path))) {
                return preg_replace('~[?#].*$~', '', $u);
            }
        }
        return null;
    }

    /** تصنيف صفحة (الهيدرز + الـHTML): النظام + نظام الحجز + طريقة النشر */
    public static function classify(string $hay): array
    {
        $out = ['key' => 'unknown', 'label' => '', 'evidence' => '', 'publish' => 'copy', 'booking' => []];
        foreach (self::PLATFORMS as [$key, $label, $re]) {
            if (preg_match($re, $hay, $m)) {
                $out['key'] = $key;
                $out['label'] = $label;
                $out['evidence'] = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($m[0]))), 0, 40);
                break;
            }
        }
        if ($out['key'] === 'wordpress') $out['publish'] = 'wordpress';
        elseif (in_array($out['key'], ['nextjs', 'nuxt', 'react', 'laravel', 'lovable'], true)) $out['publish'] = 'api';
        foreach (self::BOOKING_ENGINES as [$label, $re]) {
            if (preg_match($re, $hay)) $out['booking'][] = $label;
        }
        $out['booking'] = array_slice($out['booking'], 0, 4);
        return $out;
    }

    /** المرحلة 1: قراءة الموقع واستخراج بيانات النشاط */
    public function analyze(string $url, int $userId): array
    {
        $started = microtime(true);
        $host = self::host($url);
        $snap = (new WebsiteSnapshotFetcher())->asBrowser()->fetch($url);
        // ماحمّلش أو رجع صفحة فاضية/حماية: نجرب الشكل التاني للعنوان (بـ www أو من غيرها)
        if (!self::readable($snap)) {
            $alt = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.' . $host;
            try {
                $altSnap = (new WebsiteSnapshotFetcher())->asBrowser()->fetch('https://' . $alt . '/');
                if (self::readable($altSnap)) $snap = $altSnap;
            } catch (Throwable $e) {
            error_log('[SmartOnboardingService] ignored error: ' . $e->getMessage());
            }
        }
        $source = 'site';
        if (!self::readable($snap)) {
            // الموقع قافل علينا: نقرا صفحاته زي ما جوجل فهرسها (عناوين وأوصاف حقيقية من بحث site:)، مش تخمين
            $fromGoogle = $this->googleSnippets($host);
            if (!$fromGoogle) {
                if (class_exists('Logger')) Logger::warning('Smart onboarding: site unreadable', ['host' => $host, 'fetch' => (string) ($snap['error'] ?? 'thin_page')]);
                return ['ok' => false, 'reason' => 'fetch_failed', 'fallback' => ['business_name' => $this->nameFromTitle((string) ($snap['title'] ?? '')), 'description' => mb_substr((string) ($snap['meta_description'] ?? ''), 0, 350)]];
            }
            $snap = $fromGoogle + ['html_lang' => $snap['html_lang'] ?? null, 'alternates' => $snap['alternates'] ?? [], 'phones' => $snap['phones'] ?? []];
            $source = 'google';
        }
        $title = (string) ($snap['title'] ?? '');
        $desc = (string) ($snap['meta_description'] ?? '');
        $text = mb_substr((string) ($snap['normalized_excerpt'] ?? ''), 0, 6000);

        // لغة المحتوى = لغة الموقع نفسه (الموقع الفرنسي مكتوب لعملاء بيتكلموا فرنساوي). بتتحدد من النص بالكود، مش تخمين من النموذج
        $phoneCountries = self::phoneCountries((array) ($snap['phones'] ?? []));
        $arabicAlt = (string) (($snap['alternates'] ?? [])['ar'] ?? '');
        $htmlLang = (string) ($snap['html_lang'] ?? '');
        $gulfPhone = (bool) array_intersect($phoneCountries, self::GULF_COUNTRIES);
        // عنوان الصفحة ووصفها لجوجل هما اللي صاحب الموقع كاتبهم لسوقه، فلغتهم أقوى إشارة؛ بعدها نص الصفحة؛ بعدها وسم lang
        $metaText = $title . ' ' . $desc;
        $pageLang = (preg_match_all('/\pL/u', $metaText) >= 30 ? self::guessLang($metaText) : null)
            ?? self::guessLang($metaText . ' ' . $text) ?? (isset(self::LANG_NAMES[$htmlLang]) ? $htmlLang : '');
        // موقع خليجي بيفتح بالإنجليزي وليه نسخة عربي: عملاءه أهل البلد، فنقرا النسخة العربي ونكتب بالعربي
        if ($gulfPhone && $pageLang !== 'ar' && $arabicAlt !== '' && self::host($arabicAlt) === $host) {
            try {
                $arSnap = (new WebsiteSnapshotFetcher())->asBrowser()->fetch($arabicAlt);
                if (!empty($arSnap['success']) && self::arabicRatio((string) ($arSnap['normalized_excerpt'] ?? '')) >= 0.3) {
                    $text = mb_substr((string) $arSnap['normalized_excerpt'], 0, 6000) . "\n--- English version ---\n" . mb_substr($text, 0, 3000);
                    $pageLang = 'ar';
                }
            } catch (Throwable $e) {
            error_log('[SmartOnboardingService] ignored error: ' . $e->getMessage());
            }
        }
        $langName = self::LANG_NAMES[$pageLang] ?? '';
        $inLang = $langName !== '' ? "in {$langName}" : 'in the main language of the page text';
        // بنفس صيغة سياسة اللغة بتاعة طبقة التوجيه، عشان ماتضيفش «اكتب بالإنجليزي» الافتراضية (الزائر لسه مالوش لغة محفوظة)
        $langOrder = "\n[Tourfecto audience output policy]\nLANGUAGE: write every text value (description, services, location, target_customers, search_queries) "
            . ($langName !== '' ? "in {$langName}. Do not use English unless {$langName} is English." : 'in the main language of the page text.') . "\n";

        $prompt = "You are onboarding a travel/tourism business onto a marketing platform. Read ONLY the website content below and extract facts about the business.\n"
            . "STRICT RULES: use only what is on the page. If something is not stated or clearly implied, return an empty value - never guess or invent.\n"
            . $langOrder
            . "Return JSON only, exactly this shape:\n"
            . '{"business_name":"","description":"","industry":"tours|hotel|travel_agency|other","services":[],"site_language":"","target_countries":[],"currency":"","location":"","target_customers":"","search_queries":[],"competitor_domains":[]}' . "\n"
            . "- business_name: the brand name as written on the site (not the page title slogan, not \"Home\").\n"
            . "- description: 1-3 sentences (max 350 chars) describing what they sell and where, {$inLang}.\n"
            . "- services: up to 8 product/service names they actually offer, most important first, {$inLang}, phrased the way their customers search for them on Google. When they sell to residents of their own city, include the departure city and the local market term (e.g. Umrah bus trips sold in Saudi Arabia: \"حملات عمرة من الرياض\", not a generic \"رحلات العمرة\").\n"
            . "- site_language: ISO 639-1 code of the main content language.\n"
            . "- target_countries: up to 5 ISO 3166-1 alpha-2 codes of the countries where their CUSTOMERS live. Inbound tourism (foreign tourists visiting the business's own country, e.g. Egypt tours sold to Europeans): do NOT list the business's own country; list the source markets only if the page makes them clear (languages offered, explicit markets), otherwise []. Local or outbound businesses (e.g. a French agency selling trips to French travellers, Umrah trips departing from Riyadh): their own country.\n"
            . "- currency: ISO 4217 code of the prices shown on the site, or empty.\n"
            . "- location: city/region where they are based or operate, {$inLang}.\n"
            . "- target_customers: short phrase about who their customers are, {$inLang}, only if clear.\n"
            . "- search_queries: 3 Google searches their customers would type to find THESE services, {$inLang}.\n"
            . "- competitor_domains: up to 12 domains of real, independent tour operators/agencies that offer similar services to the same customers. Do NOT include booking marketplaces (TripAdvisor, Viator, GetYourGuide, Booking.com, Expedia), social networks, or {$host}. Only include domains you are confident exist.\n\n"
            . "Website: {$host}\nTitle: {$title}\nMeta description: {$desc}\nPage text:\n{$text}\n\n" . $langOrder;

        // نفس مسار الذكاء الاصطناعي بتاع منشئ المواقع (متظبط في توجيه الخدمات من الأدمن). محاولة تانية لو الرد فشل أو مش JSON والوقت يسمح
        $j = null;
        $res = [];
        for ($try = 1; $try <= 2 && !$j; $try++) {
            if ($try === 2 && microtime(true) - $started > 25) break;
            // استخراج بيانات من صفحة مش محتاج تفكير طويل: تفكير محدود عشان الرد مايعدّيش مهلة الـ 60 ثانية.
            // لو الموديل رفض الإعداد ده، المحاولة التانية من غيره
            $opts = ['task' => 'website_builder', 'user_id' => $userId, 'temperature' => 0.2, 'maxOutputTokens' => 8192, 'responseMimeType' => 'application/json'];
            if ($try === 1) $opts['thinkingConfig'] = ['thinkingBudget' => 1024];
            $res = (new GeminiClient())->generateContent($prompt, $opts);
            $j = !empty($res['success']) ? $this->parseJson((string) $res['data']) : null;
            if (!$j && class_exists('Logger')) Logger::warning('Smart onboarding AI failed', ['try' => $try, 'host' => $host, 'seconds' => round(microtime(true) - $started), 'diag' => (string) ($res['diag_code'] ?? ''), 'technical' => mb_substr((string) ($res['technical_error'] ?? $res['error'] ?? ''), 0, 200), 'snippet' => mb_substr((string) ($res['data'] ?? ''), 0, 200)]);
        }
        if (!$j) {
            return ['ok' => false, 'reason' => 'ai_failed', 'fallback' => ['business_name' => $this->nameFromTitle($title), 'description' => mb_substr($desc, 0, 350)]];
        }

        $str = static fn ($v, int $max) => mb_substr(trim(strip_tags((string) $v)), 0, $max);
        $services = [];
        foreach ((array) ($j['services'] ?? []) as $s) {
            $s = $str($s, 60);
            if ($s !== '' && !in_array($s, $services, true)) $services[] = $s;
        }
        $countries = [];
        foreach ((array) ($j['target_countries'] ?? []) as $c) {
            $c = strtoupper(trim((string) $c));
            if (preg_match('/^[A-Z]{2}$/', $c) && !in_array($c, $countries, true)) $countries[] = $c;
        }
        $lang = $pageLang ?: strtolower(substr(trim((string) ($j['site_language'] ?? '')), 0, 2));
        // سوق عربي محلي: الموقع عربي، أو اسم الشركة عربي وكل الدول المستهدفة عربية
        $arabicMarket = $lang === 'ar'
            || (self::arabicRatio((string) ($j['business_name'] ?? '')) >= 0.5 && $countries && !array_diff($countries, self::ARAB_COUNTRIES));
        if ($arabicMarket) {
            $lang = 'ar';
        } else {
            // سياحة وافدة بموقع غير عربي: البلد العربي اللي فيه الشركة مش سوق عملاءها
            $countries = array_values(array_diff($countries, self::ARAB_COUNTRIES));
        }
        // رقم التليفون بيقول الشركة فين، فبيتستخدم كسوق للسوق المحلي بس
        if (!$countries && $arabicMarket) {
            $countries = array_slice(array_values(array_intersect($phoneCountries, self::ARAB_COUNTRIES)), 0, 2);
        }
        $cur = strtoupper(trim((string) ($j['currency'] ?? '')));
        // العملة مش مكتوبة صريحة (أو طلعت دولار في سوق محلي والصفحة فيها ريال/درهم/جنيه): من نص الصفحة، وبعدين من الدولة المستهدفة
        if (!preg_match('/^[A-Z]{3}$/', $cur) || ($cur === 'USD' && $arabicMarket)) {
            $found = $arabicMarket ? self::currencyFromText($text) : null;
            if ($found) $cur = $found;
            elseif (!preg_match('/^[A-Z]{3}$/', $cur) && $countries && isset(self::COUNTRY_CURRENCY[$countries[0]])) $cur = self::COUNTRY_CURRENCY[$countries[0]];
        }
        $queries = array_values(array_filter(array_map(fn ($q) => $str($q, 80), (array) ($j['search_queries'] ?? [])), 'strlen'));
        $cands = [];
        foreach ((array) ($j['competitor_domains'] ?? []) as $d) {
            $h = self::host((string) $d);
            if ($h !== '' && $h !== $host && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $h) && !$this->isMarketplace($h)) $cands[] = $h;
        }

        $bizName = $str($j['business_name'] ?? '', 120) ?: $this->nameFromTitle($title);
        $description = $str($j['description'] ?? '', 400) ?: mb_substr($desc, 0, 350);
        $location = $str($j['location'] ?? '', 80);
        $targetCustomers = $str($j['target_customers'] ?? '', 200);
        // الموقع ماتقريش فعليًا (صفحة فاضية/جافاسكريبت): نقول كده بدل فورم فاضي على إنه نجاح
        if ($description === '' && !$services) {
            return ['ok' => false, 'reason' => 'empty', 'fallback' => ['business_name' => $bizName, 'description' => '']];
        }

        $platform = ['key' => 'unknown', 'label' => '', 'evidence' => '', 'publish' => 'copy', 'booking' => []];
        try { $platform = $this->detectPlatform($url); } catch (Throwable $e) { error_log('[SmartOnboardingService] ignored error: ' . $e->getMessage()); }

        // الوصف طلع بلغة غير لغة الموقع: نعيد كتابة الملف بلغة الموقع ونتأكد من النتيجة
        $written = $description . ' ' . implode(' ', $services) . ' ' . implode(' ', $queries);
        if (isset(self::LANG_NAMES[$lang]) && self::wrongLanguage(str_ireplace($bizName, ' ', $written), $lang) && microtime(true) - $started <= 70) {
            $tr = $this->rewriteIn($lang, ['description' => $description, 'services' => $services, 'location' => $location, 'target_customers' => $targetCustomers, 'search_queries' => $queries], $userId);
            if ($tr) {
                $description = $str($tr['description'] ?? '', 400) ?: $description;
                $location = $str($tr['location'] ?? '', 80) ?: $location;
                $targetCustomers = $str($tr['target_customers'] ?? '', 200) ?: $targetCustomers;
                $trServices = array_values(array_unique(array_filter(array_map(fn ($x) => $str($x, 60), (array) ($tr['services'] ?? [])), 'strlen')));
                if ($trServices) $services = $trServices;
                $trQueries = array_values(array_filter(array_map(fn ($q) => $str($q, 80), (array) ($tr['search_queries'] ?? [])), 'strlen'));
                if ($trQueries) $queries = $trQueries;
            }
        }

        // وكالة عمرة بتبيع لأهل مدينة سعودية: العملاء بيدوّروا على «حملات عمرة من <المدينة>»، فلازم تبقى أول خدمة
        $city = trim(preg_replace('/[،,].*$/u', '', $location));
        if ($city !== '' && self::arabicRatio($city) >= 0.5 && in_array('SA', $countries, true) && preg_grep('/عمر[ةه]/u', $services) && !preg_grep('/' . preg_quote($city, '/') . '/u', $services)) {
            array_unshift($services, 'حملات عمرة من ' . $city);
            $services = array_values(array_unique($services));
        }

        return ['ok' => true, 'profile' => [
            'read_from' => $source,
            'platform' => $platform,
            'business_name' => $bizName,
            'description' => $description,
            'industry' => in_array($j['industry'] ?? '', self::INDUSTRIES, true) ? $j['industry'] : 'tours',
            'services' => array_slice($services, 0, 8),
            'site_language' => preg_match('/^[a-z]{2}$/', $lang) ? $lang : '',
            'target_countries' => array_slice($countries, 0, 5),
            'currency' => preg_match('/^[A-Z]{3}$/', $cur) ? $cur : '',
            'location' => $location,
            'target_customers' => $targetCustomers,
            'search_queries' => array_slice($queries, 0, 3),
            'competitor_candidates' => array_slice(array_values(array_unique($cands)), 0, 12),
        ]];
    }

    /** بيتغيّر مع أي تعديل في منطق التحليل، عشان النتايج المحفوظة القديمة ماترجعش */
    public const PROFILE_VERSION = 'p70';

    private const GULF_COUNTRIES = ['SA', 'AE', 'KW', 'QA', 'BH', 'OM'];
    private const ARAB_COUNTRIES = ['SA', 'AE', 'KW', 'QA', 'BH', 'OM', 'EG', 'JO', 'LB', 'IQ', 'MA', 'DZ', 'TN', 'LY', 'SD', 'YE', 'SY', 'PS'];

    /** مفتاح الدولة في الرقم الدولي → كود الدولة (أطول مفتاح الأول) */
    private const DIAL_CODES = ['966' => 'SA', '971' => 'AE', '965' => 'KW', '974' => 'QA', '973' => 'BH', '968' => 'OM', '962' => 'JO', '20' => 'EG', '44' => 'GB', '49' => 'DE', '33' => 'FR', '39' => 'IT', '34' => 'ES', '31' => 'NL', '1' => 'US'];

    /** @return string[] دول أرقام التليفون/الواتساب اللي على الموقع (بالترتيب، من غير تكرار) */
    public static function phoneCountries(array $phones): array
    {
        $out = [];
        foreach ($phones as $n) {
            $n = preg_replace('/^00/', '', preg_replace('/\D/', '', (string) $n));
            if (strlen($n) < 9) continue;
            foreach (self::DIAL_CODES as $code => $cc) {
                if (strncmp($n, (string) $code, strlen((string) $code)) === 0 && ($code !== 1 || strlen($n) === 11)) {
                    if (!in_array($cc, $out, true)) $out[] = $cc;
                    break;
                }
            }
        }
        return $out;
    }

    private const COUNTRY_CURRENCY = ['SA' => 'SAR', 'AE' => 'AED', 'KW' => 'KWD', 'QA' => 'QAR', 'BH' => 'BHD', 'OM' => 'OMR', 'EG' => 'EGP', 'JO' => 'JOD', 'GB' => 'GBP', 'US' => 'USD',
        'DE' => 'EUR', 'FR' => 'EUR', 'IT' => 'EUR', 'ES' => 'EUR', 'NL' => 'EUR', 'AT' => 'EUR', 'BE' => 'EUR', 'IE' => 'EUR', 'PT' => 'EUR'];

    /** عملة محلية مكتوبة في نص الصفحة (ريال سعودي، درهم، جنيه...) أو null */
    public static function currencyFromText(string $text): ?string
    {
        $map = ['SAR' => '/ريال سعودي|ر\.س|\bSAR\b|\bSR\s?\d/u', 'AED' => '/درهم|\bAED\b/u', 'KWD' => '/دينار كويتي|د\.ك|\bKWD\b/u', 'QAR' => '/ريال قطري|ر\.ق|\bQAR\b/u', 'EGP' => '/جنيه|ج\.م|\bEGP\b|\bLE\s?\d/u'];
        foreach ($map as $code => $re) {
            if (preg_match($re, $text)) return $code;
        }
        return preg_match('/(?<!\p{L})ريال(?!\p{L})/u', $text) ? 'SAR' : null;
    }

    /** إعادة كتابة ملف النشاط بلغة الموقع (ISO 639-1)، بعد التأكد إن النتيجة فعلًا بنفس اللغة، أو null */
    private function rewriteIn(string $lang, array $fields, int $userId): ?array
    {
        $name = self::LANG_NAMES[$lang] ?? $lang;
        $prompt = "Rewrite these travel-business profile fields in {$name}, as the business would present itself to its customers. Keep the meaning, keep brand names, do not add facts. Name services the way customers in that market search for them on Google. Return JSON only with the same keys and types.\n"
            . json_encode($fields, JSON_UNESCAPED_UNICODE)
            . "\n[Tourfecto audience output policy]\nWrite every text value in {$name}.\n";
        try {
            $res = (new GeminiClient())->generateContent($prompt, ['task' => 'website_builder', 'user_id' => $userId, 'temperature' => 0.2, 'maxOutputTokens' => 8192, 'responseMimeType' => 'application/json']);
        } catch (Throwable $e) {
            $res = ['success' => false, 'error' => $e->getMessage()];
        }
        $j = !empty($res['success']) ? $this->parseJson((string) $res['data']) : null;
        $got = $j ? self::guessLang((string) ($j['description'] ?? '')) : null;
        if ($j && ($got === null || $got === $lang)) return $j;
        if (class_exists('Logger')) Logger::warning('Smart onboarding rewrite failed', ['lang' => $lang, 'got' => $got, 'error' => mb_substr((string) ($res['error'] ?? ''), 0, 200)]);
        return null;
    }

    /** لغات المحتوى اللي بنكتب بيها (ISO 639-1 → اسمها للنموذج) */
    private const LANG_NAMES = ['ar' => 'Arabic', 'en' => 'English', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish', 'it' => 'Italian',
        'pt' => 'Portuguese', 'nl' => 'Dutch', 'ru' => 'Russian', 'pl' => 'Polish', 'tr' => 'Turkish', 'zh' => 'Chinese', 'ja' => 'Japanese'];

    /** كلمات شائعة جدًا في كل لغة: بنعرف بيها لغة النص من غير ذكاء اصطناعي */
    private const STOPWORDS = [
        'en' => ['the', 'and', 'of', 'to', 'with', 'for', 'our', 'your', 'you', 'we', 'is', 'are', 'from', 'tours', 'book', 'an', 'by', 'its', 'their', 'offers', 'offering', 'including', 'which', 'that', 'this', 'over', 'worldwide', 'packages', 'trips', 'booking', 'holiday', 'vacation'],
        'fr' => ['le', 'la', 'les', 'des', 'et', 'du', 'une', 'pour', 'avec', 'vos', 'nos', 'votre', 'est', 'sur', 'voyage', 'voyages'],
        'de' => ['der', 'die', 'das', 'und', 'mit', 'für', 'ihre', 'unsere', 'ein', 'eine', 'ist', 'sie', 'reisen', 'reise', 'auf', 'nach'],
        'es' => ['el', 'los', 'las', 'y', 'del', 'con', 'para', 'sus', 'nuestros', 'una', 'es', 'por', 'viajes', 'viaje', 'su', 'tu'],
        'it' => ['il', 'gli', 'della', 'delle', 'e', 'con', 'per', 'nostri', 'una', 'è', 'viaggi', 'viaggio', 'dei', 'alla', 'tuo'],
        'pt' => ['os', 'as', 'com', 'para', 'seus', 'nossos', 'uma', 'é', 'viagens', 'viagem', 'do', 'da', 'não', 'seu', 'em'],
        'nl' => ['het', 'en', 'van', 'met', 'voor', 'onze', 'uw', 'een', 'is', 'reizen', 'reis', 'naar', 'jouw', 'je', 'op'],
        'tr' => ['ve', 'bir', 'ile', 'için', 'bu', 'turu', 'turlar', 'otel', 'tatil', 'olarak', 'en', 'da', 'de'],
        'pl' => ['i', 'w', 'z', 'na', 'do', 'dla', 'się', 'oraz', 'wycieczki', 'podróże', 'jest', 'od'],
    ];

    /** النص مكتوب بلغة غير المطلوبة؟ (إنجليزي مكان الألماني مثلًا). اسم الشركة بيتشال قبلها عشان «Studiosus Reisen» مايلخبطش الحكم */
    public static function wrongLanguage(string $text, string $lang): bool
    {
        $letters = preg_match_all('/\pL/u', $text);
        if ($letters < 15) return false;
        $script = ['ar' => '/\p{Arabic}/u', 'ru' => '/\p{Cyrillic}/u', 'zh' => '/\p{Han}/u', 'ja' => '/[\p{Hiragana}\p{Katakana}\p{Han}]/u'];
        if (isset($script[$lang])) return preg_match_all($script[$lang], $text) / $letters < 0.3;
        // لغة بحروف لاتينية: لازم تبقى غالبة على الإنجليزي (ولو اللغة إنجليزي: مفيش عربي/سيريلي ولغة تانية غالبة)
        if (preg_match_all('/[\p{Arabic}\p{Cyrillic}\p{Han}]/u', $text) / $letters >= 0.3) return true;
        $words = array_count_values(preg_split('/[^\p{L}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY));
        $score = fn (string $l) => array_sum(array_map(fn ($w) => $words[$w] ?? 0, self::STOPWORDS[$l] ?? []));
        if ($lang === 'en') {
            $g = self::guessLang($text);
            return $g !== null && $g !== 'en';
        }
        return isset(self::STOPWORDS[$lang]) && $score('en') > $score($lang);
    }

    /** لغة النص (ISO 639-1) أو null لو مش واضحة */
    public static function guessLang(string $text): ?string
    {
        $text = mb_substr($text, 0, 6000);
        $letters = preg_match_all('/\pL/u', $text);
        if ($letters < 15) return null;
        if (preg_match_all('/\p{Arabic}/u', $text) / $letters >= 0.3) return 'ar';
        if (preg_match_all('/\p{Cyrillic}/u', $text) / $letters >= 0.3) return 'ru';
        if (preg_match_all('/\p{Han}/u', $text) / $letters >= 0.3) return 'zh';
        if (preg_match_all('/[\p{Hiragana}\p{Katakana}]/u', $text) / $letters >= 0.1) return 'ja';
        $words = array_count_values(preg_split('/[^\p{L}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY));
        $best = null;
        $bestScore = 0;
        $second = 0;
        foreach (self::STOPWORDS as $l => $list) {
            $score = 0;
            foreach ($list as $w) $score += $words[$w] ?? 0;
            if ($score > $bestScore) { $second = $bestScore; $bestScore = $score; $best = $l; }
            elseif ($score > $second) { $second = $score; }
        }
        // لازم فرق واضح عن اللغة التانية عشان مانحكمش غلط على نص قصير
        return ($bestScore >= 3 && $bestScore >= $second * 1.5) ? $best : null;
    }

    /** الصفحة اتقرت فعلًا وفيها كلام كفاية نفهم منه النشاط (مش صفحة حماية أو جافاسكريبت فاضية) */
    private static function readable($snap): bool
    {
        if (!is_array($snap) || empty($snap['success'])) return false;
        $all = (string) ($snap['title'] ?? '') . ' ' . (string) ($snap['meta_description'] ?? '') . ' ' . (string) ($snap['normalized_excerpt'] ?? '');
        if (preg_match('/just a moment|attention required|access denied|enable javascript|checking your browser|captcha/i', mb_substr($all, 0, 600))) return false;
        return preg_match_all('/\pL/u', $all) >= 250;
    }

    /**
     * صفحات الموقع زي ما جوجل فهرسها (بحث site:domain): عنوان + وصف كل صفحة. بيانات حقيقية من جوجل لما الموقع يقفل على السيرفر.
     * @return array{success:bool,title:string,meta_description:string,normalized_excerpt:string}|null
     */
    private function googleSnippets(string $host): ?array
    {
        $client = $this->dfs();
        if (!$client || $host === '') return null;
        try {
            $r = $client->callPlatform('serp/google/organic/live/advanced', [['keyword' => 'site:' . preg_replace('/^www\./', '', $host), 'location_code' => 2840, 'language_code' => 'en', 'depth' => 20]], 0.01);
        } catch (Throwable $e) {
            return null;
        }
        if (empty($r['available'])) return null;
        $lines = [];
        $first = null;
        foreach (DataForSeoClient::items($r) as $it) {
            if (($it['type'] ?? '') !== 'organic') continue;
            $t = trim((string) ($it['title'] ?? ''));
            $d = trim((string) ($it['description'] ?? ''));
            if ($t === '' && $d === '') continue;
            $first ??= ['title' => $t, 'desc' => $d];
            $lines[] = $t . ' — ' . $d;
        }
        if (count($lines) < 2) return null;
        return ['success' => true, 'title' => $first['title'], 'meta_description' => $first['desc'],
            'normalized_excerpt' => "Pages of this website as indexed by Google (title — description):\n" . implode("\n", $lines)];
    }

    /** نسبة الحروف العربية من الحروف في النص (0..1) */
    public static function arabicRatio(string $text): float
    {
        $letters = preg_match_all('/\pL/u', $text);
        return $letters ? preg_match_all('/\p{Arabic}/u', $text) / $letters : 0.0;
    }

    /** دولة → [location_code, لغة البحث الأساسية] في DataForSEO */
    private const COUNTRY_LOC = [
        'US' => [2840, 'en'], 'GB' => [2826, 'en'], 'CA' => [2124, 'en'], 'AU' => [2036, 'en'], 'IE' => [2372, 'en'], 'NZ' => [2554, 'en'],
        'DE' => [2276, 'de'], 'AT' => [2040, 'de'], 'CH' => [2756, 'de'], 'FR' => [2250, 'fr'], 'BE' => [2056, 'fr'], 'IT' => [2380, 'it'],
        'ES' => [2724, 'es'], 'MX' => [2484, 'es'], 'NL' => [2528, 'nl'], 'PL' => [2616, 'pl'], 'PT' => [2620, 'pt'], 'BR' => [2076, 'pt'],
        'RU' => [2643, 'ru'], 'SE' => [2752, 'sv'], 'DK' => [2208, 'da'], 'NO' => [2578, 'no'], 'CZ' => [2203, 'cs'],
        'EG' => [2818, 'ar'], 'SA' => [2682, 'ar'], 'AE' => [2784, 'ar'], 'KW' => [2414, 'ar'], 'QA' => [2634, 'ar'], 'JO' => [2400, 'ar'],
    ];

    /** السوق اللي بنقيس فيه: أول دولة مستهدفة بلغة الموقع، وإلا السوق الافتراضي للغة */
    /** كود دولة (US...) من location_code بتاع DataForSEO - للأسواق المتسجلة في الـSEO */
    public static function countryForLocation(int $locationCode): ?string
    {
        foreach (self::COUNTRY_LOC as $country => [$code]) {
            if ($code === $locationCode) return $country;
        }
        return null;
    }

    private function market(array $countries, string $language): array
    {
        foreach ($countries as $c) {
            if (isset(self::COUNTRY_LOC[$c]) && self::COUNTRY_LOC[$c][1] === $language) return ['country' => $c, 'code' => self::COUNTRY_LOC[$c][0], 'lang' => $language];
        }
        // الدولة المستهدفة معروفة بس لغة الموقع غير لغتها (موقع إنجليزي لعملاء في الرياض): ندوّر في سوقها بلغتها
        foreach ($countries as $c) {
            if (isset(self::COUNTRY_LOC[$c])) return ['country' => $c, 'code' => self::COUNTRY_LOC[$c][0], 'lang' => self::COUNTRY_LOC[$c][1]];
        }
        $lang = isset(self::LOCATION_FOR_LANG[$language]) ? $language : 'en';
        $cc = array_search([self::LOCATION_FOR_LANG[$lang], $lang], self::COUNTRY_LOC, true) ?: 'US';
        return ['country' => $cc, 'code' => self::LOCATION_FOR_LANG[$lang], 'lang' => $lang];
    }

    public function __construct(private ?DataForSeoClient $client = null)
    {
    }

    private function dfs(): ?DataForSeoClient
    {
        if (!class_exists('DataForSeoClient')) return null;
        $c = $this->client ?? new DataForSeoClient();
        return $c->isConfigured() ? $c : null;
    }

    /**
     * المرحلة 2: المنافسين + أرقام حقيقية من جوجل (DataForSEO) لو متاحة:
     *  - موقعك: عدد الكلمات اللي ظاهر بيها + الزيارات الشهرية التقديرية + ترتيبك بين المنافسين
     *  - المنافسين: الكلمات المشتركة معاك + زياراتهم التقديرية + لغات/أسواق مواقعهم (من الموقع نفسه)
     *  - كلماتك: الكلمات اللي ظاهر بيها + ترتيبك + حجم البحث الشهري
     * لو مفيش بيانات مقاسة: اقتراحات متأكدين إنها مواقع سياحية شغالة، من غير أي أرقام.
     */
    public function competitors(string $ownUrl, array $queries, array $candidates, string $language, int $websiteId = 0, array $countries = []): array
    {
        $own = self::host($ownUrl);
        $m = $this->market($countries, $language);
        $client = $this->dfs();
        $site = null;
        $keywords = [];
        $pool = [];
        $source = 'ai_verified';

        if ($client) {
            $base = ['target' => $own, 'location_code' => $m['code'], 'language_code' => $m['lang']];
            $ov = $client->callPlatform('dataforseo_labs/google/domain_rank_overview/live', [$base], 0.02);
            if (empty($ov['available']) && class_exists('Logger')) {
                Logger::warning('Smart onboarding: Google data unavailable', ['reason' => $ov['reason'] ?? 'unknown', 'site' => $own]);
            }
            $org = $ov['available'] ? (DataForSeoClient::items($ov)[0]['metrics']['organic'] ?? null) : null;
            if ($org) {
                $site = ['keywords' => (int) ($org['count'] ?? 0), 'traffic' => (int) round((float) ($org['etv'] ?? 0)), 'top10' => (int) (($org['pos_1'] ?? 0) + ($org['pos_2_3'] ?? 0) + ($org['pos_4_10'] ?? 0))];
            }
            if ($site && $site['keywords'] > 0) {
                $cd = $client->callPlatform('dataforseo_labs/google/competitors_domain/live', [$base + ['limit' => 20, 'exclude_top_domains' => true]], 0.03);
                foreach ($cd['available'] ? DataForSeoClient::items($cd) : [] as $it) {
                    $h = self::host((string) ($it['domain'] ?? ''));
                    if ($h === '' || $h === $own || $this->isMarketplace($h)) continue;
                    $o = $it['full_domain_metrics']['organic'] ?? ($it['metrics']['organic'] ?? []);
                    if (self::isPublisher((int) ($o['count'] ?? 0), $site['keywords'])) continue;
                    $pool[$h] = ['shared' => (int) ($it['intersections'] ?? 0), 'traffic' => (int) round((float) ($o['etv'] ?? 0)), 'keywords' => (int) ($o['count'] ?? 0), 'position' => null, 'via' => 'labs'];
                }
                // كلمات الحجز الأول (tour/trip/cruise... بلغة السوق): أعلى 40 بحثًا لوحدهم كانوا كلهم معلوماتية
                // («egypt»، «mumbai»، «petra»). لو كلمات الحجز قليلة نكمّل بالأكتر بحثًا زي الأول.
                $ranked = fn (array $extra) => self::rankedKeywords($client->callPlatform('dataforseo_labs/google/ranked_keywords/live', [$base + ['limit' => 40, 'order_by' => ['keyword_data.keyword_info.search_volume,desc']] + $extra], 0.03));
                $found = $ranked(['filters' => self::bookingKeywordFilter($m['lang'])]);
                if (count($found) < 6) {
                    $seen = array_flip(array_column($found, 'keyword'));
                    foreach ($ranked([]) as $k) {
                        if (!isset($seen[$k['keyword']])) $found[] = $k;
                    }
                }
                $keywords = self::bookingKeywordsFirst($found, 12);
                if ($pool) $source = 'google_data';
            }
            // موقع جديد أو بيانات قليلة: نتايج جوجل الحقيقية لجمل البحث
            if (count($pool) < 5) {
                foreach (array_slice($queries, 0, 2) as $q) {
                    $r = $client->callPlatform('serp/google/organic/live/advanced', [['keyword' => $q, 'location_code' => $m['code'], 'language_code' => $m['lang'], 'depth' => 20]], 0.01);
                    if (empty($r['available'])) break;
                    foreach (DataForSeoClient::items($r) as $it) {
                        if (($it['type'] ?? '') !== 'organic') continue;
                        $h = self::host((string) ($it['domain'] ?? ''));
                        if ($h === '' || $h === $own || $this->isMarketplace($h)) continue;
                        $pos = (int) ($it['rank_absolute'] ?? 99);
                        if (!isset($pool[$h])) $pool[$h] = ['shared' => null, 'traffic' => null, 'keywords' => null, 'position' => $pos, 'via' => 'serp', 'query' => $q];
                        elseif ($pool[$h]['position'] === null || $pos < $pool[$h]['position']) { $pool[$h]['position'] = $pos; $pool[$h]['query'] = $q; }
                    }
                    if ($source === 'ai_verified') $source = 'google';
                }
            }
        }
        // لسه قليلين: اقتراحات الذكاء الاصطناعي (بتتعرض بس بعد ما نتأكد منها)
        foreach ($candidates as $c) {
            if (count($pool) >= 14) break;
            $h = self::host((string) $c);
            if ($h !== '' && $h !== $own && !$this->isMarketplace($h) && !isset($pool[$h])) $pool[$h] = ['shared' => null, 'traffic' => null, 'keywords' => null, 'position' => null, 'via' => 'ai'];
        }

        uasort($pool, function ($a, $b) {
            $rank = ['labs' => 0, 'serp' => 1, 'ai' => 2];
            if ($a['via'] !== $b['via']) return $rank[$a['via']] <=> $rank[$b['via']];
            if ($a['via'] === 'labs') return $b['shared'] <=> $a['shared'];
            if ($a['via'] === 'serp') return $a['position'] <=> $b['position'];
            return 0;
        });
        $verified = $this->verify(array_slice(array_keys($pool), 0, 14), 7);
        $out = [];
        foreach ($pool as $d => $x) {
            if (!isset($verified[$d])) continue;
            $out[] = ['domain' => $d, 'title' => $verified[$d]['title'], 'languages' => $verified[$d]['langs'], 'position' => $x['position'], 'query' => $x['query'] ?? null,
                'shared_keywords' => $x['shared'], 'traffic' => $x['traffic'], 'keywords' => $x['keywords'], 'measured' => $x['via'] !== 'ai'];
            if (count($out) >= 8) break;
        }
        // مواقع طالعة فعلًا في نتايج جوجل بس رفضت فحصنا (Cloudflare مثلًا): تتعرض برضه، لأن مصدرها جوجل مش تخمين
        if (count($out) < 3) {
            foreach ($pool as $d => $x) {
                if (isset($verified[$d]) || $x['via'] === 'ai') continue;
                $out[] = ['domain' => $d, 'title' => $d, 'languages' => [], 'position' => $x['position'], 'query' => $x['query'] ?? null,
                    'shared_keywords' => $x['shared'], 'traffic' => $x['traffic'], 'keywords' => $x['keywords'], 'measured' => true];
                if (count($out) >= 5) break;
            }
        }
        if (!$out && class_exists('Logger')) {
            Logger::warning('Smart onboarding: no competitors', ['site' => $own, 'market' => $m['country'] . '/' . $m['lang'], 'pool' => count($pool), 'verified' => count($verified), 'google' => $client ? 1 : 0]);
        }
        // المنافسين اللي جايين من البحث/الذكاء الاصطناعي مالهمش أرقام: طلب واحد رخيص (~$0.01) بيقيس زيارات جوجل
        // التقديرية ليهم كلهم ولموقعك، عشان الترتيب يظهر دايمًا مع نفس المنافسين المعروضين
        $missing = array_values(array_unique(array_merge($site ? [] : [$own], array_column(array_filter($out, fn ($c) => $c['traffic'] === null), 'domain'))));
        if ($client && $missing) {
            // تقدير الزيارات إضافة: لو فشل، المنافسين يفضلوا ظاهرين من غير أرقام
            try {
                $bt = $client->callPlatform('dataforseo_labs/google/bulk_traffic_estimation/live', [['targets' => $missing, 'location_code' => $m['code'], 'language_code' => $m['lang'], 'item_types' => ['organic']]], 0.02);
            } catch (Throwable $e) {
                $bt = ['available' => false];
            }
            $est = [];
            foreach ($bt['available'] ? DataForSeoClient::items($bt) : [] as $it) {
                $o = $it['metrics']['organic'] ?? [];
                $est[self::host((string) ($it['target'] ?? ''))] = ['traffic' => (int) round((float) ($o['etv'] ?? 0)), 'keywords' => (int) ($o['count'] ?? 0)];
            }
            if ($bt['available']) {
                // موقعك: لو مش ظاهر في جوجل خالص فزياراته المقاسة صفر فعلًا (مش رقم متخيَّل)
                if (!$site) $site = ['keywords' => $est[$own]['keywords'] ?? 0, 'traffic' => $est[$own]['traffic'] ?? 0, 'top10' => null];
                foreach ($out as &$c) {
                    if ($c['traffic'] === null && isset($est[$c['domain']])) {
                        $c['traffic'] = $est[$c['domain']]['traffic'];
                        $c['keywords'] = $est[$c['domain']]['keywords'];
                        $c['measured'] = true;
                    }
                }
                unset($c);
            }
        }
        // اللي جايين من البحث/الذكاء الاصطناعي اتقاسوا دلوقتي بس: الناشرين العمالقة يخرجوا من هنا كمان
        $out = array_values(array_filter($out, fn ($c) => !self::isPublisher((int) ($c['keywords'] ?? 0), (int) ($site['keywords'] ?? 0))));
        // ترتيبك بين المنافسين حسب الزيارات التقديرية: إنت + اللي فوقك على طول + اللي تحتك على طول
        if ($site) {
            $ladder = [['domain' => $own, 'traffic' => $site['traffic'], 'keywords' => $site['keywords'], 'you' => true]];
            foreach ($out as $c) {
                if ($c['traffic'] !== null) $ladder[] = ['domain' => $c['domain'], 'traffic' => $c['traffic'], 'keywords' => $c['keywords'], 'you' => false];
            }
            usort($ladder, fn ($a, $b) => $b['traffic'] <=> $a['traffic'] ?: ($a['you'] ? 1 : -1));
            foreach ($ladder as $i => &$row) $row['rank'] = $i + 1;
            unset($row);
            $me = (int) array_search(true, array_column($ladder, 'you'), true);
            $site['ladder'] = array_values(array_slice($ladder, max(0, $me - 1), $me === 0 ? 2 : 3));
        }
        if ($site) {
            $traffics = array_values(array_filter(array_map(fn ($c) => $c['traffic'], $out), fn ($t) => $t !== null));
            if ($traffics) {
                $site['rank'] = 1 + count(array_filter($traffics, fn ($t) => $t > $site['traffic']));
                $site['of'] = count($traffics) + 1;
            }
        }
        return ['source' => $source, 'market' => $m, 'site' => $site, 'keywords' => $keywords, 'competitors' => $out];
    }

    /**
     * نسخة أخف من competitors() لخدمة السوشيال: منافسين من بيانات جوجل الحقيقية بس، من غير كلمات ولا ترتيب ولا تقدير زيارات.
     * طلبين (ترتيب الموقع + المواقع المنافسة) بدل 3-5. التكلفة اللي بترجع هي الفعلية من رد المزوّد.
     * @return array{source:string, reason?:string, competitors:list<array>, cost:float}
     */
    public function competitorsLean(string $ownUrl, string $language, array $countries = []): array
    {
        $own = self::host($ownUrl);
        $m = $this->market($countries, $language);
        $client = $this->dfs();
        if (!$client) {
            return ['source' => 'none', 'reason' => 'provider_not_configured', 'competitors' => [], 'cost' => 0.0];
        }
        $cost = 0.0;
        $base = ['target' => $own, 'location_code' => $m['code'], 'language_code' => $m['lang']];
        $ov = $client->callPlatform('dataforseo_labs/google/domain_rank_overview/live', [$base], 0.02);
        $cost += (float) ($ov['cost'] ?? 0);
        if (empty($ov['available'])) {
            return ['source' => 'none', 'reason' => (string) ($ov['reason'] ?? 'unavailable'), 'competitors' => [], 'cost' => $cost];
        }
        $ownKw = (int) (DataForSeoClient::items($ov)[0]['metrics']['organic']['count'] ?? 0);
        if ($ownKw <= 0) {
            return ['source' => 'none', 'reason' => 'site_not_ranked', 'competitors' => [], 'cost' => $cost];
        }
        $cd = $client->callPlatform('dataforseo_labs/google/competitors_domain/live', [$base + ['limit' => 20, 'exclude_top_domains' => true]], 0.03);
        $cost += (float) ($cd['cost'] ?? 0);
        $pool = [];
        foreach ($cd['available'] ? DataForSeoClient::items($cd) : [] as $it) {
            $h = self::host((string) ($it['domain'] ?? ''));
            if ($h === '' || $h === $own || $this->isMarketplace($h)) continue;
            $o = $it['full_domain_metrics']['organic'] ?? ($it['metrics']['organic'] ?? []);
            if (self::isPublisher((int) ($o['count'] ?? 0), $ownKw)) continue;
            $pool[$h] = ['shared' => (int) ($it['intersections'] ?? 0), 'traffic' => (int) round((float) ($o['etv'] ?? 0)), 'keywords' => (int) ($o['count'] ?? 0)];
        }
        uasort($pool, fn ($a, $b) => $b['shared'] <=> $a['shared']);
        $top = array_slice(array_keys($pool), 0, 10);
        $verified = $top ? $this->verify($top, 7) : [];
        $out = [];
        foreach ($top as $d) {
            $out[] = ['domain' => $d, 'title' => $verified[$d]['title'] ?? $d, 'shared_keywords' => $pool[$d]['shared'], 'traffic' => $pool[$d]['traffic'], 'keywords' => $pool[$d]['keywords'], 'measured' => true, 'verified' => isset($verified[$d])];
        }
        // اللي فتحنا موقعه الأول (بنفضّله)، وبعدين اللي جوجل شايفه بس رفض فحصنا
        usort($out, fn ($a, $b) => ($b['verified'] <=> $a['verified']) ?: ($b['shared_keywords'] <=> $a['shared_keywords']));
        return ['source' => $out ? 'google_data' : 'none', 'competitors' => array_slice($out, 0, 8), 'cost' => $cost];
    }

    private function isMarketplace(string $host): bool
    {
        return self::notACompetitor($host);
    }

    public static function notACompetitor(string $host): bool
    {
        $host = strtolower($host);
        foreach (self::NOT_COMPETITORS as $m) {
            if (str_contains($host, $m)) return true;
        }
        foreach (self::NOT_COMPETITOR_DOMAINS as $d) {
            if ($host === $d || str_ends_with($host, '.' . $d)) return true;
        }
        return false;
    }

    /** @return array<int,array{keyword:string,position:?int,volume:int,intent:string}> */
    private static function rankedKeywords(array $rk): array
    {
        $found = [];
        foreach (!empty($rk['available']) ? DataForSeoClient::items($rk) : [] as $it) {
            $kw = (string) ($it['keyword_data']['keyword'] ?? '');
            if ($kw === '') continue;
            $found[] = ['keyword' => mb_substr($kw, 0, 80), 'position' => (int) ($it['ranked_serp_element']['serp_item']['rank_group'] ?? 0) ?: null, 'volume' => (int) ($it['keyword_data']['keyword_info']['search_volume'] ?? 0),
                'intent' => (string) ($it['keyword_data']['search_intent_info']['main_intent'] ?? '')];
        }
        return $found;
    }

    /** فلتر DataForSEO لكلمات الحجز بلغة السوق (أقصى حاجة 8 شروط في الطلب) */
    public static function bookingKeywordFilter(string $lang): array
    {
        $filter = [];
        foreach (self::BOOKING_WORDS[$lang] ?? self::BOOKING_WORDS['en'] as $word) {
            if ($filter) $filter[] = 'or';
            $filter[] = ['keyword_data.keyword', 'like', '%' . $word . '%'];
        }
        return $filter;
    }

    public static function isPublisher(int $theirKeywords, int $ownKeywords): bool
    {
        return $theirKeywords >= self::PUBLISHER_KEYWORDS && $theirKeywords > 10 * max(1, $ownKeywords);
    }

    /**
     * كلمات الحجز الأول: فيها رحلة/جولة/... أو جوجل شايفها تجارية، وترتيبك فيها 4-30 (قريبة تطلع أول الصفحة).
     * المعلوماتية (تاريخ، مكان، «who is») بتنزل لآخر القايمة. نفس الدرجة → الأكتر بحثًا الأول.
     * @param array<int,array{keyword:string,position:?int,volume:int,intent?:string}> $keywords
     * @return array<int,array{keyword:string,position:?int,volume:int}>
     */
    public static function bookingKeywordsFirst(array $keywords, int $limit): array
    {
        $score = static function (array $k): int {
            $s = preg_match(self::TRAVEL_WORDS, $k['keyword']) ? 2 : 0;
            $intent = strtolower($k['intent'] ?? '');
            if ($intent === 'commercial' || $intent === 'transactional') $s += 2;
            elseif ($intent === 'informational' || $intent === 'navigational') $s -= 1;
            if (preg_match(self::INFORMATIONAL_WORDS, $k['keyword'])) $s -= 1;
            if (($k['position'] ?? 0) >= 4 && $k['position'] <= 30) $s += 1;
            return $s;
        };
        usort($keywords, fn ($a, $b) => $score($b) <=> $score($a) ?: $b['volume'] <=> $a['volume']);
        return array_map(fn ($k) => ['keyword' => $k['keyword'], 'position' => $k['position'], 'volume' => $k['volume']], array_slice($keywords, 0, $limit));
    }

    /**
     * بنفتح المواقع بالتوازي (6 ثواني لكل موقع): لازم ترجع صفحة شغالة وفيها كلام سياحي.
     * @return array<string,array{title:string,langs:array}> domain => عنوان الصفحة الحقيقي + لغاته
     */
    protected function verify(array $domains, int $timeout): array
    {
        $domains = array_values(array_filter($domains, fn ($d) => !class_exists('SsrfGuard') || SsrfGuard::isSafe('https://' . $d)));
        if (!$domains || !function_exists('curl_multi_init')) return [];
        $mh = curl_multi_init();
        $hs = [];
        foreach ($domains as $d) {
            $ch = curl_init('https://' . $d . '/');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4,
                CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                // متصفح عادي: مواقع سياحية كتير ورا Cloudflare بترفض أي User-Agent فيه Bot فكانت بتتشال من المنافسين
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36',
                CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: en-US,en;q=0.8'], CURLOPT_ENCODING => '',
                CURLOPT_RANGE => '0-200000',
            ]);
            curl_multi_add_handle($mh, $ch);
            $hs[$d] = $ch;
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 1.0);
        } while ($running && $st === CURLM_OK);
        $ok = [];
        foreach ($hs as $d => $ch) {
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $body = (string) curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            if ($code < 200 || $code >= 400 || $body === '') continue;
            $title = preg_match('~<title[^>]*>(.*?)</title>~is', $body, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')) : '';
            $text = mb_substr(preg_replace('/\s+/u', ' ', strip_tags(preg_replace('~<(script|style)[^>]*>.*?</\1>~is', ' ', $body))), 0, 6000);
            if ($title === '' || !preg_match(self::TRAVEL_WORDS, $title . ' ' . $text)) continue;
            // اللغات/الأسواق اللي الموقع بيستهدفها: من hreflang ولغة الصفحة (من الموقع نفسه)
            $langs = [];
            if (preg_match('~<html[^>]*\blang=["\']?([a-z]{2})~i', $body, $lm)) $langs[] = strtolower($lm[1]);
            if (preg_match_all('~hreflang=["\']([a-z]{2})(?:-[a-z]{2})?["\']~i', $body, $hm)) foreach ($hm[1] as $l) $langs[] = strtolower($l);
            $ok[$d] = ['title' => mb_substr(preg_replace('/\s+/u', ' ', $title), 0, 90), 'langs' => array_slice(array_values(array_unique($langs)), 0, 12)];
        }
        curl_multi_close($mh);
        return $ok;
    }

    private function nameFromTitle(string $title): string
    {
        $parts = preg_split('/\s+[|\-–—:]\s+/u', trim($title));
        // «الرئيسية» و Home وأخواتها مش اسم الشركة
        $parts = array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== '' && !preg_match('/^(home|homepage|accueil|startseite|inicio|home page|главная|anasayfa|الرئيسية|الصفحة الرئيسية)$/iu', $p)));
        return mb_substr((string) ($parts[0] ?? ''), 0, 120);
    }

    private function parseJson(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?|```$/m', '', $text);
        $j = json_decode(trim((string) $text), true);
        if (!is_array($j) && preg_match('/\{.*\}/s', (string) $text, $m)) {
            $j = json_decode($m[0], true);
        }
        return is_array($j) ? $j : null;
    }
}

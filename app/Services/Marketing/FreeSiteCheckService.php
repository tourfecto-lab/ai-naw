<?php

/**
 * Tourfecto - الفحص المجاني لموقع شركة الرحلات (الصفحة الرئيسية للمنصة)
 *
 * الزائر بيكتب رابط موقعه ← بنجيب الصفحة الرئيسية مرة واحدة (SafeHttp: مفيش عناوين داخلية)
 * ← 12 فحص من عين السائح: هل هيلاقيك، هيثق فيك، ويحجز من غير ما يتوه؟
 * النتيجة بلغة الزائر (عربي/إنجليزي)، وأهم 3 مشاكل أولًا. مفيش ذكاء اصطناعي ولا تكلفة.
 */
class FreeSiteCheckService
{
    private bool $ar;

    public function __construct(string $lang = 'en')
    {
        $this->ar = $lang === 'ar';
    }

    private function t(string $ar, string $en): string
    {
        return $this->ar ? $ar : $en;
    }

    public static function normalizeUrl(string $raw): ?string
    {
        $u = trim($raw);
        if ($u === '' || mb_strlen($u) > 300) {
            return null;
        }
        if (!preg_match('~^https?://~i', $u)) {
            $u = 'https://' . $u;
        }
        $p = parse_url($u);
        $host = strtolower((string) ($p['host'] ?? ''));
        if ($host === '' || strpos($host, '.') === false || !preg_match('/^[a-z0-9.-]+$/', $host)) {
            return null;
        }
        return strtolower((string) $p['scheme']) . '://' . $host . (isset($p['port']) ? ':' . (int) $p['port'] : '') . ($p['path'] ?? '/');
    }

    private const AI_BOTS = ['gptbot', 'oai-searchbot', 'claudebot', 'perplexitybot', 'google-extended'];

    /**
     * robots.txt بيمنع بوتات الذكاء الاصطناعي؟ بيرجّع أسماء البوتات الممنوعة بالكامل (Disallow: /) من غير Allow: /.
     * بوت له مجموعة خاصة بيتحكم فيها، وإلا بتنطبق مجموعة *.
     * @return string[]
     */
    public static function blockedAiBots(string $robots): array
    {
        $groups = [];
        $agents = [];
        $inRules = false;
        foreach (preg_split('/\r\n|\r|\n/', $robots) as $line) {
            $line = trim(preg_replace('/#.*/', '', $line));
            if ($line === '' || strpos($line, ':') === false) continue;
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            $k = strtolower($k);
            if ($k === 'user-agent') {
                if ($inRules) { $agents = []; $inRules = false; }
                $agents[] = strtolower($v);
                foreach ($agents as $a) $groups[$a] ??= ['disallow_all' => false, 'allow_all' => false];
            } elseif ($k === 'disallow' || $k === 'allow') {
                $inRules = true;
                foreach ($agents as $a) {
                    if ($k === 'disallow' && $v === '/') $groups[$a]['disallow_all'] = true;
                    if ($k === 'allow' && $v === '/') $groups[$a]['allow_all'] = true;
                }
            }
        }
        $blocked = [];
        foreach (self::AI_BOTS as $bot) {
            $g = $groups[$bot] ?? ($groups['*'] ?? null);
            if ($g && $g['disallow_all'] && !$g['allow_all']) $blocked[] = $bot;
        }
        return $blocked;
    }

    /** @return array{ok:bool, error?:string, url?:string, score?:int, checks?:array, top?:array} */
    public function run(string $url): array
    {
        $start = microtime(true);
        $r = SafeHttp::exec($url, [
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HEADER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36 TourfectoCheck/1.0',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: en,ar;q=0.8'],
            CURLOPT_ENCODING => '',
            CURLOPT_MAXFILESIZE => 3 * 1024 * 1024,
        ], 5);
        $ms = (int) round((microtime(true) - $start) * 1000);
        if ($r['response'] === false || $r['code'] === 0) {
            return ['ok' => false, 'error' => $this->t('مقدرناش نفتح الموقع ده. اتأكد من الرابط وإن الموقع شغّال.', 'We could not open this website. Check the address and that the site is online.')];
        }
        if ($r['code'] >= 400) {
            return ['ok' => false, 'error' => $this->t('الموقع رجّع خطأ (' . $r['code'] . ') - السائح هيشوف نفس الخطأ ده.', 'The website returned an error (' . $r['code'] . ') - a traveller would see the same.')];
        }
        $html = substr((string) $r['response'], (int) $r['header_size']);
        $headers = strtolower(substr((string) $r['response'], 0, (int) $r['header_size']));
        $finalUrl = (string) $r['url'];
        if (strpos($headers, 'content-type: text/html') === false && stripos($html, '<html') === false) {
            return ['ok' => false, 'error' => $this->t('الرابط ده مش صفحة موقع.', 'This address is not a web page.')];
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $xp = new DOMXPath($dom);
        $q = fn (string $x) => $xp->query($x);
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags(preg_replace('~<(script|style|noscript)[^>]*>.*?</\1>~is', ' ', $html))));
        $lowHtml = strtolower($html);
        $words = count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY));

        $title = trim((string) ($q('//title')->item(0)->textContent ?? ''));
        $desc = '';
        foreach ($q('//meta[@name]') as $m) {
            if (strtolower($m->getAttribute('name')) === 'description') {
                $desc = trim($m->getAttribute('content'));
            }
        }
        $viewport = false;
        foreach ($q('//meta[@name]') as $m) {
            if (strtolower($m->getAttribute('name')) === 'viewport') {
                $viewport = true;
            }
        }
        $h1 = $q('//h1')->length;
        $imgs = $q('//img');
        $noAlt = 0;
        foreach ($imgs as $im) {
            if (trim($im->getAttribute('alt')) === '') {
                $noAlt++;
            }
        }
        $hreflang = $q('//link[@hreflang]')->length;
        $htmlLang = strtolower(substr((string) ($q('//html')->item(0) ? $q('//html')->item(0)->getAttribute('lang') : ''), 0, 2));
        $types = [];
        foreach ($q('//script[@type="application/ld+json"]') as $s) {
            if (preg_match_all('/"@type"\s*:\s*\[?\s*"([A-Za-z]+)"/', $s->textContent, $mm)) {
                $types = array_merge($types, $mm[1]);
            }
        }
        $types = array_values(array_unique($types));
        $hasWa = (bool) preg_match('~(wa\.me/|api\.whatsapp\.com|whatsapp://|web\.whatsapp\.com)~i', $html);
        $hasTel = (bool) preg_match('~href=["\']tel:~i', $html);
        $hasBook = (bool) preg_match('~(book\s?now|book\s?online|reserve|احجز|حجز|buchen|réserver|reservar|prenota|check availability)~iu', $text) || $q('//form')->length > 0;
        $hasPrice = (bool) preg_match('~((\$|€|£|usd|eur|gbp|egp|aed|sar)\s?\d{1,5}|\d{1,5}(\.\d{1,2})?\s?(\$|€|£|usd|eur|gbp|egp|ج\.?م|جنيه|دولار|يورو))~iu', $text) || preg_match('~per\s+person|للفرد|pro person|par personne~iu', $text);
        $hasReviews = (bool) preg_match('~(tripadvisor|trustpilot|google reviews|reviews|تقييم|bewertungen|avis clients|reseñas)~iu', $lowHtml) || in_array('AggregateRating', $types, true) || in_array('Review', $types, true);
        $tourSchema = (bool) array_intersect($types, ['TouristTrip', 'Product', 'Trip', 'TravelAgency', 'LocalBusiness', 'TouristAttraction', 'Event', 'Offer']);
        $https = strpos($finalUrl, 'https://') === 0;
        $sizeKb = (int) round(strlen($html) / 1024);

        // ملفات الذكاء الاصطناعي: robots.txt (هل بوتات ChatGPT وClaude وPerplexity ممنوعة؟) وllms.txt (معلومة بس)
        $origin = (string) parse_url($finalUrl, PHP_URL_SCHEME) . '://' . (string) parse_url($finalUrl, PHP_URL_HOST);
        $fetchSmall = function (string $path) use ($origin): array {
            $x = SafeHttp::exec($origin . $path, [CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_MAXFILESIZE => 512 * 1024, CURLOPT_USERAGENT => 'TourfectoCheck/1.0', CURLOPT_ENCODING => ''], 3);
            return ['code' => (int) ($x['code'] ?? 0), 'body' => is_string($x['response'] ?? null) ? (string) $x['response'] : ''];
        };
        $robots = $fetchSmall('/robots.txt');
        $robotsOk = $robots['code'] === 200 && stripos($robots['body'], '<html') === false;
        $blockedBots = $robotsOk ? self::blockedAiBots($robots['body']) : [];
        $llms = $fetchSmall('/llms.txt');
        $hasLlms = $llms['code'] === 200 && trim($llms['body']) !== '' && stripos($llms['body'], '<html') === false;

        $c = [];
        $add = function (string $key, int $weight, string $status, string $title, string $detail, string $fix) use (&$c) {
            $c[] = compact('key', 'weight', 'status', 'title', 'detail', 'fix');
        };
        $add('https', 10, $https ? 'pass' : 'fail', $this->t('الموقع آمن (HTTPS)', 'Secure website (HTTPS)'),
            $https ? $this->t('الموقع بيفتح بقفل الأمان.', 'The site opens with the security padlock.') : $this->t('المتصفح بيكتب للسائح «غير آمن» - كتير منهم بيقفلوا الصفحة قبل ما يحجزوا.', 'Browsers label the site “Not secure” - many travellers leave before booking.'),
            $this->t('فعّل شهادة SSL من الاستضافة (غالبًا مجانية).', 'Turn on an SSL certificate at your host (usually free).'));
        $add('speed', 10, $ms <= 2500 ? 'pass' : ($ms <= 5000 ? 'warn' : 'fail'), $this->t('سرعة فتح الصفحة', 'Page speed'),
            $this->t('الصفحة اتفتحت في ' . number_format($ms / 1000, 1) . ' ثانية (' . $sizeKb . ' كيلوبايت).', 'The page loaded in ' . number_format($ms / 1000, 1) . ' s (' . $sizeKb . ' KB).') . ($ms > 2500 ? ' ' . $this->t('السائح على نت الفندق الضعيف بيقفل الصفحة لو اتأخرت أكتر من 3 ثواني.', 'On weak hotel Wi-Fi, travellers leave after about 3 seconds.') : ''),
            $this->t('صغّر الصور، وشيل الإضافات اللي مش محتاجها، وفعّل الكاش.', 'Compress images, remove unused plugins and enable caching.'));
        $add('mobile', 10, $viewport ? 'pass' : 'fail', $this->t('مناسب للموبايل', 'Mobile-friendly'),
            $viewport ? $this->t('الصفحة متظبطة للموبايل - ومعظم السياح بيحجزوا منه.', 'The page is set up for phones - where most travellers book.') : $this->t('الصفحة مش متظبطة للموبايل، فالكلام بيطلع صغير ومحتاج تكبير.', 'The page is not set up for phones; text appears tiny and needs zooming.'),
            $this->t('ضيف وسم viewport أو استخدم قالب متجاوب.', 'Add a viewport tag or use a responsive theme.'));
        $add('book', 14, ($hasWa || $hasBook) ? 'pass' : 'fail', $this->t('السائح يقدر يحجز أو يكلمك بضغطة', 'Travellers can book or message in one tap'),
            $hasWa ? $this->t('فيه زرار واتساب' . ($hasBook ? ' وطريقة حجز' : '') . ' - كويس جدًا.', 'There is a WhatsApp button' . ($hasBook ? ' and a booking option' : '') . ' - great.') : ($hasBook ? $this->t('فيه طريقة حجز، بس مفيش زرار واتساب - وده أسرع طريقة السائح بيستخدمها.', 'There is a booking option, but no WhatsApp button - the fastest way travellers reach you.') : $this->t('مفيش زرار حجز ولا واتساب واضح - السائح مش هيعرف يحجز إزاي.', 'No clear booking or WhatsApp button - travellers won’t know how to book.')),
            $this->t('حط زرار «احجز على واتساب» ظاهر في كل صفحة رحلة.', 'Put a visible “Book on WhatsApp” button on every tour page.'));
        if ($hasBook && !$hasWa) {
            $c[count($c) - 1]['status'] = 'warn';
        }
        $add('price', 12, $hasPrice ? 'pass' : 'fail', $this->t('الأسعار ظاهرة', 'Prices are visible'),
            $hasPrice ? $this->t('الصفحة فيها أسعار - السائح بيقارن بسرعة.', 'The page shows prices - travellers compare quickly.') : $this->t('مفيش أسعار ظاهرة. السائح بيقارنك بـ GetYourGuide وViator اللي بيكتبوا السعر، فبيروحلهم.', 'No prices shown. Travellers compare you with GetYourGuide and Viator, which show prices, and book there.'),
            $this->t('اكتب «من … للفرد» على كل رحلة.', 'Show “from … per person” on every tour.'));
        $add('reviews', 10, $hasReviews ? 'pass' : 'fail', $this->t('تقييمات تبني الثقة', 'Reviews that build trust'),
            $hasReviews ? $this->t('الصفحة فيها تقييمات أو رابط ليها.', 'The page shows or links to reviews.') : $this->t('مفيش تقييمات ظاهرة - السائح مش عارف إذا كان يقدر يثق فيك.', 'No visible reviews - travellers can’t tell if they can trust you.'),
            $this->t('اعرض تقييمات Google وTripAdvisor على الصفحة الرئيسية وصفحات الرحلات.', 'Show your Google and TripAdvisor reviews on the home and tour pages.'));
        $add('title', 8, ($title !== '' && mb_strlen($title) >= 15 && mb_strlen($title) <= 70) ? 'pass' : ($title === '' ? 'fail' : 'warn'), $this->t('عنوان الصفحة في جوجل', 'Page title on Google'),
            $title === '' ? $this->t('الصفحة مالهاش عنوان - جوجل مش هيعرف يعرضها صح.', 'The page has no title - Google can’t show it properly.') : $this->t('العنوان: «' . mb_substr($title, 0, 80) . '» (' . mb_strlen($title) . ' حرف).', 'Title: “' . mb_substr($title, 0, 80) . '” (' . mb_strlen($title) . ' characters).'),
            $this->t('خلّي العنوان 30–60 حرف وفيه المدينة ونوع الرحلات، مثلًا «رحلات الغردقة اليومية | اسم شركتك».', 'Keep it 30–60 characters with your city and tour type, e.g. “Hurghada Day Trips | Your Company”.'));
        $add('desc', 6, $desc !== '' ? 'pass' : 'fail', $this->t('وصف الصفحة في جوجل', 'Description on Google'),
            $desc !== '' ? $this->t('فيه وصف بيظهر تحت العنوان في جوجل.', 'There is a description shown under the title on Google.') : $this->t('مفيش وصف - جوجل بيختار أي كلام من الصفحة، وغالبًا مش مقنع.', 'No description - Google picks random text, usually not convincing.'),
            $this->t('اكتب وصف 120–155 حرف فيه أهم رحلة وميزة (مثلًا: من غير دفع مقدم).', 'Write a 120–155 character description with your top tour and benefit (e.g. no upfront payment).'));
        $add('h1', 4, $h1 >= 1 ? 'pass' : 'warn', $this->t('عنوان رئيسي واضح', 'Clear main heading'),
            $h1 >= 1 ? $this->t('فيه عنوان رئيسي (H1).', 'There is a main heading (H1).') : $this->t('مفيش عنوان رئيسي (H1) - جوجل والسائح مش فاهمين الصفحة عن إيه بسرعة.', 'No main heading (H1) - Google and travellers can’t tell quickly what the page is about.'),
            $this->t('حط عنوان رئيسي واحد بيقول إنت بتقدم إيه وفين.', 'Add one main heading saying what you offer and where.'));
        $add('schema', 8, $tourSchema ? 'pass' : 'fail', $this->t('جوجل والذكاء الاصطناعي فاهمين رحلاتك', 'Google and AI understand your tours'),
            $tourSchema ? $this->t('فيه بيانات منظمة (' . implode('، ', array_slice($types, 0, 4)) . ').', 'Structured data found (' . implode(', ', array_slice($types, 0, 4)) . ').') : $this->t('مفيش بيانات منظمة للرحلات - ChatGPT وجوجل مش بيعرفوا يقترحوا رحلاتك بسعرها.', 'No structured data for your tours - ChatGPT and Google can’t suggest your trips with prices.'),
            $this->t('ضيف Schema لكل رحلة (السعر والمدة والتقييم).', 'Add schema to each tour (price, duration, rating).'));
        $add('ai', 6, $blockedBots ? 'fail' : ($robotsOk ? 'pass' : 'warn'), $this->t('مفتوح لبوتات الذكاء الاصطناعي', 'Open to AI crawlers'),
            $blockedBots ? $this->t('ملف robots.txt بيمنع: ' . implode('، ', $blockedBots) . ' - ChatGPT وClaude وPerplexity مش هيشوفوا موقعك فمش هيقترحوه.', 'robots.txt blocks: ' . implode(', ', $blockedBots) . ' - ChatGPT, Claude and Perplexity cannot see your site, so they will not suggest it.') : ($robotsOk ? $this->t('robots.txt مش بيمنع بوتات الذكاء الاصطناعي الرئيسية.', 'robots.txt does not block the main AI crawlers.') . ($hasLlms ? ' ' . $this->t('وفيه ملف llms.txt.', 'There is also an llms.txt file.') : ' ' . $this->t('ومفيش ملف llms.txt (اختياري).', 'There is no llms.txt file (optional).')) : $this->t('مقدرناش نقرا ملف robots.txt.', 'We could not read robots.txt.')),
            $this->t('شيل أي «Disallow: /» لبوتات GPTBot وClaudeBot وPerplexityBot من robots.txt لو عايز تظهر في إجابات الذكاء الاصطناعي.', 'Remove any “Disallow: /” for GPTBot, ClaudeBot and PerplexityBot in robots.txt if you want to appear in AI answers.'));
        $add('lang', 5, ($hreflang > 0) ? 'pass' : 'warn', $this->t('لغات السياح', 'Languages for travellers'),
            $hreflang > 0 ? $this->t('الموقع ليه أكتر من لغة.', 'The site has several languages.') : $this->t('الموقع بلغة واحدة' . ($htmlLang ? ' (' . $htmlLang . ')' : '') . ' - السياح الألمان والإيطاليين والروس بيحجزوا أكتر بلغتهم.', 'The site has one language' . ($htmlLang ? ' (' . $htmlLang . ')' : '') . ' - German, Italian and Russian travellers book more in their own language.'),
            $this->t('ضيف لغات أسواقك الأساسية لصفحات الرحلات المهمة.', 'Add your main markets’ languages to your key tour pages.'));
        $add('content', 3, $words >= 300 ? 'pass' : 'warn', $this->t('محتوى كفاية', 'Enough content'),
            $this->t('الصفحة فيها حوالي ' . $words . ' كلمة.', 'The page has about ' . $words . ' words.') . ($words < 300 ? ' ' . $this->t('قليل على جوجل عشان يفهم إنت بتقدم إيه.', 'Too little for Google to understand what you offer.') : ''),
            $this->t('اكتب عن رحلاتك وأماكن الانطلاق وإيه اللي مميزك.', 'Describe your tours, pickup areas and what makes you different.'));
        $altStatus = $imgs->length === 0 ? 'warn' : ($noAlt / max(1, $imgs->length) <= 0.3 ? 'pass' : 'warn');
        $add('alt', 0, $altStatus, $this->t('وصف الصور', 'Image descriptions'),
            $imgs->length === 0 ? $this->t('الصفحة مافيهاش صور - الرحلات بتتباع بالصور.', 'No images on the page - tours sell with photos.') : $this->t($noAlt . ' من ' . $imgs->length . ' صورة مالهاش وصف.', $noAlt . ' of ' . $imgs->length . ' images have no description.'),
            $this->t('اكتب وصف لكل صورة (مثلًا «معبد الكرنك - رحلة الأقصر من الغردقة»).', 'Describe each image (e.g. “Karnak Temple - Luxor day trip from Hurghada”).'));
        $c[count($c) - 1]['weight'] = 0;
        // أوزان: 100 نقطة موزعة على الفحوص (الصور بدون وزن - معلومة بس)
        $total = array_sum(array_column($c, 'weight'));
        $got = 0.0;
        foreach ($c as $x) {
            $got += $x['status'] === 'pass' ? $x['weight'] : ($x['status'] === 'warn' ? $x['weight'] * 0.5 : 0);
        }
        $score = (int) round($got / max(1, $total) * 100);
        $problems = array_values(array_filter($c, fn ($x) => $x['status'] !== 'pass'));
        $important = array_values(array_filter($problems, fn ($x) => $x['weight'] > 0));
        usort($important, fn ($a, $b) => ($b['status'] === 'fail') <=> ($a['status'] === 'fail') ?: $b['weight'] <=> $a['weight']);

        return [
            'ok' => true, 'url' => $finalUrl, 'host' => (string) parse_url($finalUrl, PHP_URL_HOST),
            'score' => $score, 'load_ms' => $ms,
            'top' => array_slice(array_column($important, 'key'), 0, 3),
            'checks' => $c,
            'passed' => count($c) - count($problems), 'total' => count($c),
        ];
    }
}

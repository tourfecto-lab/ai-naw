<?php

/**
 * Tourfecto - «افحص حضورك على السوشيال مجانًا» (عام، من غير تسجيل)
 *
 * الزائر بيكتب رابط موقعه ← بنجيب الصفحة الرئيسية مرة واحدة (SafeHttp) ← فحوصات على اللي السائح والمنصات بيشوفوه:
 * هل صفحات فيسبوك وإنستجرام مربوطة من الموقع، هل الرابط لما يتشارك بيظهر بصورة وعنوان، هل فيه بيكسل فيسبوك، هل فيه واتساب...
 * مفيش دخول على حسابات فيسبوك أو إنستجرام ومفيش سحب لبياناتها (ده بيتم بعد الربط الرسمي من جوه الحساب).
 * مفيش ذكاء اصطناعي ولا تكلفة.
 */
class FreeSocialCheckService
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

    /** @return array{ok:bool, error?:string, url?:string, host?:string, score?:int, checks?:array, top?:array, profiles?:array, passed?:int, total?:int} */
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
        if ($r['response'] === false || $r['code'] === 0) {
            return ['ok' => false, 'error' => $this->t('مقدرناش نفتح الموقع ده. اتأكد من الرابط وإن الموقع شغّال.', 'We could not open this website. Check the address and that the site is up.')];
        }
        if ($r['code'] >= 400) {
            return ['ok' => false, 'error' => $this->t('الموقع رجّع خطأ (' . $r['code'] . ').', 'The website returned an error (' . $r['code'] . ').')];
        }
        $html = substr((string) $r['response'], (int) $r['header_size']);
        if (stripos($html, '<html') === false && stripos($html, '<head') === false) {
            return ['ok' => false, 'error' => $this->t('الرابط ده مش صفحة موقع.', 'This address is not a web page.')];
        }
        return $this->analyze($html, (string) $r['url']);
    }

    /**
     * فحص HTML جاهز (منفصل عن الشبكة عشان يتختبر).
     * @return array{ok:bool, url:string, host:string, score:int, checks:array, top:array, profiles:array, passed:int, total:int}
     */
    public function analyze(string $html, string $finalUrl): array
    {
        $profiles = self::profiles($html);
        $og = self::metaTags($html);

        $hasFb = isset($profiles['facebook']);
        $hasIg = isset($profiles['instagram']);
        $hasOgTitle = ($og['og:title'] ?? '') !== '';
        $hasOgDesc = ($og['og:description'] ?? '') !== '';
        $ogImage = (string) ($og['og:image'] ?? '');
        $ogImageOk = $ogImage !== '' && preg_match('~^https?://~i', $ogImage);
        $twitter = ($og['twitter:card'] ?? '') !== '';
        $pixel = (bool) preg_match('~(fbq\s*\(|connect\.facebook\.net/[^"\']+/fbevents\.js|facebook\.com/tr\?)~i', $html);
        $wa = (bool) preg_match('~(wa\.me/|api\.whatsapp\.com|whatsapp://|web\.whatsapp\.com)~i', $html);
        $others = array_values(array_intersect(['tiktok', 'youtube', 'google_business', 'tripadvisor'], array_keys($profiles)));
        $embed = (bool) preg_match('~(facebook\.com/plugins/|instagram\.com/embed|cdn\.lightwidget|elfsight|instagram\.com/p/[^"\']+/embed)~i', $html);
        $fbHandle = $profiles['facebook']['handle'] ?? '';
        $igHandle = $profiles['instagram']['handle'] ?? '';
        $sameName = $hasFb && $hasIg && strtolower($fbHandle) === strtolower($igHandle);

        $c = [];
        $add = function (string $key, int $weight, string $status, string $title, string $detail, string $fix) use (&$c) {
            $c[] = compact('key', 'weight', 'status', 'title', 'detail', 'fix');
        };
        $add('facebook', 15, $hasFb ? 'pass' : 'fail', $this->t('صفحة فيسبوك مربوطة من الموقع', 'Facebook page linked from your site'),
            $hasFb ? $this->t('لقينا رابط صفحتك: ' . $profiles['facebook']['url'], 'Found your page link: ' . $profiles['facebook']['url'])
                : $this->t('مفيش رابط لصفحة فيسبوك ظاهر في الصفحة الرئيسية - السائح اللي عايز يتأكد منك مش هيلاقيك.', 'No Facebook page link on your home page - travellers who want to verify you cannot find you.'),
            $this->t('حط أيقونة فيسبوك في الهيدر أو الفوتر بتفتح صفحتك مباشرة.', 'Add a Facebook icon in the header or footer that opens your page.'));
        $add('instagram', 15, $hasIg ? 'pass' : 'fail', $this->t('حساب إنستجرام مربوط من الموقع', 'Instagram profile linked from your site'),
            $hasIg ? $this->t('لقينا رابط حسابك: ' . $profiles['instagram']['url'], 'Found your profile link: ' . $profiles['instagram']['url'])
                : $this->t('مفيش رابط لإنستجرام - السياح بيدوروا على صور الرحلات هناك قبل ما يحجزوا.', 'No Instagram link - travellers look for trip photos there before booking.'),
            $this->t('حط رابط حساب إنستجرام بالاسم الصح (instagram.com/اسمك).', 'Add your Instagram link with the right handle (instagram.com/yourname).'));
        $add('og', 15, ($hasOgTitle && $hasOgDesc) ? 'pass' : (($hasOgTitle || $hasOgDesc) ? 'warn' : 'fail'), $this->t('الرابط لما يتشارك بيظهر بعنوان ووصف', 'Shared links show a title and description'),
            ($hasOgTitle && $hasOgDesc) ? $this->t('وسوم Open Graph موجودة: فيسبوك وواتساب هيعرضوا عنوان ووصف.', 'Open Graph tags found: Facebook and WhatsApp will show a title and description.')
                : $this->t('وسوم العنوان أو الوصف ناقصة - لما حد يشارك رابطك على فيسبوك أو واتساب هيظهر شكل عادي.', 'Title or description tags are missing - when someone shares your link on Facebook or WhatsApp it looks plain.'),
            $this->t('ضيف og:title وog:description لكل صفحة رحلة (إضافات السيو بتعملها).', 'Add og:title and og:description to every tour page (SEO plugins do this).'));
        $add('og_image', 12, $ogImageOk ? 'pass' : 'fail', $this->t('صورة المعاينة عند المشاركة', 'Preview image when shared'),
            $ogImageOk ? $this->t('فيه صورة معاينة (og:image).', 'A preview image (og:image) is set.') : $this->t('مفيش صورة معاينة - الرابط بيتشارك من غير صورة وده بيقلل الضغط عليه جدًا.', 'No preview image - shared links show without a picture and get far fewer clicks.'),
            $this->t('حط صورة أفقية 1200×630 بالرابط الكامل في og:image.', 'Set a 1200x630 image with a full URL in og:image.'));
        $add('pixel', 10, $pixel ? 'pass' : 'fail', $this->t('بيكسل ميتا (قياس الإعلانات والزوار)', 'Meta Pixel (ad and visitor tracking)'),
            $pixel ? $this->t('البيكسل متركّب: تقدر تعمل إعلانات إعادة استهداف وتقيس الحجوزات.', 'Pixel installed: you can retarget visitors and measure bookings.')
                : $this->t('مفيش بيكسل - مش هتعرف مين زار الموقع من إعلاناتك ومش هتقدر تعيد استهدافهم.', 'No pixel - you cannot see who came from your ads or retarget them.'),
            $this->t('ركّب بيكسل ميتا (من مدير الأعمال) أو فعّله من إضافة الموقع.', 'Install the Meta Pixel (from Business Manager) or via your site plugin.'));
        $add('whatsapp', 8, $wa ? 'pass' : 'warn', $this->t('واتساب للتواصل من السوشيال', 'WhatsApp contact for social leads'),
            $wa ? $this->t('فيه زرار أو رابط واتساب.', 'A WhatsApp button or link exists.') : $this->t('مفيش واتساب - اللي بيجي من السوشيال بيحب يسأل بسرعة.', 'No WhatsApp - social visitors like to ask quickly.'),
            $this->t('حط زرار واتساب ثابت، وارفق نفس الرقم في صفحة فيسبوك.', 'Add a fixed WhatsApp button and use the same number on your Facebook page.'));
        $add('consistent', 8, $sameName ? 'pass' : (($hasFb && $hasIg) ? 'warn' : 'warn'), $this->t('نفس الاسم على المنصات', 'Same name across platforms'),
            $sameName ? $this->t('اسم الحساب واحد على فيسبوك وإنستجرام (' . $fbHandle . ').', 'The handle is the same on Facebook and Instagram (' . $fbHandle . ').')
                : (($hasFb && $hasIg) ? $this->t('الاسم مختلف (' . $fbHandle . ' / ' . $igHandle . ') - ده بيصعّب على السائح يتأكد إنها نفس الشركة.', 'The handles differ (' . $fbHandle . ' / ' . $igHandle . ') - it is harder for travellers to see it is the same company.')
                    : $this->t('محتاجين الصفحتين مربوطين عشان نقارن الاسم.', 'Both pages must be linked to compare the handle.')),
            $this->t('وحّد الاسم لو أمكن، وإلا اكتب اسم الشركة كامل في وصف الحسابين.', 'Unify the handle if possible, otherwise put the full company name in both bios.'));
        $add('more', 5, count($others) > 0 ? 'pass' : 'warn', $this->t('منصات تانية (تيك توك، يوتيوب، جوجل، تريب أدفايزر)', 'Other channels (TikTok, YouTube, Google, TripAdvisor)'),
            count($others) > 0 ? $this->t('مربوط: ' . implode('، ', $others), 'Linked: ' . implode(', ', $others)) : $this->t('مفيش أي منصة تانية مربوطة.', 'No other channel is linked.'),
            $this->t('اربط ملف جوجل بيزنس وتريب أدفايزر - ثقة السائح بتيجي منهم.', 'Link your Google Business and TripAdvisor profiles - traveller trust comes from them.'));
        $add('twitter', 2, $twitter ? 'pass' : 'warn', $this->t('معاينة الرابط على المنصات التانية', 'Link preview on other platforms'),
            $twitter ? $this->t('وسم twitter:card موجود.', 'A twitter:card tag exists.') : $this->t('مفيش twitter:card.', 'No twitter:card tag.'),
            $this->t('ضيف <meta name="twitter:card" content="summary_large_image">.', 'Add <meta name="twitter:card" content="summary_large_image">.'));
        $add('embed', 0, $embed ? 'pass' : 'warn', $this->t('عرض آخر منشورات على الموقع', 'Latest posts shown on your site'),
            $embed ? $this->t('الموقع بيعرض منشورات من فيسبوك أو إنستجرام.', 'The site shows Facebook or Instagram posts.') : $this->t('مفيش عرض لمنشوراتك على الموقع - معلومة بس.', 'Your posts are not shown on the site - information only.'),
            $this->t('اعرض آخر منشوراتك في الفوتر أو صفحة الرئيسية.', 'Show your latest posts in the footer or home page.'));
        $c[count($c) - 1]['weight'] = 0;

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
            'score' => $score,
            'top' => array_slice(array_column($important, 'key'), 0, 3),
            'checks' => $c,
            'profiles' => array_map(fn ($p) => ['url' => $p['url'], 'handle' => $p['handle']], $profiles),
            'passed' => count($c) - count($problems), 'total' => count($c),
        ];
    }

    /** @return array<string,array{url:string,handle:string}> أول رابط بروفايل حقيقي لكل منصة (مش روابط المشاركة ولا الصفحات العامة) */
    public static function profiles(string $html): array
    {
        $out = [];
        if (!preg_match_all('~href\s*=\s*["\']([^"\']+)["\']~i', $html, $m)) {
            return $out;
        }
        $skipFb = ['sharer', 'sharer.php', 'share.php', 'share', 'dialog', 'plugins', 'tr', 'login', 'policies', 'help', 'groups', 'events', 'watch', 'marketplace', 'pages', 'people', 'hashtag', 'photo', 'photo.php', 'permalink.php', 'profile.php', 'story.php', 'l.php', 'privacy', 'about'];
        $skipIg = ['p', 'reel', 'reels', 'explore', 'accounts', 'direct', 'stories', 'tv', 'about', 'developer', 'legal', 'oauth', 'web'];
        foreach ($m[1] as $href) {
            $href = html_entity_decode($href, ENT_QUOTES);
            if (!preg_match('~^(?:https?:)?//(?:www\.|m\.|web\.)?(facebook\.com|fb\.com|instagram\.com|tiktok\.com|youtube\.com|youtu\.be|tripadvisor\.[a-z.]+|g\.page|maps\.app\.goo\.gl|business\.google\.com)/([^?#]*)~i', $href, $p)) {
                continue;
            }
            $host = strtolower($p[1]);
            $path = trim($p[2], '/');
            $first = strtolower(explode('/', $path)[0] ?? '');
            if ($path === '' && !in_array($host, ['g.page', 'maps.app.goo.gl'], true)) {
                continue;
            }
            $abs = (str_starts_with($href, '//') ? 'https:' : '') . $href;
            if ($host === 'facebook.com' || $host === 'fb.com') {
                if (!isset($out['facebook']) && $first !== '' && !in_array($first, $skipFb, true) && preg_match('~^[A-Za-z0-9.\-]{3,80}$~', $first)) {
                    $out['facebook'] = ['url' => preg_replace('~[?#].*$~', '', $abs), 'handle' => $first];
                }
            } elseif ($host === 'instagram.com') {
                if (!isset($out['instagram']) && $first !== '' && !in_array($first, $skipIg, true) && preg_match('~^[A-Za-z0-9._]{2,30}$~', $first)) {
                    $out['instagram'] = ['url' => preg_replace('~[?#].*$~', '', $abs), 'handle' => $first];
                }
            } elseif ($host === 'tiktok.com' && !isset($out['tiktok']) && str_starts_with($first, '@')) {
                $out['tiktok'] = ['url' => preg_replace('~[?#].*$~', '', $abs), 'handle' => ltrim($first, '@')];
            } elseif (in_array($host, ['youtube.com', 'youtu.be'], true) && !isset($out['youtube']) && preg_match('~^(@|channel|c|user)~i', $first)) {
                $out['youtube'] = ['url' => preg_replace('~[?#].*$~', '', $abs), 'handle' => ltrim($first, '@')];
            } elseif (str_starts_with($host, 'tripadvisor.') && !isset($out['tripadvisor'])) {
                $out['tripadvisor'] = ['url' => preg_replace('~[?#].*$~', '', $abs), 'handle' => $first];
            } elseif (in_array($host, ['g.page', 'maps.app.goo.gl', 'business.google.com'], true) && !isset($out['google_business'])) {
                $out['google_business'] = ['url' => preg_replace('~[?#].*$~', '', $abs), 'handle' => $first];
            }
        }
        return $out;
    }

    /** @return array<string,string> قيم meta بالاسم أو property (og:*, twitter:card) */
    public static function metaTags(string $html): array
    {
        $out = [];
        if (!preg_match_all('~<meta\s+[^>]*>~i', $html, $m)) {
            return $out;
        }
        foreach ($m[0] as $tag) {
            if (preg_match('~(?:property|name)\s*=\s*["\']([^"\']+)["\']~i', $tag, $k) && preg_match('~content\s*=\s*["\']([^"\']*)["\']~i', $tag, $v)) {
                $key = strtolower(trim($k[1]));
                if (!isset($out[$key])) {
                    $out[$key] = trim(html_entity_decode($v[1], ENT_QUOTES));
                }
            }
        }
        return $out;
    }
}

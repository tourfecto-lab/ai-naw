<?php

/**
 * Tourfecto - تشخيص احتياج كل شركة في تسويق المنصة (منطق نقي من غير قاعدة بيانات)
 *
 * كل شركة بتاخد "مسار" رسايل على حسب أكبر مشكلة عندها، مش رسالة واحدة للكل:
 *   - من بيانات TripAdvisor: التقييمات السلبية، البيع عن طريق Viator، عدد الصور
 *   - من فحص موقعها: لينكات السوشيال، Meta Pixel / كود جوجل، واتساب أو شات، مدونة، السرعة
 * المسار الأول للمشكلة الأكبر، والتاني بعد أسبوعين لمشكلة مختلفة.
 * الأرقام في الرسالة بتيجي من البيانات بس (مفيش تأليف).
 */
class OutreachNeeds
{
    public const TRACKS = ['reputation', 'direct_booking', 'website', 'seo', 'social', 'ads', 'chat', 'crm'];
    public const LANGS = ['ar', 'en', 'tr'];

    /** أنشطة بتبيع جولات/تجارب (مش معالم ومتاحف) */
    // "Shopping" لوحده مولات ومحلات (Pelican Mall، Meydan) مش شركات سياحة: بيتقبل بس لو معاه نشاط تاني من دول
    private const SELLERS = ['Tours', 'Boat Tours & Water Sports', 'Transportation', 'Outdoor Activities', 'Food & Drink', 'Spas & Wellness', 'Nightlife', 'Classes & Workshops', 'Fun & Games'];
    private const VISUAL = ['Boat Tours & Water Sports', 'Outdoor Activities', 'Nightlife', 'Food & Drink'];

    /** فحص الصفحة الرئيسية لموقع الشركة (HTML جاي من الشبكة: بيانات بس، مابيتنفذش) */
    public static function scanSite(string $html, string $finalUrl, int $ms): array
    {
        $h = substr($html, 0, 1500000);
        $has = static fn (string $re): bool => preg_match($re, $h) === 1;
        $social = [];
        foreach (['facebook' => 'facebook\.com/(?!sharer|plugins|tr\b|dialog)', 'instagram' => 'instagram\.com/', 'tiktok' => 'tiktok\.com/@', 'youtube' => 'youtube\.com/(?:@|c/|channel/|user/)', 'x' => '(?:twitter|x)\.com/(?!intent|share)', 'linkedin' => 'linkedin\.com/(?:company|in)/'] as $k => $re) {
            if ($has('#href=["\'][^"\']*' . $re . '#i')) {
                $social[] = $k;
            }
        }
        preg_match_all('/hreflang=["\']([a-z]{2})/i', $h, $hl);
        $langs = array_values(array_unique(array_map('strtolower', $hl[1] ?? [])));
        return [
            'ok' => true,
            'https' => stripos($finalUrl, 'https://') === 0,
            'load_ms' => max(0, $ms),
            'social' => $social,
            'meta_pixel' => $has('#connect\.facebook\.net/[^"\']*/fbevents\.js|\bfbq\(\s*["\']init#i'),
            'google_tag' => $has('#googletagmanager\.com/(?:gtag/js|gtm\.js)|google-analytics\.com/(?:analytics|ga)\.js|\bgtag\(\s*["\']config#i'),
            'whatsapp' => $has('#(?:wa\.me/|api\.whatsapp\.com/send|whatsapp://send|web\.whatsapp\.com/send)#i'),
            'chat_widget' => $has('#(?:embed\.tawk\.to|client\.crisp\.chat|widget\.intercom\.io|livechatinc\.com|static\.zdassets\.com|code\.tidio\.co|respond\.io|getbutton\.io|elfsight\.com/whatsapp)#i'),
            'booking_engine' => $has('#(?:fareharbor\.com|bokun\.io|widgets\.bokun|rezdy\.com|peek\.com|checkfront\.com|trekksoft\.com|xola\.com|ventrata\.com|bookingkit\.(?:net|de)|regiondo\.|tourcms\.com|getyourguide\.com/[^"\']*widget|viator\.com/[^"\']*widget)#i'),
            'blog' => $has('#href=["\'][^"\']*/(?:blog|news|articles|travel-guide|guides|haber|blog-[a-z]+)(?:/|["\'])#i'),
            'title' => $has('#<title[^>]*>\s*[^<\s][^<]{2,}</title>#i'),
            'meta_description' => $has('#<meta[^>]+name=["\']description["\'][^>]+content=["\'][^"\']{20,}#i'),
            'langs' => count($langs),
        ];
    }

    /** بيانات TripAdvisor الإضافية (بتتحفظ في data_json.ta وقت الاستيراد) */
    public static function tripAdvisorFacts(array $r): array
    {
        if (!isset($r['ratingHistogram']) && !isset($r['rankingString']) && !isset($r['subcategories'])) {
            return [];
        }
        $h = is_array($r['ratingHistogram'] ?? null) ? $r['ratingHistogram'] : [];
        $subs = array_values(array_filter((array) ($r['subcategories'] ?? []), 'is_string'));
        return [
            'negative' => (int) ($h['count1'] ?? 0) + (int) ($h['count2'] ?? 0),
            'viator' => strcasecmp((string) ($r['booking']['provider'] ?? ''), 'Viator') === 0,
            'photos' => (int) ($r['photoCount'] ?? 0),
            'ranking' => mb_substr((string) ($r['rankingString'] ?? ''), 0, 120),
            'subcategories' => array_slice($subs, 0, 6),
            'award' => !empty($r['travelerChoiceAward']),
            'url' => (string) ($r['webUrl'] ?? ''),
        ];
    }

    /** هل الشركة بتبيع حاجة (مش متحف/جامع/حديقة عامة)؟ */
    public static function isSeller(array $ta, ?string $category): bool
    {
        $subs = $ta['subcategories'] ?? [];
        if ($subs) {
            return (bool) array_intersect($subs, self::SELLERS);
        }
        return !preg_match('/museum|mosque|church|park|landmark|monument|متحف|جامع|مسجد/i', (string) $category);
    }

    /**
     * الاحتياجات مرتّبة: [{track, score, facts}] - الأعلى أهم
     * @param array $lead صف platform_leads (rating, reviews, website, domain, category)
     * @param array $ta   tripAdvisorFacts
     * @param array|null $site scanSite (null = لسه ماتفحصش أو الموقع مافتحش)
     */
    public static function diagnose(array $lead, array $ta, ?array $site): array
    {
        $reviews = (int) ($lead['reviews'] ?? 0);
        $subs = $ta['subcategories'] ?? [];
        $tours = !$subs || (bool) array_intersect($subs, ['Tours', 'Boat Tours & Water Sports', 'Outdoor Activities', 'Transportation']);
        $hasSite = !empty($lead['website']) && ($site === null || !empty($site['ok']));
        $out = [];
        $add = static function (string $track, int $score, array $facts = []) use (&$out) {
            $out[$track] = ['track' => $track, 'score' => $score, 'facts' => $facts];
        };

        if (empty($lead['website'])) {
            $add('website', 90);
        } elseif ($site !== null && empty($site['ok'])) {
            $add('website', 70, ['reason' => 'down']);
        }
        $neg = (int) ($ta['negative'] ?? 0);
        if ($neg >= 3) {
            // كل ما التقييمات السلبية تزيد كل ما الرد عليها يبقى أهم من أي حاجة تانية
            $add('reputation', min(95, 60 + $neg), ['negative' => $neg]);
        } elseif ($neg > 0 && $reviews < 200) {
            $add('reputation', 45, ['negative' => $neg]);
        }
        if (!empty($ta['viator']) && $tours) {
            $add('direct_booking', empty($site['booking_engine']) ? 70 : 45, ['no_engine' => $hasSite && empty($site['booking_engine'])]);
        } elseif ($hasSite && $site !== null && empty($site['booking_engine']) && $tours && $reviews >= 30) {
            $add('direct_booking', 45, ['no_engine' => true]);
        }
        if ($hasSite && $site !== null) {
            if (empty($site['whatsapp']) && empty($site['chat_widget'])) {
                $add('chat', 60);
            }
            if (empty($site['social'])) {
                $add('social', in_array(true, array_map(static fn ($s) => in_array($s, self::VISUAL, true), $subs), true) ? 65 : 55, ['reason' => 'no_links']);
            }
            if (empty($site['meta_pixel']) && empty($site['google_tag'])) {
                $add('ads', 50);
            }
            $slow = (int) ($site['load_ms'] ?? 0) >= 3500;
            if ($slow || empty($site['blog']) || empty($site['meta_description']) || empty($site['https'])) {
                $add('seo', $slow ? 58 : (empty($site['blog']) ? 48 : 40), ['reason' => $slow ? 'slow' : (empty($site['blog']) ? 'no_blog' : 'basics'), 'load' => round(((int) ($site['load_ms'] ?? 0)) / 1000, 1)]);
            }
        }
        if (!isset($out['social']) && ($ta['photos'] ?? 99) > 0 && ($ta['photos'] ?? 99) < 20) {
            $add('social', 42, ['reason' => 'few_photos', 'photos' => (int) $ta['photos']]);
        }
        if ($reviews >= 300) {
            $add('crm', 38);
        }
        if (!$out) {
            // مفيش علامة واضحة: الرد على الزباين بيفيد أي شركة سياحة
            $add($hasSite ? 'seo' : 'website', 30, ['reason' => 'default']);
        }
        usort($out, static fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_values($out);
    }

    // ------------------------------------------------------------------ النصوص

    /** جملة الدليل للمسار: أرقام الشركة نفسها بس */
    public static function evidence(string $track, array $facts, array $lead, string $lang): string
    {
        $v = [
            '{{company}}' => (string) ($lead['company'] ?? ''),
            '{{reviews}}' => number_format((int) ($lead['reviews'] ?? 0)),
            '{{negative}}' => (string) (int) ($facts['negative'] ?? 0),
            '{{photos}}' => (string) (int) ($facts['photos'] ?? 0),
            '{{load}}' => (string) ($facts['load'] ?? ''),
            '{{domain}}' => (string) ($lead['domain'] ?? ''),
        ];
        $e = self::EVIDENCE[$lang] ?? self::EVIDENCE['en'];
        $key = $track;
        if ($track === 'seo') {
            $key = 'seo_' . (in_array($facts['reason'] ?? '', ['slow', 'no_blog'], true) ? $facts['reason'] : 'default');
        } elseif ($track === 'social') {
            $key = ($facts['reason'] ?? '') === 'few_photos' ? 'social_photos' : 'social';
        } elseif ($track === 'website' && ($facts['reason'] ?? '') === 'down') {
            $key = 'website_down';
        }
        $txt = $e[$key] ?? '';
        if ($track === 'direct_booking' && !empty($facts['no_engine'])) {
            $txt .= $e['direct_booking_site'];
        }
        return strtr($txt, $v);
    }

    public static function texts(string $track, string $lang): array
    {
        $lang = in_array($lang, self::LANGS, true) ? $lang : 'en';
        $t = self::TRACK_TEXT[$track][$lang] ?? self::TRACK_TEXT['seo'][$lang];
        return $t + self::BODIES[$lang];
    }

    /** نصوص رسالة واتساب (مكتب التواصل): كل لغة بصيغتها الطبيعية، {x} بتتبدّل */
    private const DESK = [
        'ar' => [
            'greet' => 'السلام عليكم يا فريق {company} 👋', 'intro' => 'أنا {name} من Tourfecto، منصة ذكاء اصطناعي لشركات السياحة.',
            'check' => 'عملنا فحص مجاني لحضوركم على الإنترنت، ونتيجته *{score}/100*. ده ملخص سريع:', 'check_plain' => 'عملنا فحص مجاني لحضوركم على الإنترنت. ده ملخص سريع:',
            'google' => '🗺️ *جوجل ماب:*', 'ta' => '✈️ *تريب أدفايزر:*', 'fb' => '📘 *فيسبوك:*', 'ig' => '📸 *إنستجرام:*', 'web' => '🌐 *الموقع:*',
            'reviews' => 'تقييم', 'g_missing' => 'مالقيناش ملف لشركتكم، وده أول مكان المسافر بيدوّر فيه', 'g_hours' => 'مواعيد العمل مش مكتوبة', 'g_below' => 'أقل من متوسط منافسينكم ({median})',
            'linked' => '✅ مربوط بموقعكم', 'not_linked' => '❌ مش مربوط بموقعكم', 'ph' => '⚠️ الرابط على موقعكم مش بيودّي لصفحتكم', 'web_ok' => 'شغّال', 'web_http' => 'من غير HTTPS', 'web_mobile' => 'مش متوافق مع الموبايل', 'web_down' => 'مش بيفتح', 'web_none' => 'مفيش موقع',
            'top' => '⚠️ *أهم حاجة لاحظناها:* {gap}', 'report' => '📄 *التقرير الكامل*: كل نقطة بالتفصيل وإزاي تتصلّح (مجاني):', 'cta' => '{trial}. ردّوا عليا هنا وأخلّيكم تبدأوا في دقيقتين 🙌', 'cta_plain' => 'ردّوا عليا هنا لو حابين نبدأ 🙌',
        ],
        'en' => [
            'greet' => 'Hello {company} team 👋', 'intro' => "I'm {name} from Tourfecto, an AI platform for tour and travel companies.",
            'check' => 'We ran a free check of your online presence. Score: *{score}/100*. Quick summary:', 'check_plain' => 'We ran a free check of your online presence. Quick summary:',
            'google' => '🗺️ *Google Maps:*', 'ta' => '✈️ *Tripadvisor:*', 'fb' => '📘 *Facebook:*', 'ig' => '📸 *Instagram:*', 'web' => '🌐 *Website:*',
            'reviews' => 'reviews', 'g_missing' => "we couldn't find your business profile, and it's the first place travellers look", 'g_hours' => 'opening hours are missing', 'g_below' => 'below the median for similar companies ({median})',
            'linked' => '✅ linked from your website', 'not_linked' => '❌ not linked from your website', 'ph' => '⚠️ the link on your website does not lead to your own page', 'web_ok' => 'online', 'web_http' => 'no HTTPS', 'web_mobile' => 'not mobile-friendly', 'web_down' => 'not loading', 'web_none' => 'no website',
            'top' => '⚠️ *The main thing we noticed:* {gap}', 'report' => '📄 *Full report*: every point in detail and how to fix it (free):', 'cta' => '{trial}. Reply here and I will get you started in two minutes 🙌', 'cta_plain' => 'Reply here if you would like to get started 🙌',
        ],
        'tr' => [
            'greet' => 'Merhaba {company} ekibi 👋', 'intro' => "Ben Tourfecto'dan {name}, turizm şirketleri için bir yapay zekâ platformuyuz.",
            'check' => 'Online varlığınız için ücretsiz bir analiz yaptık. Puan: *{score}/100*. Kısa özet:', 'check_plain' => 'Online varlığınız için ücretsiz bir analiz yaptık. Kısa özet:',
            'google' => '🗺️ *Google Haritalar:*', 'ta' => '✈️ *Tripadvisor:*', 'fb' => '📘 *Facebook:*', 'ig' => '📸 *Instagram:*', 'web' => '🌐 *Web sitesi:*',
            'reviews' => 'yorum', 'g_missing' => 'işletme profilinizi bulamadık; gezginlerin ilk baktığı yer burası', 'g_hours' => 'çalışma saatleri eksik', 'g_below' => 'benzer şirketlerin medyanının altında ({median})',
            'linked' => '✅ web sitenizde bağlı', 'not_linked' => '❌ web sitenizde bağlı değil', 'ph' => '⚠️ web sitenizdeki bağlantı sayfanıza gitmiyor', 'web_ok' => 'açık', 'web_http' => 'HTTPS yok', 'web_mobile' => 'mobil uyumlu değil', 'web_down' => 'açılmıyor', 'web_none' => 'web siteniz yok',
            'top' => '⚠️ *Fark ettiğimiz en önemli nokta:* {gap}', 'report' => '📄 *Tam rapor*: her nokta ayrıntılı ve nasıl düzeltileceği (ücretsiz):', 'cta' => '{trial}. Buradan yazın, iki dakikada başlatalım 🙌', 'cta_plain' => 'Başlamak isterseniz buradan yazın 🙌',
        ],
    ];

    /**
     * رسالة واتساب جاهزة للإرسال: فحص سريع بالأرقام (جوجل ماب، TripAdvisor، فيسبوك، إنستجرام، الموقع)، أهم نقطة ناقصة، الحل،
     * لينك التقرير الكامل، وعرض التجربة. أي سطر معلومته مش مؤكدة بيتشال بدل ما يتكتب تخمين.
     * @param array $c lang, company, name, title, score, scorecard, gap, track, report_url, trial
     */
    public static function deskMessage(array $c): string
    {
        $lang = in_array($c['lang'] ?? '', self::LANGS, true) ? $c['lang'] : 'en';
        $t = self::DESK[$lang];
        $fill = static fn (string $s, array $v): string => strtr($s, $v);
        $num = static fn ($n): string => number_format((float) $n, 1, '.', '');
        $sc = (array) ($c['scorecard'] ?? []);
        $lines = [$fill($t['greet'], ['{company}' => $c['company']]), $fill($t['intro'], ['{name}' => $c['name']]), ''];
        $lines[] = isset($c['score']) && $c['score'] !== null ? $fill($t['check'], ['{score}' => (string) (int) $c['score']]) : $t['check_plain'];
        $lines[] = '';

        $g = (array) ($sc['google'] ?? []);
        if (($g['status'] ?? '') === 'found' && ($g['rating'] ?? null) !== null) {
            $x = $t['google'] . ' ⭐ ' . $num($g['rating']) . ' (' . number_format((int) ($g['reviews'] ?? 0)) . ' ' . $t['reviews'] . ')';
            if (($g['hours'] ?? null) === false) {
                $x .= ' · ' . $t['g_hours'];
            }
            if (!empty($g['median']) && (int) ($g['reviews'] ?? 0) < (int) $g['median'] * 0.5) {
                $x .= ' · ' . $fill($t['g_below'], ['{median}' => number_format((int) $g['median'])]);
            }
            $lines[] = $x;
        } elseif (($g['status'] ?? '') === 'missing') {
            $lines[] = $t['google'] . ' ' . $t['g_missing'];
        }
        $a = (array) ($sc['tripadvisor'] ?? []);
        if (($a['status'] ?? '') === 'found' && ($a['rating'] ?? null) !== null) {
            $x = $t['ta'] . ' ⭐ ' . $num($a['rating']) . ' (' . number_format((int) ($a['reviews'] ?? 0)) . ' ' . $t['reviews'] . ')';
            if (!empty($a['ranking'])) {
                $x .= ' · ' . $a['ranking'];
            }
            $lines[] = $x . (!empty($a['award']) ? ' 🏆' : '');
        } elseif (($a['status'] ?? '') === 'linked') {
            $lines[] = $t['ta'] . ' ' . $t['linked']; // مفيش تقييم نعرفه، بس صفحتهم مربوطة بموقعهم
        }
        foreach ([['facebook', 'fb'], ['instagram', 'ig']] as [$k, $label]) {
            $s = (string) ($sc[$k]['status'] ?? 'unknown');
            if ($s !== 'unknown') {
                $lines[] = $t[$label] . ' ' . ($s === 'linked' ? $t['linked'] : ($s === 'placeholder' ? $t['ph'] : $t['not_linked']));
            }
        }
        $w = (array) ($sc['website'] ?? []);
        $ws = (string) ($w['status'] ?? 'unknown');
        if ($ws === 'ok') {
            $notes = array_filter([($w['https'] ?? true) === false ? $t['web_http'] : null, ($w['mobile'] ?? true) === false ? $t['web_mobile'] : null]);
            $lines[] = $t['web'] . ' ' . $t['web_ok'] . ($notes ? ' (' . implode('، ', $notes) . ')' : '');
        } elseif (in_array($ws, ['down', 'none'], true)) {
            $lines[] = $t['web'] . ' ' . ($ws === 'down' ? $t['web_down'] : $t['web_none']);
        }

        if (!empty($c['gap'])) {
            $lines[] = '';
            $lines[] = $fill($t['top'], ['{gap}' => $c['gap']]);
            $solution = self::texts((string) ($c['track'] ?? 'seo'), $lang)['solution'] ?? '';
            if ($solution !== '') {
                $lines[] = '💡 ' . $solution;
            }
        }
        $lines[] = '';
        $lines[] = $t['report'];
        $lines[] = (string) $c['report_url'];
        $lines[] = '';
        $lines[] = trim((string) ($c['trial'] ?? '')) !== '' ? $fill($t['cta'], ['{trial}' => rtrim((string) $c['trial'], '.')]) : $t['cta_plain'];
        $lines[] = '';
        $lines[] = (string) $c['name'] . (trim((string) ($c['title'] ?? '')) !== '' ? "\n" . $c['title'] : '');
        return implode("\n", $lines);
    }

    public static function trackLabel(string $track, string $lang = 'ar'): string
    {
        return self::LABELS[$track][$lang] ?? self::LABELS[$track]['en'] ?? $track;
    }

    /** الخدمات اللي بتحل المشكلة (لصفحة التقرير) */
    public static function services(string $track, string $lang): array
    {
        return self::SERVICES[$track][$lang] ?? self::SERVICES[$track]['en'] ?? [];
    }

    /** سطر واحد لكل خدمة (قسم "وكمان Tourfecto بيساعدك في" في الإيميل) */
    public const SHORT = [
        'reputation' => ['icon' => '⭐', 'ar' => ['السمعة والتقييمات', 'رد ذكي على كل تقييم وتنبيه فوري بالسلبي'], 'en' => ['Reviews & reputation', 'AI replies to every review and instant alerts'], 'tr' => ['Yorumlar ve itibar', 'Her yoruma akıllı yanıt ve anında uyarı']],
        'direct_booking' => ['icon' => '🎟️', 'ar' => ['حجز مباشر', 'حجز ودفع على موقعك من غير عمولة'], 'en' => ['Direct bookings', 'Bookings and payments on your site, no commission'], 'tr' => ['Doğrudan rezervasyon', 'Sitenizde komisyonsuz rezervasyon ve ödeme']],
        'website' => ['icon' => '🌐', 'ar' => ['موقع احترافي', 'موقع سريع بكذا لغة في دقايق'], 'en' => ['Professional website', 'A fast multilingual site in minutes'], 'tr' => ['Profesyonel web sitesi', 'Dakikalar içinde hızlı, çok dilli site']],
        'seo' => ['icon' => '🔎', 'ar' => ['الظهور في جوجل', 'إصلاح الموقع ومقالات كل أسبوع'], 'en' => ['Google visibility', 'Site fixes and weekly articles'], 'tr' => ['Google görünürlüğü', 'Site düzeltmeleri ve haftalık makaleler']],
        'social' => ['icon' => '📱', 'ar' => ['السوشيال ميديا', 'بوستات وفيديوهات شهر كامل بتتنشر لوحدها'], 'en' => ['Social media', 'A month of posts and videos, auto-published'], 'tr' => ['Sosyal medya', 'Bir aylık gönderi ve video, otomatik yayın']],
        'ads' => ['icon' => '📈', 'ar' => ['الإعلانات', 'حملات Meta وجوجل متقاسة بالحجوزات'], 'en' => ['Ads', 'Meta and Google campaigns measured in bookings'], 'tr' => ['Reklamlar', 'Rezervasyonla ölçülen Meta ve Google kampanyaları']],
        'chat' => ['icon' => '💬', 'ar' => ['رد 24 ساعة', 'واتساب وفيسبوك وإنستجرام في إنبوكس واحد'], 'en' => ['24/7 replies', 'WhatsApp, Facebook and Instagram in one inbox'], 'tr' => ['7/24 yanıt', 'WhatsApp, Facebook ve Instagram tek kutuda']],
        'crm' => ['icon' => '🤝', 'ar' => ['متابعة الزباين', 'CRM وتذكيرات وعروض للزبون القديم'], 'en' => ['Guest follow-up', 'CRM, reminders and offers for past guests'], 'tr' => ['Misafir takibi', 'CRM, hatırlatmalar ve eski misafirlere teklifler']],
    ];

    /** خدمات تانية تتعرض في الإيميل: الاحتياجات التانية للشركة الأول وبعدين الأشهر */
    public static function otherServices(string $track, array $needs, int $max = 4): array
    {
        $order = array_column($needs, 'track');
        foreach (['direct_booking', 'reputation', 'chat', 'social', 'seo', 'ads', 'crm', 'website'] as $t) {
            $order[] = $t;
        }
        return array_slice(array_values(array_unique(array_diff($order, [$track]))), 0, $max);
    }

    public const UI = [
        'ar' => ['noticed' => 'اللي لاحظناه', 'also' => 'وكمان Tourfecto بيساعدكم في', 'tagline' => 'منصة الذكاء الاصطناعي لشركات السياحة'],
        'en' => ['noticed' => 'What we noticed', 'also' => 'Tourfecto also helps you with', 'tagline' => 'The AI platform for tour & travel companies'],
        'tr' => ['noticed' => 'Fark ettiğimiz', 'also' => 'Tourfecto ayrıca şunlarda yardımcı olur', 'tagline' => 'Tur ve seyahat şirketleri için yapay zekâ platformu'],
    ];

    public const BUTTONS = [
        'ar' => ['report' => 'شوفوا التقرير المجاني', 'wa' => 'كلّمونا على واتساب', 'signup' => 'جرّبوا Tourfecto مجانًا', 'reply' => 'أو ردّوا على الإيميل ده وهنساعدكم'],
        'en' => ['report' => 'See your free report', 'wa' => 'Message us on WhatsApp', 'signup' => 'Try Tourfecto free', 'reply' => 'Or just reply to this email and we will help'],
        'tr' => ['report' => 'Ücretsiz raporunuzu görün', 'wa' => "WhatsApp'tan yazın", 'signup' => "Tourfecto'yu ücretsiz deneyin", 'reply' => 'Ya da bu e-postayı yanıtlayın, yardımcı olalım'],
    ];

    public const FOOTER = [
        'ar' => ['وصلتك الرسالة دي لأن شركتكم ظاهرة كشركة سياحة على الإنترنت. مش عاوزين رسايل تاني؟', 'إلغاء الاشتراك'],
        'en' => ['You received this because your company is listed publicly as a travel business. Not interested?', 'Unsubscribe'],
        'tr' => ['Bu e-postayı şirketiniz internette bir seyahat işletmesi olarak listelendiği için aldınız. Başka e-posta istemiyor musunuz?', 'Abonelikten çıkın'],
    ];

    private const BODIES = [
        'ar' => [
            'b1' => "أهلًا فريق {{company}}،\n\n{{opener}}{{evidence}}\n\n{{solution}}\n\nجهّزت تقرير مجاني قصير لـ {{company}} فيه اللي لقيناه وإزاي يتصلّح:\n{{report_link}}\n\n{{sender_name}}{{sender_title_line}}",
            'b2' => "أهلًا تاني،\n\nمتابعة سريعة. مع Tourfecto، {{company}} تقدر:\n\n{{bullets}}\n\nالتقرير المجاني لسه موجود:\n{{report_link}}\n\nأو ردّوا على الإيميل ده بأي سؤال.\n\n{{sender_name}}",
            's3' => 'آخر رسالة مني ({{company}})',
            'b3' => "أهلًا،\n\nدي آخر رسالة مني، مش عاوز أزحم الإيميل عندكم.\n\nلو حبيتوا مساعدة في {{pitch}} بعدين، تقدروا تبدأوا مجانًا من هنا:\n{{signup_link}}\n\nأو كلّمونا على واتساب ونظبطها معاكم:\n{{whatsapp_link}}\n\nبالتوفيق لـ {{company}}،\n{{sender_name}}",
        ],
        'en' => [
            'b1' => "Hi {{company}} team,\n\n{{opener}}{{evidence}}\n\n{{solution}}\n\nI prepared a short free report for {{company}} showing what we found and how to fix it:\n{{report_link}}\n\n{{sender_name}}{{sender_title_line}}",
            'b2' => "Hi again,\n\nA quick follow-up. With Tourfecto, {{company}} can:\n\n{{bullets}}\n\nYour free report is still here:\n{{report_link}}\n\nOr just reply to this email with any question.\n\n{{sender_name}}",
            's3' => 'Last note ({{company}})',
            'b3' => "Hi,\n\nThis is my last email, I don't want to crowd your inbox.\n\nIf you'd like help with {{pitch}} later, you can start free here:\n{{signup_link}}\n\nOr message us on WhatsApp and we'll set it up with you:\n{{whatsapp_link}}\n\nWishing {{company}} a great season,\n{{sender_name}}",
        ],
        'tr' => [
            'b1' => "Merhaba {{company}} ekibi,\n\n{{opener}}{{evidence}}\n\n{{solution}}\n\n{{company}} için bulduklarımızı ve nasıl düzeltileceğini gösteren kısa, ücretsiz bir rapor hazırladım:\n{{report_link}}\n\n{{sender_name}}{{sender_title_line}}",
            'b2' => "Tekrar merhaba,\n\nKısa bir hatırlatma. Tourfecto ile {{company}} şunları yapabilir:\n\n{{bullets}}\n\nÜcretsiz raporunuz hâlâ burada:\n{{report_link}}\n\nSorunuz varsa bu e-postayı yanıtlamanız yeterli.\n\n{{sender_name}}",
            's3' => 'Son notum ({{company}})',
            'b3' => "Merhaba,\n\nGelen kutunuzu doldurmak istemem, bu son e-postam.\n\nİleride {{pitch}} konusunda yardım isterseniz buradan ücretsiz başlayabilirsiniz:\n{{signup_link}}\n\nYa da WhatsApp'tan yazın, birlikte kuralım:\n{{whatsapp_link}}\n\n{{company}} için harika bir sezon dilerim,\n{{sender_name}}",
        ],
    ];

    private const EVIDENCE = [
        'ar' => [
            'reputation' => 'لاحظت إن عند {{company}} {{negative}} تقييم بنجمة أو نجمتين من {{reviews}} تقييم على TripAdvisor. المسافر الجديد بيقرا التقييمات دي الأول، والرد الهادي والسريع عليها بيفرق أكتر من التقييم نفسه.',
            'direct_booking' => 'جولاتكم على TripAdvisor بتتحجز عن طريق Viator، يعني عمولة على كل حجز.',
            'direct_booking_site' => ' وموقعكم لسه مابياخدش حجز مباشر.',
            'website' => 'مالقيتش موقع لـ {{company}}، فأغلب المسافرين اللي بيلاقوكم مش قدامهم غير إنهم يحجزوا من منصات تانية.',
            'website_down' => 'حاولت أفتح {{domain}} ومافتحش معايا، ولو ده بيحصل مع المسافرين يبقى فيه حجوزات بتضيع.',
            'seo_slow' => '{{domain}} أخد حوالي {{load}} ثانية عشان يفتح لما جرّبته، والصفحة البطيئة بتخسر الزوار وترتيب جوجل مع بعض.',
            'seo_no_blog' => '{{domain}} مافيهوش مدونة أو أدلة سياحية، ودي أكتر طريقة شركات الجولات بتظهر بيها في جوجل.',
            'seo_default' => '{{domain}} ممكن يظهر أعلى بكتير في جوجل للجولات اللي بتبيعوها.',
            'social' => 'مالقيتش لينكات لإنستجرام أو فيسبوك على {{domain}}، وفي الجولات السوشيال ميديا هي المكان اللي مسافرين كتير بيقرروا منه.',
            'social_photos' => 'عند {{company}} {{photos}} صورة بس على TripAdvisor، والصور هي أول حاجة المسافر بيشوفها.',
            'ads' => '{{domain}} مافيهوش Meta Pixel ولا كود جوجل، فلو بتعملوا إعلانات مش هتعرفوا أنهي إعلان بيجيب حجوزات.',
            'chat' => '{{domain}} مافيهوش زرار واتساب ولا شات، والمسافر غالبًا بيحجز مع أول حد يرد عليه.',
            'crm' => 'بـ {{reviews}} تقييم، {{company}} خدمت آلاف الزباين، وأغلبهم مابيسمعوش منكم تاني بعد الرحلة.',
        ],
        'en' => [
            'reputation' => 'I noticed {{company}} has {{negative}} reviews of 1–2 stars among {{reviews}} on TripAdvisor. New travellers read those first, and a calm, quick reply often matters more than the review itself.',
            'direct_booking' => 'Your tours on TripAdvisor are booked through Viator, which means a commission on every booking.',
            'direct_booking_site' => " And your website doesn't take bookings directly yet.",
            'website' => "I couldn't find a website for {{company}}, so most travellers who find you can only book through other platforms.",
            'website_down' => "I tried to open {{domain}} and it didn't load for me; if that happens to travellers too, bookings are being lost.",
            'seo_slow' => '{{domain}} took about {{load}} seconds to open when I checked, and slow pages lose both visitors and Google ranking.',
            'seo_no_blog' => '{{domain}} has no blog or travel guides, which is how most tour companies get found on Google.',
            'seo_default' => '{{domain}} can rank much higher on Google for the tours you sell.',
            'social' => "I couldn't find links to Instagram or Facebook on {{domain}}, and for tours, social media is where many travellers decide.",
            'social_photos' => '{{company}} has only {{photos}} photos on TripAdvisor, and photos are the first thing travellers look at.',
            'ads' => "{{domain}} has no Meta Pixel or Google tag, so if you run ads you can't see which ones bring bookings.",
            'chat' => '{{domain}} has no WhatsApp button or live chat, and travellers often book with whoever answers first.',
            'crm' => 'With {{reviews}} reviews, {{company}} has served thousands of guests, and most of them never hear from you again after the trip.',
        ],
        'tr' => [
            'reputation' => "{{company}} için TripAdvisor'daki {{reviews}} yorumun {{negative}} tanesinin 1–2 yıldızlı olduğunu fark ettim. Yeni gezginler önce bunları okuyor ve sakin, hızlı bir yanıt çoğu zaman yorumun kendisinden daha önemli.",
            'direct_booking' => "TripAdvisor'daki turlarınız Viator üzerinden satılıyor, yani her rezervasyonda komisyon ödüyorsunuz.",
            'direct_booking_site' => ' Web siteniz de henüz doğrudan rezervasyon almıyor.',
            'website' => '{{company}} için bir web sitesi bulamadım, bu yüzden sizi bulan gezginlerin çoğu yalnızca başka platformlar üzerinden rezervasyon yapabiliyor.',
            'website_down' => '{{domain}} adresini açmaya çalıştım ama yüklenmedi; bu gezginlerin de başına geliyorsa rezervasyon kaybediyorsunuz.',
            'seo_slow' => '{{domain}} denediğimde açılması yaklaşık {{load}} saniye sürdü; yavaş sayfalar hem ziyaretçi hem de Google sıralaması kaybettirir.',
            'seo_no_blog' => "{{domain}} üzerinde blog ya da gezi rehberi yok, oysa tur şirketlerinin çoğu Google'da bu sayede bulunuyor.",
            'seo_default' => "{{domain}}, sattığınız turlar için Google'da çok daha üst sıralarda yer alabilir.",
            'social' => '{{domain}} üzerinde Instagram ya da Facebook bağlantısı bulamadım; turlarda gezginlerin çoğu kararını sosyal medyada veriyor.',
            'social_photos' => "{{company}} için TripAdvisor'da yalnızca {{photos}} fotoğraf var ve gezginin ilk gördüğü şey fotoğraflar.",
            'ads' => '{{domain}} üzerinde Meta Pixel ya da Google etiketi yok; reklam verirseniz hangisinin rezervasyon getirdiğini göremezsiniz.',
            'chat' => '{{domain}} üzerinde WhatsApp butonu ya da canlı sohbet yok; gezginler çoğu zaman ilk yanıt verenle rezervasyon yapıyor.',
            'crm' => '{{reviews}} yorumla {{company}} binlerce misafiri ağırlamış ve çoğu turdan sonra sizden bir daha haber almıyor.',
        ],
    ];

    private const TRACK_TEXT = [
        'reputation' => [
            'ar' => ['s1' => '{{company}}: ملاحظة سريعة عن تقييماتكم', 'solution' => 'Tourfecto بيكتب رد احترافي على كل تقييم في ثواني (وانتم بتوافقوا قبل ما يتنشر)، وبينبّهكم أول ما يوصل تقييم سلبي.', 's2' => 'الرد على التقييمات من غير ما تضيعوا ساعات', 'bullets' => "• تنبيه فوري لكل تقييم نجمة أو نجمتين\n• رد بضغطة بلغة الزبون\n• طلب تقييم أوتوماتيك من الزباين المبسوطين بعد الرحلة\n• أحسن تقييماتكم تظهر على موقعكم", 'pitch' => 'تقييماتكم'],
            'en' => ['s1' => '{{company}}: a quick note about your reviews', 'solution' => 'Tourfecto writes a professional reply to every review in seconds (you approve it before it goes out) and alerts you the moment a negative review arrives.', 's2' => 'Replying to reviews without spending hours', 'bullets' => "• Instant alert for every 1–2 star review\n• One-click replies in your guest's language\n• Automatic review requests to happy guests after the trip\n• Your best reviews shown on your own website", 'pitch' => 'your reviews'],
            'tr' => ['s1' => '{{company}}: yorumlarınızla ilgili kısa bir not', 'solution' => 'Tourfecto her yoruma saniyeler içinde profesyonel bir yanıt yazar (yayınlanmadan önce siz onaylarsınız) ve olumsuz bir yorum geldiği anda sizi uyarır.', 's2' => 'Saatler harcamadan yorumlara yanıt verin', 'bullets' => "• Her 1–2 yıldızlı yorum için anında uyarı\n• Misafirinizin dilinde tek tıkla yanıt\n• Turdan sonra memnun misafirlerden otomatik yorum isteği\n• En iyi yorumlarınız kendi web sitenizde", 'pitch' => 'yorumlarınız'],
        ],
        'direct_booking' => [
            'ar' => ['s1' => '{{company}}: تكسبوا أكتر من كل حجز', 'solution' => 'Tourfecto بيضيف محرّك حجز على موقعكم انتم: المواعيد المتاحة، والدفع أونلاين أو العربون، وتأكيد فوري بالإيميل والواتساب، من غير عمولة على الحجز.', 's2' => 'حجز مباشر على {{website_or_company}}', 'bullets' => "• مواعيد متاحة ودفع أونلاين على موقعكم\n• عربون وفاوتشر وتسجيل وصول بـ QR\n• تذكير أوتوماتيك بالرحلة على واتساب\n• كل حجز مربوط بالإعلان أو البوست اللي جابه", 'pitch' => 'الحجز المباشر'],
            'en' => ['s1' => '{{company}}: keeping more of each booking', 'solution' => 'Tourfecto adds a booking engine to your own website: availability, online payment or deposit, and instant confirmation by email and WhatsApp, with no commission per booking.', 's2' => 'Direct bookings on {{website_or_company}}', 'bullets' => "• Live availability and online payments on your site\n• Deposits, vouchers and QR check-in\n• Automatic trip reminders on WhatsApp\n• Every booking tracked back to the ad or post that brought it", 'pitch' => 'direct bookings'],
            'tr' => ['s1' => '{{company}}: her rezervasyondan daha fazla kazanın', 'solution' => 'Tourfecto kendi web sitenize bir rezervasyon motoru ekler: müsaitlik, online ödeme veya kapora ve e-posta ile WhatsApp üzerinden anında onay, rezervasyon başına komisyon olmadan.', 's2' => '{{website_or_company}} üzerinden doğrudan rezervasyon', 'bullets' => "• Sitenizde canlı müsaitlik ve online ödeme\n• Kapora, voucher ve QR ile giriş\n• WhatsApp'tan otomatik tur hatırlatmaları\n• Her rezervasyonun hangi reklam veya gönderiden geldiği", 'pitch' => 'doğrudan rezervasyonlar'],
        ],
        'website' => [
            'ar' => ['s1' => 'موقع لـ {{company}}', 'solution' => 'Tourfecto بيعمل لكم موقع سريع بكذا لغة في دقايق، وفيه الحجز أونلاين وتقييماتكم.', 's2' => 'موقع {{company}} جاهز الأسبوع ده', 'bullets' => "• تصميم احترافي بالإنجليزي والعربي والتركي ولغات تانية\n• حجز ودفع أونلاين\n• جاهز لجوجل من أول يوم\n• دومين باسمكم", 'pitch' => 'موقعكم'],
            'en' => ['s1' => 'A website for {{company}}', 'solution' => 'Tourfecto builds a fast, multilingual website for your tours in minutes, with online booking and your reviews built in.', 's2' => 'A website for {{company}}, ready this week', 'bullets' => "• Professional design in English, Arabic, Turkish and more\n• Online booking and payments\n• Search-ready from day one\n• Your own domain", 'pitch' => 'your website'],
            'tr' => ['s1' => '{{company}} için bir web sitesi', 'solution' => 'Tourfecto turlarınız için dakikalar içinde hızlı, çok dilli bir web sitesi kurar; online rezervasyon ve yorumlarınız hazır gelir.', 's2' => '{{company}} web sitesi bu hafta hazır', 'bullets' => "• İngilizce, Türkçe, Arapça ve diğer dillerde profesyonel tasarım\n• Online rezervasyon ve ödeme\n• İlk günden Google'a hazır\n• Kendi alan adınız", 'pitch' => 'web siteniz'],
        ],
        'seo' => [
            'ar' => ['s1' => '{{website_or_company}} في جوجل', 'solution' => 'Tourfecto بيفحص موقعكم، وبيصلّح المشاكل التقنية أوتوماتيك، وبيكتب مقالات جاهزة لجوجل عن جولاتكم كل أسبوع.', 's2' => 'إزاي {{website_or_company}} يطلع أعلى في جوجل', 'bullets' => "• فحص وإصلاح الموقع أوتوماتيك\n• مقالات أسبوعية بتتنشر على موقعكم\n• تتبّع الكلمات والترتيب\n• الظهور في بحث الذكاء الاصطناعي زي ChatGPT وGemini", 'pitch' => 'الظهور في جوجل'],
            'en' => ['s1' => '{{website_or_company}} on Google', 'solution' => 'Tourfecto audits your site, fixes the technical issues automatically, and writes Google-ready articles about your tours every week.', 's2' => 'Getting {{website_or_company}} higher on Google', 'bullets' => "• Automatic site audit and fixes\n• Weekly articles published to your site\n• Keyword and ranking tracking\n• Visibility in AI search like ChatGPT and Gemini", 'pitch' => 'Google visibility'],
            'tr' => ['s1' => "{{website_or_company}} ve Google", 'solution' => 'Tourfecto sitenizi tarar, teknik sorunları otomatik düzeltir ve her hafta turlarınız hakkında Google\'a uygun makaleler yazar.', 's2' => "{{website_or_company}} Google'da nasıl yükselir", 'bullets' => "• Otomatik site denetimi ve düzeltmeler\n• Sitenizde yayınlanan haftalık makaleler\n• Anahtar kelime ve sıralama takibi\n• ChatGPT ve Gemini gibi yapay zekâ aramalarında görünürlük", 'pitch' => 'Google görünürlüğü'],
        ],
        'social' => [
            'ar' => ['s1' => '{{company}} على السوشيال ميديا', 'solution' => 'Tourfecto بيعمل لكم بوستات وصور وفيديوهات قصيرة لشهر كامل بلوجو شركتكم في دقايق، وبينشرها لوحده على فيسبوك وإنستجرام وتيك توك.', 's2' => 'بوستات شهر كامل لـ {{company}} في 10 دقايق', 'bullets' => "• بوستات وكلام جاهز بلغات زباينكم\n• صور وفيديوهات قصيرة بالذكاء الاصطناعي (Reels وShorts)\n• نشر مجدول على كل حساباتكم\n• أفكار على حسب موسمكم وعروضكم", 'pitch' => 'السوشيال ميديا'],
            'en' => ['s1' => '{{company}} on social media', 'solution' => 'Tourfecto creates a month of posts, images and short videos with your logo in minutes, and publishes them for you on Facebook, Instagram and TikTok.', 's2' => 'A month of posts for {{company}} in 10 minutes', 'bullets' => "• Ready posts and captions in your guests' languages\n• AI images and short videos (Reels, Shorts)\n• Scheduled publishing on all your channels\n• Ideas based on your season and offers", 'pitch' => 'your social media'],
            'tr' => ['s1' => '{{company}} ve sosyal medya', 'solution' => "Tourfecto logonuzla bir aylık gönderi, görsel ve kısa videoyu dakikalar içinde hazırlar ve Facebook, Instagram ve TikTok'ta sizin için yayınlar.", 's2' => '{{company}} için 10 dakikada bir aylık gönderi', 'bullets' => "• Misafirlerinizin dillerinde hazır gönderiler ve metinler\n• Yapay zekâ ile görseller ve kısa videolar (Reels, Shorts)\n• Tüm hesaplarınızda planlı yayın\n• Sezonunuza ve kampanyalarınıza göre fikirler", 'pitch' => 'sosyal medyanız'],
        ],
        'ads' => [
            'ar' => ['s1' => '{{company}}: إعلانات تتقاس بالحجوزات', 'solution' => 'Tourfecto بيعمل حملاتكم على Meta وجوجل، وبيربط كل حجز بالإعلان اللي جابه، وبيوقف اللي مش شغال، وكل ده بموافقتكم.', 's2' => 'إعلانات تعرفوا جابت كام حجز', 'bullets' => "• حملات جاهزة على Meta وجوجل\n• كل حجز متسجّل على الإعلان بتاعه\n• تنبيهات يومية بالميزانية والأداء\n• Autopilot مابيعملش حاجة غير بموافقتكم", 'pitch' => 'الإعلانات'],
            'en' => ['s1' => '{{company}}: ads you can measure in bookings', 'solution' => "Tourfecto builds your Meta and Google campaigns, connects every booking to the ad that brought it, and pauses what doesn't work, all with your approval.", 's2' => 'Ads that you can measure in bookings', 'bullets' => "• Ready campaigns for Meta and Google\n• Bookings tracked per ad\n• Daily budget and performance alerts\n• Autopilot that only acts with your approval", 'pitch' => 'your ads'],
            'tr' => ['s1' => '{{company}}: rezervasyonla ölçülen reklamlar', 'solution' => 'Tourfecto Meta ve Google kampanyalarınızı kurar, her rezervasyonu onu getiren reklama bağlar ve işe yaramayanı durdurur; hepsi sizin onayınızla.', 's2' => 'Kaç rezervasyon getirdiğini bildiğiniz reklamlar', 'bullets' => "• Meta ve Google için hazır kampanyalar\n• Reklam başına rezervasyon takibi\n• Günlük bütçe ve performans uyarıları\n• Yalnızca sizin onayınızla çalışan Autopilot", 'pitch' => 'reklamlarınız'],
        ],
        'chat' => [
            'ar' => ['s1' => '{{company}}: الرد على كل مسافر', 'solution' => 'Tourfecto بيرد على زباينكم على واتساب وفيسبوك وإنستجرام 24 ساعة بأسعاركم وشروطكم انتم، وبيحوّلكم المحادثة لما تحتاجوا.', 's2' => 'الرد على كل مسافر حتى بالليل', 'bullets' => "• إنبوكس واحد لواتساب وفيسبوك وإنستجرام\n• ردود ذكية من معلومات شركتكم\n• توافقوا أو تستلموا المحادثة في أي وقت\n• متابعة للي سأل ومحجزش", 'pitch' => 'الرد على الزباين'],
            'en' => ['s1' => '{{company}}: answering every traveller', 'solution' => 'Tourfecto answers your customers on WhatsApp, Facebook and Instagram 24/7 using your own prices and policies, and passes the conversation to you when needed.', 's2' => 'Answering every traveller, even at night', 'bullets' => "• One inbox for WhatsApp, Facebook and Instagram\n• AI replies from your own knowledge base\n• You approve or take over anytime\n• Follow-ups for travellers who didn't book", 'pitch' => 'customer replies'],
            'tr' => ['s1' => '{{company}}: her gezgine yanıt', 'solution' => "Tourfecto müşterilerinize WhatsApp, Facebook ve Instagram'da kendi fiyat ve koşullarınızla 7/24 yanıt verir, gerektiğinde konuşmayı size devreder.", 's2' => 'Gece bile her gezgine yanıt', 'bullets' => "• WhatsApp, Facebook ve Instagram için tek gelen kutusu\n• Kendi bilgi bankanızdan akıllı yanıtlar\n• İstediğiniz an onaylayın ya da devralın\n• Sorup rezervasyon yapmayanlara takip mesajları", 'pitch' => 'müşteri yanıtları'],
        ],
        'crm' => [
            'ar' => ['s1' => '{{company}}: زباينكم القدام', 'solution' => 'Tourfecto بيجمع كل زباينكم في CRM واحد، وبيبعت المتابعة المناسبة أوتوماتيك: شكر، وطلب تقييم، وعروض لرحلتهم الجاية.', 's2' => 'إزاي الزبون القديم يحجز تاني', 'bullets' => "• كل زباينكم وحجوزاتكم في مكان واحد\n• متابعة أوتوماتيك على واتساب والإيميل\n• طلب تقييم بعد كل رحلة\n• عروض موسمية للزباين القدام", 'pitch' => 'متابعة الزباين'],
            'en' => ['s1' => '{{company}}: your past guests', 'solution' => 'Tourfecto keeps every guest in one CRM and sends the right follow-up automatically: thank-you, review request, and offers for their next trip.', 's2' => 'Turning past guests into repeat bookings', 'bullets' => "• All your guests and bookings in one place\n• Automatic follow-ups on WhatsApp and email\n• Review requests after every trip\n• Seasonal offers to past guests", 'pitch' => 'your guest follow-ups'],
            'tr' => ['s1' => '{{company}}: eski misafirleriniz', 'solution' => "Tourfecto tüm misafirlerinizi tek bir CRM'de toplar ve doğru takip mesajını otomatik gönderir: teşekkür, yorum isteği ve bir sonraki gezileri için teklifler.", 's2' => 'Eski misafirleri tekrar rezervasyona dönüştürmek', 'bullets' => "• Tüm misafir ve rezervasyonlarınız tek yerde\n• WhatsApp ve e-posta ile otomatik takip\n• Her turdan sonra yorum isteği\n• Eski misafirlere sezonluk teklifler", 'pitch' => 'misafir takibi'],
        ],
    ];

    private const LABELS = [
        'reputation' => ['ar' => 'السمعة والتقييمات', 'en' => 'Reviews & reputation', 'tr' => 'Yorumlar ve itibar'],
        'direct_booking' => ['ar' => 'الحجز المباشر', 'en' => 'Direct bookings', 'tr' => 'Doğrudan rezervasyon'],
        'website' => ['ar' => 'موقع', 'en' => 'Website', 'tr' => 'Web sitesi'],
        'seo' => ['ar' => 'الظهور في جوجل', 'en' => 'Google visibility', 'tr' => 'Google görünürlüğü'],
        'social' => ['ar' => 'السوشيال ميديا', 'en' => 'Social media', 'tr' => 'Sosyal medya'],
        'ads' => ['ar' => 'الإعلانات', 'en' => 'Ads', 'tr' => 'Reklamlar'],
        'chat' => ['ar' => 'الرد على الزباين', 'en' => 'Customer replies', 'tr' => 'Müşteri yanıtları'],
        'crm' => ['ar' => 'متابعة الزباين (CRM)', 'en' => 'Guest follow-up (CRM)', 'tr' => 'Misafir takibi (CRM)'],
    ];

    private const SERVICES = [
        'reputation' => ['ar' => ['الرد الذكي على التقييمات', 'تنبيه فوري بالتقييم السلبي', 'طلبات التقييم بعد الرحلة', 'ويدجت التقييمات على موقعكم'], 'en' => ['AI review replies', 'Instant negative-review alerts', 'Post-trip review requests', 'Reviews widget for your website'], 'tr' => ['Yapay zekâ ile yorum yanıtları', 'Olumsuz yorumlar için anında uyarı', 'Tur sonrası yorum istekleri', 'Web siteniz için yorum widget\'ı']],
        'direct_booking' => ['ar' => ['محرّك الحجز على موقعكم', 'الدفع والعربون أونلاين', 'التذاكر وتسجيل الوصول بـ QR', 'تذكيرات الرحلة على واتساب'], 'en' => ['Booking engine on your site', 'Online payments and deposits', 'Tickets and QR check-in', 'WhatsApp trip reminders'], 'tr' => ['Sitenizde rezervasyon motoru', 'Online ödeme ve kapora', 'Bilet ve QR ile giriş', 'WhatsApp tur hatırlatmaları']],
        'website' => ['ar' => ['منشئ المواقع والدومين', 'موقع بكذا لغة', 'الحجز أونلاين', 'جاهز لجوجل'], 'en' => ['Website builder and domain', 'Multilingual site', 'Online booking', 'Search-ready pages'], 'tr' => ['Web sitesi oluşturucu ve alan adı', 'Çok dilli site', 'Online rezervasyon', 'Arama motoruna hazır sayfalar']],
        'seo' => ['ar' => ['محسّن الموقع والـ Auto SEO', 'المقالات والنشر على الموقع', 'الكلمات وتتبّع الترتيب', 'الظهور في الذكاء الاصطناعي (AEO/GEO)'], 'en' => ['Website optimizer and Auto SEO', 'Articles published to your site', 'Keywords and rank tracking', 'AI search visibility (AEO/GEO)'], 'tr' => ['Site optimizasyonu ve Otomatik SEO', 'Sitenizde yayınlanan makaleler', 'Anahtar kelime ve sıralama takibi', 'Yapay zekâ aramalarında görünürlük']],
        'social' => ['ar' => ['النشر على السوشيال ميديا', 'استوديو التصميم: صور وفيديو', 'مساعد التسويق', 'جدولة النشر'], 'en' => ['Social media publishing', 'Creative studio: images and video', 'Marketing assistant', 'Scheduled posting'], 'tr' => ['Sosyal medya yayını', 'Kreatif stüdyo: görsel ve video', 'Pazarlama asistanı', 'Planlı paylaşım']],
        'ads' => ['ar' => ['إدارة الإعلانات والـ Autopilot', 'تتبّع الحجوزات لكل إعلان', 'تنبيهات الميزانية', 'ذكاء المنافسين'], 'en' => ['Ads management and Autopilot', 'Booking tracking per ad', 'Budget alerts', 'Competitor intelligence'], 'tr' => ['Reklam yönetimi ve Autopilot', 'Reklam başına rezervasyon takibi', 'Bütçe uyarıları', 'Rakip analizi']],
        'chat' => ['ar' => ['الإنبوكس والشات الذكي', 'قاعدة المعرفة', 'التسويق بالواتساب', 'المتابعة للي ماحجزش'], 'en' => ['Unified inbox and AI chat', 'Knowledge base', 'WhatsApp marketing', 'Follow-ups for non-bookers'], 'tr' => ['Birleşik gelen kutusu ve yapay zekâ sohbeti', 'Bilgi bankası', 'WhatsApp pazarlama', 'Rezervasyon yapmayanlara takip']],
        'crm' => ['ar' => ['CRM والمتابعات', 'التسويق بالإيميل والواتساب', 'تذكيرات الرحلات', 'ذكاء الإيرادات'], 'en' => ['CRM and sequences', 'Email and WhatsApp marketing', 'Trip reminders', 'Revenue intelligence'], 'tr' => ['CRM ve takip dizileri', 'E-posta ve WhatsApp pazarlama', 'Tur hatırlatmaları', 'Gelir analizi']],
    ];
}

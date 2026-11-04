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
            if (array_intersect($subs, self::SELLERS)) {
                return true;
            }
            // TripAdvisor بالعربي أو التركي بيسمّي التصنيفات بلغته («رحلات خاصة»، «جولات…»، «Tekne Turları»): من غير ده كل الشركات بتتحسب معالم وتتخطّى
            foreach ($subs as $s) {
                $s = (string) preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', (string) $s);
                if (preg_match('/جولات|رحلات|سفاري|تأجير|إيجار|سيارات أجرة|حافلات|غوص|قفز|تجديف|ركوب|ورش|دروس|منتجع|حياة ليلية|ألعاب|طعام|مشروبات|\bTur(lar)?\b|Turları|Safari|Tekne|Transfer|Dalış|Kiralama|Aktivite|Yemek|Spa\b/iu', $s)) {
                    return true;
                }
            }
            return false;
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
            '{{company}}' => OutreachAudit::shortName((string) ($lead['company'] ?? '')),
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

    /**
     * رسايل واتساب (مكتب التواصل). القاعدة: ماتتكتبش جملة إلا لو مبنية على حاجة اتفحصت فعلًا.
     * الخدمات المذكورة هي اللي شغّالة فعلًا (المقالات الأسبوعية وقياس الكلمات وصفحات Meta مش موعودة هنا).
     */
    private const DESK = [
        'ar' => [
            'greet' => 'أهلًا بحضراتكم في {company} 👋',
            'praise' => 'ما شاء الله، ⭐ {r} من {n} تقييم على {src}: سمعة قوية فعلًا 👏', 'src_ta' => 'تريب أدفايزر', 'src_g' => 'جوجل',
            'intro_p' => 'أنا {name} من Tourfecto. بصّيت على موقعكم وصفحاتكم عشان السمعة دي تتحوّل لحجوزات أكتر، وده اللي لقيته فعلًا:',
            'intro' => 'أنا {name} من Tourfecto. بصّيت على موقعكم وصفحاتكم على جوجل والسوشيال، وده اللي لقيته فعلًا:',
            'intro_ok' => 'أنا {name} من Tourfecto. بصّيت على حضوركم الرقمي، وده الملخص:',
            'all_ok' => '✅ حضوركم قوي في كل اللي فحصناه',
            'web' => '🌐 *الموقع:*', 'seo' => '🔎 *السيو:*', 'google' => '🗺️ *جوجل ماب:*', 'social' => '📱 *السوشيال:*',
            'fb' => 'فيسبوك', 'ig' => 'إنستجرام',
            'seo_partial' => 'عندكم أساسيات، بس ناقص {list}', 'seo_missing' => 'شبه معدوم، ناقص {list}',
            'parts' => ['title' => 'عنوان صفحة واضح', 'description' => 'وصف جوجل', 'h1' => 'العنوان الرئيسي (H1)', 'schema' => 'بيانات منظّمة (Schema)', 'sitemap' => 'خريطة الموقع', 'blog' => 'مدونة ومقالات', 'alt' => 'وصف للصور'],
            'web_issue' => ['site_down' => 'مش بيفتح', 'no_https' => 'من غير HTTPS', 'no_mobile' => 'مش متوافق مع الموبايل', 'slow' => 'بطيء ({load} ثانية)', 'heavy' => 'تقيل في التحميل',
                'no_booking' => 'مفيهوش حجز ودفع أونلاين', 'no_chat' => 'مفيهوش واتساب ولا شات للرد الفوري', 'one_language' => 'بلغة واحدة بس'],
            'ta' => '✈️ *تريب أدفايزر:*', 'ta_photos' => 'صور قليلة ({n})', 'ta_neg' => 'تقييمات سلبية محتاجة رد', 'g_missing' => 'مالقيناش ملف لشركتكم', 'g_hours' => 'مواعيد العمل مش مكتوبة', 'g_below' => 'تقييماتكم أقل من المنافسين', 'g_low' => 'التقييم محتاج تحسين',
            'g_ta' => 'تقييمات جوجل ({g}) أقل بكتير من تريب أدفايزر ({t})', 'g_ok' => 'ملف قوي ✅', 'reviews' => 'تقييم',
            'linked' => 'مربوطة بموقعكم', 'not_linked' => 'مش مربوطة بموقعكم', 'ph' => 'اللينك على موقعكم مش بيودّي لصفحتكم',
            'act' => ['active' => 'نشيطة 👍', 'stale' => 'آخر منشور قديم', 'empty' => 'مفيهاش منشورات', 'notfound' => 'مش لاقيين صفحتكم'],
            'why' => ['seo' => 'السيو هو اللي بيخلّي المسافر يلاقيكم على جوجل قبل المنافسين، ومن غيره الحجز بيروح لغيركم.',
                'google' => 'ملف جوجل ماب هو أول حاجة بيشوفها المسافر قبل ما يكلّمكم.',
                'social' => 'المسافر بيدخل صفحاتكم يتأكد إنكم شغّالين قبل ما يحجز.',
                'booking' => 'المسافر اللي مش لاقي يحجز على موقعكم بيروح لغيركم.',
                'chat' => 'المسافر اللي مابيلاقيش رد بسرعة بيحجز مع أول حد يرد عليه.'],
            'solve' => 'وفي Tourfecto بنحلّها:',
            'serv' => ['seo' => '• السيو: بيفحص الموقع ويجهّز إصلاح كل نقطة ناقصة وخطة تحسين', 'google' => '• جوجل: مسودات ردود جاهزة على التقييمات ومراجعة ملفكم', 'social' => '• السوشيال: بوستات وصور وفيديوهات لشهر كامل بلوجو شركتكم',
                'booking' => '• محرّك حجز على موقعكم بدفع أونلاين من غير عمولة', 'chat' => '• شات ذكي على موقعكم بيرد على أسئلة زباينكم'],
            'grow' => 'وللنمو: *العقل الاستراتيجي* بيقترح لكم قرارات من بيانات شغلكم.',
            'report' => '📄 *التقرير الكامل* بالتفصيل وإزاي تتصلّح (مجاني):', 'cta' => '{trial}. ردّوا بكلمة «تمام» وأرتّبلكم الخطوات على شركتكم 🙌', 'cta_plain' => 'ردّوا بكلمة «تمام» وأرتّبلكم الخطوات على شركتكم 🙌',
            's2_intro' => '{name} من Tourfecto تاني 🙌 دي نبذة سريعة عن اللي بنقدّمه لشركات السياحة في منصة واحدة:',
            's2' => ["🧠 *العقل الاستراتيجي:* بيقرا بيانات شركتكم (حجوزات، عملاء، تقييمات) ويقترح قرارات بأدلة: عملاء محتاجين متابعة، أسواق بتجيب طلب، وأسعاركم قدام المنافسين. إنتم اللي بتوافقوا وهو يجهّز التنفيذ.",
                '🔎 *السيو والظهور في جوجل* وفي إجابات الذكاء الاصطناعي', '📅 *موقع وحجز ودفع أونلاين* من غير عمولة', '👁️ *مراقبة المنافسين* وتغيّر أسعارهم',
                '🧩 وكمان: CRM وتسويق بالبريد'],
            's2_plan' => 'الباقة اللي بنبدأ بيها مع شركات زيكم: *Starter* بـ {price} في الشهر.',
            's2_cta' => '{trial}. وأظبطها معاكم في مكالمة 15 دقيقة، وتبدأوا من هنا:', 's2_start' => 'وأظبطها معاكم في مكالمة 15 دقيقة، وتبدأوا من هنا:',
        ],
        'en' => [
            'greet' => 'Hello {company} team 👋',
            'praise' => 'Impressive: ⭐ {r} from {n} reviews on {src}. That is a strong reputation 👏', 'src_ta' => 'Tripadvisor', 'src_g' => 'Google',
            'intro_p' => "I'm {name} from Tourfecto. I looked at your website and pages to see how that reputation can turn into more bookings. Here is what I found:",
            'intro' => "I'm {name} from Tourfecto. I looked at your website and your Google and social pages. Here is what I actually found:",
            'intro_ok' => "I'm {name} from Tourfecto. I looked at your online presence. Summary:",
            'all_ok' => '✅ You are strong on everything we checked',
            'web' => '🌐 *Website:*', 'seo' => '🔎 *SEO:*', 'google' => '🗺️ *Google Maps:*', 'social' => '📱 *Social:*',
            'fb' => 'Facebook', 'ig' => 'Instagram',
            'seo_partial' => 'you have the basics, but missing {list}', 'seo_missing' => 'almost absent, missing {list}',
            'parts' => ['title' => 'a clear page title', 'description' => 'a Google description', 'h1' => 'a main heading (H1)', 'schema' => 'structured data (Schema)', 'sitemap' => 'a sitemap', 'blog' => 'a blog / articles', 'alt' => 'image descriptions'],
            'web_issue' => ['site_down' => 'not loading', 'no_https' => 'no HTTPS', 'no_mobile' => 'not mobile-friendly', 'slow' => 'slow ({load}s)', 'heavy' => 'heavy to load',
                'no_booking' => 'no online booking and payment', 'no_chat' => 'no WhatsApp or chat for instant replies', 'one_language' => 'one language only'],
            'ta' => '✈️ *Tripadvisor:*', 'ta_photos' => 'few photos ({n})', 'ta_neg' => 'negative reviews need a reply', 'g_missing' => "we couldn't find your profile", 'g_hours' => 'opening hours are missing', 'g_below' => 'fewer reviews than your competitors', 'g_low' => 'the rating needs work',
            'g_ta' => 'Google reviews ({g}) are far fewer than Tripadvisor ({t})', 'g_ok' => 'strong profile ✅', 'reviews' => 'reviews',
            'linked' => 'linked from your website', 'not_linked' => 'not linked from your website', 'ph' => 'the link on your website does not lead to your page',
            'act' => ['active' => 'active 👍', 'stale' => 'last post is old', 'empty' => 'no posts', 'notfound' => "we couldn't find your page"],
            'why' => ['seo' => 'SEO is what makes travellers find you on Google before your competitors; without it the booking goes elsewhere.',
                'google' => 'Your Google Maps profile is the first thing a traveller sees before contacting you.',
                'social' => 'Travellers visit your pages to check you are active before booking.',
                'booking' => 'A traveller who cannot book on your website goes to someone else.',
                'chat' => 'A traveller who gets no quick reply books with the first company that answers.'],
            'solve' => 'At Tourfecto we fix it:',
            'serv' => ['seo' => '• SEO: audits your site and prepares a fix for every missing point, plus an improvement plan', 'google' => '• Google: ready draft replies to reviews and a review of your profile', 'social' => '• Social: a month of posts, images and short videos with your logo',
                'booking' => '• A booking engine on your site with online payment and no commission', 'chat' => '• A smart chat on your website that answers your customers\' questions'],
            'grow' => 'And to grow: the *Strategic Brain* suggests decisions from your own business data.',
            'report' => '📄 *Full report* with every point and how to fix it (free):', 'cta' => '{trial}. Reply "yes" and I will lay out the steps for your company 🙌', 'cta_plain' => 'Reply "yes" and I will lay out the steps for your company 🙌',
            's2_intro' => "{name} from Tourfecto again 🙌 a quick overview of what we offer tour companies in one platform:",
            's2' => ['🧠 *Strategic Brain:* reads your business data (bookings, customers, reviews) and proposes evidence-based decisions: customers to follow up, markets that bring demand, your prices against competitors. You approve, it prepares the execution.',
                '🔎 *SEO and visibility* on Google and in AI answers', '📅 *Website, booking and online payment*, no commission', '👁️ *Competitor monitoring* and their price changes',
                '🧩 Also: CRM and email marketing'],
            's2_plan' => 'The plan we start tour companies with: *Starter* at {price} a month.',
            's2_cta' => '{trial}. I can set it up with you on a 15-minute call, start here:', 's2_start' => 'I can set it up with you on a 15-minute call, start here:',
        ],
        'tr' => [
            'greet' => 'Merhaba {company} ekibi 👋',
            'praise' => 'Etkileyici: {src}’da {n} yorumla ⭐ {r}. Gerçekten güçlü bir itibar 👏', 'src_ta' => 'Tripadvisor', 'src_g' => 'Google',
            'intro_p' => 'Ben Tourfecto’dan {name}. Bu itibarın daha fazla rezervasyona dönüşmesi için web sitenize ve sayfalarınıza baktım, bulduklarım şunlar:',
            'intro' => 'Ben Tourfecto’dan {name}. Web sitenize ve Google ile sosyal medya sayfalarınıza baktım, gerçekten bulduklarım şunlar:',
            'intro_ok' => 'Ben Tourfecto’dan {name}. Dijital varlığınıza baktım, özet:',
            'all_ok' => '✅ Baktığımız her konuda güçlüsünüz',
            'web' => '🌐 *Web sitesi:*', 'seo' => '🔎 *SEO:*', 'google' => '🗺️ *Google Haritalar:*', 'social' => '📱 *Sosyal medya:*',
            'fb' => 'Facebook', 'ig' => 'Instagram',
            'seo_partial' => 'temeller var ama eksik: {list}', 'seo_missing' => 'neredeyse yok, eksik: {list}',
            'parts' => ['title' => 'net bir sayfa başlığı', 'description' => 'Google açıklaması', 'h1' => 'ana başlık (H1)', 'schema' => 'yapılandırılmış veri (Schema)', 'sitemap' => 'site haritası', 'blog' => 'blog ve makaleler', 'alt' => 'görsel açıklamaları'],
            'web_issue' => ['site_down' => 'açılmıyor', 'no_https' => 'HTTPS yok', 'no_mobile' => 'mobil uyumlu değil', 'slow' => 'yavaş ({load} sn)', 'heavy' => 'yüklemesi ağır',
                'no_booking' => 'online rezervasyon ve ödeme yok', 'no_chat' => 'anında yanıt için WhatsApp veya sohbet yok', 'one_language' => 'tek dilli'],
            'ta' => '✈️ *Tripadvisor:*', 'ta_photos' => 'az fotoğraf ({n})', 'ta_neg' => 'olumsuz yorumların yanıtlanması gerekiyor', 'g_missing' => 'profilinizi bulamadık', 'g_hours' => 'çalışma saatleri eksik', 'g_below' => 'rakiplerden daha az yorum', 'g_low' => 'puanın iyileştirilmesi gerekiyor',
            'g_ta' => 'Google yorumları ({g}) Tripadvisor’dan ({t}) çok az', 'g_ok' => 'güçlü profil ✅', 'reviews' => 'yorum',
            'linked' => 'web sitenizde bağlı', 'not_linked' => 'web sitenizde bağlı değil', 'ph' => 'web sitenizdeki bağlantı sayfanıza gitmiyor',
            'act' => ['active' => 'aktif 👍', 'stale' => 'son gönderi eski', 'empty' => 'gönderi yok', 'notfound' => 'sayfanızı bulamadık'],
            'why' => ['seo' => 'SEO, gezginin sizi rakiplerden önce Google’da bulmasını sağlar; olmadan rezervasyon başkasına gider.',
                'google' => 'Google Haritalar profiliniz, gezginin size yazmadan önce gördüğü ilk şeydir.',
                'social' => 'Gezginler rezervasyondan önce aktif olduğunuzu görmek için sayfalarınıza bakar.',
                'booking' => 'Web sitenizden rezervasyon yapamayan gezgin başkasına gider.',
                'chat' => 'Hızlı yanıt bulamayan gezgin, ilk yanıt veren şirketle rezervasyon yapar.'],
            'solve' => 'Tourfecto’da bunu çözüyoruz:',
            'serv' => ['seo' => '• SEO: sitenizi tarar, eksik her nokta için düzeltme ve iyileştirme planı hazırlar', 'google' => '• Google: yorumlar için hazır yanıt taslakları ve profil kontrolü', 'social' => '• Sosyal medya: logonuzla bir aylık gönderi, görsel ve kısa video',
                'booking' => '• Sitenize komisyonsuz, online ödemeli rezervasyon motoru', 'chat' => '• Web sitenizde müşteri sorularını yanıtlayan akıllı sohbet'],
            'grow' => 'Büyümek için: *Stratejik Beyin* kendi işletme verilerinizden kararlar önerir.',
            'report' => '📄 *Tam rapor*: her nokta ve nasıl düzeltileceği (ücretsiz):', 'cta' => '{trial}. “Evet” yazın, şirketiniz için adımları çıkarayım 🙌', 'cta_plain' => '“Evet” yazın, şirketiniz için adımları çıkarayım 🙌',
            's2_intro' => '{name}, Tourfecto’dan tekrar 🙌 turizm şirketlerine tek platformda sunduklarımızın kısa özeti:',
            's2' => ['🧠 *Stratejik Beyin:* işletme verilerinizi (rezervasyon, müşteri, yorum) okur ve kanıtlı kararlar önerir: takip edilecek müşteriler, talep getiren pazarlar, fiyatlarınız ve rakipler. Siz onaylarsınız, o uygulamayı hazırlar.',
                '🔎 *SEO ve görünürlük:* Google’da ve yapay zekâ yanıtlarında', '📅 *Web sitesi, rezervasyon ve online ödeme*, komisyonsuz', '👁️ *Rakip takibi* ve fiyat değişiklikleri',
                '🧩 Ayrıca: CRM ve e-posta pazarlama'],
            's2_plan' => 'Turizm şirketleriyle başladığımız paket: *Starter*, ayda {price}.',
            's2_cta' => '{trial}. 15 dakikalık bir görüşmede birlikte kurabiliriz, buradan başlayın:', 's2_start' => '15 dakikalık bir görüşmede birlikte kurabiliriz, buradan başlayın:',
        ],
    ];

    /**
     * رسالة الفحص (الأولى): قصيرة، من الحقيقي بس، وبتنتهي بلينك التقرير الكامل.
     * @param array $c lang, company, name, title, scorecard, seo (seoAssessment), gaps (نواقص الفحص الخام), report_url, trial
     */
    public static function deskMessage(array $c): string
    {
        $lang = in_array($c['lang'] ?? '', self::LANGS, true) ? $c['lang'] : 'en';
        $t = self::DESK[$lang];
        $sep = $lang === 'ar' ? '، ' : ', '; // فاصلة عربية جوه نص تركي أو إنجليزي كانت بتبان غلط
        $fill = static fn (string $s, array $v): string => strtr($s, $v);
        $num = static fn ($n): string => number_format((float) $n, 1, '.', '');
        $sc = (array) ($c['scorecard'] ?? []);
        $gaps = (array) ($c['gaps'] ?? []);
        $byId = [];
        foreach ($gaps as $g) {
            $byId[$g['id'] ?? ''] ??= (array) ($g['vars'] ?? []);
        }
        $body = [];
        $svc = [];

        // الموقع: مشاكل تقنية وتحويل حقيقية من الفحص
        $web = [];
        foreach ($t['web_issue'] as $id => $phrase) {
            if (isset($byId[$id]) && count($web) < 3) {
                $web[] = $fill($phrase, ['{load}' => (string) ($byId[$id]['load'] ?? '')]);
                if ($id === 'no_booking') {
                    $svc['booking'] = true;
                } elseif ($id === 'no_chat') {
                    $svc['chat'] = true;
                }
            }
        }
        if ($web) {
            $body[] = $t['web'] . ' ' . implode($sep, $web);
        }

        // السيو: بس لو الفحص أثبت إنه ناقص، وبالتفصيل اللي ناقص فعلًا
        $seo = (array) ($c['seo'] ?? []);
        if (in_array($seo['state'] ?? '', ['partial', 'missing'], true)) {
            $list = implode($sep, array_map(static fn ($k) => $t['parts'][$k] ?? $k, array_slice((array) $seo['fails'], 0, 3)));
            $body[] = $t['seo'] . ' ' . $fill($t[$seo['state'] === 'missing' ? 'seo_missing' : 'seo_partial'], ['{list}' => $list]);
            $svc['seo'] = true;
        }

        // تريب أدفايزر: التقييم وعدد المراجعات والترتيب من بيانات الاستيراد، ومشاكلها الفعلية (صور قليلة، تقييمات سلبية كتير)
        $a = (array) ($sc['tripadvisor'] ?? []);
        if (($a['status'] ?? '') === 'found' && ($a['rating'] ?? null) !== null) {
            $x = $t['ta'] . ' ⭐ ' . $num($a['rating']) . ' (' . number_format((int) ($a['reviews'] ?? 0)) . ' ' . $t['reviews'] . ')' . (!empty($a['ranking']) ? ' · ' . self::localizeRanking((string) $a['ranking'], $lang) : '') . (!empty($a['award']) ? ' 🏆' : '');
            $taIssues = [];
            if (($a['photos'] ?? null) !== null && (int) $a['photos'] < 20) {
                $taIssues[] = $fill($t['ta_photos'], ['{n}' => (string) (int) $a['photos']]);
                $svc['social'] = true;
            }
            if ((int) ($a['reviews'] ?? 0) > 0 && (int) ($a['negative'] ?? 0) / (int) $a['reviews'] >= 0.05) {
                $taIssues[] = $t['ta_neg'];
                $svc['google'] = true;
            }
            $body[] = $x . ($taIssues ? ' · ' . implode($sep, $taIssues) : '');
        }

        // جوجل ماب
        $g = (array) ($sc['google'] ?? []);
        if (($g['status'] ?? '') === 'found' && ($g['rating'] ?? null) !== null) {
            $issues = [];
            if (($g['hours'] ?? null) === false) {
                $issues[] = $t['g_hours'];
            }
            if (!empty($g['median']) && (int) ($g['reviews'] ?? 0) < (int) $g['median'] * 0.5) {
                $issues[] = $t['g_below'];
            }
            if ((float) $g['rating'] < 4.3) {
                $issues[] = $t['g_low'];
            }
            if (isset($byId['google_few_reviews'])) {
                $issues[] = $fill($t['g_ta'], ['{g}' => number_format((int) ($byId['google_few_reviews']['g_reviews'] ?? 0)), '{t}' => number_format((int) ($byId['google_few_reviews']['ta_reviews'] ?? 0))]);
            }
            $body[] = $t['google'] . ' ⭐ ' . $num($g['rating']) . ' (' . number_format((int) ($g['reviews'] ?? 0)) . ' ' . $t['reviews'] . ')' . ' · ' . ($issues ? implode($sep, $issues) : $t['g_ok']);
            if ($issues) {
                $svc['google'] = true;
            }
        } elseif (($g['status'] ?? '') === 'missing') {
            $body[] = $t['google'] . ' ' . $t['g_missing'];
            $svc['google'] = true;
        }

        // السوشيال: اللي الموظفة شافته على الصفحة لو سجّلته، وإلا مربوطة/مش مربوطة من الموقع (من غير ادّعاء عن النشاط)
        $pieces = [];
        $flag = false;
        foreach ([['facebook', 'fb'], ['instagram', 'ig']] as [$k, $label]) {
            $n = (array) ($sc[$k] ?? []);
            $act = $n['activity'] ?? null;
            $s = (string) ($n['status'] ?? 'unknown');
            if ($act !== null) {
                $pieces[] = $t[$label] . ' ' . $t['act'][$act];
                $flag = $flag || $act !== 'active';
            } elseif ($s === 'linked') {
                $pieces[] = $t[$label] . ' ' . $t['linked'];
            } elseif ($s === 'placeholder') {
                $pieces[] = $t[$label] . ' ' . $t['ph'];
                $flag = true;
            } elseif ($s === 'missing') {
                $pieces[] = $t[$label] . ' ' . $t['not_linked'];
                $flag = true;
            }
        }
        if ($pieces) {
            $body[] = $t['social'] . ' ' . implode(' · ', $pieces);
            if ($flag) {
                $svc['social'] = true;
            }
        }

        // سطر مدح من رقم حقيقي (تقييم قوي وعدد معتبر)، بس لما فيه نواقص نقولها بعده: المدح مش بيتكتب من غير سند
        $praise = '';
        if ($svc) {
            foreach ([['tripadvisor', 'src_ta', 100], ['google', 'src_g', 50]] as [$k, $srcKey, $minReviews]) {
                $p = (array) ($sc[$k] ?? []);
                if (($p['status'] ?? '') === 'found' && (float) ($p['rating'] ?? 0) >= 4.5 && (int) ($p['reviews'] ?? 0) >= $minReviews) {
                    $praise = $fill($t['praise'], ['{r}' => $num($p['rating']), '{n}' => number_format((int) $p['reviews']), '{src}' => $t[$srcKey]]);
                    break;
                }
            }
        }
        $lines = [$fill($t['greet'], ['{company}' => OutreachAudit::shortName((string) $c['company'])])];
        if ($praise !== '') {
            $lines[] = $praise;
        }
        $lines[] = $fill($praise !== '' ? $t['intro_p'] : ($svc || $body ? $t['intro'] : $t['intro_ok']), ['{name}' => $c['name']]);
        $lines[] = '';
        $lines = array_merge($lines, $body ?: [$t['all_ok']]);
        if ($svc) {
            // جملة «ليه مهم» واحدة، للأهم: سيو ثم جوجل ثم سوشيال ثم حجز ثم شات
            foreach (['seo', 'google', 'social', 'booking', 'chat'] as $k) {
                if (isset($svc[$k])) {
                    $lines[] = '';
                    $lines[] = '💡 ' . $t['why'][$k];
                    break;
                }
            }
            $lines[] = $t['solve'];
            $shown = 0;
            foreach (['seo', 'google', 'social', 'booking', 'chat'] as $k) {
                if (isset($svc[$k]) && $shown++ < 3) {
                    $lines[] = $t['serv'][$k];
                }
            }
        } else {
            $lines[] = '';
            $lines[] = '💡 ' . $t['grow'];
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

    /**
     * تريب أدفايزر بيصنّف الشركة تحت «تسوق» أو «أطعمة» أو «حياة ليلية»...: ممكن تبقى جولة أكل أو تسوق فعلًا، وممكن تبقى محل أو مطعم أو نادي.
     * بنرجّع الكلمة اللي اتطابقت عشان الموظف يراجع قبل ما يبعت (مابنشيلش الشركة).
     */
    public static function nonTourSignal(string $ranking, string $category = ''): ?string
    {
        if (preg_match('/(تسوق|أطعمة ومشروبات|حياة ليلية|مطاعم|Shopping|Food\s*&\s*Drink|Nightlife|Concerts|Restaurants?|Spas?\b|Alışveriş|Yeme\s*&?\s*İçme|Gece Hayatı)/iu', $ranking . ' ' . $category, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * ترتيب تريب أدفايزر زي ما جه في الملف («#5 of 147 Concerts & Shows in Istanbul»): بالتركي والإنجليزي بيتكتب بنفس لغة الرسالة من غير «of» إنجليزي جوه نص تركي.
     * العربي بييجي جاهز من الملف (رقم 267 من 2,709 …) فبيفضل زي ما هو
     */
    public static function localizeRanking(string $ranking, string $lang): string
    {
        $ranking = trim($ranking);
        if ($lang === 'tr' && preg_match('/^#\s*([\d.,]+)\s+of\s+([\d.,]+)\s+(.+?)(?:\s+in\s+(.+))?$/iu', $ranking, $m)) {
            return $m[1] . '. sıra / ' . $m[2] . ' · ' . $m[3] . (isset($m[4]) && $m[4] !== '' ? ', ' . $m[4] : '');
        }
        return $ranking;
    }

    /** سعر باقة Starter الشهري كما في صفحة /pricing (79 دولار، والجنيه بسعر 50 للدولار زي الصفحة): بالجنيه لمصر، وبالدولار لباقي الدول */
    public static function starterPrice(string $country, string $lang, float $usd = 79.0, float $usdEgp = 50.0): string
    {
        $fmt = static fn (float $n): string => rtrim(rtrim(number_format($n, 2, '.', ','), '0'), '.');
        if (strtoupper($country) === 'EG') {
            $egp = number_format(round($usd * $usdEgp), 0, '.', ',');
            return $lang === 'ar' ? $egp . ' جنيه' : 'EGP ' . $egp;
        }
        return '$' . $fmt($usd);
    }

    /** الرسالة التانية (بعد ما يردّوا): خدمات المنصة، قصيرة، وآخرها لينك التسجيل بتاعهم */
    public static function deskServicesMessage(array $c): string
    {
        $lang = in_array($c['lang'] ?? '', self::LANGS, true) ? $c['lang'] : 'en';
        $t = self::DESK[$lang];
        $lines = [strtr($t['s2_intro'], ['{name}' => $c['name']]), ''];
        foreach ($t['s2'] as $x) {
            $lines[] = $x;
        }
        $lines[] = '';
        $lines[] = strtr($t['s2_plan'], ['{price}' => self::starterPrice((string) ($c['country'] ?? ''), $lang, (float) ($c['price_usd'] ?? 79), (float) ($c['usd_egp'] ?? 50))]);
        $lines[] = '';
        $trial = trim((string) ($c['trial'] ?? ''));
        $lines[] = $trial !== '' ? strtr($t['s2_cta'], ['{trial}' => rtrim($trial, '.')]) : $t['s2_start'];
        $lines[] = (string) $c['signup_url'];
        return rtrim(implode("\n", $lines));
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
        'reputation' => ['icon' => '⭐', 'ar' => ['السمعة والتقييمات', 'مسودات ردود على التقييمات وطلب تقييم بعد الرحلة'], 'en' => ['Reviews & reputation', 'Draft replies to reviews and post-trip review requests'], 'tr' => ['Yorumlar ve itibar', 'Yorumlar için yanıt taslakları ve tur sonrası yorum isteği']],
        'direct_booking' => ['icon' => '🎟️', 'ar' => ['حجز مباشر', 'حجز ودفع على موقعك من غير عمولة'], 'en' => ['Direct bookings', 'Bookings and payments on your site, no commission'], 'tr' => ['Doğrudan rezervasyon', 'Sitenizde komisyonsuz rezervasyon ve ödeme']],
        'website' => ['icon' => '🌐', 'ar' => ['موقع احترافي', 'موقع سريع بكذا لغة في دقايق'], 'en' => ['Professional website', 'A fast multilingual site in minutes'], 'tr' => ['Profesyonel web sitesi', 'Dakikalar içinde hızlı, çok dilli site']],
        'seo' => ['icon' => '🔎', 'ar' => ['الظهور في جوجل', 'إصلاح الموقع وتجهيز المقالات'], 'en' => ['Google visibility', 'Site fixes and article drafts'], 'tr' => ['Google görünürlüğü', 'Site düzeltmeleri ve makale taslakları']],
        'social' => ['icon' => '📱', 'ar' => ['السوشيال ميديا', 'بوستات وفيديوهات لشهر كامل جاهزة'], 'en' => ['Social media', 'A month of posts and videos, ready to use'], 'tr' => ['Sosyal medya', 'Bir aylık gönderi ve video hazır']],
        'ads' => ['icon' => '📈', 'ar' => ['الإعلانات', 'تتبّع الحجوزات لكل مصدر'], 'en' => ['Ads', 'Bookings tracked per source'], 'tr' => ['Reklamlar', 'Kaynak başına rezervasyon takibi']],
        'chat' => ['icon' => '💬', 'ar' => ['الرد على الزباين', 'شات ذكي على موقعكم'], 'en' => ['Customer replies', 'A smart chat on your website'], 'tr' => ['Müşteri yanıtları', 'Web sitenizde akıllı sohbet']],
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
            'website' => 'مالقيناش موقع لـ {{company}}، فالمسافر اللي بيلاقيكم مالوش غير إنه يحجز من منصات تانية.',
            'website_down' => 'حاولت أفتح {{domain}} ومافتحش معايا، ولو ده بيحصل مع المسافرين يبقى فيه حجوزات بتضيع.',
            'seo_slow' => '{{domain}} أخد حوالي {{load}} ثانية عشان يفتح لما جرّبته، والصفحة البطيئة بتخسر الزوار وترتيب جوجل مع بعض.',
            'seo_no_blog' => '{{domain}} مافيهوش مدونة أو أدلة سياحية، والمحتوى بيساعد الموقع يظهر في جوجل.',
            'seo_default' => '{{domain}} ممكن يظهر أعلى بكتير في جوجل للجولات اللي بتبيعوها.',
            'social' => 'مالقيناش لينكات لإنستجرام أو فيسبوك على {{domain}}.',
            'social_photos' => 'عند {{company}} {{photos}} صورة بس على TripAdvisor، والصور هي أول حاجة المسافر بيشوفها.',
            'ads' => '{{domain}} مافيهوش Meta Pixel ولا كود جوجل، فلو بتعملوا إعلانات مش هتعرفوا أنهي إعلان بيجيب حجوزات.',
            'chat' => '{{domain}} مافيهوش زرار واتساب ولا شات، والمسافر غالبًا بيحجز مع أول حد يرد عليه.',
            'crm' => 'بـ {{reviews}} تقييم، {{company}} عندها عملاء سابقين كتير، والتواصل معاهم بعد الرحلة بيفتح فرصة لحجوزات تانية.',
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
            'crm' => 'With {{reviews}} reviews, {{company}} has many past guests, and staying in touch after the trip opens the door to repeat bookings.',
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
            'crm' => '{{reviews}} yorumla {{company}} çok sayıda geçmiş misafire sahip; turdan sonra iletişimde kalmak tekrar rezervasyon fırsatı sunar.',
        ],
    ];

    private const TRACK_TEXT = [
        'reputation' => [
            'ar' => ['s1' => '{{company}}: ملاحظة سريعة عن تقييماتكم', 'solution' => 'Tourfecto بيجهّز مسودة رد احترافي لكل تقييم في ثواني، وانتم بتراجعوها وتستخدموها.', 's2' => 'الرد على التقييمات من غير ما تضيعوا ساعات', 'bullets' => "• مسودات ردود جاهزة بلغة الزبون\n• طلب تقييم من الزباين المبسوطين بعد الرحلة\n• أحسن تقييماتكم تظهر على موقعكم", 'pitch' => 'تقييماتكم'],
            'en' => ['s1' => '{{company}}: a quick note about your reviews', 'solution' => 'Tourfecto prepares a professional draft reply to every review in seconds, and you review and use it.', 's2' => 'Replying to reviews without spending hours', 'bullets' => "• Ready draft replies in your guest's language\n• Review requests to happy guests after the trip\n• Your best reviews shown on your own website", 'pitch' => 'your reviews'],
            'tr' => ['s1' => '{{company}}: yorumlarınızla ilgili kısa bir not', 'solution' => 'Tourfecto her yorum için saniyeler içinde profesyonel bir yanıt taslağı hazırlar; siz inceleyip kullanırsınız.', 's2' => 'Saatler harcamadan yorumlara yanıt verin', 'bullets' => "• Misafirinizin dilinde hazır yanıt taslakları\n• Turdan sonra memnun misafirlerden yorum isteği\n• En iyi yorumlarınız kendi web sitenizde", 'pitch' => 'yorumlarınız'],
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
            'ar' => ['s1' => '{{website_or_company}} في جوجل', 'solution' => 'Tourfecto بيفحص موقعكم، وبيصلّح المشاكل التقنية أوتوماتيك، وبيجهّز لكم مقالات لجوجل عن جولاتكم.', 's2' => 'إزاي {{website_or_company}} يطلع أعلى في جوجل', 'bullets' => "• فحص الموقع وإصلاحات جاهزة لكل مشكلة\n• تجهيز مقالات لموقعكم\n• خطة تحسين السيو خطوة بخطوة\n• تجهيز الأسئلة اللي بيسألها الناس للذكاء الاصطناعي زي ChatGPT وGemini", 'pitch' => 'الظهور في جوجل'],
            'en' => ['s1' => '{{website_or_company}} on Google', 'solution' => 'Tourfecto audits your site, fixes the technical issues automatically, and prepares Google-ready articles about your tours.', 's2' => 'Getting {{website_or_company}} higher on Google', 'bullets' => "• A site audit with a ready fix for every problem\n• Article drafts for your site\n• An SEO improvement plan, step by step\n• Preparing the questions people ask AI tools like ChatGPT and Gemini", 'pitch' => 'Google visibility'],
            'tr' => ['s1' => "{{website_or_company}} ve Google", 'solution' => 'Tourfecto sitenizi tarar, teknik sorunları otomatik düzeltir ve turlarınız hakkında Google\'a uygun makaleler hazırlar.', 's2' => "{{website_or_company}} Google'da nasıl yükselir", 'bullets' => "• Site denetimi ve her sorun için hazır düzeltme\n• Siteniz için makale taslakları\n• Adım adım SEO iyileştirme planı\n• İnsanların ChatGPT ve Gemini gibi yapay zekâ araçlarına sorduğu soruların hazırlanması", 'pitch' => 'Google görünürlüğü'],
        ],
        'social' => [
            'ar' => ['s1' => '{{company}} على السوشيال ميديا', 'solution' => 'Tourfecto بيعمل لكم بوستات وصور وفيديوهات قصيرة لشهر كامل بلوجو شركتكم في دقايق، وبتبقى جاهزة تنزّلوها على حساباتكم.', 's2' => 'بوستات شهر كامل لـ {{company}} في 10 دقايق', 'bullets' => "• بوستات وكلام جاهز بلغات زباينكم\n• صور وفيديوهات قصيرة بالذكاء الاصطناعي (Reels وShorts)\n• تنسيقات جاهزة لفيسبوك وإنستجرام وتيك توك\n• أفكار على حسب موسمكم وعروضكم", 'pitch' => 'السوشيال ميديا'],
            'en' => ['s1' => '{{company}} on social media', 'solution' => 'Tourfecto creates a month of posts, images and short videos with your logo in minutes, ready to post on your accounts.', 's2' => 'A month of posts for {{company}} in 10 minutes', 'bullets' => "• Ready posts and captions in your guests' languages\n• AI images and short videos (Reels, Shorts)\n• Formats ready for Facebook, Instagram and TikTok\n• Ideas based on your season and offers", 'pitch' => 'your social media'],
            'tr' => ['s1' => '{{company}} ve sosyal medya', 'solution' => "Tourfecto logonuzla bir aylık gönderi, görsel ve kısa videoyu dakikalar içinde hazırlar ve hesaplarınızda paylaşmaya hazır hale getirir.", 's2' => '{{company}} için 10 dakikada bir aylık gönderi', 'bullets' => "• Misafirlerinizin dillerinde hazır gönderiler ve metinler\n• Yapay zekâ ile görseller ve kısa videolar (Reels, Shorts)\n• Facebook, Instagram ve TikTok için hazır formatlar\n• Sezonunuza ve kampanyalarınıza göre fikirler", 'pitch' => 'sosyal medyanız'],
        ],
        'ads' => [
            'ar' => ['s1' => '{{company}}: إعلانات تتقاس بالحجوزات', 'solution' => 'Tourfecto بيربط كل حجز بالمصدر اللي جابه (إعلان أو بوست أو بحث)، وبيوريكم إيه اللي شغال وإيه اللي لأ.', 's2' => 'إعلانات تعرفوا جابت كام حجز', 'bullets' => "• كل حجز متسجّل على المصدر اللي جابه\n• تقرير بيوضح أي قناة بتجيب حجوزات\n• تنبيهات بالأداء", 'pitch' => 'الإعلانات'],
            'en' => ['s1' => '{{company}}: ads you can measure in bookings', 'solution' => "Tourfecto connects every booking to the source that brought it (an ad, a post or a search) and shows you what works and what doesn't.", 's2' => 'Ads that you can measure in bookings', 'bullets' => "• Every booking recorded against its source\n• A report of which channel brings bookings\n• Performance alerts", 'pitch' => 'your ads'],
            'tr' => ['s1' => '{{company}}: rezervasyonla ölçülen reklamlar', 'solution' => 'Tourfecto her rezervasyonu onu getiren kaynağa (reklam, gönderi ya da arama) bağlar ve neyin işe yaradığını gösterir.', 's2' => 'Kaç rezervasyon getirdiğini bildiğiniz reklamlar', 'bullets' => "• Her rezervasyon kaynağıyla kaydedilir\n• Hangi kanalın rezervasyon getirdiğini gösteren rapor\n• Performans uyarıları", 'pitch' => 'reklamlarınız'],
        ],
        'chat' => [
            'ar' => ['s1' => '{{company}}: الرد على كل مسافر', 'solution' => 'Tourfecto بيحط على موقعكم شات ذكي بيرد على أسئلة زباينكم بأسعاركم وشروطكم انتم، وبيحوّلكم المحادثة لما تحتاجوا.', 's2' => 'الرد على كل مسافر حتى بالليل', 'bullets' => "• شات ذكي على موقعكم\n• ردود ذكية من معلومات شركتكم\n• توافقوا أو تستلموا المحادثة في أي وقت\n• متابعة للي سأل ومحجزش", 'pitch' => 'الرد على الزباين'],
            'en' => ['s1' => '{{company}}: answering every traveller', 'solution' => 'Tourfecto adds a smart chat to your website that answers questions from your customers using your own prices and policies, and passes the conversation to you when needed.', 's2' => 'Answering every traveller, even at night', 'bullets' => "• A smart chat on your website\n• AI replies from your own knowledge base\n• You approve or take over anytime\n• Follow-ups for travellers who didn't book", 'pitch' => 'customer replies'],
            'tr' => ['s1' => '{{company}}: her gezgine yanıt', 'solution' => "Tourfecto web sitenize, müşteri sorularını kendi fiyat ve koşullarınızla yanıtlayan akıllı bir sohbet ekler ve gerektiğinde konuşmayı size devreder.", 's2' => 'Gece bile her gezgine yanıt', 'bullets' => "• Web sitenizde akıllı sohbet\n• Kendi bilgi bankanızdan akıllı yanıtlar\n• İstediğiniz an onaylayın ya da devralın\n• Sorup rezervasyon yapmayanlara takip mesajları", 'pitch' => 'müşteri yanıtları'],
        ],
        'crm' => [
            'ar' => ['s1' => '{{company}}: زباينكم القدام', 'solution' => 'Tourfecto بيجمع كل زباينكم في CRM واحد، وبيبعت المتابعة المناسبة أوتوماتيك: شكر، وطلب تقييم، وعروض لرحلتهم الجاية.', 's2' => 'إزاي الزبون القديم يحجز تاني', 'bullets' => "• كل زباينكم وحجوزاتكم في مكان واحد\n• متابعات جاهزة على الإيميل\n• طلب تقييم بعد كل رحلة\n• عروض موسمية للزباين القدام", 'pitch' => 'متابعة الزباين'],
            'en' => ['s1' => '{{company}}: your past guests', 'solution' => 'Tourfecto keeps every guest in one CRM and sends the right follow-up automatically: thank-you, review request, and offers for their next trip.', 's2' => 'Turning past guests into repeat bookings', 'bullets' => "• All your guests and bookings in one place\n• Ready follow-ups by email\n• Review requests after every trip\n• Seasonal offers to past guests", 'pitch' => 'your guest follow-ups'],
            'tr' => ['s1' => '{{company}}: eski misafirleriniz', 'solution' => "Tourfecto tüm misafirlerinizi tek bir CRM'de toplar ve doğru takip mesajını otomatik gönderir: teşekkür, yorum isteği ve bir sonraki gezileri için teklifler.", 's2' => 'Eski misafirleri tekrar rezervasyona dönüştürmek', 'bullets' => "• Tüm misafir ve rezervasyonlarınız tek yerde\n• E-posta ile hazır takip mesajları\n• Her turdan sonra yorum isteği\n• Eski misafirlere sezonluk teklifler", 'pitch' => 'misafir takibi'],
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
        'reputation' => ['ar' => ['مسودات الردود على التقييمات', 'ردود بلغة الزبون', 'طلبات التقييم بعد الرحلة', 'ويدجت التقييمات على موقعكم'], 'en' => ['Review reply drafts', 'Replies in the guest language', 'Post-trip review requests', 'Reviews widget for your website'], 'tr' => ['Yorum yanıt taslakları', 'Misafirin dilinde yanıtlar', 'Tur sonrası yorum istekleri', 'Web siteniz için yorum widget\'ı']],
        'direct_booking' => ['ar' => ['محرّك الحجز على موقعكم', 'الدفع والعربون أونلاين', 'التذاكر وتسجيل الوصول بـ QR', 'تذكيرات الرحلة'], 'en' => ['Booking engine on your site', 'Online payments and deposits', 'Tickets and QR check-in', 'Trip reminders'], 'tr' => ['Sitenizde rezervasyon motoru', 'Online ödeme ve kapora', 'Bilet ve QR ile giriş', 'Tur hatırlatmaları']],
        'website' => ['ar' => ['منشئ المواقع والدومين', 'موقع بكذا لغة', 'الحجز أونلاين', 'جاهز لجوجل'], 'en' => ['Website builder and domain', 'Multilingual site', 'Online booking', 'Search-ready pages'], 'tr' => ['Web sitesi oluşturucu ve alan adı', 'Çok dilli site', 'Online rezervasyon', 'Arama motoruna hazır sayfalar']],
        'seo' => ['ar' => ['محسّن الموقع والـ Auto SEO', 'تجهيز المقالات', 'خطة تحسين السيو', 'تجهيز الأسئلة للذكاء الاصطناعي (AEO/GEO)'], 'en' => ['Website optimizer and Auto SEO', 'Article drafts', 'SEO improvement plan', 'Preparing for AI search (AEO/GEO)'], 'tr' => ['Site optimizasyonu ve Otomatik SEO', 'Makale taslakları', 'SEO iyileştirme planı', 'Yapay zekâ aramalarına hazırlık (AEO/GEO)']],
        'social' => ['ar' => ['تخطيط محتوى السوشيال ميديا', 'استوديو التصميم: صور وفيديو', 'مساعد التسويق', 'تقويم المحتوى'], 'en' => ['Social media content planning', 'Creative studio: images and video', 'Marketing assistant', 'Content calendar'], 'tr' => ['Sosyal medya içerik planı', 'Kreatif stüdyo: görsel ve video', 'Pazarlama asistanı', 'İçerik takvimi']],
        'ads' => ['ar' => ['تقارير الإعلانات', 'تتبّع الحجوزات لكل مصدر', 'تنبيهات الأداء', 'ذكاء المنافسين'], 'en' => ['Ads reporting', 'Booking tracking per source', 'Performance alerts', 'Competitor intelligence'], 'tr' => ['Reklam raporları', 'Kaynak başına rezervasyon takibi', 'Performans uyarıları', 'Rakip analizi']],
        'chat' => ['ar' => ['الشات الذكي على الموقع', 'قاعدة المعرفة', 'متابعة الاستفسارات', 'المتابعة للي ماحجزش'], 'en' => ['AI chat on your website', 'Knowledge base', 'Enquiry follow-up', 'Follow-ups for non-bookers'], 'tr' => ['Web sitenizde yapay zekâ sohbeti', 'Bilgi bankası', 'Talep takibi', 'Rezervasyon yapmayanlara takip']],
        'crm' => ['ar' => ['CRM والمتابعات', 'التسويق بالإيميل', 'تذكيرات الرحلات', 'ذكاء الإيرادات'], 'en' => ['CRM and sequences', 'Email marketing', 'Trip reminders', 'Revenue intelligence'], 'tr' => ['CRM ve takip dizileri', 'E-posta pazarlama', 'Tur hatırlatmaları', 'Gelir analizi']],
    ];
}

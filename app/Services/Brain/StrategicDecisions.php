<?php
/**
 * Tourfecto - العقل الاستراتيجي: القرارات (منطق نقي من غير قاعدة بيانات)
 *
 * بياخد صورة الشركة من CompanyBrainService ويطلع قرارات مرتبة بالأولوية. كل قرار:
 * - مبني على رقم مقاس فعلًا في الصورة (لو الرقم null القرار مايطلعش)، والدليل بيتحفظ معاه.
 * - بيوجّه لخدمة موجودة بالفعل (action_url) بدل ما العقل ينفّذ بنفسه.
 * - ليه مقياس (metric) بيتقاس قبل وبعد التنفيذ عشان نعرف القرار نفع ولا لأ.
 * - requires_spend = فيه صرف فلوس، فمايتنفّذش غير بموافقة صريحة.
 *
 * الأولوية = أثر القرار × وزن المجال حسب هدف الشركة × وزن المجال من نتايج قراراتها السابقة.
 */
final class StrategicDecisions
{
    public const AREAS = ['data', 'seo', 'reputation', 'whatsapp', 'social', 'ads', 'content', 'acquisition', 'market'];

    /**
     * @param array $s صورة الشركة
     * @param array<string,float> $learned أوزان المجالات من نتايج القرارات السابقة (1 = محايد)
     * @param array<string,array{companies:int,measured:int,improved_rate:float}> $shared نتايج أنواع القرارات عند الشركات المشارِكة (SharedIntelligence)
     * @return array<int,array<string,mixed>>
     */
    public static function decide(array $s, array $learned = [], array $shared = []): array
    {
        $out = [];
        $add = static function (array $d) use (&$out): void { $out[] = $d; };
        $u = $s['understanding'] ?? [];

        // 1) العقل محتاج يفهم الشركة الأول: من غير ملف مؤكد كل الخدمات بتشتغل بمعلومات ناقصة
        if (empty($u['profile_confirmed'])) {
            // أثر 4: ده الأساس اللي كل القرارات التانية بتعتمد عليه، فبييجي الأول دايمًا
            $add(self::d('confirm_profile', 'data', 4,
                ['أكد بيانات شركتك عشان العقل يفهم نشاطك', 'Confirm your company details so the brain understands your business'],
                ['كل الخدمات (السيو والسوشيال والإعلانات والشات) بتاخد معلوماتها من ملف الشركة، وهو لسه مش متأكد.', 'Every service (SEO, social, ads, chat) reads from the company profile, which is not confirmed yet.'],
                [['ملف الشركة: غير مؤكد', 'Company profile: not confirmed']],
                '/business-hub', ['أكد بيانات الشركة', 'Confirm company details'], 'understanding.profile_confirmed', 0, 'up'));
        } else {
            $missing = array_keys(array_filter(['goal' => $u['goal'] ?? null, 'audience' => $u['audience'] ?? null, 'offers' => $u['offers'] ?? null], static fn ($v) => trim((string) $v) === ''));
            if ($missing) {
                $names = ['goal' => ['الهدف', 'goal'], 'audience' => ['الجمهور', 'audience'], 'offers' => ['العروض والأسعار', 'offers and prices']];
                $add(self::d('complete_profile', 'data', 2,
                    ['كمّل الناقص في ملف شركتك', 'Fill the gaps in your company profile'],
                    ['العقل بيرتّب الأولويات حسب هدفك وجمهورك، والمقالات بتحتاج أسعارك المؤكدة.', 'The brain ranks priorities by your goal and audience, and articles need your confirmed prices.'],
                    [[ 'ناقص: ' . implode('، ', array_map(static fn ($k) => $names[$k][0], $missing)), 'Missing: ' . implode(', ', array_map(static fn ($k) => $names[$k][1], $missing))]],
                    '/business-hub', ['كمّل البيانات', 'Complete details'], null, null, null));
            }
        }

        // 2) السمعة: تقييم سلبي من غير رد بيظهر لكل اللي بيدوّر عليك
        $neg = $s['reputation']['unanswered_negative'] ?? null;
        if ($neg !== null && $neg > 0) {
            $add(self::d('reply_negative_reviews', 'reputation', 3,
                ['رد على التقييمات السلبية', 'Reply to negative reviews'],
                ['تقييم سلبي من غير رد بيقلل الحجوزات من كل اللي بيدوّر على اسمك.', 'An unanswered negative review costs bookings from everyone who searches your name.'],
                [[$neg . ' تقييم 3 نجوم أو أقل من غير رد (آخر 30 يوم)', $neg . ' reviews of 3 stars or less without a reply (last 30 days)']],
                '/reputation/reviews', ['رد على التقييمات', 'Reply to reviews'], 'reputation.unanswered_negative', $neg, 'down'));
        }
        $reviews = $s['reputation']['reviews_30'] ?? null;
        if ($reviews !== null && $reviews < 5 && (int) ($s['bookings']['last_90'] ?? 0) >= 5) {
            $add(self::d('ask_for_reviews', 'reputation', 2,
                ['اطلب تقييمات من عملاءك اللي سافروا', 'Ask recent travellers for reviews'],
                ['عندك حجوزات كفاية، لكن التقييمات الجديدة قليلة؛ التقييمات الحديثة بتأثر على الترتيب في الخرائط وعلى قرار الحجز.', 'You have enough bookings but few new reviews; recent reviews affect map rankings and booking decisions.'],
                [[$reviews . ' تقييم في آخر 30 يوم مقابل ' . (int) $s['bookings']['last_90'] . ' حجز في آخر 90 يوم', $reviews . ' reviews in 30 days vs ' . (int) $s['bookings']['last_90'] . ' bookings in 90 days']],
                '/review-requests', ['شغّل طلب التقييمات', 'Turn on review requests'], 'reputation.reviews_30', $reviews, 'up'));
        }

        // 3) الشات/واتساب: ردود جاهزة مستنية موافقة = عملاء مستنيين
        $pending = $s['chat']['pending_approval'] ?? null;
        if ($pending !== null && $pending > 0) {
            $add(self::d('approve_chat_replies', 'whatsapp', 3,
                ['وافق على ردود الشات المستنية', 'Approve the waiting chat replies'],
                ['كل رد متأخر هو عميل ممكن يحجز مع منافس.', 'Every delayed reply is a customer who may book with a competitor.'],
                [[$pending . ' رد جاهز مستني موافقتك', $pending . ' ready replies waiting for your approval']],
                '/chat', ['راجع الردود', 'Review replies'], 'chat.pending_approval', $pending, 'down'));
        }

        // 4) السيو
        $seo = $s['seo'] ?? [];
        if (empty($seo['autopilot'])) {
            $add(self::d('seo_autopilot', 'seo', 2,
                ['شغّل الطيار الآلي للسيو', 'Turn on SEO autopilot'],
                ['من غيره موقعك مش بيتفحص ويتحسن كل أسبوع.', 'Without it your site is not checked and improved every week.'],
                [['الطيار الآلي: متوقف', 'Autopilot: off']],
                '/seo-expert', ['افتح مركز السيو', 'Open the SEO hub'], 'scores.seo_score', $s['scores']['seo_score'] ?? null, 'up'));
        }
        if ((int) ($seo['client_tasks'] ?? 0) > 0) {
            $add(self::d('seo_client_steps', 'seo', 2,
                ['خلّص خطوات السيو المطلوبة منك', 'Finish the SEO steps that need you'],
                ['المنصة طبّقت اللي تقدر عليه، والباقي محتاج قرار أو خطوة منك.', 'The platform applied what it can; the rest needs a decision or step from you.'],
                [[(int) $seo['client_tasks'] . ' خطوة مستنياك في آخر فحص', (int) $seo['client_tasks'] . ' steps waiting in the latest check']],
                '/seo-expert', ['شوف المطلوب', 'See what is needed'], 'seo.client_tasks', (int) $seo['client_tasks'], 'down'));
        }
        $seoScore = $s['scores']['seo_score'] ?? null;
        if ($seoScore !== null && $seoScore < 70) {
            $add(self::d('fix_site_health', 'seo', 2,
                ['صلّح مشاكل موقعك التقنية', 'Fix your site\'s technical issues'],
                ['درجة الموقع أقل من 70، وده بيقلل ظهورك في جوجل.', 'Your site score is below 70, which limits your Google visibility.'],
                [['درجة الفحص: ' . $seoScore . ' من 100', 'Audit score: ' . $seoScore . '/100']],
                '/website-optimizer', ['افتح الفحص', 'Open the audit'], 'scores.seo_score', $seoScore, 'up'));
        }
        if ((int) ($seo['ai_questions_approved'] ?? 0) === 0) {
            $add(self::d('ai_visibility_setup', 'seo', 1,
                ['تابع ظهورك في ChatGPT وGemini', 'Track your visibility in ChatGPT and Gemini'],
                ['المسافرين بقوا بيسألوا الذكاء الاصطناعي عن الرحلات؛ لازم نعرف بيظهر اسمك ولا لأ.', 'Travellers now ask AI assistants about trips; you need to know whether your name appears.'],
                [['مفيش أسئلة معتمدة للقياس', 'No approved questions to measure']],
                '/ai-visibility', ['جهّز الأسئلة', 'Prepare questions'], 'seo.ai_questions_approved', 0, 'up'));
        }

        // 5) الحجوزات والرحلات (من إشارات النمو الحقيقية)
        foreach (($s['growth']['focus_trips'] ?? []) as $t) {
            $name = (string) ($t['name'] ?? '');
            if ($name === '') continue;
            if (($t['reason'] ?? '') === 'needs_bookings_in_peak') {
                $add(self::d('promote_peak_' . (int) $t['id'], 'social', 3,
                    ['روّج لـ"' . $name . '" قبل الموسم', 'Promote "' . $name . '" before peak season'],
                    ['الشهر ده موسم عالي والرحلة دي حجوزاتها قليلة؛ البوستات بتتوزع عليها تلقائيًا في خطة السوشيال، والإعلان المدفوع اختياري بموافقتك.', 'This month is peak season and this trip is under-booked; social plans weight it automatically, paid ads are optional with your approval.'],
                    [[(int) $t['upcoming'] . ' حجز مؤكد للشهر ده', (int) $t['upcoming'] . ' confirmed bookings this month']],
                    '/social#plan', ['افتح خطة السوشيال', 'Open the social plan'], 'trip.' . (int) $t['id'] . '.upcoming', (int) $t['upcoming'], 'up'));
            } elseif (($t['reason'] ?? '') === 'rising_demand') {
                $add(self::d('scale_rising_' . (int) $t['id'], 'acquisition', 2,
                    ['الطلب على "' . $name . '" بيزيد: زوّد ظهورها', 'Demand for "' . $name . '" is rising: increase its visibility'],
                    ['حجوزاتها زادت عن نفس الفترة السنة اللي فاتت؛ مقال وصفحة أقوى ليها بيستغلوا الطلب ده. راجع السعر لو الأماكن بتخلص بسرعة.', 'Bookings are up on the same period last year; a stronger page and article capture that demand. Review the price if seats sell out fast.'],
                    [[(int) $t['recent'] . ' حجز في آخر 90 يوم مقابل ' . (int) $t['previous'] . ' السنة اللي فاتت', (int) $t['recent'] . ' bookings in 90 days vs ' . (int) $t['previous'] . ' a year earlier']],
                    '/seo-content', ['جهّز محتوى للرحلة', 'Prepare content for the trip'], 'trip.' . (int) $t['id'] . '.recent', (int) $t['recent'], 'up'));
            } elseif (($t['reason'] ?? '') === 'awareness') {
                $add(self::d('awareness_' . (int) $t['id'], 'content', 1,
                    ['عرّف الناس بـ"' . $name . '"', 'Build awareness for "' . $name . '"'],
                    ['الرحلة دي ما اتحجزتش قبل كده؛ محتاجة صفحة واضحة ومحتوى قبل ما نصرف عليها إعلانات.', 'This trip has never been booked; it needs a clear page and content before any ad spend.'],
                    [['0 حجز في آخر 90 يوم وفي نفس الفترة السنة اللي فاتت', '0 bookings in the last 90 days and a year earlier']],
                    '/seo-content', ['جهّز محتوى', 'Prepare content'], 'trip.' . (int) $t['id'] . '.recent', 0, 'up'));
            }
        }

        // 6) السوشيال: حساب ساكت = علامة ناسيها العملاء
        if (($s['social'] ?? null) !== null && (int) $s['social']['published_30'] === 0 && (int) $s['social']['scheduled'] === 0) {
            $add(self::d('social_activity', 'social', 2,
                ['ارجع انشر على السوشيال', 'Start posting on social media again'],
                ['مفيش ولا بوست اتنشر أو متجدول في آخر 30 يوم.', 'Nothing was published or scheduled in the last 30 days.'],
                [['0 بوست منشور و0 متجدول', '0 published and 0 scheduled posts']],
                '/social#plan', ['جهّز خطة الشهر', 'Prepare the monthly plan'], 'social.published_30', 0, 'up'));
        }

        // 7) المنافسين والأسواق
        if (($s['competitors']['tracked'] ?? null) === 0) {
            $add(self::d('track_competitors', 'market', 1,
                ['ضيف منافسينك للمراقبة', 'Add competitors to monitor'],
                ['العقل مش هيقدر يقارن أسعارك وعروضك من غير منافسين متابَعين.', 'The brain cannot compare your prices and offers without tracked competitors.'],
                [['0 منافس متابَع', '0 tracked competitors']],
                '/competitor-intelligence', ['ضيف منافس', 'Add a competitor'], 'competitors.tracked', 0, 'up'));
        }
        // الأسواق ممكن تكون مكتوبة في نص الجمهور (ذكاء السوق بيقراها)؛ القرار للي مالهوش أسواق خالص
        $marketsKnown = !empty($u['markets']['countries']) || (isset($s['market']['markets_default']) && $s['market']['markets_default'] === false);
        if (!empty($u['profile_confirmed']) && !$marketsKnown) {
            $add(self::d('define_markets', 'market', 2,
                ['حدد الدول اللي عايز تبيع لها', 'Choose the countries you want to sell to'],
                ['السيو والإعلانات والمحتوى بيتوجهوا حسب السوق؛ من غيره كل خدمة بتخمّن.', 'SEO, ads and content target a market; without it every service guesses.'],
                [['مفيش دول مستهدفة محددة', 'No target countries set']],
                '/ai/keywords', ['حدد الأسواق', 'Set markets'], null, null, null));
        }

        // 8) السوق: إشارات عامة من مصادر رسمية (MarketIntelligenceService)، كل واحدة بمصدرها وتاريخها
        foreach (self::marketDecisions($s['market'] ?? null) as $d) $add($d);

        // الأولوية: الأثر × وزن الهدف × وزن التعلّم × وزن نتايج الشركات التانية (لو فيه 5 شركات أو أكتر)
        $goal = self::goalWeights((string) ($u['goal'] ?? ''));
        foreach ($out as &$d) {
            $w = ($goal[$d['area']] ?? 1.0) * max(0.5, min(1.5, (float) ($learned[$d['area']] ?? 1.0)));
            $p = $shared[(string) preg_replace('/_\d+$/', '', $d['key'])] ?? null;
            if ($p && (int) $p['companies'] >= 5) {
                $w *= round(0.8 + 0.4 * max(0.0, min(1.0, (float) $p['improved_rate'])), 2);
                $pct = (int) round((float) $p['improved_rate'] * 100);
                $d['evidence'][] = ['ar' => 'القرار ده نفع عند ' . $pct . '% من ' . (int) $p['measured'] . ' مرة اتقاس فيها عند ' . (int) $p['companies'] . ' شركة شاركت نتايجها', 'en' => 'This decision worked in ' . $pct . '% of ' . (int) $p['measured'] . ' measured cases across ' . (int) $p['companies'] . ' companies that share results'];
                $d['shared'] = $p;
            }
            $d['priority'] = round($d['impact'] * 10 * $w, 2);
        }
        unset($d);
        usort($out, static fn ($a, $b) => $b['priority'] <=> $a['priority'] ?: strcmp($a['key'], $b['key']));
        return $out;
    }

    /** قرارات من إشارات السوق. $m = null لو ذكاء السوق مش متاح (مفيش قرار من غير بيانات) */
    public static function marketDecisions(?array $m): array
    {
        if (!$m) return [];
        $out = [];
        $names = ['PT' => ['البرتغال', 'Portugal'], 'GR' => ['اليونان', 'Greece'], 'HR' => ['كرواتيا', 'Croatia'], 'CZ' => ['التشيك', 'Czechia'], 'HU' => ['المجر', 'Hungary'], 'MT' => ['مالطا', 'Malta'], 'CY' => ['قبرص', 'Cyprus'], 'IS' => ['أيسلندا', 'Iceland'], 'NO' => ['النرويج', 'Norway'], 'GE' => ['جورجيا', 'Georgia'], 'TZ' => ['تنزانيا', 'Tanzania'], 'KE' => ['كينيا', 'Kenya'],
            'GB' => ['بريطانيا', 'the UK'], 'US' => ['أمريكا', 'the US'], 'DE' => ['ألمانيا', 'Germany'], 'FR' => ['فرنسا', 'France'], 'IT' => ['إيطاليا', 'Italy'], 'ES' => ['إسبانيا', 'Spain'],
            'NL' => ['هولندا', 'the Netherlands'], 'PL' => ['بولندا', 'Poland'], 'CA' => ['كندا', 'Canada'], 'AU' => ['أستراليا', 'Australia'], 'SA' => ['السعودية', 'Saudi Arabia'], 'AE' => ['الإمارات', 'the UAE'],
            'EG' => ['مصر', 'Egypt'], 'JO' => ['الأردن', 'Jordan'], 'MA' => ['المغرب', 'Morocco'], 'TR' => ['تركيا', 'Turkey'], 'RU' => ['روسيا', 'Russia'], 'CN' => ['الصين', 'China'], 'JP' => ['اليابان', 'Japan'], 'IN' => ['الهند', 'India'], 'BE' => ['بلجيكا', 'Belgium'], 'CH' => ['سويسرا', 'Switzerland'], 'AT' => ['النمسا', 'Austria'], 'IE' => ['أيرلندا', 'Ireland']];
        $n = static fn (string $cc, int $i) => $names[$cc][$i] ?? $cc;
        $dest = (string) ($m['destination'] ?? 'EG');

        $adv = $m['travel_advice'] ?? null;
        if ($adv && !empty($adv['recent'])) {
            $out[] = self::d('travel_advice_' . $dest, 'market', 3,
                ['تحذيرات السفر البريطانية عن ' . $n($dest, 0) . ' اتحدثت', 'UK travel advice for ' . $n($dest, 1) . ' was updated'],
                ['المسافرين بيقروها قبل ما يحجزوا. حدّث معلومات المساعد عشان يرد على أسئلة الأمان بدقة، وراجع الإعلانات اللي شغالة.', 'Travellers read it before booking. Update your assistant\'s knowledge so it answers safety questions accurately, and review running ads.'],
                [['آخر تحديث: ' . substr((string) $adv['updated_at'], 0, 10) . ' — ' . (string) $adv['change'], 'Updated: ' . substr((string) $adv['updated_at'], 0, 10) . ' — ' . (string) $adv['change']], ['المصدر: GOV.UK Foreign travel advice', 'Source: GOV.UK Foreign travel advice']],
                '/chat/knowledge-base', ['حدّث معلومات المساعد', 'Update assistant knowledge'], null, null, null);
        }

        $lead = max(14, (int) ($m['lead_time_days'] ?? 45));
        // الإجازة لازم تكون بعد مهلة الحجز بشوية: نبدأ الترويج دلوقتي عشان نلحق اللي بيحجزوا بدري.
        // نفس الإجازة في كذا سوق (زي الكريسماس) = قرار واحد بكل الأسواق
        $windows = [];
        foreach (($m['holidays'] ?? []) as $h) {
            $days = (int) $h['days_away'];
            if ($days < $lead || $days > $lead + 30) continue;
            $k = $h['date'] . '|' . $h['name'];
            $windows[$k] ??= ['date' => (string) $h['date'], 'name' => (string) $h['name'], 'days' => $days, 'countries' => []];
            $windows[$k]['countries'][] = (string) $h['country'];
        }
        foreach (array_slice(array_values($windows), 0, 2) as $w) {
            $ar = implode(' و', array_map(static fn ($c) => $n($c, 0), array_unique($w['countries'])));
            $en = implode(' and ', array_map(static fn ($c) => $n($c, 1), array_unique($w['countries'])));
            $out[] = self::d('holiday_' . str_replace('-', '', $w['date']), 'social', 2,
                [$w['name'] . ' في ' . $ar . ' بعد ' . $w['days'] . ' يوم: ابدأ الترويج دلوقتي', $w['name'] . ' in ' . $en . ' in ' . $w['days'] . ' days: start promoting now'],
                ['عملاءك بيحجزوا قبل الرحلة بحوالي ' . $lead . ' يوم، فده وقت البوستات والعروض الموجهة للأسواق دي.', 'Your guests book about ' . $lead . ' days ahead, so now is the time for posts and offers aimed at these markets.'],
                [[$w['name'] . ' — ' . $w['date'], $w['name'] . ' — ' . $w['date']], ['المصدر: Nager.Date (الإجازات الرسمية)', 'Source: Nager.Date public holidays']],
                '/social#plan', ['جهّز بوستات للأسواق دي', 'Prepare posts for these markets'], null, null, null);
        }

        // العملة: وجهة أوروبية بتسعّر باليورو → يهمنا اليورو قدام الدولار والإسترليني (المسافر الأمريكي والبريطاني).
        // وجهة برّة منطقة اليورو (زي مصر، أسعار بالدولار غالبًا) → يهمنا الدولار قدام اليورو (المسافر الأوروبي).
        $fxDecision = static function (?array $fx, string $cur, array $marketName) use ($m): ?array {
            if (!$fx || !isset($fx['change_pct']) || abs((float) $fx['change_pct']) < 5) return null;
            $eurStronger = (float) $fx['change_pct'] > 0;
            $ev = [[$cur . ' لكل يورو: ' . $fx['base'] . ' ← ' . $fx['latest'] . ' (' . ($eurStronger ? '+' : '') . $fx['change_pct'] . '%) من ' . $fx['from'] . ' لـ ' . $fx['to'], $cur . ' per EUR: ' . $fx['base'] . ' → ' . $fx['latest'] . ' (' . ($eurStronger ? '+' : '') . $fx['change_pct'] . '%) from ' . $fx['from'] . ' to ' . $fx['to']], ['المصدر: البنك المركزي الأوروبي (ECB)', 'Source: European Central Bank (ECB)']];
            if (!empty($m['eurozone'])) {
                // اليورو أضعف = الوجهة الأوروبية أرخص للمسافر اللي عملته الدولار/الإسترليني
                return $eurStronger
                    ? self::d('fx_' . strtolower($cur), 'market', 1, ['اليورو قوي: رحلاتك أغلى على المسافرين من ' . $marketName[0], 'The euro is strong: your trips cost more for travellers from ' . $marketName[1]], ['فكّر في عروض قيمة أو أسعار ثابتة بعملتهم للسوق ده.', 'Consider value offers or fixed prices in their currency for this market.'], $ev, '/ai/keywords', ['راجع أسواقك', 'Review your markets'], null, null, null)
                    : self::d('fx_' . strtolower($cur), 'market', 2, ['اليورو ضعف: رحلاتك بقت أرخص للمسافرين من ' . $marketName[0], 'The euro weakened: your trips are cheaper for travellers from ' . $marketName[1]], ['ده وقت مناسب تزوّد الترويج للسوق ده.', 'This is a good time to promote more to this market.'], $ev, '/ai/keywords', ['راجع أسواقك', 'Review your markets'], null, null, null);
            }
            if ($cur !== 'USD') return null;
            return self::d('fx_eur_usd', 'market', 1,
                $eurStronger ? ['اليورو قوي: رحلاتك بالدولار بقت أرخص للأوروبيين', 'The euro is strong: your USD prices are cheaper for Europeans'] : ['اليورو ضعف: رحلاتك بالدولار بقت أغلى على الأوروبيين', 'The euro weakened: your USD prices cost Europeans more'],
                $eurStronger ? ['لو أسعارك بالدولار، ده وقت مناسب تركّز على ألمانيا وفرنسا وإيطاليا وإسبانيا.', 'If you price in dollars, this is a good time to focus on Germany, France, Italy and Spain.'] : ['لو أسعارك بالدولار، فكّر في عرض باليورو أو ركّز على أسواق الدولار والإسترليني.', 'If you price in dollars, consider a euro offer or focus on USD and GBP markets.'],
                $ev, '/ai/keywords', ['راجع أسواقك', 'Review your markets'], null, null, null);
        };
        foreach ([$fxDecision($m['fx'] ?? null, 'USD', ['أمريكا', 'the US']), $fxDecision($m['fx_gbp'] ?? null, 'GBP', ['بريطانيا', 'the UK'])] as $d) if ($d) $out[] = $d;

        // الطلب على الوجهة الأوروبية (Eurostat): تغيّر ليالي الأجانب عن نفس الشهر السنة اللي فاتت
        $dm = $m['demand'] ?? null;
        if ($dm && isset($dm['change_pct']) && abs((float) $dm['change_pct']) >= 5) {
            $up = (float) $dm['change_pct'] > 0;
            $ev = [['ليالي الزوار الأجانب في ' . $n($dest, 0) . ': ' . number_format((float) $dm['nights']) . ' في ' . $dm['period'] . ' مقابل ' . number_format((float) $dm['previous']) . ' في ' . $dm['previous_period'] . ' (' . ($up ? '+' : '') . $dm['change_pct'] . '%)',
                'Foreign guest nights in ' . $n($dest, 1) . ': ' . number_format((float) $dm['nights']) . ' in ' . $dm['period'] . ' vs ' . number_format((float) $dm['previous']) . ' in ' . $dm['previous_period'] . ' (' . ($up ? '+' : '') . $dm['change_pct'] . '%)'], ['المصدر: Eurostat (tour_occ_nim)', 'Source: Eurostat (tour_occ_nim)']];
            $out[] = $up
                ? self::d('demand_up_' . $dest, 'acquisition', 2, ['الطلب على ' . $n($dest, 0) . ' بيزيد: استغله', 'Demand for ' . $n($dest, 1) . ' is rising: capture it'], ['السياح الأجانب بيزيدوا عن السنة اللي فاتت. زوّد المحتوى والظهور لرحلاتك الأكثر طلبًا، وراجع أسعار المواسم.', 'Foreign visitors are up on last year. Increase content and visibility for your best-selling trips and review seasonal prices.'], $ev, '/seo-content', ['جهّز محتوى', 'Prepare content'], null, null, null)
                : self::d('demand_down_' . $dest, 'market', 2, ['الطلب على ' . $n($dest, 0) . ' بيقل: احمي حجوزاتك', 'Demand for ' . $n($dest, 1) . ' is falling: protect your bookings'], ['السياح الأجانب أقل من السنة اللي فاتت. ركّز على الأسواق اللي لسه بتحجز وخلّي عروضك واضحة القيمة.', 'Foreign visitors are down on last year. Focus on markets still booking and make your offers clearly good value.'], $ev, '/ai/keywords', ['راجع أسواقك', 'Review your markets'], null, null, null);
        }

        foreach (($m['economy'] ?? []) as $cc => $e) {
            if (!isset($e['gdp_growth']) || (float) $e['gdp_growth'] >= 0) continue;
            $out[] = self::d('economy_' . $cc, 'market', 1,
                ['اقتصاد ' . $n($cc, 0) . ' بينكمش: خلّي عروضك فيها قيمة واضحة', $n($cc, 1) . '\'s economy is shrinking: make your offers clearly good value'],
                ['المسافرين من السوق ده غالبًا هيدوروا على أسعار أقل أو رحلات أقصر.', 'Travellers from this market will likely look for lower prices or shorter trips.'],
                [['نمو الناتج المحلي ' . $e['gdp_growth'] . '% في ' . $e['year'], 'GDP growth ' . $e['gdp_growth'] . '% in ' . $e['year']], ['المصدر: World Bank', 'Source: World Bank']],
                '/ai/keywords', ['راجع أسواقك', 'Review your markets'], null, null, null);
        }
        return $out;
    }

    /** وزن كل مجال حسب الهدف اللي الشركة كتبته (كلمات بسيطة، عربي وإنجليزي) */
    public static function goalWeights(string $goal): array
    {
        $g = mb_strtolower($goal);
        $w = array_fill_keys(self::AREAS, 1.0);
        $hit = static fn (string $re) => (bool) preg_match($re, $g);
        if ($hit('/حجز|حجوزات|مبيعات|بيع|book|sale|revenue|إيراد/u')) { $w['acquisition'] = 1.3; $w['whatsapp'] = 1.25; $w['social'] = 1.15; $w['ads'] = 1.15; }
        if ($hit('/ظهور|جوجل|سيو|بحث|google|seo|visib|rank/u')) { $w['seo'] = 1.3; $w['content'] = 1.15; }
        if ($hit('/تقييم|سمعة|review|reputation/u')) { $w['reputation'] = 1.3; }
        if ($hit('/سوق|دول|market|countr|expan|توسع/u')) { $w['market'] = 1.3; }
        return $w;
    }

    private static function d(string $key, string $area, int $impact, array $title, array $why, array $evidence, string $url, array $action, ?string $metric, $value, ?string $direction): array
    {
        return [
            'key' => $key, 'area' => $area, 'impact' => $impact,
            'title' => ['ar' => $title[0], 'en' => $title[1]], 'why' => ['ar' => $why[0], 'en' => $why[1]],
            'evidence' => array_map(static fn ($e) => ['ar' => $e[0], 'en' => $e[1]], $evidence),
            'action_url' => $url, 'action' => ['ar' => $action[0], 'en' => $action[1]],
            'requires_spend' => false,
            'metric' => $metric === null ? null : ['key' => $metric, 'value' => $value === null ? null : (float) $value, 'direction' => $direction],
        ];
    }

    /** قيمة مقياس من الصورة (زي "reputation.unanswered_negative" أو "trip.12.recent") */
    public static function metricValue(array $s, string $key): ?float
    {
        if (preg_match('/^trip\.(\d+)\.(recent|upcoming)$/', $key, $m)) {
            foreach (($s['growth']['focus_trips'] ?? []) as $t) if ((int) $t['id'] === (int) $m[1]) return (float) $t[$m[2]];
            return null;
        }
        $v = $s;
        foreach (explode('.', $key) as $part) {
            if (!is_array($v) || !array_key_exists($part, $v)) return null;
            $v = $v[$part];
        }
        if (is_bool($v)) return $v ? 1.0 : 0.0;
        return is_numeric($v) ? (float) $v : null;
    }

    /** نتيجة القرار: improved / unchanged / worse */
    public static function outcome(?float $before, ?float $after, string $direction): ?string
    {
        if ($before === null || $after === null) return null;
        if (abs($after - $before) < 0.0001) return 'unchanged';
        $better = $direction === 'down' ? $after < $before : $after > $before;
        return $better ? 'improved' : 'worse';
    }
}

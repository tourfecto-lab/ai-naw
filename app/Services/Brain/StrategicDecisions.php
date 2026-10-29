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
     * @return array<int,array<string,mixed>>
     */
    public static function decide(array $s, array $learned = []): array
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
        if (!empty($u['profile_confirmed']) && empty($u['markets']['countries'])) {
            $add(self::d('define_markets', 'market', 2,
                ['حدد الدول اللي عايز تبيع لها', 'Choose the countries you want to sell to'],
                ['السيو والإعلانات والمحتوى بيتوجهوا حسب السوق؛ من غيره كل خدمة بتخمّن.', 'SEO, ads and content target a market; without it every service guesses.'],
                [['مفيش دول مستهدفة محددة', 'No target countries set']],
                '/ai/keywords', ['حدد الأسواق', 'Set markets'], null, null, null));
        }

        // الأولوية: الأثر × وزن الهدف × وزن التعلّم
        $goal = self::goalWeights((string) ($u['goal'] ?? ''));
        foreach ($out as &$d) {
            $w = ($goal[$d['area']] ?? 1.0) * max(0.5, min(1.5, (float) ($learned[$d['area']] ?? 1.0)));
            $d['priority'] = round($d['impact'] * 10 * $w, 2);
        }
        unset($d);
        usort($out, static fn ($a, $b) => $b['priority'] <=> $a['priority'] ?: strcmp($a['key'], $b['key']));
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

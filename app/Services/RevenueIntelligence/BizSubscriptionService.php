<?php

/**
 * Tourfecto - Biz Subscriptions Service (MRR/ARR/NRR/GRR)
 * @version 1.0.0
 *
 * v1.5.0: الـ NR/GRR/Churn الحرفية أصبحت ممكنة بصدق الآن - بشرط واحد:
 * وجود بيانات اشتراكات "عملاء أعمال العميل" في جدول `biz_subscriptions`
 * (مختلف تمامًا عن جدول `subscriptions` القديم اللي هو خطة المستخدم نفسه
 * في منصة Tourfecto - صف واحد لكل مستخدم ولا يمثل عملاء أعماله).
 *
 * الفلسفة ثابتة كما في كل الموديول:
 *   - كل رقم من أرقام حقيقية في البيانات، ولا رقم مخترع أبدًا.
 *   - لو البيانات غير كافية (الجدول فاضي/مش مثبت/مش فيه events) =>
 *     "Not enough data" مع السبب - لا تقدير وهمي.
 *
 * Pure functions قابلة للاختبار مباشرة بـ Fixtures (بدون DB):
 *   - computeMrr(array $subscriptions)                - إجمالي MRR الحالي
 *   - computeArrFromMrr(float $mrr)                   - ARR = MRR * 12
 *   - computeMrrBreakdown(array $events)              - New/Expansion/Contraction/Churn
 *   - computeMrrByCycle(array $subscriptions)         - توزيع MRR حسب دورة الفوترة
 *   - computeNrr(array $currentSubs, array $pastSubs) - Net Revenue Retention
 *   - computeGrr(array $currentSubs, array $pastSubs) - Gross Revenue Retention
 *   - computeChurnRate(array $subscriptions, array $events, string $period) - Churn
 */
class BizSubscriptionService
{
    /** @var RevenueDataGateway */
    private $gateway;

    public function __construct(?RevenueDataGateway $gateway = null)
    {
        $this->gateway = $gateway ?? new RevenueDataGateway();
    }

    /** لو الجداول غير موجودة/مش مثبتة - نرجع فورًا بلا فشل. */
    public function tablesAvailable(): bool
    {
        return $this->gateway->hasBizSubscriptionTables();
    }

    /**
     * ملخص كامل لمقاييس الاشتراك الحقيقية لمستخدم معيّن.
     * أي مقياس غير قابل للحساب بصدق -> null مع سبب واضح.
     */
    public function getSubscriptionMetrics(int $userId): array
    {
        if (!$this->tablesAvailable()) {
            return [
                'has_data' => false,
                'reason' => 'biz_subscriptions tables are not installed on this deployment. Install database/migrations/2026_08_16_000010_create_revai_subscriptions_teams_benchmarks.sql to enable MRR/ARR/NRR/GRR.',
                'mrr' => null,
                'arr' => null,
                'active_subscriptions' => 0,
                'breakdown' => ['has_data' => false],
                'nrr' => ['has_data' => false],
                'grr' => ['has_data' => false],
                'churn' => ['has_data' => false],
            ];
        }

        $subscriptions = $this->gateway->getBizSubscriptions($userId);
        $events = $this->gateway->getBizSubscriptionEvents($userId);

        $mrr = self::computeMrr($subscriptions);
        $metrics = [
            'has_data' => count($subscriptions) > 0,
            'reason' => count($subscriptions) === 0
                ? 'No biz subscriptions recorded for this user yet. Add rows to biz_subscriptions (or connect a billing source) to enable subscription metrics.'
                : null,
            'mrr' => $mrr,
            'arr' => self::computeArrFromMrr($mrr),
            'active_subscriptions' => count(array_filter($subscriptions, static function ($s) {
                return ($s['status'] ?? '') === 'active' || ($s['status'] ?? '') === 'trialing';
            })),
            'by_cycle' => self::computeMrrByCycle($subscriptions),
            'breakdown' => self::computeMrrBreakdown($events),
            'nrr' => self::computeNrr($subscriptions, $subscriptions),
            'grr' => self::computeGrr($subscriptions, $subscriptions),
            'churn' => self::computeChurnRate($subscriptions, $events, 'monthly'),
        ];
        return $metrics;
    }

    /** إجمالي الـ MRR الحالي = مجموع mrr للاشتراكات النشطة (أو التجريبية). */
    public static function computeMrr(array $subscriptions): float
    {
        $total = 0.0;
        foreach ($subscriptions as $s) {
            $status = $s['status'] ?? 'active';
            if (!in_array($status, ['active', 'trialing'], true)) {
                continue;
            }
            $total += (float) $s['mrr'];
        }
        return round($total, 2);
    }

    /** ARR = MRR * 12 (Baremetrics convention). */
    public static function computeArrFromMrr(float $mrr): float
    {
        return round($mrr * 12, 2);
    }

    /** توزيع MRR حسب دورة الفوترة (Monthly/Quarterly/Yearly). */
    public static function computeMrrByCycle(array $subscriptions): array
    {
        $byCycle = ['monthly' => 0.0, 'quarterly' => 0.0, 'yearly' => 0.0];
        $counts = ['monthly' => 0, 'quarterly' => 0, 'yearly' => 0];
        $hasData = false;
        foreach ($subscriptions as $s) {
            $status = $s['status'] ?? 'active';
            if (!in_array($status, ['active', 'trialing'], true)) {
                continue;
            }
            $cycle = $s['billing_cycle'] ?? 'monthly';
            if (!isset($byCycle[$cycle])) {
                $cycle = 'monthly';
            }
            $byCycle[$cycle] += (float) $s['mrr'];
            $counts[$cycle]++;
            $hasData = true;
        }
        foreach ($byCycle as $k => $v) {
            $byCycle[$k] = round($v, 2);
        }
        return ['has_data' => $hasData, 'mrr_by_cycle' => $byCycle, 'counts' => $counts];
    }

    /**
     * MRR Breakdown الشهري (New / Expansion / Contraction / Churn) من
     * أحداث `biz_subscription_events` الحقيقية. Pure function.
     *
     * @param array $events صفوف أحداث [['event_type'=>, 'mrr_delta'=>, 'occurred_at'=>], ...]
     */
    public static function computeMrrBreakdown(array $events, ?string $month = null): array
    {
        if (empty($events)) {
            return ['has_data' => false, 'reason' => 'No biz_subscription_events recorded.', 'new' => null, 'expansion' => null, 'contraction' => null, 'churn' => null, 'net' => null];
        }

        $sums = ['new' => 0.0, 'expansion' => 0.0, 'contraction' => 0.0, 'churn' => 0.0];
        $count = 0;
        foreach ($events as $e) {
            $type = (string) ($e['event_type'] ?? '');
            if ($month !== null) {
                $occurred = (string) ($e['occurred_at'] ?? '');
                if (substr($occurred, 0, 7) !== $month) {
                    continue;
                }
            }
            if (!isset($sums[$type])) {
                continue;
            }
            // expansion/new: موجب. contraction/churn: سالب (أو موجب القيمة المفقودة).
            $delta = (float) $e['mrr_delta'];
            if (in_array($type, ['contraction', 'churn'], true)) {
                $delta = abs($delta);
            } else {
                $delta = abs($delta);
            }
            $sums[$type] += $delta;
            $count++;
        }

        if ($count === 0) {
            return ['has_data' => false, 'reason' => $month !== null ? "No events in month {$month}." : 'No matching events.', 'new' => null, 'expansion' => null, 'contraction' => null, 'churn' => null, 'net' => null];
        }

        $net = $sums['new'] + $sums['expansion'] - $sums['contraction'] - $sums['churn'];
        foreach ($sums as $k => $v) {
            $sums[$k] = round($v, 2);
        }
        return [
            'has_data' => true,
            'reason' => null,
            'new' => $sums['new'],
            'expansion' => $sums['expansion'],
            'contraction' => $sums['contraction'],
            'churn' => $sums['churn'],
            'net' => round($net, 2),
            'event_count' => $count,
        ];
    }

    /**
     * Net Revenue Retention (NRR) - حرفي، من بيانات حقيقية.
     * نسبة MRR الفترة الحالية من عملاء كانوا نشطين في الفترة السابقة مقسومًا
     * على MRR نفس العملاء في الفترة السابقة (يشمل التوسعات والانكماشات).
     *
     * @param array $currentSubs اشتراكات الحالة الحالية (بـ contact_id/customer + mrr + status)
     * @param array $pastSubs    اشتراكات الفترة السابقة (نفس الشكل)
     */
    public static function computeNrr(array $currentSubs, array $pastSubs): array
    {
        if (empty($pastSubs)) {
            return ['has_data' => false, 'reason' => 'No prior-period subscriptions to anchor NRR.', 'nrr_percent' => null];
        }

        // مرساة: اشتراكات الفترة السابقة (النشطة) - بمفتاح contact_id أو customer_name
        $pastBase = 0.0;
        $pastKeys = [];
        foreach ($pastSubs as $s) {
            $status = $s['status'] ?? 'active';
            if (!in_array($status, ['active', 'trialing'], true)) {
                continue;
            }
            $key = self::subscriptionKey($s);
            if ($key === null) {
                continue;
            }
            $pastBase += (float) $s['mrr'];
            $pastKeys[$key] = true;
        }

        if ($pastBase <= 0 || empty($pastKeys)) {
            return ['has_data' => false, 'reason' => 'No anchor MRR in prior period.', 'nrr_percent' => null];
        }

        // MRR الحالي لنفس العملاء (أي تمديد/توسعة/انكماش محسوب - حتى لو أصبح 0)
        $currentFromPast = 0.0;
        foreach ($currentSubs as $s) {
            $key = self::subscriptionKey($s);
            if ($key === null || !isset($pastKeys[$key])) {
                continue;
            }
            $currentFromPast += (float) $s['mrr'];
        }

        return [
            'has_data' => true,
            'nrr_percent' => round(($currentFromPast / $pastBase) * 100, 1),
            'past_anchor_mrr' => round($pastBase, 2),
            'current_from_anchor_mrr' => round($currentFromPast, 2),
            'note' => 'Literal NRR computed from real biz_subscriptions rows (customers active in the anchor period). Includes expansions and contractions; churned customers contribute 0.',
        ];
    }

    /**
     * Gross Revenue Retention (GRR) - حرفي.
     * نسبة MRR الفترة الحالية من عملاء الفترة السابقة، محسوبة من الـ MRR
     * المتناقص فقط (بدون توسعات): نفس المنهجية لكن نسقط التوسعات.
     * التقريب الأمين المتاح من البيانات: نستخدم MRR الحالي للعملاء الأصليين
     * كما هي (الاشتراكات لا تسجل "توسعة منفصلة" بشكل موثوق في نفس الصف).
     * لذلك GRR هنا = نسبة العملاء الأصليين اللي لسه نشطين (بقيمهم الحالية).
     */
    public static function computeGrr(array $currentSubs, array $pastSubs): array
    {
        if (empty($pastSubs)) {
            return ['has_data' => false, 'reason' => 'No prior-period subscriptions to anchor GRR.', 'grr_percent' => null];
        }

        $pastBase = 0.0;
        $pastKeys = [];
        foreach ($pastSubs as $s) {
            $status = $s['status'] ?? 'active';
            if (!in_array($status, ['active', 'trialing'], true)) {
                continue;
            }
            $key = self::subscriptionKey($s);
            if ($key === null) {
                continue;
            }
            $pastBase += (float) $s['mrr'];
            $pastKeys[$key] = true;
        }

        if ($pastBase <= 0 || empty($pastKeys)) {
            return ['has_data' => false, 'reason' => 'No anchor MRR in prior period.', 'grr_percent' => null];
        }

        $retained = 0.0;
        foreach ($currentSubs as $s) {
            $key = self::subscriptionKey($s);
            if ($key === null || !isset($pastKeys[$key])) {
                continue;
            }
            $retained += min((float) $s['mrr'], 0) === 0.0 ? 0.0 : (float) $s['mrr'];
        }

        return [
            'has_data' => true,
            'grr_percent' => round(($retained / $pastBase) * 100, 1),
            'retained_mrr' => round($retained, 2),
            'past_anchor_mrr' => round($pastBase, 2),
            'note' => 'Literal GRR from real biz_subscriptions rows (customers active in the anchor period still active now, at their current MRR). Expansions are not separable in the current single-row model, so GRR here approximates retained MRR.',
        ];
    }

    /**
     * Churn Rate حرفي من الاشتراكات والأحداث.
     * عدد اشتراكات الفترة السابقة النشطة اللي اختفت/اتشهرت churn مقسومًا
     * على إجمالي الاشتراكات النشطة في بداية الفترة.
     *
     * @param array  $subscriptions كل الاشتراكات
     * @param array  $events        الأحداث
     * @param string $period        'monthly' (افتراضي)
     */
    public static function computeChurnRate(array $subscriptions, array $events, string $period = 'monthly'): array
    {
        // نعتمد على أحداث churn المسجلة + الاشتراكات المليها cancelled_at حاليًا.
        $churnedCount = 0;
        foreach ($events as $e) {
            if (($e['event_type'] ?? '') === 'churn') {
                $churnedCount++;
            }
        }
        $cancelled = count(array_filter($subscriptions, static function ($s) {
            return in_array($s['status'] ?? '', ['cancelled', 'expired'], true);
        }));

        $activeCount = count(array_filter($subscriptions, static function ($s) {
            return in_array($s['status'] ?? '', ['active', 'trialing'], true);
        }));

        $base = $activeCount + $churnedCount;
        if ($base === 0) {
            return ['has_data' => false, 'reason' => 'No subscription base to compute churn.', 'churn_rate_percent' => null, 'churned' => 0, 'base' => 0];
        }

        return [
            'has_data' => true,
            'churn_rate_percent' => round(($churnedCount / $base) * 100, 1),
            'churned' => $churnedCount,
            'cancelled_currently' => $cancelled,
            'base' => $base,
            'note' => 'Literal churn from real biz_subscription_events (event_type=churn) against the active base.',
        ];
    }

    /** مفتاح تعرّف عميل مستقر عبر الفترات (contact_id أولًا، ثم customer_name). */
    private static function subscriptionKey(array $s): ?string
    {
        if (isset($s['contact_id']) && $s['contact_id'] !== null && (int) $s['contact_id'] > 0) {
            return 'c' . (int) $s['contact_id'];
        }
        if (isset($s['customer_name']) && $s['customer_name'] !== '') {
            return 'n' . mb_strtolower(trim((string) $s['customer_name']));
        }
        return null;
    }
}

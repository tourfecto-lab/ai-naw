<?php

/**
 * Tourfecto - Revenue Retention Service
 * @version 1.0.0
 *
 * NRR/GRR/Churn-style analytics المبنية على بيانات حقيقية متاحة (Baremetrics/
 * RevenueCat - style)، بدون اختراع أي رقم:
 *
 *   - لا توجد في المشروع بيانات "اشتراكات لعملاء أعمالك" (جدول subscriptions
 *     هو اشتراك المستخدم نفسه في منصة Tourfecto - صف واحد لكل مستخدم، لا
 *     يمثل عملاء أعماله). لذلك NRR/GRR الحرفية (المتطلبة تتبع تغيير قيمة
 *     كل اشتراك) غير قابلة للحساب الصادق من البيانات الحالية، ويُعاد
 *     "Not enough data" مع السبب - لا رقم مخترع.
 *
 *   - لكن الـRetention القابل للحساب بصدق موجود في سجل الشراء الحقيقي:
 *     صفقات CRM المكسوبة لكل عميل (crm_deals.status='won' + contact_id).
 *     منه نحسب:
 *       1) Cohort Retention (Retention Rate by First-Purchase Month) -
 *          كم عميل من كل Cohort رجع اشترى تاني في الشهور اللي بعدها.
 *       2) Repeat Purchase Rate - نسبة العملاء اللي اشتروا أكتر من مرة.
 *       3) Revenue Retention Rate - نصيب إيراد الفترة الحالية الجاي من
 *          عملاء كانوا مصدر إيراد في الفترة السابقة (GRR-approximation
 *          حقيقية مبينة على البيانات، مع إفصاح أنها approximation).
 *       4) Recurring Stability - استقرار الإيراد المتكرر المسجّل
 *          (source='subscription' في rev_revenue_records) شهر بشهر.
 */
class RevenueRetentionService
{
    /** @var RevenueDataGateway */
    private $gateway;

    public function __construct(?RevenueDataGateway $gateway = null)
    {
        $this->gateway = $gateway ?? new RevenueDataGateway();
    }

    /** كل تحليلات الاحتفاظ المتاحة بصدق لمستخدم معيّن. */
    public function getRetentionAnalytics(int $userId, ?string $nowStr = null): array
    {
        $wonDeals = $this->gateway->getWonDealsByContact($userId);
        $now = $nowStr ?? 'now';

        $cohorts = self::computeCohortRetention($wonDeals, $now);
        $repeatRate = self::computeRepeatPurchaseRate($wonDeals);
        $recurring = $this->gateway->getMonthlyRevenueSeries($userId, 6, 'subscription');
        $recurringStability = self::computeRecurringStability($recurring, $now);

        $hasData = count($wonDeals) > 0;
        return [
            'has_data' => $hasData,
            'cohort_retention' => $cohorts,
            'repeat_purchase_rate' => $repeatRate,
            'recurring_stability' => $recurringStability,
            'mrr_grr_note' => 'Not enough data for literal NRR/GRR. The subscriptions table tracks the user\'s own Tourfecto plan (one row per user), not the user\'s customers. No per-customer subscription/expansion tracking exists in the current schema, so literal NRR/GRR would require inventing data. Revenue Retention Rate below is the honest GRR-style approximation available from real CRM purchase history.',
        ];
    }

    /**
     * Retention Rate لكل Cohort من عملاء حسب شهر أول شراء (Pure function).
     * لكل Cohort نحسب: عدد العملاء، وعددهم اللي رجعوا اشتروا في كل شهر
     * لاحق (months_since_first_purchase). مبني على بيانات حقيقية فقط.
     *
     * @param array  $wonDeals صفقات مكسوبة [['contact_id'=>, 'closed_at'=>, 'value'=>], ...]
     * @param string $nowStr   Y-m-d (للاختبار)
     */
    public static function computeCohortRetention(array $wonDeals, string $nowStr = 'now'): array
    {
        if (empty($wonDeals)) {
            return ['has_data' => false, 'cohorts' => []];
        }

        // كل عميل: شهر أول شراء + كل شهور شرائه (فريدة)
        $byContact = [];
        foreach ($wonDeals as $deal) {
            $cid = (int) $deal['contact_id'];
            $closedAt = (string) ($deal['closed_at'] ?? '');
            $month = $closedAt !== '' ? substr($closedAt, 0, 7) : null;
            if ($month === null) {
                continue;
            }
            if (!isset($byContact[$cid])) {
                $byContact[$cid] = ['first_month' => $month, 'months' => []];
            }
            $byContact[$cid]['months'][] = $month;
        }

        // نمرّ على الشهور زمنيًا ونبني الجدول
        $cohortMonths = array_unique(array_column($byContact, 'first_month'));
        sort($cohortMonths);
        if (empty($cohortMonths)) {
            return ['has_data' => false, 'cohorts' => []];
        }

        $today = new DateTime($nowStr);
        $maxMonths = 6; // أفق معقول: 6 أشهر من أول شراء

        $cohorts = [];
        foreach ($cohortMonths as $cm) {
            $members = array_filter($byContact, static function ($c) use ($cm) {
                return $c['first_month'] === $cm;
            });
            $count = count($members);
            if ($count === 0) {
                continue;
            }

            $row = ['cohort_month' => $cm, 'customers' => $count, 'retention_rates' => []];
            for ($m = 1; $m <= $maxMonths; $m++) {
                $targetMonth = (new DateTime($cm . '-01'))->modify("+{$m} months")->format('Y-m');
                if ($targetMonth > $today->format('Y-m')) {
                    break; // شهر لسه مجاش - لا نحسبله
                }
                $returned = 0;
                foreach ($members as $c) {
                    if (in_array($targetMonth, $c['months'], true)) {
                        $returned++;
                    }
                }
                $row['retention_rates'][$m] = round(($returned / $count) * 100, 1);
            }
            $cohorts[] = $row;
        }

        usort($cohorts, static function ($a, $b) {
            return strcmp($a['cohort_month'], $b['cohort_month']);
        });
        return ['has_data' => true, 'cohorts' => $cohorts];
    }

    /** نسبة العملاء اللي اشتروا أكتر من مرة (من إجمالي عملاء له صفقات مكسوبة). */
    public static function computeRepeatPurchaseRate(array $wonDeals): array
    {
        $customers = [];
        foreach ($wonDeals as $deal) {
            $cid = (int) $deal['contact_id'];
            $customers[$cid] = ($customers[$cid] ?? 0) + 1;
        }
        $total = count($customers);
        if ($total === 0) {
            return ['has_data' => false, 'repeat_purchase_rate_percent' => null, 'repeat_customers' => 0, 'total_customers' => 0];
        }
        $repeat = count(array_filter($customers, static function ($n) {
            return $n >= 2;
        }));
        return [
            'has_data' => true,
            'repeat_purchase_rate_percent' => round(($repeat / $total) * 100, 1),
            'repeat_customers' => $repeat,
            'total_customers' => $total,
        ];
    }

    /**
     * استقرار الإيراد المتكرر شهر بشهر (source='subscription').
     * يكتشف "فجوات" (شهر بلا إيراد متكرر بين شهرين فيهما) كإشارة تشوّر
     * صادقة - وليس حكمًا مفبركًا.
     */
    public static function computeRecurringStability(array $monthlySeries, string $nowStr = 'now'): array
    {
        if (empty($monthlySeries)) {
            return ['has_data' => false, 'message' => 'Not enough data. No recurring (source=subscription) revenue records found.', 'months' => []];
        }

        $presentMonths = [];
        foreach ($monthlySeries as $row) {
            $m = (string) $row['month'];
            $presentMonths[$m] = (float) $row['total'];
        }
        ksort($presentMonths);

        $months = array_keys($presentMonths);
        $gaps = 0;
        $expected = new DateTime($months[0] . '-01');
        $expected->modify('+1 day');
        for ($i = 0; $i < count($months); $i++) {
            $cur = new DateTime($months[$i] . '-01');
            if ($i > 0) {
                $prev = new DateTime($months[$i - 1] . '-01');
                $gapMonths = (int) $prev->diff($cur)->format('%m') + 12 * (int) $prev->diff($cur)->format('%y');
                if ($gapMonths > 1) {
                    $gaps++;
                }
            }
        }

        $values = array_values($presentMonths);
        $count = count($values);
        $avg = $count > 0 ? array_sum($values) / $count : 0.0;
        $variance = 0.0;
        foreach ($values as $v) {
            $variance += ($v - $avg) ** 2;
        }
        $std = $count > 1 ? sqrt($variance / ($count - 1)) : 0.0;
        $cv = $avg > 0 ? round(($std / $avg) * 100, 1) : null; // معامل الاختلاف

        return [
            'has_data' => true,
            'months' => array_map(static function ($m, $t) {
                return ['month' => $m, 'total' => round($t, 2)];
            }, $months, $values),
            'recurring_months' => $count,
            'monthly_gaps_detected' => $gaps,
            'average_monthly_recurring' => round($avg, 2),
            'coefficient_of_variation_percent' => $cv,
            'note' => $gaps > 0
                ? 'Recurring revenue has month(s) with no recorded subscription-source revenue - possible churn gap, verify against the actual subscription records.'
                : 'Recurring revenue is present every month in the window.',
        ];
    }

    /**
     * Revenue Retention Rate (GRR-style approximation): نصيب إيراد الفترة
     * الحالية الجاي من عملاء كانوا مصدر إيراد في الفترة السابقة. Pure
     * function - مبني على صفقات CRM المكسوبة الحقيقية.
     *
     * @param array $currentDeals  صفقات الفترة الحالية (بـ contact_id + value)
     * @param array $previousDeals صفقات الفترة السابقة (بـ contact_id)
     */
    public static function computeRevenueRetentionRate(array $currentDeals, array $previousDeals): array
    {
        if (empty($currentDeals) || empty($previousDeals)) {
            return ['has_data' => false, 'revenue_retention_rate_percent' => null, 'reason' => 'Not enough data for period-over-period comparison.'];
        }

        $previousContactIds = [];
        foreach ($previousDeals as $d) {
            $previousContactIds[(int) $d['contact_id']] = true;
        }

        $currentTotal = 0.0;
        $retainedTotal = 0.0;
        foreach ($currentDeals as $d) {
            $currentTotal += (float) $d['value'];
            if (isset($previousContactIds[(int) $d['contact_id']])) {
                $retainedTotal += (float) $d['value'];
            }
        }

        if ($currentTotal <= 0) {
            return ['has_data' => false, 'revenue_retention_rate_percent' => null, 'reason' => 'Current period has no revenue.'];
        }

        return [
            'has_data' => true,
            'revenue_retention_rate_percent' => round(($retainedTotal / $currentTotal) * 100, 1),
            'retained_revenue' => round($retainedTotal, 2),
            'current_period_revenue' => round($currentTotal, 2),
            'note' => 'GRR-style approximation from real CRM won-deal history (customers who bought in both periods). Not a literal subscription NRR/GRR, which requires per-customer subscription tracking not present in the schema.',
        ];
    }
}

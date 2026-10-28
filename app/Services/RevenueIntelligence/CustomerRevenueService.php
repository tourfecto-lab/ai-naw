<?php

/**
 * Tourfecto - Customer Revenue Intelligence Service
 * @version 1.0.0
 *
 * يغطي:
 *  - Section 5: CUSTOMER REVENUE INTELLIGENCE
 *  - Section 12: REVENUE SEGMENTATION
 *
 * مهم: rev_revenue_records (BATCH6) غير مرتبط بعميل (لا يوجد contact_id
 * فيه)، فلا يمكن حساب "إيراد فعلي لكل عميل" منه بدون اختراع ربط غير
 * موجود. المصدر الحقيقي الوحيد لإيراد مرتبط بعميل معروف هو صفقات CRM
 * المكسوبة (crm_deals.status='won' مع contact_id) - نستخدمها هنا كمصدر
 * "Customer Revenue" الفعلي، ونفصح عن ذلك بوضوح بدل الادعاء بشمولية
 * كاملة. لا نعيد بناء CRM - نقرأ منه فقط عبر RevenueDataGateway.
 */
class CustomerRevenueService
{
    /** @var RevenueDataGateway */
    private $gateway;

    public function __construct(?RevenueDataGateway $gateway = null)
    {
        $this->gateway = $gateway ?? new RevenueDataGateway();
    }

    /** قائمة عملاء مع مؤشرات الإيراد والتقسيم لكل واحد منهم. */
    public function getCustomerRevenueIntelligence(int $userId): array
    {
        // v2.0 (G9): الأولوية للإيراد المدفوع فعلًا والمربوط بالعميل (حجوزات/OTA)،
        // والصفقات المكسوبة fallback فقط - من غير جمع الاتنين (منع العد المزدوج).
        $paidRows = method_exists($this->gateway, 'getCustomerRevenueRows') ? $this->gateway->getCustomerRevenueRows($userId) : [];
        if (!empty($paidRows)) {
            $byContact = self::groupPaidRevenueByContact($paidRows);
            $byContact = array_filter($byContact, static function ($c) {
                return $c['total'] > 0;
            });
            if (!empty($byContact)) {
                $totals = array_map(static function ($d) {
                    return $d['total'];
                }, $byContact);
                $customers = self::buildCustomerRecords($byContact, $totals);
                usort($customers, static function ($a, $b) {
                    return $b['customer_revenue'] <=> $a['customer_revenue'];
                });
                return ['has_data' => true, 'customers' => $customers, 'data_source' => 'rev_revenue_records (paid bookings linked to CRM contacts)'];
            }
        }

        $wonDeals = $this->gateway->getWonDealsByContact($userId);
        if (empty($wonDeals)) {
            return [
                'has_data' => false,
                'message' => 'Not enough data. No won deals linked to a customer contact yet.',
                'customers' => [],
                'data_source' => 'crm_deals (status=won) - the only revenue data currently linked to a specific customer contact.',
            ];
        }

        $byContact = self::groupDealsByContact($wonDeals);
        $totals = array_map(static function ($d) {
            return $d['total'];
        }, $byContact);
        $customers = self::buildCustomerRecords($byContact, $totals);

        usort($customers, static function ($a, $b) {
            return $b['customer_revenue'] <=> $a['customer_revenue'];
        });

        return ['has_data' => true, 'customers' => $customers, 'data_source' => 'crm_deals (status=won)'];
    }

    /**
     * v2.0: تجميع سجلات الإيراد المدفوع حسب العميل. السجلات الموجبة = عمليات
     * شراء (تدخل في التكرار/الاتجاه)، والسالبة (استرداد/إلغاء) بتتخصم من
     * الإجمالي بس من غير ما تتحسب "شراء". Pure.
     */
    public static function groupPaidRevenueByContact(array $rows): array
    {
        $positive = array_values(array_filter($rows, static function ($r) {
            return (float) $r['value'] > 0;
        }));
        $out = self::groupDealsByContact($positive);
        foreach ($rows as $r) {
            if ((float) $r['value'] < 0 && isset($out[(int) $r['contact_id']])) {
                $out[(int) $r['contact_id']]['total'] += (float) $r['value'];
            }
        }
        return $out;
    }

    /** تجميع الصفقات المكسوبة حسب contact_id. */
    public static function groupDealsByContact(array $wonDeals): array
    {
        $out = [];
        foreach ($wonDeals as $deal) {
            $cid = (int) $deal['contact_id'];
            if (!isset($out[$cid])) {
                $out[$cid] = ['contact_id' => $cid, 'contact_name' => $deal['contact_name'], 'contact_email' => $deal['contact_email'], 'deals' => [], 'total' => 0.0];
            }
            $out[$cid]['deals'][] = $deal;
            $out[$cid]['total'] += (float) $deal['value'];
        }
        return $out;
    }

    /**
     * بناء سجلات العملاء الكاملة (Pure function قابلة للاختبار مباشرة)
     * @param array $byContact ناتج groupDealsByContact()
     * @param array $totals قيم totals لكل عميل (لحساب الـ percentile)
     * @param string|null $nowStr للاختبار - تاريخ "الآن" الثابت
     */
    public static function buildCustomerRecords(array $byContact, array $totals, ?string $nowStr = null): array
    {
        $now = new DateTime($nowStr ?? 'now');
        sort($totals);
        $countTotals = count($totals);

        $customers = [];
        foreach ($byContact as $cid => $data) {
            $deals = $data['deals'];
            usort($deals, static function ($a, $b) {
                return strcmp($a['closed_at'] ?? '', $b['closed_at'] ?? '');
            });

            $revenue = round($data['total'], 2);
            $frequency = count($deals);
            $aov = $frequency > 0 ? round($revenue / $frequency, 2) : 0.0;
            $lastPurchase = end($deals)['closed_at'] ?? null;
            $daysSinceLast = $lastPurchase ? (int) $now->diff(new DateTime($lastPurchase))->format('%a') : null;

            // اتجاه الإيراد: مقارنة آخر نصف الصفقات زمنيًا بأول نصفها (تبسيط معقول لعدم وجود سلسلة زمنية يومية للعميل)
            $trend = 'stable';
            if ($frequency >= 2) {
                $mid = (int) floor($frequency / 2);
                $firstHalf = array_slice($deals, 0, max(1, $mid));
                $secondHalf = array_slice($deals, max(1, $mid));
                $firstSum = array_sum(array_column($firstHalf, 'value'));
                $secondSum = array_sum(array_column($secondHalf, 'value'));
                if ($firstSum > 0) {
                    if ($secondSum > $firstSum * 1.15) {
                        $trend = 'growing';
                    } elseif ($secondSum < $firstSum * 0.85) {
                        $trend = 'declining';
                    }
                }
            }

            $percentile = $countTotals > 0 ? self::percentileRank($totals, $revenue) : 0.0;

            $segment = self::determineSegment($daysSinceLast, $percentile, $trend, $frequency);

            $customers[] = [
                'contact_id' => $cid,
                'name' => $data['contact_name'],
                'email' => $data['contact_email'],
                'customer_revenue' => $revenue,
                'customer_lifetime_value' => $revenue, // = إجمالي ما دفعه حتى الآن (لا يوجد نموذج تنبؤي لقيمة مستقبلية بعد)
                'average_order_value' => $aov,
                'purchase_frequency' => $frequency,
                'last_purchase' => $lastPurchase,
                'days_since_last_purchase' => $daysSinceLast,
                'revenue_trend' => $trend,
                'value_segment' => $segment,
            ];
        }

        return $customers;
    }

    private static function percentileRank(array $sortedTotals, float $value): float
    {
        $count = count($sortedTotals);
        if ($count === 0) {
            return 0.0;
        }
        $below = 0;
        foreach ($sortedTotals as $t) {
            if ($t <= $value) {
                $below++;
            }
        }
        return round(($below / $count) * 100, 1);
    }

    /** VIP / High Value / Growing / Declining / Inactive - بترتيب أولوية واضح ومفسَّر. */
    public static function determineSegment(?int $daysSinceLast, float $revenuePercentile, string $trend, int $frequency): string
    {
        if ($daysSinceLast !== null && $daysSinceLast > 180) {
            return 'Inactive';
        }
        if ($revenuePercentile >= 90) {
            return 'VIP';
        }
        if ($revenuePercentile >= 65) {
            return 'High Value';
        }
        if ($trend === 'growing') {
            return 'Growing';
        }
        if ($trend === 'declining') {
            return 'Declining';
        }
        return $frequency > 1 ? 'Returning' : 'New';
    }

    /**
     * Section 12: تجميع العملاء في Segments قابلة للفلترة، بالإضافة إلى
     * New/Returning المبنية على عدد الصفقات المكسوبة.
     */
    public function getSegments(int $userId): array
    {
        $intelligence = $this->getCustomerRevenueIntelligence($userId);
        if (!$intelligence['has_data']) {
            return $intelligence;
        }

        $segments = [];
        foreach ($intelligence['customers'] as $customer) {
            $segments[$customer['value_segment']][] = $customer;
        }

        $summary = [];
        foreach ($segments as $name => $customers) {
            $summary[] = [
                'segment' => $name,
                'customer_count' => count($customers),
                'total_revenue' => round(array_sum(array_column($customers, 'customer_revenue')), 2),
            ];
        }
        usort($summary, static function ($a, $b) {
            return $b['total_revenue'] <=> $a['total_revenue'];
        });

        return ['has_data' => true, 'summary' => $summary, 'segments' => $segments];
    }
}

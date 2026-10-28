<?php

/**
 * Tourfecto - Revenue Collections (Booked vs Collected vs Outstanding)
 * @version 2.0.0
 *
 * الفرق بين "إيراد محجوز" و"فلوس اتحصّلت فعلًا" - أهم سؤال مالي لشركة
 * سياحة (العربون/الباقي/الدفع عند الوصول). BookingEngine بيسجل قيمة الحجز
 * كاملة في rev_revenue_records لحظة التأكيد، بينما المدفوع الفعلي موجود في
 * bookings.amount_paid + payment_transactions. الخدمة دي بتجمع الاتنين:
 *
 *   - booked       : قيمة الحجوزات المؤكدة/المكتملة
 *   - collected    : المحصّل فعلًا على نفس الحجوزات
 *   - outstanding  : المستحق (booked - collected)
 *   - collection_rate، وتقادم المستحقات (Aging) حسب تاريخ الرحلة:
 *       overdue      : الرحلة بدأت/خلصت والمبلغ لسه مش متحصّل
 *       due_7_days   : الرحلة خلال 7 أيام
 *       due_30_days  : خلال 30 يوم
 *       later        : بعد كده
 *   - cash_in_series : المحصّل يوميًا من payment_transactions
 *
 * كل المبالغ محوّلة لعملة التقارير عبر FxRateService (لو عملة ناقصة سعرها
 * -> المبالغ دي بتتشال من الإجمالي ويتذكر ده صراحة في unconverted).
 */
class RevenueCollectionsService
{
    /** @var RevenueDataGateway */
    private $gateway;

    public function __construct(?RevenueDataGateway $gateway = null)
    {
        $this->gateway = $gateway ?? new RevenueDataGateway();
    }

    public function getCollections(int $userId, string $periodType = 'monthly'): array
    {
        $days = RevenueOverviewService::periodToDays($periodType);
        $now = new DateTimeImmutable('now');
        $from = $now->modify("-{$days} days")->format('Y-m-d H:i:s');
        $to = $now->modify('+1 second')->format('Y-m-d H:i:s');

        $base = RevenueSettingsService::baseCurrency($userId);
        $rates = FxRateService::latestRates($userId);

        $result = self::computeReceivables($this->gateway->getOpenReceivables($userId), $now->format('Y-m-d'), $base, $rates);
        $result['period'] = self::computePeriodCollection(
            $this->gateway->getBookings($userId, $from, $to, 'created_at'),
            $this->gateway->getCollectedPayments($userId, $from, $to),
            $base,
            $rates
        );
        $result['period']['period_type'] = $periodType;
        return $result;
    }

    /**
     * المستحقات المفتوحة + التقادم + أكبر المتأخرين. Pure.
     *
     * @param array $bookings صفوف getOpenReceivables
     */
    public static function computeReceivables(array $bookings, string $today, string $base, array $rates): array
    {
        $aging = ['overdue' => 0.0, 'due_7_days' => 0.0, 'due_30_days' => 0.0, 'later' => 0.0];
        $counts = ['overdue' => 0, 'due_7_days' => 0, 'due_30_days' => 0, 'later' => 0];
        $unconverted = [];
        $overdueList = [];
        $total = 0.0;
        $t = new DateTimeImmutable($today);

        foreach ($bookings as $b) {
            $due = (float) $b['total_amount'] - (float) ($b['amount_paid'] ?? 0);
            if ($due <= 0.009) {
                continue;
            }
            $cur = strtoupper((string) ($b['currency'] ?: 'USD'));
            $conv = FxRateService::convert($due, $cur, $base, $rates);
            if ($conv === null) {
                $unconverted[$cur] = round(($unconverted[$cur] ?? 0) + $due, 2);
                continue;
            }
            $start = !empty($b['start_date']) ? new DateTimeImmutable(substr((string) $b['start_date'], 0, 10)) : $t;
            $daysTo = (int) $t->diff($start)->format('%r%a');
            if ($daysTo < 0 || $b['status'] === 'completed') {
                $bucket = 'overdue';
            } elseif ($daysTo <= 7) {
                $bucket = 'due_7_days';
            } elseif ($daysTo <= 30) {
                $bucket = 'due_30_days';
            } else {
                $bucket = 'later';
            }
            $aging[$bucket] += $conv;
            $counts[$bucket]++;
            $total += $conv;
            if ($bucket === 'overdue' || $bucket === 'due_7_days') {
                $overdueList[] = [
                    'booking_id' => (int) $b['id'],
                    'booking_reference' => (string) $b['booking_reference'],
                    'customer_name' => (string) ($b['customer_name'] ?? ''),
                    'customer_phone' => (string) ($b['customer_phone'] ?? ''),
                    'product_name' => (string) ($b['product_name'] ?? ''),
                    'start_date' => substr((string) $b['start_date'], 0, 10),
                    'days_to_trip' => $daysTo,
                    'outstanding' => round($conv, 2),
                    'bucket' => $bucket,
                ];
            }
        }
        usort($overdueList, static function ($a, $b) {
            return [$a['bucket'] !== 'overdue', -$a['outstanding']] <=> [$b['bucket'] !== 'overdue', -$b['outstanding']];
        });
        foreach ($aging as $k => $v) {
            $aging[$k] = round($v, 2);
        }

        return [
            'has_data' => $total > 0 || !empty($unconverted),
            'currency' => $base,
            'outstanding_total' => round($total, 2),
            'aging' => $aging,
            'aging_counts' => $counts,
            'at_risk' => array_slice($overdueList, 0, 25),
            'unconverted' => $unconverted,
        ];
    }

    /**
     * أداء التحصيل لحجوزات الفترة + التدفق النقدي اليومي. Pure.
     */
    public static function computePeriodCollection(array $bookings, array $cashRows, string $base, array $rates): array
    {
        $booked = 0.0;
        $collected = 0.0;
        $count = 0;
        $fullyPaid = 0;
        $unconverted = [];
        foreach ($bookings as $b) {
            if (!in_array($b['status'] ?? '', ['confirmed', 'completed'], true)) {
                continue;
            }
            $cur = strtoupper((string) ($b['currency'] ?: 'USD'));
            $tot = FxRateService::convert((float) $b['total_amount'], $cur, $base, $rates);
            $paid = FxRateService::convert(min((float) ($b['amount_paid'] ?? 0), (float) $b['total_amount']), $cur, $base, $rates);
            if ($tot === null || $paid === null) {
                $unconverted[$cur] = true;
                continue;
            }
            $booked += $tot;
            $collected += $paid;
            $count++;
            if ($paid >= $tot - 0.009) {
                $fullyPaid++;
            }
        }

        $series = [];
        $cashIn = 0.0;
        foreach ($cashRows as $r) {
            $v = FxRateService::convert((float) $r['total'], (string) $r['currency'], $base, $rates);
            if ($v === null) {
                $unconverted[strtoupper((string) $r['currency'])] = true;
                continue;
            }
            $series[$r['d']] = ($series[$r['d']] ?? 0) + $v;
            $cashIn += $v;
        }
        ksort($series);
        $seriesOut = [];
        foreach ($series as $d => $v) {
            $seriesOut[] = ['date' => $d, 'amount' => round($v, 2)];
        }

        return [
            'bookings_count' => $count,
            'booked' => round($booked, 2),
            'collected' => round($collected, 2),
            'outstanding' => round(max(0.0, $booked - $collected), 2),
            'collection_rate' => $booked > 0 ? round($collected / $booked * 100, 1) : null,
            'fully_paid_share' => $count > 0 ? round($fullyPaid / $count * 100, 1) : null,
            'cash_in_total' => round($cashIn, 2),
            'cash_in_series' => $seriesOut,
            'unconverted_currencies' => array_keys($unconverted),
        ];
    }

    /** يحوّل المستحقات المتأخرة لـ Insight مخاطرة بنفس شكل RevenueInsightService. Pure. */
    public static function toRiskInsights(array $receivables): array
    {
        $overdue = (float) ($receivables['aging']['overdue'] ?? 0);
        $soon = (float) ($receivables['aging']['due_7_days'] ?? 0);
        $out = [];
        if ($overdue > 0) {
            $n = (int) ($receivables['aging_counts']['overdue'] ?? 0);
            $out[] = [
                'type' => 'risk',
                'category' => 'overdue_receivables',
                'title' => 'Overdue customer balances',
                'finding' => "{$n} booking(s) have trips that already started or finished with " . round($overdue, 2) . ' ' . $receivables['currency'] . ' still unpaid.',
                'evidence' => ['overdue' => $overdue, 'count' => $n, 'top' => array_slice($receivables['at_risk'] ?? [], 0, 5)],
                'reasoning_summary' => 'Computed from bookings.total_amount minus bookings.amount_paid for confirmed/completed bookings whose start date has passed.',
                'confidence' => 'high',
                'severity' => $overdue >= 1000 || $n >= 5 ? 'high' : 'medium',
                'estimated_impact' => round($overdue, 2),
                'affected_area' => 'collections',
                'recommended_action' => 'Send payment links to the listed customers today and follow up by WhatsApp.',
            ];
        }
        if ($soon > 0) {
            $n = (int) ($receivables['aging_counts']['due_7_days'] ?? 0);
            $out[] = [
                'type' => 'risk',
                'category' => 'balances_due_this_week',
                'title' => 'Balances due before trips this week',
                'finding' => "{$n} upcoming trip(s) in the next 7 days still have " . round($soon, 2) . ' ' . $receivables['currency'] . ' unpaid.',
                'evidence' => ['due_7_days' => $soon, 'count' => $n],
                'reasoning_summary' => 'Outstanding balance on confirmed bookings starting within 7 days.',
                'confidence' => 'high',
                'severity' => 'medium',
                'estimated_impact' => round($soon, 2),
                'affected_area' => 'collections',
                'recommended_action' => 'Collect the remaining balance before the trip date (payment link or on-arrival policy).',
            ];
        }
        return $out;
    }
}

<?php

/**
 * Tourfecto - Tourism Revenue KPIs + Booking Pace
 * @version 2.0.0
 *
 * مؤشرات Revenue Management الخاصة بالسياحة - مش موجودة عند أدوات Revenue
 * Intelligence العامة (Clari/Gong مصممين لمبيعات B2B):
 *
 *   - متوسط قيمة الحجز، الإيراد لكل مسافر، متوسط حجم المجموعة
 *   - فترة الحجز المسبق (Lead time) + توزيعها
 *   - معدل الإلغاء، معدل عدم الحضور (No-show)
 *   - مزيج القنوات (مباشر/واتساب/موقع/OTA) + العمولة + الصافي بعد العمولة
 *   - الطلب حسب يوم الأسبوع، وأفضل المنتجات
 *   - Booking Pace / On-the-books: الحجوزات المؤكدة للأيام الجاية مقارنة
 *     بنفس النقطة الزمنية السنة الماضية (أهم مؤشر بيستخدمه مديرو الإيرادات
 *     في الفنادق وشركات الرحلات لمعرفة هل الموسم الجاي أحسن ولا أضعف بدري).
 *
 * المصدر: جدول bookings الحقيقي (BookingEngine) - لا تقديرات. كل المبالغ
 * محوّلة لعملة التقارير، وأي عملة بدون سعر بتتستبعد ويتذكر ده صراحة.
 */
class TourismKpiService
{
    /** @var RevenueDataGateway */
    private $gateway;

    public function __construct(?RevenueDataGateway $gateway = null)
    {
        $this->gateway = $gateway ?? new RevenueDataGateway();
    }

    public function getKpis(int $userId, string $periodType = 'monthly'): array
    {
        $days = RevenueOverviewService::periodToDays($periodType);
        $now = new DateTimeImmutable('now');
        $to = $now->modify('+1 second');
        $from = $now->modify("-{$days} days");
        $prevFrom = $from->modify("-{$days} days");

        $base = RevenueSettingsService::baseCurrency($userId);
        $rates = FxRateService::latestRates($userId);
        $commissions = RevenueSettingsService::commissions($userId);

        $current = self::computeKpis($this->gateway->getBookings($userId, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')), $base, $rates, $commissions);
        $previous = self::computeKpis($this->gateway->getBookings($userId, $prevFrom->format('Y-m-d H:i:s'), $from->format('Y-m-d H:i:s')), $base, $rates, $commissions);

        $current['period_type'] = $periodType;
        $current['comparison'] = self::compare($current, $previous);
        return $current;
    }

    public function getPace(int $userId, int $horizonDays = 90): array
    {
        $horizonDays = max(7, min(365, $horizonDays));
        $today = new DateTimeImmutable('today');
        $lyToday = $today->modify('-1 year');
        $base = RevenueSettingsService::baseCurrency($userId);
        $rates = FxRateService::latestRates($userId);

        $thisYear = $this->gateway->getBookings($userId, $today->format('Y-m-d'), $today->modify("+{$horizonDays} days")->format('Y-m-d'), 'start_date');
        $lastYear = $this->gateway->getBookings($userId, $lyToday->format('Y-m-d'), $lyToday->modify("+{$horizonDays} days")->format('Y-m-d'), 'start_date');
        $lastYearFinal = $lastYear;

        return self::computePace($thisYear, $lastYear, $lastYearFinal, $today->format('Y-m-d'), $horizonDays, $base, $rates);
    }

    /** يطبّع مصدر الحجز لمفتاح قناة. Pure. */
    public static function channelKey(?string $source): string
    {
        $s = strtolower(trim((string) $source));
        if ($s === '') {
            return 'direct';
        }
        if (strpos($s, 'ota:') === 0) {
            return 'ota:' . preg_replace('/[^a-z0-9_\-\.]/', '', substr($s, 4));
        }
        return preg_replace('/[^a-z0-9_:\-\.]/', '', $s) ?: 'direct';
    }

    /** نسبة العمولة للقناة: مطابقة دقيقة ثم "ota:*" كقيمة افتراضية لكل الـ OTA. Pure. */
    public static function commissionFor(string $channel, array $commissions): ?float
    {
        if (isset($commissions[$channel])) {
            return (float) $commissions[$channel];
        }
        if (strpos($channel, 'ota:') === 0 && isset($commissions['ota:*'])) {
            return (float) $commissions['ota:*'];
        }
        return null;
    }

    /**
     * كل مؤشرات الفترة من صفوف الحجوزات. Pure.
     */
    public static function computeKpis(array $bookings, string $base, array $rates, array $commissions = []): array
    {
        $total = count($bookings);
        $cancelled = 0;
        $noShow = 0;
        $valid = 0;
        $revenue = 0.0;
        $travelers = 0;
        $leadTimes = [];
        $leadBuckets = ['0_7' => 0, '8_30' => 0, '31_90' => 0, '90_plus' => 0];
        $channels = [];
        $dow = array_fill(0, 7, ['bookings' => 0, 'revenue' => 0.0]);
        $products = [];
        $unconverted = [];

        foreach ($bookings as $b) {
            $status = (string) ($b['status'] ?? '');
            if ($status === 'cancelled') {
                $cancelled++;
                continue;
            }
            if ($status === 'no_show') {
                $noShow++;
            }
            if (!in_array($status, ['confirmed', 'completed', 'no_show'], true)) {
                continue; // pending مش إيراد
            }
            $amount = FxRateService::convert((float) $b['total_amount'], strtoupper((string) ($b['currency'] ?: 'USD')), $base, $rates);
            if ($amount === null) {
                $unconverted[strtoupper((string) $b['currency'])] = true;
                continue;
            }
            $valid++;
            $pax = max(0, (int) ($b['adults_count'] ?? 0)) + max(0, (int) ($b['children_count'] ?? 0));
            $travelers += $pax;
            $revenue += $amount;

            if (!empty($b['start_date']) && !empty($b['created_at'])) {
                $lead = (int) floor((strtotime(substr((string) $b['start_date'], 0, 10)) - strtotime(substr((string) $b['created_at'], 0, 10))) / 86400);
                if ($lead >= 0) {
                    $leadTimes[] = $lead;
                    $leadBuckets[$lead <= 7 ? '0_7' : ($lead <= 30 ? '8_30' : ($lead <= 90 ? '31_90' : '90_plus'))]++;
                }
                $w = (int) date('w', strtotime(substr((string) $b['start_date'], 0, 10)));
                $dow[$w]['bookings']++;
                $dow[$w]['revenue'] += $amount;
            }

            $ch = self::channelKey($b['source'] ?? null);
            if (!isset($channels[$ch])) {
                $channels[$ch] = ['channel' => $ch, 'bookings' => 0, 'revenue' => 0.0, 'travelers' => 0];
            }
            $channels[$ch]['bookings']++;
            $channels[$ch]['revenue'] += $amount;
            $channels[$ch]['travelers'] += $pax;

            $pk = !empty($b['product_id']) ? 'p' . $b['product_id'] : 'n' . md5((string) ($b['product_name'] ?? ''));
            if (!isset($products[$pk])) {
                $products[$pk] = ['product_id' => !empty($b['product_id']) ? (int) $b['product_id'] : null, 'product_name' => (string) ($b['product_name'] ?? '—'), 'bookings' => 0, 'travelers' => 0, 'revenue' => 0.0];
            }
            $products[$pk]['bookings']++;
            $products[$pk]['travelers'] += $pax;
            $products[$pk]['revenue'] += $amount;
        }

        $commissionTotal = 0.0;
        $commissionKnown = true;
        foreach ($channels as $k => $c) {
            $pct = self::commissionFor($k, $commissions);
            $channels[$k]['share_percent'] = $revenue > 0 ? round($c['revenue'] / $revenue * 100, 1) : null;
            $channels[$k]['commission_percent'] = $pct;
            if ($pct === null && strpos($k, 'ota:') === 0) {
                $commissionKnown = false;
            }
            $comm = $pct !== null ? $c['revenue'] * $pct / 100 : 0.0;
            $commissionTotal += $comm;
            $channels[$k]['commission'] = $pct !== null ? round($comm, 2) : null;
            $channels[$k]['net_revenue'] = round($c['revenue'] - $comm, 2);
            $channels[$k]['revenue'] = round($c['revenue'], 2);
            $channels[$k]['avg_booking_value'] = $c['bookings'] > 0 ? round($c['revenue'] / $c['bookings'], 2) : null;
        }
        usort($channels, static function ($a, $b) {
            return $b['revenue'] <=> $a['revenue'];
        });
        foreach ($products as $k => $p) {
            $products[$k]['revenue'] = round($p['revenue'], 2);
            $products[$k]['revenue_per_traveler'] = $p['travelers'] > 0 ? round($p['revenue'] / $p['travelers'], 2) : null;
        }
        usort($products, static function ($a, $b) {
            return $b['revenue'] <=> $a['revenue'];
        });
        $dayNames = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
        $dowOut = [];
        foreach ($dow as $i => $d) {
            $dowOut[] = ['day' => $dayNames[$i], 'bookings' => $d['bookings'], 'revenue' => round($d['revenue'], 2)];
        }

        sort($leadTimes);
        $nl = count($leadTimes);
        $median = $nl === 0 ? null : ($nl % 2 ? $leadTimes[intdiv($nl, 2)] : ($leadTimes[$nl / 2 - 1] + $leadTimes[$nl / 2]) / 2);

        return [
            'has_data' => $total > 0,
            'currency' => $base,
            'bookings_total' => $total,
            'bookings_revenue_generating' => $valid,
            'gross_booking_value' => round($revenue, 2),
            'travelers' => $travelers,
            'avg_booking_value' => $valid > 0 ? round($revenue / $valid, 2) : null,
            'revenue_per_traveler' => $travelers > 0 ? round($revenue / $travelers, 2) : null,
            'avg_group_size' => $valid > 0 ? round($travelers / $valid, 2) : null,
            'cancellation_rate' => $total > 0 ? round($cancelled / $total * 100, 1) : null,
            'cancelled' => $cancelled,
            'no_show_rate' => ($valid > 0) ? round($noShow / $valid * 100, 1) : null,
            'avg_lead_time_days' => $nl > 0 ? round(array_sum($leadTimes) / $nl, 1) : null,
            'median_lead_time_days' => $median,
            'lead_time_buckets' => $leadBuckets,
            'channels' => array_values($channels),
            'commission_total' => round($commissionTotal, 2),
            'commission_complete' => $commissionKnown,
            'net_revenue_after_commission' => round($revenue - $commissionTotal, 2),
            'day_of_week' => $dowOut,
            'top_products' => array_slice(array_values($products), 0, 10),
            'unconverted_currencies' => array_keys($unconverted),
        ];
    }

    /** نسب التغيير مقابل الفترة السابقة. Pure. */
    public static function compare(array $cur, array $prev): array
    {
        $keys = ['gross_booking_value', 'travelers', 'avg_booking_value', 'revenue_per_traveler', 'cancellation_rate', 'avg_lead_time_days', 'bookings_revenue_generating'];
        $out = [];
        foreach ($keys as $k) {
            $a = $cur[$k] ?? null;
            $b = $prev[$k] ?? null;
            if ($a === null || $b === null) {
                $out[$k] = null;
                continue;
            }
            if (in_array($k, ['cancellation_rate'], true)) {
                $out[$k] = round($a - $b, 1); // فرق نقاط مئوية
                continue;
            }
            $out[$k] = $b != 0 ? round(($a - $b) / abs($b) * 100, 1) : null;
        }
        return $out;
    }

    /**
     * Booking Pace (On-the-books). Pure.
     *
     * @param array $thisYear حجوزات رحلاتها في [اليوم, اليوم+H)
     * @param array $lastYear حجوزات رحلاتها في [نفس اليوم السنة الماضية, +H)
     * @param array $lastYearFinal نفس lastYear (لحساب النتيجة النهائية للسنة الماضية)
     */
    public static function computePace(array $thisYear, array $lastYear, array $lastYearFinal, string $today, int $horizon, string $base, array $rates): array
    {
        $lyCutoff = (new DateTimeImmutable($today))->modify('-1 year')->format('Y-m-d 23:59:59');
        $sum = static function (array $rows, ?string $createdBefore, bool $excludeCancelled) use ($base, $rates) {
            $rev = 0.0;
            $pax = 0;
            $n = 0;
            foreach ($rows as $b) {
                $st = (string) ($b['status'] ?? '');
                if ($st === 'pending' || ($excludeCancelled && $st === 'cancelled')) {
                    continue;
                }
                if ($createdBefore !== null && (string) $b['created_at'] > $createdBefore) {
                    continue;
                }
                $v = FxRateService::convert((float) $b['total_amount'], strtoupper((string) ($b['currency'] ?: 'USD')), $base, $rates);
                if ($v === null) {
                    continue;
                }
                $rev += $v;
                $pax += (int) ($b['adults_count'] ?? 0) + (int) ($b['children_count'] ?? 0);
                $n++;
            }
            return ['revenue' => round($rev, 2), 'travelers' => $pax, 'bookings' => $n];
        };

        $otb = $sum($thisYear, null, true);
        // السنة الماضية "عند نفس النقطة": اللي كان محجوز لحد نفس اليوم من السنة الماضية.
        // تقريب مُفصح عنه: الحجوزات اللي اتلغت بعدها مستبعدة لأن تاريخ الإلغاء غير مسجّل.
        $lyAtSamePoint = $sum($lastYear, $lyCutoff, true);
        $lyFinal = $sum($lastYearFinal, null, true);

        $pace = $lyAtSamePoint['revenue'] > 0 ? round(($otb['revenue'] - $lyAtSamePoint['revenue']) / $lyAtSamePoint['revenue'] * 100, 1) : null;
        $pickupShare = $lyFinal['revenue'] > 0 ? ($lyAtSamePoint['revenue'] / $lyFinal['revenue']) : null;
        // إسقاط بسيط: لو السنة الماضية كان محجوز X% من النتيجة النهائية عند نفس النقطة،
        // نفترض نفس منحنى الحجز (Pickup) للسنة دي.
        $projected = ($pickupShare !== null && $pickupShare > 0.05) ? round($otb['revenue'] / $pickupShare, 2) : null;

        $status = 'no_history';
        if ($pace !== null) {
            $status = $pace >= 5 ? 'ahead' : ($pace <= -5 ? 'behind' : 'on_pace');
        }

        return [
            'has_data' => $otb['bookings'] > 0 || $lyFinal['bookings'] > 0,
            'currency' => $base,
            'horizon_days' => $horizon,
            'on_the_books' => $otb,
            'last_year_same_point' => $lyAtSamePoint,
            'last_year_final' => $lyFinal,
            'pace_percent' => $pace,
            'pace_status' => $status,
            'projected_final_revenue' => $projected,
            'note' => 'Last year figures exclude bookings that are cancelled today (cancellation date is not stored). Projection assumes the same booking curve as last year.',
        ];
    }

    /** Insight مخاطرة لو الموسم الجاي متأخر عن السنة الماضية. Pure. */
    public static function paceToInsights(array $pace): array
    {
        if (($pace['pace_status'] ?? '') !== 'behind') {
            return [];
        }
        $gap = round(($pace['last_year_same_point']['revenue'] ?? 0) - ($pace['on_the_books']['revenue'] ?? 0), 2);
        return [[
            'type' => 'risk',
            'category' => 'booking_pace_behind',
            'title' => 'Upcoming season is booking slower than last year',
            'finding' => "On-the-books revenue for the next {$pace['horizon_days']} days is {$pace['pace_percent']}% vs the same point last year (gap {$gap} {$pace['currency']}).",
            'evidence' => ['pace' => $pace],
            'reasoning_summary' => 'Compares confirmed bookings for upcoming trip dates with bookings that existed at the same date last year for the same trip window.',
            'confidence' => ($pace['last_year_same_point']['bookings'] ?? 0) >= 10 ? 'high' : 'medium',
            'severity' => ($pace['pace_percent'] ?? 0) <= -20 ? 'high' : 'medium',
            'estimated_impact' => $gap,
            'affected_area' => 'bookings',
            'recommended_action' => 'Launch an early-booking offer or retargeting campaign for the weak dates now, before the season starts.',
        ]];
    }
}

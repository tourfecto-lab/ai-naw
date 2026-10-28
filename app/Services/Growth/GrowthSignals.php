<?php
/**
 * Tourfecto - إشارات النمو (منطق نقي من غير قاعدة بيانات)
 *
 * بيحوّل بيانات الحجوزات الحقيقية لقرار تسويقي واضح تستخدمه باقي الخدمات (السوشيال أولًا):
 *   - الموسم: الشهر المطلوب ذروة ولا متوسط ولا هادي (من الحجوزات حسب شهر الرحلة، مش شهر الحجز)
 *   - مهلة الحجز: العملاء بيحجزوا قبل الرحلة بكام يوم ← نروّج لأنهي شهر رحلات دلوقتي
 *   - الرحلات اللي نركّز عليها: الأكثر طلبًا، اللي طلبها بيزيد، واللي محتاجة حجوزات في موسم عالي
 *   - توزيع البوستات على الرحلات بأوزان واضحة
 *
 * قاعدة: مفيش أرقام مخترعة. لو البيانات قليلة الحالة بتبقى unknown والثقة low، والخدمات بتكمّل
 * بالمعلومات المؤكدة بس (ملف الشركة والرحلات النشطة).
 */
final class GrowthSignals
{
    public const MIN_BOOKINGS_FOR_SEASON = 12;

    /**
     * @param array $monthly  [['month'=>'YYYY-MM','bookings'=>int,'travelers'=>int], ...] حسب شهر الرحلة (آخر 24 شهر + القادم)
     * @param array $trips    [['id','name','price','currency','url','recent'=>حجوزات آخر 90 يوم,'previous'=>نفس الفترة السنة اللي فاتت,'upcoming'=>حجوزات مؤكدة لرحلات الشهر المستهدف,'revenue'=>إيراد آخر 90 يوم], ...]
     * @param int[]  $leadDays أيام بين تاريخ الحجز وتاريخ الرحلة (للحجوزات المؤكدة)
     * @param string $month    الشهر المستهدف YYYY-MM
     * @param int    $postCount عدد البوستات اللي هتتوزع
     */
    public static function build(array $monthly, array $trips, array $leadDays, string $month, int $postCount = 12, ?array $revenue = null, array $markets = []): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) throw new InvalidArgumentException('Invalid month');
        $season = self::season($monthly, $month);
        $lead = self::median($leadDays);
        $focus = self::focus($trips, $season['level']);
        $mix = self::allocate($focus, $postCount);
        foreach ($focus as &$f) $f['posts'] = $mix[$f['id']] ?? 0;
        unset($f);
        $evidence = [];
        if ($season['level'] !== 'unknown') $evidence[] = sprintf('%s: %s season (%.2fx the monthly average of %d bookings, based on %d bookings).', $month, $season['level'], $season['index'], (int) round($season['average']), $season['sample']);
        if ($lead !== null) $evidence[] = sprintf('Guests book a median of %d days before the trip; posts in %s should sell departures from %s onward.', $lead, $month, self::promoteFrom($month, $lead));
        foreach (array_slice($focus, 0, 5) as $f) $evidence[] = sprintf('%s: %s (%d bookings in the last 90 days vs %d a year earlier, %d already booked for %s).', $f['name'], str_replace('_', ' ', $f['reason']), $f['recent'], $f['previous'], $f['upcoming'], $month);
        if ($revenue && isset($revenue['seasonal_factor']) && $revenue['seasonal_factor'] !== null) $evidence[] = sprintf('Revenue intelligence: current period is %.0f%% %s the prior equivalent period.', abs(($revenue['seasonal_factor'] - 1) * 100), $revenue['seasonal_factor'] >= 1 ? 'above' : 'below');
        $confidence = $season['sample'] >= 40 && count($trips) > 0 ? 'high' : ($season['sample'] >= self::MIN_BOOKINGS_FOR_SEASON ? 'medium' : 'low');
        return [
            'month' => $month,
            'season' => $season,
            'lead_time_days' => $lead,
            'promote_departures_from' => $lead !== null ? self::promoteFrom($month, $lead) : null,
            'focus_trips' => $focus,
            'brand_posts' => max(0, $postCount - array_sum($mix)),
            'markets' => $markets,
            'revenue' => $revenue,
            'goal' => self::goal($season['level'], $focus),
            'evidence' => $evidence,
            'confidence' => $confidence,
        ];
    }

    /** مستوى الموسم من متوسط نفس الشهر في السنين السابقة مقابل المتوسط الشهري العام */
    public static function season(array $monthly, string $month): array
    {
        $byMonth = [];
        $total = 0;
        $sample = 0;
        foreach ($monthly as $r) {
            if (!isset($r['month']) || !preg_match('/^\d{4}-\d{2}$/D', (string) $r['month']) || $r['month'] >= $month) continue;
            $n = max(0, (int) ($r['bookings'] ?? 0));
            $byMonth[(string) $r['month']] = $n;
            $total += $n;
            $sample += $n;
        }
        $months = count($byMonth);
        if ($sample < self::MIN_BOOKINGS_FOR_SEASON || $months < 6) {
            return ['level' => 'unknown', 'index' => null, 'average' => $months ? $total / $months : 0, 'sample' => $sample, 'peak_months' => []];
        }
        $avg = $total / $months;
        $calendar = substr($month, 5, 2);
        $same = array_values(array_filter($byMonth, fn ($v, $k) => substr($k, 5, 2) === $calendar, ARRAY_FILTER_USE_BOTH));
        // نفس الشهر مش موجود في التاريخ: نستخدم الشهرين اللي جنبه كتقريب
        if (!$same) {
            foreach ([-1, 1] as $d) {
                $near = (new DateTimeImmutable($month . '-01'))->modify(($d > 0 ? '+' : '') . $d . ' month')->format('m');
                foreach ($byMonth as $k => $v) if (substr($k, 5, 2) === $near) $same[] = $v;
            }
        }
        if (!$same || $avg <= 0) return ['level' => 'unknown', 'index' => null, 'average' => $avg, 'sample' => $sample, 'peak_months' => []];
        $index = (array_sum($same) / count($same)) / $avg;
        $calendarAvg = [];
        foreach ($byMonth as $k => $v) $calendarAvg[substr($k, 5, 2)][] = $v;
        $peaks = [];
        foreach ($calendarAvg as $m => $vals) if (array_sum($vals) / count($vals) >= $avg * 1.2) $peaks[] = sprintf('%02d', (int) $m);
        sort($peaks);
        return ['level' => $index >= 1.2 ? 'peak' : ($index <= 0.8 ? 'low' : 'shoulder'), 'index' => round($index, 2), 'average' => $avg, 'sample' => $sample, 'peak_months' => $peaks];
    }

    /** ترتيب الرحلات بسبب واضح لكل واحدة */
    public static function focus(array $trips, string $seasonLevel): array
    {
        $out = [];
        foreach ($trips as $t) {
            $recent = max(0, (int) ($t['recent'] ?? 0));
            $previous = max(0, (int) ($t['previous'] ?? 0));
            $upcoming = max(0, (int) ($t['upcoming'] ?? 0));
            if ($recent >= 3 && $recent >= $previous * 1.25 && $previous > 0) $reason = 'rising_demand';
            elseif ($recent >= 3) $reason = 'top_seller';
            elseif ($seasonLevel === 'peak' && $upcoming < 3) $reason = 'needs_bookings_in_peak';
            elseif ($recent === 0 && $previous === 0) $reason = 'awareness';
            else $reason = 'steady';
            // الوزن: الطلب الحالي + الزيادة + فرصة ملء الأماكن في الموسم العالي
            $weight = 1 + $recent + max(0, $recent - $previous) * 0.5 + ($reason === 'needs_bookings_in_peak' ? 4 : 0) + ($reason === 'awareness' ? 1 : 0);
            $out[] = [
                'id' => (int) $t['id'], 'name' => (string) $t['name'], 'url' => $t['url'] ?? null,
                'price' => isset($t['price']) && $t['price'] !== null ? (float) $t['price'] : null, 'currency' => $t['currency'] ?? null,
                'recent' => $recent, 'previous' => $previous, 'upcoming' => $upcoming, 'revenue' => isset($t['revenue']) ? (float) $t['revenue'] : null,
                'reason' => $reason, 'weight' => round($weight, 2),
            ];
        }
        usort($out, fn ($a, $b) => $b['weight'] <=> $a['weight'] ?: $b['recent'] <=> $a['recent'] ?: $a['id'] <=> $b['id']);
        return $out;
    }

    /** ~70% من البوستات للرحلات (بأوزانها) والباقي لبوستات الثقة والنصايح والعلامة */
    public static function allocate(array $focus, int $postCount): array
    {
        if ($postCount <= 0 || !$focus) return [];
        $tripPosts = max(1, (int) round($postCount * 0.7));
        $focus = array_slice($focus, 0, max(1, min(count($focus), $tripPosts)));
        $sum = array_sum(array_column($focus, 'weight')) ?: 1;
        $alloc = [];
        $rema = [];
        foreach ($focus as $f) {
            $exact = $tripPosts * $f['weight'] / $sum;
            $alloc[$f['id']] = (int) floor($exact);
            $rema[$f['id']] = $exact - floor($exact);
        }
        arsort($rema);
        $left = $tripPosts - array_sum($alloc);
        foreach (array_keys($rema) as $id) { if ($left-- <= 0) break; $alloc[$id]++; }
        return array_filter($alloc);
    }

    public static function median(array $values): ?int
    {
        $v = array_values(array_filter(array_map('intval', $values), fn ($x) => $x >= 0 && $x <= 540));
        if (count($v) < 5) return null;
        sort($v);
        $n = count($v);
        return (int) round($n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2);
    }

    private static function promoteFrom(string $month, int $lead): string
    {
        return (new DateTimeImmutable($month . '-01'))->modify('+' . $lead . ' days')->format('Y-m-d');
    }

    private static function goal(string $level, array $focus): string
    {
        $names = implode(', ', array_slice(array_column($focus, 'name'), 0, 3));
        return match ($level) {
            'peak' => 'High season: convert demand into confirmed bookings' . ($names ? ' for ' . $names : '') . ' and fill remaining departures.',
            'low' => 'Low season: build awareness and early bookings for the next high season' . ($names ? ', featuring ' . $names : '') . '.',
            'shoulder' => 'Shoulder season: steady bookings' . ($names ? ' for ' . $names : '') . ' with trust and value content.',
            default => 'Grow bookings' . ($names ? ' for ' . $names : '') . ' with helpful, trust-building content.',
        };
    }
}

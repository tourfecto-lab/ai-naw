<?php

/** Tourfecto - AI Voice Agent: arithmetic for the analytics dashboard. Pure; rates are null (not 0) when there is nothing to divide by. */
class VoiceAnalyticsMath
{
    public static function rate(int|float $part, int|float $whole): ?float
    {
        return $whole > 0 ? round($part / $whole, 4) : null;
    }

    /** Nearest-rank percentile. */
    public static function percentile(array $values, int $p): ?float
    {
        $v = array_values(array_filter($values, 'is_numeric'));
        if (!$v) {
            return null;
        }
        sort($v);
        return (float) $v[max(0, (int) ceil($p / 100 * count($v)) - 1)];
    }

    /** @return array{revenue:float, cost:float, profit:float, roi_pct:?float, cost_per_booking:?float} */
    public static function roi(float $revenue, float $cost, int $bookings): array
    {
        return ['revenue' => round($revenue, 2), 'cost' => round($cost, 2), 'profit' => round($revenue - $cost, 2), 'roi_pct' => $cost > 0 ? round(($revenue - $cost) / $cost * 100, 1) : null, 'cost_per_booking' => $bookings > 0 ? round($cost / $bookings, 2) : null];
    }

    /** @param int[] $scores 1-5 @return array{responses:int, average:?float, csat:?float, distribution:array<int,int>} csat = share of 4-5 */
    public static function satisfaction(array $scores): array
    {
        $dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        foreach ($scores as $s) {
            if (isset($dist[(int) $s])) {
                $dist[(int) $s]++;
            }
        }
        $n = array_sum($dist);
        return ['responses' => $n, 'average' => $n ? round(array_sum(array_map(static fn ($k, $v) => $k * $v, array_keys($dist), $dist)) / $n, 2) : null, 'csat' => self::rate($dist[4] + $dist[5], $n), 'distribution' => $dist];
    }

    /** @param array<string,int> $counts @return list<array{key:string,count:int,share:?float}> */
    public static function top(array $counts, int $n = 5): array
    {
        arsort($counts);
        $total = array_sum($counts);
        $out = [];
        foreach (array_slice($counts, 0, $n, true) as $k => $c) {
            $out[] = ['key' => (string) $k, 'count' => (int) $c, 'share' => self::rate($c, $total)];
        }
        return $out;
    }

    /** Validated [from, to) period as UTC datetimes; at most one year. @return array{0:string,1:string} */
    public static function period(?string $from, ?string $to, ?int $now = null): array
    {
        $now = $now ?? time();
        $f = $from ? strtotime($from . ' 00:00:00 UTC') : strtotime(gmdate('Y-m-d', $now - 29 * 86400) . ' 00:00:00 UTC');
        $t = $to ? strtotime($to . ' 00:00:00 UTC') + 86400 : strtotime(gmdate('Y-m-d', $now) . ' 00:00:00 UTC') + 86400;
        if ($f === false || $t === false || ($from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) || ($to && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))) {
            throw new InvalidArgumentException('Dates must be YYYY-MM-DD');
        }
        if ($t <= $f || $t - $f > 366 * 86400) {
            throw new InvalidArgumentException('Choose a period between one day and one year');
        }
        return [gmdate('Y-m-d H:i:s', $f), gmdate('Y-m-d H:i:s', $t)];
    }

    /** Rental cost of a monthly-priced number for the days it existed inside the period. */
    public static function proratedRental(float $monthly, string $createdAt, string $from, string $to): float
    {
        $start = max(strtotime($createdAt . ' UTC'), strtotime($from . ' UTC'));
        $end = strtotime($to . ' UTC');
        return $end > $start ? round($monthly * (($end - $start) / 86400) / 30, 4) : 0.0;
    }
}

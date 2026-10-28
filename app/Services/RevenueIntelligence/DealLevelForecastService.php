<?php

/**
 * Tourfecto - Deal-Level Forecast & Sales Attribution
 * @version 1.0.0
 *
 * v1.5.0: Clari-style deal-level forecasting + rep/team attribution.
 *
 * الفلسفة ثابتة: أرقام من بيانات حقيقية فقط. لو الصفقة مالهاش
 * expected_close_date أو probability => لا نضمّنها في توقعات متحيزة،
 * بل نصنّفها "غير موقّتة" ونفصح عنها - لا نخترع تاريخ/احتمال.
 *
 * Pure functions:
 *   - groupOpenDealsByCloseWindow(array $deals)  - توزيع الصفقات المفتوحة على نوافذ الإغلاق
 *   - weightedDealValue(array $deal)             - value * probability (للواقعية) مع fallback صادق
 *   - aggregateByRep(array $deals)               - توزيع الـ pipeline/الإيراد على المندوبين
 *   - aggregateByTeam(array $deals)              - التوزيع على الفرق
 */
class DealLevelForecastService
{
    /**
     * توزيع الصفقات المفتوحة حسب نافذة الإغلاق (هذا الشهر/الربع/لاحقًا/غير موقّت).
     * Pure function.
     *
     * @param array $deals صفوف من getDealsWithRep(): [['id','title','value','probability',
     *                    'expected_close_date','status','assigned_rep_id',...]]
     * @param string $todayStr Y-m-d
     */
    public static function groupOpenDealsByCloseWindow(array $deals, string $todayStr = 'now'): array
    {
        $today = new DateTime($todayStr);
        $monthEnd = (clone $today)->modify('last day of this month');
        $quarterEnd = self::quarterEnd($today);

        $buckets = [
            'this_month' => ['title' => 'This Month', 'weighted' => 0.0, 'unweighted' => 0.0, 'count' => 0, 'deals' => []],
            'this_quarter' => ['title' => 'This Quarter', 'weighted' => 0.0, 'unweighted' => 0.0, 'count' => 0, 'deals' => []],
            'later' => ['title' => 'Later', 'weighted' => 0.0, 'unweighted' => 0.0, 'count' => 0, 'deals' => []],
            'undated' => ['title' => 'Undated', 'weighted' => 0.0, 'unweighted' => 0.0, 'count' => 0, 'deals' => []],
        ];

        $hasData = false;
        foreach ($deals as $deal) {
            if (($deal['status'] ?? 'open') !== 'open') {
                continue;
            }
            $hasData = true;
            $value = (float) ($deal['value'] ?? 0);
            $weighted = self::weightedDealValue($deal);
            $close = (string) ($deal['expected_close_date'] ?? '');
            if ($close === '' || $close === '0000-00-00') {
                $bucket = 'undated';
            } else {
                // تاريخ غير صالح = undated (من غير ما نستخدم $closeDt من صفقة سابقة)
                try {
                    $closeDt = new DateTime($close);
                } catch (Exception $e) {
                    $closeDt = null;
                }
                if ($closeDt === null) {
                    $bucket = 'undated';
                } elseif ($closeDt <= $monthEnd) {
                    $bucket = 'this_month';
                } elseif ($closeDt <= $quarterEnd) {
                    $bucket = 'this_quarter';
                } else {
                    $bucket = 'later';
                }
            }
            $buckets[$bucket]['weighted'] += $weighted;
            $buckets[$bucket]['unweighted'] += $value;
            $buckets[$bucket]['count']++;
            $buckets[$bucket]['deals'][] = $deal;
        }

        foreach ($buckets as $k => $b) {
            $buckets[$k]['weighted'] = round($b['weighted'], 2);
            $buckets[$k]['unweighted'] = round($b['unweighted'], 2);
        }

        $totalWeighted = array_sum(array_column($buckets, 'weighted'));
        $totalUnweighted = array_sum(array_column($buckets, 'unweighted'));
        $timedWeighted = $buckets['this_month']['weighted'] + $buckets['this_quarter']['weighted'] + $buckets['later']['weighted'];

        return [
            'has_data' => $hasData,
            'buckets' => $buckets,
            'total_weighted' => round($timedWeighted, 2),
            'total_unweighted' => round($buckets['this_month']['unweighted'] + $buckets['this_quarter']['unweighted'] + $buckets['later']['unweighted'], 2),
            'total_all_weighted' => round($totalWeighted, 2),
            'total_all_unweighted' => round($totalUnweighted, 2),
            'note' => 'Deal-level forecast uses each open deal value weighted by its real probability (stage win_probability if probability null). Undated deals are excluded from time buckets and shown separately - never invented a close date. total_weighted covers only timed buckets; total_all_weighted also includes undated deals.',
        ];
    }

    /**
     * نهاية الربع الحالي (آخر يوم في آخر شهر من الربع) - PHP's modify
     * لا يدعم 'last day of this quarter'، لذا نحسبها يدويًا.
     */
    private static function quarterEnd(DateTime $today): DateTime
    {
        $month = (int) $today->format('n');
        $quarter = (int) ceil($month / 3);
        $endMonth = $quarter * 3;
        $end = new DateTime($today->format('Y') . '-' . str_pad((string) $endMonth, 2, '0', STR_PAD_LEFT) . '-01');
        $end->modify('last day of this month');
        return $end;
    }

    /**
     * القيمة الموزونة لصفقة واحدة: value * probability.
     * لو probability مفقودة/فارغة نرجع 0 (لا افتراض خفي) — ليتعامل المتصل معها.
     */
    public static function weightedDealValue(array $deal): float
    {
        $value = (float) ($deal['value'] ?? 0);
        $probability = $deal['probability'] ?? null;
        if ($probability === null || $probability === '') {
            $probability = (float) ($deal['stage_win_probability'] ?? 0);
        }
        $probability = (float) $probability;
        if ($probability <= 0 || $probability > 100) {
            return 0.0;
        }
        return round($value * ($probability / 100), 2);
    }

    /**
     * توزيع إيراد/خط الصفقات على المندوبين (Sales Attribution - Clari-style).
     * Pure function. المندوب اللي من غير ما أسند له أي صفقة -> "unassigned".
     */
    public static function aggregateByRep(array $deals): array
    {
        $reps = [];
        $unassigned = ['rep_id' => null, 'rep_name' => 'Unassigned', 'team_name' => null, 'open_weighted' => 0.0, 'open_value' => 0.0, 'won_value' => 0.0, 'open_count' => 0, 'won_count' => 0];
        $hasData = false;

        foreach ($deals as $deal) {
            $hasData = true;
            $status = (string) ($deal['status'] ?? 'open');
            $repId = (int) ($deal['assigned_rep_id'] ?? 0);
            $value = (float) ($deal['value'] ?? 0);
            $weighted = self::weightedDealValue($deal);

            if ($repId <= 0) {
                if ($status === 'open') {
                    $unassigned['open_weighted'] += $weighted;
                    $unassigned['open_value'] += $value;
                    $unassigned['open_count']++;
                } elseif ($status === 'won') {
                    $unassigned['won_value'] += $value;
                    $unassigned['won_count']++;
                }
                continue;
            }

            $name = (string) ($deal['rep_name'] ?? 'Rep #' . $repId);
            $key = 'rep' . $repId;
            if (!isset($reps[$key])) {
                $reps[$key] = ['rep_id' => $repId, 'rep_name' => $name, 'team_name' => (string) ($deal['team_name'] ?? '') ?: null, 'team_id' => (int) ($deal['team_id'] ?? 0), 'open_weighted' => 0.0, 'open_value' => 0.0, 'won_value' => 0.0, 'open_count' => 0, 'won_count' => 0];
            }
            if ($status === 'open') {
                $reps[$key]['open_weighted'] += $weighted;
                $reps[$key]['open_value'] += $value;
                $reps[$key]['open_count']++;
            } elseif ($status === 'won') {
                $reps[$key]['won_value'] += $value;
                $reps[$key]['won_count']++;
            }
        }

        $repList = array_values($reps);
        foreach ($repList as &$r) {
            $r['open_weighted'] = round($r['open_weighted'], 2);
            $r['open_value'] = round($r['open_value'], 2);
            $r['won_value'] = round($r['won_value'], 2);
        }
        unset($r);
        if ($unassigned['open_count'] > 0 || $unassigned['won_count'] > 0) {
            $unassigned['open_weighted'] = round($unassigned['open_weighted'], 2);
            $unassigned['open_value'] = round($unassigned['open_value'], 2);
            $unassigned['won_value'] = round($unassigned['won_value'], 2);
            $repList[] = $unassigned;
        }

        return ['has_data' => $hasData, 'reps' => $repList];
    }

    /** توزيع على مستوى الفرق (يجمع مندوبي كل فريق + من غير فريق). */
    public static function aggregateByTeam(array $deals): array
    {
        $teams = [];
        $unassigned = ['team_id' => null, 'team_name' => 'Unassigned', 'open_weighted' => 0.0, 'open_value' => 0.0, 'won_value' => 0.0, 'open_count' => 0, 'won_count' => 0, 'reps' => 0];
        $hasData = false;
        $seenRepsByTeam = [];

        foreach ($deals as $deal) {
            $hasData = true;
            $status = (string) ($deal['status'] ?? 'open');
            $teamId = (int) ($deal['team_id'] ?? 0);
            $repId = (int) ($deal['assigned_rep_id'] ?? 0);
            $value = (float) ($deal['value'] ?? 0);
            $weighted = self::weightedDealValue($deal);

            if ($teamId <= 0) {
                if ($status === 'open') {
                    $unassigned['open_weighted'] += $weighted;
                    $unassigned['open_value'] += $value;
                    $unassigned['open_count']++;
                } elseif ($status === 'won') {
                    $unassigned['won_value'] += $value;
                    $unassigned['won_count']++;
                }
                if ($repId > 0 && !in_array($repId, $seenRepsByTeam['none'] ?? [], true)) {
                    $seenRepsByTeam['none'][] = $repId;
                    $unassigned['reps']++;
                }
                continue;
            }

            $name = (string) ($deal['team_name'] ?? 'Team #' . $teamId);
            $key = 'team' . $teamId;
            if (!isset($teams[$key])) {
                $teams[$key] = ['team_id' => $teamId, 'team_name' => $name, 'open_weighted' => 0.0, 'open_value' => 0.0, 'won_value' => 0.0, 'open_count' => 0, 'won_count' => 0, 'reps' => 0];
                $seenRepsByTeam[$key] = [];
            }
            if ($status === 'open') {
                $teams[$key]['open_weighted'] += $weighted;
                $teams[$key]['open_value'] += $value;
                $teams[$key]['open_count']++;
            } elseif ($status === 'won') {
                $teams[$key]['won_value'] += $value;
                $teams[$key]['won_count']++;
            }
            if ($repId > 0 && !in_array($repId, $seenRepsByTeam[$key], true)) {
                $seenRepsByTeam[$key][] = $repId;
                $teams[$key]['reps']++;
            }
        }

        $teamList = array_values($teams);
        foreach ($teamList as &$t) {
            $t['open_weighted'] = round($t['open_weighted'], 2);
            $t['open_value'] = round($t['open_value'], 2);
            $t['won_value'] = round($t['won_value'], 2);
        }
        unset($t);
        if ($unassigned['open_count'] > 0 || $unassigned['won_count'] > 0) {
            $unassigned['open_weighted'] = round($unassigned['open_weighted'], 2);
            $unassigned['open_value'] = round($unassigned['open_value'], 2);
            $unassigned['won_value'] = round($unassigned['won_value'], 2);
            $teamList[] = $unassigned;
        }

        return ['has_data' => $hasData, 'teams' => $teamList];
    }
}

<?php
/**
 * ملخص أثر التعديلات من المقارنات اللي اتجمّعت فعلًا (seo_impact_monitors بحالة reported): قبل/بعد من Search Console.
 * منطق صرف. القواعد:
 *  - مقارنة واحدة لكل (صفحة، تاريخ تغيير): نختار أطول نافذة اتجمّعت، علشان نوافذ 7/14/28 لنفس التغيير ما تتعدّش مرتين.
 *  - مفيش ادعاء سببية: ده فرق قبل/بعد على نفس الصفحة، وممكن يتأثر بالموسم وتغييرات تانية (causality_proven دايمًا false).
 *  - الثقة بتتحدد بعدد الصفحات المقارنة، ولو مفيش مقارنة قابلة للحساب النتيجة not_measured.
 */
final class SeoImpactSummary
{
    private const MIN_CLICKS = 30;
    private const NOTE = 'Before/after on the same pages from Search Console. Not proof that the changes caused the difference: seasonality and other changes also move these numbers.';

    /** @param array<int,array<string,mixed>> $rows صفوف seo_impact_monitors (page_path, change_date, window_days, result_json) */
    public static function aggregate(array $rows): array
    {
        $best = [];
        foreach ($rows as $r) {
            $key = (string) $r['page_path'] . '|' . (string) $r['change_date'];
            if (!isset($best[$key]) || (int) $r['window_days'] > (int) $best[$key]['window_days']) {
                $best[$key] = $r;
            }
        }
        $pages = 0;
        $tot = ['clicks_before' => 0.0, 'clicks_after' => 0.0, 'impressions_before' => 0.0, 'impressions_after' => 0.0];
        $up = $down = $flat = 0;
        $posDiffs = [];
        $asOf = null;
        $windows = [];
        foreach ($best as $r) {
            $res = is_string($r['result_json'] ?? null) ? json_decode($r['result_json'], true) : ($r['result_json'] ?? null);
            $s = is_array($res) ? ($res['search'] ?? null) : null;
            if (!is_array($s) || ($s['state'] ?? '') !== 'collected' || !is_array($s['before'] ?? null) || !is_array($s['after'] ?? null)) {
                continue;
            }
            $cb = $s['before']['clicks'] ?? null;
            $ca = $s['after']['clicks'] ?? null;
            $ib = $s['before']['impressions'] ?? null;
            $ia = $s['after']['impressions'] ?? null;
            if (!is_numeric($cb) || !is_numeric($ca) || !is_numeric($ib) || !is_numeric($ia)) {
                continue;
            }
            $pages++;
            $tot['clicks_before'] += (float) $cb;
            $tot['clicks_after'] += (float) $ca;
            $tot['impressions_before'] += (float) $ib;
            $tot['impressions_after'] += (float) $ia;
            if ((float) $ca > (float) $cb) $up++; elseif ((float) $ca < (float) $cb) $down++; else $flat++;
            $pb = $s['before']['position'] ?? null;
            $pa = $s['after']['position'] ?? null;
            if (is_numeric($pb) && is_numeric($pa) && (float) $pb > 0 && (float) $pa > 0) {
                $posDiffs[] = (float) $pa - (float) $pb; // سالب = ترتيب أحسن
            }
            $windows[(int) $r['window_days']] = true;
            $at = (string) ($res['collected_at'] ?? '');
            if ($at !== '' && ($asOf === null || strcmp($at, $asOf) > 0)) $asOf = $at;
        }
        if ($pages === 0) {
            return ['state' => 'not_measured', 'pages' => 0, 'causality_proven' => false, 'note' => self::NOTE];
        }
        $pct = static fn (float $b, float $a): ?float => $b > 0 ? round(($a - $b) / $b * 100, 1) : null;
        // عدد الصفحات لوحده مش كفاية: لو النقرات الكلية قليلة، الفروق ضوضاء فالثقة تتخفّض.
        $lowTraffic = ($tot['clicks_before'] + $tot['clicks_after']) < self::MIN_CLICKS;
        $confidence = $lowTraffic ? 'low' : ($pages >= 20 ? 'high' : ($pages >= 5 ? 'medium' : 'low'));
        return [
            'state' => 'measured', 'pages' => $pages, 'confidence' => $confidence, 'low_traffic' => $lowTraffic,
            'clicks' => ['before' => (int) $tot['clicks_before'], 'after' => (int) $tot['clicks_after'], 'change_pct' => $pct($tot['clicks_before'], $tot['clicks_after'])],
            'impressions' => ['before' => (int) $tot['impressions_before'], 'after' => (int) $tot['impressions_after'], 'change_pct' => $pct($tot['impressions_before'], $tot['impressions_after'])],
            'pages_up' => $up, 'pages_down' => $down, 'pages_flat' => $flat,
            'avg_position_change' => $posDiffs ? round(array_sum($posDiffs) / count($posDiffs), 2) : null, // سالب = أحسن
            'windows_days' => array_keys($windows), 'as_of' => $asOf, 'causality_proven' => false, 'source' => 'search_console', 'note' => self::NOTE,
        ];
    }
}

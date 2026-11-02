<?php

use PHPUnit\Framework\TestCase;

final class SeoImpactSummaryTest extends TestCase
{
    private function row(string $path, string $date, int $win, ?array $search, string $at = '2026-11-01T04:00:00+00:00'): array
    {
        return ['page_path' => $path, 'change_date' => $date, 'window_days' => $win,
            'result_json' => json_encode(['search' => $search, 'collected_at' => $at])];
    }

    private function s(int $cb, int $ca, int $ib, int $ia, ?float $pb = null, ?float $pa = null): array
    {
        return ['state' => 'collected', 'before' => ['clicks' => $cb, 'impressions' => $ib, 'position' => $pb], 'after' => ['clicks' => $ca, 'impressions' => $ia, 'position' => $pa]];
    }

    public function testNothingCollectedIsNotMeasuredAndNeverClaimsCausality(): void
    {
        $r = SeoImpactSummary::aggregate([]);
        $this->assertSame('not_measured', $r['state']);
        $this->assertFalse($r['causality_proven']);
        $this->assertSame('not_measured', SeoImpactSummary::aggregate([$this->row('/a', '2026-10-01', 7, ['state' => 'not_connected'])])['state']);
        $this->assertSame('not_measured', SeoImpactSummary::aggregate([$this->row('/a', '2026-10-01', 7, ['state' => 'collected', 'before' => ['clicks' => null], 'after' => ['clicks' => 3]])])['state']);
    }

    public function testOneComparisonPerChangeUsingTheLongestWindow(): void
    {
        $rows = [
            $this->row('/a', '2026-10-01', 7, $this->s(10, 11, 100, 110)),
            $this->row('/a', '2026-10-01', 28, $this->s(40, 60, 400, 500)),
            $this->row('/a', '2026-10-01', 14, $this->s(20, 22, 200, 210)),
        ];
        $r = SeoImpactSummary::aggregate($rows);
        $this->assertSame(1, $r['pages']);
        $this->assertSame(['before' => 40, 'after' => 60, 'change_pct' => 50.0], $r['clicks']);
        $this->assertSame([28], $r['windows_days']);
        $this->assertSame('low', $r['confidence']);
    }

    public function testManyPagesButTinyTrafficStaysLowConfidence(): void
    {
        $rows = [];
        foreach (range(1, 25) as $i) $rows[] = $this->row("/p$i", '2026-10-01', 7, $this->s(0, 0, 0, 3));
        $r = SeoImpactSummary::aggregate($rows);
        $this->assertSame(25, $r['pages']);
        $this->assertSame('low', $r['confidence']);
        $this->assertTrue($r['low_traffic']);
        $big = [];
        foreach (range(1, 25) as $i) $big[] = $this->row("/q$i", '2026-10-01', 28, $this->s(10, 12, 100, 120));
        $this->assertSame('high', SeoImpactSummary::aggregate($big)['confidence']);
    }

    public function testTotalsUpDownFlatAndPositionChange(): void
    {
        $rows = [
            $this->row('/a', '2026-10-01', 28, $this->s(10, 20, 100, 150, 12.0, 8.0)),
            $this->row('/b', '2026-10-02', 28, $this->s(30, 20, 300, 250, 5.0, 6.0)),
            $this->row('/c', '2026-10-03', 28, $this->s(5, 5, 50, 50)),
            $this->row('/a', '2026-10-20', 14, $this->s(1, 2, 10, 20)), // same page, different change: separate comparison
        ];
        $r = SeoImpactSummary::aggregate($rows);
        $this->assertSame(4, $r['pages']);
        $this->assertSame(['before' => 46, 'after' => 47, 'change_pct' => 2.2], $r['clicks']);
        $this->assertSame(2, $r['pages_up']);
        $this->assertSame(1, $r['pages_down']);
        $this->assertSame(1, $r['pages_flat']);
        $this->assertSame(-1.5, $r['avg_position_change']); // (-4 + 1) / 2, lower is better
        $this->assertFalse($r['causality_proven']);
        $this->assertStringContainsString('Not proof', $r['note']);
    }

    public function testZeroBaselineHasNoPercent(): void
    {
        $r = SeoImpactSummary::aggregate([$this->row('/a', '2026-10-01', 7, $this->s(0, 4, 0, 40))]);
        $this->assertNull($r['clicks']['change_pct']);
        $this->assertNull($r['avg_position_change']);
    }
}

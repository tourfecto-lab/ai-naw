<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/SeoExpert/SeoNextCycle.php';

final class SeoNextCycleTest extends TestCase
{
    public function testWeeklyWhileChecksRemain(): void
    {
        $now = gmmktime(10, 0, 0, 11, 4, 2026);
        $r = SeoNextCycle::dueAt($now, '2026-11', 1, 4, 4);
        $this->assertSame('weekly', $r['reason']);
        $this->assertSame('2026-11-11 10:00:00', $r['due_at']);
        $this->assertSame(3, $r['remaining']);
    }

    public function testExhaustedMonthWaitsForTheNextMonth(): void
    {
        $now = gmmktime(10, 0, 0, 11, 4, 2026);
        $r = SeoNextCycle::dueAt($now, '2026-11', 2, 2, 4);
        $this->assertSame('monthly_limit_reached', $r['reason']);
        $this->assertSame('2026-12-01 00:05:00', $r['due_at']);
    }

    public function testPlanLimitWinsOverTheChosenLimit(): void
    {
        $now = gmmktime(10, 0, 0, 12, 20, 2026);
        $r = SeoNextCycle::dueAt($now, '2026-12', 1, 4, 1);
        $this->assertSame('2027-01-01 00:05:00', $r['due_at']);
    }

    public function testOldBudgetMonthCountsAsUnused(): void
    {
        $now = gmmktime(10, 0, 0, 11, 4, 2026);
        $this->assertSame('weekly', SeoNextCycle::dueAt($now, '2026-10', 4, 4, 4)['reason']);
    }
}

<?php

use PHPUnit\Framework\TestCase;

/** "Connected" must not hide a connector that stopped pulling rules: report the age of the last pull and flag it when stale. */
final class EdgeConnectionFreshnessTest extends TestCase
{
    public function testRecentPullIsFresh(): void
    {
        $now = strtotime('2026-11-01 12:00:00');
        $this->assertSame(['minutes' => 5, 'stale' => false], EdgeFixController::freshness('2026-11-01 11:55:00', $now));
        $this->assertSame(['minutes' => 360, 'stale' => false], EdgeFixController::freshness('2026-11-01 06:00:00', $now));
    }

    public function testOldPullIsStale(): void
    {
        $now = strtotime('2026-11-01 12:00:00');
        $r = EdgeFixController::freshness('2026-10-30 20:01:49', $now);
        $this->assertTrue($r['stale']);
        $this->assertSame(2398, $r['minutes']);
        $this->assertTrue(EdgeFixController::freshness('2026-11-01 05:59:00', $now)['stale'], 'just over 6 hours');
    }

    public function testNeverSeenOrBadValueIsStaleWithUnknownAge(): void
    {
        $this->assertSame(['minutes' => null, 'stale' => true], EdgeFixController::freshness(null));
        $this->assertSame(['minutes' => null, 'stale' => true], EdgeFixController::freshness(''));
        $this->assertSame(['minutes' => null, 'stale' => true], EdgeFixController::freshness('not a date'));
    }

    public function testClockSkewNeverGivesNegativeAge(): void
    {
        $now = strtotime('2026-11-01 12:00:00');
        $this->assertSame(['minutes' => 0, 'stale' => false], EdgeFixController::freshness('2026-11-01 12:05:00', $now));
    }
}

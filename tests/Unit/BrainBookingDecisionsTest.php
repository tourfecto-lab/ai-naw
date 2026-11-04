<?php

/**
 * العقل الاستراتيجي: قرارات مراحل الحجز (حجوزات مستنية تأكيد، وفاقد عالي)
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/Brain/StrategicDecisions.php';

final class BrainBookingDecisionsTest extends TestCase
{
    private function b(array $o = []): array
    {
        return $o + ['last_30' => 4, 'last_90' => 9, 'revenue_30' => 800.0, 'created_30' => 8, 'lost_30' => 1, 'pending_stale' => 0, 'lost_rate_30' => 13];
    }

    public function testNoDataMeansNoDecision(): void
    {
        $this->assertSame([], StrategicDecisions::bookingDecisions(null));
        $this->assertSame([], StrategicDecisions::bookingDecisions($this->b()));
    }

    public function testStaleWaitingBookingsBecomeADecision(): void
    {
        $d = StrategicDecisions::bookingDecisions($this->b(['pending_stale' => 2]));
        $this->assertCount(1, $d);
        $this->assertSame('confirm_pending_bookings', $d[0]['key']);
        $this->assertSame('/bookings', $d[0]['action_url']);
        $this->assertSame('bookings.pending_stale', $d[0]['metric']['key']);
        $this->assertSame('down', $d[0]['metric']['direction']);
        $this->assertSame(2, $d[0]['impact']);
        $this->assertSame(3, StrategicDecisions::bookingDecisions($this->b(['pending_stale' => 3]))[0]['impact']);
    }

    public function testHighLossRateNeedsEnoughBookingsToCount(): void
    {
        $this->assertSame([], StrategicDecisions::bookingDecisions($this->b(['created_30' => 4, 'lost_30' => 3, 'lost_rate_30' => 75])));
        $this->assertSame([], StrategicDecisions::bookingDecisions($this->b(['lost_rate_30' => 24])));
        $d = StrategicDecisions::bookingDecisions($this->b(['created_30' => 8, 'lost_30' => 3, 'lost_rate_30' => 38]));
        $this->assertSame('reduce_lost_bookings', $d[0]['key']);
        $this->assertEquals(38, $d[0]['metric']['value']);
        $this->assertStringContainsString('3 of 8', $d[0]['evidence'][0]['en']);
    }

    public function testDecideIncludesThem(): void
    {
        $snap = ['understanding' => ['profile_confirmed' => true, 'goal' => 'x', 'audience' => 'x', 'offers' => 'x', 'markets' => ['countries' => ['UK']]], 'seo' => ['autopilot' => true], 'competitors' => ['tracked' => 1], 'social' => ['published_30' => 3, 'scheduled' => 1], 'bookings' => $this->b(['pending_stale' => 2])];
        $this->assertContains('confirm_pending_bookings', array_column(StrategicDecisions::decide($snap), 'key'));
    }
}

<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/ServiceCenter/BookingRefundPolicy.php';

/**
 * BookingRefundPolicy::calculate هي حساب الاسترداد حسب سياسة الإلغاء (فلوس العميل): نقطة القطع بالساعات
 * قبل بداية الرحلة بتوقيت الشركة، ونسبة الاسترداد، وأقصى مبلغ. دالة نقية فمفيش DB هنا.
 */
final class BookingRefundPolicyTest extends TestCase
{
    private function booking(array $policy, string $date = '2026-12-10', ?string $time = '10:00:00', $total = 1000): array
    {
        return [
            'pricing_json' => json_encode($policy),
            'start_date' => $date,
            'start_time' => $time,
            'total_amount' => $total,
        ];
    }

    private function start(string $date = '2026-12-10', string $time = '10:00:00', string $tz = 'UTC'): int
    {
        return (new DateTimeImmutable("$date $time", new DateTimeZone($tz)))->getTimestamp();
    }

    public function testFullRefundBeforeTheCutoff(): void
    {
        $at = $this->start() - 49 * 3600;
        $r = BookingRefundPolicy::calculate($this->booking(['cancellation_hours' => 48, 'refund_percent' => 100]), 'UTC', $at);

        $this->assertTrue($r['eligible']);
        $this->assertSame(100.0, (float) $r['refund_percent']);
        $this->assertSame(1000.0, (float) $r['maximum_policy_refund']);
        $this->assertSame(48, $r['cancellation_hours']);
    }

    public function testTheCutoffSecondIsEligibleAndOneSecondLaterIsNot(): void
    {
        $cutoff = $this->start() - 48 * 3600;
        $booking = $this->booking(['cancellation_hours' => 48, 'refund_percent' => 50]);

        $onTime = BookingRefundPolicy::calculate($booking, 'UTC', $cutoff);
        $late = BookingRefundPolicy::calculate($booking, 'UTC', $cutoff + 1);

        $this->assertTrue($onTime['eligible']);
        $this->assertSame(500.0, (float) $onTime['maximum_policy_refund']);
        $this->assertFalse($late['eligible']);
        $this->assertSame(0, (int) $late['refund_percent']);
        $this->assertSame(0.0, (float) $late['maximum_policy_refund']);
    }

    public function testMissingPolicyDefaultsTo24HoursAndZeroPercent(): void
    {
        $r = BookingRefundPolicy::calculate($this->booking([]), 'UTC', $this->start() - 30 * 3600);

        $this->assertSame(24, $r['cancellation_hours']);
        $this->assertTrue($r['eligible']);
        $this->assertSame(0.0, (float) $r['maximum_policy_refund']);
    }

    public function testInvalidPricingJsonFallsBackToDefaults(): void
    {
        $booking = ['pricing_json' => '{not json', 'start_date' => '2026-12-10', 'start_time' => '10:00:00', 'total_amount' => 500];

        $r = BookingRefundPolicy::calculate($booking, 'UTC', $this->start() - 100 * 3600);

        $this->assertSame(24, $r['cancellation_hours']);
        $this->assertSame(0.0, (float) $r['maximum_policy_refund']);
    }

    public function testPercentIsClampedAndHoursCannotBeNegative(): void
    {
        $at = $this->start() - 5 * 3600;

        $over = BookingRefundPolicy::calculate($this->booking(['cancellation_hours' => 1, 'refund_percent' => 150]), 'UTC', $at);
        $under = BookingRefundPolicy::calculate($this->booking(['cancellation_hours' => 1, 'refund_percent' => -20]), 'UTC', $at);
        $negHours = BookingRefundPolicy::calculate($this->booking(['cancellation_hours' => -9, 'refund_percent' => 100]), 'UTC', $at);

        $this->assertSame(100.0, (float) $over['refund_percent']);
        $this->assertSame(1000.0, (float) $over['maximum_policy_refund']);
        $this->assertSame(0.0, (float) $under['refund_percent']);
        $this->assertSame(0, $negHours['cancellation_hours']);
    }

    public function testCutoffIsComputedInTheCompanyTimezone(): void
    {
        // 10:00 بتوقيت القاهرة في ديسمبر = 08:00 UTC
        $r = BookingRefundPolicy::calculate($this->booking(['cancellation_hours' => 0, 'refund_percent' => 100]), 'Africa/Cairo', 0);

        $this->assertSame('2026-12-10T08:00:00+00:00', $r['cutoff_at']);
        $this->assertSame('Africa/Cairo', $r['timezone']);
    }

    public function testEmptyStartTimeMeansMidnight(): void
    {
        $r = BookingRefundPolicy::calculate($this->booking(['cancellation_hours' => 0], '2026-12-10', ''), 'UTC', 0);

        $this->assertSame('2026-12-10T00:00:00+00:00', $r['cutoff_at']);
    }

    public function testMaximumRefundIsRoundedToTwoDecimals(): void
    {
        $at = $this->start() - 100 * 3600;
        $r = BookingRefundPolicy::calculate($this->booking(['cancellation_hours' => 1, 'refund_percent' => 33.3], '2026-12-10', '10:00:00', 99.99), 'UTC', $at);

        $this->assertSame(33.3, round((float) $r['maximum_policy_refund'], 2));
    }

    public function testResultAlwaysRequiresHumanReview(): void
    {
        $r = BookingRefundPolicy::calculate($this->booking(['refund_percent' => 100]), 'UTC', 0);

        $this->assertTrue($r['requires_review']);
    }
}

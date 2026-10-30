<?php

use PHPUnit\Framework\TestCase;

/** Phase 9: cost model, budgets, provider choice, fraud guard, recording policy (pure rules). */
final class VoiceCostsSecurityTest extends TestCase
{
    public function testRatesIgnoreUnknownOrInsaneValues(): void
    {
        $r = VoiceCostModel::rates('{"inbound_per_min":0.01,"outbound_per_min":-1,"speech_per_min":"x","evil":5,"storage_per_gb_month":500}');
        $this->assertSame(['inbound_per_min' => 0.01], $r);
        $this->assertSame([], VoiceCostModel::rates('not json'));
    }

    public function testProviderCostWinsOverEstimateAndNoRateMeansUnknown(): void
    {
        $call = ['direction' => 'inbound', 'duration_s' => 61, 'mode' => 'turn', 'ai_cost_usd' => 0];
        $p = VoiceCostModel::components($call, 0.05, ['inbound_per_min' => 0.5], 20);
        $this->assertSame('provider', $p[0]['cost_source']);
        $this->assertSame(0.05, $p[0]['cost_usd']);
        $this->assertSame(0.06, $p[0]['price_usd']);
        $e = VoiceCostModel::components($call, null, ['inbound_per_min' => 0.01], 0);
        $this->assertSame('estimated', $e[0]['cost_source']);
        $this->assertSame(0.02, $e[0]['cost_usd']); // 61s = 2 started minutes
        $n = VoiceCostModel::components($call, null, [], 20);
        $this->assertSame('none', $n[0]['cost_source']);
        $this->assertSame(0.0, $n[0]['cost_usd']);
    }

    public function testAiAndSpeechComponents(): void
    {
        $rows = VoiceCostModel::components(['direction' => 'outbound', 'duration_s' => 120, 'mode' => 'turn', 'ai_cost_usd' => 0.02], 0.1, ['speech_per_min' => 0.01], 0);
        $this->assertSame(['outbound_minutes', 'speech', 'ai'], array_column($rows, 'component'));
        $this->assertSame([], VoiceCostModel::components(['direction' => 'inbound', 'duration_s' => 0, 'mode' => 'turn'], null, [], 0)); // an unanswered call costs nothing
    }

    public function testPricingRoundsUpNeverBelowCost(): void
    {
        $this->assertSame(0.000013, VoicePricing::applyPrecise(0.00001, 25));
        $this->assertNull(VoicePricing::applyPrecise(null, 25));
    }

    public function testRentalProration(): void
    {
        $d = VoiceCostModel::rentalDay(3.0, 4.5, 30);
        $this->assertSame(0.1, $d['cost_usd']);
        $this->assertSame(0.15, $d['price_usd']);
    }

    public function testBudgetStatesAndPolicy(): void
    {
        $this->assertSame('none', VoiceBudget::evaluate(50, null, 'block')['state']);
        $this->assertSame('ok', VoiceBudget::evaluate(10, 100, 'block')['state']);
        $w = VoiceBudget::evaluate(80, 100, 'block');
        $this->assertSame('warning', $w['state']);
        $this->assertFalse($w['blocked']);
        $this->assertSame([80], $w['alerts']);
        $x = VoiceBudget::evaluate(100, 100, 'block');
        $this->assertTrue($x['blocked']);
        $this->assertSame([80, 100], $x['alerts']);
        $this->assertFalse(VoiceBudget::evaluate(150, 100, 'warn')['blocked']);
        $this->assertSame([], VoiceBudget::evaluate(150, 100, 'warn', [80, 100])['alerts']); // each alert once
        $this->assertTrue(VoiceBudget::dailyMinutesExceeded(600, 10));
        $this->assertFalse(VoiceBudget::dailyMinutesExceeded(599, 10));
        $this->assertFalse(VoiceBudget::dailyMinutesExceeded(99999, 0));
    }

    public function testProviderSelection(): void
    {
        $order = ['twilio', 'telnyx'];
        $c = [['provider' => 'twilio', 'price' => 2.0, 'healthy' => true], ['provider' => 'telnyx', 'price' => 1.0, 'healthy' => true]];
        $this->assertSame(['provider' => 'telnyx', 'reason' => 'cheapest_healthy'], VoiceProviderSelector::choose($c, $order));
        $c[1]['healthy'] = false;
        $this->assertSame('twilio', VoiceProviderSelector::choose($c, $order)['provider']); // failover
        $this->assertSame('degraded_all_unhealthy', VoiceProviderSelector::choose([['provider' => 'twilio', 'price' => 2.0, 'healthy' => false], ['provider' => 'telnyx', 'price' => 1.0, 'healthy' => false]], $order)['reason']);
        $this->assertNull(VoiceProviderSelector::choose([['provider' => 'twilio', 'price' => null, 'healthy' => true]], $order));
        $this->assertSame('twilio', VoiceProviderSelector::choose([['provider' => 'telnyx', 'price' => 1.0, 'healthy' => true], ['provider' => 'twilio', 'price' => 1.0, 'healthy' => true]], $order)['provider']); // tie → registry order
        $this->assertTrue(VoiceProviderSelector::healthy(4));
        $this->assertFalse(VoiceProviderSelector::healthy(5));
    }

    public function testFraudGuard(): void
    {
        $this->assertTrue(VoiceFraudGuard::destinationAllowed('+201001234567', null));
        $this->assertFalse(VoiceFraudGuard::destinationAllowed('+19005551234', null));
        $this->assertFalse(VoiceFraudGuard::destinationAllowed('+8821234567', null));
        $this->assertFalse(VoiceFraudGuard::destinationAllowed('+99999999999', null)); // unknown country
        $this->assertFalse(VoiceFraudGuard::destinationAllowed('+201001234567', ['SA']));
        $this->assertTrue(VoiceFraudGuard::destinationAllowed('+966501234567', ['sa']));
        $this->assertFalse(VoiceFraudGuard::isFlood(7));
        $this->assertTrue(VoiceFraudGuard::isFlood(8));
        $this->assertTrue(VoiceFraudGuard::dialRateExceeded(30));
    }

    public function testRecordingNeedsCompanyAndPlatformCountry(): void
    {
        $on = ['recording_enabled' => 1];
        $this->assertFalse(VoiceRecordingPolicy::decide('EG', ['recording_enabled' => 0], ['EG'])['record']);
        $this->assertFalse(VoiceRecordingPolicy::decide('EG', $on, [])['record']); // default: nowhere
        $this->assertFalse(VoiceRecordingPolicy::decide(null, $on, ['EG'])['record']);
        $this->assertTrue(VoiceRecordingPolicy::decide('eg', $on, ['EG'])['record']);
        $this->assertSame(['EG', 'US'], VoiceRecordingPolicy::countries(' eg, US ,xx1,eg'));
        $this->assertSame('1970-01-31 00:00:00', VoiceRecordingPolicy::retentionUntil(30, 0));
        $this->assertSame('1971-01-01 00:00:00', VoiceRecordingPolicy::retentionUntil(9999, 0)); // capped at 365 days
    }

    public function testBudgetSettingsValidation(): void
    {
        $out = VoiceSettingsService::sanitizeSettings(['budget_policy' => 'warn', 'daily_minutes_cap' => 30, 'allowed_dial_countries' => 'eg, sa']);
        $this->assertSame('warn', $out['budget_policy']);
        $this->assertSame('EG,SA', $out['allowed_dial_countries']);
        $this->expectException(InvalidArgumentException::class);
        VoiceSettingsService::sanitizeSettings(['allowed_dial_countries' => 'KP']);
    }

    public function testMarginMaths(): void
    {
        $this->assertSame(['cost_usd' => 8.0, 'price_usd' => 10.0, 'margin_usd' => 2.0, 'margin_pct' => 20.0], VoiceAdminReports::margin(8, 10));
        $this->assertNull(VoiceAdminReports::margin(0, 0)['margin_pct']);
    }
}

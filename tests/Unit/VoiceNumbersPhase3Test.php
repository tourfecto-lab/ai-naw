<?php

/** AI Voice & Sales Agent (Phase 3): customer pricing with markup, requirement details, default number, source rules. No DB. */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/VoiceAgent/VoicePricing.php';
require_once __DIR__ . '/../../app/Services/VoiceAgent/VoiceMarkets.php';

final class VoiceNumbersPhase3Test extends TestCase
{
    public function testMarkupRoundsUpToTheCentAndNeverBelowCost(): void
    {
        $this->assertSame(1.15, VoicePricing::apply(1.15, 0));
        $this->assertSame(1.38, VoicePricing::apply(1.15, 20));   // 1.38 exactly
        $this->assertSame(1.35, VoicePricing::apply(1.0, 34.5));  // 1.345 -> up to 1.35
        $this->assertSame(0.01, VoicePricing::apply(0.001, 0));
        $this->assertNull(VoicePricing::apply(null, 50));
        $this->assertSame(0.0, VoicePricing::apply(0.0, 50));
        foreach ([0.5, 1.15, 3.333, 12.0] as $cost) {
            foreach ([0, 7.5, 40] as $pct) {
                $this->assertGreaterThanOrEqual($cost, VoicePricing::apply($cost, $pct));
            }
        }
    }

    public function testMarkupIsClamped(): void
    {
        $this->assertSame(0.0, VoicePricing::clamp(-20));
        $this->assertSame(500.0, VoicePricing::clamp(99999));
        $this->assertSame(6.0, VoicePricing::apply(1.0, 99999));
        $this->assertSame(1.0, VoicePricing::apply(1.0, -50));
    }

    public function testUsageRatesAreMarkedUpPerDirectionAndMissingStaysMissing(): void
    {
        $r = VoicePricing::applyRates(['inbound_per_min' => 0.0085, 'outbound_per_min' => null, 'currency' => 'USD'], 20);
        $this->assertSame(0.02, $r['inbound_per_min']);
        $this->assertNull($r['outbound_per_min']);
        $this->assertSame('USD', $r['currency']);
        $this->assertNull(VoicePricing::applyRates(null, 20));
    }

    public function testRequirementDetailsAreExplainedPerMarketAndAlwaysCarryTheDisclaimer(): void
    {
        foreach (['US', 'EG', 'SA', 'AE', 'GB', 'DE', 'ZZ'] as $iso) {
            $d = VoiceMarkets::requirementDetails($iso, 'local');
            $this->assertGreaterThanOrEqual(2, count($d), $iso);
            $this->assertStringContainsString('authoritative', end($d), $iso);
        }
        $this->assertStringContainsString('Business registration', implode(' ', VoiceMarkets::requirementDetails('EG', 'local')));
        $this->assertStringContainsString('local address', implode(' ', VoiceMarkets::requirementDetails('GB', 'local')));
        $this->assertStringContainsString('not known', implode(' ', VoiceMarkets::requirementDetails('ZZ', 'local')));
        $this->assertStringContainsString('Toll-free', implode(' ', VoiceMarkets::requirementDetails('US', 'toll_free')));
        $this->assertStringNotContainsString('Toll-free', implode(' ', VoiceMarkets::requirementDetails('US', 'local')));
    }

    public function testTheCompanyNeverSeesWhatTheProviderChargesTourfecto(): void
    {
        $svc = file_get_contents(dirname(__DIR__, 2) . '/app/Services/VoiceAgent/VoiceNumberService.php');
        $quote = substr($svc, strpos($svc, 'public function quote('), strpos($svc, 'public function approve(') - strpos($svc, 'public function quote('));
        $ret = substr($quote, strpos($quote, 'return ['));
        $this->assertStringNotContainsString('providerMonthly', $ret);
        $this->assertStringNotContainsString("'provider' =>", $ret);
        $this->assertStringContainsString("unset(\$r['verification_hash'], \$r['live_key'], \$r['provider_number_id'])", $svc);
        $mig = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/2026_10_31_000008_voice_numbers.sql');
        $this->assertStringContainsString('provider_monthly_cost', $mig);
        $this->assertStringContainsString('is_default TINYINT(1) NOT NULL DEFAULT 0', $mig);
    }

    public function testDefaultNumberRules(): void
    {
        $svc = file_get_contents(dirname(__DIR__, 2) . '/app/Services/VoiceAgent/VoiceNumberService.php');
        $this->assertSame(4, substr_count($svc, '$this->ensureDefault($tenantId)'));        // after buying, after connecting, after the admin attaches a platform number, after releasing the default
        $this->assertStringContainsString('UPDATE voice_numbers SET is_default = 0 WHERE tenant_user_id = ?', $svc);
        $this->assertStringContainsString('Only a number that is set up can be the default', $svc);
        $routes = file_get_contents(dirname(__DIR__, 2) . '/app/routes/voice_agent.php');
        $this->assertStringContainsString("/api/voice-agent/numbers/{id}/default", $routes);
    }

    public function testPurchaseCanBindTheAssistantAndTheTypeOfAConnectedNumberIsKept(): void
    {
        $svc = file_get_contents(dirname(__DIR__, 2) . '/app/Services/VoiceAgent/VoiceNumberService.php');
        $this->assertStringContainsString('?int $agentId = null): array', $svc);
        $this->assertStringContainsString('string $type = \'local\'): array', $svc);
        $this->assertStringContainsString("Unknown number type", $svc);
    }
}

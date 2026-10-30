<?php

/** AI Voice & Sales Agent (Phase 4 additions): capacity, stream parameters, booking consent rule, bridge control. No network, no DB. */

use PHPUnit\Framework\TestCase;

foreach (['VoiceHttpClient', 'VoiceProviderException', 'VoiceProviderAdapter', 'AbstractVoiceProviderAdapter', 'TwilioVoiceAdapter', 'TelnyxVoiceAdapter'] as $c) {
    require_once __DIR__ . '/../../app/Services/VoiceAgent/Telephony/' . $c . '.php';
}
foreach (['VoiceCapacity', 'VoiceBridgeAuth', 'VoiceSettingsService'] as $c) {
    require_once __DIR__ . '/../../app/Services/VoiceAgent/' . $c . '.php';
}
require_once __DIR__ . '/../Fixtures/VoiceFakeHttp.php';

final class VoiceInboundPhase4Test extends TestCase
{
    private function src(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    public function testCapacityLimitsTheCompanyFirstThenThePlatform(): void
    {
        $this->assertTrue(VoiceCapacity::decide(0, 3, 0, 50)['allowed']);
        $this->assertTrue(VoiceCapacity::decide(2, 3, 49, 50)['allowed']);
        $this->assertSame('company_capacity', VoiceCapacity::decide(3, 3, 3, 50)['reason']);
        $this->assertSame('platform_capacity', VoiceCapacity::decide(1, 3, 50, 50)['reason']);
        $this->assertSame('company_capacity', VoiceCapacity::decide(1, 0, 0, 50)['reason']); // a cap of 0 is treated as 1
    }

    public function testConcurrentCallSettingIsValidated(): void
    {
        $this->assertSame(['max_concurrent_calls' => 10], VoiceSettingsService::sanitizeSettings(['max_concurrent_calls' => '10']));
        foreach ([0, 51, 'x'] as $bad) {
            try {
                VoiceSettingsService::sanitizeSettings(['max_concurrent_calls' => $bad]);
                $this->fail('accepted ' . $bad);
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('between 1 and 50', $e->getMessage());
            }
        }
    }

    public function testInboundFallsBackPolitelyWhenSubscriptionEndsOrCapacityIsFull(): void
    {
        $s = $this->src('app/Services/VoiceAgent/VoiceInboundService.php');
        $this->assertStringContainsString("'subscription_inactive'", $s);
        $this->assertStringContainsString("'at_capacity'", $s);
        $this->assertStringContainsString('VoicePlatformLoad::activeCalls()', $s);
        // order matters: a lapsed plan is checked before anything that would use the AI
        $this->assertLessThan(strpos($s, 'VoiceBudgetService'), strpos($s, "'subscription_inactive'"));
    }

    public function testTwilioStreamTokenTravelsAsAParameterBecauseTwilioDropsQueryStrings(): void
    {
        $t = new TwilioVoiceAdapter(['account_sid' => 'AC1', 'auth_token' => 't'], new VoiceFakeHttp());
        $x = $t->inboundInstructions(['stream_url' => 'wss://bridge.test/stream', 'stream_params' => ['token' => 'a.b', 'bad key!' => 'x']])['body'];
        $this->assertStringContainsString('<Stream url="wss://bridge.test/stream"><Parameter name="token" value="a.b"/></Stream>', $x);
        $this->assertStringNotContainsString('bad key', $x);
        $this->assertStringNotContainsString('?t=', $x);
        $plain = $t->inboundInstructions(['stream_url' => 'wss://bridge.test/stream'])['body'];
        $this->assertStringContainsString('<Stream url="wss://bridge.test/stream"/>', $plain);
        $this->assertStringNotContainsString('?t=', $this->src('app/Services/VoiceAgent/VoiceInboundService.php'));
    }

    public function testTelnyxDoesNotClaimTheRealtimeStreamUntilItIsVerified(): void
    {
        $x = new TelnyxVoiceAdapter(['api_key' => 'k', 'connection_id' => 'c', 'public_key' => 'p'], new VoiceFakeHttp());
        $this->assertFalse($x->capabilities()['streaming']); // so a Telnyx call is never put in stream mode
        $this->assertFalse($x->capabilities()['outbound']); // inbound only in this version
    }

    public function testSignatureVectorMatchesTheNodeBridge(): void
    {
        // the same vector is asserted in voice-bridge/test/lib.test.js
        $this->assertSame('1c8f9d88a2f702e9a253782028e88bfe68a1f8b93217f549210d2d1b9fec9d69', VoiceBridgeAuth::signRequest('{"a":1}', 5000, 'sekret'));
    }

    public function testBookingRequestNeedsAClearYesAndIsNeverCalledConfirmed(): void
    {
        $p = $this->src('app/Services/VoiceAgent/VoicePromptBuilder.php');
        $this->assertStringContainsString('ONLY after the caller clearly says yes', $p);
        $this->assertStringContainsString('Never say a booking is confirmed', $p);
        $node = $this->src('voice-bridge/lib.js');
        $this->assertStringContainsString('only after the caller clearly agreed', $node);
    }

    public function testBridgeControlCannotBeUsedOutsideAnActiveStreamCall(): void
    {
        $c = $this->src('app/Controllers/VoiceBridgeController.php');
        $this->assertStringContainsString("\$call['mode'] !== 'stream'", $c);
        $this->assertStringContainsString("'transfer_call'", $c);
        $this->assertStringContainsString("'hangup_call'", $c);
        $this->assertStringContainsString("/api/voice-bridge/control", $this->src('app/routes/voice_agent.php'));
        // transfers go to the company's own configured line only, never to a number sent by the bridge
        $this->assertStringNotContainsString("\$x['body']['to']", $c);
    }

    public function testLatencyIsMeasuredPerReply(): void
    {
        $this->assertStringContainsString("'latency_ms_add'", $this->src('app/Services/VoiceAgent/VoiceInboundService.php'));
        $this->assertStringContainsString('latency_ms_total INT NOT NULL DEFAULT 0', $this->src('database/migrations/2026_10_31_000009_voice_calls.sql'));
    }
}

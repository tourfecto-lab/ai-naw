<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Fixtures/VoiceFakeHttp.php';

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

    public function testQuoteWithoutAProviderPicksTheCheapestAndFailsOver(): void
    {
        $env = ['TWILIO_VOICE_ACCOUNT_SID' => 'ACx', 'TWILIO_VOICE_AUTH_TOKEN' => 't', 'TELNYX_API_KEY' => 'k', 'TELNYX_CONNECTION_ID' => 'c', 'TELNYX_PUBLIC_KEY' => 'p'];
        foreach ($env as $k => $v) {
            putenv("$k=$v");
        }
        try {
            $mk = static fn (array $byProvider) => new class ($byProvider) extends VoiceProviderService {
                public function __construct(private array $byProvider)
                {
                }

                public function search(int $tenantId, string $provider, string $iso, string $type, array $filters = []): array
                {
                    $r = $this->byProvider[$provider];
                    if ($r instanceof Throwable) {
                        throw $r;
                    }
                    return $r;
                }
            };
            $n = static fn (float $m) => [['number' => '+201001234567', 'voice' => true, 'monthly_cost' => $m, 'setup_cost' => 0.0]];
            $pick = static function (VoiceProviderService $p): ?string {
                $m = new ReflectionMethod(VoiceNumberService::class, 'cheapestProvider');
                $m->setAccessible(true);
                return $m->invoke(new VoiceNumberService($p), 1, '+201001234567', 'EG', 'mobile');
            };
            $this->assertSame('telnyx', $pick($mk(['twilio' => $n(3.0), 'telnyx' => $n(1.0)])));
            $this->assertSame('twilio', $pick($mk(['twilio' => $n(3.0), 'telnyx' => new VoiceProviderException('down', 500, '')]))); // failover
            $this->assertNull($pick($mk(['twilio' => [], 'telnyx' => []])));
        } finally {
            foreach ($env as $k => $v) {
                putenv($k);
            }
        }
    }

    public function testTwilioRecordingStartFetchDeleteAndTelnyxIsHonestlyUnsupported(): void
    {
        $h = new VoiceFakeHttp();
        $a = new TwilioVoiceAdapter(['account_sid' => 'ACx', 'auth_token' => 't'], $h);
        $h->push(201, ['sid' => 'RE123']);
        $this->assertSame(['recording_id' => 'RE123'], $a->startRecording('CA1'));
        $this->assertStringContainsString('/Calls/CA1/Recordings.json', $h->calls[0]['url']);
        $h->push(200, 'AUDIO');
        $this->assertSame(['content_type' => 'audio/mpeg', 'body' => 'AUDIO'], $a->fetchRecording('RE123'));
        $h->push(404, '');
        try {
            $a->fetchRecording('RE404');
            $this->fail('a missing recording must not return audio');
        } catch (VoiceProviderException $e) {
            $this->assertSame(404, $e->httpCode);
        }
        $h->push(204, '');
        $this->assertTrue($a->deleteRecording('RE123'));
        $h->push(404, '');
        $this->assertTrue($a->deleteRecording('RE123')); // already gone
        $this->assertFalse(VoiceProviderService::capabilitiesOf('telnyx')['recording']);
        $this->expectException(VoiceProviderException::class);
        (new TelnyxVoiceAdapter(['api_key' => 'k', 'connection_id' => 'c', 'public_key' => 'p'], new VoiceFakeHttp()))->startRecording('x');
    }

    public function testNoticeAndRecordingShareOneDecision(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../app/Services/VoiceAgent/VoiceInboundService.php');
        $this->assertStringContainsString('$rec = VoiceRecordingService::decide(', $src);
        $this->assertStringContainsString("\$lang, \$rec['record'], \$agent['opening_text']", $src);
        $this->assertStringContainsString("if (\$rec['record'] && \$mode !== 'fallback')", $src);
        $this->assertStringNotContainsString("!empty(\$settings['recording_enabled']), \$agent['opening_text']", $src);
    }

    public function testPlatformSettingsValidationAndConfirmation(): void
    {
        $c = VoicePlatformSettings::sanitize(['voice_live_operations_enabled' => '1', 'voice_markup_pct' => '25', 'voice_recording_countries' => 'eg, sa', 'voice_platform_max_concurrent' => '50', 'unknown_key' => 'x', 'voice_rates_json' => '{"inbound_per_min":0.01}', 'voice_stream_url' => 'wss://bridge.example.com/ws']);
        $this->assertEquals(['voice_live_operations_enabled' => '1', 'voice_markup_pct' => '25', 'voice_recording_countries' => 'EG,SA', 'voice_platform_max_concurrent' => '50', 'voice_rates_json' => '{"inbound_per_min":0.01}', 'voice_stream_url' => 'wss://bridge.example.com/ws'], $c); // unknown keys are never stored
        $cur = ['voice_live_operations_enabled' => '0'];
        $this->assertSame(['voice_live_operations_enabled'], VoicePlatformSettings::dangerousChanges($c, $cur));
        $this->assertSame([], VoicePlatformSettings::dangerousChanges(['voice_live_operations_enabled' => '0'], ['voice_live_operations_enabled' => '1'])); // turning OFF never needs confirmation
        $this->assertSame([], VoicePlatformSettings::dangerousChanges(['voice_live_operations_enabled' => '1'], ['voice_live_operations_enabled' => '1']));
        $this->assertSame(['voice_marketing_countries'], VoicePlatformSettings::dangerousChanges(['voice_marketing_countries' => 'EG,SA'], ['voice_marketing_countries' => 'EG']));
        $this->assertSame([], VoicePlatformSettings::dangerousChanges(['voice_marketing_countries' => 'EG'], ['voice_marketing_countries' => 'EG,SA']));
    }

    /** @dataProvider badSettings */
    public function testPlatformSettingsRejectBadValues(array $in): void
    {
        $this->expectException(InvalidArgumentException::class);
        VoicePlatformSettings::sanitize($in);
    }

    public static function badSettings(): array
    {
        return [[['voice_markup_pct' => '900']], [['voice_platform_max_concurrent' => '0']], [['voice_recording_countries' => 'KP']], [['voice_stream_url' => 'http://x']],
            [['voice_rates_json' => '{"inbound_per_min":-1}']], [['voice_rates_json' => '{"evil":1}']], [['voice_sip_domain' => 'a b;rm']]];
    }

    public function testVoiceAccessLimitIsClamped(): void
    {
        $this->assertNull(VoiceFeatureAccess::clampMax(null));
        $this->assertSame(50, VoiceFeatureAccess::clampMax(9999));
        $this->assertSame(1, VoiceFeatureAccess::clampMax(0));
    }

    public function testTelnyxTexmlGreetingGatherAndStatusParsing(): void
    {
        $x = new TelnyxVoiceAdapter(['api_key' => 'k', 'connection_id' => 'c', 'public_key' => 'p'], new VoiceFakeHttp());
        $body = $x->turnInstructions(['say' => 'Hello & welcome', 'voice' => 'Polly.Joanna', 'language' => 'en-US', 'gather_url' => 'https://t.test/webhooks/voice/telnyx/5/turn?call=9&n=1&k=abc'])['body'];
        $this->assertStringContainsString('<Gather input="speech"', $body);
        $this->assertStringContainsString('Hello &amp; welcome', $body);
        $this->assertStringContainsString('&amp;k=abc', $body); // the token survives inside the XML attribute
        $ev = $x->parseStatusEvent([], '', ['CallSid' => 'CS1', 'CallStatus' => 'completed', 'CallDuration' => '75', 'From' => '+2010', 'To' => '+1415', 'Direction' => 'inbound']);
        $this->assertSame(['CS1', 'completed', 75, 'inbound'], [$ev['call_id'], $ev['status'], $ev['duration_s'], $ev['direction']]);
    }

    public function testTelnyxWebhookNeedsTheTokenOrASignature(): void
    {
        if (!defined('ENCRYPTION_KEY') || ENCRYPTION_KEY === '') {
            $this->markTestSkipped('no ENCRYPTION_KEY in this environment');
        }
        $x = new TelnyxVoiceAdapter(['api_key' => 'k', 'connection_id' => 'c', 'public_key' => base64_encode(str_repeat('a', 32))], new VoiceFakeHttp());
        $k = VoiceWebhookToken::make('telnyx');
        $this->assertSame(32, strlen($k));
        $this->assertTrue($x->verifyWebhook('https://t.test/webhooks/voice/telnyx/0/inbound?k=' . $k, [], '', []));
        $this->assertFalse($x->verifyWebhook('https://t.test/webhooks/voice/telnyx/0/inbound', [], '', []));
        $this->assertFalse($x->verifyWebhook('https://t.test/webhooks/voice/telnyx/0/inbound?k=' . strrev($k), [], '', []));
        $this->assertFalse($x->verifyWebhook('https://t.test/webhooks/voice/telnyx/0/inbound?k=', [], '', []));
        $this->assertNotSame($k, VoiceWebhookToken::make('twilio')); // a token is per provider
    }

    public function testPublicWebhookBaseMustBeAnHttpsOrigin(): void
    {
        foreach (['https://tourfectoai.pro', 'https://tourfectoai.pro/', 'https://voice.example.com:8443'] as $ok) {
            $this->assertTrue(VoicePublicUrl::valid($ok), $ok);
        }
        foreach (['http://tourfectoai.pro', 'https://tourfectoai.pro/app', 'https://x.pro?a=1', 'javascript:alert(1)', 'https://', 'tourfectoai.pro'] as $bad) {
            $this->assertFalse(VoicePublicUrl::valid($bad), $bad);
        }
        $this->assertSame(['voice_public_base_url' => 'https://tourfectoai.pro'], VoicePlatformSettings::sanitize(['voice_public_base_url' => 'https://tourfectoai.pro/']));
        $this->expectException(InvalidArgumentException::class);
        VoicePlatformSettings::sanitize(['voice_public_base_url' => 'http://insecure.test']);
    }

    public function testTelnyxWorksWithoutThePublicKeyAndStillRefusesUnauthenticatedWebhooks(): void
    {
        $get = static fn (string $k) => ['TELNYX_API_KEY' => 'KEY1', 'TELNYX_CONNECTION_ID' => '308'][$k] ?? '';
        $creds = VoicePlatformCredentials::forProvider('telnyx', $get);
        $this->assertSame(['api_key' => 'KEY1', 'connection_id' => '308'], $creds);
        $this->assertSame([], VoicePlatformCredentials::missing('telnyx', $get));
        $this->assertNull(VoicePlatformCredentials::forProvider('telnyx', static fn (string $k) => ['TELNYX_CONNECTION_ID' => '308'][$k] ?? '')); // the API key is still required
        $this->assertSame(['TELNYX_API_KEY'], VoicePlatformCredentials::missing('telnyx', static fn (string $k) => ['TELNYX_CONNECTION_ID' => '308'][$k] ?? ''));
        $x = new TelnyxVoiceAdapter($creds, new VoiceFakeHttp());
        $this->assertFalse($x->verifyWebhook('https://t.test/inbound', ['telnyx-signature-ed25519' => 'AAAA', 'telnyx-timestamp' => (string) time()], '{}', []));
        $this->assertEquals(['api_key' => 'K', 'connection_id' => 'C', 'public_key' => 'P'], VoiceProviderService::sanitizeCredentials('telnyx', ['api_key' => 'K', 'connection_id' => 'C', 'public_key' => 'P']));
        $this->assertSame(['api_key' => 'K', 'connection_id' => 'C'], VoiceProviderService::sanitizeCredentials('telnyx', ['api_key' => 'K', 'connection_id' => 'C']));
    }
}

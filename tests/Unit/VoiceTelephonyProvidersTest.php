<?php

/** AI Voice & Sales Agent (Phase 2): provider adapters against a fake transport, live guard, webhook signatures. No network, no DB. */

use PHPUnit\Framework\TestCase;

foreach (['VoiceHttpClient', 'VoiceProviderException', 'VoiceProviderAdapter', 'AbstractVoiceProviderAdapter', 'TwilioVoiceAdapter', 'TelnyxVoiceAdapter', 'VoiceProviderRegistry', 'VoiceLiveGuard', 'VoiceCostTracker'] as $c) {
    require_once __DIR__ . '/../../app/Services/VoiceAgent/Telephony/' . $c . '.php';
}
require_once __DIR__ . '/../../app/Services/VoiceAgent/VoiceProviderService.php';
require_once __DIR__ . '/../Fixtures/VoiceFakeHttp.php';

final class VoiceTelephonyProvidersTest extends TestCase
{
    private function twilio(VoiceFakeHttp $h): TwilioVoiceAdapter
    {
        return new TwilioVoiceAdapter(['account_sid' => 'ACtest', 'auth_token' => 'secret'], $h);
    }

    public function testRegistryKnowsOnlyRegisteredProviders(): void
    {
        $this->assertSame(['twilio', 'telnyx'], VoiceProviderRegistry::keys());
        $this->assertFalse(VoiceProviderRegistry::has('evil'));
        $this->expectException(InvalidArgumentException::class);
        VoiceProviderRegistry::classFor('evil');
    }

    public function testAdapterRefusesMissingCredentials(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TwilioVoiceAdapter(['account_sid' => 'AC'], new VoiceFakeHttp());
    }

    public function testCapabilitiesAreNotAssumedEqual(): void
    {
        $t = $this->twilio(new VoiceFakeHttp());
        $x = new TelnyxVoiceAdapter(['api_key' => 'k', 'connection_id' => 'c', 'public_key' => 'p'], new VoiceFakeHttp());
        $this->assertTrue($t->capabilities()['cost_lookup']);
        $this->assertFalse($x->capabilities()['cost_lookup']);
    }

    public function testTwilioConnectionTestUsesBasicAuthAndReadsStatus(): void
    {
        $h = (new VoiceFakeHttp())->push(200, ['status' => 'active', 'friendly_name' => 'Acme Tours']);
        $r = $this->twilio($h)->testConnection();
        $this->assertTrue($r['ok']);
        $this->assertSame(['ACtest', 'secret'], $h->calls[0]['basicAuth']);
        $this->assertSame('GET', $h->calls[0]['method']);
    }

    public function testProviderErrorIsNormalisedAndDoesNotLeakCredentials(): void
    {
        $h = (new VoiceFakeHttp())->push(401, ['code' => 20003, 'message' => 'Authenticate']);
        try {
            $this->twilio($h)->testConnection();
            $this->fail('expected exception');
        } catch (VoiceProviderException $e) {
            $this->assertSame(401, $e->httpCode);
            $this->assertSame('20003', $e->providerCode);
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function testTwilioSearchNormalisesNumbersAndPrices(): void
    {
        $h = (new VoiceFakeHttp())
            ->push(200, ['available_phone_numbers' => [['phone_number' => '+15125550100', 'capabilities' => ['voice' => true, 'SMS' => false], 'address_requirements' => 'none']]])
            ->push(200, ['price_unit' => 'USD', 'phone_number_prices' => [['number_type' => 'local', 'current_price' => '1.15']]]);
        $n = $this->twilio($h)->searchNumbers('us', 'local');
        $this->assertCount(1, $n);
        $this->assertSame(1.15, $n[0]['monthly_cost']);
        $this->assertSame('US', $n[0]['country']);
        $this->assertTrue($n[0]['voice']);
        $this->assertStringContainsString('/AvailablePhoneNumbers/US/Local.json', $h->calls[0]['url']);
    }

    public function testSearchRejectsBadCountryAndType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->twilio(new VoiceFakeHttp())->searchNumbers('USA', 'local');
    }

    public function testTwilioPriceIsNullWhenNotPublishedNotInvented(): void
    {
        $h = (new VoiceFakeHttp())
            ->push(200, ['available_phone_numbers' => [['phone_number' => '+201000000000', 'capabilities' => ['voice' => true]]]])
            ->push(404, ['message' => 'not found']);
        $this->assertNull($this->twilio($h)->searchNumbers('EG', 'mobile')[0]['monthly_cost']);
    }

    public function testTwilioStartCallBuildsRequestAndRequiresInstructionsUrl(): void
    {
        $h = (new VoiceFakeHttp())->push(201, ['sid' => 'CA1', 'status' => 'queued']);
        $r = $this->twilio($h)->startCall('+15125550100', '+201001234567', ['url' => 'https://x.test/i', 'record' => true, 'machine_detection' => true]);
        $this->assertSame('CA1', $r['call_id']);
        $this->assertSame('true', $h->calls[0]['body']['Record']);
        $this->assertSame('Enable', $h->calls[0]['body']['MachineDetection']);
        $this->expectException(InvalidArgumentException::class);
        $this->twilio(new VoiceFakeHttp())->startCall('+15125550100', '+201001234567', []);
    }

    public function testInvalidPhoneNumbersAreRejectedBeforeAnyRequest(): void
    {
        $h = new VoiceFakeHttp();
        try {
            $this->twilio($h)->startCall('abc', '+201001234567', ['url' => 'https://x.test/i']);
            $this->fail('expected exception');
        } catch (InvalidArgumentException $e) {
            $this->assertSame([], $h->calls);
        }
    }

    public function testTwilioInboundInstructionsStreamForwardAndSip(): void
    {
        $t = $this->twilio(new VoiceFakeHttp());
        $this->assertStringContainsString('<Connect><Stream url="wss://s.test/ws"/></Connect>', $t->inboundInstructions(['stream_url' => 'wss://s.test/ws'])['body']);
        $this->assertStringContainsString('<Dial>+201001234567</Dial>', $t->inboundInstructions(['forward_to' => '+201001234567'])['body']);
        $this->assertStringContainsString('<Sip>sip:a@b.test</Sip>', $t->inboundInstructions(['sip_uri' => 'sip:a@b.test'])['body']);
        $this->expectException(InvalidArgumentException::class);
        $t->inboundInstructions(['stream_url' => 'http://insecure.test']);
    }

    public function testTwilioWebhookSignatureAcceptsValidRejectsTamperedAndMissing(): void
    {
        $t = $this->twilio(new VoiceFakeHttp());
        $url = 'https://tourfecto.pro/webhooks/voice/twilio/7/status';
        $p = ['CallSid' => 'CA1', 'CallStatus' => 'completed', 'CallDuration' => '61'];
        $sig = base64_encode(hash_hmac('sha1', $url . 'CallDuration61CallSidCA1CallStatuscompleted', 'secret', true));
        $this->assertTrue($t->verifyWebhook($url, ['x-twilio-signature' => $sig], '', $p));
        $this->assertFalse($t->verifyWebhook($url, ['x-twilio-signature' => $sig], '', array_merge($p, ['CallDuration' => '1'])));
        $this->assertFalse($t->verifyWebhook($url, [], '', $p));
        $ev = $t->parseStatusEvent([], '', $p);
        $this->assertSame('completed', $ev['status']);
        $this->assertSame(61, $ev['duration_s']);
    }

    public function testTwilioCallCostIsAbsoluteAndNullUntilBilled(): void
    {
        $h = (new VoiceFakeHttp())->push(200, ['price' => '-0.0140', 'price_unit' => 'USD'])->push(200, ['price' => null]);
        $t = $this->twilio($h);
        $this->assertSame(0.014, $t->fetchCallCost('CA1')['cost_usd']);
        $this->assertNull($t->fetchCallCost('CA2'));
    }

    /** @requires extension sodium */
    public function testTelnyxWebhookEd25519SignatureAndReplayWindow(): void
    {
        $kp = sodium_crypto_sign_keypair();
        $pub = base64_encode(sodium_crypto_sign_publickey($kp));
        $x = new TelnyxVoiceAdapter(['api_key' => 'k', 'connection_id' => 'c', 'public_key' => $pub], new VoiceFakeHttp());
        $body = json_encode(['data' => ['id' => 'e1', 'event_type' => 'call.hangup', 'payload' => ['call_control_id' => 'v3:abc', 'direction' => 'incoming', 'from' => '+1', 'to' => '+2', 'start_time' => '2026-10-31T10:00:00Z', 'end_time' => '2026-10-31T10:01:30Z']]]);
        $ts = (string) time();
        $sig = base64_encode(sodium_crypto_sign_detached($ts . '|' . $body, sodium_crypto_sign_secretkey($kp)));
        $this->assertTrue($x->verifyWebhook('', ['telnyx-signature-ed25519' => $sig, 'telnyx-timestamp' => $ts], $body, []));
        $this->assertFalse($x->verifyWebhook('', ['telnyx-signature-ed25519' => $sig, 'telnyx-timestamp' => $ts], $body . ' ', []));
        $old = (string) (time() - 3600);
        $oldSig = base64_encode(sodium_crypto_sign_detached($old . '|' . $body, sodium_crypto_sign_secretkey($kp)));
        $this->assertFalse($x->verifyWebhook('', ['telnyx-signature-ed25519' => $oldSig, 'telnyx-timestamp' => $old], $body, []));
        $ev = $x->parseStatusEvent([], $body, []);
        $this->assertSame('completed', $ev['status']);
        $this->assertSame(90, $ev['duration_s']);
        $this->assertSame('inbound', $ev['direction']);
    }

    public function testTelnyxSearchUsesProviderCostInformation(): void
    {
        $h = (new VoiceFakeHttp())->push(200, ['data' => [['phone_number' => '+442071234567', 'features' => [['name' => 'voice']], 'cost_information' => ['monthly_cost' => '1.00', 'upfront_cost' => '0.50', 'currency' => 'USD']]]]);
        $x = new TelnyxVoiceAdapter(['api_key' => 'k', 'connection_id' => 'c', 'public_key' => 'p'], $h);
        $n = $x->searchNumbers('gb', 'national')[0];
        $this->assertSame(1.0, $n['monthly_cost']);
        $this->assertSame(0.5, $n['setup_cost']);
        $this->assertSame('unknown', $n['address_required']);
        $this->assertSame('Bearer k', $h->calls[0]['headers']['Authorization']);
    }

    public function testLiveGuardSimulatesUnlessEverythingIsExplicitlyEnabled(): void
    {
        $on = ['enabled' => 1, 'sandbox_mode' => 0];
        $this->assertSame('platform_live_disabled', VoiceLiveGuard::decide('start_call', $on, false, true)['reason']);
        $this->assertSame('module_disabled', VoiceLiveGuard::decide('start_call', ['enabled' => 0, 'sandbox_mode' => 0], true, true)['reason']);
        $this->assertSame('sandbox_mode', VoiceLiveGuard::decide('start_call', ['enabled' => 1, 'sandbox_mode' => 1], true, true)['reason']);
        $this->assertSame('sandbox_mode', VoiceLiveGuard::decide('start_call', ['enabled' => 1], true, true)['reason']);
        $this->assertSame('approval_required', VoiceLiveGuard::decide('purchase_number', $on, true, false)['reason']);
        $this->assertTrue(VoiceLiveGuard::decide('purchase_number', $on, true, true)['live']);
        $this->assertTrue(VoiceLiveGuard::decide('hangup_call', $on, true, false)['live']);
        $this->assertFalse(VoiceLiveGuard::decide('start_call', [], true, true)['live']);
    }

    public function testSimulationIsDeterministicAndMarkedSimulated(): void
    {
        $a = VoiceLiveGuard::simulate('purchase_number', ['number' => '+15125550100']);
        $this->assertSame($a, VoiceLiveGuard::simulate('purchase_number', ['number' => '+15125550100']));
        $this->assertSame('simulated', $a['status']);
        $this->assertStringStartsWith('SIM-', $a['number_id']);
    }

    public function testCostEstimateBillsStartedMinutes(): void
    {
        $this->assertSame(0.0, VoiceCostTracker::estimate(0, 0.01));
        $this->assertSame(0.02, VoiceCostTracker::estimate(61, 0.01));
        $this->assertSame(0.01, VoiceCostTracker::estimate(60, 0.01));
    }

    public function testCredentialSanitizingKeepsOnlyRequiredFieldsAndMasks(): void
    {
        $c = VoiceProviderService::sanitizeCredentials('twilio', ['account_sid' => ' AC1 ', 'auth_token' => 't', 'evil' => 'x']);
        $this->assertSame(['account_sid' => 'AC1', 'auth_token' => 't'], $c);
        $this->assertSame('******9999', VoiceProviderService::mask('abcd9999'));
        $this->assertSame('****', VoiceProviderService::mask('ab'));
        $this->expectException(InvalidArgumentException::class);
        VoiceProviderService::sanitizeCredentials('twilio', ['account_sid' => 'AC1']);
    }

    public function testMigrationIsAdditiveAndEncryptsCredentials(): void
    {
        $sql = file_get_contents(__DIR__ . '/../../database/migrations/2026_10_31_000007_voice_telephony_providers.sql');
        $this->assertStringNotContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString('credentials_enc', $sql);
        $this->assertStringContainsString('UNIQUE KEY uq_voice_event (provider, event_id)', $sql);
    }
}

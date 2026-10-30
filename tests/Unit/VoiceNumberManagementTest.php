<?php

/** AI Voice & Sales Agent (Phase 3): number rules, markets, owned-number lookup and usage rates. No network, no DB. */

use PHPUnit\Framework\TestCase;

foreach (['VoiceHttpClient', 'VoiceProviderException', 'VoiceProviderAdapter', 'AbstractVoiceProviderAdapter', 'TwilioVoiceAdapter', 'TelnyxVoiceAdapter'] as $c) {
    require_once __DIR__ . '/../../app/Services/VoiceAgent/Telephony/' . $c . '.php';
}
require_once __DIR__ . '/../../app/Services/VoiceAgent/VoiceMarkets.php';
require_once __DIR__ . '/../../app/Services/VoiceAgent/VoiceNumberRules.php';
if (!class_exists('VoiceFakeHttp')) {
    require_once __DIR__ . '/VoiceTelephonyProvidersTest.php';
}

final class VoiceNumberManagementTest extends TestCase
{
    public function testE164NormalisationAcceptsFormattedAndRejectsLocalFormats(): void
    {
        $this->assertSame('+201001234567', VoiceNumberRules::normalizeE164('+20 100 123-4567'));
        foreach (['01001234567', '+0123456789', '+12', 'abc', '+1234567890123456'] as $bad) {
            try {
                VoiceNumberRules::normalizeE164($bad);
                $this->fail("accepted $bad");
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testFirstChargeIsMonthlyPlusSetupAndNullWhenNoPublishedPrice(): void
    {
        $this->assertSame(2.65, VoiceNumberRules::firstCharge(1.15, 1.5));
        $this->assertSame(1.15, VoiceNumberRules::firstCharge(1.15, null));
        $this->assertNull(VoiceNumberRules::firstCharge(null, 1.0));
    }

    public function testApprovalMustMatchTheQuotedAmountExactly(): void
    {
        $this->assertTrue(VoiceNumberRules::approvalMatches(2.65, '2.65'));
        $this->assertTrue(VoiceNumberRules::approvalMatches(2.65, 2.650));
        $this->assertFalse(VoiceNumberRules::approvalMatches(2.65, 1.15));
        $this->assertFalse(VoiceNumberRules::approvalMatches(2.65, 'abc'));
        $this->assertFalse(VoiceNumberRules::approvalMatches(2.65, null));
        $this->assertFalse(VoiceNumberRules::approvalMatches(null, 0));
    }

    public function testQuoteExpiresAfterFifteenMinutes(): void
    {
        $this->assertFalse(VoiceNumberRules::quoteExpired(1000, 1000 + 900));
        $this->assertTrue(VoiceNumberRules::quoteExpired(1000, 1000 + 901));
    }

    public function testRequirementsNeedAcknowledgementUnlessClearlyNone(): void
    {
        $this->assertFalse(VoiceNumberRules::assessRequirements('US', 'none')['needs_ack']);
        $this->assertTrue(VoiceNumberRules::assessRequirements('US', 'any')['needs_ack']);
        $this->assertTrue(VoiceNumberRules::assessRequirements('US', 'unknown')['needs_ack']);
        $this->assertTrue(VoiceNumberRules::assessRequirements('EG', 'none')['needs_ack']);
        $this->assertTrue(VoiceNumberRules::assessRequirements('GB', 'none')['needs_ack']);
        $this->assertTrue(VoiceNumberRules::assessRequirements('ZZ', 'none')['needs_ack']);
        $this->assertSame('unknown', VoiceNumberRules::assessRequirements('ZZ', 'none')['level']);
    }

    public function testMaxNumbersFallsBackToDefaultAndIsCapped(): void
    {
        $this->assertSame(3, VoiceNumberRules::maxNumbers(null));
        $this->assertSame(3, VoiceNumberRules::maxNumbers(true));
        $this->assertSame(3, VoiceNumberRules::maxNumbers(0));
        $this->assertSame(10, VoiceNumberRules::maxNumbers('10'));
        $this->assertSame(100, VoiceNumberRules::maxNumbers(5000));
    }

    public function testVerificationCodeIsSixDigitsBoundToTheNumberAndNeverStoredPlain(): void
    {
        $code = VoiceNumberRules::newVerificationCode();
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $code);
        $hash = VoiceNumberRules::hashCode($code, 7);
        $this->assertTrue(VoiceNumberRules::codeMatches($hash, $code, 7));
        $this->assertFalse(VoiceNumberRules::codeMatches($hash, $code, 8));
        $this->assertFalse(VoiceNumberRules::codeMatches($hash, '000000' === $code ? '111111' : '000000', 7));
        $this->assertFalse(VoiceNumberRules::codeMatches($hash, 'abcdef', 7));
        $this->assertStringNotContainsString($code, $hash);
    }

    public function testVerificationTwimlSpeaksDigitsSeparately(): void
    {
        $x = VoiceNumberRules::verificationTwiml('123456');
        $this->assertStringContainsString('1 2 3 4 5 6', $x);
        $this->assertStringContainsString('<Response>', $x);
    }

    public function testLiveUsableRejectsSimulatedAndSandboxVerifiedNumbers(): void
    {
        $this->assertTrue(VoiceNumberRules::liveUsable(['status' => 'active', 'source' => 'purchased', 'provider_number_id' => 'PN123']));
        $this->assertFalse(VoiceNumberRules::liveUsable(['status' => 'active', 'source' => 'purchased', 'provider_number_id' => 'SIM-abc']));
        $this->assertFalse(VoiceNumberRules::liveUsable(['status' => 'active', 'source' => 'purchased', 'provider_number_id' => '']));
        $this->assertTrue(VoiceNumberRules::liveUsable(['status' => 'active', 'source' => 'connected', 'verification_mode' => 'live']));
        $this->assertFalse(VoiceNumberRules::liveUsable(['status' => 'active', 'source' => 'connected', 'verification_mode' => 'sandbox']));
        $this->assertFalse(VoiceNumberRules::liveUsable(['status' => 'awaiting_link_test', 'source' => 'connected', 'verification_mode' => 'live']));
    }

    public function testMarketsCoverTargetCountriesAndResolveCallingCodes(): void
    {
        $isos = array_column(VoiceMarkets::targets(), 'iso');
        foreach (['US', 'EG', 'SA', 'AE', 'GB', 'DE', 'FR'] as $c) {
            $this->assertTrue(in_array($c, $isos, true), $c);
        }
        $this->assertSame('EG', VoiceMarkets::countryOf('+201001234567'));
        $this->assertSame('SA', VoiceMarkets::countryOf('+966501234567'));
        $this->assertSame('AE', VoiceMarkets::countryOf('+971501234567'));
        $this->assertSame('GB', VoiceMarkets::countryOf('+442071234567'));
        $this->assertSame('IE', VoiceMarkets::countryOf('+353851234567'));
        $this->assertNull(VoiceMarkets::countryOf('+9999999999'));
        $this->assertSame('none_typical', VoiceMarkets::requirementLevel('us'));
    }

    public function testConnectionStepsExistInBothLanguagesForEveryMethod(): void
    {
        foreach (VoiceNumberRules::METHODS as $m) {
            $s = VoiceNumberRules::connectionSteps($m, '+201001234567', '+15125550100');
            $this->assertTrue(count($s['ar']) >= 2 && count($s['en']) >= 2, $m);
        }
        $this->assertStringContainsString('+15125550100', VoiceNumberRules::connectionSteps('forward', '+201001234567', '+15125550100')['en'][1]);
        $this->assertStringContainsString('not configured', VoiceNumberRules::connectionSteps('sip', '+201001234567', null)['en'][1]);
    }

    public function testPortingIsNeverPromisedAsAutomatic(): void
    {
        $this->assertStringContainsString('nothing is ported automatically', VoiceNumberRules::connectionSteps('port', '+201001234567', null)['en'][2]);
    }

    public function testTwilioListsOwnedNumbersForOwnershipProof(): void
    {
        $h = (new VoiceFakeHttp())->push(200, ['incoming_phone_numbers' => [['phone_number' => '+15125550100', 'sid' => 'PN1']]]);
        $a = new TwilioVoiceAdapter(['account_sid' => 'AC1', 'auth_token' => 't'], $h);
        $this->assertSame([['number' => '+15125550100', 'number_id' => 'PN1']], $a->listOwnedNumbers());
    }

    public function testTelnyxListsOwnedNumbers(): void
    {
        $h = (new VoiceFakeHttp())->push(200, ['data' => [['phone_number' => '+442071234567', 'id' => 'tn1']]]);
        $a = new TelnyxVoiceAdapter(['api_key' => 'k', 'connection_id' => 'c', 'public_key' => 'p'], $h);
        $this->assertSame('tn1', $a->listOwnedNumbers()[0]['number_id']);
    }

    public function testTwilioUsageRatesUseWorstCaseOutboundAndNullWhenUnavailable(): void
    {
        $h = (new VoiceFakeHttp())
            ->push(200, ['price_unit' => 'USD', 'inbound_call_prices' => [['number_type' => 'local', 'current_price' => '0.0085']], 'outbound_prefix_prices' => [['current_price' => '0.02'], ['current_price' => '0.35']]])
            ->push(404, ['message' => 'nope']);
        $a = new TwilioVoiceAdapter(['account_sid' => 'AC1', 'auth_token' => 't'], $h);
        $r = $a->usageRates('EG', 'local');
        $this->assertSame(0.0085, $r['inbound_per_min']);
        $this->assertSame(0.35, $r['outbound_per_min']);
        $this->assertNull($a->usageRates('EG', 'local'));
        $x = new TelnyxVoiceAdapter(['api_key' => 'k', 'connection_id' => 'c', 'public_key' => 'p'], new VoiceFakeHttp());
        $this->assertNull($x->usageRates('US', 'local'));
    }

    public function testOnlyTwilioCanDeliverInlineVerificationCalls(): void
    {
        $h = (new VoiceFakeHttp())->push(201, ['sid' => 'CA9', 'status' => 'queued']);
        $a = new TwilioVoiceAdapter(['account_sid' => 'AC1', 'auth_token' => 't'], $h);
        $this->assertTrue($a->capabilities()['inline_voice']);
        $a->startCall('+15125550100', '+201001234567', ['twiml' => '<Response/>']);
        $this->assertSame('<Response/>', $h->calls[0]['body']['Twiml']);
        $this->assertTrue(!isset($h->calls[0]['body']['Url']));
        $x = new TelnyxVoiceAdapter(['api_key' => 'k', 'connection_id' => 'c', 'public_key' => 'p'], new VoiceFakeHttp());
        $this->assertFalse($x->capabilities()['inline_voice']);
    }

    public function testMigrationPreventsNumberSquattingAndIsAdditive(): void
    {
        $sql = file_get_contents(__DIR__ . '/../../database/migrations/2026_10_31_000008_voice_numbers.sql');
        $this->assertStringContainsString('UNIQUE KEY uq_voice_number_live (live_key)', $sql);
        $this->assertStringNotContainsString('UNIQUE KEY uq_voice_number_e164', $sql);
        $this->assertStringNotContainsString('ALTER TABLE', $sql);
        $this->assertStringContainsString("ENUM('none','live','sandbox')", $sql);
    }
}

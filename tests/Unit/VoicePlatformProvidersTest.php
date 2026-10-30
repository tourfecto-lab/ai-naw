<?php

/** AI Voice & Sales Agent (Phase 2, platform-owned accounts): platform credentials, sub-accounts, admin reports, registry. No network, no DB. */

use PHPUnit\Framework\TestCase;

foreach (['VoiceHttpClient', 'VoiceProviderException', 'VoiceProviderAdapter', 'AbstractVoiceProviderAdapter', 'TwilioVoiceAdapter', 'TelnyxVoiceAdapter', 'VoiceProviderRegistry', 'VoiceLiveGuard'] as $c) {
    require_once __DIR__ . '/../../app/Services/VoiceAgent/Telephony/' . $c . '.php';
}
require_once __DIR__ . '/../../app/Services/VoiceAgent/VoicePlatformCredentials.php';
require_once __DIR__ . '/../../app/Services/VoiceAgent/VoiceAdminReports.php';
require_once __DIR__ . '/../Fixtures/VoiceFakeHttp.php';

final class VoicePlatformProvidersTest extends TestCase
{
    private function env(array $set): callable
    {
        return static fn (string $k): string => $set[$k] ?? '';
    }

    public function testPlatformCredentialsNeedEveryKeyAndReportOnlyNamesOfMissingOnes(): void
    {
        $this->assertSame(['account_sid' => 'AC1', 'auth_token' => 't'], VoicePlatformCredentials::forProvider('twilio', $this->env(['TWILIO_VOICE_ACCOUNT_SID' => ' AC1 ', 'TWILIO_VOICE_AUTH_TOKEN' => 't'])));
        $this->assertNull(VoicePlatformCredentials::forProvider('twilio', $this->env(['TWILIO_VOICE_ACCOUNT_SID' => 'AC1'])));
        $this->assertSame(['TELNYX_CONNECTION_ID'], VoicePlatformCredentials::missing('telnyx', $this->env(['TELNYX_API_KEY' => 'k'])));
        $this->assertNull(VoicePlatformCredentials::forProvider('nobody'));
        $this->assertSame([], VoicePlatformCredentials::envKeys('nobody'));
    }

    public function testEveryRegisteredProviderHasPlatformKeysMatchingItsAdapterFields(): void
    {
        foreach (VoiceProviderRegistry::keys() as $k) {
            $class = VoiceProviderRegistry::classFor($k);
            $fields = array_merge($class::credentialFields(), $class::optionalCredentialFields());
            sort($fields);
            $mapped = array_keys(VoicePlatformCredentials::MAP[$k]);
            sort($mapped);
            $this->assertSame($fields, $mapped, $k);
        }
    }

    public function testAdminTestIsReadOnlyAndNeverEchoesTheKeys(): void
    {
        $env = $this->env(['TWILIO_VOICE_ACCOUNT_SID' => 'ACsecretsid', 'TWILIO_VOICE_AUTH_TOKEN' => 'supersecrettoken']);
        $ok = VoicePlatformCredentials::adminTest('twilio_voice', $env, (new VoiceFakeHttp())->push(200, ['status' => 'active', 'friendly_name' => 'Tourfecto']));
        $this->assertTrue($ok['ok']);
        $h = (new VoiceFakeHttp())->push(401, ['code' => 20003, 'message' => 'Authenticate']);
        $bad = VoicePlatformCredentials::adminTest('twilio_voice', $env, $h);
        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString('HTTP 401', $bad['message']);
        $this->assertStringNotContainsString('supersecrettoken', $bad['message']);
        $this->assertSame('GET', $h->calls[0]['method']);
        $missing = VoicePlatformCredentials::adminTest('telnyx_voice', $this->env([]), new VoiceFakeHttp());
        $this->assertFalse($missing['ok']);
        $this->assertStringContainsString('TELNYX_API_KEY', $missing['message']);
        $this->assertFalse(VoicePlatformCredentials::adminTest('stripe', $env, new VoiceFakeHttp())['ok']);
    }

    public function testTwilioCreatesAnIsolatedSubaccountWithItsOwnKeys(): void
    {
        $h = (new VoiceFakeHttp())->push(201, ['sid' => 'ACsub1', 'auth_token' => 'subtoken']);
        $a = new TwilioVoiceAdapter(['account_sid' => 'ACmaster', 'auth_token' => 'mastertoken'], $h);
        $r = $a->createSubaccount('Tourfecto company 7');
        $this->assertSame('ACsub1', $r['account_id']);
        $this->assertSame(['account_sid' => 'ACsub1', 'auth_token' => 'subtoken'], $r['credentials']);
        $this->assertSame('POST', $h->calls[0]['method']);
        $this->assertStringEndsWith('/Accounts.json', $h->calls[0]['url']);
        $this->assertSame(['ACmaster', 'mastertoken'], $h->calls[0]['basicAuth']);
        $this->assertTrue($a->capabilities()['subaccounts']);
        $bad = new TwilioVoiceAdapter(['account_sid' => 'AC', 'auth_token' => 't'], (new VoiceFakeHttp())->push(201, ['sid' => 'ACx']));
        $this->expectException(VoiceProviderException::class);
        $bad->createSubaccount('x');
    }

    public function testSubaccountCallsAreMadeAsTheCompanyNotAsThePlatform(): void
    {
        $h = (new VoiceFakeHttp())->push(201, ['sid' => 'CA1', 'status' => 'queued']);
        $sub = new TwilioVoiceAdapter(['account_sid' => 'ACsub1', 'auth_token' => 'subtoken'], $h);
        $sub->startCall('+15125550100', '+201001234567', ['url' => 'https://x.test/i']);
        $this->assertStringContainsString('/Accounts/ACsub1/Calls.json', $h->calls[0]['url']);
        $this->assertSame(['ACsub1', 'subtoken'], $h->calls[0]['basicAuth']);
    }

    public function testTelnyxHasNoSubaccountsAndSaysSo(): void
    {
        $x = new TelnyxVoiceAdapter(['api_key' => 'k', 'connection_id' => 'c', 'public_key' => 'p'], new VoiceFakeHttp());
        $this->assertFalse($x->capabilities()['subaccounts']);
        $this->expectException(LogicException::class);
        $x->createSubaccount('x');
    }

    public function testProvisioningIsALiveOperationThatSimulatesInSandbox(): void
    {
        $this->assertContains('create_subaccount', VoiceLiveGuard::MUTATING);
        $this->assertNotContains('create_subaccount', VoiceLiveGuard::NEEDS_APPROVAL);
        $on = ['enabled' => 1, 'sandbox_mode' => 0];
        $this->assertSame('platform_live_disabled', VoiceLiveGuard::decide('create_subaccount', $on, false, false)['reason']);
        $this->assertSame('sandbox_mode', VoiceLiveGuard::decide('create_subaccount', ['enabled' => 1, 'sandbox_mode' => 1], true, false)['reason']);
        $this->assertTrue(VoiceLiveGuard::decide('create_subaccount', $on, true, false)['live']);
        $sim = VoiceLiveGuard::simulate('create_subaccount', ['x' => 1]);
        $this->assertSame('simulated', $sim['status']);
        $this->assertStringStartsWith('SIM-', $sim['account_id']);
    }

    public function testCostSummaryFillsMissingPartsAndRanksByTotal(): void
    {
        $r = VoiceAdminReports::summarise('2026-11', [
            ['tenant_user_id' => 7, 'inbound_calls_usd' => 1.5, 'ai_usd' => 0.25],
            ['tenant_user_id' => 9, 'outbound_calls_usd' => 4.0, 'number_rental_usd' => 2.0, 'ai_usd' => 1.0],
        ]);
        $this->assertSame(9, $r['companies'][0]['tenant_user_id']);
        $this->assertSame(7.0, $r['companies'][0]['total_usd']);
        $this->assertSame(0.0, $r['companies'][1]['outbound_calls_usd']);
        $this->assertSame(1.75, $r['companies'][1]['total_usd']);
        $this->assertSame(8.75, $r['totals']['total_usd']);
        $this->assertSame(1.5, $r['totals']['inbound_calls_usd']);
    }

    public function testRegistryShowsBothProvidersInAdminIntegrationsWithTheSameKeysTheServiceReads(): void
    {
        $reg = require dirname(__DIR__, 2) . '/app/Config/integrations.php';
        foreach (VoicePlatformCredentials::INTEGRATIONS as $integration => $provider) {
            $this->assertArrayHasKey($integration, $reg);
            $this->assertSame(array_values(VoicePlatformCredentials::MAP[$provider]), $reg[$integration]['env_keys'], $integration);
            $this->assertSame('voice', $reg[$integration]['category']);
        }
        $ctl = file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/IntegrationsController.php');
        $this->assertStringContainsString("case 'twilio_voice':", $ctl);
        $this->assertStringContainsString('VoicePlatformCredentials::adminTest', $ctl);
    }

    public function testAccountModelSourceRules(): void
    {
        $svc = file_get_contents(dirname(__DIR__, 2) . '/app/Services/VoiceAgent/VoiceProviderService.php');
        $this->assertStringContainsString("voice_allow_own_provider_accounts', '0')", $svc);                       // own keys off by default
        $this->assertStringContainsString('bringing your own provider account is not enabled', $svc);
        $this->assertStringContainsString("AND mode = 'own'", $svc);                                              // a company can never delete its platform link
        $this->assertSame(1, substr_count($svc, '$this->ensureProvisioned('));                                    // only in guarded()'s live branch
        $this->assertLessThan(strpos($svc, '$this->ensureProvisioned('), strpos($svc, 'if (!$d[\'live\'])'));
        $mig = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/2026_10_31_000007_voice_telephony_providers.sql');
        $this->assertStringContainsString("mode ENUM('platform','own') NOT NULL DEFAULT 'platform'", $mig);
        $this->assertStringContainsString('provider_account_id', $mig);
    }
}

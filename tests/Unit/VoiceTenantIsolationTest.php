<?php

/**
 * AI Voice & Sales Agent: multi-tenant isolation guard. Static, no DB.
 * Every SQL statement in the voice module that touches a voice_* table must carry tenant_user_id. The only exceptions are listed
 * below, each with the reason it is safe; adding a new one needs a reason a reviewer can check.
 */

use PHPUnit\Framework\TestCase;

final class VoiceTenantIsolationTest extends TestCase
{
    /** statement prefix => why it is allowed to omit tenant_user_id in that literal */
    private const ALLOWED = [
        'UPDATE voice_calls SET ' => 'dynamic column list; VoiceDataStore::updateCall appends "WHERE id = ? AND tenant_user_id = ?" (asserted below)',
        "SELECT * FROM voice_outbound_jobs WHERE status = 'dialing'" => 'cron worker reaper: intentionally cross-tenant, each row is then handled under its own tenant id',
        "SELECT * FROM voice_outbound_jobs WHERE status = 'scheduled'" => 'cron worker queue: intentionally cross-tenant, each claimed row is handled under its own tenant id',
    ];

    /** @return list<string> */
    private function files(): array
    {
        $root = dirname(__DIR__, 2);
        return array_merge(glob($root . '/app/Services/VoiceAgent/*.php'), glob($root . '/app/Controllers/Voice*.php'), glob($root . '/cron/voice_*.php'));
    }

    public function testEverySqlStatementOnAVoiceTableCarriesTheTenant(): void
    {
        $problems = [];
        $checked = 0;
        foreach ($this->files() as $f) {
            if (in_array(basename($f), ['VoiceAdminReports.php', 'VoicePlatformLoad.php'], true)) {
                continue; // the one deliberately cross-company class; see testCrossCompanyReportsAreAdminOnly; VoicePlatformLoad returns one count only
            }
            foreach (token_get_all(file_get_contents($f)) as $t) {
                if (!is_array($t) || !in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }
                if (!preg_match('/\b(?:FROM|INTO|UPDATE|JOIN)\s+voice_\w+/i', $t[1])) {
                    continue;
                }
                $checked++;
                if (stripos($t[1], 'tenant_user_id') !== false) {
                    continue;
                }
                $body = trim($t[1], "'\"");
                $ok = false;
                foreach (array_keys(self::ALLOWED) as $prefix) {
                    $ok = $ok || strpos($body, $prefix) === 0;
                }
                if (!$ok) {
                    $problems[] = basename($f) . ':' . $t[2] . ' ' . preg_replace('/\s+/', ' ', substr($body, 0, 90));
                }
            }
        }
        $this->assertGreaterThan(80, $checked, 'the scanner should see the voice SQL');
        $this->assertSame([], $problems, "voice SQL without tenant scoping:\n" . implode("\n", $problems));
    }

    public function testTheDynamicCallUpdateIsTenantScoped(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/app/Services/VoiceAgent/VoiceDataStore.php');
        $this->assertStringContainsString("' WHERE id = ? AND tenant_user_id = ?'", $src);
    }

    public function testNoControllerAcceptsATenantIdFromTheRequest(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/app/Controllers/Voice*.php') as $f) {
            $src = file_get_contents($f);
            $this->assertDoesNotMatchRegularExpression('/get\(\s*[\'"]tenant(_user)?(_id)?[\'"]/', $src, basename($f));
        }
        // the only place a tenant id comes from a URL is the signed provider webhook, which is verified with that tenant's own credentials
        $web = file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/VoiceWebhookController.php');
        $this->assertStringContainsString("\$params['tenant']", $web);
    }

    public function testNumberLevelWritesAreAlwaysTenantScoped(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/app/Services/VoiceAgent/VoiceNumberService.php');
        $this->assertStringContainsString('function recordInboundArrival(int $tenantId, string $e164)', $src);
        $this->assertDoesNotMatchRegularExpression('/UPDATE voice_numbers[^"\']*WHERE e164=\? AND/', $src);
    }

    public function testAuditReusesThePlatformActivityLogInsteadOfItsOwnTable(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/app/Services/VoiceAgent/VoiceAuditService.php');
        $this->assertStringContainsString("ActivityLog::record('voice'", $src);
        $this->assertStringNotContainsString('INSERT INTO', $src);
        $mig = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/2026_10_31_000006_voice_agent_foundation.sql');
        $this->assertStringNotContainsString('voice_audit_log', $mig);
    }

    public function testVoiceFeaturesAreRegisteredInThePlanCatalogueAndOffByDefault(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/app/Services/PlanFeature.php');
        $this->assertMatchesRegularExpression("/'voice_agent' => \[[^\]]*'type' => 'boolean'/s", $src);
        $this->assertMatchesRegularExpression("/'voice_max_numbers' => \[[^\]]*'type' => 'number'/s", $src);
        // no stock plan grants it: the catalogue entry only makes it editable
        $this->assertStringNotContainsString('voice_agent', file_get_contents(dirname(__DIR__, 2) . '/app/Services/Subscription/BillingPlanCatalog.php'));
    }

    public function testCrossCompanyReportsAreAdminOnly(): void
    {
        $root = dirname(__DIR__, 2);
        $users = [];
        foreach (array_merge(glob($root . '/app/Services/*/*.php'), glob($root . '/app/Services/*.php'), glob($root . '/app/Controllers/*.php'), glob($root . '/cron/*.php')) as $f) {
            if (strpos(file_get_contents($f), 'VoiceAdminReports') !== false && basename($f) !== 'VoiceAdminReports.php') {
                $users[] = basename($f);
            }
        }
        $this->assertSame(['VoiceAdminController.php'], $users);
        foreach (file($root . '/app/routes/voice_agent.php') as $line) {
            if (strpos($line, 'VoiceAdminController') !== false) {
                $this->assertStringContainsString("'AdminMiddleware'", $line);
                $this->assertStringContainsString('/api/admin/voice/', $line);
            }
        }
        $ctl = file_get_contents($root . '/app/Controllers/VoiceAdminController.php');
        $this->assertStringContainsString("['admin', 'super_admin']", $ctl);
    }

    public function testNoCustomerFacingControllerHandlesProviderSecrets(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/app/Controllers/Voice*.php') as $f) {
            $src = file_get_contents($f);
            $this->assertStringNotContainsString('credentials_enc', $src, basename($f));
            $this->assertStringNotContainsString('->decrypt(', $src, basename($f));
        }
    }
}

<?php

/** AI Voice & Sales Agent (Phase 1): role matrix and input validation (pure logic, no DB). */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/VoiceAgent/VoiceAccessService.php';
require_once __DIR__ . '/../../app/Services/VoiceAgent/VoiceRolePrompts.php';
require_once __DIR__ . '/../../app/Services/VoiceAgent/VoiceSettingsService.php';

final class VoiceAgentFoundationTest extends TestCase
{
    public function testOnlyAdminManagesSettingsAndTelephony(): void
    {
        foreach (['manager', 'sales', 'support', 'viewer'] as $role) {
            $this->assertFalse(VoiceAccessService::roleAllows($role, 'manage_settings'), $role);
            $this->assertFalse(VoiceAccessService::roleAllows($role, 'manage_telephony'), $role);
        }
        $this->assertTrue(VoiceAccessService::roleAllows('admin', 'manage_settings'));
        $this->assertTrue(VoiceAccessService::roleAllows('admin', 'manage_telephony'));
    }

    public function testViewerAndUnknownRolesAreReadOnly(): void
    {
        $this->assertSame(['view'], VoiceAccessService::capabilitiesFor('viewer'));
        $this->assertSame([], VoiceAccessService::capabilitiesFor('hacker'));
        $this->assertFalse(VoiceAccessService::roleAllows('viewer', 'place_calls'));
    }

    public function testRecordingsAreRestrictedToAdminAndManager(): void
    {
        $this->assertTrue(VoiceAccessService::roleAllows('manager', 'view_recordings'));
        $this->assertFalse(VoiceAccessService::roleAllows('sales', 'view_recordings'));
    }

    public function testSettingsSanitizeIgnoresUnknownKeysSoTenantIdCannotBeInjected(): void
    {
        $out = VoiceSettingsService::sanitizeSettings(['tenant_user_id' => 999, 'enabled' => 'true', 'default_language' => 'AR']);
        $this->assertSame(['enabled' => 1, 'default_language' => 'ar'], $out);
    }

    public function testSettingsRejectBadLanguageBudgetAndPhone(): void
    {
        foreach ([['default_language' => 'xx'], ['monthly_budget_usd' => -1], ['human_handoff_phone' => '0123']] as $bad) {
            try {
                VoiceSettingsService::sanitizeSettings($bad);
                $this->fail('expected InvalidArgumentException');
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame(['human_handoff_phone' => '+201001234567'], VoiceSettingsService::sanitizeSettings(['human_handoff_phone' => '+201001234567']));
    }

    public function testAgentSanitizeRequiresNameAndValidStatus(): void
    {
        $a = VoiceSettingsService::sanitizeAgent(['name' => ' Luxor Bot ', 'language' => 'en']);
        $this->assertSame('Luxor Bot', $a['name']);
        $this->assertSame('draft', $a['status']);
        $this->expectException(InvalidArgumentException::class);
        VoiceSettingsService::sanitizeAgent(['name' => '']);
    }

    public function testMigrationKeepsSafeDefaults(): void
    {
        $sql = file_get_contents(__DIR__ . '/../../database/migrations/2026_10_31_000006_voice_agent_foundation.sql');
        $this->assertMatchesRegularExpression('/sandbox_mode TINYINT\(1\) NOT NULL DEFAULT 1/', $sql);
        $this->assertMatchesRegularExpression('/outbound_enabled TINYINT\(1\) NOT NULL DEFAULT 0/', $sql);
        $this->assertMatchesRegularExpression('/recording_enabled TINYINT\(1\) NOT NULL DEFAULT 0/', $sql);
        $this->assertStringNotContainsString('ALTER TABLE', $sql);
    }
}

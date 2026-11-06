<?php

use PHPUnit\Framework\TestCase;

/** The "support_lead" staff role: support plus account control and team-activity visibility, but no money or settings. */
final class SupportLeadRoleTest extends TestCase
{
    public function testSupportLeadIsAValidRoleThatFitsTheStaffRoleColumn(): void
    {
        $this->assertTrue(AdminAccess::validRole('support_lead'));
        $this->assertLessThanOrEqual(20, strlen('support_lead'), 'admin_staff.staff_role is VARCHAR(20)');
    }

    public function testSupportLeadIsASupersetOfSupport(): void
    {
        $missing = array_diff(AdminAccess::rolePermissions('support'), AdminAccess::rolePermissions('support_lead'));
        $this->assertSame([], array_values($missing));
        foreach (['users.suspend', 'users.export', 'security.manage', 'audit.view'] as $p) {
            $this->assertContains($p, AdminAccess::rolePermissions('support_lead'));
            $this->assertNotContains($p, AdminAccess::rolePermissions('support'), "plain support must not get $p");
        }
    }

    public function testSupportLeadStaysAwayFromMoneySettingsAndTeamManagement(): void
    {
        $perms = AdminAccess::rolePermissions('support_lead');
        foreach (['billing.manage', 'billing.settings', 'subscriptions.manage', 'plans.manage', 'settings.manage', 'system.manage',
                  'users.delete', 'users.impersonate', 'team.manage', 'broadcast.send'] as $p) {
            $this->assertNotContains($p, $perms, $p);
        }
    }

    public function testEveryPermissionOfEveryRoleExistsInTheCatalog(): void
    {
        foreach (array_keys(AdminAccess::ROLE_LABELS) as $role) {
            foreach (AdminAccess::rolePermissions($role) as $p) {
                $this->assertArrayHasKey($p, AdminAccess::CATALOG, "$role -> $p");
            }
        }
    }

    public function testTeamStatusNeedsTeamManageAndActivityNeedsAuditView(): void
    {
        $this->assertSame('team.manage', AdminAccess::requiredFor('POST', '/api/admin/ops/team/5/status'));
        $this->assertSame('audit.view', AdminAccess::requiredFor('GET', '/api/admin/ops/team/5/activity'));
        $this->assertSame('team.view', AdminAccess::requiredFor('GET', '/api/admin/ops/team'));
    }

    public function testPlainSupportCannotReadAnotherStaffMembersActivity(): void
    {
        $this->assertNotContains(AdminAccess::requiredFor('GET', '/api/admin/ops/team/5/activity'), AdminAccess::rolePermissions('support'));
    }
}

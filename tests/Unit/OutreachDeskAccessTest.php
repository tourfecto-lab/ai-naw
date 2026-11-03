<?php

use PHPUnit\Framework\TestCase;

/** The "sales" staff role sees the outreach desk and nothing else in the admin area. */
final class OutreachDeskAccessTest extends TestCase
{
    public function testSalesRoleHasOnlyTheDeskPermission(): void
    {
        $this->assertTrue(AdminAccess::validRole('sales'));
        $this->assertSame(['outreach.desk'], AdminAccess::rolePermissions('sales'));
        $this->assertArrayHasKey('outreach.desk', AdminAccess::CATALOG);
    }

    public function testDeskPageAndApiNeedTheDeskPermission(): void
    {
        $this->assertSame('outreach.desk', AdminAccess::requiredFor('GET', '/admin/outreach-desk'));
        $this->assertSame('outreach.desk', AdminAccess::requiredFor('GET', '/api/admin/outreach-desk/leads'));
        $this->assertSame('outreach.desk', AdminAccess::requiredFor('POST', '/api/admin/outreach-desk/leads/12/action'));
    }

    public function testSalesCannotReachGrowthOrOtherAdminAreas(): void
    {
        $perms = AdminAccess::rolePermissions('sales');
        foreach ([['GET', '/admin/growth'], ['POST', '/api/admin/growth/run'], ['GET', '/admin/users'], ['GET', '/admin/finance'], ['GET', '/admin/settings']] as [$m, $p]) {
            $need = AdminAccess::requiredFor($m, $p);
            $this->assertNotNull($need, $p);
            $this->assertNotContains($need, $perms, "$m $p must stay closed to the sales role");
        }
    }

    public function testOwnerAndAdminRolesGetTheDeskToo(): void
    {
        $this->assertContains('outreach.desk', AdminAccess::rolePermissions('owner'));
        $this->assertContains('outreach.desk', AdminAccess::rolePermissions('admin'));
    }
}

<?php

/**
 * Tourfecto - Competitor Intelligence: CiPermissions Test
 * @version 1.0.0
 *
 * اختبار offline بالكامل - منطق بحت بدون قاعدة بيانات:
 *   php tests/Unit/CompetitorIntelligence/CiPermissionsTest.php
 */
require_once dirname(__DIR__, 3) . '/app/Services/CompetitorIntelligence/CiPermissions.php';

class CiPermissionsTest
{
    private $passed = 0;
    private $failed = 0;

    public function runAll(): void
    {
        echo "\n✅ CiPermissions Tests\n======================\n\n";

        $this->testAdminHasFullAccess();
        $this->testAccountOwnerHasFullAccessToOwnData();
        $this->testAnalystCannotDelete();
        $this->testViewerCannotAdd();
        $this->testUnknownRoleDefaultsToViewer();

        $this->printSummary();
    }

    private function testAdminHasFullAccess(): void
    {
        $this->startTest('admin role has full CI permissions');
        $user = ['role' => 'admin'];
        foreach ([CiPermissions::PERM_VIEW, CiPermissions::PERM_ADD, CiPermissions::PERM_EDIT, CiPermissions::PERM_DELETE, CiPermissions::PERM_MANAGE_SETTINGS] as $perm) {
            CiPermissions::can($user, $perm) ? $this->pass("admin can {$perm}") : $this->fail("admin CANNOT {$perm}");
        }
    }

    private function testAccountOwnerHasFullAccessToOwnData(): void
    {
        $this->startTest('account owner (role=user) has admin-level CI access');
        $user = ['role' => 'user'];
        CiPermissions::can($user, CiPermissions::PERM_DELETE)
            ? $this->pass('account owner can delete their own competitors')
            : $this->fail('account owner CANNOT delete their own competitors');
    }

    private function testAnalystCannotDelete(): void
    {
        $this->startTest('analyst (role=agent) cannot delete or manage settings');
        $user = ['role' => 'agent'];
        !CiPermissions::can($user, CiPermissions::PERM_DELETE)
            ? $this->pass('analyst correctly blocked from delete')
            : $this->fail('analyst was allowed to delete');
        CiPermissions::can($user, CiPermissions::PERM_VIEW)
            ? $this->pass('analyst can still view')
            : $this->fail('analyst cannot view (too restrictive)');
    }

    private function testViewerCannotAdd(): void
    {
        $this->startTest('unrecognized role falls back to viewer (read-only)');
        $user = ['role' => 'guest'];
        !CiPermissions::can($user, CiPermissions::PERM_ADD)
            ? $this->pass('viewer correctly blocked from add')
            : $this->fail('viewer was allowed to add');
    }

    private function testUnknownRoleDefaultsToViewer(): void
    {
        $this->startTest('missing role key defaults safely to viewer');
        $user = [];
        CiPermissions::ciRole($user) === 'viewer'
            ? $this->pass('missing role defaults to viewer')
            : $this->fail('missing role did not default to viewer');
    }

    private function startTest(string $name): void
    {
        echo "\n  ▶ {$name}\n";
    }
    private function pass(string $message): void
    {
        echo "    ✅ {$message}\n";
        $this->passed++;
    }
    private function fail(string $message): void
    {
        echo "    ❌ {$message}\n";
        $this->failed++;
    }

    private function printSummary(): void
    {
        $total = $this->passed + $this->failed;
        $percentage = $total > 0 ? round(($this->passed / $total) * 100, 2) : 0;
        echo "\n" . str_repeat('=', 50) . "\n";
        echo "📊 CiPermissions Test Summary\n";
        echo str_repeat('=', 50) . "\n";
        echo "  ✅ Passed: {$this->passed}\n  ❌ Failed: {$this->failed}\n  📝 Total: {$total}\n  📈 Success Rate: {$percentage}%\n";
        echo str_repeat('=', 50) . "\n\n";
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    (new CiPermissionsTest())->runAll();
}

<?php

/**
 * Tourfecto - Business Access Service Test
 * @version 1.0.0
 *
 * اختبار offline بالكامل - بيغطي الطبقة الخالصة من RBAC (roleRank و
 * roleAllows وخريطة الأدوار) اللي مفيش فيها أي اتصال بقاعدة بيانات.
 * الفحوص اللي محتاجة DB (roleOf/getAccessibleBusiness) مش جزء من الاختبار
 * ده لأنها بتتطلب سيرفر بجدول business_members فعلي.
 *
 * التشغيل:
 *   php tests/Unit/Business/BusinessAccessServiceTest.php
 */
require_once dirname(__DIR__, 3) . '/app/Services/BusinessAccessService.php';

class BusinessAccessServiceTest
{
    private $passed = 0;
    private $failed = 0;

    public function runAll(): void
    {
        echo "\nBusiness Access Service Tests (RBAC pure logic)\n";
        echo "==================================================\n\n";

        $this->testRoleRankOrdering();
        $this->testAllowedMemberRolesExcludesOwner();
        $this->testViewCapability();
        $this->testEditCapability();
        $this->testManageTeamCapability();
        $this->testAdministerTeamCapability();
        $this->testSensitiveCapabilities();
        $this->testFullCapabilityMatrix();
        $this->testUnknownRoleAndCapability();

        $this->printSummary();
    }

    private function testRoleRankOrdering(): void
    {
        $this->startTest('Role rank ordering (owner highest)');
        $expected = [
            BusinessAccessService::ROLE_OWNER => 4,
            BusinessAccessService::ROLE_ADMIN => 3,
            BusinessAccessService::ROLE_MEMBER => 2,
            BusinessAccessService::ROLE_VIEWER => 1,
        ];
        $ok = true;
        foreach ($expected as $role => $rank) {
            if (BusinessAccessService::roleRank($role) !== $rank) {
                $ok = false;
                break;
            }
        }
        if (BusinessAccessService::roleRank('nonexistent_role') !== 0) {
            $ok = false;
        }
        $ok ? $this->pass('owner=4 > admin=3 > member=2 > viewer=1, unknown=0') : $this->fail('Ranking mismatch');
    }

    private function testAllowedMemberRolesExcludesOwner(): void
    {
        $this->startTest('allowedMemberRoles excludes owner (owner is derived, not stored)');
        $roles = BusinessAccessService::allowedMemberRoles();
        $ok = in_array('admin', $roles, true)
            && in_array('member', $roles, true)
            && in_array('viewer', $roles, true)
            && !in_array('owner', $roles, true);
        $ok ? $this->pass('contains admin/member/viewer only') : $this->fail('Unexpected roles: ' . implode(',', $roles));
    }

    private function testViewCapability(): void
    {
        $this->startTest('view allowed for all four roles');
        $all = ['owner', 'admin', 'member', 'viewer'];
        $ok = true;
        foreach ($all as $role) {
            if (!BusinessAccessService::roleAllows($role, BusinessAccessService::CAP_VIEW)) {
                $ok = false;
                break;
            }
        }
        $ok ? $this->pass('owner/admin/member/viewer can all view') : $this->fail('view should be allowed for all roles');
    }

    private function testEditCapability(): void
    {
        $this->startTest('edit denied for viewer (read-only)');
        $ok = BusinessAccessService::roleAllows('owner', 'edit')
            && BusinessAccessService::roleAllows('admin', 'edit')
            && BusinessAccessService::roleAllows('member', 'edit')
            && !BusinessAccessService::roleAllows('viewer', 'edit');
        $ok ? $this->pass('owner/admin/member edit; viewer cannot') : $this->fail('edit capability mismatch');
    }

    private function testManageTeamCapability(): void
    {
        $this->startTest('manage_team limited to owner/admin');
        $ok = BusinessAccessService::roleAllows('owner', 'manage_team')
            && BusinessAccessService::roleAllows('admin', 'manage_team')
            && !BusinessAccessService::roleAllows('member', 'manage_team')
            && !BusinessAccessService::roleAllows('viewer', 'manage_team');
        $ok ? $this->pass('owner/admin manage team; member/viewer cannot') : $this->fail('manage_team capability mismatch');
    }

    private function testAdministerTeamCapability(): void
    {
        $this->startTest('administer_team (admin-role operations) owner-only');
        $ok = BusinessAccessService::roleAllows('owner', 'administer_team')
            && !BusinessAccessService::roleAllows('admin', 'administer_team')
            && !BusinessAccessService::roleAllows('member', 'administer_team')
            && !BusinessAccessService::roleAllows('viewer', 'administer_team');
        $ok ? $this->pass('only owner can administer admin-level team ops') : $this->fail('administer_team capability mismatch');
    }

    private function testSensitiveCapabilities(): void
    {
        $this->startTest('manage_keys / read_audit limited to owner/admin (Phase 22 refinement)');
        $ok = true;
        foreach (['manage_keys', 'read_audit'] as $cap) {
            if (!BusinessAccessService::roleAllows('owner', $cap)) {
                $ok = false;
            }
            if (!BusinessAccessService::roleAllows('admin', $cap)) {
                $ok = false;
            }
            if (BusinessAccessService::roleAllows('member', $cap)) {
                $ok = false;
            }
            if (BusinessAccessService::roleAllows('viewer', $cap)) {
                $ok = false;
            }
        }
        $ok ? $this->pass('owner/admin manage keys + read audit; member/viewer cannot') : $this->fail('sensitive capability mismatch');
    }

    private function testFullCapabilityMatrix(): void
    {
        $this->startTest('Full capability matrix (5 caps x 4 roles)');
        // Expected truth table: row = capability, col = owner/admin/member/viewer
        $matrix = [
            'view'             => [true,  true,  true,  true],
            'edit'             => [true,  true,  true,  false],
            'manage_team'      => [true,  true,  false, false],
            'administer_team'  => [true,  false, false, false],
            'manage_keys'      => [true,  true,  false, false],
            'read_audit'       => [true,  true,  false, false],
        ];
        $roles = ['owner', 'admin', 'member', 'viewer'];
        $ok = true;
        foreach ($matrix as $cap => $expected) {
            foreach ($roles as $i => $role) {
                if (BusinessAccessService::roleAllows($role, $cap) !== $expected[$i]) {
                    $ok = false;
                    $this->fail("{$cap} + {$role} expected " . var_export($expected[$i], true));
                }
            }
        }
        $ok ? $this->pass('all 24 role/capability pairs correct') : null;
    }

    private function testUnknownRoleAndCapability(): void
    {
        $this->startTest('Unknown role / capability deny safely');
        $ok = !BusinessAccessService::roleAllows('superadmin', 'edit')
            && !BusinessAccessService::roleAllows('owner', 'delete_business')
            && BusinessAccessService::roleRank('') === 0;
        $ok ? $this->pass('unknown role/capability => false, rank 0') : $this->fail('Unknown inputs not denied');
    }

    private function startTest(string $name): void
    {
        echo "\n  > {$name}\n";
    }
    private function pass(string $message): void
    {
        echo "    [PASS] {$message}\n";
        $this->passed++;
    }
    private function fail(string $message): void
    {
        echo "    [FAIL] {$message}\n";
        $this->failed++;
    }

    private function printSummary(): void
    {
        $total = $this->passed + $this->failed;
        $percentage = $total > 0 ? round(($this->passed / $total) * 100, 2) : 0;
        echo "\n" . str_repeat('=', 50) . "\n";
        echo "Business Access Service Test Summary\n";
        echo str_repeat('=', 50) . "\n";
        echo "  Passed: {$this->passed}\n  Failed: {$this->failed}\n  Total: {$total}\n  Success Rate: {$percentage}%\n";
        echo str_repeat('=', 50) . "\n\n";
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    (new BusinessAccessServiceTest())->runAll();
}

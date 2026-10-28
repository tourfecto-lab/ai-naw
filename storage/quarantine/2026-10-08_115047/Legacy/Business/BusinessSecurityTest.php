<?php

/**
 * Tourfecto - Business Security Audit Fixes Test (Phase 26)
 * @version 1.0.0
 *
 * بيختبر إصلاحات فجوات الأمان اللي طلعت من مراجعة Phase 26:
 *   - F2: منع الـadmin من تولية admin جديد (حق المالك بس) - داخل
 *     BusinessTeamService::invite (طبقة الدفاع الثانية).
 *   - F5: حد أقصى للدعوات المعلقة لكل Business (MAX_PENDING_INVITES).
 *   - F3: invite_token مخفي من toArray() في BusinessMember.
 */

if (!class_exists('Model')) {
    class Model
    {
        protected $table = '';
        protected $fillable = [];
        protected $hidden = [];
        protected $attrs = [];
        public function __construct(array $data = [])
        {
            $this->attrs = $data;
        }
        public function getAttribute(string $key)
        {
            return $this->attrs[$key] ?? null;
        }
        public function setAttribute(string $key, $value)
        {
            $this->attrs[$key] = $value;
        }
        public function save()
        {
            return true;
        }
        public function toArray(): array
        {
            $out = [];
            foreach ($this->attrs as $k => $v) {
                if (in_array($k, $this->hidden, true)) {
                    continue;
                }
                $out[$k] = $v;
            }
            return $out;
        }
        public function where(array $conditions = [], array $order = [], int $limit = 0)
        {
            return [];
        }
        public function find(int $id)
        {
            return null;
        }
        public function delete()
        {
            return true;
        }
    }
}

require_once dirname(__DIR__, 3) . '/app/Services/BusinessAccessService.php';
require_once dirname(__DIR__, 3) . '/app/Models/BusinessMember.php';
require_once dirname(__DIR__, 3) . '/app/Services/BusinessTeamService.php';

class BusinessSecurityTest
{
    private $passed = 0;
    private $failed = 0;

    public function runAll(): void
    {
        echo "\nBusiness Security Audit Fixes (Phase 26) Tests\n";
        echo "===============================================\n\n";

        $this->testInviteConstantExists();
        $this->testInviteTokenHidden();
        $this->testMaxPendingInvitesConstant();
        $this->testAdminInviteRuleServiceEnforced();
        $this->testNonOwnerCannotInviteAdmin();

        $this->printSummary();
    }

    private function testInviteConstantExists(): void
    {
        $this->startTest('MAX_PENDING_INVITES constant exists and is positive');
        $ok = defined('BusinessTeamService::MAX_PENDING_INVITES')
            && BusinessTeamService::MAX_PENDING_INVITES > 0;
        $ok ? $this->pass('MAX_PENDING_INVITES = ' . BusinessTeamService::MAX_PENDING_INVITES) : $this->fail('constant missing/zero');
    }

    private function testMaxPendingInvitesConstant(): void
    {
        $this->startTest('MAX_PENDING_INVITES is a sensible cap');
        $ok = BusinessTeamService::MAX_PENDING_INVITES <= 100;
        $ok ? $this->pass('cap within sane bound (' . BusinessTeamService::MAX_PENDING_INVITES . ')') : $this->fail('cap too high');
    }

    private function testInviteTokenHidden(): void
    {
        $this->startTest('F3: invite_token is hidden from toArray()');
        $m = new BusinessMember([
            'id' => 1,
            'business_id' => 2,
            'role' => 'member',
            'status' => 'invited',
            'invited_email' => 'x@example.com',
            'invite_token' => 'super-secret-token',
            'invite_expires_at' => '2026-08-24 12:00:00',
        ]);
        $arr = $m->toArray();
        $ok = !array_key_exists('invite_token', $arr)
            && !array_key_exists('invite_expires_at', $arr)
            && isset($arr['role']);
        $ok ? $this->pass('token + expiry not present in toArray()') : $this->fail('sensitive fields leaked');
    }

    private function testAdminInviteRuleServiceEnforced(): void
    {
        $this->startTest('F2: service rejects admin invite for non-owner actor role');
        // Stub User::findByEmail -> null (لمستخدم غير مسجل) عشان نستطيع
        // فحص مسار الدعوة المعلقة قبل ما نضرب في أي DB.
        if (!class_exists('User')) {
            eval('class User { public static function findByEmail($email) { return null; } }');
        }
        $svc = new BusinessTeamService();
        // actorRole = admin -> تولية admin لازم تترفض.
        $result = $svc->invite(2, 5, 'guest@example.com', 'admin', 'admin');
        $ok = $result['ok'] === false && strpos($result['error'] ?? '', 'المالك') !== false;
        $ok ? $this->pass('admin actor cannot grant admin role: ' . $result['error']) : $this->fail('admin invite not blocked: ' . json_encode($result));
    }

    private function testNonOwnerCannotInviteAdmin(): void
    {
        $this->startTest('F2: member/viewer actor role also rejected');
        $svc = new BusinessTeamService();
        $result = $svc->invite(2, 5, 'guest2@example.com', 'admin', 'member');
        $ok = $result['ok'] === false && strpos($result['error'] ?? '', 'المالك') !== false;
        $ok ? $this->pass('non-owner blocked for admin role: ' . $result['error']) : $this->fail('non-owner admin invite not blocked');
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
        echo "Business Security Test Summary\n";
        echo str_repeat('=', 50) . "\n";
        echo "  Passed: {$this->passed}\n  Failed: {$this->failed}\n  Total: {$total}\n  Success Rate: {$percentage}%\n";
        echo str_repeat('=', 50) . "\n\n";
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    (new BusinessSecurityTest())->runAll();
}

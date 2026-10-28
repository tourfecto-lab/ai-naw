<?php

/**
 * Tourfecto - Business Notification Service Test (Phase 24)
 * @version 1.0.0
 *
 * اختبار offline لـ BusinessNotificationService - بيركز على الـbuilders
 * (المنطق الخالص اللي بيبني الرسائل من غير أي DB). الـpush بيعتمد على
 * Notification::notify اللي محتاج DB - فنستثنيه هنا ونركز على بناء
 * الرسائل الصحيح (المحتوى والنوع والوجهة).
 */
class BusinessNotificationServiceTest
{
    private $passed = 0;
    private $failed = 0;

    public function runAll(): void
    {
        echo "\nBusiness Notification Service (Phase 24) Tests\n";
        echo "===============================================\n\n";

        require_once dirname(__DIR__, 3) . '/app/Services/BusinessNotificationService.php';

        $this->testMemberAdded();
        $this->testInviteSent();
        $this->testInviteAccepted();
        $this->testMemberRemoved();
        $this->testRoleChanged();
        $this->testApiKeyCreated();
        $this->testApiKeyRevoked();
        $this->testPushSkipsEmptyUserId();

        $this->printSummary();
    }

    private function testMemberAdded(): void
    {
        $this->startTest('memberAdded builds correct payload');
        $n = BusinessNotificationService::memberAdded(42, 'نيل للسياحة', 'أحمد', 'member');
        $ok = $n['user_id'] === 42
            && $n['type'] === 'business_team_added'
            && strpos($n['title'], 'نيل للسياحة') !== false
            && strpos($n['body'], 'أحمد') !== false
            && $n['link'] === '/business-center';
        $ok ? $this->pass('user/type/title/body/link all correct') : $this->fail('payload mismatch: ' . json_encode($n));
    }

    private function testInviteSent(): void
    {
        $this->startTest('inviteSent notifies the owner');
        $n = BusinessNotificationService::inviteSent(7, 'شركة الأهرام', 'guest@example.com', 'viewer');
        $ok = $n['user_id'] === 7
            && $n['type'] === 'business_team_invite_sent'
            && strpos($n['body'], 'guest@example.com') !== false
            && strpos($n['body'], 'viewer') !== false;
        $ok ? $this->pass('owner receives invite notice with email+role') : $this->fail('payload mismatch');
    }

    private function testInviteAccepted(): void
    {
        $this->startTest('inviteAccepted notifies the owner on acceptance');
        $n = BusinessNotificationService::inviteAccepted(7, 'شركة الأهرام', 'محمود');
        $ok = $n['user_id'] === 7
            && $n['type'] === 'business_team_invite_accepted'
            && strpos($n['title'], 'محمود') !== false;
        $ok ? $this->pass('owner notified with member name') : $this->fail('payload mismatch');
    }

    private function testMemberRemoved(): void
    {
        $this->startTest('memberRemoved notifies the removed member');
        $n = BusinessNotificationService::memberRemoved(42, 'نيل للسياحة');
        $ok = $n['user_id'] === 42
            && $n['type'] === 'business_team_removed'
            && strpos($n['title'], 'نيل للسياحة') !== false;
        $ok ? $this->pass('removed member notified') : $this->fail('payload mismatch');
    }

    private function testRoleChanged(): void
    {
        $this->startTest('roleChanged notifies with new role');
        $n = BusinessNotificationService::roleChanged(42, 'نيل للسياحة', 'admin');
        $ok = $n['user_id'] === 42
            && $n['type'] === 'business_team_role_changed'
            && strpos($n['body'], 'admin') !== false;
        $ok ? $this->pass('role change notice correct') : $this->fail('payload mismatch');
    }

    private function testApiKeyCreated(): void
    {
        $this->startTest('apiKeyCreated notifies the owner');
        $n = BusinessNotificationService::apiKeyCreated(7, 'شركة الأهرام', 'payment-gateway');
        $ok = $n['user_id'] === 7
            && $n['type'] === 'business_api_key_created'
            && strpos($n['body'], 'payment-gateway') !== false;
        $ok ? $this->pass('owner notified with key name') : $this->fail('payload mismatch');
    }

    private function testApiKeyRevoked(): void
    {
        $this->startTest('apiKeyRevoked notifies the owner');
        $n = BusinessNotificationService::apiKeyRevoked(7, 'شركة الأهرام', 'payment-gateway');
        $ok = $n['user_id'] === 7
            && $n['type'] === 'business_api_key_revoked'
            && strpos($n['body'], 'payment-gateway') !== false;
        $ok ? $this->pass('owner notified on revocation') : $this->fail('payload mismatch');
    }

    private function testPushSkipsEmptyUserId(): void
    {
        $this->startTest('push() no-ops safely without user_id / Notification class');
        // بدون Notification class مكدّرة - push لازم ترجع بصمت من غير خطأ.
        // (فقط لو الكلاس غير معرف - والاختبار ده بيتم في بيئة بلا bootstrap)
        $ok = true;
        try {
            BusinessNotificationService::push(['type' => 'x', 'title' => 'y']);
            BusinessNotificationService::push([]);
        } catch (\Throwable $e) {
            $ok = false;
            $this->fail('push threw: ' . $e->getMessage());
        }
        $ok ? $this->pass('push no-ops without error') : null;
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
        echo "Business Notification Service Test Summary\n";
        echo str_repeat('=', 50) . "\n";
        echo "  Passed: {$this->passed}\n  Failed: {$this->failed}\n  Total: {$total}\n  Success Rate: {$percentage}%\n";
        echo str_repeat('=', 50) . "\n\n";
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    (new BusinessNotificationServiceTest())->runAll();
}

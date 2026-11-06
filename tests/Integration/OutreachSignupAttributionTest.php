<?php

use PHPUnit\Framework\TestCase;

/**
 * ربط الحساب الجديد بالشركة من لينك الموظف (كوكي tf_lead_ref): بيشتغل حتى لو الإيميل والدومين مختلفين،
 * وبيرفض الحسابات القديمة أو المتربطة قبل كده أو شركة ألغت الاشتراك.
 */
final class OutreachSignupAttributionTest extends TestCase
{
    private ?Database $db = null;
    private PlatformOutreachService $svc;

    protected function setUp(): void
    {
        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) foreach (['app.php', 'database.php', 'encryption.php'] as $cfg) if (file_exists($app . '/Config/' . $cfg)) require_once $app . '/Config/' . $cfg;
            if (!class_exists('Database')) require_once $app . '/Core/Database.php';
            $this->db = Database::getInstance();
            $this->svc = new PlatformOutreachService();
        } catch (Throwable $e) {
            $this->markTestSkipped('DB غير متاحة: ' . $e->getMessage());
        }
        $this->clean();
    }

    protected function tearDown(): void
    {
        if ($this->db) $this->clean();
    }

    private function clean(): void
    {
        foreach (['attr-new@phpunit.test', 'attr-new2@phpunit.test', 'attr-old@phpunit.test'] as $e) {
            $ids = array_map('intval', array_column($this->db->query('SELECT id FROM users WHERE email = ?', [$e]) ?: [], 'id'));
            foreach ($ids as $id) {
                foreach (['visitor_logs', 'login_history', 'websites', 'subscriptions'] as $t) $this->db->query("DELETE FROM {$t} WHERE user_id = ?", [$id]);
            }
            $this->db->query('DELETE FROM users WHERE email = ?', [$e]);
        }
        $this->db->query("DELETE FROM platform_leads WHERE source = 'phpunit-attr'");
    }

    private function user(string $email, string $created): int
    {
        $this->db->query("INSERT INTO users (email, password_hash, role, status, company_name, created_at) VALUES (?, 'x', 'manager', 'active', 'Attr Co', ?)", [$email, $created]);
        return (int) ($this->db->query('SELECT id FROM users WHERE email = ?', [$email])[0]['id'] ?? 0);
    }

    private function lead(string $status = 'contacted'): string
    {
        $token = bin2hex(random_bytes(16));
        $this->db->query("INSERT INTO platform_leads (source, company, email, domain, phone, country, status, step, token, desk_action, desk_user_id, desk_by) VALUES ('phpunit-attr', 'Attr Lead', ?, ?, '01099998888', 'EG', ?, 1, ?, 'contacted', 4242, 'Vivian')", ['info@' . substr($token, 0, 8) . '.test', substr($token, 0, 8) . '.test', $status, $token]);
        return $token;
    }

    public function testAttributesFreshSignupEvenWithDifferentEmailAndNoWebsite(): void
    {
        $token = $this->lead();
        $uid = $this->user('attr-new@phpunit.test', gmdate('Y-m-d H:i:s', time() - 3600));

        $this->assertTrue($this->svc->attributeSignup($uid, $token));
        $row = $this->db->query("SELECT status, signup_user_id FROM platform_leads WHERE token = ?", [$token])[0];
        $this->assertSame('signed_up', $row['status']);
        $this->assertSame($uid, (int) $row['signup_user_id']);

        // الرحلة بتلاقي الشركة والموظف من الربط، مش من الإيميل ولا الدومين
        $j = (new CustomerJourneyService())->forUser($uid);
        $this->assertNotNull($j['source']);
        $this->assertSame('Attr Lead', $j['source']['company']);
        $this->assertSame(['id' => 4242, 'name' => 'Vivian'], $j['source']['staff']);
        $mine = array_column((new CustomerJourneyService())->newSignupAlerts(4242), null, 'id');
        $this->assertArrayHasKey($uid, $mine, 'التنبيه بيوصل للموظف اللي بعت اللينك');
    }

    public function testRejectsOldAccountsAlreadyLinkedAndUnsubscribed(): void
    {
        $token = $this->lead();
        $old = $this->user('attr-old@phpunit.test', gmdate('Y-m-d H:i:s', time() - 20 * 86400));
        $this->assertFalse($this->svc->attributeSignup($old, $token), 'حساب قديم مش بيتربط بكوكي قديم');

        $a = $this->user('attr-new@phpunit.test', gmdate('Y-m-d H:i:s', time() - 600));
        $b = $this->user('attr-new2@phpunit.test', gmdate('Y-m-d H:i:s', time() - 300));
        $this->assertTrue($this->svc->attributeSignup($a, $token));
        $this->assertFalse($this->svc->attributeSignup($b, $token), 'الشركة اتربطت بحساب تاني');

        $unsub = $this->lead('unsubscribed');
        $this->assertFalse($this->svc->attributeSignup($b, $unsub), 'شركة ألغت الاشتراك');
        $this->assertFalse($this->svc->attributeSignup($b, 'not-a-token'));
        $this->assertFalse($this->svc->attributeSignup(0, $token));
    }
}

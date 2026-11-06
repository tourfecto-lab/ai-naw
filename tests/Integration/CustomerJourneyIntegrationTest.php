<?php

use PHPUnit\Framework\TestCase;

/**
 * رحلة عميل حقيقية في قاعدة الاختبار: مشترك جديد دخل مرة وفتح صفحتين وضاف موقعه ومربطوش.
 */
final class CustomerJourneyIntegrationTest extends TestCase
{
    private ?Database $db = null;

    protected function setUp(): void
    {
        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) foreach (['app.php', 'database.php', 'encryption.php'] as $cfg) if (file_exists($app . '/Config/' . $cfg)) require_once $app . '/Config/' . $cfg;
            if (!class_exists('Database')) require_once $app . '/Core/Database.php';
            $this->db = Database::getInstance();
            $this->db->query('SELECT 1 FROM visitor_logs LIMIT 1');
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
        $ids = array_map('intval', array_column($this->db->query("SELECT id FROM users WHERE email = 'journey-test@phpunit.test'") ?: [], 'id'));
        foreach ($ids as $id) {
            foreach (['visitor_logs', 'login_history', 'websites', 'subscriptions'] as $t) {
                $this->db->query("DELETE FROM {$t} WHERE user_id = ?", [$id]);
            }
        }
        $this->db->query("DELETE FROM users WHERE email = 'journey-test@phpunit.test'");
        $this->db->query("DELETE FROM platform_leads WHERE source = 'phpunit-journey'");
    }

    public function testNewSignupJourneyAndNextSteps(): void
    {
        $signup = gmdate('Y-m-d H:i:s', time() - 20 * 3600);
        $this->db->query("INSERT INTO users (email, password_hash, role, status, company_name, phone, email_verified_at, created_at) VALUES ('journey-test@phpunit.test', 'x', 'manager', 'active', 'Journey Co', '01011112222', ?, ?)", [$signup, $signup]);
        $uid = (int) ($this->db->query("SELECT id FROM users WHERE email = 'journey-test@phpunit.test'")[0]['id'] ?? 0);
        $this->assertGreaterThan(0, $uid);
        $this->db->query("INSERT INTO login_history (user_id, status, created_at) VALUES (?, 'success', ?)", [$uid, $signup]);
        $this->db->query("INSERT INTO visitor_logs (visitor_id, user_id, page_url, is_authenticated, created_at) VALUES ('v1', ?, '/dashboard', 1, ?), ('v1', ?, '/websites/3', 1, ?)", [$uid, $signup, $uid, gmdate('Y-m-d H:i:s', time() - 20 * 3600 + 90)]);
        $this->db->query("INSERT INTO websites (user_id, main_url, is_connected, created_at) VALUES (?, 'https://journeyco.test', 0, ?)", [$uid, $signup]);
        $this->db->query("INSERT INTO platform_leads (source, company, email, domain, phone, country, status, step, token, report_viewed_at, desk_action, desk_user_id, desk_by) VALUES ('phpunit-journey', 'Journey Co', 'info@journeyco.test', 'journeyco.test', '01011112222', 'EG', 'contacted', 1, ?, ?, 'contacted', 987654, 'Salma')", [bin2hex(random_bytes(16)), $signup]);

        $svc = new CustomerJourneyService();
        $j = $svc->forUser($uid);

        $this->assertSame('never_returned', $j['state']);
        $this->assertSame(2, $j['visits']);
        $this->assertSame(90, $j['active_seconds']);
        $done = array_column($j['milestones'], 'done', 'key');
        $this->assertTrue($done['signup']);
        $this->assertTrue($done['site']);
        $this->assertFalse($done['connected']);
        $this->assertFalse($done['returned']);
        $this->assertNotNull($j['source']);
        $this->assertTrue($j['source']['report_viewed'], 'اتربط بالحملة عن طريق دومين الموقع');
        $this->assertStringContainsString('https://wa.me/201011112222', (string) $j['contact']['wa_url']);
        $texts = implode(' | ', array_column($j['next_steps'], 'text'));
        $this->assertStringContainsString('مرجعش', $texts);
        $this->assertStringContainsString('ما ربطوش', $texts);
        $this->assertStringContainsString('تقرير', $texts);

        $ids = array_map(static fn ($x) => $x['user']['id'], $svc->recentSignups(14, 50));
        $this->assertContains($uid, $ids);
        $this->assertSame('not_connected', $j['stage']['key']);
        $this->assertSame(['id' => 987654, 'name' => 'Salma'], $j['source']['staff'], 'الموظف اللي بعت التقرير على واتساب');
        $this->assertStringContainsString('journeyco.test', $j['contact']['message']);
        $this->assertStringContainsString('سجّلت فعلاً', $j['assistant_context']);
        $mine = array_map(static fn ($x) => $x['user']['id'], $svc->recentSignups(14, 50, null, 987654));
        $this->assertContains($uid, $mine);
        $other = array_map(static fn ($x) => $x['user']['id'], $svc->recentSignups(14, 50, null, 111));
        $this->assertNotContains($uid, $other, 'الموظف التاني مايشوفش مشتركين مش بتوعه');
        // التنبيه الفوري: خفيف، ومنسوب للموظف اللي بعت الواتساب بس، والمدير (null) يشوف الكل
        $alertMine = array_column($svc->newSignupAlerts(987654), null, 'id');
        $this->assertArrayHasKey($uid, $alertMine);
        $this->assertSame('Salma', $alertMine[$uid]['staff_name']);
        $this->assertSame('Journey Co', $alertMine[$uid]['name']);
        $this->assertArrayNotHasKey($uid, array_column($svc->newSignupAlerts(111), null, 'id'));
        $this->assertArrayHasKey($uid, array_column($svc->newSignupAlerts(null), null, 'id'));
        $this->assertNull($svc->forUser(999999999));
    }
}

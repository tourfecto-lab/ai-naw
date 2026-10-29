<?php

/**
 * TOURFECTO AI CEO (المرحلة 4): الموافقة على أول رسالة بتعدّي على البوابة القانونية (حتى من صفحة تسويق المنصة)،
 * ومراجعة اللي اتوافق عليه قبل كده، والمسار، والنقل للـ CRM مرة واحدة.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AiCeo/SalesEngine.php';
require_once __DIR__ . '/../../app/Services/AiCeo/AiCeoStore.php';

final class SalesEngineIntegrationTest extends TestCase
{
    private const OWNER = 999860;
    private ?Database $db = null;

    protected function setUp(): void
    {
        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) foreach (['app.php', 'database.php', 'encryption.php'] as $cfg) if (file_exists($app . '/Config/' . $cfg)) require_once $app . '/Config/' . $cfg;
            if (!class_exists('Database')) require_once $app . '/Core/Database.php';
            $this->db = Database::getInstance();
            new SalesEngine($this->db);
            $this->db->query("INSERT INTO users (id, email, password, company_name, created_at) VALUES (?, 'owner-sales@tourfecto.test', 'x', 'Tourfecto Owner Test', NOW()) ON DUPLICATE KEY UPDATE email = email", [self::OWNER]);
        } catch (Throwable $e) {
            $this->markTestSkipped('DB غير متاحة: ' . $e->getMessage());
        }
        $this->clean();
        $this->db->query("INSERT INTO system_settings (setting_key, setting_value, is_secret, category) VALUES ('outreach_company_address', '12 Nile Street, Luxor 85951, Egypt', 0, 'outreach') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    }

    protected function tearDown(): void
    {
        if ($this->db) $this->clean();
    }

    private function clean(): void
    {
        $ids = array_map('intval', array_column($this->db->query("SELECT id FROM platform_leads WHERE source = 'phpunit-sales'") ?: [], 'id'));
        if ($ids) {
            $in = implode(',', $ids);
            $this->db->query("DELETE FROM tbi_consent_events WHERE lead_id IN ({$in})");
            $this->db->query("DELETE FROM tbi_crm_handoffs WHERE lead_id IN ({$in})");
        }
        $this->db->query("DELETE FROM platform_leads WHERE source = 'phpunit-sales'");
        $this->db->query("DELETE FROM system_settings WHERE setting_key LIKE 'sales\\_%' OR setting_key = 'outreach_company_address'");
        try { $this->db->query('DELETE FROM crm_leads WHERE contact_id IN (SELECT id FROM crm_contacts WHERE user_id = ?)', [self::OWNER]); $this->db->query('DELETE FROM crm_contacts WHERE user_id = ?', [self::OWNER]); } catch (Throwable $e) {}
    }

    private function lead(string $country, string $email, array $v = []): int
    {
        $v += ['status' => 'new', 'approved' => 0, 'step' => 0, 'track' => 'seo', 'score' => 60];
        $this->db->query("INSERT INTO platform_leads (source, company, email, country, category, status, approved, step, track, score, token) VALUES ('phpunit-sales', ?, ?, ?, 'Tour agency', ?, ?, ?, ?, ?, ?)",
            ['Sales Test ' . $email, $email, $country, $v['status'], $v['approved'], $v['step'], $v['track'], $v['score'], bin2hex(random_bytes(16))]);
        return (int) ($this->db->query("SELECT MAX(id) AS id FROM platform_leads WHERE source = 'phpunit-sales'")[0]['id'] ?? 0);
    }

    private function approved(int $id): int
    {
        return (int) $this->db->query('SELECT approved FROM platform_leads WHERE id = ?', [$id])[0]['approved'];
    }

    public function testOutreachApprovalGoesThroughTheGate(): void
    {
        $us = $this->lead('US', 'info@sales-us.test');
        $fr = $this->lead('FR', 'marie@sales-fr.test');
        $eng = new SalesEngine($this->db);
        // من غير تأكيد المنطقة: ولا واحدة
        $this->assertSame(0, (new PlatformOutreachService())->approve([$us, $fr]));
        $eng->saveSettings(['basis_us' => true, 'basis_eu' => true]);
        // أمريكا تعدّي؛ فرنسا إيميل شخصي فلأ
        $this->assertSame(1, (new PlatformOutreachService())->approve([$us, $fr]));
        $this->assertSame(1, $this->approved($us));
        $this->assertSame(0, $this->approved($fr));
        $q = $eng->approvalQueue(100);
        $frItem = array_values(array_filter($q['items'], static fn ($i) => $i['id'] === $fr))[0];
        $this->assertSame(['personal_email_needs_opt_in'], $frItem['verdict']['reasons']);
    }

    public function testPreviouslyApprovedNonCompliantGoBackToTheQueue(): void
    {
        $id = $this->lead('AE', 'info@sales-ae.test', ['approved' => 1]);
        $eng = new SalesEngine($this->db);
        $this->assertSame(true, $eng->reevaluateApproved() >= 1);
        $this->assertSame(0, $this->approved($id));
    }

    public function testPipelineAndCrmHandoffOnce(): void
    {
        $a = $this->lead('US', 'info@sales-int.test', ['status' => 'interested', 'step' => 2, 'approved' => 1]);
        $this->lead('US', 'info@sales-new.test');
        $eng = new SalesEngine($this->db);
        $p = $eng->pipeline();
        $this->assertSame(true, $p['stages']['interested'] >= 1);
        $this->assertSame(true, $p['crm_pending'] >= 1);
        $this->assertSame(true, $eng->handoffToCrm(self::OWNER, self::OWNER) >= 1);
        $this->assertCount(1, $this->db->query('SELECT lead_id FROM tbi_crm_handoffs WHERE lead_id = ?', [$a]));
        $this->assertSame(1, (int) $this->db->query("SELECT COUNT(*) AS c FROM crm_contacts WHERE user_id = ? AND email = 'info@sales-int.test'", [self::OWNER])[0]['c']);
        $eng->handoffToCrm(self::OWNER, self::OWNER);
        $this->assertSame(1, (int) $this->db->query("SELECT COUNT(*) AS c FROM crm_contacts WHERE user_id = ? AND email = 'info@sales-int.test'", [self::OWNER])[0]['c']);
    }

    public function testDailyReportIsWrittenOnce(): void
    {
        $store = new AiCeoStore($this->db);
        $eng = new SalesEngine($this->db);
        $now = strtotime('2026-11-03 09:00:00'); // اتنين
        $this->db->query("DELETE FROM aiceo_memory WHERE title IN ('Sales daily report 2026-11-02', 'Sales weekly report 2026-10-27')");
        $this->assertSame(['daily', 'weekly'], $eng->writeReports($store, $now));
        $this->assertSame([], $eng->writeReports($store, $now));
        $this->db->query("DELETE FROM aiceo_memory WHERE title IN ('Sales daily report 2026-11-02', 'Sales weekly report 2026-10-27')");
    }
}

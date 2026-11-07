<?php

use PHPUnit\Framework\TestCase;

/** كل فتح للتقرير بيتسجّل بنوع الجهاز، وفتح أول 90 ثانية بعد الإرسال مش محسوب، والسجل بيظهر في تايم لاين الشركة. */
final class OutreachReportViewLogTest extends TestCase
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
            $this->svc->deskLeads(['country' => 'EG', 'view' => 'all']);
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
        $this->db->query("DELETE FROM platform_report_views WHERE lead_id IN (SELECT id FROM platform_leads WHERE source = 'phpunit-rv')");
        $this->db->query("DELETE FROM platform_leads WHERE source = 'phpunit-rv'");
    }

    private function lead(string $deskAt): array
    {
        $token = bin2hex(random_bytes(16));
        $this->db->query("INSERT INTO platform_leads (source, company, email, domain, phone, country, language, status, step, token, desk_action, desk_at) VALUES ('phpunit-rv', 'RV Co', ?, ?, '01011112222', 'EG', 'ar', 'contacted', 0, ?, 'contacted', ?)", ['info@' . substr($token, 0, 8) . '.test', substr($token, 0, 8) . '.test', $token, $deskAt]);
        return [$token, (int) ($this->db->query('SELECT id FROM platform_leads WHERE token = ?', [$token])[0]['id'] ?? 0)];
    }

    public function testDeviceLabel(): void
    {
        $this->assertSame('iPhone/iPad · Safari', PlatformOutreachService::deviceLabel('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1'));
        $this->assertSame('Android · Chrome', PlatformOutreachService::deviceLabel('Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36'));
        $this->assertSame('Windows · Edge', PlatformOutreachService::deviceLabel('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36 Edg/120.0'));
    }

    public function testRealOpenIsCountedAndLogged(): void
    {
        [$token, $id] = $this->lead(gmdate('Y-m-d H:i:s', time() - 7200));
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $this->svc->report($token, true);
        $row = $this->db->query('SELECT report_views, report_viewed_at FROM platform_leads WHERE id = ?', [$id])[0];
        $this->assertSame(1, (int) $row['report_views']);
        $log = $this->db->query('SELECT device, counted FROM platform_report_views WHERE lead_id = ?', [$id]);
        $this->assertCount(1, $log);
        $this->assertSame('Android · Chrome', $log[0]['device']);
        $this->assertSame(1, (int) $log[0]['counted']);
        $texts = implode(' | ', array_column($this->svc->leadTimeline($id), 'text'));
        $this->assertStringContainsString('Android · Chrome', $texts);
    }

    public function testOpenRightAfterSendIsLoggedButNotCounted(): void
    {
        [$token, $id] = $this->lead(date('Y-m-d H:i:s'));
        $this->db->query('UPDATE platform_leads SET desk_at = NOW() WHERE id = ?', [$id]);
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';
        $this->svc->report($token, true);
        $row = $this->db->query('SELECT report_views, report_viewed_at FROM platform_leads WHERE id = ?', [$id])[0];
        $this->assertSame(0, (int) $row['report_views']);
        $this->assertNull($row['report_viewed_at']);
        $this->assertSame(0, (int) $this->db->query('SELECT counted FROM platform_report_views WHERE lead_id = ?', [$id])[0]['counted']);
    }
}

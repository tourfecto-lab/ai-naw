<?php

/**
 * قمع لوحة تسويق المنصة: كل شركة بتتعدّ مرة واحدة حتى لو اتواصل معاها بالإيميل والواتساب،
 * والشركات الواقفة (paused) اللي اتبعتلها بتتحسب "وصلنا لها"، والمستبعدة بتتحسب برا.
 */

use PHPUnit\Framework\TestCase;

final class OutreachUnifiedFunnelTest extends TestCase
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
            $this->svc->unifiedFunnel();
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
        $this->db->query("DELETE FROM platform_leads WHERE source = 'phpunit-funnel'");
    }

    private function lead(string $name, array $v = []): void
    {
        $v += ['status' => 'new', 'step' => 0, 'desk' => null, 'report' => null];
        $slug = strtolower(str_replace(' ', '', $name));
        $this->db->query("INSERT INTO platform_leads (source, company, email, domain, country, status, approved, step, token, desk_action, report_viewed_at)
            VALUES ('phpunit-funnel', ?, ?, ?, 'EG', ?, 1, ?, ?, ?, ?)",
            [$name, $slug . '@funnel.test', $slug . '.test', $v['status'], $v['step'], bin2hex(random_bytes(16)), $v['desk'], $v['report']]);
    }

    public function testEachCompanyCountedOnceAcrossChannelsAndPausedCounts(): void
    {
        $before = $this->svc->unifiedFunnel();

        $this->lead('Email Only', ['status' => 'contacted', 'step' => 1]);
        $this->lead('Paused Mailed', ['status' => 'paused', 'step' => 1]);
        $this->lead('Whatsapp Only', ['desk' => 'contacted', 'report' => '2026-11-05 10:00:00']);
        $this->lead('Both Channels', ['status' => 'replied', 'step' => 1, 'desk' => 'replied', 'report' => '2026-11-05 11:00:00']);
        $this->lead('Never Contacted');
        $this->lead('Excluded', ['status' => 'invalid', 'step' => 1]);

        $after = $this->svc->unifiedFunnel();
        $d = static fn (string $k): int => $after[$k] - $before[$k];

        $this->assertSame(5, $d('valid'), 'المستبعدة مابتتحسبش صالحة');
        $this->assertSame(1, $d('invalid'));
        $this->assertSame(4, $d('reached'), 'Both Channels بتتعدّ مرة واحدة والـ paused بتتحسب');
        $this->assertSame(3, $d('reached_email'));
        $this->assertSame(2, $d('reached_whatsapp'));
        $this->assertSame(2, $d('viewed'));
        $this->assertSame(1, $d('replied'));
    }
}

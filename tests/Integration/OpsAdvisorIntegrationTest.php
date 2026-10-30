<?php

/**
 * TOURFECTO AI CEO (المرحلة 7): فحص الصفحات بيتحفظ، الحوادث بتتفتح وتتقفل لوحدها وبتعد التكرار، الأعطال المتكررة بتبقى بند تطوير،
 * والقياس بعد بيتسجل لما البند يخلص. الإعدادات بترفض أي عنوان برا دومين المنصة.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AiCeo/OpsAdvisor.php';

final class OpsAdvisorIntegrationTest extends TestCase
{
    private ?Database $db = null;

    protected function setUp(): void
    {
        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) foreach (['app.php', 'database.php', 'encryption.php'] as $cfg) if (file_exists($app . '/Config/' . $cfg)) require_once $app . '/Config/' . $cfg;
            if (!class_exists('Database')) require_once $app . '/Core/Database.php';
            $this->db = Database::getInstance();
            new OpsAdvisor($this->db, fn () => ['code' => 200, 'ms' => 1]);
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
        $this->db->query('DELETE FROM aiceo_ops_checks');
        $this->db->query('DELETE FROM aiceo_incidents');
        $this->db->query('DELETE FROM aiceo_dev_backlog');
        $this->db->query("DELETE FROM system_settings WHERE setting_key LIKE 'ops\\_%'");
    }

    public function testSyntheticChecksAndIncidentLifecycle(): void
    {
        $down = true;
        $seen = [];
        $o = new OpsAdvisor($this->db, function (string $url) use (&$down, &$seen) {
            $seen[] = $url;
            $p = (string) parse_url($url, PHP_URL_PATH);
            if ($p === '/pricing') return ['code' => $down ? 500 : 200, 'ms' => 100];
            return ['code' => in_array($p, OpsAdvisor::PROTECTED_PAGES, true) ? 302 : 200, 'ms' => 100];
        });
        $base = $o->baseUrl();
        foreach ($seen as $u) $this->assertSame(0, strpos($u, $base));

        // ثلاث مرات تقع وترجع: حادثة بتتفتح وتتقفل، والتالتة بتبقى متكررة وبند تطوير
        for ($i = 1; $i <= 3; $i++) {
            $down = true;
            $syn = $o->synthetic();
            $this->assertSame(1, $syn['failed']);
            $r = $o->syncIncidents(OpsAdvisor::signals($syn['results'], [], [], [], ['status' => 'ok']));
            $this->assertSame(1, $r['opened']);
            $open = $o->incidents('open');
            $this->assertSame(1, count($open));
            $this->assertSame($i, $open[0]['occurrence']);
            $this->assertSame('critical', $open[0]['severity']);
            // نفس الإشارة في الجولة الجاية: مفيش حادثة جديدة
            $this->assertSame(0, $o->syncIncidents(OpsAdvisor::signals($syn['results'], [], [], [], ['status' => 'ok']))['opened']);
            $down = false;
            $syn = $o->synthetic();
            $this->assertSame(0, $syn['failed']);
            $this->assertSame(1, $o->syncIncidents(OpsAdvisor::signals($syn['results'], [], [], [], ['status' => 'ok']))['resolved']);
        }
        $this->assertSame(0, count($o->incidents('open')));
        $this->assertSame(6, count($o->lastChecks(24)));
        $rec = $o->recurring();
        $this->assertSame('page:/pricing', $rec[0]['key']);
        $this->assertSame(3, $rec[0]['times']);

        $this->assertSame(2, $o->generateBacklog(['whatsapp' => 4], []));
        $this->assertSame(2, $o->generateBacklog(['whatsapp' => 5], []), 'the same keys update instead of duplicating');
        $items = $o->backlog();
        $this->assertSame(2, count($items));
        $page = array_values(array_filter($items, static fn ($b) => $b['source'] === 'incident'))[0];
        $this->assertSame('high', $page['severity']);
        $this->assertSame('3 incidents / 30 days', $page['metric_before']);
        $this->assertSame(true, $page['priority'] > 0);
        $o->setBacklogStatus($page['id'], 'done');
        $done = $o->backlog('done');
        $this->assertSame('3 incidents / 30 days (at completion)', $done[0]['metric_after']);
        try { $o->setBacklogStatus($page['id'], 'shipped'); $this->fail('invalid status'); } catch (InvalidArgumentException $e) { $this->assertSame(1, 1); }

        $id = $o->addBacklog(['service' => 'crm', 'problem' => 'Export is slow', 'severity' => 'nope', 'cost_estimate' => 'XL']);
        $mine = array_values(array_filter($o->backlog(), static fn ($b) => $b['id'] === $id))[0];
        $this->assertSame('medium', $mine['severity']);
        $this->assertSame('M', $mine['cost_estimate']);
        try { $o->addBacklog(['service' => '', 'problem' => 'x']); $this->fail('missing service'); } catch (InvalidArgumentException $e) { $this->assertSame(1, 1); }
        try { $o->createIssue($id); $this->fail('github off'); } catch (RuntimeException $e) { $this->assertSame('github_not_enabled', $e->getMessage()); }
    }

    public function testExpectedIncidentsStayClosedUntilRestored(): void
    {
        $o = new OpsAdvisor($this->db, fn () => ['code' => 200, 'ms' => 1]);
        $sig = ['health:cron:scheduler' => ['service' => 'cron', 'title' => 'Scheduler', 'severity' => 'high', 'evidence' => 'not run 14h']];
        $o->syncIncidents($sig);
        $id = $o->incidents('open')[0]['id'];
        $this->assertSame('health:cron:scheduler', $o->ignoreIncident($id, 'dev site: scheduler runs on the main site only'));
        $this->assertSame(0, count($o->incidents('open')));
        $this->assertSame(0, $o->syncIncidents($sig)['opened'], 'ignored signals do not reopen');
        $this->assertSame('dev site: scheduler runs on the main site only', $o->ignored()['health:cron:scheduler']);
        $o->unignore('health:cron:scheduler');
        $this->assertSame(1, $o->syncIncidents($sig)['opened']);
        try { $o->ignoreIncident(987654, 'x'); $this->fail('unknown'); } catch (InvalidArgumentException $e) { $this->assertSame(1, 1); }
    }

    public function testSettingsAndSummary(): void
    {
        $o = new OpsAdvisor($this->db, fn () => ['code' => 200, 'ms' => 1]);
        try { $o->saveSettings(['base_url' => 'https://evil.example.com']); $this->fail('other host'); } catch (InvalidArgumentException $e) { $this->assertSame(1, 1); }
        try { $o->saveSettings(['backup_dir' => '../../etc']); $this->fail('relative path'); } catch (InvalidArgumentException $e) { $this->assertSame(1, 1); }
        $s = $o->saveSettings(['backup_dir' => '/nonexistent-tourfecto-backups', 'github_issues' => true]);
        $this->assertSame(true, $s['github_issues']);
        $this->assertSame(false, $s['github_ready']);
        $this->assertSame('fail', $o->backups()['status']);
        $sum = $o->summary();
        $this->assertSame('fail', $sum['backup_status']);
        $this->assertSame(0, $sum['backlog_open']);
    }
}

<?php

/**
 * TOURFECTO AI CEO (المرحلة 9): الدرجة بتتحفظ يوم بيوم، التقرير اليومي بيتكتب مرة واحدة (والأسبوعي يوم الاتنين)،
 * القرارات اللي بترجع بتتحول لدرس مرة واحدة، جودة البيانات، والمنافسين ومتابعة تغيير صفحتهم.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AiCeo/ExecutiveIntel.php';
require_once __DIR__ . '/../../app/Services/AiCeo/AiCeoStore.php';

final class ExecutiveIntelIntegrationTest extends TestCase
{
    private ?Database $db = null;

    protected function setUp(): void
    {
        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) foreach (['app.php', 'database.php', 'encryption.php'] as $cfg) if (file_exists($app . '/Config/' . $cfg)) require_once $app . '/Config/' . $cfg;
            if (!class_exists('Database')) require_once $app . '/Core/Database.php';
            $this->db = Database::getInstance();
            new ExecutiveIntel($this->db);
            new AiCeoStore($this->db);
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
        foreach (['aiceo_health_scores', 'aiceo_competitors', 'aiceo_activity', 'aiceo_memory', 'aiceo_decisions'] as $t) $this->db->query("DELETE FROM {$t}");
    }

    private function snap(): array
    {
        return ['revenue' => ['mrr' => ['now' => 100.0, 'prev' => 100.0], 'paying' => ['now' => 2, 'prev' => 2], 'logo_churn' => ['now' => 0.0, 'prev' => 0.0]], 'signups' => ['last_7d' => 1, 'prev_7d' => 1],
            'platform' => ['counts' => ['fail' => 0, 'warn' => 0]], 'ops' => null, 'finance' => ['fixed_entered' => false], 'generated_at' => gmdate('Y-m-d H:i:s')];
    }

    public function testHealthReportsAndLessons(): void
    {
        $x = new ExecutiveIntel($this->db);
        $store = new AiCeoStore($this->db);
        $h = $x->health($this->snap());
        $this->assertSame(null, $h['score'], 'no invented score without full data');
        $this->assertContains('profitability', $h['missing']);
        $this->assertSame(8, count($h['components']));
        $x->health($this->snap());
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) AS n FROM aiceo_health_scores')[0]['n'], 'one row per day');

        $monday = strtotime('2026-11-02 08:00:00 UTC');
        $this->assertSame(['daily', 'weekly'], $x->writeReports($store, $this->snap(), [], $monday));
        $this->assertSame([], $x->writeReports($store, $this->snap(), [], $monday), 'written once');
        $body = $this->db->query("SELECT body FROM aiceo_memory WHERE title = 'Executive report daily 2026-11-02'")[0]['body'] ?? '';
        $this->assertStringContainsString('Business health: incomplete', $body);
        $this->assertStringContainsString('Next step:', $body);

        foreach (['decision_proposed', 'decision_approved', 'decision_resolved', 'decision_reopened', 'decision_resolved', 'decision_reopened'] as $a) $store->log(null, $a, ['key' => 'ops_pages_down']);
        foreach (['decision_proposed', 'decision_dismissed', 'decision_dismissed'] as $a) $store->log(null, $a, ['key' => 'fin_quarter_goals']);
        foreach (['decision_proposed', 'decision_auto_approved', 'decision_resolved'] as $a) $store->log(null, $a, ['key' => 'cs_support_unanswered']);
        $rv = $x->decisionReview(30);
        $this->assertSame('ops_pages_down', $rv['recurring'][0]['key']);
        $this->assertSame('fin_quarter_goals', $rv['ignored'][0]['key']);
        $this->assertContains('cs_support_unanswered', array_column($rv['worked'], 'key'));
        $this->assertSame(2, $x->learn($store, $rv));
        $this->assertSame(0, $x->learn($store, $rv), 'lessons are not repeated');
        $this->assertSame(2, count($x->lessons()));

        $dq = $x->dataQuality($this->snap() + ['trials' => null]);
        $this->assertContains('Section trials', array_column(array_filter($dq['checks'], static fn ($c) => !$c['ok']), 'source'));
        $this->assertSame(true, $dq['score'] < 100);
    }

    public function testCompetitorsAndPageChanges(): void
    {
        $x = new ExecutiveIntel($this->db);
        try { $x->saveCompetitor(['name' => 'Bad', 'url' => 'ftp://x']); $this->fail('bad url'); } catch (InvalidArgumentException $e) { $this->assertSame(1, 1); }
        $id = $x->saveCompetitor(['name' => 'Rival', 'url' => 'https://rival.example', 'starting_price_usd' => '49', 'weaknesses' => 'No WhatsApp AI']);
        $x->saveCompetitor(['name' => 'NoSite', 'starting_price_usd' => '']);
        $html = '<title>Rival</title><p>From $49</p>';
        $fetch = function (string $url) use (&$html) { return $html; };
        $this->assertSame(0, $x->checkCompetitors($fetch), 'first check is the baseline');
        $this->assertSame(0, $x->checkCompetitors($fetch));
        $html = '<title>Rival</title><p>From $59</p>';
        $this->assertSame(1, $x->checkCompetitors($fetch));
        $c = array_values(array_filter($x->competitors(), static fn ($r) => $r['id'] === $id))[0];
        $this->assertSame('Rival', $c['page_title']);
        $this->assertNotNull($c['page_changed_at']);
        $this->assertSame(49.0, ExecutiveIntel::priceComparison($x->competitors(), [])['competitor_median']);
        $x->deleteCompetitor($id);
        $this->assertSame(1, count($x->competitors()));
    }
}

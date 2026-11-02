<?php

use PHPUnit\Framework\TestCase;

/** لوحة الظهور الموحدة: منطق الدرجات الصرف + عزل Tenant على قاعدة الاختبار. */
final class VisibilityScoreServiceTest extends TestCase
{
    private const OWNER = 999801;
    private const OTHER = 999802;
    private const NOW = '2026-11-02 12:00:00';

    private function provider(?float $sov, ?float $cit, int $q, string $week = '2026-10-26'): array
    {
        return ['state' => $sov === null ? 'not_measured' : 'measured_sample', 'share_of_voice' => $sov, 'citation_rate' => $cit, 'questions' => $q, 'week_start' => $week];
    }

    public function testNothingMeasuredNeverInventsANumber(): void
    {
        foreach ([VisibilityScoreService::seo(null, self::NOW), VisibilityScoreService::aeo(null), VisibilityScoreService::geo(null, self::NOW),
                  VisibilityScoreService::geo(['providers' => ['openai' => $this->provider(null, null, 0)]], self::NOW)] as $s) {
            $this->assertSame('not_measured', $s['state']);
            $this->assertNull($s['score']);
        }
    }

    public function testGeoWeightsMentionOverCitationAndReportsConfidence(): void
    {
        $g = VisibilityScoreService::geo(['providers' => [
            'openai' => $this->provider(0.5, 1.0, 20), 'gemini' => $this->provider(0.5, null, 20), 'claude' => $this->provider(null, null, 0),
        ]], self::NOW);
        $this->assertSame(65, $g['score']); // 100*(0.7*0.5+0.3*1.0)
        $this->assertSame(2, $g['providers']);
        $this->assertSame('high', $g['confidence']); // 2 providers x 20 questions
        $this->assertFalse($g['stale']);
    }

    public function testStaleSamplesAreFlagged(): void
    {
        $g = VisibilityScoreService::geo(['providers' => ['openai' => $this->provider(0.2, 0.0, 40, '2026-09-01')]], self::NOW);
        $this->assertTrue($g['stale']);
        $this->assertSame('high', $g['confidence']);
    }

    public function testSeoScoreIsClampedAndDated(): void
    {
        $s = VisibilityScoreService::seo(['overall_score' => 140, 'created_at' => '2026-11-01 10:00:00', 'findings_total' => 7], self::NOW);
        $this->assertSame(100, $s['score']);
        $this->assertSame('2026-11-01 10:00:00', $s['as_of']);
        $this->assertFalse($s['stale']);
    }

    public function testTopActionsRankByPriorityDedupeAndCapPerArea(): void
    {
        $c = [];
        foreach (range(1, 5) as $i) $c[] = ['area' => 'seo', 'ref' => "s$i", 'priority' => 'high', 'title' => "t$i"];
        $c[] = ['area' => 'seo', 'ref' => 's1', 'priority' => 'high', 'title' => 'dup'];
        $c[] = ['area' => 'geo', 'ref' => 'g1', 'priority' => 'low', 'title' => 'geo'];
        $out = VisibilityScoreService::topActions($c, 5);
        $this->assertCount(4, $out); // 3 seo (cap) + 1 geo
        $this->assertSame(['s1', 's2', 's3', 'g1'], array_column($out, 'ref'));
    }

    public function testSeoFromRunCountsAuditedPagesWithoutOpenTasks(): void
    {
        $run = ['id' => 1, 'created_at' => '2026-11-02 04:00:05', 'audited' => '96', 'is_partial' => 'false'];
        $open = [['page_path' => '/a'], ['page_path' => '/a'], ['page_path' => '/b']];
        $s = VisibilityScoreService::seoFromRun($run, $open, self::NOW);
        $this->assertSame(98, $s['score']); // 2 of 96 pages have open tasks
        $this->assertSame('high', $s['confidence']);
        $this->assertSame(2, $s['pages_with_open_tasks']);
        $this->assertSame('not_measured', VisibilityScoreService::seoFromRun(null, [], self::NOW)['state']);
        $this->assertSame('not_measured', VisibilityScoreService::seoFromRun(['created_at' => 'x', 'audited' => '0'], [], self::NOW)['state']);
        $this->assertSame('low', VisibilityScoreService::seoFromRun(['is_partial' => '1'] + $run, [], self::NOW)['confidence']);
    }

    public function testPickSeoPrefersTheNewerMeasuredSource(): void
    {
        $old = ['state' => 'measured', 'as_of' => '2026-10-01 00:00:00', 'score' => 50];
        $new = ['state' => 'measured', 'as_of' => '2026-11-01 00:00:00', 'score' => 80];
        $none = ['state' => 'not_measured', 'as_of' => null, 'score' => null];
        $this->assertSame(80, VisibilityScoreService::pickSeo($old, $new)['score']);
        $this->assertSame(80, VisibilityScoreService::pickSeo($new, $old)['score']);
        $this->assertSame(50, VisibilityScoreService::pickSeo($old, $none)['score']);
        $this->assertSame('not_measured', VisibilityScoreService::pickSeo($none, $none)['state']);
    }

    private function db(): ?Database
    {
        try {
            $app = dirname(__DIR__, 2) . '/app';
            foreach (['app', 'database', 'encryption'] as $cfg) {
                if (!defined('APP_ENV') && file_exists("$app/Config/$cfg.php")) require_once "$app/Config/$cfg.php";
            }
            foreach (['Database', 'Logger'] as $cls) {
                if (!class_exists($cls) && file_exists("$app/Core/$cls.php")) require_once "$app/Core/$cls.php";
            }
            if (!class_exists('AiVisibilityService')) require_once "$app/Config/project_classmap.php";
            $db = Database::getInstance();
            foreach (['users', 'websites', 'seo_reports', 'seo_expert_tasks'] as $t) {
                if (!$db->getConnection()->query("SHOW TABLES LIKE '$t'")->fetchAll()) return null;
            }
            return $db;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function testOverviewReadsLatestSeoExpertRunAndOnlyOpenTasks(): void
    {
        $db = $this->db();
        if (!$db) $this->markTestSkipped('DB غير متاحة');
        $pdo = $db->getConnection();
        $u = self::OWNER;
        foreach (['seo_expert_tasks', 'seo_expert_runs'] as $t) {
            if (!$pdo->query("SHOW TABLES LIKE '$t'")->fetchAll()) $this->markTestSkipped("$t غير موجود");
        }
        $pdo->exec("DELETE FROM seo_expert_tasks WHERE user_id=$u");
        $pdo->exec("DELETE FROM seo_expert_runs WHERE user_id=$u");
        $pdo->exec("DELETE FROM websites WHERE user_id=$u");
        $pdo->exec("INSERT INTO users (id,email,password,company_name,created_at) VALUES ($u,'vis-$u@tourfecto.test','x','V',NOW()) ON DUPLICATE KEY UPDATE email=email");
        $pdo->exec("INSERT INTO websites (user_id,main_url,company_name,brand_name) VALUES ($u,'https://vis2.example.com','V','V')");
        $wid = (int) $pdo->lastInsertId();
        $snap = json_encode(['coverage' => ['audited' => 20], 'partial' => false]);
        $ins = $pdo->prepare("INSERT INTO seo_expert_runs (website_id,user_id,request_key,status,version,snapshot_json,created_at,updated_at) VALUES (?,?,?,'completed',1,?,NOW(),NOW())");
        $ins->execute([$wid, $u, 'old', json_encode(['coverage' => ['audited' => 20]])]);
        $ins->execute([$wid, $u, 'new', $snap]);
        $newRun = (int) $pdo->lastInsertId();
        $oldRun = $newRun - 1;
        $task = $pdo->prepare("INSERT INTO seo_expert_tasks (run_id,website_id,user_id,kind,category,priority,page_url,page_path,title,instructions,status,is_active,created_at,updated_at) VALUES (?,?,?,'manual','seo',?,?,?,?,'x',?,0,NOW(),NOW())");
        $task->execute([$newRun, $wid, $u, 'high', 'https://x/a', '/a', 'fix a', 'active']);
        $task->execute([$newRun, $wid, $u, 'low', 'https://x/b', '/b', 'done b', 'verified']);
        $task->execute([$oldRun, $wid, $u, 'high', 'https://x/c', '/c', 'old c', 'active']);
        try {
            $ov = (new VisibilityScoreService($db))->overview($u, $wid);
            $this->assertSame(95, $ov['seo']['score']); // 1 of 20 pages has an open task in the latest run
            $this->assertSame(['fix a'], array_column($ov['actions'], 'title'));
        } finally {
            $pdo->exec("DELETE FROM seo_expert_tasks WHERE user_id=$u");
            $pdo->exec("DELETE FROM seo_expert_runs WHERE user_id=$u");
            $pdo->exec("DELETE FROM websites WHERE id=$wid");
        }
    }

    public function testAeoCoverageFeedsTheDashboardAndIsTenantScoped(): void
    {
        $db = $this->db();
        if (!$db) $this->markTestSkipped('DB غير متاحة');
        $pdo = $db->getConnection();
        if (!$pdo->query("SHOW TABLES LIKE 'seo_aeo_pages'")->fetchAll()) $this->markTestSkipped('seo_aeo_pages غير موجود');
        $u = self::OWNER;
        $pdo->exec("DELETE FROM seo_aeo_pages WHERE user_id IN ($u," . self::OTHER . ")");
        $pdo->exec("DELETE FROM websites WHERE user_id=$u");
        $pdo->exec("INSERT INTO users (id,email,password,company_name,created_at) VALUES ($u,'vis-$u@tourfecto.test','x','V',NOW()) ON DUPLICATE KEY UPDATE email=email");
        $pdo->exec("INSERT INTO websites (user_id,main_url,company_name,brand_name) VALUES ($u,'https://vis3.example.com','V','V')");
        $wid = (int) $pdo->lastInsertId();
        try {
            $this->assertSame('not_measured', (new VisibilityScoreService($db))->overview($u, $wid)['aeo']['state']);
            $this->assertTrue(AeoCoverageStore::record($db, $u, $wid, 'https://vis3.example.com/tour/a', ['has_faq_schema' => true], 3));
            $this->assertTrue(AeoCoverageStore::record($db, $u, $wid, 'https://vis3.example.com/tour/b', ['has_faq_schema' => false], 0));
            $this->assertTrue(AeoCoverageStore::record($db, $u, $wid, 'https://vis3.example.com/tour/b', ['has_faq_schema' => false], 1)); // upsert, not duplicate
            $aeo = (new VisibilityScoreService($db))->overview($u, $wid)['aeo'];
            $this->assertSame(50, $aeo['score']);
            $this->assertSame(2, $aeo['pages']);
            $this->assertSame('low', $aeo['confidence']);
            $this->assertSame(0, AeoCoverageStore::summary($db, self::OTHER, $wid)['total']);
            // صفحة اتفحصت وطلعت مش رحلة: ما بتدخلش في التغطية، ومتعلّم عليها كمستبعدة
            $this->assertTrue(AeoCoverageStore::recordNotATrip($db, $u, $wid, 'https://vis3.example.com/tours/category'));
            $sum = AeoCoverageStore::summary($db, $u, $wid);
            $this->assertSame(2, $sum['total']);
            $this->assertSame(1, $sum['excluded']);
            $this->assertSame(50, (new VisibilityScoreService($db))->overview($u, $wid)['aeo']['score']);
            $this->assertSame(['https://vis3.example.com/tour/a'], array_slice(AeoCoverageStore::nextUrls($db, $u, $wid, ['https://vis3.example.com/tour/a', 'https://vis3.example.com/tour/new'], 5), 1));
        } finally {
            $pdo->exec("DELETE FROM seo_aeo_pages WHERE website_id=$wid");
            $pdo->exec("DELETE FROM websites WHERE id=$wid");
        }
    }

    public function testOverviewIsTenantIsolated(): void
    {
        $db = $this->db();
        if (!$db) $this->markTestSkipped('DB غير متاحة');
        $pdo = $db->getConnection();
        foreach ([self::OWNER, self::OTHER] as $u) {
            $pdo->exec("DELETE FROM seo_reports WHERE user_id=$u");
            $pdo->exec("DELETE FROM websites WHERE user_id=$u");
            $pdo->exec("INSERT INTO users (id,email,password,company_name,created_at) VALUES ($u,'vis-$u@tourfecto.test','x','V',NOW()) ON DUPLICATE KEY UPDATE email=email");
        }
        $pdo->exec("INSERT INTO websites (user_id,main_url,company_name,brand_name) VALUES (" . self::OWNER . ",'https://vis.example.com','V','V')");
        $wid = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO seo_reports (website_id,user_id,overall_score,findings_total,fixes_applied,source,created_at) VALUES ($wid," . self::OWNER . ",72,3,0,'manual',NOW())");

        $svc = new VisibilityScoreService($db);
        $ov = $svc->overview(self::OWNER, $wid);
        $this->assertSame(72, $ov['seo']['score']);
        $this->assertSame('not_measured', $ov['aeo']['state']);
        $this->assertSame('not_measured', $ov['impact']['state']);
        $this->assertFalse($ov['impact']['causality_proven']);

        $this->expectException(InvalidArgumentException::class);
        try {
            $svc->overview(self::OTHER, $wid);
        } finally {
            $pdo->exec("DELETE FROM seo_reports WHERE website_id=$wid");
            $pdo->exec("DELETE FROM websites WHERE id=$wid");
        }
    }
}

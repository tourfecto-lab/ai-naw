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

        $this->expectException(InvalidArgumentException::class);
        try {
            $svc->overview(self::OTHER, $wid);
        } finally {
            $pdo->exec("DELETE FROM seo_reports WHERE website_id=$wid");
            $pdo->exec("DELETE FROM websites WHERE id=$wid");
        }
    }
}

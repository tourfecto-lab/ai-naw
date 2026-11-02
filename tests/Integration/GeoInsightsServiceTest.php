<?php

use PHPUnit\Framework\TestCase;

/** تتبع GEO: تجميع من إجابات مخزّنة (منطق صرف) + عزل Tenant على قاعدة الاختبار. */
final class GeoInsightsServiceTest extends TestCase
{
    private const OWNER = 999811;
    private const OTHER = 999812;
    private const NOW = '2026-11-02 12:00:00';

    private function row(string $provider, string $q, bool $mentioned, ?int $cited, array $competitors = [], array $urls = [], string $week = '2026-10-26', string $at = '2026-10-27 10:00:00'): array
    {
        return ['market_id' => 1, 'provider' => $provider, 'question' => $q, 'week_start' => $week, 'mentioned_brand' => (int) $mentioned, 'brand_position' => $mentioned ? 1 : null,
            'cited_domain' => $cited, 'cited_urls' => json_encode($urls), 'competitors_mentioned' => json_encode($competitors), 'checked_at' => $at];
    }

    public function testNoAnswersIsNotMeasuredAndNeverInventsNumbers(): void
    {
        $r = GeoInsightsService::compose([], [], 'x.com', self::NOW);
        $this->assertSame('not_measured', $r['state']);
        $this->assertSame(0, $r['answers']);
        $this->assertSame([], $r['recommendations']);
    }

    public function testTrendCompetitorsCitationsAndPromptsFromStoredAnswers(): void
    {
        $rows = [
            $this->row('openai', 'Best Nile cruise?', false, 0, [['name' => 'RivalTours', 'domain' => 'rival.com']], ['https://rival.com/nile', 'https://www.x.com/tour/nile']),
            $this->row('gemini', 'Best Nile cruise?', false, null, [['name' => 'RivalTours', 'domain' => 'rival.com']], ['https://rival.com/nile']),
            $this->row('openai', 'Abu Simbel price?', true, 1, [], ['https://x.com/tour/abu-simbel']),
            $this->row('openai', 'Old question', true, 1, [], [], '2026-10-19', '2026-10-20 10:00:00'),
        ];
        $r = GeoInsightsService::compose($rows, [['name' => 'RivalTours', 'domain' => 'rival.com']], 'x.com', self::NOW);
        $this->assertSame('measured_sample', $r['state']);
        $this->assertSame(4, $r['answers']);
        $this->assertSame('low', $r['confidence']);
        $this->assertFalse($r['stale']);

        $weeks = array_column($r['trend'], 'week');
        $this->assertSame(['2026-10-19', '2026-10-26'], $weeks);
        $w2 = $r['trend'][1];
        $this->assertSame(round(1 / 3, 4), $w2['all']['share_of_voice']);
        $this->assertSame(0.5, $w2['all']['citation_rate']); // 1 of 2 answers with citation data
        $this->assertEqualsWithDelta(round(1 / 3, 4) - 1.0, $w2['delta_share_of_voice'], 0.0001);

        $c = $r['competitors']['list'][0];
        $this->assertSame('RivalTours', $c['name']);
        $this->assertSame(2, $c['answers']);
        $this->assertSame(2, $c['mentioned_without_us']);
        $this->assertTrue($c['registered']);
        $this->assertSame(2, $r['competitors']['brand']['answers']); // we are mentioned in 2 of 4 answers
        $this->assertSame(0.5, $r['competitors']['brand']['share']);

        $d = array_column($r['citations']['domains'], null, 'domain');
        $this->assertSame('competitor', $d['rival.com']['kind']);
        $this->assertSame('own', $d['x.com']['kind']);
        $this->assertContains('https://www.x.com/tour/nile', array_column($r['citations']['own_pages'], 'url'));

        $p = array_column($r['prompts'], null, 'question');
        $this->assertSame('lost', $p['Best Nile cruise?']['status']);
        $this->assertSame(['RivalTours'], $p['Best Nile cruise?']['competitors']);
        $this->assertSame('won', $p['Abu Simbel price?']['status']);
        $this->assertSame('lost', array_column($r['prompts'], 'status')[0]); // lost first
    }

    public function testRecommendationsAreEvidenceBasedAndPrioritised(): void
    {
        $rows = [
            $this->row('openai', 'Q competitor', false, 0, [['name' => 'Rival']]),
            $this->row('openai', 'Q open', false, 0, []),
            $this->row('openai', 'Q uncited', true, 0, []),
            $this->row('openai', 'Q won', true, 1, []),
        ];
        $recs = GeoInsightsService::compose($rows, [], 'x.com', self::NOW)['recommendations'];
        $this->assertSame(['competitor_ahead', 'open_question', 'mentioned_not_cited'], array_column($recs, 'kind'));
        $this->assertSame(['Rival'], $recs[0]['evidence']['competitors']);
        $this->assertNotContains('Q won', array_column($recs, 'question'));
        $this->assertStringContainsString('Rival', $recs[0]['action_en']);
    }

    public function testStaleWhenLatestAnswerIsOld(): void
    {
        $r = GeoInsightsService::compose([$this->row('openai', 'Q', true, 1, [], [], '2026-09-01', '2026-09-02 10:00:00')], [], 'x.com', self::NOW);
        $this->assertTrue($r['stale']);
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
            if (!class_exists('GeoInsightsService')) require_once "$app/Config/project_classmap.php";
            $db = Database::getInstance();
            foreach (['users', 'websites', 'seo_ai_answers'] as $t) {
                if (!$db->getConnection()->query("SHOW TABLES LIKE '$t'")->fetchAll()) return null;
            }
            return $db;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function testNotMeasuredSiteExplainsWhyWithoutPaidCalls(): void
    {
        $db = $this->db();
        if (!$db) $this->markTestSkipped('DB غير متاحة');
        $pdo = $db->getConnection();
        $u = self::OWNER;
        $pdo->exec("DELETE FROM seo_ai_answers WHERE user_id=$u");
        $pdo->exec("DELETE FROM websites WHERE user_id=$u");
        $pdo->exec("INSERT INTO users (id,email,password,company_name,created_at) VALUES ($u,'geo-$u@tourfecto.test','x','G',NOW()) ON DUPLICATE KEY UPDATE email=email");
        $pdo->exec("INSERT INTO websites (user_id,main_url,company_name,brand_name) VALUES ($u,'https://geo2.example.com','G','G')");
        $wid = (int) $pdo->lastInsertId();
        try {
            $ov = (new GeoInsightsService($db))->overview($u, $wid);
            $this->assertSame('not_measured', $ov['state']);
            $this->assertNotEmpty($ov['diagnosis']);
            foreach ($ov['diagnosis'] as $d) {
                $this->assertNotSame('', $d['code']);
                $this->assertNotSame('', $d['message_ar']);
                $this->assertNotSame('', $d['message_en']);
            }
            $this->assertContains('no_provider_configured', array_column($ov['diagnosis'], 'code')); // no keys in the test DB
            $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM seo_ai_calls WHERE website_id=$wid")->fetchColumn()); // diagnosis never reserves/spends
        } finally {
            $pdo->exec("DELETE FROM websites WHERE id=$wid");
        }
    }

    public function testOverviewReadsOnlyTheOwnersAnswers(): void
    {
        $db = $this->db();
        if (!$db) $this->markTestSkipped('DB غير متاحة');
        $pdo = $db->getConnection();
        foreach ([self::OWNER, self::OTHER] as $u) {
            $pdo->exec("DELETE FROM seo_ai_answers WHERE user_id=$u");
            $pdo->exec("DELETE FROM websites WHERE user_id=$u");
            $pdo->exec("INSERT INTO users (id,email,password,company_name,created_at) VALUES ($u,'geo-$u@tourfecto.test','x','G',NOW()) ON DUPLICATE KEY UPDATE email=email");
        }
        $pdo->exec("INSERT INTO websites (user_id,main_url,company_name,brand_name) VALUES (" . self::OWNER . ",'https://geo.example.com','G','G')");
        $wid = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO seo_ai_answers (call_id,website_id,user_id,market_id,provider,question,question_hash,week_start,set_version,mentioned_brand,brand_position,cited_domain,cited_urls,competitors_mentioned,answer_excerpt,model,cost_basis,usage_json,checked_at)
            VALUES (?,?,?,1,'openai',?,?,UTC_DATE(),1,?,NULL,0,'[]','[{\"name\":\"Rival\"}]','x','m','test','{}',UTC_TIMESTAMP())");
        $base = random_int(900000000, 990000000);
        $ins->execute([$base, $wid, self::OWNER, 'Owner question', sha1('a'), 0]);
        $ins->execute([$base + 1, $wid, self::OTHER, 'Other tenant question', sha1('b'), 1]); // same website id, different user: must not leak
        try {
            $svc = new GeoInsightsService($db);
            $ov = $svc->overview(self::OWNER, $wid);
            $this->assertSame(1, $ov['answers']);
            $this->assertSame(['Owner question'], array_column($ov['prompts'], 'question'));
            $this->assertSame('lost', $ov['prompts'][0]['status']);
            $this->assertSame('competitor_ahead', $ov['recommendations'][0]['kind']);
            $this->expectException(InvalidArgumentException::class);
            $svc->overview(self::OTHER, $wid);
        } finally {
            $pdo->exec("DELETE FROM seo_ai_answers WHERE website_id=$wid");
            $pdo->exec("DELETE FROM websites WHERE id=$wid");
        }
    }
}

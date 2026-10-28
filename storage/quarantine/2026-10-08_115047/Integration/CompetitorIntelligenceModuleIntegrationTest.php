<?php

/**
 * Tourfecto - Competitor Intelligence Module Integration Test (M5)
 * بيفحص فجوات Competitive Analysis CompetitorIntelligence:
 *   G1 تتبع ترتيب الكلمات المفتاحية (SERP Keyword Rankings)
 *   G6 Battlecards لإعداد فريق المبيعات
 *   G7 تتبع سعر لكل منتج/SKU بجدولة منتظمة
 *
 * محتاج الميجريشن 2026_08_29_000003_ci_keyword_rankings_product_prices_battlecards.sql.
 * بيتخطى تلقائيًا (markTestSkipped) لو DB غير متاحة أو الميجريشن لسه
 * ما اتشغّلش.
 * @version 1.0.0  @date 2026-08-29
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Core/Model.php';
require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Models/Competitor.php';
require_once __DIR__ . '/../../app/Models/CiKeywordRanking.php';
require_once __DIR__ . '/../../app/Models/CiProductPrice.php';
require_once __DIR__ . '/../../app/Models/CiBattlecard.php';
require_once __DIR__ . '/../../app/Models/CiSnapshot.php';
require_once __DIR__ . '/../../app/Models/CiScorecard.php';
require_once __DIR__ . '/../../app/Models/CiInsight.php';
require_once __DIR__ . '/../../app/Services/CompetitorIntelligence/CiConstants.php';
require_once __DIR__ . '/../../app/Services/CompetitorIntelligence/PriceExtractor.php';
require_once __DIR__ . '/../../app/Services/CompetitorIntelligence/ProductPriceTrackerService.php';
require_once __DIR__ . '/../../app/Services/CompetitorIntelligence/KeywordRankingService.php';
require_once __DIR__ . '/../../app/Services/CompetitorIntelligence/BattlecardService.php';
require_once __DIR__ . '/../../app/Core/Contracts/KeywordRankingSourceInterface.php';
require_once __DIR__ . '/../../app/Services/CompetitorIntelligence/NullKeywordRankingSource.php';
require_once __DIR__ . '/../../app/Services/CompetitorIntelligence/CompetitorDomain.php';

final class CompetitorIntelligenceModuleIntegrationTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static bool $dbChecked = false;
    private static int $userId = 0;
    private static int $websiteId = 0;
    private static array $competitorIds = [];

    private function db(): ?PDO
    {
        if (self::$dbChecked) {
            return self::$pdo;
        }
        self::$dbChecked = true;

        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) {
                foreach ([$app . '/Config/app.php', $app . '/Config/database.php'] as $cfg) {
                    if (file_exists($cfg)) {
                        require_once $cfg;
                    }
                }
            }
            if (!class_exists('Database')) {
                require_once $app . '/Core/Database.php';
            }

            $db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $conn = $ref->getValue($db);
            if (!$conn instanceof PDO) {
                self::$pdo = null;
                return null;
            }

            $tables = ['ci_keyword_rankings', 'ci_product_prices', 'ci_battlecards'];
            foreach ($tables as $t) {
                if (empty($conn->query("SHOW TABLES LIKE '{$t}'")->fetchAll())) {
                    self::$pdo = null;
                    return null;
                }
            }
            self::$pdo = $conn;
            return self::$pdo;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function setUp(): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            $this->markTestSkipped('DB غير متاحة أو ميجريشن M5 (keyword_rankings/product_prices/battlecards) لسه ما اتشغّلش');
        }
        if (self::$userId === 0) {
            self::$userId = createTestUser();
            self::$websiteId = createTestWebsite(self::$userId);
        }
        if (empty(self::$competitorIds)) {
            self::$competitorIds[] = $this->addCompetitor('rival' . uniqid() . '.example', 'Rival One');
            self::$competitorIds[] = $this->addCompetitor('rival2' . uniqid() . '.example', 'Rival Two');
        }
    }

    protected function tearDown(): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return;
        }
        $uid = (int) self::$userId;
        foreach (self::$competitorIds as $cid) {
            $cid = (int) $cid;
            $pdo->exec("DELETE FROM ci_battlecards WHERE competitor_id = {$cid}");
            $pdo->exec("DELETE FROM ci_product_prices WHERE competitor_id = {$cid}");
            $pdo->exec("DELETE FROM ci_keyword_rankings WHERE competitor_id = {$cid}");
            $pdo->exec("DELETE FROM ci_insights WHERE competitor_id = {$cid}");
            $pdo->exec("DELETE FROM ci_scorecards WHERE competitor_id = {$cid}");
            $pdo->exec("DELETE FROM ci_snapshots WHERE competitor_id = {$cid}");
            $pdo->exec("DELETE FROM competitors WHERE id = {$cid} AND user_id = {$uid}");
        }
        self::$competitorIds = [];
    }

    private function addCompetitor(string $domain, string $name = ''): int
    {
        $pdo = $this->db();
        $stmt = $pdo->prepare(
            "INSERT INTO competitors (user_id, website_id, competitor_domain, competitor_name, is_active, source)
             VALUES (?, ?, ?, ?, 1, 'manual')"
        );
        $stmt->execute([self::$userId, self::$websiteId, $domain, $name ?: $domain]);
        return (int) $pdo->lastInsertId();
    }

    private function competitorId(int $idx = 0): int
    {
        return (int) self::$competitorIds[$idx];
    }

    // ============================ G1: Keyword Rankings ============================

    public function testRecordRankingValidatesAndSaves(): void
    {
        $cid = $this->competitorId(0);
        $service = new KeywordRankingService();

        $ok = $service->recordRanking($cid, 'desert safari dubai', 4, 'https://rival.example/safari');
        $this->assertTrue($ok['success']);
        $this->assertSame(4, $ok['ranking']['position']);
        $this->assertSame('desert safari dubai', $ok['ranking']['keyword']);

        $invalidKeyword = $service->recordRanking($cid, '   ', 5);
        $this->assertFalse($invalidKeyword['success']);

        $invalidPosition = $service->recordRanking($cid, 'keyword x', 150);
        $this->assertFalse($invalidPosition['success']);

        $noPosition = $service->recordRanking($cid, 'out of top 100', null);
        $this->assertTrue($noPosition['success']);
        $this->assertNull($noPosition['ranking']['position']);
    }

    public function testListRankingsReturnsLatestBestAndTrend(): void
    {
        $cid = $this->competitorId(0);
        $service = new KeywordRankingService();

        $service->recordRanking($cid, 'camel ride', 10, null, 'manual', '2026-08-01 10:00:00');
        $service->recordRanking($cid, 'camel ride', 5, null, 'manual', '2026-08-08 10:00:00');

        $list = $service->listRankings($cid);
        $this->assertNotEmpty($list);
        $found = null;
        foreach ($list as $r) {
            if ($r['keyword'] === 'camel ride') {
                $found = $r;
                break;
            }
        }
        $this->assertNotNull($found);
        $this->assertSame(5, $found['position']);      // أحدث قياس
        $this->assertSame(5, $found['best_position']); // أفضل ترتيب
        $this->assertSame(-5, $found['trend']);        // تحسّن من 10 إلى 5
    }

    public function testHistoryReturnsChronologicalSeries(): void
    {
        $cid = $this->competitorId(1);
        $service = new KeywordRankingService();
        $service->recordRanking($cid, 'hot air balloon', 20, null, 'manual', '2026-08-01 10:00:00');
        $service->recordRanking($cid, 'hot air balloon', 12, null, 'manual', '2026-08-10 10:00:00');

        $history = $service->history($cid, 'hot air balloon');
        $this->assertCount(2, $history);
        $this->assertSame(20, (int) $history[0]['position']);
        $this->assertSame(12, (int) $history[1]['position']);
    }

    public function testScheduledCheckFailsSafeWithoutConfiguredSource(): void
    {
        $cid = $this->competitorId(0);
        $service = new KeywordRankingService(); // المصدر الافتراضي = Null

        $result = $service->runScheduledCheck($cid, 'rival.example', ['desert safari', 'camel ride']);
        $this->assertFalse($result['available']);
        $this->assertSame('no_keyword_ranking_source_configured', $result['reason']);
        $this->assertSame(0, $result['recorded']);
    }

    public function testScheduledCheckUsesConfiguredSourceAndRecords(): void
    {
        $cid = $this->competitorId(1);
        $fake = new FakeKeywordRankingSource();
        $service = new KeywordRankingService($fake);

        $result = $service->runScheduledCheck($cid, 'rival.example', ['desert safari', 'camel ride']);
        $this->assertTrue($result['available']);
        $this->assertSame(2, $result['recorded']);
        $this->assertSame('integration:fake_serp', $result['results'][0]['source']);

        $list = $service->listRankings($cid);
        $found = array_filter($list, static fn ($r) => $r['keyword'] === 'desert safari');
        $this->assertNotEmpty($found);
    }

    // ============================ G7: Product Price Tracking ============================

    public function testPriceExtractorExtractAllMultiplePrices(): void
    {
        $prices = PriceExtractor::extractAll(
            "Deluxe Room costs $199.00 /night\nStandard Room costs $89.00 /night\nPool Villa USD 450.00"
        );
        $this->assertCount(3, $prices);
        $amounts = array_map(static fn ($p) => $p['amount'], $prices);
        sort($amounts);
        $this->assertSame([89.0, 199.0, 450.0], $amounts);

        // كل سعر ليه سياق/اسم تقديري (غير فاضي للنص المسبوق)
        foreach ($prices as $p) {
            $this->assertNotNull($p['label']);
        }
    }

    public function testExtractAllDeduplicatesByCurrencyAmountLabel(): void
    {
        $prices = PriceExtractor::extractAll("Price: \$99\nPrice: \$99");
        $this->assertCount(1, $prices);
    }

    public function testRecordPriceValidatesAndSaves(): void
    {
        $cid = $this->competitorId(0);
        $tracker = new ProductPriceTrackerService();

        $ok = $tracker->recordPrice($cid, 'Deluxe Room', 199.0, 'USD', 'https://rival.example/pricing', 'pricing');
        $this->assertTrue($ok['success']);
        $this->assertSame('deluxe room', $ok['product_price']['product_name']);

        $badName = $tracker->recordPrice($cid, '   ', 10);
        $this->assertFalse($badName['success']);

        $badPrice = $tracker->recordPrice($cid, 'Product', -5);
        $this->assertFalse($badPrice['success']);
    }

    public function testTrackFromSnapshotExtractsPricesFromPricingPage(): void
    {
        $cid = $this->competitorId(1);
        $tracker = new ProductPriceTrackerService();

        $snapshot = new CiSnapshot([
            'competitor_id' => $cid,
            'page_type' => 'pricing',
            'url' => 'https://rival.example/pricing',
            'fetch_status' => 'ok',
            'normalized_excerpt' => "Deluxe Room costs \$199.00 /night\nStandard Room costs \$89.00 /night",
            'captured_at' => date('Y-m-d H:i:s'),
        ]);
        $snapshot->save();

        $result = $tracker->trackFromSnapshot($snapshot);
        $this->assertSame('pricing', $result['page_type']);
        $this->assertGreaterThanOrEqual(2, $result['extracted']);
        $this->assertGreaterThanOrEqual(2, $result['saved']);

        $products = $tracker->listProducts($cid);
        $this->assertGreaterThanOrEqual(2, count($products));
    }

    public function testTrackFromSnapshotIgnoresNonPricePages(): void
    {
        $cid = $this->competitorId(0);
        $tracker = new ProductPriceTrackerService();
        $snapshot = new CiSnapshot([
            'competitor_id' => $cid,
            'page_type' => 'blog',
            'url' => 'https://rival.example/blog',
            'fetch_status' => 'ok',
            'normalized_excerpt' => 'Our blog post about travel costs $50',
        ]);
        $snapshot->save();

        $result = $tracker->trackFromSnapshot($snapshot);
        $this->assertSame(0, $result['extracted']);
        $this->assertSame(0, $result['saved']);
    }

    public function testProductPriceHistoryAndListProducts(): void
    {
        $cid = $this->competitorId(0);
        $tracker = new ProductPriceTrackerService();
        $tracker->recordPrice($cid, 'Deluxe Room', 199.0, 'USD', null, 'pricing', '2026-08-01 10:00:00');
        $tracker->recordPrice($cid, 'Deluxe Room', 219.0, 'USD', null, 'pricing', '2026-08-10 10:00:00');
        $tracker->recordPrice($cid, 'Standard Room', 89.0, 'USD', null, 'pricing', '2026-08-10 10:00:00');

        $products = $tracker->listProducts($cid);
        $deluxe = array_values(array_filter($products, static fn ($p) => $p['product_name'] === 'deluxe room'))[0];
        $this->assertSame(219.0, $deluxe['latest_price']);
        $this->assertSame(199.0, $deluxe['first_price']);
        $this->assertSame(2, $deluxe['readings']);

        $history = $tracker->history($cid, 'Deluxe Room');
        $this->assertCount(2, $history);
        $this->assertSame(199.0, (float) $history[0]['price']);
        $this->assertSame(219.0, (float) $history[1]['price']);
    }

    // ============================ G6: Battlecards ============================

    public function testGenerateReturnsInsufficientDataWithoutAnyMonitoringData(): void
    {
        // منافس جديد بدون scorecard/insights/prices/changes -> لا اختلاق
        $fresh = $this->addCompetitor('fresh' . uniqid() . '.example', 'Fresh Rival');
        $result = (new BattlecardService())->generate(self::$userId, $fresh);

        $this->assertFalse($result['success']);
        $this->assertFalse($result['available']);
        $this->assertSame('insufficient_data', $result['error']);
    }

    public function testGenerateBuildsBattlecardFromRealData(): void
    {
        $cid = $this->competitorId(0);
        $pdo = $this->db();

        // بيانات حقيقية: scorecard + insight + أسعار منتجات
        $uid = self::$userId;
        $wid = self::$websiteId;
        $pdo->exec(
            "INSERT INTO ci_scorecards (competitor_id, period_start, period_end, visibility_score, content_activity_score, offer_activity_score, product_coverage_score, market_presence_score, basis, computed_at)
             VALUES ({$cid}, '2026-08-01', '2026-08-31', 70, 30, 55, 80, 40, 'data_backed', NOW())"
        );
        $pdo->exec(
            "INSERT INTO ci_insights (user_id, website_id, competitor_id, type, title, description, confidence, threat_level, status, generated_by, created_at)
             VALUES ({$uid}, {$wid}, {$cid}, 'opportunity', 'Strong visibility opportunity', 'Competitor ranks high on visibility', 'high', 'low', 'new', 'rules_engine', NOW())"
        );
        (new ProductPriceTrackerService())->recordPrice($cid, 'Deluxe Room', 199.0, 'USD', null, 'pricing');

        $result = (new BattlecardService())->generate(self::$userId, $cid);
        $this->assertTrue($result['success']);
        $this->assertTrue($result['available']);

        $battlecard = $result['battlecard'];
        $this->assertStringContainsString('Battlecard', $battlecard['title']);

        $strengths = json_decode((string) $battlecard['strengths'], true);
        $weaknesses = json_decode((string) $battlecard['weaknesses'], true);
        $prices = json_decode((string) $battlecard['price_position'], true);
        $actions = json_decode((string) $battlecard['recommended_actions'], true);

        $this->assertNotEmpty($strengths);   // visibility 70 >= 60 + opportunity insight
        $this->assertNotEmpty($weaknesses);  // content_activity 30 <= 40
        $this->assertNotEmpty($prices);      // Deluxe Room
        $this->assertNotEmpty($actions);
    }

    public function testLatestReturnsMostRecentBattlecard(): void
    {
        $cid = $this->competitorId(1);
        $pdo = $this->db();
        $pdo->exec(
            "INSERT INTO ci_scorecards (competitor_id, period_start, period_end, visibility_score, content_activity_score, offer_activity_score, product_coverage_score, market_presence_score, basis, computed_at)
             VALUES ({$cid}, '2026-08-01', '2026-08-31', 65, 65, 65, 65, 65, 'data_backed', NOW())"
        );

        $generate = (new BattlecardService())->generate(self::$userId, $cid);
        $this->assertTrue($generate['success']);

        $latest = (new BattlecardService())->latest(self::$userId, $cid);
        $this->assertNotNull($latest);
        $this->assertSame((int) $generate['battlecard']['id'], (int) $latest['id']);
    }

    public function testListForUserReturnsCardsAcrossCompetitors(): void
    {
        $pdo = $this->db();
        $cards = [];
        foreach ([0, 1] as $idx) {
            $cid = $this->competitorId($idx);
            $pdo->exec(
                "INSERT INTO ci_scorecards (competitor_id, period_start, period_end, visibility_score, content_activity_score, offer_activity_score, product_coverage_score, market_presence_score, basis, computed_at)
                 VALUES ({$cid}, '2026-08-01', '2026-08-31', 50, 50, 50, 50, 50, 'estimated', NOW())"
            );
            $r = (new BattlecardService())->generate(self::$userId, $cid);
            if ($r['success']) {
                $cards[] = $r['battlecard']['id'];
            }
        }
        $list = (new BattlecardService())->listForUser(self::$userId);
        $this->assertGreaterThanOrEqual(2, count($list));
    }
}

/** مصدر SERP وهمي حقيقي السلوك لاختبار مسار التكامل (بدون شبكة). */
class FakeKeywordRankingSource implements KeywordRankingSourceInterface
{
    public function isConfigured(): bool
    {
        return true;
    }

    public function sourceName(): string
    {
        return 'fake_serp';
    }

    public function check(string $domain, array $keywords): array
    {
        $rankings = [];
        foreach ($keywords as $i => $kw) {
            $rankings[] = ['keyword' => $kw, 'position' => $i + 1, 'url' => 'https://' . $domain . '/' . strtolower(str_replace(' ', '-', $kw))];
        }
        return ['available' => true, 'reason' => null, 'rankings' => $rankings];
    }
}

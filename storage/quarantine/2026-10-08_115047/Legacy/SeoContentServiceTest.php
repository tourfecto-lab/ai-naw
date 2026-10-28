<?php

/**
 * Tourfecto - SEO Content Engine Service Test (Phase 24)
 * اختبارات منطق SeoContentService (اكتشاف مواضيع + إنشاء حملات + تجميع CTR
 * + توليد عنوان بديل لتجربة A/B) - offline بالكامل (من غير قاعدة بيانات
 * حقيقية ولا LLM) باستخدام FakeDatabase بيعيد تعريف query()/exec().
 *
 * التشغيل: php tests/Unit/SeoContentServiceTest.php
 * @version 1.0.0
 * @date 2026-08-20
 */

require_once __DIR__ . '/../../app/Core/Database.php';
require_once __DIR__ . '/../../app/Core/Queue/QueueManager.php';
require_once __DIR__ . '/../../app/Services/Seo/SeoAbTestService.php';
require_once __DIR__ . '/../../app/Services/Seo/SeoPerformanceService.php';
require_once __DIR__ . '/../../app/Services/Seo/SeoContentService.php';

/**
 * نسخة وهمية من Database بترجع بيانات ثابتة حسب اسم الجدول اللي في الاستعلام،
 * وبتحاكي INSERT بإرجاع معرف متزايد - من غير أي اتصال بـ PDO.
 */
class FakeDatabase extends Database
{
    /** @var array map: اسم الجدول => صفوف تُرجع لاستعلامات SELECT */
    public $rows = [];

    /** @var int معرف متزايد لمحاكاة lastInsertId */
    public $nextId = 1;

    /** @var array سجل استعلامات INSERT المنفّذة [sql, params] */
    public $inserts = [];

    public function __construct()
    {
        // من غير parent::__construct() - مفيش اتصال حقيقي
    }

    public function query(string $sql, array $params = [], int $fetchMode = PDO::FETCH_ASSOC)
    {
        $upper = strtoupper(trim($sql));

        if (strpos($upper, 'INSERT') === 0) {
            $this->inserts[] = [$sql, $params];
            return $this->nextId++;
        }

        if (strpos($upper, 'INFORMATION_SCHEMA') !== false) {
            $table = $params[0] ?? '';
            return isset($this->rows[$table]) ? [['ok' => 1]] : [];
        }

        foreach (array_keys($this->rows) as $table) {
            if (stripos($sql, $table) !== false) {
                return $this->rows[$table];
            }
        }
        return [];
    }

    public function exec(string $sql, array $params = []): bool
    {
        return true;
    }
}

class SeoContentServiceTest
{
    private $passed = 0;
    private $failed = 0;

    public function runAll(): void
    {
        echo "\nSEO Content Engine Service Tests\n";
        echo "================================\n";

        $this->testDiscoverTopicsFromKeywords();
        $this->testDiscoverTopicsEmpty();
        $this->testCreateCampaignValidation();
        $this->testCreateCampaignInsertsAndSkipsEmptyTopics();
        $this->testCampaignStatsAggregatesCtr();
        $this->testDeriveVariantTitleAppendsKeyword();
        $this->testDeriveVariantTitleKeepsExistingKeyword();
        $this->testPendingGenerationItems();
        $this->testApplyWinningTitleAppliesWinner();
        $this->testApplyWinningTitleNoWinner();
        $this->testRunEngineCycleSummary();

        $this->printSummary();
    }

    private function testDiscoverTopicsFromKeywords(): void
    {
        $db = $this->makeDb([
            'tracked_keywords' => [
                ['keyword' => 'فنادق القاهرة', 'current_position' => 8, 'search_volume' => 5400, 'difficulty' => 40, 'opportunity_score' => 85, 'priority' => 'high'],
                ['keyword' => 'رحلات سفاري', 'current_position' => null, 'search_volume' => 1200, 'difficulty' => 30, 'opportunity_score' => 60, 'priority' => 'medium'],
            ],
        ]);

        $service = new SeoContentService($db);
        $topics = $service->discoverTopics(1, 'keywords');

        $this->assertTrue(count($topics) === 2, 'discoverTopics returns 2 topics');
        $this->assertTrue($topics[0]['topic'] === 'فنادق القاهرة', 'first topic keyword mapped');
        $this->assertTrue($topics[0]['keyword'] === 'فنادق القاهرة', 'first topic keyword preserved');
        $this->assertTrue($topics[0]['opportunity_score'] === 85, 'opportunity_score cast to int');
        $this->assertTrue($topics[0]['priority'] === 'high', 'priority preserved');
        $this->assertTrue($topics[0]['current_position'] === 8, 'current_position cast to int');
        $this->assertTrue($topics[1]['current_position'] === null, 'null current_position preserved as null');
    }

    private function testDiscoverTopicsEmpty(): void
    {
        $db = $this->makeDb(['tracked_keywords' => []]);
        $service = new SeoContentService($db);
        $this->assertTrue($service->discoverTopics(1, 'keywords') === [], 'no tracked keywords => empty topics');
    }

    private function testCreateCampaignValidation(): void
    {
        $db = $this->makeDb([]);
        $service = new SeoContentService($db);

        $r1 = $service->createCampaign(1, 1, '', ['topic a']);
        $this->assertTrue($r1['success'] === false, 'empty name rejected');

        $r2 = $service->createCampaign(1, 1, 'My Campaign', []);
        $this->assertTrue($r2['success'] === false, 'empty topics rejected');
    }

    private function testCreateCampaignInsertsAndSkipsEmptyTopics(): void
    {
        $db = $this->makeDb([]);
        $service = new SeoContentService($db);

        $result = $service->createCampaign(1, 1, 'My Campaign', ['topic a', '', 'topic b'], 'keywords');

        $this->assertTrue($result['success'] === true, 'valid campaign created');
        $this->assertTrue($result['campaign_id'] === 1, 'campaign_id = 1 (first insert)');
        $this->assertTrue($result['item_count'] === 2, 'empty topic skipped => 2 items');
        $this->assertTrue(count($db->inserts) === 3, '1 campaign insert + 2 item inserts');
    }

    private function testCampaignStatsAggregatesCtr(): void
    {
        $db = $this->makeDb([
            'seo_content_campaigns' => [
                ['id' => 1, 'user_id' => 1, 'website_id' => 1, 'name' => 'C', 'status' => 'ready', 'total_items' => 2, 'generated_items' => 2],
            ],
            'seo_content_items' => [
                ['id' => 1, 'topic' => 'فنادق القاهرة', 'title' => 'Best Cairo Hotels', 'slug' => 'cairo-hotels', 'status' => 'indexed', 'ab_test_id' => null, 'indexnow_code' => 202],
                ['id' => 2, 'topic' => 'رحلات سفاري', 'title' => 'Safari Trips', 'slug' => 'safari-trips', 'status' => 'generated', 'ab_test_id' => null, 'indexnow_code' => null],
            ],
            'seo_gsc_page_metrics' => [
                ['page_path' => '/blog/cairo-hotels', 'clicks' => 80, 'impressions' => 1000, 'ctr' => 8.0, 'position' => 3.1],
                ['page_path' => '/blog/safari-trips', 'clicks' => 150, 'impressions' => 1000, 'ctr' => 15.0, 'position' => 2.2],
            ],
        ]);

        $service = new SeoContentService($db);
        $stats = $service->campaignStats(1);

        $this->assertTrue($stats['success'] === true, 'campaignStats succeeds');
        $this->assertTrue(count($stats['items']) === 2, '2 items returned');
        $this->assertTrue($stats['totals']['clicks'] === 230, 'total clicks = 230');
        $this->assertTrue($stats['totals']['impressions'] === 2000, 'total impressions = 2000');
        $this->assertTrue($stats['totals']['ctr'] === 11.5, 'total CTR = 11.5%');
        $this->assertTrue($stats['items'][0]['ctr'] === 8.0, 'item 1 CTR mapped from cache');
        $this->assertTrue($stats['items'][0]['indexnow_code'] === 202, 'item indexnow_code preserved');
    }

    private function testDeriveVariantTitleAppendsKeyword(): void
    {
        $db = $this->makeDb([
            'seo_content_campaigns' => [
                ['id' => 1, 'user_id' => 1, 'website_id' => 1, 'name' => 'C'],
            ],
            'seo_content_items' => [
                ['id' => 1, 'campaign_id' => 1, 'title' => 'Best Cairo Hotels', 'slug' => 'cairo-hotels', 'keyword' => 'فنادق القاهرة', 'topic' => 'فنادق القاهرة'],
            ],
        ]);

        $service = new SeoContentService($db);
        $result = $service->createTitleAbTest(1);

        $this->assertTrue($result['success'] === true, 'A/B test created');
        $this->assertTrue($result['variant_title'] === 'Best Cairo Hotels | فنادق القاهرة', 'variant title appends keyword');
        $this->assertTrue($result['test_id'] === 1, 'test_id = 1');
    }

    private function testDeriveVariantTitleKeepsExistingKeyword(): void
    {
        $db = $this->makeDb([
            'seo_content_campaigns' => [
                ['id' => 1, 'user_id' => 1, 'website_id' => 1, 'name' => 'C'],
            ],
            'seo_content_items' => [
                ['id' => 1, 'campaign_id' => 1, 'title' => 'دليل فنادق القاهرة', 'slug' => 'cairo-hotels', 'keyword' => 'فنادق القاهرة', 'topic' => 'فنادق القاهرة'],
            ],
        ]);

        $service = new SeoContentService($db);
        $result = $service->createTitleAbTest(1);

        $this->assertTrue($result['success'] === true, 'A/B test created (keyword already in title)');
        $this->assertTrue($result['variant_title'] === 'دليل فنادق القاهرة (دليل شامل)', 'variant uses generic suffix when keyword present');
    }

    private function testPendingGenerationItems(): void
    {
        $db = $this->makeDb([
            'seo_content_items' => [
                ['id' => 1, 'campaign_id' => 10, 'topic' => 'topic a'],
                ['id' => 2, 'campaign_id' => 10, 'topic' => 'topic b'],
            ],
        ]);
        $service = new SeoContentService($db);
        $items = $service->pendingGenerationItems(20);
        $this->assertTrue(count($items) === 2, 'pending generation returns queued items');
        $this->assertTrue($items[0]['campaign_id'] === 10, 'item campaign_id preserved');
    }

    private function testApplyWinningTitleAppliesWinner(): void
    {
        $db = $this->makeDb([
            'seo_content_campaigns' => [
                ['id' => 1, 'user_id' => 1, 'website_id' => 1, 'name' => 'C', 'total_items' => 1, 'generated_items' => 1],
            ],
            'seo_content_items' => [
                ['id' => 1, 'campaign_id' => 1, 'article_id' => 5, 'title' => 'Old Title', 'slug' => 'x', 'status' => 'testing', 'ab_test_id' => 9],
            ],
            'seo_ab_tests' => [
                ['id' => 9, 'winner_variant_id' => 3],
            ],
            'seo_ab_variants' => [
                ['id' => 3, 'value' => 'Winning SEO Title'],
            ],
        ]);
        $service = new SeoContentService($db);
        $result = $service->applyWinningTitleToItem(1);

        $this->assertTrue($result['success'] === true, 'winner applied');
        $this->assertTrue($result['winner_title'] === 'Winning SEO Title', 'winner title returned');
        $this->assertTrue($result['test_id'] === 9, 'test_id preserved');
        $this->assertTrue($result['item_id'] === 1, 'item_id preserved');
    }

    private function testApplyWinningTitleNoWinner(): void
    {
        $db = $this->makeDb([
            'seo_content_campaigns' => [
                ['id' => 1, 'user_id' => 1, 'website_id' => 1, 'name' => 'C'],
            ],
            'seo_content_items' => [
                ['id' => 1, 'campaign_id' => 1, 'article_id' => 5, 'title' => 'Old', 'slug' => 'x', 'status' => 'testing', 'ab_test_id' => 9],
            ],
            'seo_ab_tests' => [
                ['id' => 9, 'winner_variant_id' => null],
            ],
        ]);
        $service = new SeoContentService($db);
        $result = $service->applyWinningTitleToItem(1);
        $this->assertTrue($result['success'] === false, 'no winner => not applied');
    }

    /**
     * دورة المحرك: العناصر queued بتتجدول حملة واحدة للتوليد في الطابور،
     * وبتفضل كل الخطوات التانية (index/A-B/winner) صفر لأن الفيكتورة مبتعرفش
     * تفرّق حالات غير queued (بتتجاهل WHERE) - وده المطلوب إن الدورة تشتغل
     * بأمان من غير اعتماد على DB حقيقي ولا LLM.
     */
    private function testRunEngineCycleSummary(): void
    {
        $db = new FakeDatabase();
        $db->rows = [
            'jobs' => [],
            'seo_content_items' => [
                ['id' => 1, 'campaign_id' => 10, 'topic' => 'topic a'],
                ['id' => 2, 'campaign_id' => 10, 'topic' => 'topic b'],
            ],
        ];
        $service = new SeoContentService($db);
        $summary = $service->runEngineCycle();

        $this->assertTrue(is_array($summary), 'runEngineCycle returns array');
        $this->assertTrue(array_keys($summary) === ['campaigns_enqueued', 'indexed', 'ab_created', 'winner_applied'], 'summary has 4 keys');
        $this->assertTrue($summary['campaigns_enqueued'] === 1, 'one unique campaign enqueued for generation');
        $this->assertTrue($summary['indexed'] === 0, 'no index step executed');
        $this->assertTrue($summary['ab_created'] === 0, 'no A/B step executed');
        $this->assertTrue($summary['winner_applied'] === 0, 'no winner step executed');
        $this->assertTrue(count($db->inserts) === 1, 'exactly one job INSERT queued');
    }

    private function makeDb(array $rows): FakeDatabase
    {
        $db = new FakeDatabase();
        $db->rows = $rows;
        return $db;
    }

    private function assertTrue(bool $condition, string $message): void
    {
        if ($condition) {
            echo "    [PASS] {$message}\n";
            $this->passed++;
        } else {
            echo "    [FAIL] {$message}\n";
            $this->failed++;
        }
    }

    private function printSummary(): void
    {
        $total = $this->passed + $this->failed;
        $percentage = $total > 0 ? round(($this->passed / $total) * 100, 2) : 0;

        echo "\n" . str_repeat('=', 50) . "\n";
        echo "SEO Content Engine Service Summary\n";
        echo str_repeat('=', 50) . "\n";
        echo "  Passed: {$this->passed}\n";
        echo "  Failed: {$this->failed}\n";
        echo "  Total: {$total}\n";
        echo "  Success Rate: {$percentage}%\n";
        echo str_repeat('=', 50) . "\n\n";
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    $test = new SeoContentServiceTest();
    $test->runAll();
}

<?php

/**
 * Tourfecto - SEO A/B Testing Service Test
 * اختبارات منطق SeoAbTestService (توحيد الـ URL + تجميع مقاييس CTR).
 *
 * بيعمل offline بالكامل (من غير قاعدة بيانات حقيقية) باستخدام SeoAbFakeDatabase
 * بيعيد تعريف query()/exec() - نفس أسلوب الاختبارات النقية (Section 22).
 *
 * التشغيل: php tests/Unit/SeoAbTestServiceTest.php
 * @version 1.0.0
 * @date 2026-08-20
 */

require_once __DIR__ . '/../../app/Services/Seo/SeoAbTestService.php';
require_once __DIR__ . '/../../app/Core/Database.php';

/**
 * نسخة وهمية من Database بتعيد تعريف query()/exec() لترجع بيانات ثابتة
 * من غير أي اتصال بـ PDO - عشان نختبر منطق التجميع بشكل معزول.
 */
class SeoAbFakeDatabase extends Database
{
    public $variantRows = [];
    public $servingRows = [];

    public function __construct()
    {
        // مش بنستدعي parent::__construct() (private) - مفيش اتصال حقيقي
    }

    public function query(string $sql, array $params = [], int $fetchMode = PDO::FETCH_ASSOC)
    {
        if (strpos($sql, 'seo_ab_servings') !== false) {
            return $this->servingRows;
        }
        return $this->variantRows;
    }

    public function exec(string $sql, array $params = []): bool
    {
        return true;
    }
}

class SeoAbTestServiceTest
{
    private $passed = 0;
    private $failed = 0;

    public function runAll(): void
    {
        echo "\nSEO A/B Testing Service Tests\n";
        echo "=============================\n";

        $this->testNormalizePagePath();
        $this->testAggregateMetricsGroupsByVariant();
        $this->testAggregateMetricsIgnoresPagesWithoutGscData();
        $this->testAggregateMetricsNoWinnerBelowThreshold();
        $this->testAggregateMetricsEmptyVariants();

        $this->printSummary();
    }

    private function testNormalizePagePath(): void
    {
        $cases = [
            ['https://example.com', '/'],
            ['https://example.com/', '/'],
            ['https://example.com/about', '/about'],
            ['https://example.com/about?x=1', '/about?x=1'],
            ['https://example.com/a?x=1#frag', '/a?x=1'],
            ['/relative/path', '/relative/path'],
            ['', '/'],
        ];

        foreach ($cases as [$input, $expected]) {
            $actual = SeoAbTestService::normalizePagePath($input);
            $this->assertTrue(
                $actual === $expected,
                "normalizePagePath('{$input}') === '{$expected}' (got '{$actual}')"
            );
        }
    }

    private function testAggregateMetricsGroupsByVariant(): void
    {
        $db = $this->makeDb([
            ['id' => 3, 'name' => 'control', 'is_control' => 1, 'served_count' => 0],
            ['id' => 4, 'name' => 'variant B', 'is_control' => 0, 'served_count' => 0],
        ], [
            ['page_url' => 'https://example.com/about', 'variant_id' => 3],
            ['page_url' => 'https://example.com/pricing', 'variant_id' => 4],
        ]);

        $service = new SeoAbTestService($db);
        $result = $service->aggregateMetrics(1, [
            '/about'   => ['clicks' => 80, 'impressions' => 1000, 'ctr' => 8.0, 'position' => 3.1],
            '/pricing' => ['clicks' => 150, 'impressions' => 1000, 'ctr' => 15.0, 'position' => 2.2],
        ]);

        $byId = [];
        foreach ($result['variants'] as $v) {
            $byId[$v['id']] = $v;
        }

        $this->assertTrue(count($result['variants']) === 2, 'Two variants returned');
        $this->assertTrue($byId[3]['clicks'] === 80, 'control clicks = 80');
        $this->assertTrue($byId[3]['ctr'] === 8.0, 'control ctr = 8%');
        $this->assertTrue($byId[3]['avg_position'] === 3.1, 'control avg position = 3.1');
        $this->assertTrue($byId[4]['clicks'] === 150, 'variant B clicks = 150');
        $this->assertTrue($byId[4]['ctr'] === 15.0, 'variant B ctr = 15%');
        $this->assertTrue($result['suggested_winner_variant_id'] === 4, 'Variant B is suggested winner (higher CTR)');
    }

    private function testAggregateMetricsIgnoresPagesWithoutGscData(): void
    {
        $db = $this->makeDb([
            ['id' => 3, 'name' => 'control', 'is_control' => 1, 'served_count' => 0],
            ['id' => 4, 'name' => 'variant B', 'is_control' => 0, 'served_count' => 0],
        ], [
            ['page_url' => 'https://example.com/about', 'variant_id' => 3],
            ['page_url' => 'https://example.com/pricing', 'variant_id' => 4],
        ]);

        $service = new SeoAbTestService($db);
        // بس /about ليها بيانات في GSC - /pricing مفيهاش
        $result = $service->aggregateMetrics(1, [
            '/about' => ['clicks' => 200, 'impressions' => 5000, 'ctr' => 4.0, 'position' => 4.0],
        ]);

        $byId = [];
        foreach ($result['variants'] as $v) {
            $byId[$v['id']] = $v;
        }

        $this->assertTrue($byId[3]['pages'] === 1, 'control counted on 1 page');
        $this->assertTrue($byId[4]['pages'] === 0, 'variant B has no GSC data (0 pages)');
        $this->assertTrue($byId[4]['impressions'] === 0, 'variant B impressions = 0');
        $this->assertTrue($result['suggested_winner_variant_id'] === 3, 'control wins by default (only one with data)');
    }

    private function testAggregateMetricsNoWinnerBelowThreshold(): void
    {
        $db = $this->makeDb([
            ['id' => 3, 'name' => 'control', 'is_control' => 1, 'served_count' => 0],
            ['id' => 4, 'name' => 'variant B', 'is_control' => 0, 'served_count' => 0],
        ], [
            ['page_url' => 'https://example.com/about', 'variant_id' => 3],
            ['page_url' => 'https://example.com/pricing', 'variant_id' => 4],
        ]);

        $service = new SeoAbTestService($db);
        // ظهور أقل من العتبة (100) - مفيش فائز مقترح
        $result = $service->aggregateMetrics(1, [
            '/about'   => ['clicks' => 8, 'impressions' => 50, 'ctr' => 16.0, 'position' => 2.0],
            '/pricing' => ['clicks' => 4, 'impressions' => 50, 'ctr' => 8.0, 'position' => 3.0],
        ]);

        $this->assertTrue($result['suggested_winner_variant_id'] === null, 'No winner suggested below impressions threshold');
    }

    private function testAggregateMetricsEmptyVariants(): void
    {
        $db = $this->makeDb([], []);

        $service = new SeoAbTestService($db);
        $result = $service->aggregateMetrics(1, []);

        $this->assertTrue($result['variants'] === [], 'No variants => empty result');
        $this->assertTrue($result['suggested_winner_variant_id'] === null, 'No winner when no variants');
    }

    private function makeDb(array $variants, array $servings): SeoAbFakeDatabase
    {
        $db = new SeoAbFakeDatabase();
        $db->variantRows = $variants;
        $db->servingRows = $servings;
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
        echo "SEO A/B Testing Service Summary\n";
        echo str_repeat('=', 50) . "\n";
        echo "  Passed: {$this->passed}\n";
        echo "  Failed: {$this->failed}\n";
        echo "  Total: {$total}\n";
        echo "  Success Rate: {$percentage}%\n";
        echo str_repeat('=', 50) . "\n\n";
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    $test = new SeoAbTestServiceTest();
    $test->runAll();
}

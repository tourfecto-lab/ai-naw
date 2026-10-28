<?php

/**
 * Tourfecto - Revenue Intelligence Module Integration Test
 * بيتخطى تلقائيًا (markTestSkipped) لو DB غير متاحة أو الجداول المطلوبة
 * غير مثبتة (rev_revenue_records/crm_sales_goals/crm_deals).
 *
 * يغطي تحسينات موديول ذكاء الإيرادات (2026-08-29):
 *   1) G7 - RevenueQuotaService: قراءة crm_sales_goals (عزل تينانت)
 *      مع الإنجاز الفعلي من rev_revenue_records، إشارة منفصلة للصفقات
 *      المكسوبة، والتنبؤ من الصفقات المفتوحة المقررة في الشهر (وزن
 *      بالاحتمالية)، والفجوة والحالة.
 *   2) G2 - RevenueOverviewService::getRevenueByProduct: تجميع الإيراد
 *      حسب بُعد المنتج الاختياري (product_name/category) مع fallback
 *      آمن للمصدر وصدق "Not enough data".
 * @version 1.0.0  @date 2026-08-29
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/RevenueIntelligence/RevenueDataGateway.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/RevenueOverviewService.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/RevenueQuotaService.php';

final class RevenueModuleIntegrationTest extends TestCase
{
    private const TEST_USER_ID = 999010;
    private const TEST_PERIOD = '2026-08';

    private static ?PDO $pdo = null;
    private static bool $dbChecked = false;

    private function db(): ?PDO
    {
        if (self::$dbChecked) {
            return self::$pdo;
        }
        self::$dbChecked = true;

        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) {
                foreach ([
                    $app . '/Config/app.php',
                    $app . '/Config/database.php',
                ] as $cfg) {
                    if (file_exists($cfg)) {
                        require_once $cfg;
                    }
                }
            }
            if (!class_exists('Database') && file_exists($app . '/Core/Database.php')) {
                require_once $app . '/Core/Database.php';
            }
            if (!class_exists('Model') && file_exists($app . '/Core/Model.php')) {
                require_once $app . '/Core/Model.php';
            }
            if (!class_exists('CrmSalesGoal') && file_exists($app . '/Models/CrmSalesGoal.php')) {
                require_once $app . '/Models/CrmSalesGoal.php';
            }

            $db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $conn = $ref->getValue($db);
            if (!$conn instanceof PDO) {
                return null;
            }

            foreach (['users', 'rev_revenue_records', 'crm_sales_goals', 'crm_deals', 'crm_pipeline_stages'] as $table) {
                $found = $conn->query("SHOW TABLES LIKE '{$table}'")->fetchAll();
                if (empty($found)) {
                    return null;
                }
            }
            // عمود product_name مطلوب (ميجريشن 2026_08_29)
            $cols = $conn->query("SHOW COLUMNS FROM rev_revenue_records LIKE 'product_name'")->fetchAll();
            if (empty($cols)) {
                return null;
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
            $this->markTestSkipped('DB غير متاحة أو جداول الإيرادات غير مثبتة');
        }

        $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                    VALUES (999010, 'revenue-module@tourfecto.test', 'x', 'Test', NOW())
                    ON DUPLICATE KEY UPDATE email = email");
    }

    protected function tearDown(): void
    {
        $pdo = self::$pdo;
        if ($pdo === null) {
            return;
        }
        $pdo->exec("DELETE FROM rev_revenue_records WHERE user_id = 999010");
        $pdo->exec("DELETE FROM crm_deals WHERE owner_user_id = 999010");
        $pdo->exec("DELETE FROM crm_sales_goals WHERE user_id = 999010");
        $pdo->exec("DELETE FROM crm_pipeline_stages WHERE id > 999000");
        $pdo->exec("DELETE FROM users WHERE id = 999010");
    }

    private function addGoal(string $period, float $target): void
    {
        $stmt = self::$pdo->prepare(
            "INSERT INTO crm_sales_goals (user_id, period, target_value)
             VALUES (999010, ?, ?)"
        );
        $stmt->execute([$period, $target]);
    }

    private function addRevenue(float $amount, string $recordedAt, string $productName = '', string $category = ''): void
    {
        $stmt = self::$pdo->prepare(
            "INSERT INTO rev_revenue_records
                (user_id, source, product_name, category, amount, currency, recorded_at, notes)
             VALUES (999010, 'manual', ?, ?, ?, 'USD', ?, NULL)"
        );
        $stmt->execute([
            $productName !== '' ? $productName : null,
            $category !== '' ? $category : null,
            $amount,
            $recordedAt,
        ]);
    }

    public function testGetQuotasNoGoalsReturnsNotEnoughData(): void
    {
        $service = new RevenueQuotaService();
        $result = $service->getQuotas(self::TEST_USER_ID);
        $this->assertFalse($result['has_data']);
        $this->assertStringContainsString('Not enough data', $result['message']);
    }

    public function testGetQuotasComputesAchievedAndProgress(): void
    {
        $this->addGoal(self::TEST_PERIOD, 1000.0);
        $this->addRevenue(400.0, self::TEST_PERIOD . '-05 12:00:00');
        $this->addRevenue(250.0, self::TEST_PERIOD . '-15 09:00:00');

        $service = new RevenueQuotaService();
        $result = $service->getQuotas(self::TEST_USER_ID, self::TEST_PERIOD);

        $this->assertTrue($result['has_data']);
        $this->assertCount(1, $result['quotas']);
        $quota = $result['quotas'][0];
        $this->assertSame(self::TEST_PERIOD, $quota['period']);
        $this->assertEquals(1000.0, $quota['target_value']);
        $this->assertEquals(650.0, $quota['achieved_value']);
        $this->assertEquals(65.0, $quota['progress_percent']);
        $this->assertEquals(350.0, $quota['gap_to_target']);
        $this->assertSame('at_risk', $quota['status']);
    }

    public function testGetQuotasAheadWhenGoalMet(): void
    {
        $this->addGoal(self::TEST_PERIOD, 500.0);
        $this->addRevenue(600.0, self::TEST_PERIOD . '-10 10:00:00');

        $service = new RevenueQuotaService();
        $result = $service->getQuotas(self::TEST_USER_ID, self::TEST_PERIOD);

        $this->assertTrue($result['has_data']);
        $quota = $result['quotas'][0];
        $this->assertEquals(120.0, $quota['progress_percent']);
        $this->assertSame('ahead', $quota['status']);
    }

    public function testGetQuotasForecastFromWeightedOpenDeals(): void
    {
        // مرحلة بـ win_probability صفر (الاحتمالية على الصفقة نفسها)
        $stmt = self::$pdo->prepare(
            "INSERT INTO crm_pipeline_stages (id, pipeline_id, name, slug, win_probability, is_won, is_lost, created_at)
             VALUES (999001, NULL, 'Test Stage', 'test-stage', 0, 0, 0, NOW())"
        );
        $stmt->execute();
        $this->addGoal(self::TEST_PERIOD, 1000.0);
        $dealStmt = self::$pdo->prepare(
            "INSERT INTO crm_deals
                (id, owner_user_id, stage_id, title, value, currency, probability, expected_close_date, status, created_at, updated_at)
             VALUES (999001, 999010, 999001, 'صفقة نيل', 2000.0, 'USD', 50, ?, 'open', NOW(), NOW())"
        );
        $dealStmt->execute([self::TEST_PERIOD . '-20']);

        $service = new RevenueQuotaService();
        $result = $service->getQuotas(self::TEST_USER_ID, self::TEST_PERIOD);

        $this->assertTrue($result['has_data']);
        $quota = $result['quotas'][0];
        $this->assertEquals(1000.0, $quota['forecast_value']);
        $this->assertEquals(1, $quota['open_deal_count']);
        $this->assertEquals(1000.0, $quota['projected_value']);
        $this->assertEquals(100.0, $quota['projected_progress_percent']);
        $this->assertSame('on_track', $quota['status']);
    }

    public function testGetRevenueByProductGroupsByProductName(): void
    {
        $this->addRevenue(300.0, '2026-08-01 10:00:00', 'رحلة الأقصر', 'tours');
        $this->addRevenue(200.0, '2026-08-02 10:00:00', 'رحلة الأقصر', 'tours');
        $this->addRevenue(150.0, '2026-08-03 10:00:00', 'غرفة ديلوكس', 'rooms');

        $service = new RevenueOverviewService();
        $result = $service->getRevenueByProduct(self::TEST_USER_ID);

        $this->assertTrue($result['has_data']);
        $this->assertEquals(650.0, $result['total_revenue']);
        $this->assertCount(2, $result['products']);

        $this->assertSame('رحلة الأقصر', $result['products'][0]['label']);
        $this->assertEquals(500.0, $result['products'][0]['revenue']);
        $this->assertEquals(2, $result['products'][0]['count']);
        $this->assertSame('product', $result['products'][0]['dimension']);
        $this->assertEquals(76.9, $result['products'][0]['share_percent']);

        $this->assertSame('غرفة ديلوكس', $result['products'][1]['label']);
        $this->assertEquals(150.0, $result['products'][1]['revenue']);
    }

    public function testGetRevenueByProductHonestFallbackWithoutProductDimension(): void
    {
        $this->addRevenue(300.0, '2026-08-01 10:00:00');
        $this->addRevenue(200.0, '2026-08-02 10:00:00');

        $service = new RevenueOverviewService();
        $result = $service->getRevenueByProduct(self::TEST_USER_ID);

        // بدون بُعد منتج: has_data=false (لا أرقام مخترعة) لكن المصفوفة
        // تعرض التجميع حسب المصدر كـ fallback شفاف.
        $this->assertFalse($result['has_data']);
        $this->assertNotEmpty($result['products']);
        $this->assertSame('manual', $result['products'][0]['label']);
        $this->assertSame('source', $result['products'][0]['dimension']);
        $this->assertEquals(500.0, $result['products'][0]['revenue']);
    }

    public function testGetRevenueByProductEmptyReturnsNotEnoughData(): void
    {
        $service = new RevenueOverviewService();
        $result = $service->getRevenueByProduct(self::TEST_USER_ID);
        $this->assertFalse($result['has_data']);
        $this->assertStringContainsString('Not enough data', $result['message']);
    }
}

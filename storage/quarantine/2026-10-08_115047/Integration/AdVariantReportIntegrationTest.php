<?php

/**
 * Tourfecto - Ad/Variant Report Service Integration Test (بند 3)
 * بيفحص تقارير مستوى الإعلان/الـ variant بجوار AdReportService:
 *   1) تقرير شامل (حملات ← أصول ← تنويعات) داخل فترة من أداء حقيقي.
 *   2) نافذة زمنية عبر recorded_on: تنويع داخل الفترة يظهر، وخارجه لا.
 *   3) مقاييس محسوبة عند القراءة فقط (CTR/CPC/CPA/ROAS) + null عند مقام صفر.
 *   4) أفضل تنويع (أعلى CTR مع حد أدنى من الانطباعات).
 *   5) تفصيل أصل/حملة/تنويع + ملخص شامل.
 *   6) عزل التينانت: مستخدم تاني لا يرى بيانات مستخدم آخر.
 *
 * محتاج الميجريشن: 2026_08_28_000005_add_variant_performance_date.sql
 * (بالإضافة إلى 2026_08_28_000003_create_ad_creative_assets.sql).
 * بيتخطى تلقائيًا لو DB غير متاحة أو الجداول مش متشغّلة.
 * @version 1.0.0  @date 2026-08-28
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Models/AdCampaign.php';
require_once __DIR__ . '/../../app/Models/AdCreative.php';
require_once __DIR__ . '/../../app/Models/AdCreativeVariant.php';
require_once __DIR__ . '/../../app/Models/ActivityLog.php';
require_once __DIR__ . '/../../app/Services/Ads/AdCreativeService.php';
require_once __DIR__ . '/../../app/Services/Ads/AdVariantReportService.php';

final class AdVariantReportIntegrationTest extends TestCase
{
    private const USER_ID = 999720;
    private const OTHER_USER_ID = 999721;

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
                    $app . '/Config/encryption.php',
                ] as $cfg) {
                    if (file_exists($cfg)) {
                        require_once $cfg;
                    }
                }
            }
            if (!class_exists('Database') && file_exists($app . '/Core/Database.php')) {
                require_once $app . '/Core/Database.php';
            }
            if (!class_exists('Logger') && file_exists($app . '/Core/Logger.php')) {
                require_once $app . '/Core/Logger.php';
            }

            $db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $conn = $ref->getValue($db);
            if (!$conn instanceof PDO) {
                self::$pdo = null;
                return null;
            }

            foreach (['users', 'ad_campaigns', 'ad_creatives', 'ad_creative_variants', 'ad_performance_reports'] as $table) {
                $found = $conn->query("SHOW TABLES LIKE '{$table}'")->fetchAll();
                if (empty($found)) {
                    self::$pdo = null;
                    return null;
                }
            }

            // عمود recorded_on لازم يكون موجود
            $cols = $conn->query("SHOW COLUMNS FROM ad_creative_variants LIKE 'recorded_on'")->fetchAll();
            if (empty($cols)) {
                self::$pdo = null;
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
            $this->markTestSkipped('DB غير متاحة أو عمود recorded_on مش متشغّل - راجع تعليق أعلى الملف');
        }

        $this->cleanup();

        foreach ([self::USER_ID, self::OTHER_USER_ID] as $uid) {
            $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                        VALUES ($uid, 'variantreport-" . $uid . "@tourfecto.test', 'x', 'Variant Report Travel', NOW())
                        ON DUPLICATE KEY UPDATE email = email");
        }
    }

    private function cleanup(): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return;
        }
        $pdo->exec("DELETE FROM ad_performance_reports WHERE campaign_id IN (
            SELECT id FROM ad_campaigns WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . "))");
        $pdo->exec("DELETE FROM ad_creative_variants WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
        $pdo->exec("DELETE FROM ad_creatives WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
        $pdo->exec("DELETE FROM ad_campaigns WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
    }

    private function addCampaign(int $userId): int
    {
        $pdo = $this->db();
        $stmt = $pdo->prepare("INSERT INTO ad_campaigns
                    (user_id, platform_connection_id, name, objective, status, spend, currency)
                    VALUES (?, NULL, ?, 'traffic', 'active', 0, 'USD')");
        $stmt->execute([$userId, 'VariantReport Campaign ' . uniqid()]);
        return (int) $pdo->lastInsertId();
    }

    private function addSyncedReport(int $campaignId, string $dateStart, float $spend, int $clicks, int $impressions, float $conversions, float $revenue): void
    {
        $pdo = $this->db();
        $stmt = $pdo->prepare("INSERT INTO ad_performance_reports
                    (campaign_id, date_start, date_end, impressions, clicks, conversions, spend, revenue)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$campaignId, $dateStart, $dateStart, $impressions, $clicks, $conversions, $spend, $revenue]);
    }

    /** أصل إعلاني + تنويعان بأداء داخل فترة محددة (recorded_on) */
    private function setupCreativeWithDatedVariants(int $userId, int $campaignId, string $recordedOn): array
    {
        $service = new AdCreativeService();
        $creative = $service->create($userId, $campaignId, ['name' => 'Report Creative']);
        $v1 = $service->addVariant($userId, (int) $creative['id'], []);
        $v2 = $service->addVariant($userId, (int) $creative['id'], []);
        $service->recordPerformance($userId, (int) $v1['id'], [
            'impressions' => 1000, 'clicks' => 120, 'spend' => 60.00,
            'conversions' => 6, 'revenue' => 300.00, 'recorded_on' => $recordedOn,
        ]);
        $service->recordPerformance($userId, (int) $v2['id'], [
            'impressions' => 1000, 'clicks' => 40, 'spend' => 30.00,
            'conversions' => 2, 'revenue' => 90.00, 'recorded_on' => $recordedOn,
        ]);
        return [$creative, $v1, $v2];
    }

    private function service(): AdVariantReportService
    {
        return new AdVariantReportService();
    }

    // ================================================================
    // الاختبارات
    // ================================================================

    public function testGenerateBuildsCampaignCreativeVariantHierarchy(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        [$creative, $v1] = $this->setupCreativeWithDatedVariants(self::USER_ID, $campaignId, date('Y-m-d'));
        $this->addSyncedReport($campaignId, date('Y-m-d'), 100.00, 200, 5000, 10, 400.00);

        $report = $this->service()->generate(self::USER_ID, 'weekly');
        $this->assertTrue($report['has_data']);
        $this->assertSame(1, (int) $report['summary']['campaigns']);
        $this->assertSame(1, (int) $report['summary']['creatives']);
        $this->assertSame(2, (int) $report['summary']['variants']);

        $campaign = $report['campaigns'][0];
        $this->assertSame($campaignId, (int) $campaign['campaign_id']);
        $this->assertSame(200, (int) $campaign['campaign_metrics']['clicks']);
        $this->assertSame(10.0, $campaign['campaign_metrics']['cpa']);  // 100/10

        $creativeRow = $campaign['creatives'][0];
        $this->assertSame((int) $creative['id'], (int) $creativeRow['creative_id']);
        $this->assertCount(2, $creativeRow['variants']);
        // أفضل تنويع: v1 CTR 12% vs v2 4%
        $this->assertSame((int) $v1['id'], (int) $report['best_variant']['variant_id']);
        $this->assertSame(12.0, $report['best_variant']['ctr']);
    }

    public function testPeriodFilterExcludesOldVariantData(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        $oldDate = date('Y-m-d', strtotime('-45 days'));
        [$creative] = $this->setupCreativeWithDatedVariants(self::USER_ID, $campaignId, $oldDate);

        $report = $this->service()->generate(self::USER_ID, 'weekly');
        $this->assertSame(0, (int) $report['summary']['variants'], 'old variant data is excluded from the weekly window');
        $this->assertNull($report['best_variant']);

        // التقرير الشهري (30 يوم) يظل يستثنيه
        $monthly = $this->service()->generate(self::USER_ID, 'monthly');
        $this->assertSame(0, (int) $monthly['summary']['variants']);

        // التفصيل (كل الفترات) يراه
        $breakdown = $this->service()->creativeBreakdown(self::USER_ID, (int) $creative['id']);
        $this->assertNotNull($breakdown);
        $this->assertCount(2, $breakdown['variants']);
        $this->assertSame(12.0, $breakdown['best_variant']['ctr']);
    }

    public function testMetricsReturnNullWhenDenominatorIsZero(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        $service = new AdCreativeService();
        $creative = $service->create(self::USER_ID, $campaignId, ['name' => 'No Data Creative']);
        $v1 = $service->addVariant(self::USER_ID, (int) $creative['id'], []);

        // أداء بصفر نقرات وانطباعات
        $service->recordPerformance(self::USER_ID, (int) $v1['id'], ['recorded_on' => date('Y-m-d')]);

        $summary = $this->service()->variantSummary(self::USER_ID);
        $row = $summary['variants'][0];
        $this->assertNull($row['ctr']);
        $this->assertNull($row['cpc']);
        $this->assertNull($row['cpa']);
        $this->assertNull($row['roas']);
        $this->assertSame(0, (int) $row['impressions']);
    }

    public function testVariantBreakdownIncludesCreativeContext(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        [$creative, $v1] = $this->setupCreativeWithDatedVariants(self::USER_ID, $campaignId, date('Y-m-d'));

        $breakdown = $this->service()->variantBreakdown(self::USER_ID, (int) $v1['id']);
        $this->assertNotNull($breakdown);
        $this->assertSame(12.0, $breakdown['ctr']);
        $this->assertSame(0.5, $breakdown['cpc']);   // 60/120
        $this->assertSame(10.0, $breakdown['cpa']);  // 60/6
        $this->assertSame(5.0, $breakdown['roas']);  // 300/60
        $this->assertSame((int) $creative['id'], (int) $breakdown['creative']['creative_id']);
        $this->assertSame($campaignId, (int) $breakdown['creative']['campaign_id']);
    }

    public function testCampaignBreakdownAndBestVariantWithMinImpressions(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        [$creative, $v1, $v2] = $this->setupCreativeWithDatedVariants(self::USER_ID, $campaignId, date('Y-m-d'));

        $campaign = $this->service()->campaignBreakdown(self::USER_ID, $campaignId);
        $this->assertNotNull($campaign);
        $this->assertCount(1, $campaign['creatives']);
        $this->assertSame((int) $v1['id'], (int) $campaign['creatives'][0]['best_variant']['variant_id']);

        // حد أدنى عالٍ يستبعد الجميع
        $none = $this->service()->bestVariant(self::USER_ID, 999999, 'weekly');
        $this->assertNull($none);

        // حد منخفض يعيد v1
        $best = $this->service()->bestVariant(self::USER_ID, 10, 'weekly');
        $this->assertSame((int) $v1['id'], (int) $best['variant_id']);
    }

    public function testTenantIsolation(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        [$creative] = $this->setupCreativeWithDatedVariants(self::USER_ID, $campaignId, date('Y-m-d'));

        $service = $this->service();
        $this->assertNull($service->creativeBreakdown(self::OTHER_USER_ID, (int) $creative['id']));
        $this->assertNull($service->variantBreakdown(self::OTHER_USER_ID, 999999));
        $this->assertNull($service->campaignBreakdown(self::OTHER_USER_ID, $campaignId));

        $foreignReport = $service->generate(self::OTHER_USER_ID, 'weekly');
        $this->assertSame([], $foreignReport['campaigns']);
        $this->assertSame(0, (int) $foreignReport['summary']['campaigns']);

        $foreignSummary = $service->variantSummary(self::OTHER_USER_ID);
        $this->assertSame([], $foreignSummary['variants']);
    }
}

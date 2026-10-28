<?php

/**
 * Tourfecto - Ad Next-Best-Action Service Integration Test (بند 5)
 * بيفحص توصيات "الخطوة التالية" لكل حملة من ترند إحصائي حقيقي على
 * بيانات ad_performance_reports المزامنة:
 *   1) بيانات غير كافية (< 5 أيام) → wait صريح بلا أرقام مختلقة.
 *   2) صرف كامل الميزانية + ROAS ≥ 1 + ميل إنفاق تصاعدي → increase_budget.
 *   3) ROAS < 0.5 → decrease_budget.
 *   4) انهيار CTR (ميل سالب + CTR < 1%) → rotate_creative.
 *   5) ميزانية لا تُصرف (≤ 30%) مع بيانات كافية → review_targeting.
 *   6) أصل بأكثر من تنويع بلا تجربة جارية → start_ab_test.
 *   7) حفظ سجل يومي (dedupe) + list + applied + dismiss + عزل تينانت.
 *   8) linearSlope إحصائي صحيح (تصاعدي موجب / ثابت صفر).
 *
 * محتاج الميجريشن: 2026_08_28_000007_create_ad_recommendations.sql
 * بيتخطى تلقائيًا لو DB غير متاحة.
 * @version 1.0.0  @date 2026-08-28
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Models/AdCampaign.php';
require_once __DIR__ . '/../../app/Models/AdCreative.php';
require_once __DIR__ . '/../../app/Models/AdCreativeVariant.php';
require_once __DIR__ . '/../../app/Models/AdAbTest.php';
require_once __DIR__ . '/../../app/Models/AdAbTestVariant.php';
require_once __DIR__ . '/../../app/Models/AdRecommendation.php';
require_once __DIR__ . '/../../app/Models/ActivityLog.php';
require_once __DIR__ . '/../../app/Services/Ads/AdCreativeService.php';
require_once __DIR__ . '/../../app/Services/Ads/AdAbTestService.php';
require_once __DIR__ . '/../../app/Services/Ads/AdNextBestActionService.php';

final class AdNextBestActionIntegrationTest extends TestCase
{
    private const USER_ID = 999740;
    private const OTHER_USER_ID = 999741;

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

            foreach (['users', 'ad_campaigns', 'ad_performance_reports', 'ad_recommendations'] as $table) {
                $found = $conn->query("SHOW TABLES LIKE '{$table}'")->fetchAll();
                if (empty($found)) {
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
            $this->markTestSkipped('DB غير متاحة أو جدول التوصيات مش متشغّل - راجع تعليق أعلى الملف');
        }

        $this->cleanup();

        foreach ([self::USER_ID, self::OTHER_USER_ID] as $uid) {
            $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                        VALUES ($uid, 'recommend-" . $uid . "@tourfecto.test', 'x', 'Recommend Travel', NOW())
                        ON DUPLICATE KEY UPDATE email = email");
        }
    }

    private function cleanup(): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return;
        }
        $pdo->exec("DELETE FROM ad_recommendations WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
        $pdo->exec("DELETE FROM ad_ab_test_variants WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
        $pdo->exec("DELETE FROM ad_ab_tests WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
        $pdo->exec("DELETE FROM ad_creative_variants WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
        $pdo->exec("DELETE FROM ad_creatives WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
        $pdo->exec("DELETE FROM ad_performance_reports WHERE campaign_id IN (
            SELECT id FROM ad_campaigns WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . "))");
        $pdo->exec("DELETE FROM ad_campaigns WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
    }

    private function addCampaign(int $userId, float $dailyBudget): int
    {
        $pdo = $this->db();
        $stmt = $pdo->prepare("INSERT INTO ad_campaigns
                    (user_id, platform_connection_id, name, objective, daily_budget, status, spend, currency)
                    VALUES (?, NULL, ?, 'traffic', ?, 'active', 0, 'USD')");
        $stmt->execute([$userId, 'Recommend Campaign ' . uniqid(), $dailyBudget]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * يزرع صفوف مزامنة يومية. $dayRows قائمة [spend, clicks, impressions,
     * revenue] من الأقدم للأحدث (كلها خلال آخر 14 يوم، ليس منها اليوم).
     * $todaySpend = إنفاق اليوم (يُستخدم لكفاية الميزانية).
     */
    private function seedCampaignData(int $campaignId, array $dayRows, float $todaySpend = 0.0): void
    {
        $pdo = $this->db();
        $n = count($dayRows);
        $stmt = $pdo->prepare("INSERT INTO ad_performance_reports
                    (campaign_id, date_start, date_end, impressions, clicks, conversions, spend, revenue)
                    VALUES (?, DATE_SUB(CURDATE(), INTERVAL ? DAY), DATE_SUB(CURDATE(), INTERVAL ? DAY), ?, ?, 0, ?, ?)");
        foreach ($dayRows as $i => $row) {
            $stmt->execute([$campaignId, $n - $i, $n - $i, $row[2], $row[1], $row[0], $row[3]]);
        }
        if ($todaySpend > 0) {
            $stmt->execute([$campaignId, 0, 0, 0, 0, $todaySpend, 0]);
        }
    }

    private function service(): AdNextBestActionService
    {
        return new AdNextBestActionService();
    }

    // ================================================================
    // الاختبارات
    // ================================================================

    public function testInsufficientDataYieldsExplicitWait(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID, 100);
        $this->seedCampaignData($campaignId, [[20, 10, 1000, 0], [25, 12, 1100, 0]]);

        $recs = $this->service()->recommendations(self::USER_ID);
        $this->assertCount(1, $recs);
        $this->assertSame('wait', $recs[0]['action']);
        $this->assertSame('low', $recs[0]['confidence']);
        $this->assertStringContainsString('2 أيام', $recs[0]['reason']);
    }

    public function testIncreaseBudgetWhenFullSpendWithPositiveRoas(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID, 100);
        $rows = [];
        for ($i = 0; $i < 14; $i++) {
            $spend = 20 + $i * 5; // تصاعدي 20→85
            $rows[] = [$spend, 20, 1000, $spend * 1.3]; // ROAS ثابت ~1.3
        }
        $this->seedCampaignData($campaignId, $rows, 98.0); // 98% من الميزانية

        $recs = $this->service()->recommendations(self::USER_ID);
        $this->assertCount(1, $recs);
        $this->assertSame('increase_budget', $recs[0]['action']);
        $this->assertSame('high', $recs[0]['confidence']);
        $this->assertSame('statistical', $recs[0]['basis']);
        $this->assertGreaterThan(0, (float) $recs[0]['signals']['spend_trend_slope']);
    }

    public function testDecreaseBudgetWhenRoasBelowHalf(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID, 100);
        $rows = [];
        for ($i = 0; $i < 14; $i++) {
            $rows[] = [50, 25, 1000, 10]; // ROAS = 10/50 = 0.2
        }
        $this->seedCampaignData($campaignId, $rows, 40.0);

        $recs = $this->service()->recommendations(self::USER_ID);
        $this->assertCount(1, $recs);
        $this->assertSame('decrease_budget', $recs[0]['action']);
        $this->assertSame(0.2, $recs[0]['signals']['roas']);
    }

    public function testRotateCreativeOnCtrCollapse(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID, 100);
        $rows = [];
        for ($i = 0; $i < 14; $i++) {
            $clicks = (int) max(2, 50 - $i * 3); // 50→11 (انهيار حاد: ميل ≈ -0.12)
            $spend = 50;
            $rows[] = [$spend, $clicks, 2500, $spend * 1.0]; // ROAS 1.0 (لا يمر للـ decrease)
        }
        $this->seedCampaignData($campaignId, $rows, 50.0);

        $recs = $this->service()->recommendations(self::USER_ID);
        $this->assertCount(1, $recs);
        $this->assertSame('rotate_creative', $recs[0]['action']);
        $this->assertLessThan(0, (float) $recs[0]['signals']['ctr_trend_slope']);
        $this->assertLessThan(1.0, $recs[0]['signals']['recent_ctr']);
    }

    public function testReviewTargetingWhenBudgetUnderSpent(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID, 100);
        $rows = [];
        for ($i = 0; $i < 10; $i++) {
            $rows[] = [40, 20, 1000, 60]; // ROAS 1.5
        }
        $this->seedCampaignData($campaignId, $rows, 20.0); // 20% من الميزانية فقط

        $recs = $this->service()->recommendations(self::USER_ID);
        $this->assertCount(1, $recs);
        $this->assertSame('review_targeting', $recs[0]['action']);
        $this->assertLessThanOrEqual(30.0, (float) $recs[0]['signals']['budget_utilization_pct']);
    }

    public function testStartAbTestWhenCreativeHasTwoVariants(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID, 100);
        $rows = [];
        for ($i = 0; $i < 10; $i++) {
            $rows[] = [40, 20, 1000, 60]; // ROAS 1.5
        }
        $this->seedCampaignData($campaignId, $rows, 50.0);

        // أصل بتنويعين بأداء فعلي
        $creativeService = new AdCreativeService();
        $creative = $creativeService->create(self::USER_ID, $campaignId, ['name' => 'Two Variants']);
        $v1 = $creativeService->addVariant(self::USER_ID, (int) $creative['id'], []);
        $v2 = $creativeService->addVariant(self::USER_ID, (int) $creative['id'], []);
        $creativeService->recordPerformance(self::USER_ID, (int) $v1['id'], ['impressions' => 1000, 'clicks' => 50, 'recorded_on' => date('Y-m-d')]);
        $creativeService->recordPerformance(self::USER_ID, (int) $v2['id'], ['impressions' => 1000, 'clicks' => 30, 'recorded_on' => date('Y-m-d')]);

        $recs = $this->service()->recommendations(self::USER_ID);
        $this->assertCount(1, $recs);
        $this->assertSame('start_ab_test', $recs[0]['action']);
        $this->assertSame('rule', $recs[0]['basis']);
    }

    public function testPersistenceDedupeAndLifecycle(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID, 100);
        $rows = [];
        for ($i = 0; $i < 6; $i++) {
            $rows[] = [40, 20, 1000, 60];
        }
        $this->seedCampaignData($campaignId, $rows, 50.0);

        $service = $this->service();
        $service->recommendations(self::USER_ID);
        $service->recommendations(self::USER_ID); // dedupe نفس اليوم

        $history = $service->list(self::USER_ID);
        $this->assertCount(1, $history, 'dedupe per campaign per day');
        $this->assertSame('pending', $history[0]['status']);

        $recId = (int) $history[0]['id'];
        $this->assertTrue($service->markApplied(self::USER_ID, $recId));
        $this->assertSame('applied', $service->list(self::USER_ID)[0]['status']);

        // عزل التينانت: مستخدم تاني لا يعدّل
        $this->assertFalse($service->markApplied(self::OTHER_USER_ID, $recId));
        $this->assertFalse($service->dismiss(self::OTHER_USER_ID, $recId));
    }

    public function testLinearSlopeIsStatisticallyCorrect(): void
    {
        $this->assertGreaterThan(0, AdNextBestActionService::linearSlope([1, 2, 3, 4, 5]));
        $this->assertSame(0.0, AdNextBestActionService::linearSlope([5, 5, 5, 5]));
        $this->assertLessThan(0, AdNextBestActionService::linearSlope([5, 4, 3, 2, 1]));
        $this->assertSame(0.0, AdNextBestActionService::linearSlope([]));
        $this->assertSame(0.0, AdNextBestActionService::linearSlope([3]));
    }
}

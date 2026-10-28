<?php

/**
 * Tourfecto - Ad A/B Test Service Integration Test (بند 2)
 * بيفحص مسار تجارب A/B على تنويعات الأصول الإعلانية:
 *   1) إنشاء تجربة على أصل إعلاني لحملة مملوكة + عزل التينانت.
 *   2) رفض إنشاء تجربة على حملة/أصل غير مملوك.
 *   3) إضافة أذرع بأوزان نسبية + تنويع تحكم + رفض تنويع من أصل تاني.
 *   4) بدء التجربة (تحتاج ذراعين) وتحديد التواريخ + رفض تعديل الوزن بعد البدء.
 *   5) إعلان فائز + رفض فائز خارج الأذرع.
 *   6) إحصائيات chi-square حقيقية (significant/reliable) من أداء تنويعات فعلي.
 *   7) التنبؤ بالفائز (أعلى CTR مع دلالة إحصائية أو سبب واضح).
 *   8) توزيع الحركة الموزون (weighted pick) لتجربة جارية.
 *   9) أرشفة التجربة وإخفائها.
 *
 * محتاج الميجريشن: 2026_08_28_000004_create_ad_ab_tests.sql
 * (بالإضافة إلى 2026_08_28_000003_create_ad_creative_assets.sql).
 * بيتخطى تلقائيًا لو DB غير متاحة أو الجداول مش متشغّلة.
 * @version 1.0.0  @date 2026-08-28
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Models/AdCampaign.php';
require_once __DIR__ . '/../../app/Models/AdCreative.php';
require_once __DIR__ . '/../../app/Models/AdCreativeVariant.php';
require_once __DIR__ . '/../../app/Models/AdAbTest.php';
require_once __DIR__ . '/../../app/Models/AdAbTestVariant.php';
require_once __DIR__ . '/../../app/Models/ActivityLog.php';
require_once __DIR__ . '/../../app/Services/Ads/AdCreativeService.php';
require_once __DIR__ . '/../../app/Services/Ads/AdAbTestService.php';

final class AdAbTestIntegrationTest extends TestCase
{
    private const USER_ID = 999710;
    private const OTHER_USER_ID = 999711;

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

            foreach (['users', 'ad_campaigns', 'ad_creatives', 'ad_creative_variants', 'ad_ab_tests', 'ad_ab_test_variants'] as $table) {
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
            $this->markTestSkipped('DB غير متاحة أو جداول الـ A/B مش متشغّلة - راجع تعليق أعلى الملف');
        }

        $this->cleanup();

        foreach ([self::USER_ID, self::OTHER_USER_ID] as $uid) {
            $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                        VALUES ($uid, 'abtest-" . $uid . "@tourfecto.test', 'x', 'AbTest Travel', NOW())
                        ON DUPLICATE KEY UPDATE email = email");
        }
    }

    private function cleanup(): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return;
        }
        $pdo->exec("DELETE FROM ad_ab_test_variants WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
        $pdo->exec("DELETE FROM ad_ab_tests WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
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
        $stmt->execute([$userId, 'AbTest Campaign ' . uniqid()]);
        return (int) $pdo->lastInsertId();
    }

    /** إنشاء أصل إعلاني + تنويعين مع أداء واقعي */
    private function setupCreativeWithTwoVariants(int $userId, int $campaignId): array
    {
        $service = new AdCreativeService();
        $creative = $service->create($userId, $campaignId, ['name' => 'AbTest Creative']);
        $control = $service->addVariant($userId, (int) $creative['id'], []);
        $variant = $service->addVariant($userId, (int) $creative['id'], []);
        return [$creative, $control, $variant];
    }

    private function service(): AdAbTestService
    {
        return new AdAbTestService();
    }

    // ================================================================
    // الاختبارات
    // ================================================================

    public function testCreateAndListTestWithTenantIsolation(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        [$creative] = $this->setupCreativeWithTwoVariants(self::USER_ID, $campaignId);

        $service = $this->service();
        $created = $service->createTest(self::USER_ID, $campaignId, (int) $creative['id'], 'Headline Test');
        $this->assertNotNull($created);
        $this->assertSame('draft', $created['status']);
        $this->assertSame(0, (int) $created['arms_count']);

        $list = $service->listForCampaign(self::USER_ID, $campaignId);
        $this->assertCount(1, $list);

        // عزل التينانت
        $this->assertNull($service->get(self::OTHER_USER_ID, (int) $created['id']));
        $this->assertSame([], $service->listForCampaign(self::OTHER_USER_ID, $campaignId));
    }

    public function testCreateOnForeignCampaignOrCreativeReturnsNull(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        [$creative] = $this->setupCreativeWithTwoVariants(self::USER_ID, $campaignId);

        $service = $this->service();
        $this->assertNull($service->createTest(self::OTHER_USER_ID, $campaignId, (int) $creative['id'], 'Nope'));
        $this->assertNull($service->createTest(self::USER_ID, $campaignId, 999999, 'Nope'));
    }

    public function testAddArmsWithWeightsAndControl(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        [$creative, $control, $variant] = $this->setupCreativeWithTwoVariants(self::USER_ID, $campaignId);
        $service = $this->service();

        $test = $service->createTest(self::USER_ID, $campaignId, (int) $creative['id'], 'Split Test');
        $testId = (int) $test['id'];

        $withArm = $service->addVariant(self::USER_ID, $testId, (int) $control['id'], 50, true);
        $this->assertNotNull($withArm);
        $withArm = $service->addVariant(self::USER_ID, $testId, (int) $variant['id'], 50, false);
        $this->assertNotNull($withArm);
        $this->assertCount(2, $withArm['variants']);

        // رفض تنويع لا ينتمي للأصل
        $otherCampaign = $this->addCampaign(self::USER_ID);
        [$otherCreative] = $this->setupCreativeWithTwoVariants(self::USER_ID, $otherCampaign);
        $otherVariant = (new AdCreativeService())->addVariant(self::USER_ID, (int) $otherCreative['id'], []);
        $this->assertNull($service->addVariant(self::USER_ID, $testId, (int) $otherVariant['id'], 50));

        // عزل التينانت على الذراع
        $this->assertNull($service->addVariant(self::OTHER_USER_ID, $testId, (int) $variant['id'], 50));
        $this->assertNull($service->updateVariantWeight(self::OTHER_USER_ID, (int) $withArm['variants'][0]['id'], 40));
    }

    public function testStartRequiresTwoArmsAndBlocksWeightChangeAfterStart(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        [$creative, $control, $variant] = $this->setupCreativeWithTwoVariants(self::USER_ID, $campaignId);
        $service = $this->service();

        $test = $service->createTest(self::USER_ID, $campaignId, (int) $creative['id'], 'Two Arms Only');
        $testId = (int) $test['id'];

        // البدء بذراع واحد يفشل
        $service->addVariant(self::USER_ID, $testId, (int) $control['id'], 50, true);
        $this->expectException(InvalidArgumentException::class);
        $service->startTest(self::USER_ID, $testId);
    }

    public function testStartAndCompleteWithWinner(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        [$creative, $control, $variant] = $this->setupCreativeWithTwoVariants(self::USER_ID, $campaignId);
        $service = $this->service();

        $test = $service->createTest(self::USER_ID, $campaignId, (int) $creative['id'], 'Complete Flow');
        $testId = (int) $test['id'];
        $service->addVariant(self::USER_ID, $testId, (int) $control['id'], 50, true);
        $service->addVariant(self::USER_ID, $testId, (int) $variant['id'], 50, false);

        $running = $service->startTest(self::USER_ID, $testId);
        $this->assertSame('running', $running['status']);
        $this->assertNotNull($running['started_at']);

        // الوزن مش قابل للتعديل بعد البدء
        $armId = (int) $running['variants'][0]['id'];
        $this->assertNull($service->updateVariantWeight(self::USER_ID, $armId, 60));
        $this->assertNull($service->removeVariant(self::USER_ID, $armId));

        $completed = $service->completeTest(self::USER_ID, $testId, (int) $variant['id']);
        $this->assertSame('completed', $completed['status']);
        $this->assertSame((int) $variant['id'], (int) $completed['winning_variant_id']);
        $this->assertNotNull($completed['ended_at']);

        // فائز خارج الأذرع مرفوض
        $this->expectException(InvalidArgumentException::class);
        $service->completeTest(self::USER_ID, $testId, 999999);
    }

    public function testStatisticsComputesChiSquareFromRealPerformance(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        [$creative, $control, $variant] = $this->setupCreativeWithTwoVariants(self::USER_ID, $campaignId);
        $service = $this->service();

        $creativeService = new AdCreativeService();
        $creativeService->recordPerformance(self::USER_ID, (int) $control['id'], ['impressions' => 1000, 'clicks' => 100]);
        $creativeService->recordPerformance(self::USER_ID, (int) $variant['id'], ['impressions' => 1000, 'clicks' => 150]);

        $test = $service->createTest(self::USER_ID, $campaignId, (int) $creative['id'], 'Chi Test');
        $testId = (int) $test['id'];
        $service->addVariant(self::USER_ID, $testId, (int) $control['id'], 50, true);
        $service->addVariant(self::USER_ID, $testId, (int) $variant['id'], 50, false);

        $stats = $service->statistics(self::USER_ID, $testId);
        $this->assertTrue($stats['has_enough_data']);
        $this->assertCount(2, $stats['arms']);

        $comparisons = [];
        foreach ($stats['comparisons'] as $cmp) {
            if ($cmp['is_control']) {
                $this->assertNull($cmp['chi_square']);
            } else {
                $comparisons[] = $cmp;
            }
        }
        $this->assertCount(1, $comparisons);
        $this->assertTrue($comparisons[0]['significant'], '15% vs 10% CTR with 1000 impressions each must be significant');
        $this->assertTrue($comparisons[0]['reliable']);
        $this->assertGreaterThan(3.841, $comparisons[0]['chi_square']);
        $this->assertSame(15.0, $comparisons[0]['ctr']);
        $this->assertSame(10.0, $comparisons[0]['control_ctr']);
    }

    public function testStatisticsFlagsUnreliableWhenDataIsThin(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        [$creative, $control, $variant] = $this->setupCreativeWithTwoVariants(self::USER_ID, $campaignId);
        $service = $this->service();

        $creativeService = new AdCreativeService();
        $creativeService->recordPerformance(self::USER_ID, (int) $control['id'], ['impressions' => 10, 'clicks' => 1]);
        $creativeService->recordPerformance(self::USER_ID, (int) $variant['id'], ['impressions' => 10, 'clicks' => 3]);

        $test = $service->createTest(self::USER_ID, $campaignId, (int) $creative['id'], 'Thin Data');
        $testId = (int) $test['id'];
        $service->addVariant(self::USER_ID, $testId, (int) $control['id'], 50, true);
        $service->addVariant(self::USER_ID, $testId, (int) $variant['id'], 50, false);

        $stats = $service->statistics(self::USER_ID, $testId);
        $this->assertFalse($stats['has_enough_data'], 'expected cells < 5 => unreliable');
        foreach ($stats['comparisons'] as $cmp) {
            if (!$cmp['is_control']) {
                $this->assertFalse($cmp['reliable']);
            }
        }
    }

    public function testPredictWinnerReturnsLeaderWithSignificance(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        [$creative, $control, $variant] = $this->setupCreativeWithTwoVariants(self::USER_ID, $campaignId);
        $service = $this->service();

        $creativeService = new AdCreativeService();
        $creativeService->recordPerformance(self::USER_ID, (int) $control['id'], ['impressions' => 1000, 'clicks' => 100]);
        $creativeService->recordPerformance(self::USER_ID, (int) $variant['id'], ['impressions' => 1000, 'clicks' => 150]);

        $test = $service->createTest(self::USER_ID, $campaignId, (int) $creative['id'], 'Predict Test');
        $testId = (int) $test['id'];
        $service->addVariant(self::USER_ID, $testId, (int) $control['id'], 50, true);
        $service->addVariant(self::USER_ID, $testId, (int) $variant['id'], 50, false);

        $prediction = $service->predictWinner(self::USER_ID, $testId);
        $this->assertSame((int) $variant['id'], (int) $prediction['predicted_winner_id']);
        $this->assertTrue($prediction['significant']);
        $this->assertTrue($prediction['reliable']);
        $this->assertSame(15.0, $prediction['ctr']);
    }

    public function testPredictWinnerReportsInsufficientData(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        [$creative] = $this->setupCreativeWithTwoVariants(self::USER_ID, $campaignId);
        $service = $this->service();

        $test = $service->createTest(self::USER_ID, $campaignId, (int) $creative['id'], 'No Data Yet');
        $testId = (int) $test['id'];

        $prediction = $service->predictWinner(self::USER_ID, $testId);
        $this->assertNull($prediction['predicted_winner_id']);
        $this->assertFalse($prediction['significant']);
    }

    public function testPickVariantForTrafficUsesRunningTestWeights(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        [$creative, $control, $variant] = $this->setupCreativeWithTwoVariants(self::USER_ID, $campaignId);
        $service = $this->service();

        $test = $service->createTest(self::USER_ID, $campaignId, (int) $creative['id'], 'Traffic Pick');
        $testId = (int) $test['id'];
        $service->addVariant(self::USER_ID, $testId, (int) $control['id'], 50, true);
        $service->addVariant(self::USER_ID, $testId, (int) $variant['id'], 50, false);
        $service->startTest(self::USER_ID, $testId);

        $allowed = [(int) $control['id'], (int) $variant['id']];
        for ($i = 0; $i < 20; $i++) {
            $pick = $service->pickVariantForTraffic(self::USER_ID, (int) $creative['id']);
            $this->assertNotNull($pick['creative_variant_id']);
            $this->assertSame($testId, (int) $pick['ab_test_id']);
            $this->assertContains((int) $pick['creative_variant_id'], $allowed);
        }

        // بدون تجربة جارية على أصل آخر => null
        $otherCampaign = $this->addCampaign(self::USER_ID);
        [$otherCreative] = $this->setupCreativeWithTwoVariants(self::USER_ID, $otherCampaign);
        $pick = $service->pickVariantForTraffic(self::USER_ID, (int) $otherCreative['id']);
        $this->assertNull($pick['creative_variant_id']);
    }

    public function testArchiveHidesTest(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        [$creative] = $this->setupCreativeWithTwoVariants(self::USER_ID, $campaignId);
        $service = $this->service();

        $test = $service->createTest(self::USER_ID, $campaignId, (int) $creative['id'], 'Archive Me');
        $testId = (int) $test['id'];

        $this->assertTrue($service->archiveTest(self::USER_ID, $testId));
        $this->assertNull($service->get(self::USER_ID, $testId));
        $this->assertCount(0, $service->listForCampaign(self::USER_ID, $campaignId));

        // أرشفة غير مصرّح بها
        $otherCampaign = $this->addCampaign(self::USER_ID);
        [$otherCreative] = $this->setupCreativeWithTwoVariants(self::USER_ID, $otherCampaign);
        $otherTest = $service->createTest(self::USER_ID, $otherCampaign, (int) $otherCreative['id'], 'Other');
        $this->assertFalse($service->archiveTest(self::OTHER_USER_ID, (int) $otherTest['id']));
    }
}

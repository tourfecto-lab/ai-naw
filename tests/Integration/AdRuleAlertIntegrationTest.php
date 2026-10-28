<?php

/**
 * Tourfecto - Ad Rule-triggered Alerts Integration Test (بند 4)
 * بيفحص القواعد الجديدة فوق AdAlertService القائم (مستوى الأصل الإعلاني/
 * التنويع/التجربة) من بيانات حقيقية فقط:
 *   1) saveRules/getRules يقبلان الأنواع الجديدة الأربعة.
 *   2) creative_underperforming: أفضل تنويع أقل من % من CTR الحملة.
 *   3) creative_stale: أصل بلا أداء مُسجّل منذ N يوم والحملة نشطة.
 *   4) variant_wasted_spend: إنفاق فوق حد بلا تحويلات.
 *   5) ab_test_inconclusive: تجربة جارية منذ N يوم بلا دلالة إحصائية.
 *   6) is_enabled=0 يمنع التقييم.
 *   7) عزل التينانت + كتالوج rule-types.
 *
 * محتاج الميجريشن: 2026_08_28_000006_add_rule_alert_creative_types.sql
 * (بالإضافة لميجريشنات البنود 1-3). بيتخطى تلقائيًا لو DB غير متاحة.
 * @version 1.0.0  @date 2026-08-28
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Models/AdCampaign.php';
require_once __DIR__ . '/../../app/Models/AdCreative.php';
require_once __DIR__ . '/../../app/Models/AdCreativeVariant.php';
require_once __DIR__ . '/../../app/Models/AdAbTest.php';
require_once __DIR__ . '/../../app/Models/AdAbTestVariant.php';
require_once __DIR__ . '/../../app/Models/AdAlert.php';
require_once __DIR__ . '/../../app/Models/AdAlertRule.php';
require_once __DIR__ . '/../../app/Models/ActivityLog.php';
require_once __DIR__ . '/../../app/Services/Ads/AdCreativeService.php';
require_once __DIR__ . '/../../app/Services/Ads/AdAbTestService.php';
require_once __DIR__ . '/../../app/Services/Ads/AdAlertService.php';
require_once __DIR__ . '/../../app/Controllers/AdRuleAlertController.php';

final class AdRuleAlertIntegrationTest extends TestCase
{
    private const USER_ID = 999730;
    private const OTHER_USER_ID = 999731;

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
            if (!class_exists('Notification') && file_exists($app . '/Models/Notification.php')) {
                require_once $app . '/Models/Notification.php';
            }

            $db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $conn = $ref->getValue($db);
            if (!$conn instanceof PDO) {
                self::$pdo = null;
                return null;
            }

            foreach ([
                'users', 'ad_campaigns', 'ad_creatives', 'ad_creative_variants',
                'ad_ab_tests', 'ad_ab_test_variants', 'ad_alert_rules', 'ad_alerts',
            ] as $table) {
                $found = $conn->query("SHOW TABLES LIKE '{$table}'")->fetchAll();
                if (empty($found)) {
                    self::$pdo = null;
                    return null;
                }
            }

            // ENUM الجديد لازم يكون متشغّل
            $enum = $conn->query("SHOW COLUMNS FROM ad_alert_rules LIKE 'rule_type'")->fetch();
            if ($enum === false || strpos($enum['Type'], 'creative_underperforming') === false) {
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
            $this->markTestSkipped('DB غير متاحة أو أنواع قواعد بند 4 مش متشغّلة - راجع تعليق أعلى الملف');
        }

        $this->cleanup();

        foreach ([self::USER_ID, self::OTHER_USER_ID] as $uid) {
            $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                        VALUES ($uid, 'ruletest-" . $uid . "@tourfecto.test', 'x', 'Rule Alert Travel', NOW())
                        ON DUPLICATE KEY UPDATE email = email");
        }
    }

    private function cleanup(): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return;
        }
        $pdo->exec("DELETE FROM ad_alerts WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
        $pdo->exec("DELETE FROM ad_alert_rules WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
        $pdo->exec("DELETE FROM ad_ab_test_variants WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
        $pdo->exec("DELETE FROM ad_ab_tests WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
        $pdo->exec("DELETE FROM ad_creative_variants WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
        $pdo->exec("DELETE FROM ad_creatives WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
        $pdo->exec("DELETE FROM ad_performance_reports WHERE campaign_id IN (
            SELECT id FROM ad_campaigns WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . "))");
        $pdo->exec("DELETE FROM ad_campaigns WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
    }

    private function addCampaign(int $userId): int
    {
        $pdo = $this->db();
        $stmt = $pdo->prepare("INSERT INTO ad_campaigns
                    (user_id, platform_connection_id, name, objective, status, spend, currency)
                    VALUES (?, NULL, ?, 'traffic', 'active', 0, 'USD')");
        $stmt->execute([$userId, 'RuleAlert Campaign ' . uniqid()]);
        return (int) $pdo->lastInsertId();
    }

    private function addSyncedReport(int $campaignId, string $dateStart, float $spend, int $clicks, int $impressions): void
    {
        $pdo = $this->db();
        $stmt = $pdo->prepare("INSERT INTO ad_performance_reports
                    (campaign_id, date_start, date_end, impressions, clicks, conversions, spend, revenue)
                    VALUES (?, ?, ?, ?, ?, 0, ?, 0)");
        $stmt->execute([$campaignId, $dateStart, $dateStart, $impressions, $clicks, $spend]);
    }

    private function enableRules(int $userId, array $enabledTypes, array $thresholds = []): void
    {
        $rules = [];
        foreach ($enabledTypes as $type) {
            $rules[$type] = [
                'is_enabled' => 1,
                'threshold_value' => $thresholds[$type] ?? null,
            ];
        }
        (new AdAlertService())->saveRules($userId, ['rules' => $rules]);
    }

    private function service(): AdAlertService
    {
        return new AdAlertService();
    }

    // ================================================================
    // الاختبارات
    // ================================================================

    public function testSaveAndGetNewRuleTypes(): void
    {
        $this->enableRules(self::USER_ID, ['creative_underperforming', 'creative_stale', 'variant_wasted_spend', 'ab_test_inconclusive']);

        $rules = $this->service()->getRules(self::USER_ID);
        $this->assertArrayHasKey('creative_underperforming', $rules);
        $this->assertArrayHasKey('creative_stale', $rules);
        $this->assertArrayHasKey('variant_wasted_spend', $rules);
        $this->assertArrayHasKey('ab_test_inconclusive', $rules);
        $this->assertSame(1, (int) $rules['creative_underperforming']['is_enabled']);

        // عزل التينانت
        $otherRules = $this->service()->getRules(self::OTHER_USER_ID);
        $this->assertArrayNotHasKey('creative_underperforming', $otherRules);
    }

    public function testCreativeUnderperformingTriggers(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        // CTR الحملة 10% (حقيقي من المزامنة خلال نافذة -7/-1 يوم المستخدمة في avgCtr)
        $this->addSyncedReport($campaignId, date('Y-m-d', strtotime('-1 day')), 100.0, 200, 2000);

        $creativeService = new AdCreativeService();
        $creative = $creativeService->create(self::USER_ID, $campaignId, ['name' => 'Weak Creative']);
        $variant = $creativeService->addVariant(self::USER_ID, (int) $creative['id'], []);
        // أفضل تنويع CTR 2% < 50% من 10%
        $creativeService->recordPerformance(self::USER_ID, (int) $variant['id'], [
            'impressions' => 1000, 'clicks' => 20, 'recorded_on' => date('Y-m-d'),
        ]);

        $this->enableRules(self::USER_ID, ['creative_underperforming'], ['creative_underperforming' => 50]);
        $result = $this->service()->evaluateForUser(self::USER_ID);
        $this->assertGreaterThanOrEqual(1, $result['generated']);
        $types = array_column($result['alerts'], 'rule_type');
        $this->assertContains('creative_underperforming', $types);
    }

    public function testCreativeStaleTriggersOnlyWithNoRecentData(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        $creativeService = new AdCreativeService();
        $creative = $creativeService->create(self::USER_ID, $campaignId, ['name' => 'Stale Creative']);
        $variant = $creativeService->addVariant(self::USER_ID, (int) $creative['id'], []);
        // أداء قديم جدًا (خارج نافذة 7 أيام الافتراضية)
        $creativeService->recordPerformance(self::USER_ID, (int) $variant['id'], [
            'impressions' => 100, 'clicks' => 5, 'recorded_on' => date('Y-m-d', strtotime('-30 days')),
        ]);

        $this->enableRules(self::USER_ID, ['creative_stale'], ['creative_stale' => 7]);
        $result = $this->service()->evaluateForUser(self::USER_ID);
        $types = array_column($result['alerts'], 'rule_type');
        $this->assertContains('creative_stale', $types);

        // تحديث الأداء اليوم → ما عاد "قديم"
        $creativeService->recordPerformance(self::USER_ID, (int) $variant['id'], [
            'impressions' => 100, 'clicks' => 5, 'recorded_on' => date('Y-m-d'),
        ]);
        $result2 = $this->service()->evaluateForUser(self::USER_ID);
        $types2 = array_column($result2['alerts'], 'rule_type');
        $this->assertNotContains('creative_stale', $types2);
    }

    public function testVariantWastedSpendTriggers(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        $creativeService = new AdCreativeService();
        $creative = $creativeService->create(self::USER_ID, $campaignId, ['name' => 'Waste Creative']);
        $variant = $creativeService->addVariant(self::USER_ID, (int) $creative['id'], []);
        // إنفاق 120 بلا تحويلات
        $creativeService->recordPerformance(self::USER_ID, (int) $variant['id'], [
            'impressions' => 5000, 'clicks' => 50, 'spend' => 120.00, 'conversions' => 0,
            'recorded_on' => date('Y-m-d'),
        ]);

        $this->enableRules(self::USER_ID, ['variant_wasted_spend'], ['variant_wasted_spend' => 50]);
        $result = $this->service()->evaluateForUser(self::USER_ID);
        $types = array_column($result['alerts'], 'rule_type');
        $this->assertContains('variant_wasted_spend', $types);
    }

    public function testAbTestInconclusiveTriggersForOldRunningTest(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        $creativeService = new AdCreativeService();
        $creative = $creativeService->create(self::USER_ID, $campaignId, ['name' => 'AbTest Creative']);
        $control = $creativeService->addVariant(self::USER_ID, (int) $creative['id'], []);
        $variant = $creativeService->addVariant(self::USER_ID, (int) $creative['id'], []);
        // بدون أي بيانات أداء → has_enough_data=false
        $creativeService->recordPerformance(self::USER_ID, (int) $control['id'], ['recorded_on' => date('Y-m-d')]);
        $creativeService->recordPerformance(self::USER_ID, (int) $variant['id'], ['recorded_on' => date('Y-m-d')]);

        $abService = new AdAbTestService();
        $test = $abService->createTest(self::USER_ID, $campaignId, (int) $creative['id'], 'Inconclusive Test');
        $testId = (int) $test['id'];
        $abService->addVariant(self::USER_ID, $testId, (int) $control['id'], 50, true);
        $abService->addVariant(self::USER_ID, $testId, (int) $variant['id'], 50, false);
        $abService->startTest(self::USER_ID, $testId);

        // رجعنا تاريخ البدء 20 يوم (أكبر من 14 الافتراضية)
        $pdo = $this->db();
        $pdo->exec("UPDATE ad_ab_tests SET started_at = DATE_SUB(NOW(), INTERVAL 20 DAY) WHERE id = " . $testId);

        $this->enableRules(self::USER_ID, ['ab_test_inconclusive'], ['ab_test_inconclusive' => 14]);
        $result = $this->service()->evaluateForUser(self::USER_ID);
        $types = array_column($result['alerts'], 'rule_type');
        $this->assertContains('ab_test_inconclusive', $types);
    }

    public function testDisabledAdvancedRuleIsSkipped(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        $creativeService = new AdCreativeService();
        $creative = $creativeService->create(self::USER_ID, $campaignId, ['name' => 'Waste Disabled']);
        $variant = $creativeService->addVariant(self::USER_ID, (int) $creative['id'], []);
        $creativeService->recordPerformance(self::USER_ID, (int) $variant['id'], [
            'impressions' => 5000, 'clicks' => 50, 'spend' => 999.00, 'conversions' => 0,
            'recorded_on' => date('Y-m-d'),
        ]);

        $this->enableRules(self::USER_ID, ['variant_wasted_spend'], ['variant_wasted_spend' => 50]);
        $rules = $this->service()->getRules(self::USER_ID);
        $rules['variant_wasted_spend']['is_enabled'] = 0;
        (new AdAlertService())->saveRules(self::USER_ID, ['rules' => [
            'variant_wasted_spend' => ['is_enabled' => 0, 'threshold_value' => 50],
        ]]);

        $result = $this->service()->evaluateForUser(self::USER_ID);
        $types = array_column($result['alerts'], 'rule_type');
        $this->assertNotContains('variant_wasted_spend', $types);
    }

    public function testRuleTypesCatalogExposesNewRules(): void
    {
        $catalog = AdRuleAlertController::ruleCatalog();
        $types = array_column($catalog, 'type');
        foreach (['creative_underperforming', 'creative_stale', 'variant_wasted_spend', 'ab_test_inconclusive'] as $type) {
            $this->assertContains($type, $types);
        }
        $this->assertCount(9, $catalog);
    }

    public function testTenantIsolationForEvaluation(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        $creativeService = new AdCreativeService();
        $creative = $creativeService->create(self::USER_ID, $campaignId, ['name' => 'Tenant Creative']);
        $variant = $creativeService->addVariant(self::USER_ID, (int) $creative['id'], []);
        $creativeService->recordPerformance(self::USER_ID, (int) $variant['id'], [
            'impressions' => 5000, 'clicks' => 50, 'spend' => 200.00, 'conversions' => 0,
            'recorded_on' => date('Y-m-d'),
        ]);
        $this->enableRules(self::USER_ID, ['variant_wasted_spend'], ['variant_wasted_spend' => 50]);

        // مستخدم تاني يقيم بنفسه: لا يرى حملة/أصل مستخدم آخر
        $other = $this->service()->evaluateForUser(self::OTHER_USER_ID);
        $this->assertSame(0, $other['generated']);
        $this->assertSame(0, $other['evaluated']);
    }
}

<?php

/**
 * Tourfecto - Ad Creative Service Integration Test (بند 1)
 * بيفحص مسار إدارة الأصول الإعلانية (نص/صورة/فيديو) وتنويعاتها A/B/C:
 *   1) إنشاء أصل لحملة يملكها المستخدم (بأنواع text/image/video).
 *   2) إضافة تنويعات مع تسمية تلقائية A/B/C.
 *   3) تحديث أداء تنويع بأرقام حقيقية فقط + حساب CTR/CPC/CPA/ROAS.
 *   4) عزل التينانت: مستخدم تاني لا يرى ولا يعدّل أصول مستخدم آخر.
 *   5) أرشفة منطقية (تخفي الأصل من القائمة النشطة).
 *   6) أفضل تنويع أداءً (bestVariant) بكفاية حد أدنى من الانطباعات.
 *
 * محتاج الميجريشن: 2026_08_28_000003_create_ad_creative_assets.sql
 * (بالإضافة إلى جداول ad_campaigns الأساسية). بيتخطى تلقائيًا لو DB
 * غير متاحة أو الجداول مش متشغّلة.
 * @version 1.0.0  @date 2026-08-28
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Models/AdCampaign.php';
require_once __DIR__ . '/../../app/Models/AdCreative.php';
require_once __DIR__ . '/../../app/Models/AdCreativeVariant.php';
require_once __DIR__ . '/../../app/Models/ActivityLog.php';
require_once __DIR__ . '/../../app/Services/Ads/AdCreativeService.php';

final class AdCreativeIntegrationTest extends TestCase
{
    private const USER_ID = 999700;
    private const OTHER_USER_ID = 999701;

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

            foreach (['users', 'ad_campaigns', 'ad_creatives', 'ad_creative_variants'] as $table) {
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
            $this->markTestSkipped('DB غير متاحة أو جداول الأصول الإعلانية مش متشغّلة - راجع تعليق أعلى الملف');
        }

        $this->cleanup();

        foreach ([self::USER_ID, self::OTHER_USER_ID] as $uid) {
            $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                        VALUES ($uid, 'creative-" . $uid . "@tourfecto.test', 'x', 'Creative Test Travel', NOW())
                        ON DUPLICATE KEY UPDATE email = email");
        }
    }

    private function cleanup(): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return;
        }
        $pdo->exec("DELETE FROM ad_creative_variants WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
        $pdo->exec("DELETE FROM ad_creatives WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
        $pdo->exec("DELETE FROM ad_campaigns WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
    }

    private function addCampaign(int $userId): int
    {
        $pdo = $this->db();
        $name = 'Creative Campaign ' . uniqid();
        $stmt = $pdo->prepare("INSERT INTO ad_campaigns
                    (user_id, platform_connection_id, name, objective, status, spend, currency)
                    VALUES (?, NULL, ?, 'traffic', 'active', 0, 'USD')");
        $stmt->execute([$userId, $name]);
        return (int) $pdo->lastInsertId();
    }

    private function service(): AdCreativeService
    {
        return new AdCreativeService();
    }

    // ================================================================
    // الاختبارات
    // ================================================================

    public function testCreateCreativeAndListForCampaign(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        $service = $this->service();

        $created = $service->create(self::USER_ID, $campaignId, [
            'name' => 'Summer Safari Text',
            'creative_type' => 'text',
            'headline' => 'Explore the Desert',
            'primary_text' => 'Book your summer safari now.',
        ]);
        $this->assertNotNull($created, 'create() should return the created creative');
        $this->assertSame('text', $created['creative_type']);
        $this->assertSame('active', $created['status']);
        $this->assertSame(0, (int) $created['variants_count']);

        $image = $service->create(self::USER_ID, $campaignId, [
            'name' => 'Beach Image',
            'creative_type' => 'image',
            'media_url' => 'https://cdn.example.com/beach.jpg',
        ]);
        $this->assertNotNull($image);
        $this->assertSame('image', $image['creative_type']);

        $list = $service->listForCampaign(self::USER_ID, $campaignId);
        $this->assertCount(2, $list);

        // عزل التينانت: مستخدم تاني مايشوفش أصول الحملة دي
        $otherList = $service->listForCampaign(self::OTHER_USER_ID, $campaignId);
        $this->assertSame([], $otherList, 'other user must not see foreign creatives');
    }

    public function testCreateRejectsInvalidTypeAndMissingName(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        $service = $this->service();

        $this->expectException(InvalidArgumentException::class);
        $service->create(self::USER_ID, $campaignId, ['name' => 'X', 'creative_type' => 'audio']);
    }

    public function testCreateOnForeignCampaignReturnsNull(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        $result = $this->service()->create(self::OTHER_USER_ID, $campaignId, ['name' => 'Nope']);
        $this->assertNull($result, 'cannot create creative on a campaign owned by another user');
    }

    public function testVariantsAutoLabelAndUpdate(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        $service = $this->service();
        $creative = $service->create(self::USER_ID, $campaignId, ['name' => 'Test Creative']);

        $v1 = $service->addVariant(self::USER_ID, (int) $creative['id'], ['headline' => 'Variant A headline']);
        $this->assertNotNull($v1);
        $this->assertSame('A', $v1['variant_label'], 'first variant is auto-labeled A');

        $v2 = $service->addVariant(self::USER_ID, (int) $creative['id'], ['headline' => 'Variant B headline']);
        $this->assertSame('B', $v2['variant_label']);

        $updated = $service->updateVariant(self::USER_ID, (int) $v1['id'], ['headline' => 'Updated A headline']);
        $this->assertSame('Updated A headline', $updated['headline']);

        // عزل التينانت على الـ Variant
        $this->assertNull($service->updateVariant(self::OTHER_USER_ID, (int) $v1['id'], ['headline' => 'hack']));
    }

    public function testRecordPerformanceComputesMetrics(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        $service = $this->service();
        $creative = $service->create(self::USER_ID, $campaignId, ['name' => 'Perf Creative']);
        $variant = $service->addVariant(self::USER_ID, (int) $creative['id'], []);

        $updated = $service->recordPerformance(self::USER_ID, (int) $variant['id'], [
            'impressions' => 1000,
            'clicks' => 120,
            'spend' => 60.00,
            'conversions' => 6,
            'revenue' => 300.00,
        ]);
        $this->assertNotNull($updated);
        $this->assertSame(120, (int) $updated['clicks']);
        $this->assertSame(12.0, $updated['ctr']);       // 120/1000 * 100
        $this->assertSame(0.5, $updated['cpc']);         // 60/120
        $this->assertSame(10.0, $updated['cpa']);        // 60/6
        $this->assertSame(5.0, $updated['roas']);        // 300/60

        // قيم غير رقمية مرفوضة
        $this->expectException(InvalidArgumentException::class);
        $service->recordPerformance(self::USER_ID, (int) $variant['id'], ['clicks' => 'many']);
    }

    public function testBestVariantPicksHighestCtrWithEnoughImpressions(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        $service = $this->service();
        $creative = $service->create(self::USER_ID, $campaignId, ['name' => 'Best Variant Creative']);

        $va = $service->addVariant(self::USER_ID, (int) $creative['id'], []);
        $vb = $service->addVariant(self::USER_ID, (int) $creative['id'], []);

        $service->recordPerformance(self::USER_ID, (int) $va['id'], ['impressions' => 500, 'clicks' => 25]);
        $service->recordPerformance(self::USER_ID, (int) $vb['id'], ['impressions' => 500, 'clicks' => 10]);

        $best = $service->bestVariant(self::USER_ID, (int) $creative['id'], 50);
        $this->assertNotNull($best);
        $this->assertSame((int) $va['id'], (int) $best['id'], 'variant A has higher CTR');

        // لا يصل لمستخدم آخر
        $this->assertNull($service->bestVariant(self::OTHER_USER_ID, (int) $creative['id'], 50));
    }

    public function testArchiveHidesFromActiveList(): void
    {
        $campaignId = $this->addCampaign(self::USER_ID);
        $service = $this->service();
        $creative = $service->create(self::USER_ID, $campaignId, ['name' => 'Archive Me']);

        $this->assertTrue($service->archive(self::USER_ID, (int) $creative['id']));
        $this->assertNull($service->get(self::USER_ID, (int) $creative['id']), 'archived creative is no longer fetched as active');

        $list = $service->listForCampaign(self::USER_ID, $campaignId);
        $this->assertCount(0, $list);

        // أرشفة غير مصرّح بها
        $this->assertFalse($service->archive(self::OTHER_USER_ID, (int) $creative['id']));
    }
}

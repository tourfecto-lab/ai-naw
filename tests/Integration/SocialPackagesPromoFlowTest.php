<?php

use PHPUnit\Framework\TestCase;

/** اشتراك كامل بكود خصم: الخصم على أول دورة بس، حد الاستخدام الكلي، ومرة لكل عميل، والتجديد بالسعر العادي */
final class SocialPackagesPromoFlowTest extends TestCase
{
    private const U1 = 900101;
    private const U2 = 900102;
    private const SETTING = 'social_packages_config';
    private PDO $pdo;

    protected function setUp(): void
    {
        try {
            $this->pdo = Database::getInstance()->getConnection();
            $this->pdo->query('SELECT 1 FROM social_subscriptions LIMIT 1');
        } catch (Throwable $e) {
            $this->markTestSkipped('قاعدة الاختبار مش متاحة: ' . $e->getMessage());
        }
        SocialPackages::ensureSchema();
        $this->cleanup();
        foreach ([self::U1, self::U2] as $u) {
            $this->pdo->exec("INSERT INTO users (id, email, password, company_name, created_at) VALUES ($u, 'promoflow-$u@tourfecto.test', 'x', 'Promo Flow', NOW()) ON DUPLICATE KEY UPDATE email = email");
            $this->pdo->exec("INSERT INTO wallet_transactions (user_id, type, amount, currency, status, reference_note, approved_at) VALUES ($u, 'deposit', 500, 'USD', 'completed', 'phpunit', NOW())");
        }
        $this->resetCache();
        SocialPackages::saveConfig(['promos' => [['code' => 'FLOW20', 'percent' => 20, 'tiers' => ['pro'], 'max_uses' => 1]]], null);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->cleanup();
            $this->resetCache();
        }
    }

    private function resetCache(): void
    {
        $p = new ReflectionProperty(SocialPackages::class, 'cfgCache');
        $p->setAccessible(true);
        $p->setValue(null, null);
        SocialPackages::flush();
    }

    private function cleanup(): void
    {
        $ids = self::U1 . ',' . self::U2;
        $this->pdo->exec("DELETE FROM social_subscriptions WHERE user_id IN ($ids)");
        $this->pdo->exec("DELETE FROM social_promo_redemptions WHERE promo_code = 'FLOW20'");
        $this->pdo->exec("DELETE FROM wallet_transactions WHERE user_id IN ($ids)");
        $this->pdo->exec("DELETE FROM users WHERE id IN ($ids)");
        $this->pdo->exec("DELETE FROM system_settings WHERE setting_key = '" . self::SETTING . "'");
    }

    public function testPromoDiscountsFirstTermOnlyAndRenewsAtFullPrice(): void
    {
        $r = SocialPackages::subscribe(self::U1, 'pro', 'monthly', 'flow-key-0001', null, 'flow20');
        $this->assertSame('subscribed', $r['action']);
        $this->assertSame(39.2, $r['charged']);
        $this->assertSame('FLOW20', $r['promo']);

        $row = $this->pdo->query('SELECT price_paid, promo_code FROM social_subscriptions WHERE user_id = ' . self::U1)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('39.20', $row['price_paid']);
        $this->assertSame('FLOW20', $row['promo_code']);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM social_promo_redemptions WHERE promo_code = 'FLOW20'")->fetchColumn());

        // تجديد الدورة: لازم بالسعر الكامل 49 ويتشال علامة الخصم
        $this->pdo->exec("UPDATE social_subscriptions SET period_end = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE user_id = " . self::U1);
        $this->assertGreaterThanOrEqual(1, SocialPackages::renewDue());
        $after = $this->pdo->query('SELECT price_paid, promo_code FROM social_subscriptions WHERE user_id = ' . self::U1)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('49.00', $after['price_paid']);
        $this->assertNull($after['promo_code']);
    }

    public function testTotalUsageLimitStopsSecondCustomer(): void
    {
        SocialPackages::subscribe(self::U1, 'pro', 'monthly', 'flow-key-0002', null, 'FLOW20');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('خلصت');
        SocialPackages::subscribe(self::U2, 'pro', 'monthly', 'flow-key-0003', null, 'FLOW20');
    }

    public function testPromoNotValidForOtherTier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SocialPackages::subscribe(self::U1, 'basic', 'monthly', 'flow-key-0004', null, 'FLOW20');
    }

    public function testRetryWithSameRequestKeyDoesNotChargeOrRedeemTwice(): void
    {
        SocialPackages::subscribe(self::U1, 'pro', 'monthly', 'flow-key-0005', null, 'FLOW20');
        try {
            SocialPackages::subscribe(self::U1, 'pro', 'monthly', 'flow-key-0005', null, 'FLOW20');
        } catch (InvalidArgumentException $e) {
            // الكود اتستخدم قبل كده: الرفض صح
        }
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM social_promo_redemptions WHERE promo_code = 'FLOW20'")->fetchColumn());
        $charges = (int) $this->pdo->query("SELECT COUNT(*) FROM wallet_transactions WHERE user_id = " . self::U1 . " AND type = 'subscription_charge'")->fetchColumn();
        $this->assertSame(1, $charges);
    }

    public function testUsageReportRanksByCostAndFlagsEightyPercent(): void
    {
        $month = '2099-01';
        $this->pdo->exec("DELETE FROM social_usage_monthly WHERE month_key = '$month'");
        // U1: على الباقة الافتراضية (basic = 90 منشور بعد التعديل)، استهلك 80 = ‎≥80%‎. U2: استهلاك قليل
        $this->pdo->exec("INSERT INTO social_usage_monthly (user_id, month_key, posts, ai_captions) VALUES (" . self::U1 . ", '$month', 80, 10), (" . self::U2 . ", '$month', 2, 1)");
        try {
            $rep = SocialPackages::usageReport(10, $month);
        } finally {
            $this->pdo->exec("DELETE FROM social_usage_monthly WHERE month_key = '$month'");
        }
        $this->assertSame([self::U1, self::U2], array_column($rep, 'user_id'));
        $this->assertTrue($rep[0]['alert']);
        $this->assertFalse($rep[1]['alert']);
        $this->assertSame(round(80 * 0.001 + 10 * 0.003, 2), $rep[0]['est_cost']);
    }
}

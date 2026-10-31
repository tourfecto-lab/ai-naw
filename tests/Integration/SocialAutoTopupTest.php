<?php

use PHPUnit\Framework\TestCase;

/** الاستخدام الزائد: شحن حزمة منشورات تلقائيًا من المحفظة، اختياري من العميل، وبسقف شهري - ومش بيتخطى الرصيد ولا السقف */
final class SocialAutoTopupTest extends TestCase
{
    private const U = 900901;
    private const SETTING = 'social_packages_config';
    private PDO $pdo;
    private string $tier = 'free';

    protected function setUp(): void
    {
        try {
            $this->pdo = Database::getInstance()->getConnection();
            $this->pdo->query('SELECT 1 FROM wallet_transactions LIMIT 1');
        } catch (Throwable $e) {
            $this->markTestSkipped('قاعدة الاختبار مش متاحة: ' . $e->getMessage());
        }
        SocialPackages::ensureSchema();
        $this->cleanup();
        $this->pdo->exec("INSERT INTO users (id, email, password, company_name, created_at) VALUES (" . self::U . ", 'topup-" . self::U . "@tourfecto.test', 'x', 'Topup', NOW()) ON DUPLICATE KEY UPDATE email = email");
        $this->reset();
        $this->tier = SocialPackages::entitlements(self::U)['tier'];
        // حد صغير عشان نوصله بسهولة
        SocialPackages::saveConfig(['tiers' => [$this->tier => ['posts' => 3]]], null);
        $this->usage(3);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->cleanup();
            $this->reset();
        }
    }

    private function reset(): void
    {
        $p = new ReflectionProperty(SocialPackages::class, 'cfgCache');
        $p->setAccessible(true);
        $p->setValue(null, null);
        SocialPackages::flush();
    }

    private function cleanup(): void
    {
        $u = self::U;
        foreach (['wallet_transactions', 'social_addons', 'social_topup_prefs', 'social_topup_log', 'social_usage_monthly', 'social_usage_alerts', 'notifications'] as $t) {
            $this->pdo->exec("DELETE FROM $t WHERE user_id = $u");
        }
        $this->pdo->exec("DELETE FROM system_settings WHERE setting_key = '" . self::SETTING . "'");
        $this->pdo->exec("DELETE FROM users WHERE id = $u");
    }

    private function usage(int $posts): void
    {
        $this->pdo->exec("INSERT INTO social_usage_monthly (user_id, month_key, posts) VALUES (" . self::U . ", '" . SocialPackages::monthKey() . "', $posts) ON DUPLICATE KEY UPDATE posts = $posts");
        SocialPackages::flush();
    }

    private function fund(float $usd): void
    {
        $this->pdo->exec("INSERT INTO wallet_transactions (user_id, type, amount, currency, status, reference_note, approved_at) VALUES (" . self::U . ", 'deposit', $usd, 'USD', 'completed', 'phpunit', NOW())");
    }

    private function packPrice(): float
    {
        return (float) SocialPackages::config()['addons']['posts_100']['price'];
    }

    public function testNothingHappensUntilTheCustomerOptsIn(): void
    {
        $this->fund(100);
        $this->assertFalse(SocialPackages::autoTopUp(self::U, 'posts', 1));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM social_addons WHERE user_id = ' . self::U)->fetchColumn());
        $this->assertSame(100.0, (new WalletService())->getBalance(self::U));
    }

    public function testBuysOnePackWhenOverTheLimitAndAllowsThePost(): void
    {
        $this->fund(100);
        SocialPackages::setTopupPref(self::U, true, 20);
        $this->assertTrue(SocialPackages::autoTopUp(self::U, 'posts', 2));
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM social_addons WHERE user_id = ' . self::U . " AND kind = 'posts'")->fetchColumn());
        $this->assertEqualsWithDelta(100 - $this->packPrice(), (new WalletService())->getBalance(self::U), 0.001);
        $this->assertSame(103, SocialPackages::entitlements(self::U)['limits']['posts'], '3 + 100');
        SocialPackages::assertPublish(self::U, [], 2); // مابيرميش استثناء
        $this->assertEqualsWithDelta($this->packPrice(), SocialPackages::topupPref(self::U)['spent'], 0.001);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = " . self::U . " AND title LIKE '%اتشحنت%'")->fetchColumn());
    }

    public function testNoPurchaseWhenThePostStillFitsTheLimit(): void
    {
        $this->usage(1);
        $this->fund(100);
        SocialPackages::setTopupPref(self::U, true, 20);
        $this->assertTrue(SocialPackages::autoTopUp(self::U, 'posts', 2));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM social_addons WHERE user_id = ' . self::U)->fetchColumn());
    }

    public function testMonthlyCapStopsTheTopUp(): void
    {
        $this->fund(100);
        SocialPackages::setTopupPref(self::U, true, max(1, $this->packPrice() - 1)); // السقف أقل من سعر حزمة
        $this->assertFalse(SocialPackages::autoTopUp(self::U, 'posts', 1));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM social_addons WHERE user_id = ' . self::U)->fetchColumn());
        $this->assertSame(100.0, (new WalletService())->getBalance(self::U));
    }

    public function testInsufficientWalletBuysNothingAndLeavesTheLimitInPlace(): void
    {
        $this->fund(1);
        SocialPackages::setTopupPref(self::U, true, 50);
        $this->assertFalse(SocialPackages::autoTopUp(self::U, 'posts', 1));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM social_topup_log WHERE user_id = ' . self::U)->fetchColumn());
        $this->assertSame(3, SocialPackages::entitlements(self::U)['limits']['posts']);
        $this->expectException(SocialPackageLimit::class);
        SocialPackages::assertPublish(self::U, [], 1);
    }

    public function testAdminSwitchDisablesTheFeatureForEveryone(): void
    {
        $this->fund(100);
        SocialPackages::setTopupPref(self::U, true, 50);
        SocialPackages::saveConfig(['auto_topup' => false], null);
        $this->assertFalse(SocialPackages::autoTopUp(self::U, 'posts', 1));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM social_addons WHERE user_id = ' . self::U)->fetchColumn());
    }

    public function testDisabledAddonMeansNoTopUp(): void
    {
        $this->fund(100);
        SocialPackages::setTopupPref(self::U, true, 50);
        SocialPackages::saveConfig(['addons' => ['posts_100' => ['enabled' => false]]], null);
        $this->assertFalse(SocialPackages::autoTopUp(self::U, 'posts', 1));
    }

    public function testBuyingManyPacksIsBounded(): void
    {
        $this->fund(1000);
        SocialPackages::setTopupPref(self::U, true, 500);
        $this->assertFalse(SocialPackages::autoTopUp(self::U, 'posts', 900), 'أكتر من 5 حزم في طلب واحد مرفوض');
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM social_addons WHERE user_id = ' . self::U)->fetchColumn());
    }

    public function testPrefIsCappedAndClamped(): void
    {
        $p = SocialPackages::setTopupPref(self::U, true, 99999);
        $this->assertSame(500.0, $p['cap']);
        $p = SocialPackages::setTopupPref(self::U, false, -5);
        $this->assertFalse($p['enabled']);
        $this->assertSame(1.0, $p['cap']);
    }
}

<?php

use PHPUnit\Framework\TestCase;

/** باقات جديدة بالكامل من الأدمن + عملة تسعير لكل باقة (الخصم بالدولار المكافئ بسعر الصرف الحالي) */
final class SocialCustomTiersCurrencyTest extends TestCase
{
    private const U = 901001;
    private const SETTING = 'social_packages_config';
    private PDO $pdo;

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
        $this->pdo->exec("INSERT INTO users (id, email, password, company_name, created_at) VALUES (" . self::U . ", 'tiers-" . self::U . "@tourfecto.test', 'x', 'Tiers', NOW()) ON DUPLICATE KEY UPDATE email = email");
        $this->reset();
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
        CurrencyService::resetCache();
    }

    private function cleanup(): void
    {
        $u = self::U;
        foreach (['wallet_transactions', 'social_subscriptions', 'social_usage_monthly', 'social_addons', 'notifications', 'social_promo_redemptions'] as $t) {
            $this->pdo->exec("DELETE FROM $t WHERE user_id = $u");
        }
        $this->pdo->exec("DELETE FROM system_settings WHERE setting_key = '" . self::SETTING . "'");
        $this->pdo->exec("DELETE FROM users WHERE id = $u");
    }

    private function fund(float $usd): void
    {
        $this->pdo->exec("INSERT INTO wallet_transactions (user_id, type, amount, currency, status, reference_note, approved_at) VALUES (" . self::U . ", 'deposit', $usd, 'USD', 'completed', 'phpunit', NOW())");
    }

    private function addTier(string $key = 'starterplus', array $extra = []): array
    {
        return SocialPackages::saveConfig(['new_tiers' => [['key' => $key, 'name' => 'ستارتر بلس', 'name_en' => 'Starter Plus', 'after' => 'basic', 'clone_from' => 'basic'] + $extra]], null);
    }

    // ---------------------------------------------------------------- باقة جديدة

    public function testNewTierIsCreatedHiddenFromSaleWithTheClonedSpec(): void
    {
        $cfg = $this->addTier();
        $t = $cfg['tiers']['starterplus'];
        $this->assertTrue($t['custom']);
        $this->assertFalse($t['for_sale'], 'مقفولة للبيع لحد ما الأدمن يفتحها');
        $this->assertSame('Starter Plus', $t['name_en']);
        $this->assertSame($cfg['tiers']['basic']['posts'], $t['posts']);
        $this->assertSame($cfg['tiers']['basic']['price_monthly'], $t['price_monthly']);
        $this->assertGreaterThan($cfg['tiers']['basic']['rank'], $t['rank']);
        $this->assertLessThan($cfg['tiers']['pro']['rank'], $t['rank'], 'ترتيبها بين أساسي واحترافي');
        $this->assertContains('starterplus', SocialPackages::sellableTierKeys());
        $order = SocialPackages::sellableTierKeys();
        $this->assertSame(['basic', 'starterplus', 'pro'], array_slice($order, 0, 3));
    }

    /** @dataProvider badKeys */
    public function testInvalidTierKeysAreRejected(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->addTier($key);
    }

    public static function badKeys(): array
    {
        return ['too short' => ['ab'], 'starts with digit' => ['1plan'], 'uppercase gets lowered but spaces fail' => ['my plan'], 'reserved free' => ['free'], 'reserved custom' => ['custom'], 'built-in pro' => ['pro'], 'too long' => ['abcdefghijklmnopq']];
    }

    public function testDuplicateTierAndMissingNameAreRejected(): void
    {
        $this->addTier();
        try {
            $this->addTier();
            $this->fail('مكرر');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('مايتكررش', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        SocialPackages::saveConfig(['new_tiers' => [['key' => 'noname1', 'name' => '  ']]], null);
    }

    public function testNewTierCanBeEditedAndSoldAndSubscribedTo(): void
    {
        $this->addTier();
        SocialPackages::saveConfig(['tiers' => ['starterplus' => ['for_sale' => true, 'price_monthly' => 29, 'price_yearly' => 290, 'posts' => 150, 'accounts' => 5]]], null);
        $this->fund(100);
        $r = SocialPackages::subscribe(self::U, 'starterplus', 'monthly');
        $this->assertSame('subscribed', $r['action']);
        $this->assertSame(29.0, $r['charged']);
        $limits = SocialPackages::entitlements(self::U)['limits'];
        $this->assertSame(150, $limits['posts']);
        $this->assertSame(5, $limits['accounts']);
        $this->assertSame('starterplus', SocialPackages::entitlements(self::U)['tier']);
    }

    public function testUnsoldNewTierCannotBeBought(): void
    {
        $this->addTier();
        $this->fund(100);
        $this->expectException(InvalidArgumentException::class);
        SocialPackages::subscribe(self::U, 'starterplus', 'monthly');
    }

    public function testNewTierRankDrivesUpgradeVersusDowngrade(): void
    {
        $this->addTier();
        SocialPackages::saveConfig(['tiers' => ['starterplus' => ['for_sale' => true, 'price_monthly' => 29, 'price_yearly' => 290]]], null);
        $this->fund(300);
        SocialPackages::subscribe(self::U, 'starterplus', 'monthly');
        $up = SocialPackages::subscribe(self::U, 'pro', 'monthly');
        $this->assertSame('upgraded', $up['action'], 'احترافي أعلى من الباقة المضافة بين أساسي واحترافي');
        $down = SocialPackages::subscribe(self::U, 'starterplus', 'monthly');
        $this->assertSame('scheduled', $down['action'], 'النزول بيتطبق عند التجديد');
    }

    public function testRemovalIsBlockedWhileSubscribersExistThenAllowedAfter(): void
    {
        $this->addTier();
        SocialPackages::saveConfig(['tiers' => ['starterplus' => ['for_sale' => true, 'price_monthly' => 29, 'price_yearly' => 290]]], null);
        $this->fund(100);
        SocialPackages::subscribe(self::U, 'starterplus', 'monthly');
        try {
            SocialPackages::saveConfig(['remove_tiers' => ['starterplus']], null);
            $this->fail('فيه مشترك شغال');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('مشترك شغال', $e->getMessage());
        }
        $this->assertArrayHasKey('starterplus', SocialPackages::config()['tiers']);
        $this->pdo->exec("UPDATE social_subscriptions SET status = 'expired' WHERE user_id = " . self::U);
        $cfg = SocialPackages::saveConfig(['remove_tiers' => ['starterplus']], null);
        $this->assertArrayNotHasKey('starterplus', $cfg['tiers']);
    }

    public function testBuiltInTiersCanNeverBeRemovedAndPlanMapFollowsRemoval(): void
    {
        $cfg = SocialPackages::saveConfig(['remove_tiers' => ['pro', 'free', 'basic']], null);
        $this->assertArrayHasKey('pro', $cfg['tiers']);
        $this->addTier('promoted');
        SocialPackages::saveConfig(['plan_map' => ['seo-growth' => 'promoted'], 'plan_default' => 'promoted'], null);
        $this->assertSame('promoted', SocialPackages::config()['plan_map']['seo-growth']);
        $cfg = SocialPackages::saveConfig(['remove_tiers' => ['promoted']], null);
        $this->assertSame('basic', $cfg['plan_default']);
        $this->assertSame('basic', $cfg['plan_map']['seo-growth'] ?? 'basic');
    }

    public function testCustomTierAcceptsPromoCodes(): void
    {
        $this->addTier();
        $cfg = SocialPackages::saveConfig(['promos' => [['code' => 'NEWT10', 'percent' => 10, 'tiers' => ['starterplus', 'ghost']]]], null);
        $this->assertSame(['starterplus'], $cfg['promos'][0]['tiers'], 'باقة مش موجودة بتتشال');
    }

    public function testNewTierIsAuditedAndLimitedInNumber(): void
    {
        $this->addTier();
        $log = SocialPackages::auditLog(1);
        $this->assertArrayHasKey('tiers.starterplus.name_en', $log[0]['changes']);
        for ($i = 1; $i < SocialPackages::MAX_CUSTOM_TIERS; $i++) {
            $this->addTier('extra' . $i);
        }
        $this->expectException(InvalidArgumentException::class);
        $this->addTier('onetoomany');
    }

    // ---------------------------------------------------------------- عملة التسعير

    private function egpPerUsd(): float
    {
        return CurrencyService::rate('EGP');
    }

    public function testEgpPricedTierChargesTheUsdEquivalentAtTheCurrentRate(): void
    {
        $cfg = SocialPackages::saveConfig(['tiers' => ['pro' => ['currency' => 'EGP', 'price_native_monthly' => 2500, 'price_native_yearly' => 25000]]], null);
        $pro = $cfg['tiers']['pro'];
        $this->assertSame('EGP', $pro['currency']);
        $this->assertSame(2500.0, $pro['price_native_monthly']);
        $this->assertEqualsWithDelta(round(2500 / $this->egpPerUsd(), 2), $pro['price_monthly'], 0.011);
        $this->fund(500);
        $r = SocialPackages::subscribe(self::U, 'pro', 'monthly');
        $this->assertEqualsWithDelta($pro['price_monthly'], $r['charged'], 0.0001, 'بيتخصم الدولار المكافئ بالظبط');
    }

    public function testChangingCurrencyWithoutNewPriceConvertsTheCurrentPriceSoNothingLooksWrong(): void
    {
        $before = SocialPackages::config()['tiers']['pro']['price_monthly']; // 49$
        $cfg = SocialPackages::saveConfig(['tiers' => ['pro' => ['currency' => 'EGP']]], null);
        $pro = $cfg['tiers']['pro'];
        $this->assertEqualsWithDelta(round($before * $this->egpPerUsd(), 2), $pro['price_native_monthly'], 0.01);
        $this->assertEqualsWithDelta($before, $pro['price_monthly'], 0.02, 'القيمة الفعلية مابتتغيّرش بتغيير العملة لوحده');
    }

    public function testBackToUsdAndLegacyUsdPriceFieldsStillWork(): void
    {
        SocialPackages::saveConfig(['tiers' => ['pro' => ['currency' => 'EGP', 'price_native_monthly' => 2500]]], null);
        $cfg = SocialPackages::saveConfig(['tiers' => ['pro' => ['currency' => 'USD', 'price_native_monthly' => 55, 'price_native_yearly' => 550]]], null);
        $this->assertSame('USD', $cfg['tiers']['pro']['currency']);
        $this->assertSame(55.0, $cfg['tiers']['pro']['price_monthly']);
        // الحقل القديم price_monthly لباقة بالدولار لازم يشتغل (وما يتعطلش بسبب price_native قديم)
        $cfg = SocialPackages::saveConfig(['tiers' => ['pro' => ['price_monthly' => 60]]], null);
        $this->assertSame(60.0, $cfg['tiers']['pro']['price_monthly']);
        $this->assertSame(60.0, $cfg['tiers']['pro']['price_native_monthly']);
    }

    public function testUnsupportedCurrencyAndFreeTierAreIgnored(): void
    {
        $cfg = SocialPackages::saveConfig(['tiers' => ['pro' => ['currency' => 'XYZ'], 'free' => ['currency' => 'EGP']]], null);
        $this->assertSame('USD', $cfg['tiers']['pro']['currency']);
        $this->assertSame('USD', $cfg['tiers']['free']['currency']);
    }

    public function testEconomicsAndPriceNoticesStayInUsd(): void
    {
        SocialPackages::saveConfig(['tiers' => ['pro' => ['currency' => 'EGP', 'price_native_monthly' => 2500]]], null);
        $eco = SocialPackages::economics();
        $this->assertEqualsWithDelta(SocialPackages::config()['tiers']['pro']['price_monthly'], $eco['pro']['price_monthly'], 0.001);
        // مشترك شهري: تغيير السعر الأصلي بالجنيه = إشعار بالدولار المكافئ
        $this->fund(300);
        SocialPackages::subscribe(self::U, 'pro', 'monthly');
        SocialPackages::saveConfig(['tiers' => ['pro' => ['price_native_monthly' => 3500]]], null);
        $n = (int) $this->pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = " . self::U . " AND type = 'social_package'")->fetchColumn();
        $this->assertSame(1, $n);
    }

    public function testPromoStillAppliesOnAnEgpPricedTier(): void
    {
        SocialPackages::saveConfig(['tiers' => ['pro' => ['currency' => 'EGP', 'price_native_monthly' => 2500]], 'promos' => [['code' => 'EGP10', 'percent' => 10, 'tiers' => ['pro']]]], null);
        $this->fund(500);
        $usd = SocialPackages::config()['tiers']['pro']['price_monthly'];
        $r = SocialPackages::subscribe(self::U, 'pro', 'monthly', null, null, 'EGP10');
        $this->assertEqualsWithDelta(round($usd * 0.9, 2), $r['charged'], 0.011);
    }
}

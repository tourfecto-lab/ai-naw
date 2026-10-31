<?php

/**
 * Tourfecto - باقات السوشيال: تحكم الأدمن (أكواد الخصم، التكلفة والهامش، سجل التعديلات، سياسة المشتركين القدام).
 * الجزء الأول منطق بحت، والتاني على قاعدة الاختبار (system_settings + social_packages_audit).
 */

use PHPUnit\Framework\TestCase;

final class SocialPackagesAdminControlsTest extends TestCase
{
    private const SETTING = 'social_packages_config';

    private function promo(array $o = []): array
    {
        return $o + ['code' => 'LAUNCH20', 'label' => '', 'percent' => 20.0, 'tiers' => [], 'cycles' => [], 'starts' => '', 'ends' => '', 'max_uses' => 0, 'enabled' => true];
    }

    public function testPromoAppliesPercentDiscount(): void
    {
        $r = SocialPackages::applyPromo([$this->promo()], ' launch20 ', 'pro', 'monthly', 49.0);
        $this->assertSame(39.2, $r['price']);
        $this->assertSame(9.8, $r['discount']);
        $this->assertSame('LAUNCH20', $r['code']);
    }

    /** @dataProvider badPromos */
    public function testPromoRejections(array $promo, string $code, string $tier, string $cycle, int $uses, bool $mine): void
    {
        $this->expectException(InvalidArgumentException::class);
        SocialPackages::applyPromo([$promo], $code, $tier, $cycle, 49.0, $uses, $mine, strtotime('2026-11-15 UTC'));
    }

    public static function badPromos(): array
    {
        $p = static fn (array $o = []) => $o + ['code' => 'X1X', 'label' => '', 'percent' => 10.0, 'tiers' => [], 'cycles' => [], 'starts' => '', 'ends' => '', 'max_uses' => 0, 'enabled' => true];
        return [
            'unknown code' => [$p(), 'NOPE', 'pro', 'monthly', 0, false],
            'disabled' => [$p(['enabled' => false]), 'X1X', 'pro', 'monthly', 0, false],
            'not started' => [$p(['starts' => '2026-12-01']), 'X1X', 'pro', 'monthly', 0, false],
            'expired' => [$p(['ends' => '2026-11-01']), 'X1X', 'pro', 'monthly', 0, false],
            'wrong tier' => [$p(['tiers' => ['agency']]), 'X1X', 'pro', 'monthly', 0, false],
            'wrong cycle' => [$p(['cycles' => ['yearly']]), 'X1X', 'pro', 'monthly', 0, false],
            'max uses reached' => [$p(['max_uses' => 5]), 'X1X', 'pro', 'monthly', 5, false],
            'already used by this user' => [$p(), 'X1X', 'pro', 'monthly', 1, true],
        ];
    }

    public function testEconomicsComputesCostAndMargin(): void
    {
        $cfg = SocialPackages::config();
        $cfg['costs'] = ['ai_caption' => 0.01, 'post' => 0.01, 'channel_month' => 1.0, 'competitor_month' => 1.0, 'monthly_plan' => 1.0, 'unlimited_posts_assumed' => 100, 'gateway_fee_pct' => 10.0];
        $cfg['tiers']['basic'] = ['accounts' => 2, 'posts' => 10, 'ai_captions' => 100, 'competitors' => 0, 'monthly_plans' => 1, 'price_monthly' => 20.0, 'price_yearly' => 120.0] + $cfg['tiers']['basic'];
        $e = SocialPackages::economics($cfg)['basic'];
        // 2*1 + 10*0.01 + 100*0.01 + 0 + 1*1 = 4.10
        $this->assertSame(4.1, $e['cost']);
        // صافي بعد رسوم 10%: 18 - 4.1 = 13.9
        $this->assertSame(13.9, $e['margin_monthly']);
        $this->assertSame(69.5, $e['margin_pct_monthly']);
        // السنوي 120/12 = 10 → صافي 9 → 4.9 هامش = 49%
        $this->assertSame(49.0, $e['margin_pct_yearly']);
    }

    public function testEconomicsTreatsUnlimitedPostsWithAssumption(): void
    {
        $cfg = SocialPackages::config();
        $cfg['costs'] = ['ai_caption' => 0, 'post' => 0.01, 'channel_month' => 0, 'competitor_month' => 0, 'monthly_plan' => 0, 'unlimited_posts_assumed' => 200, 'gateway_fee_pct' => 0];
        $cfg['tiers']['agency']['posts'] = -1;
        $cfg['tiers']['agency']['monthly_plans'] = 0;
        $cfg['tiers']['agency']['competitors'] = 0;
        $cfg['tiers']['agency']['ai_captions'] = 0;
        $cfg['tiers']['agency']['accounts'] = 0;
        $this->assertSame(2.0, SocialPackages::economics($cfg)['agency']['cost']);
    }

    private function dbOrSkip(): PDO
    {
        try {
            $pdo = Database::getInstance()->getConnection();
            $pdo->query('SELECT 1 FROM system_settings LIMIT 1');
            return $pdo;
        } catch (Throwable $e) {
            $this->markTestSkipped('قاعدة الاختبار مش متاحة: ' . $e->getMessage());
        }
    }

    public function testSaveConfigWritesAuditLogWithOldAndNewValues(): void
    {
        $pdo = $this->dbOrSkip();
        SocialPackages::ensureSchema();
        $pdo->exec("DELETE FROM system_settings WHERE setting_key = '" . self::SETTING . "'");
        $pdo->exec('DELETE FROM social_packages_audit');
        SocialPackages::flush();
        $ref = new ReflectionProperty(SocialPackages::class, 'cfgCache');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        SocialPackages::saveConfig(['tiers' => ['pro' => ['price_monthly' => 59]]], null);
        $log = SocialPackages::auditLog(5);
        $this->assertCount(1, $log);
        $this->assertEquals(['old' => 49, 'new' => 59], $log[0]['changes']['tiers.pro.price_monthly']);

        // حفظ من غير تغيير فعلي مايسجّلش حاجة
        SocialPackages::saveConfig(['tiers' => ['pro' => ['price_monthly' => 59]]], null);
        $this->assertCount(1, SocialPackages::auditLog(5));

        $pdo->exec("DELETE FROM system_settings WHERE setting_key = '" . self::SETTING . "'");
        $ref->setValue(null, null);
    }

    public function testSaveConfigSanitizesPromosCostsAndPolicy(): void
    {
        $pdo = $this->dbOrSkip();
        $pdo->exec("DELETE FROM system_settings WHERE setting_key = '" . self::SETTING . "'");
        $ref = new ReflectionProperty(SocialPackages::class, 'cfgCache');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        $cfg = SocialPackages::saveConfig([
            'renewal_policy' => 'grandfather',
            'costs' => ['ai_caption' => 0.02, 'gateway_fee_pct' => 999, 'bogus' => 5],
            'promos' => [
                ['code' => 'ok-1', 'percent' => 25, 'tiers' => ['pro', 'hacker'], 'ends' => '2026-12-31'],
                ['code' => 'ok-1', 'percent' => 50],          // مكرر
                ['code' => 'a', 'percent' => 10],               // كود قصير
                ['code' => 'ZERO', 'percent' => 0],             // نسبة صفر
                ['code' => 'BIG', 'percent' => 101],            // أكتر من 100%
                ['code' => 'BAD DATE', 'percent' => 10],        // مسافة في الكود
                ['code' => 'DATE1', 'percent' => 10, 'ends' => 'tomorrow'],
            ],
        ], null);
        $this->assertSame('grandfather', $cfg['renewal_policy']);
        $this->assertSame(0.02, $cfg['costs']['ai_caption']);
        $this->assertSame(SocialPackages::COSTS['gateway_fee_pct'], $cfg['costs']['gateway_fee_pct']);
        $this->assertArrayNotHasKey('bogus', $cfg['costs']);
        $this->assertSame(['OK-1', 'DATE1'], array_column($cfg['promos'], 'code'));
        $this->assertSame(['pro'], $cfg['promos'][0]['tiers']);
        $this->assertSame('', $cfg['promos'][1]['ends']);

        $pdo->exec("DELETE FROM system_settings WHERE setting_key = '" . self::SETTING . "'");
        $ref->setValue(null, null);
    }

    public function testGrandfatherKeepsOldPriceOnlyWhenNoPromoAndSameTerm(): void
    {
        $pdo = $this->dbOrSkip();
        $pdo->exec("DELETE FROM system_settings WHERE setting_key = '" . self::SETTING . "'");
        $ref = new ReflectionProperty(SocialPackages::class, 'cfgCache');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
        SocialPackages::saveConfig(['tiers' => ['pro' => ['price_monthly' => 60]], 'renewal_policy' => 'grandfather'], null);

        $next = new ReflectionMethod(SocialPackages::class, 'nextTerm');
        $next->setAccessible(true);
        $row = ['tier' => 'pro', 'cycle' => 'monthly', 'pending_tier' => null, 'pending_cycle' => null, 'custom_json' => null, 'pending_custom_json' => null, 'price_paid' => 49.0, 'promo_code' => null];

        $this->assertSame(49.0, $next->invoke(null, $row)['price'], 'grandfather: يفضل على 49');
        $this->assertSame(60.0, $next->invoke(null, ['promo_code' => 'LAUNCH20', 'price_paid' => 39.2] + $row)['price'], 'الدورة اللي كانت بخصم تتجدد بالسعر الكامل');

        SocialPackages::saveConfig(['renewal_policy' => 'at_renewal'], null);
        $this->assertSame(60.0, $next->invoke(null, $row)['price'], 'at_renewal: السعر الجديد');

        $pdo->exec("DELETE FROM system_settings WHERE setting_key = '" . self::SETTING . "'");
        $ref->setValue(null, null);
    }
}

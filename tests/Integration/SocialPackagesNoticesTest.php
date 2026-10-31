<?php

use PHPUnit\Framework\TestCase;

/** تنبيهات الاستهلاك (80% ثم 100% مرة لكل مستوى في الشهر)، وإشعار المشتركين بتغيير السعر، وتكلفة الـAI الفعلية في تقرير الاستهلاك */
final class SocialPackagesNoticesTest extends TestCase
{
    private const U = 900501;
    private const SETTING = 'social_packages_config';
    private PDO $pdo;

    protected function setUp(): void
    {
        try {
            $this->pdo = Database::getInstance()->getConnection();
            $this->pdo->query('SELECT 1 FROM notifications LIMIT 1');
        } catch (Throwable $e) {
            $this->markTestSkipped('قاعدة الاختبار مش متاحة: ' . $e->getMessage());
        }
        SocialPackages::ensureSchema();
        $this->cleanup();
        $this->pdo->exec("INSERT INTO users (id, email, password, company_name, created_at) VALUES (" . self::U . ", 'notices-" . self::U . "@tourfecto.test', 'x', 'Notices', NOW()) ON DUPLICATE KEY UPDATE email = email");
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
    }

    private function cleanup(): void
    {
        $u = self::U;
        $this->pdo->exec("DELETE FROM notifications WHERE user_id = $u");
        $this->pdo->exec("DELETE FROM social_usage_alerts WHERE user_id = $u");
        $this->pdo->exec("DELETE FROM social_usage_monthly WHERE user_id = $u");
        $this->pdo->exec("DELETE FROM social_subscriptions WHERE user_id = $u");
        $this->pdo->exec("DELETE FROM api_usage_logs WHERE user_id = $u");
        $this->pdo->exec("DELETE FROM system_settings WHERE setting_key = '" . self::SETTING . "'");
        $this->pdo->exec("DELETE FROM users WHERE id = $u");
    }

    private function notices(string $like = '%'): array
    {
        $st = $this->pdo->prepare("SELECT title, body AS message FROM notifications WHERE user_id = ? AND type = 'social_package' AND title LIKE ? ORDER BY id");
        $st->execute([self::U, $like]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function usage(int $posts, int $ai = 0): void
    {
        $this->pdo->exec("INSERT INTO social_usage_monthly (user_id, month_key, posts, ai_captions) VALUES (" . self::U . ", '" . SocialPackages::monthKey() . "', $posts, $ai) ON DUPLICATE KEY UPDATE posts = $posts, ai_captions = $ai");
    }

    public function testUsageAlertsFireOncePerLevel(): void
    {
        $limit = SocialPackages::entitlements(self::U)['limits']['posts'];
        $this->assertGreaterThan(0, $limit);

        $this->usage((int) floor($limit * 0.5));
        $this->assertSame(0, SocialPackages::alertSweep());

        $this->usage((int) ceil($limit * 0.8));
        $this->assertSame(1, SocialPackages::alertSweep());
        $this->assertSame(0, SocialPackages::alertSweep(), 'نفس المستوى مايتبعتش تاني');
        $this->assertCount(1, $this->notices('%80%'));

        $this->usage($limit);
        $this->assertSame(1, SocialPackages::alertSweep());
        $this->assertCount(1, $this->notices('خلصت%'));
        $this->assertSame(0, SocialPackages::alertSweep());

        // رجوع للتنبيه ده بعد وصول 100% مايبعتش 80% متأخر
        $this->assertCount(2, $this->notices());
    }

    public function testNoAlertForUnlimitedOrZeroLimits(): void
    {
        $tier = SocialPackages::entitlements(self::U)['tier'];
        SocialPackages::saveConfig(['tiers' => [$tier => ['posts' => -1, 'ai_captions' => 0]]], null);
        $this->usage(5000, 5000);
        $this->assertSame(0, SocialPackages::alertSweep());
    }

    private function subscribe(string $cycle, float $paid): void
    {
        $this->pdo->exec("INSERT INTO social_subscriptions (user_id, tier, cycle, price_paid, status, auto_renew, period_start, period_end) VALUES (" . self::U . ", 'pro', '$cycle', $paid, 'active', 1, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 DAY))");
    }

    public function testSubscribersGetNoticeWhenTheirTierPriceRises(): void
    {
        $this->subscribe('monthly', 49);
        SocialPackages::saveConfig(['renewal_policy' => 'at_renewal', 'tiers' => ['pro' => ['price_monthly' => 59]]], null);
        $n = $this->notices('تغيير سعر%');
        $this->assertCount(1, $n);
        $this->assertStringContainsString('59', $n[0]['message']);
        $this->assertStringContainsString('49', $n[0]['message']);
    }

    public function testGrandfatheredSubscribersAreToldTheirPriceIsKept(): void
    {
        $this->subscribe('monthly', 49);
        SocialPackages::saveConfig(['renewal_policy' => 'grandfather', 'tiers' => ['pro' => ['price_monthly' => 59]]], null);
        $n = $this->notices('%سعرك ثابت%');
        $this->assertCount(1, $n);
    }

    public function testPriceDropAndOtherCycleAndUnchangedPrice(): void
    {
        $this->subscribe('yearly', 490);
        SocialPackages::saveConfig(['tiers' => ['pro' => ['price_monthly' => 39]]], null);
        $this->assertCount(0, $this->notices(), 'مشترك سنوي ماتأثرش بسعر الشهري');
        SocialPackages::saveConfig(['tiers' => ['pro' => ['price_yearly' => 390]]], null);
        $this->assertCount(1, $this->notices('سعر باقة السوشيال نزل'));
        SocialPackages::saveConfig(['tiers' => ['pro' => ['price_yearly' => 390]]], null);
        $this->assertCount(1, $this->notices(), 'حفظ من غير تغيير مايبعتش إشعار');
    }

    public function testUsageReportShowsActualAiCost(): void
    {
        $this->usage(10, 5);
        $this->pdo->exec("INSERT INTO api_usage_logs (user_id, api_type, feature, cost_in_usd) VALUES (" . self::U . ", 'gemini', 'social_content', 0.0123), (" . self::U . ", 'gemini', 'social_content', 0.0100), (" . self::U . ", 'gemini', 'other_task', 5)");
        $rep = SocialPackages::usageReport(50);
        $mine = array_values(array_filter($rep, static fn ($r) => $r['user_id'] === self::U));
        $this->assertCount(1, $mine);
        $this->assertEqualsWithDelta(0.0223, $mine[0]['ai_cost_actual'], 0.00001, 'بس مهمة social_content');
    }
}

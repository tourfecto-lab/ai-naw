<?php

/**
 * The scheduled AI-visibility measurement must never charge a customer's wallet on its own:
 * once the plan quota is gone it stops, unless the site has an explicit monthly wallet limit,
 * and that limit is respected within the month.
 */

use PHPUnit\Framework\TestCase;

final class AiVisibilityWalletGuardTest extends TestCase
{
    private const U = 999501;
    private const SITE = 999502;

    private ?PDO $pdo = null;
    private ?Database $db = null;
    private string $priceBackup = '';

    protected function setUp(): void
    {
        try {
            $this->db = Database::getInstance();
            $this->pdo = $this->db->getConnection();
            $this->pdo->query('SELECT 1 FROM wallet_transactions LIMIT 1');
            $this->pdo->query('SELECT 1 FROM seo_expert_sites LIMIT 1');
            $this->pdo->query('SELECT 1 FROM pay_per_use_pricing LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('tables not available');
        }
        $this->cleanup();
        $this->pdo->exec("INSERT INTO users (id, email, password, company_name, created_at) VALUES (" . self::U . ", 'geo-guard@tourfecto.test', 'x', 'G', NOW())");
        $this->pdo->exec("INSERT INTO pay_per_use_pricing (feature_key, label, price, currency, currency_symbol, is_active) VALUES ('ai_visibility', 'geo', 0.15, 'USD', '$', 1) ON DUPLICATE KEY UPDATE price = 0.15, currency = 'USD', is_active = 1");
    }

    protected function tearDown(): void
    {
        if ($this->pdo) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $this->pdo->exec('DELETE FROM wallet_transactions WHERE user_id = ' . self::U);
        $this->pdo->exec('DELETE FROM seo_expert_sites WHERE website_id = ' . self::SITE);
        $this->pdo->exec('DELETE FROM users WHERE id = ' . self::U);
    }

    private function fund(float $usd): void
    {
        $this->pdo->exec("INSERT INTO wallet_transactions (user_id, type, amount, currency, status, reference_note, approved_at) VALUES (" . self::U . ", 'deposit', $usd, 'USD', 'completed', 'phpunit', NOW())");
    }

    private function policy(array $p): void
    {
        $this->pdo->exec("INSERT INTO seo_expert_sites (website_id, user_id, brief_json, policy_json, status, budget_month, created_at, updated_at) VALUES (" . self::SITE . ", " . self::U . ", '{}', " . $this->pdo->quote(json_encode($p)) . ", 'active', '" . gmdate('Y-m') . "', NOW(), NOW())");
    }

    private function charge(): void
    {
        $m = new ReflectionMethod(AiVisibilityLedger::class, 'chargeQuestion');
        $m->setAccessible(true);
        $m->invoke(new AiVisibilityLedger($this->db), $this->pdo, ['user_id' => self::U, 'website_id' => self::SITE], 'openai');
    }

    private function balance(): float
    {
        return (new WalletService())->getBalance(self::U);
    }

    public function testFundedWalletIsNotChargedWithoutAnExplicitLimit(): void
    {
        $this->fund(50);
        try {
            $this->charge();
            $this->fail('must stop when the plan quota is gone');
        } catch (RuntimeException $e) {
            $this->assertSame('geo_plan_quota_reached', $e->getMessage());
        }
        $this->assertSame(50.0, $this->balance());
    }

    public function testExplicitLimitAllowsChargesUpToTheLimitThenStops(): void
    {
        $this->fund(50);
        $this->policy(['max_ai_wallet_monthly' => 0.30]);
        $this->charge();
        $this->charge();
        $this->assertEqualsWithDelta(49.70, $this->balance(), 0.001);
        try {
            $this->charge();
            $this->fail('the third question is above the 0.30 monthly limit');
        } catch (RuntimeException $e) {
            $this->assertSame('geo_plan_quota_reached', $e->getMessage());
        }
        $this->assertEqualsWithDelta(49.70, $this->balance(), 0.001);
    }

    public function testEmptyWalletWithLimitStopsInsteadOfGoingNegative(): void
    {
        $this->policy(['max_ai_wallet_monthly' => 5]);
        try {
            $this->charge();
            $this->fail('no funds');
        } catch (RuntimeException $e) {
            $this->assertSame('geo_quota_and_wallet_exhausted', $e->getMessage());
        }
        $this->assertSame(0.0, $this->balance());
    }
}

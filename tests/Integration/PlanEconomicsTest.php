<?php

/**
 * Plan economics are computed from real rows only: subscribers and monthly-equivalent revenue per plan,
 * DataForSEO + AI cost of those accounts (team members count for the owner), margin, low-margin flag,
 * and no invented margin when nobody subscribes.
 */

use PHPUnit\Framework\TestCase;

final class PlanEconomicsTest extends TestCase
{
    private const OWNER = 999301;
    private const MEMBER = 999302;
    private const SITE = 999303;

    private ?PDO $pdo = null;
    private ?Database $db = null;
    private int $planM = 0;
    private int $planY = 0;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../app/Services/Subscription/PlanEconomics.php';
        try {
            $this->db = Database::getInstance();
            $this->pdo = $this->db->getConnection();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB not available');
        }
        if (!$this->pdo->query("SHOW COLUMNS FROM subscription_plans LIKE 'websites_limit'")->fetch()) {
            $this->pdo->exec('ALTER TABLE subscription_plans ADD COLUMN websites_limit INT NOT NULL DEFAULT 0');
        }
        $this->cleanup();
        $this->pdo->exec("INSERT INTO users (id, email, password, company_name, owner_user_id, created_at) VALUES (" . self::OWNER . ", 'econ-owner@tourfecto.test', 'x', 'Owner', NULL, NOW()), (" . self::MEMBER . ", 'econ-member@tourfecto.test', 'x', 'Member', " . self::OWNER . ", NOW())");
        $this->pdo->exec("INSERT INTO subscription_plans (plan_code, name, billing_cycle, price, currency, features_json, is_active) VALUES ('zz-econ_monthly','Econ','monthly',100,'USD','{}',1),('zz-econ_yearly','Econ','yearly',1200,'USD','{}',1)");
        $this->planM = (int) $this->pdo->query("SELECT id FROM subscription_plans WHERE plan_code='zz-econ_monthly'")->fetchColumn();
        $this->planY = (int) $this->pdo->query("SELECT id FROM subscription_plans WHERE plan_code='zz-econ_yearly'")->fetchColumn();
    }

    protected function tearDown(): void
    {
        if ($this->pdo) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $this->pdo->exec('DELETE FROM api_usage_logs WHERE user_id IN (' . self::OWNER . ',' . self::MEMBER . ')');
        $this->pdo->exec('DELETE FROM dataforseo_calls WHERE website_id = ' . self::SITE);
        $this->pdo->exec('DELETE FROM websites WHERE id = ' . self::SITE);
        $this->pdo->exec('DELETE FROM subscriptions WHERE user_id IN (' . self::OWNER . ',' . self::MEMBER . ')');
        $this->pdo->exec("DELETE FROM subscription_plans WHERE plan_code LIKE 'zz-econ_%'");
        $this->pdo->exec('DELETE FROM users WHERE id IN (' . self::OWNER . ',' . self::MEMBER . ')');
    }

    private function subscribe(int $planId, string $type): void
    {
        $this->pdo->exec("INSERT INTO subscriptions (user_id, plan_name, plan_type, plan_id, status, price, currency, start_date, expiry_date) VALUES (" . self::OWNER . ", 'starter', '$type', $planId, 'active', 100, 'USD', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY))");
    }

    private function row(array $report): ?array
    {
        foreach ($report['plans'] as $p) {
            if ($p['plan_key'] === 'zz-econ') return $p;
        }
        return null;
    }

    public function testRevenueCostAndMarginComeFromRealRowsIncludingTeamMembers(): void
    {
        $this->subscribe($this->planY, 'yearly'); // 1200/12 = 100 شهريًا
        $this->pdo->exec("INSERT INTO websites (id, user_id, main_url, company_name) VALUES (" . self::SITE . ", " . self::MEMBER . ", 'https://econ.test', 'Econ')");
        $this->pdo->exec("INSERT INTO dataforseo_calls (endpoint, cost, reserved_cost, website_id, status, created_at) VALUES ('serp', 20, 20, " . self::SITE . ", 'done', UTC_TIMESTAMP())");
        $this->pdo->exec("INSERT INTO api_usage_logs (user_id, api_type, feature, cost_in_usd) VALUES (" . self::OWNER . ", 'gemini', 'x', 15)");
        $this->pdo->exec("INSERT INTO dataforseo_calls (endpoint, cost, reserved_cost, website_id, status, created_at) VALUES ('serp', 99, 99, " . self::SITE . ", 'done', DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 DAY))");

        $r = $this->row(PlanEconomics::report($this->db, 30));
        $this->assertNotNull($r);
        $this->assertSame(1, $r['subscribers']);
        $this->assertSame(100.0, $r['mrr_usd']);
        $this->assertSame(20.0, $r['dfs_cost_usd']); // الطلب القديم (60 يوم) مش محسوب
        $this->assertSame(15.0, $r['ai_cost_usd']);
        $this->assertSame(65.0, $r['margin_pct']);
        $this->assertFalse($r['low_margin']);
    }

    public function testLowMarginIsFlagged(): void
    {
        $this->subscribe($this->planM, 'monthly');
        $this->pdo->exec("INSERT INTO api_usage_logs (user_id, api_type, feature, cost_in_usd) VALUES (" . self::OWNER . ", 'gemini', 'x', 70)");
        $r = $this->row(PlanEconomics::report($this->db, 30));
        $this->assertSame(30.0, $r['margin_pct']);
        $this->assertTrue($r['low_margin']);
    }

    public function testInactiveSubscriptionsDoNotCountAndNoInventedMargin(): void
    {
        $this->pdo->exec("INSERT INTO subscriptions (user_id, plan_name, plan_type, plan_id, status, price, currency, start_date, expiry_date) VALUES (" . self::OWNER . ", 'starter', 'monthly', {$this->planM}, 'cancelled', 100, 'USD', NOW(), NOW())");
        $this->assertNull($this->row(PlanEconomics::report($this->db, 30)));
    }
}

<?php

/**
 * Admin-created plans: cloned from an existing plan, locked for sale, listed nowhere public until enabled,
 * validated keys, and deletable only when custom and without any subscription history.
 */

use PHPUnit\Framework\TestCase;

final class CustomPlanCreateDeleteTest extends TestCase
{
    private const USER = 999401;
    private const KEY = 'zz-custom';

    private ?PDO $pdo = null;
    private ?Database $db = null;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../app/Services/Subscription/BillingPlanCatalog.php';
        try {
            $this->db = Database::getInstance();
            $this->pdo = $this->db->getConnection();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB not available');
        }
        if (!$this->pdo->query("SELECT COUNT(*) FROM subscription_plans WHERE plan_code = 'seo-essential_monthly'")->fetchColumn()) {
            $this->markTestSkipped('SEO plan seed not present');
        }
        if (!$this->pdo->query("SHOW COLUMNS FROM subscription_plans LIKE 'websites_limit'")->fetch()) {
            $this->pdo->exec('ALTER TABLE subscription_plans ADD COLUMN websites_limit INT NOT NULL DEFAULT 0');
        }
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if ($this->pdo) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $this->pdo->exec('DELETE FROM subscriptions WHERE user_id = ' . self::USER);
        $this->pdo->exec("DELETE FROM subscription_plans WHERE plan_code LIKE 'zz-custom_%'");
        $this->pdo->exec("DELETE FROM plan_pricing_display WHERE plan_key = 'zz-custom'");
        $this->pdo->exec('DELETE FROM users WHERE id = ' . self::USER);
        try {
            $this->pdo->exec("DELETE FROM plan_change_audit WHERE plan_key = 'zz-custom'");
        } catch (\Throwable $e) {
        }
    }

    public function testCloneIsLockedForSaleKeepsLimitsAndIsNotPublic(): void
    {
        BillingPlanCatalog::createCustomPlan($this->db, self::KEY, 'باقة تجريبية', 'seo-growth', 1);
        $rows = $this->pdo->query("SELECT * FROM subscription_plans WHERE plan_code LIKE 'zz-custom_%' ORDER BY plan_code")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $rows);
        foreach ($rows as $r) {
            $this->assertSame(0, (int) $r['is_active']);
            $this->assertSame('باقة تجريبية', $r['name']);
            $f = json_decode($r['features_json'], true);
            $this->assertTrue($f['custom']);
            $this->assertSame(4, (int) $f['seo_cycles']);
        }
        $display = $this->pdo->query("SELECT is_active, name FROM plan_pricing_display WHERE plan_key = 'zz-custom'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(0, (int) $display['is_active']);
        $this->assertArrayNotHasKey(self::KEY, BillingPlanCatalog::read($this->db), 'a locked plan must not be sold or listed');
        $this->assertArrayHasKey(self::KEY, BillingPlanCatalog::fromRows($rows));
    }

    public function testInvalidOrDuplicateKeysAndMissingSourceAreRejected(): void
    {
        foreach ([['Bad Key', 'seo-growth'], ['x', 'seo-growth'], ['seo-growth', 'seo-growth'], [self::KEY, 'no-such-plan']] as [$key, $from]) {
            try {
                BillingPlanCatalog::createCustomPlan($this->db, $key, 'اسم', $from, null);
                $this->fail("should reject $key from $from");
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM subscription_plans WHERE plan_code LIKE 'zz-custom_%'")->fetchColumn());
        BillingPlanCatalog::createCustomPlan($this->db, self::KEY, 'أول', 'seo-growth', null);
        $this->expectException(InvalidArgumentException::class);
        BillingPlanCatalog::createCustomPlan($this->db, self::KEY, 'تاني', 'seo-growth', null);
    }

    public function testDeleteWorksOnlyForCustomPlansWithoutSubscriptions(): void
    {
        try {
            BillingPlanCatalog::deleteCustomPlan($this->db, 'seo-growth', null);
            $this->fail('built-in plans must not be deletable');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM subscription_plans WHERE plan_code LIKE 'seo-growth_%'")->fetchColumn());
        }

        BillingPlanCatalog::createCustomPlan($this->db, self::KEY, 'مؤقتة', 'seo-growth', null);
        $planId = (int) $this->pdo->query("SELECT id FROM subscription_plans WHERE plan_code = 'zz-custom_monthly'")->fetchColumn();
        $this->pdo->exec("INSERT INTO users (id, email, password, company_name, created_at) VALUES (" . self::USER . ", 'custom-plan@tourfecto.test', 'x', 'C', NOW())");
        $this->pdo->exec("INSERT INTO subscriptions (user_id, plan_name, plan_type, plan_id, status, price, currency, start_date, expiry_date) VALUES (" . self::USER . ", 'starter', 'monthly', $planId, 'expired', 10, 'USD', NOW(), NOW())");
        try {
            BillingPlanCatalog::deleteCustomPlan($this->db, self::KEY, null);
            $this->fail('a plan with subscription history must not be deleted');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM subscription_plans WHERE plan_code LIKE 'zz-custom_%'")->fetchColumn());
        }

        $this->pdo->exec('DELETE FROM subscriptions WHERE user_id = ' . self::USER);
        BillingPlanCatalog::deleteCustomPlan($this->db, self::KEY, null);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM subscription_plans WHERE plan_code LIKE 'zz-custom_%'")->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM plan_pricing_display WHERE plan_key = 'zz-custom'")->fetchColumn());
    }
}

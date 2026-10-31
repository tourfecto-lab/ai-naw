<?php

/**
 * Admin control of the SEO plan limits: validation ranges, the page count that the crawler really supports,
 * saving into both billing cycles, and the change log (before/after, only real differences).
 */

use PHPUnit\Framework\TestCase;

final class SeoPlanAdminControlsTest extends TestCase
{
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
        $has = $this->pdo->query("SELECT COUNT(*) FROM subscription_plans WHERE plan_code = 'seo-essential_monthly'")->fetchColumn();
        if (!$has) {
            $this->markTestSkipped('SEO plan seed not present');
        }
        // بعض سلاسل الـ migrations في قاعدة الاختبار مبتضيفش العمود ده (راجع MIGRATION_CHAIN_GAPS_AR.md)؛ الإنتاج عنده
        if (!$this->pdo->query("SHOW COLUMNS FROM subscription_plans LIKE 'websites_limit'")->fetch()) {
            $this->pdo->exec('ALTER TABLE subscription_plans ADD COLUMN websites_limit INT NOT NULL DEFAULT 0');
        }
        $this->cleanup();
        foreach (['monthly', 'yearly'] as $c) {
            $this->pdo->exec("INSERT INTO subscription_plans (plan_code, name, billing_cycle, price, currency, ai_analysis_limit, ai_message_limit, review_auto_reply_limit, websites_limit, features_json, is_active, sort_order)
                SELECT 'zz-seotest_$c', name, billing_cycle, price, currency, ai_analysis_limit, ai_message_limit, review_auto_reply_limit, websites_limit, features_json, is_active, sort_order
                FROM subscription_plans WHERE plan_code = 'seo-essential_$c'");
        }
        BillingPlanCatalog::ensureAuditTable($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->pdo) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM subscription_plans WHERE plan_code LIKE 'zz-seotest_%'");
        try {
            $this->pdo->exec("DELETE FROM plan_change_audit WHERE plan_key = 'zz-seotest'");
        } catch (\Throwable $e) {
        }
    }

    private function planArray(): array
    {
        $rows = $this->pdo->query("SELECT * FROM subscription_plans WHERE plan_code LIKE 'zz-seotest_%' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $p = BillingPlanCatalog::fromRows($rows)['zz-seotest'];
        return ['plan_key' => 'zz-seotest', 'name' => $p['name'], 'price_monthly' => $p['price_monthly'], 'price_yearly' => $p['price_yearly'], 'currency' => $p['currency'], 'is_active' => 1, 'ai_analysis' => 3, 'chat_credits' => 0, 'review_credits' => 0] + $p['features'];
    }

    public function testValidationRejectsOutOfRangeAndUnsupportedPageCounts(): void
    {
        $this->assertSame(3, BillingPlanCatalog::seoLimit('seo_cycles', '3'));
        $this->assertSame(300, BillingPlanCatalog::seoLimit('seo_max_pages', 300));
        foreach ([['seo_cycles', 0], ['seo_cycles', 5], ['seo_max_pages', 200], ['seo_max_pages', 80], ['seo_markets', 13], ['articles', -1], ['ai_visibility', '1.5'], ['seo_data', 'abc']] as [$f, $v]) {
            try {
                BillingPlanCatalog::seoLimit($f, $v);
                $this->fail("$f=$v should be rejected");
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function testSavesLimitsIntoBothCyclesAndKeepsThemEqual(): void
    {
        $plan = $this->planArray();
        $this->pdo->beginTransaction();
        BillingPlanCatalog::syncDisplay($this->db, $plan, ['seo_cycles' => 4, 'seo_max_pages' => 500, 'seo_markets' => 6, 'ai_visibility' => 777, 'seo_data' => 4321, 'articles' => 9]);
        $this->pdo->commit();
        $after = $this->planArray();
        $this->assertSame([4, 500, 6, 777, 4321, 9], [$after['seo_cycles'], $after['seo_max_pages'], $after['seo_markets'], $after['ai_visibility'], $after['seo_data'], $after['articles']]);
        // fromRows drops a plan whose two cycles differ, so reaching here proves both cycles were updated identically
        $this->assertCount(1, array_filter(array_keys(BillingPlanCatalog::fromRows($this->pdo->query("SELECT * FROM subscription_plans WHERE plan_code LIKE 'zz-seotest_%'")->fetchAll(PDO::FETCH_ASSOC))), fn ($k) => $k === 'zz-seotest'));
    }

    public function testInvalidLimitRollsBackAndChangesNothing(): void
    {
        $plan = $this->planArray();
        $before = $plan['seo_cycles'];
        $this->pdo->beginTransaction();
        try {
            BillingPlanCatalog::syncDisplay($this->db, $plan, ['seo_cycles' => 4, 'seo_max_pages' => 250]);
            $this->fail('expected rejection');
        } catch (InvalidArgumentException $e) {
            $this->pdo->rollBack();
        }
        $this->assertSame($before, $this->planArray()['seo_cycles']);
    }

    public function testAuditRecordsOnlyRealDifferences(): void
    {
        $diff = BillingPlanCatalog::recordChange($this->db, 'zz-seotest', null, ['price_monthly' => 99, 'seo_cycles' => 2, 'is_active' => true], ['price_monthly' => '99.00', 'seo_cycles' => 4, 'is_active' => 1, 'articles' => 5]);
        $this->assertSame(['seo_cycles' => ['from' => 2, 'to' => 4], 'articles' => ['from' => null, 'to' => 5]], $diff);
        $this->assertSame([], BillingPlanCatalog::recordChange($this->db, 'zz-seotest', null, ['seo_cycles' => 4], ['seo_cycles' => 4]));
        $rows = array_values(array_filter(BillingPlanCatalog::recentChanges($this->db, 50), fn ($r) => $r['plan_key'] === 'zz-seotest'));
        $this->assertCount(1, $rows);
        $this->assertSame($diff, $rows[0]['changes']);
    }
}

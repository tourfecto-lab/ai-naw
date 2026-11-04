<?php

/**
 * Changing only the price of a plan (what the "price vs competitors" apply button sends) must work:
 * the untouched feature flags are booleans from the billing rows and MySQL strict mode rejects boolean false
 * for tinyint columns, which made the first live attempt fail.
 */

use PHPUnit\Framework\TestCase;

final class AdminPlanPartialUpdateTest extends TestCase
{
    private const KEY = 'zz-partial';

    private ?PDO $pdo = null;
    private ?Database $db = null;
    private string $oldMode = '';

    protected function setUp(): void
    {
        if (!defined('APP_PATH')) {
            define('APP_PATH', dirname(__DIR__, 2) . '/app');
        }
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
        $this->oldMode = (string) $this->pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $this->pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
        $this->cleanup();
        BillingPlanCatalog::createCustomPlan($this->db, self::KEY, 'اختبار جزئي', 'seo-essential', null);
    }

    protected function tearDown(): void
    {
        if ($this->pdo) {
            $this->cleanup();
            $this->pdo->exec('SET SESSION sql_mode = ' . $this->pdo->quote($this->oldMode));
        }
    }

    private function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM subscription_plans WHERE plan_code LIKE 'zz-partial_%'");
        $this->pdo->exec("DELETE FROM plan_pricing_display WHERE plan_key = 'zz-partial'");
        try {
            $this->pdo->exec("DELETE FROM plan_change_audit WHERE plan_key = 'zz-partial'");
        } catch (\Throwable $e) {
        }
    }

    private function controller(array $data): AdminController
    {
        $c = new AdminController();
        foreach (['authenticated' => true, 'user' => ['id' => 1, 'role' => 'admin'], 'data' => $data] as $prop => $value) {
            $r = new ReflectionProperty(Controller::class, $prop);
            $r->setAccessible(true);
            $r->setValue($c, $value);
        }
        return $c;
    }

    public function testPriceOnlyUpdateSavesBothCyclesAndLogsTheChange(): void
    {
        $id = (int) $this->pdo->query("SELECT id FROM plan_pricing_display WHERE plan_key = 'zz-partial'")->fetchColumn();
        $this->assertGreaterThan(0, $id);
        $res = $this->controller(['price_monthly' => 77, 'price_yearly' => 770])->updatePlan(['id' => $id]);
        $this->assertTrue($res['success'] ?? false, json_encode($res, JSON_UNESCAPED_UNICODE));
        $rows = $this->pdo->query("SELECT plan_code, price FROM subscription_plans WHERE plan_code LIKE 'zz-partial_%' ORDER BY plan_code")->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertEquals(['zz-partial_monthly' => 77, 'zz-partial_yearly' => 770], $rows);
        $log = $this->pdo->query("SELECT changes_json FROM plan_change_audit WHERE plan_key = 'zz-partial' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $diff = json_decode((string) $log, true);
        // the custom plan copies the price of its base plan (seo-essential), which migrations may change
        $this->assertEquals(['from' => (float) $diff['price_monthly']['from'], 'to' => 77], $diff['price_monthly']);
        $this->assertGreaterThan(0, (float) $diff['price_monthly']['from'], 'the old price is recorded');
        $this->assertArrayNotHasKey('seo_cycles', $diff, 'untouched limits must not appear as changes');
    }
}

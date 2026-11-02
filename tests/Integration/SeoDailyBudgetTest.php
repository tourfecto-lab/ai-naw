<?php

/** سقف يومي اختياري على صرف DataForSEO: بيمنع يوم واحد ياكل ميزانية الشهر (مثال حقيقي: 4.76$ في يومين من فحص الترتيب). */

use PHPUnit\Framework\TestCase;

final class SeoDailyBudgetTest extends TestCase
{
    private ?Database $db = null;
    private string $before = '';

    protected function setUp(): void
    {
        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) foreach (['app.php', 'database.php', 'encryption.php'] as $cfg) if (file_exists($app . '/Config/' . $cfg)) require_once $app . '/Config/' . $cfg;
            if (!class_exists('Database')) require_once $app . '/Core/Database.php';
            $this->db = Database::getInstance();
            SeoDataSchema::ensureSchema($this->db);
            $this->before = (string) (new SystemSettingsService())->get('serp_daily_budget_usd', '');
        } catch (Throwable $e) {
            $this->markTestSkipped('DB غير متاحة: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            $this->db->query('DELETE FROM dataforseo_calls WHERE website_id = 999902');
            (new SystemSettingsService())->set('serp_daily_budget_usd', $this->before);
        }
    }

    private function check(float $ceiling): ?string
    {
        $m = new ReflectionMethod(SeoDataLedger::class, 'assertDailyBudget');
        $m->setAccessible(true);
        try {
            $m->invoke(new SeoDataLedger($this->db), $this->db->getConnection(), $ceiling);
            return null;
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    }

    public function testDailyCapStopsCallsOnlyWhenSet(): void
    {
        $this->db->query("INSERT INTO dataforseo_calls (endpoint, cost, reserved_cost, website_id, status, created_at) VALUES ('serp/google/organic/live/advanced', 0.5, 0, 999902, 'complete', UTC_TIMESTAMP())");
        $today = (float) $this->db->query('SELECT COALESCE(SUM(GREATEST(cost,reserved_cost)),0) AS s FROM dataforseo_calls WHERE created_at >= UTC_DATE()')[0]['s'];
        $settings = new SystemSettingsService();

        $settings->set('serp_daily_budget_usd', '');
        $this->assertNull($this->check(0.10), 'no daily cap configured');
        $settings->set('serp_daily_budget_usd', '0');
        $this->assertNull($this->check(0.10), 'zero means no daily cap');
        $settings->set('serp_daily_budget_usd', (string) ($today + 0.05));
        $this->assertSame('seo_daily_budget_reached', $this->check(0.10));
        $settings->set('serp_daily_budget_usd', (string) ($today + 1));
        $this->assertNull($this->check(0.10));
    }
}

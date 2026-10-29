<?php

/**
 * TOURFECTO AI CEO (المرحلة 2): ميزانية AI CEO المنفصلة والتقرير من api_usage_logs
 *   - إنفاق AI CEO بيتحسب من مهامه بس (ai_ceo%)، مش من مهام العملاء.
 *   - لما يوصل الميزانية اليومية بيتوقف (aiCeoBlocked)، والميزانيات بتتحفظ وبتترفض القيم الغلط.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AiCeo/AiCostIntelligence.php';
require_once __DIR__ . '/../../app/Services/Brain/BrainSchema.php';

final class AiCostIntelligenceIntegrationTest extends TestCase
{
    private ?Database $db = null;

    protected function setUp(): void
    {
        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) foreach (['app.php', 'database.php', 'encryption.php'] as $cfg) if (file_exists($app . '/Config/' . $cfg)) require_once $app . '/Config/' . $cfg;
            if (!class_exists('Database')) require_once $app . '/Core/Database.php';
            $this->db = Database::getInstance();
            BrainSchema::ensure($this->db);
        } catch (Throwable $e) {
            $this->markTestSkipped('DB غير متاحة: ' . $e->getMessage());
        }
        $this->clean();
    }

    protected function tearDown(): void
    {
        if ($this->db) $this->clean();
    }

    private function clean(): void
    {
        $this->db->query("DELETE FROM api_usage_logs WHERE endpoint = 'phpunit-aiceo'");
        $this->db->query("DELETE FROM api_usage_logs WHERE feature LIKE 'ai_ceo%'");
        $this->db->query("DELETE FROM system_settings WHERE setting_key IN ('ai_platform_budget_usd_month', 'aiceo_ai_budget_usd_day', 'aiceo_ai_budget_usd_month')");
    }

    private function log(string $feature, float $cost): void
    {
        $this->db->query("INSERT INTO api_usage_logs (user_id, api_type, feature, model, endpoint, status_code, tokens_used, cost_in_usd, duration_ms) VALUES (NULL, 'gemini', ?, 'test-model', 'phpunit-aiceo', 200, 100, ?, 50)", [$feature, $cost]);
    }

    public function testAiCeoBudgetIsSeparateFromCustomersAndStopsAtTheLimit(): void
    {
        $c = new AiCostIntelligence($this->db);
        $this->log('seo_article', 5.0);           // عميل: مايتحسبش على AI CEO
        $this->log('ai_ceo_chat', 0.4);
        $this->assertSame(0.4, $c->aiCeoSpend()['day']);
        $this->assertNull($c->aiCeoBlocked());     // الافتراضي 1 دولار في اليوم
        $this->log('ai_ceo_chat', 0.7);
        $this->assertSame('daily', $c->aiCeoBlocked());

        $c->saveBudgets(['aiceo_ai_budget_usd_day' => '5', 'ai_platform_budget_usd_month' => '100']);
        $this->assertNull($c->aiCeoBlocked());
        $this->assertSame(100.0, $c->budgets()['ai_platform_budget_usd_month']);

        $r = $c->report();
        $this->assertSame(true, $r['totals']['today'] >= 6.1);
        $this->assertSame(100.0, $r['platform_budget']['limit']);
        $this->assertContains('ai_ceo_chat', array_column($r['features'], 'feature'));
    }

    public function testInvalidBudgetIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AiCostIntelligence($this->db))->saveBudgets(['aiceo_ai_budget_usd_day' => '-3']);
    }
}

<?php

/**
 * TOURFECTO AI CEO (المرحلة 8): المصاريف الثابتة بتدخل في الأرباح، الإيراد من خصومات الاشتراك الفعلية، الأسواق حسب الدولة،
 * الأهداف ومهامها الأسبوعية، والـ Boardroom بذكاء اصطناعي وهمي (وبيرفض الرد اللي مش JSON).
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AiCeo/FinanceStrategy.php';

final class FinanceStrategyIntegrationTest extends TestCase
{
    private const USER = 999890;
    private ?Database $db = null;

    protected function setUp(): void
    {
        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) foreach (['app.php', 'database.php', 'encryption.php'] as $cfg) if (file_exists($app . '/Config/' . $cfg)) require_once $app . '/Config/' . $cfg;
            if (!class_exists('Database')) require_once $app . '/Core/Database.php';
            if (!class_exists('SaasMetricsService')) require_once $app . '/Services/Admin/SaasMetricsService.php';
            $this->db = Database::getInstance();
            new FinanceStrategy($this->db);
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
        $this->db->query('DELETE FROM aiceo_cost_items');
        $this->db->query('DELETE FROM aiceo_goal_tasks');
        $this->db->query('DELETE FROM aiceo_goals');
        $this->db->query('DELETE FROM wallet_transactions WHERE user_id = ?', [self::USER]);
        $this->db->query('DELETE FROM api_usage_logs WHERE user_id = ?', [self::USER]);
        $this->db->query('DELETE FROM subscriptions WHERE user_id = ?', [self::USER]);
        $this->db->query('DELETE FROM users WHERE id = ?', [self::USER]);
        $this->db->query("DELETE FROM system_settings WHERE setting_key = 'fin_capacity_customers'");
    }

    public function testPnlMarketsAndCosts(): void
    {
        $f = new FinanceStrategy($this->db);
        $before = $f->pnl(1)['months'][0];
        $this->db->query("INSERT INTO users (id, email, password, company_name, role, country, created_at) VALUES (?, 'fin@phpunit.test', 'x', 'Fin Co', 'user', 'ZZ', NOW())", [self::USER]);
        $this->db->query("INSERT INTO wallet_transactions (user_id, type, amount, status, created_at) VALUES (?, 'deposit', 100, 'completed', NOW()), (?, 'subscription_charge', -49, 'completed', NOW()), (?, 'subscription_charge', -10, 'pending', NOW())", [self::USER, self::USER, self::USER]);
        $this->db->query("INSERT INTO api_usage_logs (user_id, api_type, cost_in_usd, created_at) VALUES (?, 'gemini', 1.5, NOW())", [self::USER]);
        $this->db->query("INSERT INTO subscriptions (user_id, plan_name, plan_type, status, price, currency, start_date, created_at, payment_gateway) VALUES (?, 'starter', 'monthly', 'active', 49, 'USD', NOW(), NOW(), 'wallet')", [self::USER]);
        $f->saveCostItem(['name' => 'Server', 'category' => 'infrastructure', 'monthly_usd' => 40]);
        $id = $f->saveCostItem(['name' => 'Old tool', 'category' => 'tools', 'monthly_usd' => 60]);
        $f->saveCostItem(['id' => $id, 'name' => 'Old tool', 'category' => 'tools', 'monthly_usd' => 60, 'active' => false]);
        try { $f->saveCostItem(['name' => '', 'monthly_usd' => 5]); $this->fail('empty name'); } catch (InvalidArgumentException $e) { $this->assertSame(1, 1); }

        $p = $f->pnl(1);
        $m = $p['months'][0];
        $this->assertSame(40.0, $p['fixed_monthly'], 'inactive costs are not counted');
        $this->assertSame(round($before['revenue'] + 49, 2), $m['revenue'], 'only completed subscription charges');
        $this->assertSame(round($before['cash_in'] + 100, 2), $m['cash_in']);
        $this->assertSame(round($before['ai_cost'] + 1.5, 2), $m['ai_cost']);
        $this->assertSame(round($m['revenue'] - $m['costs'], 2), $m['profit']);

        $zz = array_values(array_filter($f->markets(), static fn ($x) => $x['country'] === 'ZZ'))[0];
        $this->assertSame(1, $zz['paying']);
        $this->assertSame(49.0, $zz['mrr']);
        $this->assertSame(47.5, $zz['margin']);

        $b = $f->baseline();
        $this->assertSame(40.0, $b['fixed_monthly']);
        $f->saveCapacity('500');
        $this->assertSame(500.0, $f->baseline()['capacity_customers']);
        try { $f->saveCapacity('-3'); $this->fail('negative'); } catch (InvalidArgumentException $e) { $this->assertSame(1, 1); }
    }

    public function testGoalsTasksAndBoardroom(): void
    {
        $f = new FinanceStrategy($this->db);
        $gid = $f->addGoal(['metric' => 'mrr', 'target' => 1000], ['mrr' => 400.0], 1);
        try { $f->addGoal(['metric' => 'vanity', 'target' => 1], [], 1); $this->fail('bad metric'); } catch (InvalidArgumentException $e) { $this->assertSame(1, 1); }
        $t1 = $f->addTask($gid, 'Call 10 trial users');
        $f->addTask($gid, 'Publish 3 case studies');
        $f->setTaskStatus($t1, 'done');
        $g = $f->goals(['mrr' => 700.0]);
        $item = $g['items'][0];
        $this->assertSame(400.0, $item['baseline']);
        $this->assertSame(50.0, $item['progress']);
        $this->assertSame(1, $item['week_done']);
        $this->assertSame(2, $item['week_total']);
        $f->setGoalStatus($gid, 'achieved');
        try { $f->addTask(987654, 'x'); $this->fail('unknown goal'); } catch (InvalidArgumentException $e) { $this->assertSame(1, 1); }

        $prompt = '';
        $board = new FinanceStrategy($this->db, function (string $p) use (&$prompt) { $prompt = $p;
            return json_encode(['summary' => 'Focus on GCC', 'perspectives' => ['cfo' => 'x', 'cmo' => 'y', 'cto' => 'z', 'coo' => 'w'], 'scenarios' => ['conservative' => ['cost' => '$0'], 'balanced' => ['cost' => '$500'], 'aggressive' => ['cost' => '$3000']], 'data_gaps' => ['CAC']]); });
        $out = $board->boardroom(1, 'Should we focus on the Gulf next quarter?', ['mrr' => 573.17]);
        $this->assertSame('Focus on GCC', $out['answer']['summary']);
        $this->assertSame('Boardroom: Should we focus on the Gulf next quarter?', $out['title']);
        $this->assertStringContainsString('573.17', $prompt);
        try { $board->boardroom(1, 'short', []); $this->fail('too short'); } catch (InvalidArgumentException $e) { $this->assertSame(1, 1); }
        $bad = new FinanceStrategy($this->db, fn () => 'no json here');
        try { $bad->boardroom(1, 'Should we raise prices by 10%?', []); $this->fail('invalid'); } catch (RuntimeException $e) { $this->assertSame('invalid_plan', $e->getMessage()); }
    }
}

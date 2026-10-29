<?php

/** TOURFECTO AI CEO (المرحلة 8): السيناريوهات، التوأم الرقمي، تقدم الأهداف، والربع */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AiCeo/FinanceStrategy.php';

final class FinanceStrategyTest extends TestCase
{
    private function base(): array
    {
        return ['paying' => 10, 'active_customers' => 20, 'mrr' => 500.0, 'arpu' => 50.0, 'churn_pct' => 5.0, 'ai_cost_per_customer' => 2.0, 'data_cost_per_customer' => 0.5, 'fixed_monthly' => 100.0,
            'signups_month' => 40, 'conversion_pct' => 10.0, 'capacity_customers' => 30.0, 'support_minutes_per_customer' => null, 'support_cost_hour' => null];
    }

    public function testScenariosAreOrderedAndExplained(): void
    {
        $r = FinanceStrategy::scenarios(['one_time_cost' => 1000, 'monthly_cost' => 200, 'customers_per_month' => 4, 'arpu' => 50, 'churn_pct' => 5, 'months' => 12]);
        $c = $r['cases'];
        $this->assertSame(['conservative', 'balanced', 'aggressive'], array_keys($c));
        $this->assertSame(true, $c['conservative']['revenue'] < $c['balanced']['revenue'] && $c['balanced']['revenue'] < $c['aggressive']['revenue']);
        $this->assertSame(true, $c['conservative']['cost'] > $c['balanced']['cost'], 'conservative assumes cost overrun');
        $this->assertSame(1000.0 + 200 * 12, $c['balanced']['cost']);
        foreach ($c as $x) { $this->assertSame(true, count($x['assumptions']) >= 3 && count($x['risks']) >= 1 && $x['resources'] !== ''); }
        $this->assertSame(null, FinanceStrategy::scenarios(['customers_per_month' => 0, 'arpu' => 0])['cases']['balanced']['payback_month']);
    }

    public function testDigitalTwin(): void
    {
        $b = $this->base();
        $same = FinanceStrategy::simulate($b, []);
        $this->assertSame(500.0, $same['mrr']);
        // 20 × 2.5 متغير + 100 ثابت = 150
        $this->assertSame(150.0, $same['costs']);
        $this->assertSame(0.0, $same['profit_change']);
        $double = FinanceStrategy::simulate($b, ['customers_mult' => 2]);
        $this->assertSame(1000.0, $double['mrr']);
        $this->assertSame(133.3, $double['capacity_pct']);
        $this->assertSame(true, str_contains(implode(' ', $double['resources']), 'Server'));
        $api = FinanceStrategy::simulate($b, ['api_cost_change_pct' => 100]);
        $this->assertSame(-40.0, $api['profit_change']);
        $lose = FinanceStrategy::simulate($b, ['lose_customers_pct' => 10]);
        $this->assertSame(450.0, $lose['mrr']);
        $x5 = FinanceStrategy::simulate($b, ['signups_mult' => 5]);
        // 160 تسجيل زيادة × 10% = 16 عميل بيدفع زيادة
        $this->assertSame(26.0, $x5['paying']);
        $this->assertSame(true, $x5['estimate']);
        $this->assertSame(5, count(FinanceStrategy::presets()));
    }

    public function testGoalProgress(): void
    {
        $this->assertSame(50.0, FinanceStrategy::progress(100, 300, 200, 'mrr'));
        $this->assertSame(100.0, FinanceStrategy::progress(100, 300, 400, 'mrr'));
        $this->assertSame(0.0, FinanceStrategy::progress(100, 300, 50, 'mrr'));
        $this->assertSame(50.0, FinanceStrategy::progress(10, 4, 7, 'logo_churn'), 'lower is better');
        $this->assertSame(null, FinanceStrategy::progress(10, 4, null, 'logo_churn'));
        $this->assertSame('2026-Q4', FinanceStrategy::quarter(strtotime('2026-10-29')));
        $this->assertSame('2027-Q1', FinanceStrategy::quarter(strtotime('2027-03-31')));
    }

    public function testMetricValuesFromSnapshot(): void
    {
        $v = FinanceStrategy::metricValues(['revenue' => ['mrr' => ['now' => 573.17], 'paying' => ['now' => 3], 'logo_churn' => ['now' => 10.0], 'trial_conversion' => 25.0],
            'brand' => ['signups_30d' => 12, 'audit_score' => 71], 'ops' => ['incidents_open' => ['high' => 2, 'low' => 1]], 'success' => ['support_unanswered' => 4]]);
        $this->assertSame(573.17, $v['mrr']);
        $this->assertSame(3, $v['incidents_open']);
        $this->assertSame(4, $v['support_unanswered']);
        $this->assertSame(null, FinanceStrategy::metricValues([])['mrr']);
    }
}

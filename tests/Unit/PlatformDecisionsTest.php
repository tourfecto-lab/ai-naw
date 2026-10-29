<?php

/** TOURFECTO AI CEO: قرارات المنصة من الأرقام، ومستوى الخطر، ومعادلة الأولوية */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AiCeo/PlatformDecisions.php';

final class PlatformDecisionsTest extends TestCase
{
    public static function snapshot(array $over = []): array
    {
        return array_replace([
            'revenue' => ['mrr' => ['now' => 900, 'prev' => 1000], 'paying' => ['now' => 9, 'prev' => 10], 'logo_churn' => ['now' => 10.0, 'prev' => 5.0], 'revenue_churn' => ['now' => 8, 'prev' => 4], 'trial_conversion' => 20.0, 'cash_30d' => ['now' => 800, 'prev' => 900]],
            'signups' => ['today' => 0, 'last_7d' => 2, 'prev_7d' => 10],
            'trials' => ['active' => 4, 'ending_24h' => 2, 'ended_unconverted' => 3, 'signed_up_no_trial' => 1],
            'customers' => ['healthy' => 5, 'watch' => 2, 'at_risk' => 2, 'paying_at_risk' => 1, 'mrr_at_risk' => 49],
            'platform' => ['overall' => 'fail', 'counts' => ['ok' => 10, 'warn' => 1, 'fail' => 1], 'problems' => [
                ['section' => 'cron', 'key' => 'cron_jobs', 'status' => 'fail', 'label' => 'Cron', 'message' => '1 job failing'],
                ['section' => 'mail', 'key' => 'mail', 'status' => 'warn', 'label' => 'Mail', 'message' => 'bounces high']]],
            'errors' => ['errors_24h' => 250, 'errors_last_hour' => 3, 'top' => [['message' => 'DB timeout', 'count' => 40, 'last_seen' => '2026-10-29']]],
            'ai_cost' => ['today_usd' => 9.5, 'avg_day_7d_usd' => 2.0, 'month_usd' => 80, 'requests_today' => 100, 'failed_today' => 30],
            'outreach' => ['total' => 120, 'awaiting_approval' => 15, 'pending_sends' => 0, 'sent' => 50, 'opened' => 10, 'clicked' => 2, 'paused_reason' => '', 'countries' => []],
            'generated_at' => '2026-10-29 12:00:00',
        ], $over);
    }

    public function testPriorityFormulaStaysBetween10And50(): void
    {
        $this->assertSame(10.0, PlatformDecisions::priority(['impact' => 1, 'roi' => 1, 'urgency' => 1, 'confidence' => 1, 'risk' => 5, 'cost' => 5, 'effort' => 5]));
        $this->assertSame(50.0, PlatformDecisions::priority(['impact' => 5, 'roi' => 5, 'urgency' => 5, 'confidence' => 5, 'risk' => 1, 'cost' => 1, 'effort' => 1]));
    }

    public function testDecisionsComeFromTheNumbersWithTheRightRisk(): void
    {
        $by = [];
        foreach (PlatformDecisions::decide(self::snapshot()) as $d) $by[$d['key']] = $d;
        foreach (['platform_failures', 'platform_warnings', 'error_spike', 'mrr_down', 'churn_up', 'save_at_risk_customers', 'trials_ending', 'trials_unconverted', 'signups_down', 'ai_cost_spike', 'ai_failures', 'outreach_awaiting_approval'] as $k) {
            $this->assertArrayHasKey($k, $by, $k);
        }
        // شغل داخلي = خطر قليل وينفع تلقائي؛ أي تواصل بره المنصة = موافقة
        $this->assertSame('low', $by['platform_failures']['risk']);
        $this->assertSame(true, $by['platform_failures']['auto_ok']);
        foreach (['churn_up', 'save_at_risk_customers', 'trials_ending', 'trials_unconverted', 'outreach_awaiting_approval'] as $k) {
            $this->assertSame('medium', $by[$k]['risk'], $k);
            $this->assertSame(true, $by[$k]['requires_approval'], $k);
            $this->assertSame(false, $by[$k]['auto_ok'], $k);
        }
        $this->assertSame(['legal_basis'], $by['outreach_awaiting_approval']['dependencies']);
        $this->assertStringContainsString('$900', $by['mrr_down']['evidence'][0]['en']);
    }

    public function testSortedByPriority(): void
    {
        $list = PlatformDecisions::decide(self::snapshot());
        for ($i = 1; $i < count($list); $i++) $this->assertSame(true, $list[$i - 1]['priority'] >= $list[$i]['priority']);
    }

    public function testHealthyCompanyHasNoDecisions(): void
    {
        $healthy = self::snapshot([
            'revenue' => ['mrr' => ['now' => 1000, 'prev' => 900], 'paying' => ['now' => 10, 'prev' => 9], 'logo_churn' => ['now' => 0, 'prev' => 2], 'revenue_churn' => ['now' => 0, 'prev' => 0], 'trial_conversion' => 30, 'cash_30d' => ['now' => 1, 'prev' => 1]],
            'signups' => ['today' => 1, 'last_7d' => 8, 'prev_7d' => 8],
            'trials' => ['active' => 3, 'ending_24h' => 0, 'ended_unconverted' => 0, 'signed_up_no_trial' => 0],
            'customers' => ['healthy' => 10, 'watch' => 0, 'at_risk' => 0, 'paying_at_risk' => 0, 'mrr_at_risk' => 0],
            'platform' => ['overall' => 'ok', 'counts' => ['ok' => 12], 'problems' => []],
            'errors' => ['errors_24h' => 3, 'errors_last_hour' => 0, 'top' => []],
            'ai_cost' => ['today_usd' => 1.0, 'avg_day_7d_usd' => 1.0, 'month_usd' => 20, 'requests_today' => 50, 'failed_today' => 1],
            'outreach' => ['total' => 0, 'awaiting_approval' => 0, 'pending_sends' => 0, 'sent' => 0, 'opened' => 0, 'clicked' => 0, 'paused_reason' => '', 'countries' => []],
        ]);
        $this->assertSame([], PlatformDecisions::decide($healthy));
    }

    public function testMissingSourcesAreReportedNotGuessed(): void
    {
        $list = PlatformDecisions::decide(self::snapshot(['revenue' => null, 'customers' => null]));
        $keys = array_column($list, 'key');
        $this->assertContains('data_sources_missing', $keys);
        $this->assertSame(false, in_array('mrr_down', $keys, true));
        $this->assertSame(false, in_array('save_at_risk_customers', $keys, true));
    }

    public function testCostDecisionsFromPhaseTwo(): void
    {
        $snap = self::snapshot();
        $snap['ai_cost'] += ['platform_budget' => ['limit' => 50, 'used_pct' => 60, 'projected_pct' => 120],
            'anomalies' => [['type' => 'feature', 'name' => 'seo_article', 'today' => 6, 'avg_day' => 1]],
            'routing_review' => [['feature' => 'seo_article', 'expensive_model' => 'big', 'expensive_cpr' => 0.05, 'expensive_requests' => 20, 'cheap_model' => 'small', 'cheap_cpr' => 0.005]],
            'aiceo' => ['day' => 1.2, 'month' => 3, 'blocked' => 'daily']];
        $by = [];
        foreach (PlatformDecisions::decide($snap) as $d) $by[$d['key']] = $d;
        $this->assertSame('low', $by['ai_budget_projection']['risk']);
        $this->assertSame('low', $by['ai_usage_anomaly']['risk']);
        // تغيير النموذج بيأثر على جودة خدمة العملاء: موافقة
        $this->assertSame('medium', $by['ai_routing_review']['risk']);
        $this->assertSame(true, $by['ai_routing_review']['requires_approval']);
        $this->assertArrayHasKey('aiceo_budget_reached', $by);
    }
}

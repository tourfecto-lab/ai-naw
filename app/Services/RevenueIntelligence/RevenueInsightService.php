<?php

/**
 * Tourfecto - Revenue Insight Service (Opportunities + Risks)
 * @version 1.0.0
 *
 * يغطي:
 *  - Section 3: REVENUE OPPORTUNITIES
 *  - Section 4: REVENUE RISK DETECTION
 *  - Section 15: كل Insight بالشكل الموحّد (Finding/Evidence/Reasoning/Confidence/Recommended Action)
 *
 * كل الاستنتاجات هنا مبنية على بيانات حقيقية من RevenueOverviewService/
 * CustomerRevenueService/PipelineRevenueService/RevenueAnomalyService.
 * لا يُنشأ Insight بدون evidence حقيقي - لو القسم يحتاج بيانات غير
 * متوفرة (مثال: Cross-sell أو أداء منتج/خدمة - section 8 غير متاح) يتم
 * تخطّيه بدل اختراعه.
 */
class RevenueInsightService
{
    private RevenueOverviewService $overview;
    private CustomerRevenueService $customerService;
    private PipelineRevenueService $pipelineService;
    private RevenueOverviewService $overviewSource; // بالاسم منفصل لوضوح استخدام revenue_by_source

    public function __construct(
        ?RevenueOverviewService $overview = null,
        ?CustomerRevenueService $customerService = null,
        ?PipelineRevenueService $pipelineService = null
    ) {
        $this->overview = $overview ?? new RevenueOverviewService();
        $this->overviewSource = $this->overview;
        $this->customerService = $customerService ?? new CustomerRevenueService();
        $this->pipelineService = $pipelineService ?? new PipelineRevenueService();
    }

    /** Section 3: REVENUE OPPORTUNITIES */
    public function getOpportunities(int $userId): array
    {
        $opportunities = [];

        $customerIntel = $this->customerService->getCustomerRevenueIntelligence($userId);
        if ($customerIntel['has_data']) {
            $opportunities = array_merge($opportunities, self::opportunitiesFromCustomers($customerIntel['customers']));
        }

        $sourceGrowth = $this->overviewSource->getRevenueBySourceWithGrowth($userId, 'monthly');
        if ($sourceGrowth['has_data']) {
            $opportunities = array_merge($opportunities, self::opportunitiesFromSources($sourceGrowth['sources']));
        }

        return $opportunities;
    }

    /** Section 4: REVENUE RISK DETECTION */
    public function getRisks(int $userId): array
    {
        $risks = [];

        $overview = $this->overview->getOverview($userId, 'monthly');
        if ($overview['has_data']) {
            $overviewRisk = self::riskFromOverview($overview);
            if ($overviewRisk !== null) {
                $risks[] = $overviewRisk;
            }
        }

        $customerIntel = $this->customerService->getCustomerRevenueIntelligence($userId);
        if ($customerIntel['has_data']) {
            $risks = array_merge($risks, self::risksFromCustomers($customerIntel['customers']));
        }

        $sourceGrowth = $this->overviewSource->getRevenueBySourceWithGrowth($userId, 'monthly');
        if ($sourceGrowth['has_data']) {
            $risks = array_merge($risks, self::risksFromSources($sourceGrowth['sources']));
        }

        $pipeline = $this->pipelineService->getPipelineIntelligence($userId);
        if ($pipeline['has_data']) {
            $pipelineRisk = self::riskFromPipeline($pipeline['pipeline']);
            if ($pipelineRisk !== null) {
                $risks[] = $pipelineRisk;
            }
        }

        return $risks;
    }

    // ================= Pure builder functions (testable) =================

    public static function opportunitiesFromCustomers(array $customers): array
    {
        $out = [];
        $vipAndHigh = array_values(array_filter($customers, static function ($c) {
            return in_array($c['value_segment'], ['VIP', 'High Value'], true);
        }));
        usort($vipAndHigh, static function ($a, $b) {
            return $b['customer_revenue'] <=> $a['customer_revenue'];
        });
        foreach (array_slice($vipAndHigh, 0, 5) as $c) {
            $out[] = [
                'type' => 'opportunity',
                'category' => 'high_value_customer',
                'title' => "High-value customer: {$c['name']}",
                'finding' => "{$c['name']} is a {$c['value_segment']} customer with total revenue of {$c['customer_revenue']} across {$c['purchase_frequency']} deal(s).",
                'evidence' => ['customer_revenue' => $c['customer_revenue'], 'purchase_frequency' => $c['purchase_frequency'], 'segment' => $c['value_segment']],
                'reasoning_summary' => 'Customers with the highest realized revenue and recent activity are the safest targets for account growth (upsell/cross-sell/loyalty attention).',
                'confidence' => 'high',
                'estimated_impact' => null,
                'affected_area' => 'customer:' . $c['contact_id'],
                'recommended_action' => "Prioritize {$c['name']} for a personal check-in, upsell offer, or loyalty recognition.",
            ];
        }

        $growing = array_values(array_filter($customers, static function ($c) {
            return $c['revenue_trend'] === 'growing';
        }));
        foreach (array_slice($growing, 0, 5) as $c) {
            $out[] = [
                'type' => 'opportunity',
                'category' => 'upsell_growing_customer',
                'title' => "Upsell opportunity: {$c['name']}",
                'finding' => "{$c['name']}'s deal value has been increasing across their purchase history.",
                'evidence' => ['revenue_trend' => 'growing', 'purchase_frequency' => $c['purchase_frequency'], 'customer_revenue' => $c['customer_revenue']],
                'reasoning_summary' => 'A rising spend trend suggests growing trust/need - a natural moment to propose a bigger package or add-on.',
                'confidence' => 'medium',
                'estimated_impact' => null,
                'affected_area' => 'customer:' . $c['contact_id'],
                'recommended_action' => "Propose an upsell or expanded package to {$c['name']} while momentum is positive.",
            ];
        }

        $inactive = array_values(array_filter($customers, static function ($c) {
            return $c['value_segment'] === 'Inactive';
        }));
        usort($inactive, static function ($a, $b) {
            return $b['customer_revenue'] <=> $a['customer_revenue'];
        });
        foreach (array_slice($inactive, 0, 5) as $c) {
            $out[] = [
                'type' => 'opportunity',
                'category' => 're_engage_inactive_customer',
                'title' => "Re-engagement opportunity: {$c['name']}",
                'finding' => "{$c['name']} previously generated {$c['customer_revenue']} but has had no won deal in {$c['days_since_last_purchase']} days.",
                'evidence' => ['customer_revenue' => $c['customer_revenue'], 'days_since_last_purchase' => $c['days_since_last_purchase']],
                'reasoning_summary' => 'A previously valuable, now-quiet customer is a lower-cost revenue opportunity than acquiring a new one.',
                'confidence' => 'medium',
                'estimated_impact' => $c['customer_revenue'] > 0 ? round($c['customer_revenue'] * 0.2, 2) : null,
                'affected_area' => 'customer:' . $c['contact_id'],
                'recommended_action' => "Reach out to {$c['name']} with a re-engagement offer or check-in call.",
            ];
        }

        return $out;
    }

    public static function opportunitiesFromSources(array $sources): array
    {
        $out = [];
        $positive = array_values(array_filter($sources, static function ($s) {
            return $s['revenue_growth_percent'] !== null && $s['revenue_growth_percent'] > 15;
        }));
        usort($positive, static function ($a, $b) {
            return $b['revenue_growth_percent'] <=> $a['revenue_growth_percent'];
        });
        foreach (array_slice($positive, 0, 3) as $s) {
            $out[] = [
                'type' => 'opportunity',
                'category' => 'high_performing_channel',
                'title' => "High-performing source: {$s['source']}",
                'finding' => "Revenue from '{$s['source']}' grew {$s['revenue_growth_percent']}% versus the previous period, now totaling {$s['revenue']}.",
                'evidence' => ['source' => $s['source'], 'revenue' => $s['revenue'], 'growth_percent' => $s['revenue_growth_percent']],
                'reasoning_summary' => 'Sustained growth in a specific revenue source suggests it currently has strong product-market fit or momentum worth reinforcing.',
                'confidence' => 'medium',
                'estimated_impact' => null,
                'affected_area' => 'source:' . $s['source'],
                'recommended_action' => "Double down on '{$s['source']}' (more budget/attention) while it is outperforming.",
            ];
        }
        return $out;
    }

    public static function riskFromOverview(array $overview): ?array
    {
        if ($overview['growth_percent'] === null || $overview['growth_percent'] >= -10) {
            return null;
        }
        return [
            'type' => 'risk',
            'category' => 'revenue_decline',
            'title' => 'Revenue decline detected',
            'finding' => "Total revenue is down {$overview['growth_percent']}% versus the previous period ({$overview['previous_period_revenue']} -> {$overview['total_revenue']}).",
            'evidence' => ['current' => $overview['total_revenue'], 'previous' => $overview['previous_period_revenue'], 'growth_percent' => $overview['growth_percent']],
            'reasoning_summary' => 'A double-digit period-over-period drop in total revenue is a material signal worth investigating before it compounds.',
            'confidence' => 'high',
            'severity' => $overview['growth_percent'] <= -30 ? 'high' : 'medium',
            'estimated_impact' => round($overview['previous_period_revenue'] - $overview['total_revenue'], 2),
            'affected_area' => 'overall_revenue',
            'recommended_action' => 'Review what changed this period: lost deals, paused campaigns, or a seasonal/demand shift. Compare against revenue-by-source to isolate the cause.',
        ];
    }

    public static function risksFromCustomers(array $customers): array
    {
        $out = [];
        $inactive = array_values(array_filter($customers, static function ($c) {
            return $c['value_segment'] === 'Inactive';
        }));
        if (count($inactive) > 0) {
            $totalInactiveRevenue = round(array_sum(array_column($inactive, 'customer_revenue')), 2);
            $out[] = [
                'type' => 'risk',
                'category' => 'customer_inactivity',
                'title' => count($inactive) . ' customer(s) have gone inactive',
                'finding' => count($inactive) . ' previously purchasing customer(s), representing ' . $totalInactiveRevenue . ' in historical revenue, have had no won deal in over 180 days.',
                'evidence' => ['inactive_customer_count' => count($inactive), 'historical_revenue' => $totalInactiveRevenue],
                'reasoning_summary' => 'A growing inactive-customer base without replacement growth erodes recurring revenue potential over time.',
                'confidence' => 'medium',
                'severity' => count($inactive) >= 5 ? 'high' : 'medium',
                'estimated_impact' => null,
                'affected_area' => 'customer_base',
                'recommended_action' => 'Launch a structured re-engagement outreach for the highest-value inactive customers first.',
            ];
        }

        $lostHighValue = array_values(array_filter($customers, static function ($c) {
            return $c['value_segment'] === 'Inactive' && $c['customer_revenue'] > 0;
        }));
        usort($lostHighValue, static function ($a, $b) {
            return $b['customer_revenue'] <=> $a['customer_revenue'];
        });
        foreach (array_slice($lostHighValue, 0, 3) as $c) {
            $out[] = [
                'type' => 'risk',
                'category' => 'lost_high_value_customer',
                'title' => "Lost high-value customer: {$c['name']}",
                'finding' => "{$c['name']} generated {$c['customer_revenue']} in total but has been inactive for {$c['days_since_last_purchase']} days.",
                'evidence' => ['customer_revenue' => $c['customer_revenue'], 'days_since_last_purchase' => $c['days_since_last_purchase']],
                'reasoning_summary' => 'Losing a customer with proven high spend has an outsized effect on revenue compared to average churn.',
                'confidence' => 'medium',
                'severity' => 'high',
                'estimated_impact' => round($c['customer_revenue'], 2),
                'affected_area' => 'customer:' . $c['contact_id'],
                'recommended_action' => "Personally reach out to {$c['name']} to understand why they stopped purchasing.",
            ];
        }

        return $out;
    }

    public static function risksFromSources(array $sources): array
    {
        $out = [];
        $declining = array_values(array_filter($sources, static function ($s) {
            return $s['revenue_growth_percent'] !== null && $s['revenue_growth_percent'] < -20;
        }));
        usort($declining, static function ($a, $b) {
            return $a['revenue_growth_percent'] <=> $b['revenue_growth_percent'];
        });
        foreach (array_slice($declining, 0, 3) as $s) {
            $out[] = [
                'type' => 'risk',
                'category' => 'declining_channel_performance',
                'title' => "Declining source: {$s['source']}",
                'finding' => "Revenue from '{$s['source']}' fell {$s['revenue_growth_percent']}% versus the previous period.",
                'evidence' => ['source' => $s['source'], 'revenue' => $s['revenue'], 'growth_percent' => $s['revenue_growth_percent']],
                'reasoning_summary' => 'A sharply declining source may indicate a broken funnel, a paused channel, or lost demand there.',
                'confidence' => 'medium',
                'severity' => $s['revenue_growth_percent'] <= -50 ? 'high' : 'medium',
                'estimated_impact' => null,
                'affected_area' => 'source:' . $s['source'],
                'recommended_action' => "Investigate '{$s['source']}' - check if a campaign paused, an integration broke, or demand genuinely dropped.",
            ];
        }
        return $out;
    }

    public static function riskFromPipeline(array $pipeline): ?array
    {
        if ($pipeline['open_deals_count'] === 0) {
            return [
                'type' => 'risk',
                'category' => 'pipeline_weakness',
                'title' => 'No open pipeline',
                'finding' => 'There are currently no open deals in the CRM pipeline.',
                'evidence' => ['open_deals_count' => 0],
                'reasoning_summary' => 'Without an open pipeline, future revenue has no near-term source beyond existing recurring/repeat customers.',
                'confidence' => 'high',
                'severity' => 'high',
                'estimated_impact' => null,
                'affected_area' => 'pipeline',
                'recommended_action' => 'Prioritize lead generation and qualification to rebuild the pipeline.',
            ];
        }
        if ($pipeline['pipeline_coverage'] !== null && $pipeline['pipeline_coverage'] < 1.0) {
            return [
                'type' => 'risk',
                'category' => 'pipeline_weakness',
                'title' => 'Pipeline coverage is thin',
                'finding' => "Weighted pipeline ({$pipeline['weighted_pipeline']}) is below recent average monthly actual revenue, giving a coverage ratio of {$pipeline['pipeline_coverage']}x.",
                'evidence' => ['weighted_pipeline' => $pipeline['weighted_pipeline'], 'pipeline_coverage' => $pipeline['pipeline_coverage']],
                'reasoning_summary' => 'A coverage ratio below 1x suggests the current pipeline alone is unlikely to sustain the recent revenue run-rate.',
                'confidence' => 'medium',
                'severity' => $pipeline['pipeline_coverage'] < 0.5 ? 'high' : 'medium',
                'estimated_impact' => null,
                'affected_area' => 'pipeline',
                'recommended_action' => 'Increase prospecting/lead generation to widen the pipeline relative to the recent revenue run-rate.',
            ];
        }
        if (!empty($pipeline['at_risk_deals'])) {
            $count = count($pipeline['at_risk_deals']);
            $value = round(array_sum(array_column($pipeline['at_risk_deals'], 'value')), 2);
            return [
                'type' => 'risk',
                'category' => 'stalled_deals',
                'title' => "{$count} deal(s) past their expected close date",
                'finding' => "{$count} open deal(s) worth {$value} combined are past their expected close date and may be stalled.",
                'evidence' => ['stalled_deal_count' => $count, 'stalled_deal_value' => $value],
                'reasoning_summary' => 'Deals lingering past their expected close date rarely close at the originally-estimated probability.',
                'confidence' => 'medium',
                'severity' => $value > 0 ? 'medium' : 'low',
                'estimated_impact' => $value,
                'affected_area' => 'pipeline',
                'recommended_action' => 'Review each overdue deal with its owner: re-confirm timeline, re-qualify, or mark lost.',
            ];
        }
        return null;
    }
}

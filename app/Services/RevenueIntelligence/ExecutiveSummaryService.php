<?php

/**
 * Tourfecto - Executive Revenue Summary Service
 * @version 1.0.0
 *
 * Section 14: EXECUTIVE SUMMARY
 * ملخص عالي المستوى مناسب لـ CEO/Manager - يجمّع أهم رقم من كل خدمة
 * بدل تفصيل كامل. لا يحسب أي شيء بنفسه؛ يستدعي الخدمات الأخرى فقط.
 */
class ExecutiveSummaryService
{
    private RevenueOverviewService $overview;
    private RevenueForecastService $forecastService;
    private RevenueInsightService $insightService;
    private CustomerRevenueService $customerService;
    private RevenueActionService $actionService;

    public function __construct(
        ?RevenueOverviewService $overview = null,
        ?RevenueForecastService $forecastService = null,
        ?RevenueInsightService $insightService = null,
        ?CustomerRevenueService $customerService = null,
        ?RevenueActionService $actionService = null
    ) {
        $this->overview = $overview ?? new RevenueOverviewService();
        $this->forecastService = $forecastService ?? new RevenueForecastService();
        $this->insightService = $insightService ?? new RevenueInsightService();
        $this->customerService = $customerService ?? new CustomerRevenueService();
        $this->actionService = $actionService ?? new RevenueActionService();
    }

    public function getSummary(int $userId): array
    {
        $overview = $this->overview->getOverview($userId, 'monthly');
        $forecast = $this->forecastService->forecast($userId, 'monthly', false);
        $opportunities = $this->insightService->getOpportunities($userId);
        $risks = $this->insightService->getRisks($userId);
        $segments = $this->customerService->getSegments($userId);
        $sourceGrowth = $this->overview->getRevenueBySourceWithGrowth($userId, 'monthly');
        $actions = $this->actionService->getNextBestActions($userId, 3);

        $topOpportunity = $opportunities[0] ?? null;
        $topRisk = self::pickTopRisk($risks);
        $topSegment = ($segments['has_data'] ?? false) && !empty($segments['summary']) ? $segments['summary'][0] : null;
        $topSource = ($sourceGrowth['has_data'] ?? false) && !empty($sourceGrowth['sources']) ? $sourceGrowth['sources'][0] : null;

        return [
            'current_revenue' => $overview['total_revenue'],
            'growth_percent' => $overview['growth_percent'],
            'growth_trend' => $overview['growth_trend'],
            'forecast' => $forecast['insufficient_data'] ? null : [
                'expected_revenue' => $forecast['expected_revenue'],
                'confidence' => $forecast['confidence'],
                'period' => $forecast['period'],
            ],
            'forecast_message' => $forecast['insufficient_data'] ? 'Not enough data for reliable forecast.' : null,
            'top_opportunity' => $topOpportunity ? ['title' => $topOpportunity['title'], 'recommended_action' => $topOpportunity['recommended_action']] : null,
            'top_risk' => $topRisk ? ['title' => $topRisk['title'], 'severity' => $topRisk['severity'] ?? null, 'recommended_action' => $topRisk['recommended_action']] : null,
            'top_customer_segment' => $topSegment,
            'top_revenue_source' => $topSource ? ['source' => $topSource['source'], 'revenue' => $topSource['revenue']] : null,
            'recommended_actions' => $actions,
            'has_data' => $overview['has_data'],
        ];
    }

    private static function pickTopRisk(array $risks): ?array
    {
        if (empty($risks)) {
            return null;
        }
        $sevRank = ['high' => 3, 'medium' => 2, 'low' => 1];
        usort($risks, static function ($a, $b) use ($sevRank) {
            return ($sevRank[$b['severity'] ?? 'low'] ?? 0) <=> ($sevRank[$a['severity'] ?? 'low'] ?? 0);
        });
        return $risks[0];
    }
}

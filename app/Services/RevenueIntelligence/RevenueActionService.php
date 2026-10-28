<?php

/**
 * Tourfecto - Next Best Revenue Action Service
 * @version 1.0.0
 *
 * Section 11: NEXT BEST REVENUE ACTION
 *
 * لا ينفذ أي إجراء خارجي تلقائيًا (كما ينص section 11 صراحةً) - فقط
 * يقترح، مبنيًا على Opportunities/Risks/Anomalies الحقيقية المُولّدة من
 * RevenueInsightService/RevenueAnomalyService.
 */
class RevenueActionService
{
    private RevenueInsightService $insightService;
    private RevenueAnomalyService $anomalyService;

    public function __construct(?RevenueInsightService $insightService = null, ?RevenueAnomalyService $anomalyService = null)
    {
        $this->insightService = $insightService ?? new RevenueInsightService();
        $this->anomalyService = $anomalyService ?? new RevenueAnomalyService();
    }

    public function getNextBestActions(int $userId, int $limit = 10): array
    {
        $opportunities = $this->insightService->getOpportunities($userId);
        $risks = $this->insightService->getRisks($userId);
        $anomalies = $this->anomalyService->detect($userId);
        $anomalyList = $anomalies['has_data'] ? $anomalies['anomalies'] : [];

        return self::rankActions($opportunities, $risks, $anomalyList, $limit);
    }

    /** Pure function - قابلة للاختبار مباشرة بمدخلات ثابتة. */
    public static function rankActions(array $opportunities, array $risks, array $anomalies, int $limit = 10): array
    {
        $actions = [];

        foreach ($opportunities as $o) {
            $actions[] = [
                'action' => self::categoryToActionLabel($o['category']),
                'reason' => $o['finding'],
                'confidence' => $o['confidence'],
                'expected_impact' => $o['estimated_impact'],
                'source_type' => 'opportunity',
                'source_category' => $o['category'],
                'affected_area' => $o['affected_area'] ?? null,
                'recommended_action' => $o['recommended_action'],
            ];
        }

        foreach ($risks as $r) {
            $actions[] = [
                'action' => self::categoryToActionLabel($r['category']),
                'reason' => $r['finding'],
                'confidence' => $r['confidence'],
                'expected_impact' => $r['estimated_impact'],
                'source_type' => 'risk',
                'source_category' => $r['category'],
                'affected_area' => $r['affected_area'] ?? null,
                'recommended_action' => $r['recommended_action'],
                'severity' => $r['severity'] ?? null,
            ];
        }

        foreach ($anomalies as $a) {
            if ($a['type'] !== 'sudden_drop') {
                continue; // الارتفاعات المفاجئة أقل إلحاحًا كـ "إجراء مقترح فوري"
            }
            $actions[] = [
                'action' => 'Investigate Revenue Drop',
                'reason' => "Revenue on {$a['period']} was {$a['value']}, notably below the expected range ({$a['expected_range']['low']}-{$a['expected_range']['high']}).",
                'confidence' => $a['severity'] === 'high' ? 'high' : 'medium',
                'expected_impact' => null,
                'source_type' => 'anomaly',
                'source_category' => $a['type'],
                'affected_area' => 'daily_revenue',
                'period' => $a['period'],
                'recommended_action' => $a['recommended_investigation'],
                'severity' => $a['severity'],
            ];
        }

        // ترتيب: أولاً حسب severity/confidence (Critical/High أولاً)، بعدين حسب expected_impact
        $rank = static function ($a) {
            $sevRank = ['high' => 3, 'medium' => 2, 'low' => 1, null => 0];
            $confRank = ['high' => 3, 'medium' => 2, 'low' => 1, null => 0];
            $severity = $sevRank[$a['severity'] ?? null] ?? 0;
            $confidence = $confRank[$a['confidence']] ?? 0;
            $impact = $a['expected_impact'] ?? 0;
            return [$severity, $confidence, $impact];
        };

        usort($actions, static function ($a, $b) use ($rank) {
            return $rank($b) <=> $rank($a);
        });

        return array_slice($actions, 0, $limit);
    }

    private static function categoryToActionLabel(string $category): string
    {
        $map = [
            'high_value_customer' => 'Contact High-value Customer',
            'upsell_growing_customer' => 'Upsell',
            're_engage_inactive_customer' => 'Re-engage Customer',
            'high_performing_channel' => 'Promote Product', // أقرب تسمية متاحة من القائمة المطلوبة (لا يوجد "Promote Channel" في section 11)
            'revenue_decline' => 'Investigate Revenue Drop',
            'customer_inactivity' => 'Re-engage Customer',
            'lost_high_value_customer' => 'Contact High-value Customer',
            'declining_channel_performance' => 'Review Campaign',
            'pipeline_weakness' => 'Follow-up',
            'stalled_deals' => 'Follow-up',
        ];
        return $map[$category] ?? 'Follow-up';
    }
}

<?php

/**
 * Tourfecto - Revenue Insight Persister
 * @version 1.0.0
 *
 * منطق تخزين الـ Insights (Opportunities/Risks/Anomalies) في
 * revai_insights مستخرج هنا في مكان واحد، عشان يستخدمه أي مكان محتاج
 * يسجّل Insight (الـ Controller وقت طلب مباشر من المستخدم، أو
 * RecomputeRevenueInsightsJob وقت إعادة الحساب في الخلفية) - بدل تكرار
 * نفس الكود في مكانين (Section 17: Audit Log).
 */
class RevenueInsightPersister
{
    /** @param array<int, array<string, mixed>> $insights */
    public static function persist(int $userId, array $insights): void
    {
        foreach ($insights as $insight) {
            try {
                (new RevaiInsight([
                    'user_id' => $userId,
                    'type' => $insight['type'],
                    'category' => $insight['category'],
                    'title' => $insight['title'],
                    'finding' => $insight['finding'],
                    'evidence' => json_encode($insight['evidence'] ?? [], JSON_UNESCAPED_UNICODE),
                    'reasoning_summary' => $insight['reasoning_summary'] ?? null,
                    'confidence' => $insight['confidence'] ?? 'medium',
                    'severity' => $insight['severity'] ?? null,
                    'estimated_impact' => $insight['estimated_impact'] ?? null,
                    'affected_area' => $insight['affected_area'] ?? null,
                    'recommended_action' => $insight['recommended_action'] ?? '',
                    'status' => 'active',
                ]))->save();
            } catch (Throwable $e) {
                if (class_exists('Logger')) {
                    Logger::error('RevenueInsightPersister: failed to persist insight', ['message' => $e->getMessage()]);
                }
            }
        }
    }

    /** يحوّل Anomaly (شكل مختلف شوية) لنفس شكل Insight القياسي قبل التخزين. */
    public static function anomalyToInsight(array $anomaly): array
    {
        return [
            'type' => 'anomaly',
            'category' => $anomaly['type'],
            'title' => ucfirst(str_replace('_', ' ', $anomaly['type'])) . " on {$anomaly['period']}",
            'finding' => "Daily revenue was {$anomaly['value']} on {$anomaly['period']} (expected {$anomaly['expected_range']['low']}-{$anomaly['expected_range']['high']}).",
            'evidence' => $anomaly,
            'reasoning_summary' => "Z-score of {$anomaly['z_score']} vs the {$anomaly['severity']} anomaly threshold.",
            'confidence' => $anomaly['severity'] === 'high' ? 'high' : 'medium',
            'severity' => $anomaly['severity'],
            'estimated_impact' => null,
            'affected_area' => 'daily_revenue',
            'recommended_action' => $anomaly['recommended_investigation'],
        ];
    }
}

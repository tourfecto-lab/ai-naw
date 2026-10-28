<?php

/**
 * Tourfecto - Competitor Intelligence: Tracking / Historical Timeline Service
 * @version 1.0.0
 *
 * يبني Timeline زمني (مجمّع بالشهر) من السجل الدائم لـ ci_changes -
 * يعرض تطور المنافس بمرور الوقت (مش بس آخر تغيير). لا يحذف أو يستبدل
 * أي سجل قديم - القراءة فقط.
 */
class CompetitorTrackingService
{
    /**
     * @return array<string, array> مفتاح كل عنصر "YYYY-MM"، والقيمة قائمة تغييرات الشهر ده
     */
    public function getTimeline(int $competitorId, int $monthsBack = 12): array
    {
        $db = Database::getInstance();
        $rows = $db->query(
            "SELECT * FROM `ci_changes` WHERE competitor_id = ?
             AND detected_at >= DATE_SUB(NOW(), INTERVAL ? MONTH)
             ORDER BY detected_at DESC",
            [$competitorId, $monthsBack]
        );

        $timeline = [];
        foreach ($rows as $row) {
            $monthKey = date('Y-m', strtotime($row['detected_at']));
            $timeline[$monthKey][] = [
                'id' => (int) $row['id'],
                'page_type' => $row['page_type'],
                'change_type' => $row['change_type'],
                'severity' => $row['severity'],
                'confidence' => $row['confidence'],
                'detected_at' => $row['detected_at'],
                'source_url' => $row['source_url'],
                'previous_value' => $row['previous_value'],
                'new_value' => $row['new_value'],
            ];
        }

        return $timeline;
    }

    public function getActivityFeed(int $userId, int $limit = 50): array
    {
        $db = Database::getInstance();
        $rows = $db->query(
            "SELECT c.*, comp.competitor_name, comp.competitor_domain
             FROM `ci_changes` c
             JOIN `competitors` comp ON comp.id = c.competitor_id
             WHERE c.user_id = ?
             ORDER BY c.detected_at DESC LIMIT ?",
            [$userId, $limit]
        );

        return array_map(function ($row) {
            return [
                'id' => (int) $row['id'],
                'competitor' => $row['competitor_name'] ?: $row['competitor_domain'],
                'competitor_id' => (int) $row['competitor_id'],
                'change_type' => $row['change_type'],
                'severity' => $row['severity'],
                'page_type' => $row['page_type'],
                'detected_at' => $row['detected_at'],
                'source_url' => $row['source_url'],
            ];
        }, $rows);
    }
}

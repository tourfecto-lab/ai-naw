<?php

/**
 * Tourfecto - CRM Chart & Visualization Service (المرحلة 14 - G7)
 * @version 1.0.0
 *
 * سد فجوة "إحصائيات + رسوم بيانية للتقرير" - G7 في COMPETITIVE_ANALYSIS.md:
 * حتى الآن التحليلات تُعرض كجداول/tiles فقط (تقرير Win/Loss، Sales Goals).
 * هذه الخدمة توفر بيانات جاهزة للرسوم البيانية (Chart.js) مباشرة من
 * قاعدة البيانات الفعلية - بدون أي قيم افتراضية/وهمية (بند 39).
 *
 * كل دالة تُرجع مصفوفة labels + datasets جاهزة للرسم:
 *   - pipelineChart: توزيع الصفقات المفتوحة على مراحل الـPipeline
 *   - revenueTrend: صافي الإيراد (Won) شهريًا خلال آخر N شهر
 *   - winLossTrend: أعمدة Won/Lost خلال فترة
 *   - leadSourceDistribution: توزيع الـLeads على المصادر
 *   - dealStatusDistribution: فطيرة Open/Won/Lost
 *   - lifecycleDistribution: توزيع جهات الاتصال على مراحل دورة الحياة
 */
class CrmChartService
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * توزيع الصفقات المفتوحة على مراحل الـPipeline (عدد + قيمة).
     * @return array{labels:string[], datasets:array}
     */
    public function pipelineChart(int $userId): array
    {
        $rows = $this->db->query(
            "SELECT s.name AS stage, s.color AS color,
                    COUNT(d.id) AS deal_count,
                    COALESCE(SUM(d.value), 0) AS total_value
             FROM crm_pipeline_stages s
             LEFT JOIN crm_deals d
                    ON d.stage_id = s.id AND d.owner_user_id = ? AND d.status = 'open'
             GROUP BY s.id, s.name, s.color, s.sort_order
             ORDER BY s.sort_order ASC, s.id ASC",
            [$userId]
        );

        $labels = [];
        $counts = [];
        $values = [];
        $colors = [];
        foreach ($rows as $row) {
            $labels[] = $row['stage'];
            $counts[] = (int) $row['deal_count'];
            $values[] = round((float) $row['total_value'], 2);
            $colors[] = $row['color'] ?? '#4f46e5';
        }

        return [
            'labels' => $labels,
            'datasets' => [
                ['label' => 'عدد الصفقات', 'data' => $counts, 'backgroundColor' => $colors],
                ['label' => 'القيمة', 'data' => $values, 'backgroundColor' => $colors],
            ],
        ];
    }

    /**
     * صافي الإيراد (Won) شهريًا خلال آخر $months شهر.
     * @return array{labels:string[], datasets:array}
     */
    public function revenueTrend(int $userId, int $months = 12): array
    {
        $months = max(1, min(24, $months));
        $labels = [];
        $series = [];
        $start = new DateTimeImmutable(date('Y-m-01'));
        for ($i = $months - 1; $i >= 0; $i--) {
            $labels[] = $start->modify("-{$i} months")->format('Y-m');
        }

        $rows = $this->db->query(
            "SELECT DATE_FORMAT(closed_at, '%Y-%m') AS month, SUM(value) AS total
             FROM crm_deals
             WHERE owner_user_id = ? AND status = 'won' AND closed_at IS NOT NULL
               AND closed_at >= ?
             GROUP BY month ORDER BY month ASC",
            [$userId, $start->modify('-' . ($months - 1) . ' months')->format('Y-m-01') . ' 00:00:00']
        );
        $byMonth = [];
        foreach ($rows as $row) {
            $byMonth[$row['month']] = round((float) $row['total'], 2);
        }
        foreach ($labels as $m) {
            $series[] = $byMonth[$m] ?? 0;
        }

        return ['labels' => $labels, 'datasets' => [['label' => 'الإيراد (Won)', 'data' => $series]]];
    }

    /**
     * أعمدة Won/Lost خلال فترة (متوافقة مع CrmReportService::winLoss).
     */
    public function winLossTrend(int $userId, ?string $from = null, ?string $to = null): array
    {
        if ($from === null || $from === '') {
            $from = date('Y-m-01');
        }
        if ($to === null || $to === '') {
            $to = date('Y-m-t');
        }

        $rows = $this->db->query(
            "SELECT status, COUNT(*) AS cnt, COALESCE(SUM(value), 0) AS total
             FROM crm_deals
             WHERE owner_user_id = ? AND status IN ('won','lost') AND closed_at IS NOT NULL
               AND closed_at >= ? AND closed_at <= ?
             GROUP BY status",
            [$userId, $from . ' 00:00:00', $to . ' 23:59:59']
        );

        $won = ['count' => 0, 'value' => 0];
        $lost = ['count' => 0, 'value' => 0];
        foreach ($rows as $row) {
            if ($row['status'] === 'won') {
                $won = ['count' => (int) $row['cnt'], 'value' => round((float) $row['total'], 2)];
            } else {
                $lost = ['count' => (int) $row['cnt'], 'value' => round((float) $row['total'], 2)];
            }
        }

        return [
            'labels' => ['مكاسب (Won)', 'خسائر (Lost)'],
            'datasets' => [
                ['label' => 'العدد', 'data' => [$won['count'], $lost['count']]],
                ['label' => 'القيمة', 'data' => [$won['value'], $lost['value']]],
            ],
            'from' => $from,
            'to' => $to,
        ];
    }

    /** توزيع الـLeads على المصادر (قيم المصادر الفعلية المسجّلة) */
    public function leadSourceDistribution(int $userId): array
    {
        $rows = $this->db->query(
            "SELECT COALESCE(NULLIF(source, ''), 'غير محدد') AS source, COUNT(*) AS cnt
             FROM crm_leads
             WHERE owner_user_id = ? OR contact_id IN (SELECT id FROM crm_contacts WHERE user_id = ?)
             GROUP BY source ORDER BY cnt DESC LIMIT 15",
            [$userId, $userId]
        );

        $labels = [];
        $data = [];
        foreach ($rows as $row) {
            $labels[] = $row['source'];
            $data[] = (int) $row['cnt'];
        }
        return ['labels' => $labels, 'datasets' => [['label' => 'عدد الـLeads', 'data' => $data]]];
    }

    /** فطيرة حالات الصفقات (Open/Won/Lost) عددًا وقيمة */
    public function dealStatusDistribution(int $userId): array
    {
        $rows = $this->db->query(
            "SELECT status, COUNT(*) AS cnt, COALESCE(SUM(value), 0) AS total
             FROM crm_deals WHERE owner_user_id = ? GROUP BY status",
            [$userId]
        );
        $map = ['open' => ['count' => 0, 'value' => 0], 'won' => ['count' => 0, 'value' => 0], 'lost' => ['count' => 0, 'value' => 0]];
        foreach ($rows as $row) {
            if (isset($map[$row['status']])) {
                $map[$row['status']] = ['count' => (int) $row['cnt'], 'value' => round((float) $row['total'], 2)];
            }
        }
        return [
            'labels' => ['مفتوحة', 'مكسبة', 'خاسرة'],
            'datasets' => [
                ['label' => 'العدد', 'data' => [$map['open']['count'], $map['won']['count'], $map['lost']['count']]],
                ['label' => 'القيمة', 'data' => [$map['open']['value'], $map['won']['value'], $map['lost']['value']]],
            ],
        ];
    }

    /** توزيع جهات الاتصال على مراحل دورة الحياة (G6) */
    public function lifecycleDistribution(int $userId): array
    {
        $rows = $this->db->query(
            "SELECT COALESCE(NULLIF(lifecycle_stage, ''), 'غير محددة') AS stage, COUNT(*) AS cnt
             FROM crm_contacts WHERE user_id = ? GROUP BY stage ORDER BY cnt DESC",
            [$userId]
        );
        $labels = [];
        $data = [];
        foreach ($rows as $row) {
            $labels[] = $row['stage'];
            $data[] = (int) $row['cnt'];
        }
        return ['labels' => $labels, 'datasets' => [['label' => 'عدد جهات الاتصال', 'data' => $data]]];
    }
}

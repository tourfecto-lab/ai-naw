<?php

/**
 * Tourfecto - CRM Dashboard & Analytics Service (بند 23/24)
 * @version 1.1.0
 *
 * كل رقم هنا محسوب مباشرة من بيانات القاعدة الفعلية للحساب - لا توجد أي
 * قيمة افتراضية/وهمية. لو مفيش بيانات كافية لحساب مقياس معيّن (مثال:
 * متوسط دورة البيع بدون صفقات مغلقة)، تُرجع القيمة null صراحة (بند 39).
 *
 * Caching (استكمال بند 37): المشروع عنده نظام Cache حقيقي جاهز بالفعل
 * (`Core/Cache.php` + `Traits/CacheableTrait.php`) - لم يكن مُستخدَمًا في
 * موديول CRM قبل كده. `stats()` هي أغلى استعلام في كل الموديول (10+
 * استعلامات SQL مجمّعة لكل تحميل صفحة Reports) وبتتحمّل التخزين المؤقت
 * القصير المدى بأمان (بيانات تحليلية، مش حرجة للحظة بالثانية). الـTrait
 * نفسه Fail-open: لو الكاش مش متاح لأي سبب، بينفّذ الاستعلامات مباشرة
 * زي ما كان يحصل قبل كده بالظبط - لا فشل صامت للميزة الأساسية.
 */
class CrmDashboardService
{
    use CacheableTrait;

    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function stats(int $userId): array
    {
        return $this->cached("crm:dashboard_stats:{$userId}", 90, function () use ($userId) {
            return $this->computeStats($userId);
        });
    }

    /** الحساب الفعلي - اتفصلت عن stats() بس عشان cached() تقدر تغلّفها */
    private function computeStats(int $userId): array
    {
        $totalLeads = $this->scalar(
            "SELECT COUNT(*) FROM crm_leads l JOIN crm_contacts c ON c.id = l.contact_id WHERE c.user_id = ?",
            [$userId]
        );
        $newLeads = $this->scalar(
            "SELECT COUNT(*) FROM crm_leads l JOIN crm_contacts c ON c.id = l.contact_id WHERE c.user_id = ? AND l.status = 'new'",
            [$userId]
        );
        $qualifiedLeads = $this->scalar(
            "SELECT COUNT(*) FROM crm_leads l JOIN crm_contacts c ON c.id = l.contact_id WHERE c.user_id = ? AND l.status = 'qualified'",
            [$userId]
        );
        $convertedLeads = $this->scalar(
            "SELECT COUNT(*) FROM crm_leads l JOIN crm_contacts c ON c.id = l.contact_id WHERE c.user_id = ? AND l.status = 'converted'",
            [$userId]
        );
        $conversionRate = $totalLeads > 0 ? round(($convertedLeads / $totalLeads) * 100, 1) : null;

        $openDeals = $this->scalar("SELECT COUNT(*) FROM crm_deals WHERE owner_user_id = ? AND status = 'open'", [$userId]);
        $wonDeals = $this->scalar("SELECT COUNT(*) FROM crm_deals WHERE owner_user_id = ? AND status = 'won'", [$userId]);
        $lostDeals = $this->scalar("SELECT COUNT(*) FROM crm_deals WHERE owner_user_id = ? AND status = 'lost'", [$userId]);

        $pipelineValue = $this->scalar("SELECT COALESCE(SUM(value),0) FROM crm_deals WHERE owner_user_id = ? AND status = 'open'", [$userId]);
        $weightedPipeline = $this->scalar(
            "SELECT COALESCE(SUM(d.value * COALESCE(d.probability, s.win_probability) / 100),0)
             FROM crm_deals d JOIN crm_pipeline_stages s ON s.id = d.stage_id
             WHERE d.owner_user_id = ? AND d.status = 'open'",
            [$userId]
        );
        $avgDealValue = $this->scalar("SELECT AVG(value) FROM crm_deals WHERE owner_user_id = ? AND status IN ('open','won')", [$userId]);

        $avgSalesCycleDays = $this->scalar(
            "SELECT AVG(DATEDIFF(closed_at, created_at)) FROM crm_deals WHERE owner_user_id = ? AND status = 'won' AND closed_at IS NOT NULL",
            [$userId]
        );

        $overdueFollowUps = count((new CrmLead())->overdueFollowUps($userId));
        $overdueTasks = count((new CrmTask())->overdue($userId));

        $leadSources = $this->db->query(
            "SELECT COALESCE(l.source, 'unknown') AS source, COUNT(*) AS total
             FROM crm_leads l JOIN crm_contacts c ON c.id = l.contact_id
             WHERE c.user_id = ? GROUP BY l.source",
            [$userId]
        );

        $salesTrend = $this->db->query(
            "SELECT DATE_FORMAT(closed_at, '%Y-%m') AS month, SUM(value) AS total
             FROM crm_deals WHERE owner_user_id = ? AND status = 'won' AND closed_at IS NOT NULL
             GROUP BY DATE_FORMAT(closed_at, '%Y-%m') ORDER BY month ASC LIMIT 12",
            [$userId]
        );

        $repPerformance = $this->db->query(
            "SELECT u.id, u.first_name, u.last_name,
                    COUNT(DISTINCT d.id) AS deals_count,
                    COALESCE(SUM(CASE WHEN d.status = 'won' THEN d.value ELSE 0 END), 0) AS won_value
             FROM crm_deals d JOIN users u ON u.id = d.owner_user_id
             WHERE d.owner_user_id = ? GROUP BY u.id",
            [$userId]
        );

        return [
            'total_leads' => (int) $totalLeads,
            'new_leads' => (int) $newLeads,
            'qualified_leads' => (int) $qualifiedLeads,
            'conversion_rate' => $conversionRate,
            'open_deals' => (int) $openDeals,
            'won_deals' => (int) $wonDeals,
            'lost_deals' => (int) $lostDeals,
            'pipeline_value' => (float) $pipelineValue,
            'weighted_pipeline' => round((float) $weightedPipeline, 2),
            'average_deal_value' => $avgDealValue !== null ? round((float) $avgDealValue, 2) : null,
            'average_sales_cycle_days' => $avgSalesCycleDays !== null ? round((float) $avgSalesCycleDays, 1) : null,
            'overdue_follow_ups' => $overdueFollowUps,
            'overdue_tasks' => $overdueTasks,
            'lead_sources' => $leadSources,
            'sales_trend' => $salesTrend,
            'sales_rep_performance' => $repPerformance,
        ];
    }

    private function scalar(string $sql, array $params)
    {
        $rows = $this->db->query($sql, $params);
        if (empty($rows)) {
            return null;
        }
        $row = $rows[0];
        return reset($row);
    }
}

<?php

/** Tourfecto - CRM Deal Model @version 1.1.0 */
class CrmDeal extends Model
{
    protected $table = 'crm_deals';
    protected $fillable = [
        'owner_user_id', 'lead_id', 'contact_id', 'company_id', 'pipeline_id', 'stage_id',
        'title', 'value', 'currency', 'probability', 'expected_close_date',
        'closed_at', 'status', 'lost_reason', 'notes',
    ];

    public function allForOwner(int $ownerUserId, int $limit = 200): array
    {
        return $this->db->query(
            "SELECT d.*, s.name AS stage_name, s.color AS stage_color, s.is_won, s.is_lost
             FROM `crm_deals` d
             JOIN `crm_pipeline_stages` s ON s.id = d.stage_id
             WHERE d.owner_user_id = ?
             ORDER BY d.created_at DESC
             LIMIT ?",
            [$ownerUserId, $limit]
        );
    }

    /**
     * صفقات معرّضة للخسارة بناءً على إشارات فعلية متاحة فقط: بدون نشاط
     * حديث ووقت طويل في نفس المرحلة (بند 26). Heuristic بسيط شفاف - وليس
     * "AI تنبؤي" مُدّعى؛ هذه أساس جاهز ليُستبدل/يُدعّم بموديول AI في مرحلة تالية.
     */
    public function staleOpenDeals(int $ownerUserId, int $daysWithoutActivity = 14): array
    {
        return $this->db->query(
            "SELECT d.*, s.name AS stage_name
             FROM `crm_deals` d
             JOIN `crm_pipeline_stages` s ON s.id = d.stage_id
             WHERE d.owner_user_id = ?
               AND d.status = 'open'
               AND d.updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY d.updated_at ASC",
            [$ownerUserId, $daysWithoutActivity]
        );
    }
}

<?php

/** Tourfecto - CRM Lead Routing Rule Model (المرحلة 13 - G5) @version 1.0.0 */
class CrmLeadRoutingRule extends Model
{
    protected $table = 'crm_lead_routing_rules';
    protected $fillable = [
        'user_id', 'name', 'is_active', 'match_source', 'match_country',
        'match_min_value', 'match_max_value', 'assignment_mode',
        'assignee_user_id', 'rotation_index', 'sort_order',
    ];

    /** قواعد الحساب النشطة مرتبة حسب الترتيب */
    public function activeForUser(int $userId): array
    {
        return $this->db->query(
            "SELECT * FROM crm_lead_routing_rules WHERE user_id = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC",
            [$userId]
        );
    }

    /** كل قواعد الحساب */
    public function allForUser(int $userId): array
    {
        return $this->db->query(
            "SELECT * FROM crm_lead_routing_rules WHERE user_id = ? ORDER BY sort_order ASC, id ASC",
            [$userId]
        );
    }

    /** قاعدة مملوكة للحساب */
    public function findOwned(int $userId, int $ruleId): ?CrmLeadRoutingRule
    {
        $rows = $this->db->query(
            "SELECT * FROM crm_lead_routing_rules WHERE id = ? AND user_id = ? LIMIT 1",
            [$ruleId, $userId]
        );
        if (empty($rows)) {
            return null;
        }
        $model = new static($rows[0]);
        $model->original = $rows[0];
        return $model;
    }
}

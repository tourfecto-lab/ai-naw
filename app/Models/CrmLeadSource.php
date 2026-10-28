<?php

/** Tourfecto - CRM Lead Source Model (مصادر قابلة للتخصيص - بند 4) @version 1.0.0 */
class CrmLeadSource extends Model
{
    protected $table = 'crm_lead_sources';
    protected $fillable = ['user_id', 'name', 'source_key', 'is_active'];

    /** المصادر العامة الافتراضية + المصادر المخصصة لهذا الحساب */
    public function availableForUser(int $userId): array
    {
        return $this->db->query(
            "SELECT * FROM `crm_lead_sources` WHERE (`user_id` IS NULL OR `user_id` = ?) AND `is_active` = 1 ORDER BY `id` ASC",
            [$userId]
        );
    }
}

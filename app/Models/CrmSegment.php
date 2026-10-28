<?php

/** Tourfecto - CRM Segment Model (بند 19) @version 1.0.0 */
class CrmSegment extends Model
{
    protected $table = 'crm_segments';
    protected $fillable = ['user_id', 'name', 'filters', 'is_system'];

    /** القطاعات العامة الافتراضية + قطاعات الحساب نفسه */
    public function availableForUser(int $userId): array
    {
        return $this->db->query(
            "SELECT * FROM crm_segments WHERE user_id IS NULL OR user_id = ? ORDER BY is_system DESC, id ASC",
            [$userId]
        );
    }
}

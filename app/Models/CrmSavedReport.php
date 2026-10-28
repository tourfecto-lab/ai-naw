<?php

/** Tourfecto - CRM Saved Report Model (المرحلة 15 - G13) @version 1.0.0 */
class CrmSavedReport extends Model
{
    protected $table = 'crm_saved_reports';
    protected $fillable = ['user_id', 'name', 'entity', 'config'];

    public function forUser(int $userId): array
    {
        return $this->db->query(
            "SELECT * FROM crm_saved_reports WHERE user_id = ? ORDER BY name ASC",
            [$userId]
        );
    }

    public function findOwned(int $userId, int $reportId): ?CrmSavedReport
    {
        $rows = $this->db->query(
            "SELECT * FROM crm_saved_reports WHERE id = ? AND user_id = ? LIMIT 1",
            [$reportId, $userId]
        );
        if (empty($rows)) {
            return null;
        }
        $model = new static($rows[0]);
        $model->original = $rows[0];
        return $model;
    }
}

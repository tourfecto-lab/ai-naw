<?php

/** Tourfecto - CRM Lifecycle Stage Model (المرحلة 13 - G6) @version 1.0.0 */
class CrmLifecycleStage extends Model
{
    protected $table = 'crm_lifecycle_stages';
    protected $fillable = ['user_id', 'stage_key', 'name', 'color', 'sort_order', 'is_system'];

    /** المراحل المتاحة للحساب: الافتراضية العامة + المخصصة */
    public function availableForUser(int $userId): array
    {
        return $this->db->query(
            "SELECT * FROM crm_lifecycle_stages WHERE user_id IS NULL OR user_id = ?
             ORDER BY sort_order ASC, id ASC",
            [$userId]
        );
    }

    /** مرحلة عامة أو خاصة بحساب - للتحقق من صحة stage_key */
    public function findByKey(int $userId, string $stageKey): ?CrmLifecycleStage
    {
        $rows = $this->db->query(
            "SELECT * FROM crm_lifecycle_stages WHERE stage_key = ? AND (user_id IS NULL OR user_id = ?) LIMIT 1",
            [$stageKey, $userId]
        );
        if (empty($rows)) {
            return null;
        }
        $model = new static($rows[0]);
        $model->original = $rows[0];
        return $model;
    }

    /** مرحلة مملوكة للحساب (للتعديل/الحذف) */
    public function findOwned(int $userId, int $stageId): ?CrmLifecycleStage
    {
        $rows = $this->db->query(
            "SELECT * FROM crm_lifecycle_stages WHERE id = ? AND user_id = ? LIMIT 1",
            [$stageId, $userId]
        );
        if (empty($rows)) {
            return null;
        }
        $model = new static($rows[0]);
        $model->original = $rows[0];
        return $model;
    }
}

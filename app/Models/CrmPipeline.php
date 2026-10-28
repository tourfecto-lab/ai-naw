<?php

/** Tourfecto - CRM Pipeline Model (دعم أكثر من Pipeline - بند 6) @version 1.0.0 */
class CrmPipeline extends Model
{
    protected $table = 'crm_pipelines';
    protected $fillable = ['user_id', 'name', 'pipeline_key', 'is_default', 'sort_order'];

    /** الـPipelines المتاحة لحساب معيّن: العامة (user_id IS NULL) + الخاصة به */
    public function availableForUser(int $userId): array
    {
        return $this->db->query(
            "SELECT * FROM `crm_pipelines` WHERE `user_id` IS NULL OR `user_id` = ? ORDER BY `sort_order` ASC, `id` ASC",
            [$userId]
        );
    }
}

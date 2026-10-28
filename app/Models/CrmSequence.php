<?php

/** Tourfecto - CRM Sequence Model (المرحلة 15 - G12) @version 1.0.0 */
class CrmSequence extends Model
{
    protected $table = 'crm_sequences';
    protected $fillable = ['user_id', 'name', 'description', 'steps', 'is_active'];

    public function forUser(int $userId): array
    {
        return $this->db->query(
            "SELECT * FROM crm_sequences WHERE user_id = ? ORDER BY created_at DESC",
            [$userId]
        );
    }

    public function findOwned(int $userId, int $seqId): ?CrmSequence
    {
        $rows = $this->db->query(
            "SELECT * FROM crm_sequences WHERE id = ? AND user_id = ? LIMIT 1",
            [$seqId, $userId]
        );
        if (empty($rows)) {
            return null;
        }
        $model = new static($rows[0]);
        $model->original = $rows[0];
        return $model;
    }
}

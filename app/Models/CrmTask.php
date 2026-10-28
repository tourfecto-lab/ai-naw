<?php

/** Tourfecto - CRM Task Model @version 1.1.0 */
class CrmTask extends Model
{
    protected $table = 'crm_tasks';
    protected $fillable = [
        'user_id', 'created_by_user_id', 'assigned_to_user_id', 'related_type', 'related_id',
        'title', 'description', 'due_date', 'priority', 'status', 'completed_at',
    ];

    public function allForUser(int $userId, int $limit = 200): array
    {
        return $this->where(['user_id' => $userId], ['due_date' => 'ASC'], $limit);
    }

    public function overdue(int $userId): array
    {
        return $this->db->query(
            "SELECT * FROM `crm_tasks`
             WHERE `user_id` = ? AND `status` NOT IN ('done', 'cancelled') AND `due_date` IS NOT NULL AND `due_date` < NOW()
             ORDER BY `due_date` ASC",
            [$userId]
        );
    }

    public function forRelated(string $relatedType, int $relatedId): array
    {
        return $this->where(['related_type' => $relatedType, 'related_id' => $relatedId], ['due_date' => 'ASC']);
    }
}

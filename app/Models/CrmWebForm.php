<?php

/** Tourfecto - CRM Web Form Model (المرحلة 15 - G11) @version 1.0.0 */
class CrmWebForm extends Model
{
    protected $table = 'crm_web_forms';
    protected $fillable = [
        'user_id', 'name', 'slug', 'description', 'fields', 'success_message',
        'redirect_url', 'owner_user_id', 'source', 'is_active',
    ];

    public function forUser(int $userId): array
    {
        return $this->db->query(
            "SELECT * FROM crm_web_forms WHERE user_id = ? ORDER BY created_at DESC",
            [$userId]
        );
    }

    public function findOwned(int $userId, int $formId): ?CrmWebForm
    {
        $rows = $this->db->query(
            "SELECT * FROM crm_web_forms WHERE id = ? AND user_id = ? LIMIT 1",
            [$formId, $userId]
        );
        if (empty($rows)) {
            return null;
        }
        $model = new static($rows[0]);
        $model->original = $rows[0];
        return $model;
    }

    /** نموذج عام نشط عبر slug (للإرسال العام) */
    public function findBySlug(string $slug): ?CrmWebForm
    {
        $rows = $this->db->query(
            "SELECT * FROM crm_web_forms WHERE slug = ? AND is_active = 1 LIMIT 1",
            [$slug]
        );
        if (empty($rows)) {
            return null;
        }
        $model = new static($rows[0]);
        $model->original = $rows[0];
        return $model;
    }
}

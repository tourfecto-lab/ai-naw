<?php

/** Tourfecto - CRM Activity Type Model (المرحلة 14 - G10) @version 1.0.0 */
class CrmActivityType extends Model
{
    protected $table = 'crm_activity_types';
    protected $fillable = ['user_id', 'type_key', 'name', 'icon', 'color', 'is_system', 'is_active'];

    /** الأنواع المتاحة للحساب: الافتراضية العامة + المخصصة */
    public function availableForUser(int $userId): array
    {
        return $this->db->query(
            "SELECT * FROM crm_activity_types
             WHERE (user_id IS NULL OR user_id = ?) AND is_active = 1
             ORDER BY is_system ASC, name ASC, id ASC",
            [$userId]
        );
    }

    /** نوع مملوك للحساب (للتعديل/الحذف) */
    public function findOwned(int $userId, int $typeId): ?CrmActivityType
    {
        $rows = $this->db->query(
            "SELECT * FROM crm_activity_types WHERE id = ? AND user_id = ? LIMIT 1",
            [$typeId, $userId]
        );
        if (empty($rows)) {
            return null;
        }
        $model = new static($rows[0]);
        $model->original = $rows[0];
        return $model;
    }

    /** نوع صالح للاستخدام (عام أو خاص بالحساب) - للتحقق عند تسجيل نشاط */
    public function findUsable(int $userId, int $typeId): ?CrmActivityType
    {
        $rows = $this->db->query(
            "SELECT * FROM crm_activity_types
             WHERE id = ? AND (user_id IS NULL OR user_id = ?) AND is_active = 1 LIMIT 1",
            [$typeId, $userId]
        );
        if (empty($rows)) {
            return null;
        }
        $model = new static($rows[0]);
        $model->original = $rows[0];
        return $model;
    }
}

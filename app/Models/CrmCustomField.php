<?php

/** Tourfecto - CRM Custom Field Definition Model (المرحلة 12 - G2) @version 1.0.0 */
class CrmCustomField extends Model
{
    protected $table = 'crm_custom_fields';
    protected $fillable = ['user_id', 'entity_type', 'field_key', 'label', 'field_type', 'options'];

    public const TYPES = ['text', 'number', 'date', 'select'];
    public const ENTITY_TYPES = ['contact', 'lead', 'deal', 'company'];

    /** تعريفات الحساب حسب الكيان (اختياري) */
    public function forUser(int $userId, string $entityType = ''): array
    {
        $sql = "SELECT * FROM crm_custom_fields WHERE user_id = ?";
        $params = [$userId];
        if ($entityType !== '') {
            $sql .= " AND entity_type = ?";
            $params[] = $entityType;
        }
        $sql .= " ORDER BY entity_type ASC, id ASC";
        return $this->db->query($sql, $params);
    }

    /** تعريف مملوك للحساب */
    public function findOwned(int $userId, int $fieldId): ?CrmCustomField
    {
        $rows = $this->db->query(
            "SELECT * FROM crm_custom_fields WHERE id = ? AND user_id = ? LIMIT 1",
            [$fieldId, $userId]
        );
        if (empty($rows)) {
            return null;
        }
        $model = new static($rows[0]);
        $model->original = $rows[0];
        return $model;
    }

    /** تعريف بواسطة (user + entity + key) - للتحقق من التكرار */
    public function findByKey(int $userId, string $entityType, string $fieldKey): ?CrmCustomField
    {
        $rows = $this->db->query(
            "SELECT * FROM crm_custom_fields WHERE user_id = ? AND entity_type = ? AND field_key = ? LIMIT 1",
            [$userId, $entityType, $fieldKey]
        );
        if (empty($rows)) {
            return null;
        }
        $model = new static($rows[0]);
        $model->original = $rows[0];
        return $model;
    }

    /** خيارات حقل select كـ array */
    public function optionsList(): array
    {
        $json = (string) $this->getAttribute('options');
        return $json !== '' ? (json_decode($json, true) ?: []) : [];
    }
}

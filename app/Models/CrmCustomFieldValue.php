<?php

/** Tourfecto - CRM Custom Field Value Model (المرحلة 12 - G2) @version 1.0.0 */
class CrmCustomFieldValue extends Model
{
    protected $table = 'crm_custom_field_values';
    protected $fillable = ['user_id', 'entity_type', 'entity_id', 'field_id', 'value'];

    /** كل قيم كيان معيّن (map: field_id => value) */
    public function allForEntity(int $userId, string $entityType, int $entityId): array
    {
        $rows = $this->db->query(
            "SELECT field_id, value FROM crm_custom_field_values
             WHERE user_id = ? AND entity_type = ? AND entity_id = ?",
            [$userId, $entityType, $entityId]
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['field_id']] = $row['value'];
        }
        return $map;
    }

    /** قيمة سجل-معيّن (أو null) */
    public function findForField(int $userId, string $entityType, int $entityId, int $fieldId): ?CrmCustomFieldValue
    {
        $rows = $this->db->query(
            "SELECT * FROM crm_custom_field_values
             WHERE user_id = ? AND entity_type = ? AND entity_id = ? AND field_id = ? LIMIT 1",
            [$userId, $entityType, $entityId, $fieldId]
        );
        if (empty($rows)) {
            return null;
        }
        $model = new static($rows[0]);
        $model->original = $rows[0];
        return $model;
    }
}

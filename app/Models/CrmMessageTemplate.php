<?php

/** Tourfecto - CRM Message Template Model (المرحلة 12 - G1) @version 1.0.0 */
class CrmMessageTemplate extends Model
{
    protected $table = 'crm_message_templates';
    protected $fillable = ['user_id', 'channel', 'name', 'subject', 'body', 'variables', 'created_by_user_id'];

    /** قوالب الحساب نفسه حسب القناة (اختياري: كل القنوات لو فاضية) */
    public function forUser(int $userId, string $channel = ''): array
    {
        $sql = "SELECT * FROM crm_message_templates WHERE user_id = ?";
        $params = [$userId];
        if ($channel !== '') {
            $sql .= " AND channel = ?";
            $params[] = $channel;
        }
        $sql .= " ORDER BY channel ASC, name ASC";
        return $this->db->query($sql, $params);
    }

    /** قالب واحد مملوك للحساب (عزل تينانت) */
    public function findOwned(int $userId, int $templateId): ?CrmMessageTemplate
    {
        $rows = $this->db->query(
            "SELECT * FROM crm_message_templates WHERE id = ? AND user_id = ? LIMIT 1",
            [$templateId, $userId]
        );
        if (empty($rows)) {
            return null;
        }
        $model = new static($rows[0]);
        $model->original = $rows[0];
        return $model;
    }

    /** المتغيرات كـ JSON decodable (أو [] لو مش موجود) */
    public function variablesList(): array
    {
        $json = (string) $this->getAttribute('variables');
        return $json !== '' ? (json_decode($json, true) ?: []) : [];
    }
}

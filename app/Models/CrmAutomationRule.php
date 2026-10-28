<?php

/** Tourfecto - CRM Automation Rule Model (بند 12، 36) @version 1.0.0 */
class CrmAutomationRule extends Model
{
    protected $table = 'crm_automation_rules';
    protected $fillable = ['user_id', 'name', 'trigger_event', 'conditions', 'actions', 'is_active'];

    public function activeForUserAndEvent(int $userId, string $event): array
    {
        return $this->db->query(
            "SELECT * FROM crm_automation_rules WHERE user_id = ? AND trigger_event = ? AND is_active = 1 ORDER BY id ASC",
            [$userId, $event]
        );
    }

    public function allForUser(int $userId): array
    {
        return $this->where(['user_id' => $userId], ['created_at' => 'DESC']);
    }
}

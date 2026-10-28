<?php

/** Tourfecto - CRM Email Tracking Model (المرحلة 14 - G8) @version 1.0.0 */
class CrmEmailTracking extends Model
{
    protected $table = 'crm_email_trackings';
    protected $fillable = [
        'user_id', 'contact_id', 'message_id', 'token', 'email_subject',
        'sent_at', 'first_opened_at', 'last_opened_at', 'open_count',
        'ip_address', 'user_agent',
    ];

    public function findByToken(string $token): ?CrmEmailTracking
    {
        $rows = $this->db->query(
            "SELECT * FROM crm_email_trackings WHERE token = ? LIMIT 1",
            [$token]
        );
        if (empty($rows)) {
            return null;
        }
        $model = new static($rows[0]);
        $model->original = $rows[0];
        return $model;
    }

    public function forUser(int $userId, ?int $contactId = null, int $limit = 100): array
    {
        $sql = "SELECT * FROM crm_email_trackings WHERE user_id = ?";
        $params = [$userId];
        if ($contactId !== null) {
            $sql .= " AND contact_id = ?";
            $params[] = $contactId;
        }
        $sql .= " ORDER BY created_at DESC LIMIT " . (int) $limit;
        return $this->db->query($sql, $params);
    }
}

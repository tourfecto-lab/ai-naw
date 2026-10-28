<?php

/** Tourfecto - CRM Web Form Submission Model (المرحلة 15 - G11) @version 1.0.0 */
class CrmWebFormSubmission extends Model
{
    protected $table = 'crm_web_form_submissions';
    protected $fillable = [
        'user_id', 'web_form_id', 'contact_id', 'lead_id',
        'payload', 'ip_address', 'user_agent',
    ];

    /** إرسالات الحساب (اختياري: لتسجيل واحد) مع بيانات الـContact */
    public function forUser(int $userId, ?int $webFormId = null, int $limit = 100): array
    {
        $sql = "SELECT s.*, c.name AS contact_name, c.email AS contact_email
                FROM crm_web_form_submissions s
                LEFT JOIN crm_contacts c ON c.id = s.contact_id
                WHERE s.user_id = ?";
        $params = [$userId];
        if ($webFormId !== null) {
            $sql .= " AND s.web_form_id = ?";
            $params[] = $webFormId;
        }
        $sql .= " ORDER BY s.created_at DESC LIMIT " . (int) $limit;
        return $this->db->query($sql, $params);
    }
}

<?php

/**
 * Tourfecto - CRM Lead Model
 * @version 1.1.0
 *
 * ملاحظة: الحقول priority/score/score_reason جاهزة في القاعدة لاستخدامها
 * من AI Lead Scoring في مرحلة تالية (خارج نطاق هذه المرحلة الحالية -
 * راجع CHANGELOG). لا تُملأ تلقائيًا بأي قيمة وهمية هنا.
 */
class CrmLead extends Model
{
    protected $table = 'crm_leads';
    protected $fillable = [
        'contact_id', 'owner_user_id', 'source', 'interest', 'value', 'currency',
        'status', 'priority', 'score', 'score_reason', 'next_follow_up_at', 'last_engagement_at',
        'conv_probability', 'score_confidence', 'score_signals_json',
    ];

    /** كل الـLeads الخاصة بحساب معيّن (Tenant) عبر جهة الاتصال المالكة */
    public function allForUser(int $userId, int $limit = 200): array
    {
        return $this->db->query(
            "SELECT l.*, c.name AS contact_name, c.email AS contact_email, c.phone AS contact_phone, c.user_id AS tenant_user_id
             FROM `crm_leads` l
             JOIN `crm_contacts` c ON c.id = l.contact_id
             WHERE c.user_id = ?
             ORDER BY l.created_at DESC
             LIMIT ?",
            [$userId, $limit]
        );
    }

    /** Leads بدون Follow-up قادم محدد أو تاريخه فات (لتنبيهات المتابعة المتأخرة - بند 11) */
    public function overdueFollowUps(int $userId): array
    {
        return $this->db->query(
            "SELECT l.*, c.name AS contact_name, c.user_id AS tenant_user_id
             FROM `crm_leads` l
             JOIN `crm_contacts` c ON c.id = l.contact_id
             WHERE c.user_id = ?
               AND l.status NOT IN ('converted', 'disqualified')
               AND l.next_follow_up_at IS NOT NULL
               AND l.next_follow_up_at < NOW()
             ORDER BY l.next_follow_up_at ASC",
            [$userId]
        );
    }
}

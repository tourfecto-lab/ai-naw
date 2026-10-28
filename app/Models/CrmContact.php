<?php

/**
 * Tourfecto - CRM Contact Model
 * @version 1.1.0
 *
 * تحديث: تمت إضافة الحقول الفعلية للجدول بعد إنشائه (migration
 * 2026_08_08_000001)، وإضافة دوال مساعدة مربوطة بعزل Tenant عبر user_id
 * (نفس نمط باقي الموديولات في المشروع - راجع تعليق الـmigration).
 */
class CrmContact extends Model
{
    protected $table = 'crm_contacts';
    protected $fillable = [
        'user_id', 'agency_id', 'company_id', 'name', 'email', 'phone',
        'country', 'language', 'source', 'status', 'tags', 'notes',
    ];

    /** كل جهات الاتصال الخاصة بحساب معيّن (Tenant) */
    public function allForUser(int $userId, int $limit = 200): array
    {
        return $this->where(['user_id' => $userId], ['created_at' => 'DESC'], $limit);
    }

    /**
     * اكتشاف تكرار محتمل بنفس الإيميل أو الهاتف داخل نفس الحساب (بند 21).
     * لا يحذف أو يدمج تلقائيًا - يعرض المرشحين فقط.
     */
    public function findDuplicateCandidates(int $userId, ?string $email, ?string $phone, ?int $excludeId = null): array
    {
        if (empty($email) && empty($phone)) {
            return [];
        }
        $sql = "SELECT * FROM `crm_contacts` WHERE `user_id` = ? AND (";
        $params = [$userId];
        $conds = [];
        if (!empty($email)) {
            $conds[] = "`email` = ?";
            $params[] = $email;
        }
        if (!empty($phone)) {
            $conds[] = "`phone` = ?";
            $params[] = $phone;
        }
        $sql .= implode(' OR ', $conds) . ')';
        if ($excludeId) {
            $sql .= " AND `id` != ?";
            $params[] = $excludeId;
        }
        $sql .= " LIMIT 10";
        return $this->db->query($sql, $params);
    }
}

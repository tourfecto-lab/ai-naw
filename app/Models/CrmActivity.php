<?php

/** Tourfecto - CRM Activity Model (المرحلة 14 - G10) @version 1.0.0 */
class CrmActivity extends Model
{
    protected $table = 'crm_activities';
    protected $fillable = [
        'user_id', 'activity_type_id', 'related_type', 'related_id',
        'subject', 'notes', 'performed_at',
    ];

    /** أنشطة الحساب مع اسم النوع (اختياريًا فلترة حسب الكيان/النوع) */
    public function forUser(int $userId, ?string $relatedType = null, ?int $relatedId = null, ?int $activityTypeId = null, int $limit = 100): array
    {
        $sql = "SELECT a.*, t.name AS type_name, t.icon AS type_icon, t.color AS type_color
                FROM crm_activities a
                LEFT JOIN crm_activity_types t ON t.id = a.activity_type_id
                WHERE a.user_id = ?";
        $params = [$userId];
        if ($relatedType !== null && $relatedType !== '') {
            $sql .= " AND a.related_type = ?";
            $params[] = $relatedType;
            if ($relatedId !== null) {
                $sql .= " AND a.related_id = ?";
                $params[] = $relatedId;
            }
        }
        if ($activityTypeId !== null) {
            $sql .= " AND a.activity_type_id = ?";
            $params[] = $activityTypeId;
        }
        $sql .= " ORDER BY a.performed_at DESC, a.id DESC LIMIT " . (int) $limit;
        return $this->db->query($sql, $params);
    }
}

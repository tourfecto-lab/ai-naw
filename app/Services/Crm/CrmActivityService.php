<?php

/**
 * Tourfecto - CRM Custom Activity Types Service (المرحلة 14 - G10)
 * @version 1.0.0
 *
 * سد فجوة G10: "أنشطة/نتائج مخصصة" (مثل زيارات الموقع، مكالمات) مرتبطة
 * بأي كيان CRM - كل المنافسين الكبار يملكونها.
 *
 * Additive خالص: جدولان جديدان (crm_activity_types / crm_activities) فقط.
 * لا يعدّل أي منطق/جدول قائم. الأنواع الافتراضية عامة (user_id NULL)
 * للمستخدم أن يضيف عليها أنواعًا مخصصة خاصة بحسابه.
 */
class CrmActivityService
{
    /** أنواع الأنشطة المتاحة للحساب (عامة + مخصصة) */
    public function listTypes(int $userId): array
    {
        return (new CrmActivityType())->availableForUser($userId);
    }

    /** إنشاء نوع مخصص خاص بالحساب */
    public function createType(int $userId, array $data): CrmActivityType
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new Exception('اسم النشاط مطلوب', 422);
        }
        $key = trim((string) ($data['type_key'] ?? ''));
        if ($key === '') {
            $key = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $name));
        }
        if ($key === '') {
            throw new Exception('مفتاح النشاط مطلوب', 422);
        }
        $key = substr($key, 0, 50);

        $duplicate = $this->db()->query(
            "SELECT id FROM crm_activity_types WHERE type_key = ? AND (user_id IS NULL OR user_id = ?) LIMIT 1",
            [$key, $userId]
        );
        if (!empty($duplicate)) {
            throw new Exception('يوجد نوع بنفس المفتاح مسبقًا', 422);
        }

        $type = new CrmActivityType([
            'user_id' => $userId,
            'type_key' => $key,
            'name' => $name,
            'icon' => $data['icon'] ?? null,
            'color' => $data['color'] ?? '#6366f1',
            'is_system' => 0,
            'is_active' => !empty($data['is_active']) ? 1 : 1,
        ]);
        $type->save();

        ActivityLog::record('crm', 'activity_type.created', [
            'user_id' => $userId, 'subject_type' => 'crm_activity_types', 'subject_id' => (int) $type->getAttribute('id'),
        ]);
        return $type;
    }

    /** تحديث نوع مخصص (لا يمكن تعديل الأنواع النظامية العامة) */
    public function updateType(int $userId, int $typeId, array $data): CrmActivityType
    {
        $type = (new CrmActivityType())->findOwned($userId, $typeId);
        if (!$type) {
            throw new Exception('النوع غير موجود أو لا تملك صلاحية تعديله', 404);
        }
        $name = trim((string) ($data['name'] ?? $type->getAttribute('name')));
        if ($name === '') {
            throw new Exception('اسم النشاط مطلوب', 422);
        }
        $type->setAttribute('name', $name);
        if (array_key_exists('icon', $data)) {
            $type->setAttribute('icon', $data['icon'] ?: null);
        }
        if (array_key_exists('color', $data)) {
            $type->setAttribute('color', $data['color'] ?: '#6366f1');
        }
        if (array_key_exists('is_active', $data)) {
            $type->setAttribute('is_active', !empty($data['is_active']) ? 1 : 0);
        }
        $type->save();
        return $type;
    }

    /** حذف نوع مخصص (لا يمكن حذف الأنواع النظامية العامة) */
    public function deleteType(int $userId, int $typeId): bool
    {
        $type = (new CrmActivityType())->findOwned($userId, $typeId);
        if (!$type) {
            throw new Exception('النوع غير موجود أو لا تملك صلاحية حذفه', 404);
        }
        return $type->delete();
    }

    /** تسجيل نشاط جديد على كيان CRM */
    public function recordActivity(int $userId, array $data): CrmActivity
    {
        $subject = trim((string) ($data['subject'] ?? ''));
        if ($subject === '') {
            throw new Exception('عنوان النشاط مطلوب', 422);
        }
        $typeId = (int) ($data['activity_type_id'] ?? 0);
        if ($typeId <= 0) {
            throw new Exception('نوع النشاط مطلوب', 422);
        }
        $type = (new CrmActivityType())->findUsable($userId, $typeId);
        if (!$type) {
            throw new Exception('نوع النشاط غير موجود', 422);
        }

        $activity = new CrmActivity([
            'user_id' => $userId,
            'activity_type_id' => $typeId,
            'related_type' => $data['related_type'] ?? null,
            'related_id' => $data['related_id'] ?? null,
            'subject' => $subject,
            'notes' => $data['notes'] ?? null,
            'performed_at' => ($data['performed_at'] ?? '') !== '' ? $data['performed_at'] : date('Y-m-d H:i:s'),
        ]);
        $activity->save();

        ActivityLog::record('crm', 'activity.created', [
            'user_id' => $userId, 'subject_type' => 'crm_activities', 'subject_id' => (int) $activity->getAttribute('id'),
            'meta' => ['activity_type_id' => $typeId, 'related_type' => $activity->getAttribute('related_type'), 'related_id' => $activity->getAttribute('related_id')],
        ]);
        return $activity;
    }

    /** أنشطة الحساب (فلترة حسب الكيان/النوع) */
    public function listActivities(int $userId, ?string $relatedType = null, ?int $relatedId = null, ?int $activityTypeId = null, int $limit = 100): array
    {
        return (new CrmActivity())->forUser($userId, $relatedType, $relatedId, $activityTypeId, $limit);
    }

    /** حذف نشاط خاص بالحساب */
    public function deleteActivity(int $userId, int $activityId): bool
    {
        $rows = $this->db()->query(
            "SELECT id FROM crm_activities WHERE id = ? AND user_id = ? LIMIT 1",
            [$activityId, $userId]
        );
        if (empty($rows)) {
            throw new Exception('النشاط غير موجود', 404);
        }
        return $this->db()->exec("DELETE FROM crm_activities WHERE id = ? AND user_id = ?", [$activityId, $userId]);
    }

    /** توزيع الأنشطة حسب النوع (للرسوم البيانية) */
    public function distributionByType(int $userId): array
    {
        $rows = $this->db()->query(
            "SELECT t.name AS type_name, t.icon AS type_icon, t.color AS type_color, COUNT(a.id) AS cnt
             FROM crm_activity_types t
             LEFT JOIN crm_activities a ON a.activity_type_id = t.id AND a.user_id = ?
             WHERE t.user_id IS NULL OR t.user_id = ?
             GROUP BY t.id, t.name, t.icon, t.color
             ORDER BY cnt DESC",
            [$userId, $userId]
        );
        return $rows;
    }

    private function db()
    {
        return Database::getInstance();
    }
}

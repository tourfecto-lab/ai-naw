<?php

/**
 * Tourfecto - CRM Contact Lifecycle Service (المرحلة 13 - G6)
 * @version 1.0.0
 *
 * مراحل دورة حياة العميل (جديد/مؤهل/عميل/خامل/مفقود أو مراحل مخصصة)
 * منفصلة عن حالة الـLead - تفصل "حالة العلاقة" عن "مرحلة الفرصة".
 * الميزة يملكها كل المنافسين الكبار.
 *
 * Additive: عمود lifecycle_stage أُضيف على crm_contacts عبر migration
 * (إضافة خالصة لا تمس الاستعلامات القائمة) + جدول مراحل قابلة للتخصيص.
 * لا يعدّل أي منطق في CrmController الأصلي.
 */
class CrmLifecycleService
{
    /** المراحل المتاحة للحساب (افتراضية عامة + مخصصة) */
    public function listStages(int $userId): array
    {
        return (new CrmLifecycleStage())->availableForUser($userId);
    }

    /** تحديد مرحلة دورة حياة لجهة اتصال (بـ stage_key أو بتعريف مخصص) */
    public function setStage(int $userId, int $contactId, ?string $stageKey): CrmContact
    {
        $contact = (new CrmContact())->find($contactId);
        if (!$contact || (int) $contact->getAttribute('user_id') !== $userId) {
            throw new Exception('جهة الاتصال غير موجودة', 404);
        }

        if ($stageKey === null || $stageKey === '') {
            $contact->setAttribute('lifecycle_stage', null);
        } else {
            $stage = (new CrmLifecycleStage())->findByKey($userId, $stageKey);
            if (!$stage) {
                throw new Exception('مرحلة دورة حياة غير معروفة', 422);
            }
            $contact->setAttribute('lifecycle_stage', $stageKey);
        }
        $contact->save();

        ActivityLog::record('crm', 'contact.lifecycle_changed', [
            'user_id' => $userId, 'subject_type' => 'crm_contacts', 'subject_id' => $contactId,
            'meta' => ['lifecycle_stage' => $stageKey],
        ]);

        return $contact;
    }

    /** جهات الاتصال حسب المرحلة (فلترة دورة حياة) */
    public function contactsByStage(int $userId, ?string $stageKey = null): array
    {
        $sql = "SELECT * FROM crm_contacts WHERE user_id = ?";
        $params = [$userId];
        if ($stageKey !== null && $stageKey !== '') {
            $sql .= " AND lifecycle_stage = ?";
            $params[] = $stageKey;
        } else {
            $sql .= " AND lifecycle_stage IS NOT NULL";
        }
        $sql .= " ORDER BY lifecycle_stage ASC, updated_at DESC LIMIT 500";
        return $this->db()->query($sql, $params);
    }

    /** توزيع الحساب حسب المرحلة (للرسوم/التقارير) */
    public function distribution(int $userId): array
    {
        $rows = $this->db()->query(
            "SELECT lifecycle_stage, COUNT(*) AS total
             FROM crm_contacts
             WHERE user_id = ? AND lifecycle_stage IS NOT NULL
             GROUP BY lifecycle_stage",
            [$userId]
        );
        $stages = $this->listStages($userId);
        $stageNames = [];
        foreach ($stages as $stage) {
            $stageNames[$stage['stage_key']] = $stage['name'];
        }
        $out = [];
        foreach ($rows as $row) {
            $key = (string) $row['lifecycle_stage'];
            $out[] = [
                'stage_key' => $key,
                'name' => $stageNames[$key] ?? $key,
                'total' => (int) $row['total'],
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------
    // مراحل مخصصة (CRUD)
    // ------------------------------------------------------------

    public function createStage(int $userId, array $data): CrmLifecycleStage
    {
        $key = strtolower(trim((string) ($data['stage_key'] ?? '')));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key);
        $key = trim($key, '_');
        $name = trim((string) ($data['name'] ?? ''));

        if ($key === '') {
            throw new Exception('مفتاح المرحلة مطلوب (أحرف صغيرة وشرطات)', 422);
        }
        if ($name === '') {
            throw new Exception('اسم المرحلة مطلوب', 422);
        }
        if ((new CrmLifecycleStage())->findByKey($userId, $key)) {
            throw new Exception('يوجد مرحلة بنفس المفتاح (عامة أو خاصة بحسابك)', 422);
        }

        $stage = new CrmLifecycleStage([
            'user_id' => $userId,
            'stage_key' => $key,
            'name' => $name,
            'color' => (string) ($data['color'] ?? '#6366f1'),
            'sort_order' => (int) ($data['sort_order'] ?? 99),
            'is_system' => 0,
        ]);
        $stage->save();
        return $stage;
    }

    public function updateStage(int $userId, int $stageId, array $data): CrmLifecycleStage
    {
        $stage = (new CrmLifecycleStage())->findOwned($userId, $stageId);
        if (!$stage) {
            throw new Exception('المرحلة غير موجودة (لا يمكن تعديل مرحلة عامة)', 404);
        }
        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new Exception('اسم المرحلة مطلوب', 422);
            }
            $stage->setAttribute('name', $name);
        }
        if (isset($data['color'])) {
            $stage->setAttribute('color', (string) $data['color']);
        }
        if (isset($data['sort_order'])) {
            $stage->setAttribute('sort_order', (int) $data['sort_order']);
        }
        $stage->save();
        return $stage;
    }

    public function deleteStage(int $userId, int $stageId): bool
    {
        $stage = (new CrmLifecycleStage())->findOwned($userId, $stageId);
        if (!$stage) {
            throw new Exception('المرحلة غير موجودة (لا يمكن حذف مرحلة عامة)', 404);
        }
        // جهات الاتصال التي تستخدمها تُعاد لحالة null (بدون مرحلة)
        $this->db()->query(
            "UPDATE crm_contacts SET lifecycle_stage = NULL
             WHERE user_id = ? AND lifecycle_stage = ?",
            [$userId, $stage->getAttribute('stage_key')]
        );
        return $stage->delete();
    }

    private function db()
    {
        return Database::getInstance();
    }
}

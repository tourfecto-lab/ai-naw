<?php

/**
 * Tourfecto - CRM Contact Service
 * @version 1.0.0
 */
class CrmContactService
{
    public function create(int $userId, array $data): CrmContact
    {
        if (empty($data['name'])) {
            throw new Exception('اسم جهة الاتصال مطلوب');
        }
        $contact = new CrmContact([
            'user_id' => $userId,
            'company_id' => $data['company_id'] ?? null,
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'country' => $data['country'] ?? null,
            'language' => $data['language'] ?? null,
            'source' => $data['source'] ?? 'manual',
            'status' => $data['status'] ?? 'active',
            'tags' => isset($data['tags']) ? json_encode($data['tags'], JSON_UNESCAPED_UNICODE) : null,
            'notes' => $data['notes'] ?? null,
        ]);
        $contact->save();

        ActivityLog::record('crm', 'contact.created', [
            'user_id' => $userId, 'subject_type' => 'crm_contacts', 'subject_id' => (int) $contact->getAttribute('id'),
        ]);

        return $contact;
    }

    public function update(int $userId, int $contactId, array $data): CrmContact
    {
        $contact = $this->findOwned($userId, $contactId);
        $before = $contact->toArray();

        foreach (['name', 'email', 'phone', 'country', 'language', 'source', 'status', 'notes', 'company_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $contact->setAttribute($field, $data[$field]);
            }
        }
        if (array_key_exists('tags', $data)) {
            $contact->setAttribute('tags', json_encode($data['tags'], JSON_UNESCAPED_UNICODE));
        }
        $contact->save();

        ActivityLog::record('crm', 'contact.updated', [
            'user_id' => $userId, 'subject_type' => 'crm_contacts', 'subject_id' => $contactId,
            'meta' => ['before' => $before, 'after' => $contact->toArray()],
        ]);

        return $contact;
    }

    public function findOwned(int $userId, int $contactId): CrmContact
    {
        $contact = (new CrmContact())->find($contactId);
        if (!$contact || (int) $contact->getAttribute('user_id') !== $userId) {
            throw new Exception('جهة الاتصال غير موجودة', 404);
        }
        return $contact;
    }

    public function listForUser(int $userId, int $limit = 200): array
    {
        return (new CrmContact())->allForUser($userId, $limit);
    }

    /**
     * نسخة متقدمة بـFilters + Pagination حقيقي (بند 29، 37) - لا تُستبدل
     * listForUser() القديمة (لسه مُستخدمة في Customer 360/Export/CSV) حفاظًا
     * على التوافق، دي إضافة جديدة للواجهة الجديدة (قوائم قابلة للفلترة).
     *
     * $filters المدعومة: status, source, country, company_id, tag (يبحث
     * داخل tags JSON عبر LIKE بسيط - مش Full JSON query لتجنّب تعقيد غير
     * لازم)، search (على name/email/phone)، created_from/created_to
     * (YYYY-MM-DD)، last_activity_before (أيام - لقطاع "Inactive").
     */
    public function search(int $userId, array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['user_id = ?'];
        $params = [$userId];

        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['source'])) {
            $where[] = 'source = ?';
            $params[] = $filters['source'];
        }
        if (!empty($filters['country'])) {
            $where[] = 'country = ?';
            $params[] = $filters['country'];
        }
        if (!empty($filters['company_id'])) {
            $where[] = 'company_id = ?';
            $params[] = (int) $filters['company_id'];
        }
        if (!empty($filters['tag'])) {
            $where[] = 'tags LIKE ?';
            $params[] = '%"' . $filters['tag'] . '"%';
        }
        if (!empty($filters['created_from'])) {
            $where[] = 'created_at >= ?';
            $params[] = $filters['created_from'] . ' 00:00:00';
        }
        if (!empty($filters['created_to'])) {
            $where[] = 'created_at <= ?';
            $params[] = $filters['created_to'] . ' 23:59:59';
        }
        if (!empty($filters['last_activity_before_days'])) {
            $where[] = 'id NOT IN (
                SELECT DISTINCT subject_id FROM activity_logs
                WHERE subject_type = "crm_contacts" AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            )';
            $params[] = (int) $filters['last_activity_before_days'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(name LIKE ? OR email LIKE ? OR phone LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like);
        }
        // فلاتر إضافية مبنية على Leads/Deals المرتبطة (بند 19: قطاعات زي Hot
        // Lead/VIP محتاجة تربط ببيانات الفرص الفعلية، مش بيانات جهة الاتصال
        // نفسها بس) - عبر EXISTS subquery بدل JOIN عشان مايكررش الصفوف.
        if (!empty($filters['min_lead_score'])) {
            $where[] = 'id IN (SELECT contact_id FROM crm_leads WHERE score >= ?)';
            $params[] = (int) $filters['min_lead_score'];
        }
        if (!empty($filters['max_lead_score'])) {
            $where[] = 'id IN (SELECT contact_id FROM crm_leads WHERE score <= ? AND score > 0)';
            $params[] = (int) $filters['max_lead_score'];
        }
        if (!empty($filters['has_open_deal'])) {
            $where[] = 'id IN (SELECT contact_id FROM crm_deals WHERE status = "open" AND contact_id IS NOT NULL)';
        }
        if (!empty($filters['min_deal_value'])) {
            $where[] = 'id IN (SELECT contact_id FROM crm_deals WHERE value >= ? AND contact_id IS NOT NULL)';
            $params[] = (float) $filters['min_deal_value'];
        }

        $whereSql = implode(' AND ', $where);
        $db = Database::getInstance();

        $total = (int) ($db->query("SELECT COUNT(*) AS c FROM crm_contacts WHERE {$whereSql}", $params)[0]['c'] ?? 0);

        $items = $db->query(
            "SELECT * FROM crm_contacts WHERE {$whereSql} ORDER BY created_at DESC LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])
        );

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    /** بند 21: اكتشاف تكرار */
    public function duplicateCandidates(int $userId, ?string $email, ?string $phone, ?int $excludeId = null): array
    {
        return (new CrmContact())->findDuplicateCandidates($userId, $email, $phone, $excludeId);
    }

    /**
     * بند 22: دمج جهتَي اتصال - ينقل كل الـLeads/Deals/Tasks/Notes/Meetings
     * من $duplicateId إلى $primaryId قبل حذف السجل المكرر، بدون فقد بيانات.
     */
    public function merge(int $userId, int $primaryId, int $duplicateId): CrmContact
    {
        if ($primaryId === $duplicateId) {
            throw new Exception('لا يمكن دمج جهة الاتصال في نفسها');
        }
        $primary = $this->findOwned($userId, $primaryId);
        $duplicate = $this->findOwned($userId, $duplicateId);

        $db = Database::getInstance();
        $db->query("UPDATE `crm_leads` SET `contact_id` = ? WHERE `contact_id` = ?", [$primaryId, $duplicateId]);
        $db->query("UPDATE `crm_deals` SET `contact_id` = ? WHERE `contact_id` = ?", [$primaryId, $duplicateId]);
        // قاعدة البيانات الفعلية بتخزّن 'contact' (enum)، والقيمة القديمة 'crm_contacts' كانت بتخلي مهام وملاحظات المكرر تضيع بعد الدمج
        $db->query("UPDATE `crm_tasks` SET `related_id` = ? WHERE `related_type` IN ('contact', 'crm_contacts') AND `related_id` = ?", [$primaryId, $duplicateId]);
        $db->query("UPDATE `crm_notes` SET `related_id` = ? WHERE `related_type` IN ('contact', 'crm_contacts') AND `related_id` = ?", [$primaryId, $duplicateId]);
        $db->query("UPDATE `crm_meetings` SET `contact_id` = ? WHERE `contact_id` = ?", [$primaryId, $duplicateId]);

        $duplicate->delete();

        ActivityLog::record('crm', 'contact.merged', [
            'user_id' => $userId, 'subject_type' => 'crm_contacts', 'subject_id' => $primaryId,
            'meta' => ['merged_from' => $duplicateId],
        ]);

        return $primary;
    }
}

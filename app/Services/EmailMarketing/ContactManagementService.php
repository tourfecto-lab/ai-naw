<?php

/**
 * Tourfecto - Email Marketing: Contact Management Service
 * @version 1.0.0
 *
 * إدارة جهات الاتصال المتقدمة (منافس Brevo/Mailchimp):
 *   - حقول مخصصة (إنشاء/تعديل/حذف + حقول نظامية تلقائية لكل حساب)
 *   - قيم الحقول لكل مشترك + حفظ دفعة
 *   - وسوم (CRUD + ربط/فك من المشتركين)
 *   - شرائح ديناميكية (شروط JSON تُترجم لـ SQL وتُقيَّم لحظيًا)
 *   - قائمة ممنوعين/ارتدادات/شكاوى
 *   - استيراد CSV/JSON مع رسم خرائط الحقول + تصدير كامل
 */
class ContactManagementService
{
    /** @var Database */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ============================ Custom Fields ============================

    /**
     * ضمان وجود الحقول النظامية لحساب (تُنشأ مرة واحدة عند أول استخدام)
     */
    public function ensureSystemFields(int $userId): void
    {
        $count = $this->db->query(
            "SELECT COUNT(*) AS total FROM email_custom_fields WHERE user_id = ? AND is_system = 1",
            [$userId]
        );
        if ((int) ($count[0]['total'] ?? 0) > 0) {
            return;
        }

        $sort = 1;
        foreach (EmailCustomField::systemFields() as $field) {
            $this->db->query(
                "INSERT INTO email_custom_fields
                    (user_id, name, label, field_type, is_system, sort_order)
                 VALUES (?, ?, ?, ?, 1, ?)",
                [$userId, $field['name'], $field['label'], $field['field_type'], $sort++]
            );
        }
    }

    public function customFields(int $userId): array
    {
        $this->ensureSystemFields($userId);
        return $this->db->query(
            "SELECT * FROM email_custom_fields WHERE user_id = ? ORDER BY sort_order ASC, id ASC",
            [$userId]
        );
    }

    public function createCustomField(int $userId, array $data): array
    {
        $name = strtolower(trim((string) ($data['name'] ?? '')));
        $label = trim((string) ($data['label'] ?? ''));
        $type = (string) ($data['field_type'] ?? EmailCustomField::TYPE_TEXT);

        if ($name === '' || $label === '') {
            return ['success' => false, 'error' => 'اسم الحقل وتسميته مطلوبان'];
        }
        if (!in_array($type, EmailCustomField::VALID_TYPES, true)) {
            return ['success' => false, 'error' => 'نوع حقل غير صالح'];
        }
        // اسم برمجي آمن (snake_case)
        $name = preg_replace('/[^a-z0-9_]/', '_', $name);
        $name = trim($name, '_');
        if ($name === '') {
            return ['success' => false, 'error' => 'اسم الحقل غير صالح'];
        }

        $exists = $this->db->query(
            "SELECT id FROM email_custom_fields WHERE user_id = ? AND name = ?",
            [$userId, $name]
        );
        if (!empty($exists)) {
            return ['success' => false, 'error' => 'حقل بنفس الاسم موجود مسبقًا'];
        }

        $options = null;
        if (in_array($type, [EmailCustomField::TYPE_SELECT, EmailCustomField::TYPE_MULTI_SELECT], true)) {
            $options = !empty($data['options'])
                ? json_encode(array_values(array_filter(array_map('trim', explode(',', (string) $data['options'])))), JSON_UNESCAPED_UNICODE)
                : json_encode([]);
        }

        $maxSort = $this->db->query(
            "SELECT MAX(sort_order) AS mx FROM email_custom_fields WHERE user_id = ?",
            [$userId]
        );
        $sort = (int) ($maxSort[0]['mx'] ?? 0) + 1;

        $id = $this->db->query(
            "INSERT INTO email_custom_fields
                (user_id, name, label, field_type, options, is_required, is_system, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?)",
            [$userId, $name, $label, $type, $options, !empty($data['is_required']) ? 1 : 0, $sort]
        );

        return $id ? ['success' => true, 'id' => (int) $id] : ['success' => false, 'error' => 'تعذر إنشاء الحقل'];
    }

    public function updateCustomField(int $userId, int $fieldId, array $data): array
    {
        $field = $this->db->query(
            "SELECT * FROM email_custom_fields WHERE id = ? AND user_id = ?",
            [$fieldId, $userId]
        );
        if (empty($field)) {
            return ['success' => false, 'error' => 'الحقل غير موجود'];
        }
        $field = $field[0];
        if ((int) ($field['is_system'] ?? 0) === 1) {
            return ['success' => false, 'error' => 'لا يمكن تعديل الحقول النظامية'];
        }

        $sql = "UPDATE email_custom_fields SET updated_at = NOW()";
        $params = [];
        if (isset($data['label'])) {
            $sql .= ", label = ?";
            $params[] = trim((string) $data['label']);
        }
        if (array_key_exists('is_required', $data)) {
            $sql .= ", is_required = ?";
            $params[] = !empty($data['is_required']) ? 1 : 0;
        }
        if (isset($data['field_type']) && in_array($data['field_type'], EmailCustomField::VALID_TYPES, true)) {
            $sql .= ", field_type = ?";
            $params[] = $data['field_type'];
        }
        if (array_key_exists('options', $data)) {
            $options = json_encode(
                array_values(array_filter(array_map('trim', explode(',', (string) $data['options'])))),
                JSON_UNESCAPED_UNICODE
            );
            $sql .= ", options = ?";
            $params[] = $options;
        }
        $sql .= " WHERE id = ? AND user_id = ?";
        $params[] = $fieldId;
        $params[] = $userId;

        $this->db->exec($sql, $params);
        return ['success' => true];
    }

    public function deleteCustomField(int $userId, int $fieldId): array
    {
        $field = $this->db->query(
            "SELECT is_system FROM email_custom_fields WHERE id = ? AND user_id = ?",
            [$fieldId, $userId]
        );
        if (empty($field)) {
            return ['success' => false, 'error' => 'الحقل غير موجود'];
        }
        if ((int) ($field[0]['is_system'] ?? 0) === 1) {
            return ['success' => false, 'error' => 'لا يمكن حذف الحقول النظامية'];
        }
        $this->db->exec("DELETE FROM email_custom_fields WHERE id = ? AND user_id = ?", [$fieldId, $userId]);
        return ['success' => true];
    }

    // ============================ Custom Values ============================

    /**
     * خريطة field_id => value لمشترك معيّن
     */
    public function subscriberCustomValues(int $userId, int $subscriberId): array
    {
        $rows = $this->db->query(
            "SELECT scv.field_id, scv.value, cf.name, cf.field_type
             FROM email_subscriber_custom_values scv
             JOIN email_custom_fields cf ON cf.id = scv.field_id AND cf.user_id = ?
             WHERE scv.subscriber_id = ?
             ORDER BY cf.sort_order ASC",
            [$userId, $subscriberId]
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['field_id']] = [
                'value' => $row['value'],
                'name' => $row['name'],
                'field_type' => $row['field_type'],
            ];
        }
        return $map;
    }

    /**
     * حفظ قيم الحقول لمشترك. $values مفاتيحها اسم الحقل (name) أو field_id
     */
    public function saveCustomValues(int $userId, int $subscriberId, array $values): void
    {
        $sub = (new EmailSubscriber())->find($subscriberId);
        if (!$sub || (int) $sub->getAttribute('user_id') !== $userId) {
            return;
        }

        $fields = $this->db->query(
            "SELECT id, name, field_type FROM email_custom_fields WHERE user_id = ?",
            [$userId]
        );
        foreach ($fields as $field) {
            $fieldId = (int) $field['id'];
            $key = array_key_exists($field['name'], $values)
                ? $field['name']
                : (array_key_exists($fieldId, $values) ? $fieldId : null);
            if ($key === null) {
                continue;
            }
            $raw = $values[$key];
            $value = is_array($raw) ? implode(',', array_map('trim', $raw)) : trim((string) $raw);
            if ($field['field_type'] === EmailCustomField::TYPE_BOOLEAN) {
                $value = in_array(strtolower((string) $value), ['1', 'true', 'yes', 'نعم'], true) ? '1' : '0';
            }
            if ($value === '') {
                $this->db->exec(
                    "DELETE FROM email_subscriber_custom_values WHERE subscriber_id = ? AND field_id = ?",
                    [$subscriberId, $fieldId]
                );
                continue;
            }
            $this->db->query(
                "INSERT INTO email_subscriber_custom_values (subscriber_id, field_id, value)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)",
                [$subscriberId, $fieldId, $value]
            );
        }

        // تحديث attributes (نسخة مرنة للـ API القديم)
        $attributes = json_decode((string) ($sub->getAttribute('attributes') ?? '{}'), true) ?: [];
        foreach ($values as $k => $v) {
            if (is_string($k) && !is_numeric($k)) {
                $attributes[$k] = is_array($v) ? implode(',', $v) : $v;
            }
        }
        $sub->setAttribute('attributes', json_encode($attributes, JSON_UNESCAPED_UNICODE));
        $sub->save();
    }

    // ============================ Tags ============================

    public function tags(int $userId): array
    {
        return $this->db->query(
            "SELECT t.*,
                    (SELECT COUNT(*) FROM email_subscriber_tag st WHERE st.tag_id = t.id) AS subscriber_count
             FROM email_tags t
             WHERE t.user_id = ?
             ORDER BY t.name ASC",
            [$userId]
        );
    }

    public function createTag(int $userId, string $name, ?string $color = null): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['success' => false, 'error' => 'اسم الوسم مطلوب'];
        }
        $exists = $this->db->query(
            "SELECT id FROM email_tags WHERE user_id = ? AND name = ?",
            [$userId, $name]
        );
        if (!empty($exists)) {
            return ['success' => false, 'error' => 'وسم بنفس الاسم موجود'];
        }
        $id = $this->db->query(
            "INSERT INTO email_tags (user_id, name, color) VALUES (?, ?, ?)",
            [$userId, $name, $color !== null ? trim($color) : null]
        );
        return $id ? ['success' => true, 'id' => (int) $id] : ['success' => false, 'error' => 'تعذر إنشاء الوسم'];
    }

    public function updateTag(int $userId, int $tagId, array $data): array
    {
        $tag = (new EmailTag())->find($tagId);
        if (!$tag || (int) $tag->getAttribute('user_id') !== $userId) {
            return ['success' => false, 'error' => 'الوسم غير موجود'];
        }
        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return ['success' => false, 'error' => 'اسم الوسم مطلوب'];
            }
            $dup = $this->db->query(
                "SELECT id FROM email_tags WHERE user_id = ? AND name = ? AND id != ?",
                [$userId, $name, $tagId]
            );
            if (!empty($dup)) {
                return ['success' => false, 'error' => 'وسم بنفس الاسم موجود'];
            }
            $tag->setAttribute('name', $name);
        }
        if (array_key_exists('color', $data)) {
            $tag->setAttribute('color', $data['color'] !== null ? trim((string) $data['color']) : null);
        }
        $tag->save();
        return ['success' => true];
    }

    public function deleteTag(int $userId, int $tagId): array
    {
        $tag = (new EmailTag())->find($tagId);
        if (!$tag || (int) $tag->getAttribute('user_id') !== $userId) {
            return ['success' => false, 'error' => 'الوسم غير موجود'];
        }
        $tag->delete();
        return ['success' => true];
    }

    public function subscriberTags(int $userId, int $subscriberId): array
    {
        return $this->db->query(
            "SELECT t.* FROM email_tags t
             JOIN email_subscriber_tag st ON st.tag_id = t.id
             WHERE st.subscriber_id = ? AND t.user_id = ?
             ORDER BY t.name ASC",
            [$subscriberId, $userId]
        );
    }

    public function assignTag(int $userId, int $subscriberId, int $tagId): array
    {
        $tag = (new EmailTag())->find($tagId);
        $sub = (new EmailSubscriber())->find($subscriberId);
        if (!$tag || (int) $tag->getAttribute('user_id') !== $userId) {
            return ['success' => false, 'error' => 'الوسم غير موجود'];
        }
        if (!$sub || (int) $sub->getAttribute('user_id') !== $userId) {
            return ['success' => false, 'error' => 'المشترك غير موجود'];
        }
        $this->db->query(
            "INSERT IGNORE INTO email_subscriber_tag (subscriber_id, tag_id) VALUES (?, ?)",
            [$subscriberId, $tagId]
        );
        // خطاف الأتمتة: "عند إضافة وسم"
        if (class_exists('EmailAutomationService')) {
            try {
                (new EmailAutomationService())->handleEvent($userId, 'tag_added', [
                    'subscriber_id' => $subscriberId,
                    'tag_id' => $tagId,
                    'tag_name' => (string) $tag->getAttribute('name'),
                ]);
            } catch (\Throwable $e) {
                // لا نفشل إسناد الوسم بسبب الأتمتة
            }
        }
        return ['success' => true];
    }

    public function removeTag(int $userId, int $subscriberId, int $tagId): array
    {
        $this->db->exec(
            "DELETE FROM email_subscriber_tag WHERE subscriber_id = ? AND tag_id = ?",
            [$subscriberId, $tagId]
        );
        return ['success' => true];
    }

    /**
     * تطبيق وسوم بالاسم على مشترك (مستخدم في الاستيراد والأتمتة)
     */
    public function applyTagByName(int $userId, int $subscriberId, string $tagName): void
    {
        $tagName = trim($tagName);
        if ($tagName === '') {
            return;
        }
        $tag = $this->db->query(
            "SELECT id FROM email_tags WHERE user_id = ? AND name = ?",
            [$userId, $tagName]
        );
        if (empty($tag)) {
            $tagId = $this->db->query(
                "INSERT INTO email_tags (user_id, name) VALUES (?, ?)",
                [$userId, $tagName]
            );
        } else {
            $tagId = (int) $tag[0]['id'];
        }
        $this->db->query(
            "INSERT IGNORE INTO email_subscriber_tag (subscriber_id, tag_id) VALUES (?, ?)",
            [$subscriberId, $tagId]
        );
        // خطاف الأتمتة: "عند إضافة وسم"
        if (class_exists('EmailAutomationService')) {
            try {
                (new EmailAutomationService())->handleEvent($userId, 'tag_added', [
                    'subscriber_id' => $subscriberId,
                    'tag_id' => $tagId,
                    'tag_name' => $tagName,
                ]);
            } catch (\Throwable $e) {
                // لا نفشل تطبيق الوسم بسبب الأتمتة
            }
        }
    }

    // ============================ Segments ============================

    public function segments(int $userId): array
    {
        return $this->db->query(
            "SELECT * FROM email_segments WHERE user_id = ? ORDER BY updated_at DESC",
            [$userId]
        );
    }

    public function createSegment(int $userId, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'error' => 'اسم الشريحة مطلوب'];
        }
        $conditions = $this->normalizeConditions($data['conditions'] ?? []);
        if (empty($conditions)) {
            return ['success' => false, 'error' => 'أضف شرطًا واحدًا على الأقل'];
        }

        $segment = new EmailSegment([
            'user_id' => $userId,
            'name' => $name,
            'description' => isset($data['description']) ? trim((string) $data['description']) : null,
            'conditions' => json_encode($conditions, JSON_UNESCAPED_UNICODE),
            'match_all' => !empty($data['match_all']) ? 1 : 0,
            'subscriber_count' => 0,
        ]);
        $id = $segment->save();
        if ($id) {
            $this->refreshSegmentCount($userId, (int) $id);
        }
        return $id ? ['success' => true, 'id' => (int) $id] : ['success' => false, 'error' => 'تعذر إنشاء الشريحة'];
    }

    public function updateSegment(int $userId, int $segmentId, array $data): array
    {
        $segment = (new EmailSegment())->find($segmentId);
        if (!$segment || (int) $segment->getAttribute('user_id') !== $userId) {
            return ['success' => false, 'error' => 'الشريحة غير موجودة'];
        }
        if (isset($data['name'])) {
            if (trim((string) $data['name']) === '') {
                return ['success' => false, 'error' => 'اسم الشريحة مطلوب'];
            }
            $segment->setAttribute('name', trim((string) $data['name']));
        }
        if (array_key_exists('description', $data)) {
            $segment->setAttribute('description', $data['description'] !== null ? trim((string) $data['description']) : null);
        }
        if (array_key_exists('match_all', $data)) {
            $segment->setAttribute('match_all', !empty($data['match_all']) ? 1 : 0);
        }
        if (array_key_exists('conditions', $data)) {
            $conditions = $this->normalizeConditions($data['conditions']);
            if (empty($conditions)) {
                return ['success' => false, 'error' => 'أضف شرطًا واحدًا على الأقل'];
            }
            $segment->setAttribute('conditions', json_encode($conditions, JSON_UNESCAPED_UNICODE));
        }
        $segment->save();
        $this->refreshSegmentCount($userId, $segmentId);
        return ['success' => true];
    }

    public function deleteSegment(int $userId, int $segmentId): array
    {
        $segment = (new EmailSegment())->find($segmentId);
        if (!$segment || (int) $segment->getAttribute('user_id') !== $userId) {
            return ['success' => false, 'error' => 'الشريحة غير موجودة'];
        }
        $segment->delete();
        return ['success' => true];
    }

    /**
     * تقييم شريحة: يُرجع ids + count + بيانات للمعاينة
     */
    public function evaluateSegment(int $userId, int $segmentId, array $extraConditions = [], int $limit = 0): array
    {
        $segment = (new EmailSegment())->find($segmentId);
        if (!$segment || (int) $segment->getAttribute('user_id') !== $userId) {
            return ['success' => false, 'error' => 'الشريحة غير موجودة'];
        }

        $conditions = json_decode((string) $segment->getAttribute('conditions'), true) ?: [];
        if (!empty($extraConditions)) {
            $conditions = array_merge($conditions, $extraConditions);
        }
        if (empty($conditions)) {
            return ['success' => true, 'ids' => [], 'count' => 0, 'data' => []];
        }

        $sqlParts = [];
        $params = [];
        foreach ($conditions as $cond) {
            $part = $this->buildConditionSql($userId, $cond, $params);
            if ($part !== null) {
                $sqlParts[] = $part;
            }
        }
        if (empty($sqlParts)) {
            return ['success' => true, 'ids' => [], 'count' => 0, 'data' => []];
        }

        $matchAll = (int) $segment->getAttribute('match_all') === 1;
        $glue = $matchAll ? ' AND ' : ' OR ';
        $where = implode($glue, $sqlParts);

        // بند 2 (Double Opt-In): أي جمهور/شريحة يستثني pending_optin — اللي
        // لسه ما أكّدش بريده لا يظهر في شرائح الحملات إطلاقًا.
        $baseSql = "SELECT DISTINCT s.id FROM email_subscribers s WHERE s.user_id = ? AND s.status != 'pending_optin' AND ({$where})";
        $paramsBase = array_merge([$userId], $params);

        $countRow = $this->db->query(
            "SELECT COUNT(*) AS total FROM ({$baseSql}) AS seg",
            $paramsBase
        );
        $count = (int) ($countRow[0]['total'] ?? 0);

        $sql = $baseSql;
        if ($limit > 0) {
            $sql .= " LIMIT " . (int) $limit;
        }
        $ids = array_column($this->db->query($sql, $paramsBase), 'id');

        $data = [];
        if (!empty($ids)) {
            $in = implode(',', array_map('intval', $ids));
            $data = $this->db->query(
                "SELECT s.id, s.email, s.name, s.status, s.created_at, s.engagement_score
                 FROM email_subscribers s WHERE s.id IN ({$in}) ORDER BY s.created_at DESC"
            );
        }

        return ['success' => true, 'ids' => array_map('intval', $ids), 'count' => $count, 'data' => $data];
    }

    public function refreshSegmentCount(int $userId, int $segmentId): void
    {
        $result = $this->evaluateSegment($userId, $segmentId);
        $this->db->exec(
            "UPDATE email_segments SET subscriber_count = ? WHERE id = ?",
            [(int) ($result['count'] ?? 0), $segmentId]
        );
    }

    /**
     * ترجمة شرط JSON واحد لجزء SQL مع إضافة معاملاته إلى $params
     */
    private function buildConditionSql(int $userId, array $cond, array &$params): ?string
    {
        $field = (string) ($cond['field'] ?? '');
        $operator = (string) ($cond['operator'] ?? 'is');
        $value = $cond['value'] ?? '';

        // حقول قاعدة المشترك الأساسية
        $baseMap = [
            'email' => 's.email',
            'name' => 's.name',
            'status' => 's.status',
            'language' => 's.language',
            'created_at' => 'DATE(s.created_at)',
            'engagement_score' => 's.engagement_score',
        ];

        // عمليات الحقول النصية الأساسية
        $textOps = ['is' => '=', 'is_not' => '!=', 'contains' => 'LIKE', 'not_contains' => 'NOT LIKE',
            'starts_with' => 'LIKE', 'ends_with' => 'LIKE'];
        $numOps = ['greater_than' => '>', 'less_than' => '<', 'is' => '=', 'is_not' => '!='];

        switch ($operator) {
            case 'has_tag':
                $params[] = $value;
                return "EXISTS (SELECT 1 FROM email_subscriber_tag t1 JOIN email_tags t2 ON t2.id = t1.tag_id AND t2.user_id = {$userId} WHERE t1.subscriber_id = s.id AND t2.name = ?)";
            case 'not_has_tag':
                $params[] = $value;
                return "NOT EXISTS (SELECT 1 FROM email_subscriber_tag t1 JOIN email_tags t2 ON t2.id = t1.tag_id AND t2.user_id = {$userId} WHERE t1.subscriber_id = s.id AND t2.name = ?)";
            case 'in_list':
                $params[] = (int) $value;
                return "EXISTS (SELECT 1 FROM email_list_subscriber l1 WHERE l1.subscriber_id = s.id AND l1.list_id = ?)";
            case 'not_in_list':
                $params[] = (int) $value;
                return "NOT EXISTS (SELECT 1 FROM email_list_subscriber l1 WHERE l1.subscriber_id = s.id AND l1.list_id = ?)";
            case 'opened':
                return "EXISTS (SELECT 1 FROM email_campaign_recipients r1 WHERE r1.subscriber_id = s.id AND r1.status IN ('opened','clicked'))";
            case 'not_opened':
                return "NOT EXISTS (SELECT 1 FROM email_campaign_recipients r1 WHERE r1.subscriber_id = s.id AND r1.status IN ('opened','clicked'))";
            case 'clicked':
                return "EXISTS (SELECT 1 FROM email_campaign_recipients r1 WHERE r1.subscriber_id = s.id AND r1.status = 'clicked')";
            case 'not_clicked':
                return "NOT EXISTS (SELECT 1 FROM email_campaign_recipients r1 WHERE r1.subscriber_id = s.id AND r1.status = 'clicked')";
        }

        // الحقول المخصصة: custom:name
        if (strpos($field, 'custom:') === 0) {
            $fieldName = substr($field, 7);
            $fid = $this->db->query(
                "SELECT id, field_type FROM email_custom_fields WHERE user_id = ? AND name = ?",
                [$userId, $fieldName]
            );
            if (empty($fid)) {
                return null;
            }
            $fid = (int) $fid[0]['id'];
            $col = "scv{$fid}.value";
            $join = "EXISTS (SELECT 1 FROM email_subscriber_custom_values scv{$fid} WHERE scv{$fid}.subscriber_id = s.id AND scv{$fid}.field_id = {$fid} AND {$col} ";
            if (isset($textOps[$operator])) {
                $params[] = $value;
                switch ($operator) {
                    case 'contains':
                        $params[count($params) - 1] = '%' . $value . '%';
                        break;
                    case 'starts_with':
                        $params[count($params) - 1] = $value . '%';
                        break;
                    case 'ends_with':
                        $params[count($params) - 1] = '%' . $value;
                        break;
                }
                return $join . $textOps[$operator] . " ?)";
            }
            if ($operator === 'is_empty') {
                return $join . " = '')";
            }
            if ($operator === 'is_not_empty') {
                return $join . " != '')";
            }
            return null;
        }

        // الحقول الأساسية
        if (isset($baseMap[$field])) {
            $col = $baseMap[$field];
            if (isset($textOps[$operator])) {
                $params[] = $value;
                switch ($operator) {
                    case 'contains':
                        $params[count($params) - 1] = '%' . $value . '%';
                        break;
                    case 'starts_with':
                        $params[count($params) - 1] = $value . '%';
                        break;
                    case 'ends_with':
                        $params[count($params) - 1] = '%' . $value;
                        break;
                }
                return "{$col} " . $textOps[$operator] . " ?";
            }
            if ($field === 'created_at' && in_array($operator, ['before', 'after'], true)) {
                $params[] = date('Y-m-d', strtotime((string) $value));
                return "{$col} " . ($operator === 'before' ? '<' : '>') . " ?";
            }
            if (in_array($operator, ['greater_than', 'less_than'], true) && isset($numOps[$operator])) {
                $params[] = $value;
                return "{$col} " . $numOps[$operator] . " ?";
            }
            if ($operator === 'is_empty') {
                return "({$col} IS NULL OR {$col} = '')";
            }
            if ($operator === 'is_not_empty') {
                return "({$col} IS NOT NULL AND {$col} != '')";
            }
        }

        return null;
    }

    private function normalizeConditions($conditions): array
    {
        if (is_string($conditions)) {
            $decoded = json_decode($conditions, true);
            $conditions = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($conditions)) {
            return [];
        }
        $out = [];
        foreach ($conditions as $cond) {
            if (!is_array($cond) || empty($cond['field']) || empty($cond['operator'])) {
                continue;
            }
            $out[] = [
                'field' => (string) $cond['field'],
                'operator' => (string) $cond['operator'],
                'value' => $cond['value'] ?? '',
            ];
        }
        return $out;
    }

    // ============================ Suppressions ============================

    public function suppressions(int $userId, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $where = 'user_id = ?';
        $params = [$userId];
        if (!empty($filters['type'])) {
            $where .= ' AND type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['q'])) {
            $where .= ' AND email LIKE ?';
            $params[] = '%' . $filters['q'] . '%';
        }

        $countRow = $this->db->query("SELECT COUNT(*) AS total FROM email_suppressions WHERE {$where}", $params);
        $total = (int) ($countRow[0]['total'] ?? 0);

        $offset = (max(1, $page) - 1) * $perPage;
        $rows = $this->db->query(
            "SELECT * FROM email_suppressions WHERE {$where} ORDER BY suppressed_at DESC
             LIMIT " . (int) $perPage . " OFFSET " . (int) $offset,
            $params
        );

        return ['data' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function addSuppression(int $userId, string $email, string $type = 'manual', ?string $reason = null): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'بريد إلكتروني غير صالح'];
        }
        if (!in_array($type, EmailSuppression::VALID_TYPES, true)) {
            $type = 'manual';
        }
        $this->db->query(
            "INSERT INTO email_suppressions (user_id, email, type, reason, source)
             VALUES (?, ?, ?, ?, 'manual')
             ON DUPLICATE KEY UPDATE type = VALUES(type), reason = VALUES(reason), suppressed_at = NOW()",
            [$userId, $email, $type, $reason]
        );
        $q=$this->db->getConnection()->prepare("UPDATE email_campaign_recipients r JOIN email_campaigns c ON c.id=r.campaign_id SET r.status='unsubscribed' WHERE c.user_id=? AND LOWER(r.email)=? AND r.status IN ('pending','failed')");$q->execute([$userId,$email]);
        // إنهاء حالة المشترك في القوائم (لو موجود)
        $sub = (new EmailSubscriber())->where(['user_id' => $userId, 'email' => $email]);
        if (!empty($sub)) {
            $sub[0]->setAttribute('status', 'unsubscribed');
            $sub[0]->setAttribute('unsubscribed_at', date('Y-m-d H:i:s'));
            $sub[0]->save();
        }
        return ['success' => true];
    }

    public function removeSuppression(int $userId, int $id): array
    {
        $this->db->exec(
            "DELETE FROM email_suppressions WHERE id = ? AND user_id = ?",
            [$id, $userId]
        );
        return ['success' => true];
    }

    /**
     * إعادة حساب درجة تفاعل المشترك (engagement_score 0-100) من أحداث حقيقية:
     * فتح/كليك في حملات (email_campaign_recipients) + رسائل الأتمتة
     * (email_automation_logs). كل فتح +20 وكل كليك +30 حتى سقف 100.
     * تعيد الدرجة المحسوبة وتحدّث العمود. (G9 — كانت الدرجة صفرًا دائمًا).
     */
    public function recomputeEngagementScore(int $userId, int $subscriberId): int
    {
        $opened = 0;
        $clicked = 0;

        $campaignRows = $this->db->query(
            "SELECT COUNT(*) AS opened,
                    SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked
             FROM email_campaign_recipients r
             JOIN email_campaigns c ON c.id = r.campaign_id
             WHERE c.user_id = ? AND r.subscriber_id = ?",
            [$userId, $subscriberId]
        );
        if (!empty($campaignRows)) {
            $opened += (int) ($campaignRows[0]['opened'] ?? 0);
            $clicked += (int) ($campaignRows[0]['clicked'] ?? 0);
        }

        $autoRows = $this->db->query(
            "SELECT COUNT(*) AS opened,
                    SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked
             FROM email_automation_logs
             WHERE user_id = ? AND subscriber_id = ?",
            [$userId, $subscriberId]
        );
        if (!empty($autoRows)) {
            $opened += (int) ($autoRows[0]['opened'] ?? 0);
            $clicked += (int) ($autoRows[0]['clicked'] ?? 0);
        }

        $score = min(100, $opened * 20 + $clicked * 30);
        $this->db->query(
            "UPDATE email_subscribers SET engagement_score = ? WHERE id = ? AND user_id = ?",
            [$score, $subscriberId, $userId]
        );
        return $score;
    }

    /**
     * هل البريد ممنوع (ارتداد/شكوى/سبام/يدوي)؟ يُستخدم قبل أي إرسال
     */
    public function isSuppressed(int $userId, string $email): bool
    {
        $email = strtolower(trim($email));
        $rows = $this->db->query(
            "SELECT id FROM email_suppressions WHERE user_id = ? AND email = ? LIMIT 1",
            [$userId, $email]
        );
        return !empty($rows);
    }

    /**
     * تسجيل ارتداد/شكوى من SMTP أو Webhook، مع تحديث حالة المشترك
     */
    public function recordDeliveryIssue(int $userId, string $email, string $type, ?string $reason = null, bool $checkHealth = true): void
    {
        if (!in_array($type, ['bounce', 'complaint', 'spam'], true)) {
            return;
        }
        $email = strtolower(trim($email));
        $this->db->query(
            "INSERT INTO email_suppressions (user_id, email, type, reason, source)
             VALUES (?, ?, ?, ?, 'smtp')
             ON DUPLICATE KEY UPDATE type = VALUES(type), reason = VALUES(reason), suppressed_at = NOW()",
            [$userId, $email, $type, $reason]
        );
        $sub = (new EmailSubscriber())->where(['user_id' => $userId, 'email' => $email]);
        if (!empty($sub)) {
            $sub = $sub[0];
            $status = $type === 'complaint' ? 'unsubscribed' : 'bounced';
            $sub->setAttribute('status', $status);
            if ($type === 'bounce') {
                $sub->setAttribute('bounce_count', (int) $sub->getAttribute('bounce_count') + 1);
            }
            $sub->save();
        }

        // ربط الارتداد/الشكوى بالحملة + مراقبة السمعة (إيقاف تلقائي عند تجاوز العتبات).
        // أي خطأ هنا ما يكسّرش تسجيل الحظر نفسه.
        if (class_exists('EmailReputationGuard')) {
            try {
                (new EmailReputationGuard())->onDeliveryIssue($userId, $email, $type, $reason, $checkHealth);
            } catch (\Throwable $e) {
                if (class_exists('Logger')) {
                    Logger::warning('EmailReputationGuard::onDeliveryIssue failed', ['error' => $e->getMessage()]);
                }
            }
        }
    }

    /**
     * معالجة webhook تتبع التسليم (بند 1): ارتداد/شكوى/سبام من مزوّد SMTP.
     * بيتحقق من التوقيع (مفتاح المستخدم الخاص أو المفتاح العام في .env كحد
     * أدنى) قبل تسجيل المشكلة — النوع غير المعروف أو التوقيع الغلط بيتم
     * تجاهلهم بأمان من غير ما يكسّروا الـ webhook.
     *
     * المصدر الوحيد لهذه المعالجة هو WebhookController::emailDeliveryStatusWebhook
     * (route /webhooks/email/delivery-status/{user_id}) — أي مسارات webhooks.php
     * القديمة غير محمّلة إطلاقًا في index.php (بيحمّل web.php + api.php فقط).
     *
     * @param string $rawBody جسم الطلب الخام (مطلوب للتحقق HMAC بتاع SendGrid)
     * @param array  $payload الـ JSON المُحلَّل (أو $_POST للمتغير form)
     * @param array  $headers هيدرز http ذات الصلة (أسماء lowercase بلا بادئة HTTP_)
     * @return array ['handled'=>bool, 'type'=>?string, 'email'=>?string, 'error'=>?string]
     */
    public function handleDeliveryWebhook(int $userId, string $rawBody, array $payload, array $headers = []): array
    {
        if ($userId <= 0) {
            return ['handled' => false, 'error' => 'معرّف مستخدم غير صالح'];
        }
        $settings = (new SmtpSettingsService())->get($userId);
        if (!$settings) {
            return ['handled' => false, 'error' => 'لا توجد إعدادات SMTP لهذا المستخدم'];
        }
        // webhook معطّل => تجاهل آمن (200 بدون معالجة)
        if (!(int) ($settings['delivery_webhook_enabled'] ?? 0)) {
            return ['handled' => false];
        }

        $secret = trim((string) ($settings['delivery_webhook_secret'] ?? ''));
        // الحد الأدنى: مفتاح سري عام في .env لو المستخدم معملش مفتاح خاص
        if ($secret === '' && defined('EMAIL_DELIVERY_WEBHOOK_SECRET')) {
            $secret = trim((string) EMAIL_DELIVERY_WEBHOOK_SECRET);
        }
        if ($secret === '' || !$this->verifyDeliverySignature($rawBody, $payload, $headers, $secret)) {
            return ['handled' => false, 'error' => 'توقيع غير صالح'];
        }

        // SendGrid بيبعت دفعة أحداث في طلب واحد — لازم نعالجها كلها (مش أول حدث بس)
        $first = null;
        $count = 0;
        $seen = [];
        foreach ($this->mapDeliveryEvents($payload) as [$type, $email, $reason]) {
            // نوع/بريد غير معروف => تجاهل آمن (نرد نجاح بس من غير معالجة)
            if ($type === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $dedupe = $type . '|' . $email;
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;
            $this->recordDeliveryIssue($userId, $email, $type, $reason);
            $first = $first ?? ['type' => $type, 'email' => $email];
            $count++;
        }
        if ($first === null) {
            return ['handled' => false];
        }
        return ['handled' => true, 'type' => $first['type'], 'email' => $first['email'], 'count' => $count];
    }

    /**
     * التحقق من توقيع webhook التسليم حسب المزوّد:
     *  - Mailgun: signature.timestamp + signature.token مرقّمة HMAC-SHA256
     *    بمفتاح المستخدم.
     *  - SendGrid: هيدرز X-Twilio-Email-Event-Webhook-Signature/
     *    Timestamp مع HMAC-SHA256 فوق (timestamp + جسم خام).
     *  - Postmark: هيدر X-Postmark-Server-Token بمطابقة نص المفتاح.
     *  - عام: هيدر X-Delivery-Webhook-Secret بمطابقة نص المفتاح (الحد الأدنى).
     * @param string $secret المفتاح السري (خاص بالمستخدم أو عام من .env)
     */
    private function verifyDeliverySignature(string $rawBody, array $payload, array $headers, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }

        // Mailgun: {"signature":{"timestamp":..,"token":..,"signature":".."}}
        if (!empty($payload['signature']) && is_array($payload['signature'])) {
            $sig = $payload['signature'];
            if (!$this->timestampFresh((string) ($sig['timestamp'] ?? ''))) {
                return false;
            }
            $expected = hash_hmac('sha256', (string) ($sig['timestamp'] ?? '') . (string) ($sig['token'] ?? ''), $secret);
            return hash_equals($expected, (string) ($sig['signature'] ?? ''));
        }

        // SendGrid: X-Twilio-Email-Event-Webhook-Signature + Timestamp
        $sendGridSig = (string) ($headers['x-twilio-email-event-webhook-signature'] ?? '');
        $sendGridTs = (string) ($headers['x-twilio-email-event-webhook-timestamp'] ?? '');
        if ($sendGridSig !== '' && $sendGridTs !== '') {
            if (!$this->timestampFresh($sendGridTs)) {
                return false;
            }
            $expected = hash_hmac('sha256', $sendGridTs . $rawBody, $secret);
            return hash_equals($expected, $sendGridSig);
        }

        // Postmark: X-Postmark-Server-Token
        $postmarkToken = (string) ($headers['x-postmark-server-token'] ?? '');
        if ($postmarkToken !== '') {
            return hash_equals($secret, $postmarkToken);
        }

        // عام/مخصص: X-Delivery-Webhook-Secret (الحد الأدنى المطلوب في .env)
        $generic = (string) ($headers['x-delivery-webhook-secret'] ?? '');
        if ($generic !== '') {
            return hash_equals($secret, $generic);
        }

        return false;
    }

    /**
     * حماية من إعادة تشغيل (replay) طلب webhook موقّع قديم: الطابع الزمني لازم
     * يكون ضمن EMAIL_WEBHOOK_TOLERANCE_SECONDS (افتراضي 600ث، 0 = تعطيل الفحص).
     */
    private function timestampFresh(string $ts): bool
    {
        $tolerance = defined('EMAIL_WEBHOOK_TOLERANCE_SECONDS') ? (int) EMAIL_WEBHOOK_TOLERANCE_SECONDS : 600;
        if ($tolerance <= 0) {
            return true;
        }
        if ($ts === '' || !ctype_digit($ts)) {
            return false;
        }
        return abs(time() - (int) $ts) <= $tolerance;
    }

    /**
     * رسم أحداث webhook التسليم لنوع suppressions + البريد + السبب.
     * يدعم صيغ SendGrid (مصفوفة أحداث — كلها)، Mailgun (event-data)، Postmark
     * (RecordType) والصيغة العامة (event/email).
     * @return array[] قائمة [type|null, email, reason]
     */
    private function mapDeliveryEvents(array $payload): array
    {
        $items = [];
        if (isset($payload[0]) && is_array($payload[0])) {
            // SendGrid: [{"event":"bounce","email":"..","reason":".."}, ...]
            foreach ($payload as $item) {
                if (is_array($item)) {
                    $items[] = [(string) ($item['event'] ?? ''), (string) ($item['email'] ?? ''), (string) ($item['reason'] ?? $item['response'] ?? '')];
                }
            }
        } elseif (!empty($payload['event-data']) && is_array($payload['event-data'])) {
            // Mailgun
            $ed = $payload['event-data'];
            $items[] = [(string) ($ed['event'] ?? ''), (string) ($ed['recipient'] ?? ''), (string) ($ed['reason'] ?? '')];
        } else {
            // Postmark / عام
            $items[] = [
                (string) ($payload['event'] ?? $payload['type'] ?? $payload['RecordType'] ?? ''),
                (string) ($payload['email'] ?? $payload['recipient'] ?? $payload['Email'] ?? ''),
                (string) ($payload['reason'] ?? $payload['Description'] ?? ''),
            ];
        }

        $out = [];
        foreach ($items as [$event, $email, $reason]) {
            $event = strtolower(trim($event));
            $type = null;
            if (in_array($event, ['bounce', 'bounced', 'blocked', 'permanent_fail'], true)) {
                $type = 'bounce';
            } elseif (in_array($event, ['complaint', 'complained', 'spamreport', 'spam_complaint', 'spamcomplaint'], true)) {
                $type = 'complaint';
            } elseif (in_array($event, ['spam', 'manual_spam'], true)) {
                $type = 'spam';
            }
            $out[] = [$type, strtolower(trim($email)), trim($reason)];
        }
        return $out;
    }

    // ============================ Import / Export ============================

    /**
     * استيراد متقدم مع رسم خرائط الحقول.
     * $rows: array of assoc arrays (email + أي أعمدة). $fieldMap يربط
     * عمود الملف باسم الحقل: ['first_name' => 'الاسم', ...] أو قائمة أسماء.
     */
    public function importContacts(int $userId, array $rows, array $options = []): array
    {
        $this->ensureSystemFields($userId);
        $listId = (int) ($options['list_id'] ?? 0);
        $tagNames = $options['tags'] ?? [];
        $fieldMap = $options['field_map'] ?? [];

        $added = 0;
        $updated = 0;
        $invalid = 0;

        // تنضيف السمعة: دومينات مؤقتة (disposable) + الحظر العام تُرفض عند الاستيراد.
        // الحسابات الوظيفية (info@...) وأخطاء الكتابة (gmial.com) تُقبل لكن تُعدّ risky.
        // options: hygiene (افتراضي true)، check_mx (افتراضي false — بطيء على ملفات كبيرة)
        $hygieneOn = !array_key_exists('hygiene', $options) || !empty($options['hygiene']);
        $hygiene = $hygieneOn && class_exists('EmailListHygieneService')
            ? new EmailListHygieneService() : null;
        $guard = $hygieneOn && class_exists('EmailReputationGuard') ? new EmailReputationGuard() : null;
        $rejected = 0;
        $risky = 0;
        $rejectReasons = [];
        $optedOut = 0;

        foreach ($rows as $row) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalid++;
                continue;
            }
            // الاستيراد عمره ما يرجّع اشتراك حد لغى/ارتد/اشتكى أو محظور عند الحساب
            // (subscribe() كان بيحوّل أي مشترك موجود لـ subscribed)
            if ($this->importBlockedByOptOut($userId, $email)) {
                $optedOut++;
                continue;
            }
            if ($hygiene) {
                $a = $hygiene->assess($email, !empty($options['check_mx']));
                if ($a['verdict'] === 'reject') {
                    $rejected++;
                    $rejectReasons[$a['reasons'][0]] = ($rejectReasons[$a['reasons'][0]] ?? 0) + 1;
                    continue;
                }
                if ($a['verdict'] === 'risky') {
                    $risky++;
                }
            }
            if ($guard) {
                try {
                    if ($guard->isGloballySuppressed($email)) {
                        $rejected++;
                        $rejectReasons['global_suppression'] = ($rejectReasons['global_suppression'] ?? 0) + 1;
                        continue;
                    }
                } catch (\Throwable $e) {
                    // جدول الحظر العام لسه مش موجود (migration ما اتشغّلتش): نكمل
                }
            }
            $name = '';
            foreach (['name', 'first_name', 'الاسم', 'firstname'] as $key) {
                if (!empty($row[$key])) {
                    $name = (string) $row[$key];
                    break;
                }
            }
            if ($name === '' && !empty($row['first_name']) && !empty($row['last_name'])) {
                $name = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
            }

            // الخريطة: name الحقل => قيمة من الصف
            $customValues = [];
            foreach ($fieldMap as $fieldName => $columnKey) {
                if (is_numeric($fieldName)) {
                    $fieldName = $columnKey;
                }
                if (array_key_exists($columnKey, $row)) {
                    $customValues[$fieldName] = $row[$columnKey];
                }
            }
            if (isset($row['custom']) && is_array($row['custom'])) {
                foreach ($row['custom'] as $key => $value) {
                    $customValues[(string) $key] = $value;
                }
            }

            $result = (new EmailListService())->subscribe($userId, $email, [
                'name' => $name,
                'source' => 'import',
            ], $listId);

            if (!$result['success']) {
                $invalid++;
                continue;
            }
            $subscriberId = (int) $result['id'];
            $result['created'] ? $added++ : $updated++;

            $rowStatus = strtolower(trim((string) ($row['status'] ?? '')));
            // عمود status في الملف بيقدر ينزّل الحالة بس (إلغاء/ارتداد)، "subscribed" هي الافتراضي أصلًا
            if (in_array($rowStatus, ['unsubscribed', 'bounced', 'complained'], true)) {
                $this->db->exec('UPDATE email_subscribers SET status = ? WHERE id = ?', [$rowStatus, $subscriberId]);
            }

            if (!empty($customValues)) {
                $this->saveCustomValues($userId, $subscriberId, $customValues);
            }
            foreach ($tagNames as $tagName) {
                $this->applyTagByName($userId, $subscriberId, (string) $tagName);
            }
            if (isset($row['tags']) && is_array($row['tags'])) {
                foreach ($row['tags'] as $tagName) {
                    $this->applyTagByName($userId, $subscriberId, (string) $tagName);
                }
            }
        }

        return ['success' => true, 'added' => $added, 'updated' => $updated, 'invalid' => $invalid,
            'rejected' => $rejected, 'risky' => $risky, 'reject_reasons' => $rejectReasons, 'skipped_opted_out' => $optedOut];
    }

    private function importBlockedByOptOut(int $userId, string $email): bool
    {
        $existing = $this->db->query("SELECT status FROM email_subscribers WHERE user_id = ? AND email = ? LIMIT 1", [$userId, $email])[0] ?? null;
        if ($existing && in_array($existing['status'], ['unsubscribed', 'bounced', 'complained'], true)) {
            return true;
        }
        try {
            return !empty($this->db->query("SELECT id FROM email_suppressions WHERE user_id = ? AND email = ? LIMIT 1", [$userId, $email]));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * معاينة الاستيراد قبل التنفيذ (Dry-run): نفس قواعد importContacts بالظبط
     * من غير أي كتابة. بترجّع الأعداد + أول 50 صف بحكم كل واحد.
     */
    public function previewImport(int $userId, array $rows, array $options = []): array
    {
        $hygieneOn = !array_key_exists('hygiene', $options) || !empty($options['hygiene']);
        $hygiene = $hygieneOn && class_exists('EmailListHygieneService') ? new EmailListHygieneService() : null;
        $guard = $hygieneOn && class_exists('EmailReputationGuard') ? new EmailReputationGuard() : null;
        $counts = ['total' => 0, 'new' => 0, 'update' => 0, 'invalid' => 0, 'duplicate_in_file' => 0, 'opted_out' => 0, 'rejected' => 0, 'risky' => 0];
        $sample = [];
        $seen = [];
        foreach ($rows as $row) {
            $counts['total']++;
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            $verdict = 'new';
            $reason = '';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $verdict = 'invalid';
            } elseif (isset($seen[$email])) {
                $verdict = 'duplicate_in_file';
            } elseif ($this->importBlockedByOptOut($userId, $email)) {
                $verdict = 'opted_out';
            } else {
                $seen[$email] = true;
                if ($hygiene) {
                    $a = $hygiene->assess($email, !empty($options['check_mx']));
                    if ($a['verdict'] === 'reject') {
                        $verdict = 'rejected';
                        $reason = (string) ($a['reasons'][0] ?? '');
                    } elseif ($a['verdict'] === 'risky') {
                        $counts['risky']++;
                        $reason = (string) ($a['reasons'][0] ?? '');
                    }
                }
                if ($verdict === 'new' && $guard) {
                    try {
                        if ($guard->isGloballySuppressed($email)) {
                            $verdict = 'rejected';
                            $reason = 'global_suppression';
                        }
                    } catch (\Throwable $e) {
                    }
                }
                if ($verdict === 'new' && !empty($this->db->query("SELECT id FROM email_subscribers WHERE user_id = ? AND email = ? LIMIT 1", [$userId, $email]))) {
                    $verdict = 'update';
                }
            }
            $counts[$verdict]++;
            if (count($sample) < 50) {
                $sample[] = ['email' => $email, 'name' => (string) ($row['name'] ?? ''), 'verdict' => $verdict, 'reason' => $reason];
            }
        }
        return ['success' => true, 'counts' => $counts, 'sample' => $sample];
    }

    /**
     * كل بيانات جهات الاتصال للتصدير (CSV/JSON) مع قيم الحقول والوسوم
     */
    public function exportSubscribers(int $userId, array $filters = []): array
    {
        $this->ensureSystemFields($userId);
        $fields = $this->customFields($userId);

        $where = 's.user_id = ?';
        $params = [$userId];
        if (!empty($filters['list_id'])) {
            $where .= ' AND EXISTS (SELECT 1 FROM email_list_subscriber lx WHERE lx.subscriber_id = s.id AND lx.list_id = ?)';
            $params[] = (int) $filters['list_id'];
        }
        if (!empty($filters['status'])) {
            $where .= ' AND s.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['segment_id'])) {
            $seg = $this->evaluateSegment($userId, (int) $filters['segment_id']);
            $ids = $seg['ids'] ?? [];
            if (empty($ids)) {
                return [];
            }
            $where .= ' AND s.id IN (' . implode(',', array_map('intval', $ids)) . ')';
        }

        $subs = $this->db->query(
            "SELECT s.* FROM email_subscribers s WHERE {$where} ORDER BY s.created_at DESC",
            $params
        );

        $out = [];
        $fieldNames = array_column($fields, 'name');
        foreach ($subs as $sub) {
            $row = [
                'email' => $sub['email'],
                'name' => $sub['name'] ?? '',
                'status' => $sub['status'],
                'engagement_score' => (int) ($sub['engagement_score'] ?? 0),
                'created_at' => $sub['created_at'],
                'lists' => $this->subscriberListNames($sub['id']),
                'tags' => $this->subscriberTagNames($sub['id']),
            ];
            $values = $this->subscriberCustomValues($userId, (int) $sub['id']);
            foreach ($fieldNames as $fn) {
                $row[$fn] = '';
            }
            foreach ($values as $val) {
                if (isset($row[$val['name']])) {
                    $row[$val['name']] = $val['value'];
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    public function subscriberListNames(int $subscriberId): string
    {
        $rows = $this->db->query(
            "SELECT l.name FROM email_lists l
             JOIN email_list_subscriber ls ON ls.list_id = l.id
             WHERE ls.subscriber_id = ?",
            [$subscriberId]
        );
        return implode(', ', array_column($rows, 'name'));
    }

    public function subscriberTagNames(int $subscriberId): string
    {
        $rows = $this->db->query(
            "SELECT t.name FROM email_tags t
             JOIN email_subscriber_tag st ON st.tag_id = t.id
             WHERE st.subscriber_id = ?",
            [$subscriberId]
        );
        return implode(', ', array_column($rows, 'name'));
    }

    /**
     * البيانات الكاملة لمشترك واحد (للصفحة التفصيلية)
     */
    public function subscriberDetail(int $userId, int $subscriberId): ?array
    {
        $sub = (new EmailSubscriber())->find($subscriberId);
        if (!$sub || (int) $sub->getAttribute('user_id') !== $userId) {
            return null;
        }
        $data = $sub->toArray();

        $data['lists'] = $this->db->query(
            "SELECT l.id, l.name, ls.created_at AS joined_at FROM email_lists l
             JOIN email_list_subscriber ls ON ls.list_id = l.id
             WHERE ls.subscriber_id = ?",
            [$subscriberId]
        );
        $data['tags'] = $this->subscriberTags($userId, $subscriberId);
        $data['custom_values'] = $this->subscriberCustomValues($userId, $subscriberId);
        $data['activity'] = $this->db->query(
            "SELECT c.id AS campaign_id, c.name AS campaign_name, r.status, r.opened_at,
                    r.clicked_at, r.open_count, r.click_count
             FROM email_campaign_recipients r
             JOIN email_campaigns c ON c.id = r.campaign_id AND c.user_id = ?
             WHERE r.subscriber_id = ?
             ORDER BY r.created_at DESC LIMIT 20",
            [$userId, $subscriberId]
        );
        return $data;
    }

    public function updateSubscriberStatus(int $userId, int $subscriberId, string $status): array
    {
        if (!in_array($status, ['subscribed', 'unsubscribed', 'bounced'], true)) {
            return ['success' => false, 'error' => 'حالة غير صالحة'];
        }
        $sub = (new EmailSubscriber())->find($subscriberId);
        if (!$sub || (int) $sub->getAttribute('user_id') !== $userId) {
            return ['success' => false, 'error' => 'المشترك غير موجود'];
        }
        $sub->setAttribute('status', $status);
        $sub->setAttribute('unsubscribed_at', $status === 'unsubscribed' ? date('Y-m-d H:i:s') : null);
        $sub->save();
        return ['success' => true];
    }
}

<?php

/**
 * Tourfecto - CRM Report Builder Service (المرحلة 15 - G13)
 * @version 1.0.0
 *
 * تقارير مخصصة قابلة للحفظ (Report Builder) - سد فجوة 2.9:
 * "تقارير قابلة للتخصيص: 🔶 (ثابتة، بدون Builder)".
 *
 * Additive خالص: جدول واحد جديد (crm_saved_reports) فقط - لا يمس
 * CrmReportService القائم (Win/Loss + Goals).
 *
 * الأمان: لا SQL حر من المستخدم أبدًا. التقرير يُعرَّف كـJSON config
 * (entity + fields + filters + group_by + order_by + limit) وتُبنى
 * الاستعلامات من قوائم بيضاء ثابتة للحقول لكل كيان (بند 34).
 */
class CrmReportBuilderService
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /** الكيانات المدعومة مع حقولها البيضاء المعروضة */
    public const ENTITIES = [
        'contacts' => ['label_ar' => 'جهات الاتصال', 'fields' => ['name', 'email', 'phone', 'country', 'language', 'source', 'status', 'created_at']],
        'leads' => ['label_ar' => 'العملاء المحتملون', 'fields' => ['status', 'source', 'priority', 'value', 'interest', 'created_at']],
        'deals' => ['label_ar' => 'الصفقات', 'fields' => ['title', 'stage_id', 'status', 'value', 'currency', 'expected_close_date', 'closed_at', 'created_at']],
        'activities' => ['label_ar' => 'الأنشطة', 'fields' => ['activity_type', 'created_at']],
        'tasks' => ['label_ar' => 'المهام', 'fields' => ['title', 'status', 'priority', 'due_date', 'created_at']],
    ];

    /** حقول الـJOIN + العزل لكل كيان (مربوطة بـcrm_contacts.user_id عبر العلاقة) */
    private const ENTITY_SQL = [
        'contacts' => [
            'from' => 'crm_contacts',
            'where' => 'user_id = ?',
            'groupable' => ['country', 'language', 'source', 'status'],
        ],
        'leads' => [
            'from' => 'crm_leads l JOIN crm_contacts c ON c.id = l.contact_id',
            'where' => 'c.user_id = ?',
            'groupable' => ['status', 'source', 'priority'],
        ],
        'deals' => [
            'from' => 'crm_deals d JOIN crm_contacts c ON c.id = d.contact_id',
            'where' => 'c.user_id = ?',
            'groupable' => ['status', 'currency'],
        ],
        'activities' => [
            'from' => 'crm_activities a JOIN crm_contacts c ON c.id = a.contact_id',
            'where' => 'c.user_id = ?',
            'groupable' => ['activity_type'],
        ],
        'tasks' => [
            'from' => 'crm_tasks',
            'where' => 'user_id = ?',
            'groupable' => ['status', 'priority'],
        ],
    ];

    public function schema(): array
    {
        $schema = [];
        foreach (self::ENTITIES as $entity => $meta) {
            $groupable = self::ENTITY_SQL[$entity]['groupable'];
            $schema[$entity] = [
                'label_ar' => $meta['label_ar'],
                'fields' => $meta['fields'],
                'groupable' => $groupable,
            ];
        }
        return $schema;
    }

    /** حفظ تعريف تقرير جديد */
    public function save(int $userId, array $data, ?int $reportId = null): CrmSavedReport
    {
        $name = trim((string) ($data['name'] ?? ''));
        $entity = (string) ($data['entity'] ?? '');
        if ($name === '') {
            throw new Exception('اسم التقرير مطلوب', 422);
        }
        if (!isset(self::ENTITIES[$entity])) {
            throw new Exception('كيان غير معروف للتقرير', 422);
        }
        $config = $this->validateConfig($data['config'] ?? [], $entity);
        $configJson = json_encode($config, JSON_UNESCAPED_UNICODE);

        if ($reportId !== null) {
            $report = (new CrmSavedReport())->findOwned($userId, $reportId);
            if (!$report) {
                throw new Exception('التقرير غير موجود', 404);
            }
            $report->setAttribute('name', $name);
            $report->setAttribute('entity', $entity);
            $report->setAttribute('config', $configJson);
            $report->save();
            return $report;
        }

        $report = new CrmSavedReport([
            'user_id' => $userId, 'name' => $name, 'entity' => $entity, 'config' => $configJson,
        ]);
        $report->save();
        ActivityLog::record('crm', 'report.saved', [
            'user_id' => $userId, 'subject_type' => 'crm_saved_reports', 'subject_id' => (int) $report->getAttribute('id'),
        ]);
        return $report;
    }

    /** تنفيذ تقرير محفوظ وإرجاع الصفوف */
    public function run(int $userId, int $reportId): array
    {
        $report = (new CrmSavedReport())->findOwned($userId, $reportId);
        if (!$report) {
            throw new Exception('التقرير غير موجود', 404);
        }
        return $this->execute($userId, $report->getAttribute('entity'), json_decode((string) $report->getAttribute('config'), true) ?: []);
    }

    /** تنفيذ تقرير مباشر (بدون حفظ) من config + entity */
    public function execute(int $userId, string $entity, array $config): array
    {
        if (!isset(self::ENTITIES[$entity])) {
            throw new Exception('كيان غير معروف للتقرير', 422);
        }
        $config = $this->validateConfig($config, $entity);
        $meta = self::ENTITY_SQL[$entity];
        $fieldsWhitelist = self::ENTITIES[$entity]['fields'];

        $selectParts = [];
        $groupBy = null;
        if (!empty($config['group_by'])) {
            $g = $config['group_by'];
            $groupBy = $g;
            $selectParts[] = $g;
            if (in_array('value', $fieldsWhitelist, true)) {
                $selectParts[] = 'SUM(value) AS total_value';
            }
            $selectParts[] = 'COUNT(*) AS row_count';
        } else {
            foreach (($config['fields'] ?? []) as $field) {
                if (in_array($field, $fieldsWhitelist, true)) {
                    $selectParts[] = $this->fieldRef($field);
                }
            }
            if (empty($selectParts)) {
                $selectParts[] = '*';
            }
        }

        $where = [$meta['where']];
        $params = [$userId];
        foreach (($config['filters'] ?? []) as $filter) {
            $field = $filter['field'] ?? '';
            $op = $filter['operator'] ?? '=';
            $value = $filter['value'] ?? null;
            if (!in_array($field, $fieldsWhitelist, true)) {
                continue;
            }
            [$col] = explode(' AS ', $field);
            if (!preg_match('/^[a-zA-Z_]+$/', $col)) {
                continue;
            }
            $param = $this->operatorParam($op, $col, $value);
            if ($param !== null) {
                $where[] = $param['sql'];
                $params[] = $param['value'];
            }
        }

        $orderBy = '';
        if (!empty($config['order_by']) && in_array($config['order_by'], $fieldsWhitelist, true)) {
            $dir = strtoupper((string) ($config['order_dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
            $orderBy = ' ORDER BY ' . $this->fieldRef($config['order_by']) . ' ' . $dir;
        }

        $limit = max(1, min(500, (int) ($config['limit'] ?? 100)));

        $sql = 'SELECT ' . implode(', ', $selectParts) . ' FROM ' . $meta['from']
            . ' WHERE ' . implode(' AND ', $where);
        if ($groupBy) {
            $sql .= ' GROUP BY ' . $this->fieldRef($groupBy);
        }
        $sql .= $orderBy . ' LIMIT ' . $limit;

        $rows = $this->db->query($sql, $params);
        return ['entity' => $entity, 'config' => $config, 'rows' => $rows, 'row_count' => count($rows)];
    }

    public function listForUser(int $userId): array
    {
        return (new CrmSavedReport())->forUser($userId);
    }

    public function delete(int $userId, int $reportId): bool
    {
        $report = (new CrmSavedReport())->findOwned($userId, $reportId);
        if (!$report) {
            throw new Exception('التقرير غير موجود', 404);
        }
        ActivityLog::record('crm', 'report.deleted', [
            'user_id' => $userId, 'subject_type' => 'crm_saved_reports', 'subject_id' => $reportId,
        ]);
        return $report->delete();
    }

    // ================================================================
    // أدوات داخلية
    // ================================================================

    private function validateConfig(array $config, string $entity): array
    {
        $fieldsWhitelist = self::ENTITIES[$entity]['fields'];
        $groupable = self::ENTITY_SQL[$entity]['groupable'];

        $fields = [];
        foreach (($config['fields'] ?? []) as $f) {
            if (in_array($f, $fieldsWhitelist, true) && !in_array($f, $fields, true)) {
                $fields[] = $f;
            }
        }
        $filters = [];
        foreach (($config['filters'] ?? []) as $f) {
            if (!in_array($f['field'] ?? '', $fieldsWhitelist, true)) {
                continue;
            }
            if (!in_array($f['operator'] ?? '=', ['=', '!=', '>', '<', '>=', '<=', 'contains', 'is_null'], true)) {
                continue;
            }
            $filters[] = ['field' => $f['field'], 'operator' => $f['operator'], 'value' => $f['value'] ?? null];
        }
        $groupBy = isset($config['group_by']) && in_array($config['group_by'], $groupable, true) ? $config['group_by'] : null;
        $orderBy = isset($config['order_by']) && in_array($config['order_by'], $fieldsWhitelist, true) ? $config['order_by'] : null;

        return [
            'fields' => $fields,
            'filters' => $filters,
            'group_by' => $groupBy,
            'order_by' => $orderBy,
            'order_dir' => strtoupper((string) ($config['order_dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC',
            'limit' => max(1, min(500, (int) ($config['limit'] ?? 100))),
        ];
    }

    /** مرجع عمود آمن (قائمة بيضاء مفروضة قبل الاستدعاء) */
    private function fieldRef(string $field): string
    {
        return $field;
    }

    private function operatorParam(string $op, string $col, $value): ?array
    {
        if ($op === 'is_null') {
            return ['sql' => $col . ' IS NULL', 'value' => null];
        }
        $map = ['=' => '=', '!=' => '!=', '>' => '>', '<' => '<', '>=' => '>=', '<=' => '<='];
        if (isset($map[$op])) {
            return ['sql' => $col . ' ' . $map[$op] . ' ?', 'value' => $value];
        }
        if ($op === 'contains') {
            return ['sql' => $col . ' LIKE ?', 'value' => '%' . $value . '%'];
        }
        return null;
    }
}

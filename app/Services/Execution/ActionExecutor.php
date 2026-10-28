<?php

/**
 * Tourfecto - Action Executor (طبقة التنفيذ الموحّدة) v1.0.0
 *
 * الفئة الأساسية لطبقة التنفيذ: بتحوّل توصيات وحدات التحليل لأي نوع من
 * "التحليل فقط" لإجراءات حقيقية على النظام:
 *   - إنشاء مهمة CRM (بمرجع لجهة الاتصال لو متاحة) بتاريخ استحقاق وأولوية
 *     بناءً على خطورة الإجراء.
 *   - إشعار داخلي فوري للأعمال عالية الخطورة (severity = high).
 *   - تسجيل التنفيذ في جدول التدقيق (لكل وحدة جدولها) لمنع التكرار (dedup)
 *     مع نافذة زمنية.
 *
 * الوحدات المشتقّة بتحدد عبر hooks: جدول التنفيذات، نوع الإشعار، رابط
 * الإشعار، بادئة عنوان المهمة، محتوى المهمة، وخطوة afterExecution
 * (زي وسم مصدر الرؤية بحالة actioned).
 *
 * ملاحظة: بيشتغل مع أي منفّذ خارجي عن طريق حقن دوال (taskCreator/notifier)
 * عشان يكون قابلًا للاختبار offline - في الإنتاج بيتأسسوا تلقائيًا على
 * CrmTaskService + Notification::notify.
 *
 * @version 1.0.0
 * @date 2026-08-20
 */
abstract class ActionExecutor
{
    /** @var Database */
    protected $db;

    /** @var callable|null */
    protected $taskCreator;

    /** @var callable|null */
    protected $notifier;

    public function __construct(?Database $db = null, ?callable $taskCreator = null, ?callable $notifier = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->taskCreator = $taskCreator;
        $this->notifier = $notifier;
    }

    // ==================== hooks (بتنفّذها كل وحدة) ====================

    /** اسم جدول تسجيل التنفيذات (للتدقيق + منع التكرار). */
    abstract protected function table(): string;

    /** نوع الإشعار الداخلي (notification type). */
    abstract protected function notificationType(): string;

    /** رابط الإشعار داخل اللوحة. */
    abstract protected function notificationUrl(): string;

    /** بادئة عنوان المهمة (زي "إجراء إيرادات: "). */
    abstract protected function taskPrefix(): string;

    /** بناء وصف المهمة من الإجراء. */
    abstract protected function buildTaskBody(array $action): string;

    /** خطوة إضافية بعد تسجيل التنفيذ (افتراضيًا لا شيء). */
    protected function afterExecution(int $userId, array $intent): void
    {
        // override في الوحدات اللي محتاجة وسم مصدر الرؤية (status = actioned)
    }

    // ==================== public API ====================

    /**
     * تحويل قائمة الإجراءات إلى "نوايا تنفيذ" نقيّة وقابلة للفحص.
     * Pure function - من غير أي كتابة.
     *
     * @param array $actions قائمة الإجراءات من خدمة التوصيات
     * @param int $windowDays نافذة منع التكرار بالأيام
     * @return array قائمة نوايا تنفيذ
     */
    public function planActions(array $actions, int $windowDays = 7): array
    {
        $intents = [];
        foreach ($actions as $action) {
            $intents[] = $this->planOne($action, $windowDays);
        }
        return $intents;
    }

    /**
     * تخطيط إجراء واحد → نية تنفيذ.
     */
    public function planOne(array $action, int $windowDays = 7): array
    {
        $severity = $action['severity'] ?? null;
        $confidence = (string) ($action['confidence'] ?? 'medium');
        $affected = (string) ($action['affected_area'] ?? '');
        $affected = $affected === '' ? 'all' : $affected;

        $actionKey = implode(':', [
            (string) ($action['source_type'] ?? 'action'),
            (string) ($action['source_category'] ?? 'general'),
            $affected,
        ]);
        if (!empty($action['period'])) {
            $actionKey .= ':' . (string) $action['period'];
        }
        if (!empty($action['dedup_key'])) {
            $actionKey .= ':' . (string) $action['dedup_key'];
        }

        $relatedType = null;
        $relatedId = null;
        if (preg_match('/^customer:(\d+)$/', $affected, $m)) {
            $relatedType = 'crm_contacts';
            $relatedId = (int) $m[1];
        }

        $priority = 'medium';
        $dueOffsetDays = 7;
        if ($severity === 'high' || ($severity === null && $confidence === 'high')) {
            $priority = 'high';
            $dueOffsetDays = 1;
        } elseif ($severity === 'medium') {
            $priority = 'medium';
            $dueOffsetDays = 3;
        } elseif ($severity === 'low') {
            $priority = 'low';
            $dueOffsetDays = 7;
        }

        $title = '⚡ ' . $this->taskPrefix() . (string) ($action['action'] ?? 'Follow-up');
        $description = $this->buildTaskBody($action);

        return [
            'action_key' => $actionKey,
            'action' => (string) ($action['action'] ?? 'Follow-up'),
            'source_type' => $action['source_type'] ?? null,
            'source_category' => $action['source_category'] ?? null,
            'affected_area' => $affected,
            'affected_area_id' => $action['affected_area_id'] ?? null,
            'severity' => $severity,
            'confidence' => $confidence,
            'priority' => $priority,
            'due_date' => date('Y-m-d H:i:s', strtotime("+{$dueOffsetDays} days")),
            'task' => [
                'title' => $title,
                'description' => $description,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
            ],
            'notify' => ($severity === 'high'),
            'window_days' => (int) $windowDays,
        ];
    }

    /**
     * هل تم تنفيذ نفس الإجراء لنفس المستخدم داخل النافذة الزمنية؟
     */
    public function alreadyExecuted(int $userId, string $actionKey, int $windowDays = 7): bool
    {
        $rows = $this->db->query(
            "SELECT id FROM {$this->table()}
              WHERE user_id = ? AND action_key = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 1",
            [$userId, $actionKey, $windowDays]
        );
        return !empty($rows);
    }

    /**
     * تنفيذ قائمة إجراءات فعلًا، مع منع التكرار وتسجيل التاريخ.
     *
     * @param int $userId
     * @param array $actions إجراءات من خدمة التوصيات
     * @param array $opts ['create_tasks'=>bool, 'notify'=>bool, 'window_days'=>int, 'dry_run'=>bool]
     * @return array ملخص التنفيذ
     */
    public function executeActions(int $userId, array $actions, array $opts = []): array
    {
        $createTasks = $opts['create_tasks'] ?? true;
        $notify = $opts['notify'] ?? true;
        $windowDays = (int) ($opts['window_days'] ?? 7);
        $dryRun = !empty($opts['dry_run']);

        $summary = [
            'planned' => 0,
            'executed' => 0,
            'tasks_created' => 0,
            'notifications_sent' => 0,
            'skipped' => 0,
            'errors' => 0,
            'executed_items' => [],
        ];

        foreach ($actions as $action) {
            $summary['planned']++;
            $intent = $this->planOne($action, $windowDays);

            if (!$dryRun && $this->alreadyExecuted($userId, $intent['action_key'], $windowDays)) {
                $summary['skipped']++;
                continue;
            }

            $taken = [];
            try {
                if (!$dryRun && $createTasks && !empty($intent['task']['title'])) {
                    $this->createTask($userId, $intent['task'], $intent['priority'], $intent['due_date']);
                    $taken[] = 'crm_task';
                    $summary['tasks_created']++;
                }
                if (!$dryRun && $notify && $intent['notify']) {
                    $this->notifyUser($userId, $intent);
                    $taken[] = 'notification';
                    $summary['notifications_sent']++;
                }
                if (!$dryRun) {
                    if (!empty($taken)) {
                        $this->recordExecution($userId, $intent, $taken);
                        $this->afterExecution($userId, $intent);
                    }
                } else {
                    $taken[] = 'crm_task';
                    if ($intent['notify']) {
                        $taken[] = 'notification';
                    }
                }
                $summary['executed']++;
                $summary['executed_items'][] = [
                    'action_key' => $intent['action_key'],
                    'action' => $intent['action'],
                    'severity' => $intent['severity'],
                    'taken' => $taken,
                ];
            } catch (Throwable $e) {
                $summary['errors']++;
                if (class_exists('Logger')) {
                    Logger::warning('Action execute failed', ['user_id' => $userId, 'key' => $intent['action_key'], 'error' => $e->getMessage()]);
                }
            }
        }

        return $summary;
    }

    /** تاريخ آخر عمليات التنفيذ للمستخدم (حد $limit). */
    public function history(int $userId, int $limit = 20): array
    {
        return $this->db->query(
            "SELECT id, action_key, source_type, source_category, affected_area, severity, actions_taken, created_at
               FROM {$this->table()}
              WHERE user_id = ?
              ORDER BY id DESC LIMIT ?",
            [$userId, $limit]
        );
    }

    // ==================== internal ====================

    protected function createTask(int $userId, array $taskData, string $priority, string $dueDate): void
    {
        $payload = array_merge($taskData, ['priority' => $priority, 'due_date' => $dueDate]);
        if ($this->taskCreator !== null) {
            call_user_func($this->taskCreator, $userId, $payload);
            return;
        }
        (new CrmTaskService())->create($userId, $payload);
    }

    protected function notifyUser(int $userId, array $intent): void
    {
        if ($this->notifier !== null) {
            call_user_func($this->notifier, $userId, $intent);
            return;
        }
        $body = isset($intent['task']['description']) && $intent['task']['description'] !== ''
            ? $intent['task']['description']
            : $intent['action'];
        Notification::notify($userId, $this->notificationType(), $intent['action'], $body, $this->notificationUrl());
    }

    protected function recordExecution(int $userId, array $intent, array $taken): void
    {
        $this->db->exec(
            "INSERT INTO {$this->table()}
               (user_id, action_key, source_type, source_category, affected_area, severity, actions_taken)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $userId,
                $intent['action_key'],
                $intent['source_type'],
                $intent['source_category'],
                $intent['affected_area'],
                $intent['severity'],
                json_encode($taken, JSON_UNESCAPED_UNICODE),
            ]
        );
    }
}

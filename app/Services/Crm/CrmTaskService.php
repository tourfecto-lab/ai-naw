<?php

/** Tourfecto - CRM Task / Follow-up Service (بند 11 و13) @version 1.1.0 */
class CrmTaskService
{
    /**
     * تصحيح: الجدول ENUM('lead','contact','deal'). أي صيغة تانية (crm_leads / leads / Contact...)
     * كانت بتوقع INSERT بخطأ 500 "Data truncated". بنوحّدها، والقيمة غير المعروفة = خطأ واضح.
     */
    private static function normalizeRelatedType($type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }
        $t = strtolower(trim((string) $type));
        $t = preg_replace('/^crm_/', '', $t);
        $map = ['lead' => 'lead', 'leads' => 'lead', 'contact' => 'contact', 'contacts' => 'contact', 'deal' => 'deal', 'deals' => 'deal'];
        if (!isset($map[$t])) {
            throw new Exception('نوع السجل المرتبط غير مدعوم (المسموح: lead, contact, deal)');
        }
        return $map[$t];
    }

    use CrmPaginationHelper;

    /**
     * $actorUserId اختياري (بند 30 - استكمال): الهوية الحقيقية لمن أنشأ
     * المهمة فعليًا، منفصلة عن $userId (الـTenant). لو مش مُمرَّرة، تتساوى
     * بـ$userId (نفس السلوك القديم قبل الإصلاح - توافق خلفي كامل).
     */
    public function create(int $userId, array $data, ?int $actorUserId = null): CrmTask
    {
        if (empty($data['title'])) {
            throw new Exception('عنوان المهمة مطلوب');
        }
        $actorUserId = $actorUserId ?? $userId;
        $task = new CrmTask([
            'user_id' => $userId,
            'created_by_user_id' => $actorUserId,
            'assigned_to_user_id' => $data['assigned_to_user_id'] ?? $actorUserId,
            'related_type' => self::normalizeRelatedType($data['related_type'] ?? null),
            'related_id' => $data['related_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'priority' => $data['priority'] ?? 'medium',
            'status' => 'open',
        ]);
        $task->save();

        ActivityLog::record('crm', 'task.created', [
            'user_id' => $actorUserId, 'subject_type' => 'crm_tasks', 'subject_id' => (int) $task->getAttribute('id'),
        ]);

        return $task;
    }

    public function findOwned(int $userId, int $taskId): CrmTask
    {
        $task = (new CrmTask())->find($taskId);
        if (!$task || (int) $task->getAttribute('user_id') !== $userId) {
            throw new Exception('المهمة غير موجودة', 404);
        }
        return $task;
    }

    public function updateStatus(int $userId, int $taskId, string $status): CrmTask
    {
        $allowed = ['open', 'in_progress', 'done', 'cancelled'];
        if (!in_array($status, $allowed, true)) {
            throw new Exception('حالة غير صحيحة');
        }
        $task = $this->findOwned($userId, $taskId);
        $task->setAttribute('status', $status);
        $task->setAttribute('completed_at', $status === 'done' ? date('Y-m-d H:i:s') : null);
        $task->save();

        ActivityLog::record('crm', 'task.status_changed', [
            'user_id' => $userId, 'subject_type' => 'crm_tasks', 'subject_id' => $taskId, 'meta' => ['status' => $status],
        ]);

        return $task;
    }

    public function listForUser(int $userId, int $limit = 200): array
    {
        return (new CrmTask())->allForUser($userId, $limit);
    }

    public function overdue(int $userId): array
    {
        return (new CrmTask())->overdue($userId);
    }

    /**
     * نسخة بـFilters + Pagination حقيقي (بند 29، 37) - listForUser() القديمة
     * لسه موجودة (مُستخدَمة في overdue/dashboard وغيرهم).
     * $filters المدعومة: status, priority, related_type, due_before, due_after.
     */
    public function search(int $userId, array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where = ['user_id = ?'];
        $params = [$userId];

        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['priority'])) {
            $where[] = 'priority = ?';
            $params[] = $filters['priority'];
        }
        if (!empty($filters['related_type'])) {
            $where[] = 'related_type = ?';
            $params[] = $filters['related_type'];
        }
        if (!empty($filters['due_before'])) {
            $where[] = 'due_date <= ?';
            $params[] = $filters['due_before'] . ' 23:59:59';
        }
        if (!empty($filters['due_after'])) {
            $where[] = 'due_date >= ?';
            $params[] = $filters['due_after'] . ' 00:00:00';
        }
        if (!empty($filters['search'])) {
            $where[] = 'title LIKE ?';
            $params[] = '%' . $filters['search'] . '%';
        }

        return $this->paginateQuery('crm_tasks', implode(' AND ', $where), $params, $page, $perPage, 'due_date ASC');
    }
}

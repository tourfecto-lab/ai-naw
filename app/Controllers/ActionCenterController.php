<?php

/**
 * Tourfecto - Action Center Controller
 * Phase 12. Endpoint واحد Read-Only بيجمع "ماذا أفعل الآن؟" من كل الـAgents.
 * @version 1.0.0
 */
class ActionCenterController extends Controller
{
    /** GET /api/action-center?website_id=X (اختياري) */
    public function list(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = $this->get('website_id') ? (int) $this->get('website_id') : null;

        if (!class_exists('ActionCenterService')) {
            return $this->error('الخدمة غير متاحة', 500);
        }

        try {
            $service = new ActionCenterService();
            $items = $service->getActionItems($this->db, (int) $this->user['id'], $websiteId);
        } catch (Exception $e) {
            Logger::error('Action Center Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب قائمة الإجراءات', 500);
        }

        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($items as $item) {
            if (isset($counts[$item['priority']])) {
                $counts[$item['priority']]++;
            }
        }

        return $this->success([
            'items' => $items,
            'total' => count($items),
            'counts_by_priority' => $counts,
        ]);
    }

    /** GET /api/action-center/actions - استعراض التوصيات القابلة للتنفيذ */
    public function actions(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!class_exists('ActionCenterExecutionService') || !class_exists('ActionCenterExecutor')) {
            return $this->error('خدمة التنفيذ غير متاحة', 500);
        }

        $websiteId = $this->get('website_id') ? (int) $this->get('website_id') : null;
        $limit = min((int) ($this->get('limit') ?: 20), 50);

        try {
            $service = new ActionCenterExecutionService();
            $actions = $service->getNextBestActions($this->db, (int) $this->user['id'], $websiteId, $limit);
            $executor = new ActionCenterExecutor($this->db);
            $intents = $executor->planActions($actions);
        } catch (Exception $e) {
            Logger::error('Action Center Actions Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر استعراض الإجراءات', 500);
        }

        return $this->success(['has_data' => !empty($intents), 'actions' => $intents]);
    }

    /** POST /api/action-center/actions/execute - تنفيذ التوصيات كإجراءات فعلية */
    public function execute(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!class_exists('ActionCenterExecutionService') || !class_exists('ActionCenterExecutor')) {
            return $this->error('خدمة التنفيذ غير متاحة', 500);
        }

        $websiteId = $this->get('website_id') ? (int) $this->get('website_id') : null;
        $limit = min((int) ($this->get('limit') ?: 20), 50);
        $dryRun = $this->asBool($this->get('dry_run'));
        $createTasks = $this->asBool($this->get('create_tasks'), true);
        $notify = $this->asBool($this->get('notify'), true);
        $windowDays = max(1, (int) ($this->get('window_days') ?: 7));

        try {
            $service = new ActionCenterExecutionService();
            $actions = $service->getNextBestActions($this->db, (int) $this->user['id'], $websiteId, $limit);
            $executor = new ActionCenterExecutor($this->db);
            $summary = $executor->executeActions((int) $this->user['id'], $actions, [
                'create_tasks' => $createTasks,
                'notify' => $notify,
                'window_days' => $windowDays,
                'dry_run' => $dryRun,
            ]);
            $this->recordActivity($summary);
        } catch (Exception $e) {
            Logger::error('Action Center Execute Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تنفيذ الإجراءات', 500);
        }

        $msg = $dryRun
            ? 'استعراض التنفيذ المتوقع'
            : ($summary['tasks_created'] > 0 ? 'تم إنشاء ' . $summary['tasks_created'] . ' مهمة' : 'تمت المعالجة');

        return $this->success(['summary' => $summary, 'message' => $msg, 'dry_run' => $dryRun]);
    }

    /** GET /api/action-center/actions/history - سجلّ آخر عمليات التنفيذ */
    public function history(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!class_exists('ActionCenterExecutor')) {
            return $this->error('خدمة التنفيذ غير متاحة', 500);
        }

        $limit = min((int) ($this->get('limit') ?: 20), 100);
        try {
            $executor = new ActionCenterExecutor($this->db);
            $history = $executor->history((int) $this->user['id'], $limit);
        } catch (Exception $e) {
            return $this->error('تعذر جلب السجلّ', 500);
        }

        return $this->success(['has_data' => !empty($history), 'history' => $history]);
    }

    private function asBool($value, bool $default = false): bool
    {
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }

    private function recordActivity(array $summary): void
    {
        if (!class_exists('ActivityLog')) {
            return;
        }
        try {
            ActivityLog::record('action_center', 'execution', [
                'user_id' => (int) $this->user['id'],
                'meta' => [
                    'planned' => $summary['planned'],
                    'executed' => $summary['executed'],
                    'tasks_created' => $summary['tasks_created'],
                    'skipped' => $summary['skipped'],
                ],
            ]);
        } catch (Throwable $e) {
            // التسجيل ثانوي - فشله مش بيفشّل الطلب
        }
    }
}

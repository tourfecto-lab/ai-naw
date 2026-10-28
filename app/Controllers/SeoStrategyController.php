<?php

/**
 * Tourfecto - SEO Strategy Controller
 * Phase 14 (SEO Strategy Agent - خطة 30/60/90 يوم)
 * @version 1.0.0
 */
class SeoStrategyController extends Controller
{
    private $subscription;
    private $strategyService;

    public function __construct()
    {
        parent::__construct();
        $this->subscription = new SubscriptionValidator();
        $this->strategyService = new SeoStrategyService();
    }

    /** POST /api/seo-strategy/generate  { website_id } */
    public function generate(array $params = []): array
    {
        $billingUncertain = false;
        $billingScope = isset($this->subscription) ? $this->subscription->beginUsageScope() : [];
        try {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }
        if (!$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $creditsCheck = $this->subscription->reserveAICredits((int) $this->user['id'], 1);
        if (!$creditsCheck['available']) {
            return $this->error($creditsCheck['message'] ?? 'رصيد الذكاء الاصطناعي غير كافٍ', 403);
        }

        $result = $this->strategyService->generatePlan($this->db, (int) $this->user['id'], $websiteId);
        if (!$result['success']) {
            return $this->error($result['error'] ?? 'تعذر توليد الخطة', 502);
        }

        $plan = new SeoStrategyPlan([
            'user_id' => (int) $this->user['id'],
            'website_id' => $websiteId,
            'summary' => $result['summary'],
            'based_on_seo_score' => $result['context_used']['seo_score'] ?? null,
        ]);
        $plan->save();
        $planId = (int) $plan->getAttribute('id');

        $savedTasks = [];
        foreach ($result['tasks'] as $t) {
            $task = new SeoStrategyTask(array_merge($t, ['plan_id' => $planId, 'status' => 'todo']));
            $task->save();
            $savedTasks[] = $task->toArray();
        }

        if (!$this->subscription->consumeAICredits((int) $this->user['id'], 1, $creditsCheck['source'] === 'wallet')) throw new RuntimeException('تعذر تأكيد محاسبة الاستخدام');
        $this->log('SEO Strategy Plan Generated', ['website_id' => $websiteId, 'plan_id' => $planId, 'tasks_count' => count($savedTasks)]);

        return $this->success([
            'plan' => $plan->toArray(),
            'tasks' => $savedTasks,
        ], 'تم توليد خطة 90 يوم');
    
        } catch (Throwable $billingFailure) {
            $billingUncertain = true; throw $billingFailure;
        } finally {
            if (isset($this->subscription)) $this->subscription->releasePendingReservations($billingUncertain,$billingScope);
        }
    }

    /** GET /api/seo-strategy/latest?website_id=X */
    public function getLatestPlan(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }
        if (!$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $plans = $this->db->query(
            "SELECT * FROM seo_strategy_plans WHERE website_id = ? AND user_id = ? ORDER BY generated_at DESC LIMIT 1",
            [$websiteId, $this->user['id']]
        );
        if (empty($plans)) {
            return $this->error('مفيش خطة اتولدت لسه لهذا الموقع', 404);
        }

        $tasks = $this->db->query(
            "SELECT * FROM seo_strategy_tasks WHERE plan_id = ? ORDER BY FIELD(phase,'30_days','60_days','90_days'), FIELD(priority,'high','medium','low')",
            [$plans[0]['id']]
        );

        return $this->success(['plan' => $plans[0], 'tasks' => $tasks]);
    }

    /** POST /api/seo-strategy/tasks/{id}/status  { status } */
    public function updateTaskStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $taskId = (int) ($params['id'] ?? 0);
        $status = (string) $this->get('status', '');
        if (!$taskId || !in_array($status, ['todo', 'in_progress', 'done'], true)) {
            return $this->error('بيانات غير صالحة', 422);
        }

        $rows = $this->db->query(
            "SELECT t.id FROM seo_strategy_tasks t
             INNER JOIN seo_strategy_plans p ON p.id = t.plan_id
             WHERE t.id = ? AND p.user_id = ? LIMIT 1",
            [$taskId, $this->user['id']]
        );
        if (empty($rows)) {
            return $this->error('المهمة غير موجودة', 404);
        }

        $this->db->exec("UPDATE seo_strategy_tasks SET status = ? WHERE id = ?", [$status, $taskId]);

        return $this->success(['id' => $taskId, 'status' => $status], 'تم التحديث');
    }

    private function ownsWebsite(int $websiteId): bool
    {
        $website = (new Website())->find($websiteId);
        return $website && (int) $website->getAttribute('user_id') === (int) $this->user['id'];
    }
}

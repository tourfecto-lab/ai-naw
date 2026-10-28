<?php

/**
 * Tourfecto - Executive Extras Controller
 * جزء من ai-ceo-assistant (ملاحظات سياق + مخاطر + فرص نمو) + ترقية
 * executive-command-center (تنبيهات/مهام ثابتة بدل محسوبة كل مرة).
 * مبنية كإضافة للوحة القيادة التنفيذية الموجودة، مش صفحة منفصلة -
 * تجنبًا لتكرار executive_reports/recommendations الموجودين أصلًا.
 * @version 1.0.0 - BATCH6
 */
class ExecutiveExtrasController extends Controller
{
    /** GET /api/executive/extras */
    public function getExtras(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $userId = (int) $this->user['id'];

        try {
            $notes = $this->db->query("SELECT id, note, created_at FROM ceo_business_context_notes WHERE user_id = ? ORDER BY created_at DESC LIMIT 10", [$userId]);
            $risks = $this->db->query("SELECT id, title, description, severity, source_module, created_at FROM ceo_risk_alerts WHERE user_id = ? AND is_resolved = 0 ORDER BY FIELD(severity,'critical','high','medium','low'), created_at DESC LIMIT 10", [$userId]);
            $opportunities = $this->db->query("SELECT id, title, description, estimated_impact, status, created_at FROM ceo_growth_opportunities WHERE user_id = ? AND status NOT IN ('done','dismissed') ORDER BY FIELD(estimated_impact,'high','medium','low'), created_at DESC LIMIT 10", [$userId]);
            $alerts = $this->db->query("SELECT id, message, severity, created_at FROM cc_ai_alerts WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 10", [$userId]);
            $tasks = $this->db->query("SELECT id, title, status, created_at FROM cc_ai_tasks WHERE user_id = ? AND status = 'open' ORDER BY created_at DESC LIMIT 10", [$userId]);

            return $this->success([
                'notes' => $notes, 'risks' => $risks, 'opportunities' => $opportunities,
                'alerts' => $alerts, 'tasks' => $tasks,
            ]);
        } catch (Exception $e) {
            Logger::error('Executive Extras Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التحميل', 500);
        }
    }

    /** POST /api/executive/notes */
    public function addNote(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $note = trim((string) $this->get('note'));
        if ($note === '') {
            return $this->error('الملاحظة فاضية', 422);
        }

        try {
            $this->db->exec("INSERT INTO ceo_business_context_notes (user_id, note) VALUES (?, ?)", [$this->user['id'], $note]);
            return $this->success([], 'تم الحفظ');
        } catch (Exception $e) {
            Logger::error('Executive Add Note Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر الحفظ', 500);
        }
    }

    /** POST /api/executive/tasks/{id}/complete */
    public function completeTask(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $id = (int) ($params['id'] ?? 0);

        try {
            $this->db->exec("UPDATE cc_ai_tasks SET status = 'done' WHERE id = ? AND user_id = ?", [$id, $this->user['id']]);
            return $this->success([], 'تم الإنجاز');
        } catch (Exception $e) {
            Logger::error('Executive Complete Task Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التنفيذ', 500);
        }
    }

    /** POST /api/executive/alerts/{id}/read */
    public function markAlertRead(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $id = (int) ($params['id'] ?? 0);

        try {
            $this->db->exec("UPDATE cc_ai_alerts SET is_read = 1 WHERE id = ? AND user_id = ?", [$id, $this->user['id']]);
            return $this->success([], 'تم');
        } catch (Exception $e) {
            Logger::error('Executive Mark Alert Read Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر التنفيذ', 500);
        }
    }

    /**
     * POST /api/executive/ceo-advisor/ask  { question }
     * Phase 11 (AI CEO Advisor): سؤال حر مبني على بيانات الحساب الفعلية
     * (SEO Scores الحقيقية، مقارنات المنافسين، فرص الكلمات المفتاحية،
     * حالة Outreach Pipeline، تكلفة AI، الملاحظات/المخاطر/الفرص اليدوية) -
     * مش إجابات عامة. راجع CeoAdvisorService::gatherAccountSnapshot() لمصدر كل رقم.
     */
    public function askCeoAdvisor(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        if ($rateError = $this->rateLimitGuard('user', 'ai', 20, 60)) {
            return $rateError;
        }

        $question = trim((string) $this->get('question', ''));
        if ($question === '') {
            return $this->error('اكتب سؤالك الأول', 422);
        }

        if (!class_exists('CeoAdvisorService')) {
            return $this->error('الخدمة غير متاحة', 500);
        }

        try {
            $service = new CeoAdvisorService();
            $result = $service->ask($this->db, (int) $this->user['id'], $question);
        } catch (Exception $e) {
            Logger::error('CEO Advisor Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر معالجة السؤال', 500);
        }

        if (!$result['success']) {
            return $this->error($result['error'] ?? 'تعذر الحصول على إجابة', 502);
        }

        return $this->success([
            'answer' => $result['answer'],
            'based_on' => $result['snapshot_used'],
        ]);
    }
}

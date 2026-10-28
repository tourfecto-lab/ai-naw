<?php

/**
 * Tourfecto - AI Chat Platform
 * Learning Loop Controller: إدارة فجوات المعرفة المقترحة (Knowledge Gaps)
 * المستخرجة من محادثات لم يستطع الـAI الإجابة عنها (مستوحى من Zendesk
 * Resolution Learning Loop / Intercom Fin). تتيح لصاحب الشركة الاطلاع على
 * الأسئلة المتكررة غير المحلولة وإضافتها لقاعدة المعرفة أو تجاهلها.
 *
 * @version 1.0.0
 */

class AiLearningController extends Controller
{
    /** @var LearningLoopService */
    private $learningLoop;

    public function __construct()
    {
        parent::__construct();
        $this->learningLoop = new LearningLoopService();
    }

    /**
     * قائمة فجوات المعرفة (بالأعلى تكرارًا) + ملخص معدلات الحل.
     * GET /api/ai-chat/websites/{id}/learning/gaps
     * Query: since (Y-m-d اختياري)
     */
    public function gaps(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }

        $since = $this->get('since');
        if ($since && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            return $this->error('since must be in Y-m-d format', 422);
        }

        $websiteId = (int) $website->getAttribute('id');
        $gaps = $this->learningLoop->topKnowledgeGaps($websiteId, $since ?: date('Y-m-d', strtotime('-30 days')), 50);

        return $this->success([
            'knowledge_gaps' => $gaps,
            'summary' => [
                'ai_resolution_rate_percent' => $this->learningLoop->getLearningInsights($websiteId, $since)['ai_resolution_rate_percent'],
                'unresolved_questions' => count($gaps),
            ],
        ]);
    }

    /**
     * تحديث حالة فجوة معرفة: acknowledged | added_to_kb | dismissed.
     * POST /api/ai-chat/websites/{id}/learning/gaps/{gapId}/status
     * Body: status
     */
    public function updateGapStatus(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }

        $status = (string) $this->get('status', '');
        $gapId = (int) ($params['gapId'] ?? 0);
        $updated = $this->learningLoop->updateGapStatus($gapId, (int) $website->getAttribute('id'), $status);

        if (!$updated) {
            return $this->error('Gap not found or invalid status', 404);
        }

        return $this->success([], 'Knowledge gap status updated');
    }

    /**
     * إعادة مسح فجوات المعرفة من المحادثات المحوّلة مؤخرًا (Flywheel).
     * POST /api/ai-chat/websites/{id}/learning/gaps/scan
     */
    public function scan(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }

        $recorded = $this->learningLoop->scanKnowledgeGaps((int) $website->getAttribute('id'));

        return $this->success(['new_gaps_recorded' => $recorded], 'Knowledge gap scan completed');
    }

    /**
     * @param int $websiteId
     * @return Website|null
     */
    private function authorizedWebsite(int $websiteId): ?Website
    {
        if ($websiteId <= 0) {
            return null;
        }
        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->user['id']) {
            return null;
        }
        return $website;
    }
}

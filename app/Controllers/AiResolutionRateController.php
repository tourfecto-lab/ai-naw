<?php

/**
 * Tourfecto - AI Chat Resolution Rate Controller (بند 8)
 * @version 1.0.0
 *
 * سطح صحة/حالة إضافي (Additive): معدل الحل + جودة استدعاء الـAI لموقع
 * معيّن، محسوب إحصائيًا من ai_conversations + ai_usage_logs. لا يُعدِّل
 * AiAnalyticsController أو LearningLoopService القائمين إطلاقًا.
 */

class AiResolutionRateController extends Controller
{
    /** GET /api/ai-chat/websites/{id}/resolution-rate?since=Y-m-d */
    public function index(array $params = []): array
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

        return $this->success((new AiResolutionRateService())->resolutionRate((int) $website->getAttribute('id'), $since));
    }

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

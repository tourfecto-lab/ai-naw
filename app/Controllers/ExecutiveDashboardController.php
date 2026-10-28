<?php

/**
 * Tourfecto - Executive Dashboard Controller
 * Phase 15. Endpoint واحد بيرجع بالظبط اللي §16 طلبته: الدرجات الست +
 * Top 5 Opportunities + Top 5 Problems + This Week's Actions (بيعيد
 * استخدام ActionCenterService من Phase 12 - مفيش تكرار منطق) + Recent
 * Changes (Phase 13) + مقارنة المنافسين.
 * @version 1.0.0
 */
class ExecutiveDashboardController extends Controller
{
    /** GET /api/executive-dashboard?website_id=X */
    public function getDashboard(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->user['id']) {
            return $this->error('الموقع غير موجود', 404);
        }

        if (!class_exists('ExecutiveDashboardService')) {
            return $this->error('الخدمة غير متاحة', 500);
        }

        try {
            $service = new ExecutiveDashboardService();
            $userId = (int) $this->user['id'];

            $response = [
                'scores' => $service->getScores($this->db, $userId, $websiteId),
                'top_opportunities' => $service->getTopOpportunities($this->db, $userId, $websiteId),
                'top_problems' => $service->getTopProblems($this->db, $userId, $websiteId),
                'recent_changes' => $service->getRecentChanges($this->db, $userId, $websiteId),
                'competitor_snapshot' => $service->getCompetitorSnapshot($this->db, $userId, $websiteId),
            ];

            // "This Week's Actions" = نفس Action Center (Phase 12)، أعلى 5 بالأولوية بس -
            // مفيش تكرار لمنطق التجميع، إعادة استخدام مباشرة.
            if (class_exists('ActionCenterService')) {
                $actionService = new ActionCenterService();
                $allActions = $actionService->getActionItems($this->db, $userId, $websiteId);
                $response['this_weeks_actions'] = array_slice($allActions, 0, 5);
            } else {
                $response['this_weeks_actions'] = [];
            }

            return $this->success($response);
        } catch (Exception $e) {
            Logger::error('Executive Dashboard Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تحميل لوحة القيادة', 500);
        }
    }
}

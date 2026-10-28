<?php

/**
 * Tourfecto - AI Chat Platform
 * إعدادات المتابعة التلقائية القابلة للتعديل لكل شركة (بند 7):
 * Enable/Disable, Schedule (steps), Templates, Maximum Follow-ups,
 * Stop Conditions.
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class AiFollowupSettingsController extends Controller
{
    /** @var FollowUpAutomationService */
    private $followUpService;

    public function __construct()
    {
        parent::__construct();
        $this->followUpService = new FollowUpAutomationService();
    }

    /**
     * الإعدادات الحالية (أو الافتراضية المعطّلة لو لم تُضبط بعد).
     * GET /api/ai-chat/websites/{id}/followup-settings
     */
    public function show(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('الموقع غير موجود', 404);
        }

        return $this->success([
            'settings' => $this->followUpService->getRules((int) $website->getAttribute('id')),
        ]);
    }

    /**
     * تحديث إعدادات المتابعة التلقائية.
     * PUT /api/ai-chat/websites/{id}/followup-settings
     * Body: is_enabled (bool), steps (array of {after_hours, template, is_final?}),
     *       max_followups (int), stop_conditions (array)
     */
    public function update(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('الموقع غير موجود', 404);
        }

        $steps = $this->get('steps');
        if ($steps !== null && !is_array($steps)) {
            return $this->error('خطوات المتابعة مش بالشكل الصحيح', 422);
        }

        foreach ((array) $steps as $step) {
            if (!isset($step['after_hours']) || !isset($step['template'])) {
                return $this->error('كل خطوة محتاجة عدد ساعات ونص رسالة', 422);
            }
        }

        $maxFollowups = (int) $this->get('max_followups', 3);
        if ($maxFollowups < 1 || $maxFollowups > 10) {
            return $this->error('عدد المتابعات لازم يكون من 1 لـ 10', 422);
        }

        $saved = $this->followUpService->updateRules((int) $website->getAttribute('id'), [
            'is_enabled' => (bool) $this->get('is_enabled', false),
            'steps' => $steps ?? [],
            'max_followups' => $maxFollowups,
            // لو الصفحة مابعتتش شروط الإيقاف، نسيب المحفوظة زي ما هي (كان الحفظ بيمسحها)
            'stop_conditions' => $this->get('stop_conditions') !== null
                ? $this->get('stop_conditions')
                : ($this->followUpService->getRules((int) $website->getAttribute('id'))['stop_conditions'] ?? []),
        ]);

        if (!$saved) {
            return $this->error('تعذر حفظ إعدادات المتابعة', 500);
        }

        return $this->success([], 'تم حفظ إعدادات المتابعة');
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

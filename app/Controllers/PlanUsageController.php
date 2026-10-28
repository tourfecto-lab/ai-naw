<?php

/**
 * GET /api/plan-usage?feature=ai_assistant_message|website_generation|website_item
 * حصة العميل الحالية من الباقة (بيستخدمها الشريط الصغير في المساعد الذكي ومنشئ المواقع).
 */
class PlanUsageController extends Controller
{
    public function show(array $params = []): array
    {
        $uid = (int) ($this->user['id'] ?? ($_SESSION['user_id'] ?? 0));
        if ($uid <= 0) {
            return $this->error('غير مسجّل', 401);
        }
        $feature = (string) $this->get('feature', 'ai_assistant_message');
        if (!in_array($feature, ['ai_assistant_message', 'website_generation', 'website_item'], true)) {
            return $this->error('ميزة غير معروفة', 422);
        }
        return $this->success(PlanUsage::describe($uid, $feature));
    }
}

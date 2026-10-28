<?php

/**
 * Tourfecto - Business Onboarding Controller
 * Business Control Center Phase 17: Onboarding wizard progress (business-scoped)
 * @version 1.0.0
 *
 * بيجيب حالة خطوات الـOnboarding للـBusiness من بياناته الفعلية.
 * الوصول: أي عضو فريق له دور view فأعلى - التقدم مش سر، لازم الفريق
 * كله يشوف إيه ناقص.
 */
class BusinessOnboardingController extends Controller
{
    /** GET /api/business/{businessId}/onboarding */
    public function status(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $businessId = (int) ($params['businessId'] ?? 0);
        $access = new BusinessAccessService();
        $business = $access->getAccessibleBusiness($businessId, (int) $this->user['id']);
        if (!$business) {
            return $this->error('Business غير موجود', 404);
        }

        $progress = (new BusinessOnboardingService())->progress($businessId);

        return $this->success([
            'business_id' => $businessId,
            'onboarding' => $progress,
        ]);
    }
}

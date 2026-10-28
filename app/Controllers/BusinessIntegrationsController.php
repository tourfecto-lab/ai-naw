<?php

/**
 * Tourfecto - Business Integrations Controller
 * Business Control Center Phase 8-9: Integrations Center (business-scoped)
 * @version 1.0.0
 *
 * حالة التكاملات على مستوى الـBusiness (مجمعة لكل مواقعه) بدل صفحة
 * الـwebsite-scoped القديمة. الوصول: أي عضو فريق له دور view فأعلى
 * (owner/admin/member/viewer) - حالة التكاملات معلومات للعرض مش أسرار.
 *
 * أمان: بيفحص BusinessAccessService::getAccessibleBusiness() الأول
 * (404 للـbusinesses مش مملوكة/مش مصرّح بيها - منع تسريب وجود موارد
 * لمستخدمين تانيين)، وبعدين يلفّ BusinessIntegrationsService.
 */
class BusinessIntegrationsController extends Controller
{
    /** GET /api/business/{businessId}/integrations */
    public function index(array $params = []): array
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

        $status = (new BusinessIntegrationsService())->getBusinessStatus($businessId);

        return $this->success([
            'business_id' => $businessId,
            'integrations' => $status['integrations'],
            'connected_count' => $status['connected_count'],
            'total_count' => $status['total_count'],
        ]);
    }
}

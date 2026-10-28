<?php

/**
 * Tourfecto - Ad Next-Best-Action Controller (بند 5)
 * توصيات "الخطوة التالية" لكل حملة إعلانية من ترند إحصائي حقيقي
 * (انحدار خطي على بيانات مزامنة) — اقتراحات فقط، لا تنفيذ تلقائي.
 *
 * عزل تينانت مطابق لمنهجية AdsController القائمة: `resolveAdsAccess()`
 * تحلّ المالك (owner_id) من المستخدم الحالي أو `?owner_id=` مع التحقق
 * من أدوار ad_team_members عبر AdPermissionService.
 *
 * @version 1.0.0
 */
class AdNextBestActionController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    /** GET /api/ads/recommendations?campaign_id=&owner_id= - توليد توصيات اليوم */
    public function recommend(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $campaignId = (int) $this->get('campaign_id', 0);
        $service = new AdNextBestActionService();
        return $this->success([
            'recommendations' => $service->recommendations($access['owner_id'], $campaignId > 0 ? $campaignId : null),
        ]);
    }

    /** GET /api/ads/recommendations/history?status=&owner_id= - سجل التوصيات */
    public function history(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $status = (string) $this->get('status', '');
        if ($status !== '' && !in_array($status, ['pending', 'applied', 'dismissed'], true)) {
            return $this->error('حالة غير صالحة (pending/applied/dismissed)', 422);
        }
        $limit = max(1, min(200, (int) $this->get('limit', 50)));

        return $this->success([
            'recommendations' => (new AdNextBestActionService())->list($access['owner_id'], $limit, $status !== '' ? $status : null),
        ]);
    }

    /** POST /api/ads/recommendations/{id}/applied */
    public function apply(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        $ok = (new AdNextBestActionService())->markApplied($access['owner_id'], (int) ($params['id'] ?? 0));
        return $ok ? $this->success([], 'تم وضع علامة تم التطبيق على التوصية') : $this->error('التوصية غير موجودة', 404);
    }

    /** POST /api/ads/recommendations/{id}/dismiss */
    public function dismiss(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        $ok = (new AdNextBestActionService())->dismiss($access['owner_id'], (int) ($params['id'] ?? 0));
        return $ok ? $this->success([], 'تم تجاهل التوصية') : $this->error('التوصية غير موجودة', 404);
    }

    // ================================================================
    // عزل التينانت (نفس منهجية AdsController)
    // ================================================================

    /**
     * حلّ مالك حساب الإعلانات (owner_id) من المستخدم الحالي أو ?owner_id=
     * مع التحقق من أدوار الفريق عبر AdPermissionService.
     * @return array{owner_id:int, role:string}|null
     */
    private function resolveAdsAccess(string $minRole = 'viewer'): ?array
    {
        $currentUserId = (int) $this->user['id'];
        $requestedOwnerId = $this->get('owner_id') ? (int) $this->get('owner_id') : $currentUserId;

        if ($requestedOwnerId === $currentUserId) {
            return ['owner_id' => $currentUserId, 'role' => 'owner'];
        }

        $perm = new AdPermissionService();
        $access = $perm->resolveAccess($currentUserId, $requestedOwnerId);
        if (!$access['allowed'] || !$perm->hasMinRole($access['role'], $minRole)) {
            return null;
        }

        return ['owner_id' => $requestedOwnerId, 'role' => $access['role']];
    }
}

<?php

/**
 * Tourfecto - Ad Creative Controller (بند 1: إدارة الأصول الإعلانية)
 * نقاط API جاهزة لإدارة أصول الإعلانات (نص/صورة/فيديو) وتنويعاتها
 * (A/B/C) مع أداء حقيقي لكل Variant.
 *
 * عزل تينانت مطابق لمنهجية AdsController القائمة: `resolveAdsAccess()`
 * تحلّ المالك (owner_id) من المستخدم الحالي أو `?owner_id=` مع التحقق من
 * أدوار ad_team_members عبر AdPermissionService، وكل وصول لأي سجل يمر
 * بعد ذلك بفحص ملكية user_id في AdCreativeService.
 *
 * @version 1.0.0
 */
class AdCreativeController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    // ================================================================
    // المسارات
    // ================================================================

    /** GET /api/ads/creatives?campaign_id=&owner_id= */
    public function list(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $campaignId = (int) $this->get('campaign_id', 0);
        if ($campaignId <= 0) {
            return $this->error('معرف الحملة مطلوب', 422);
        }

        $service = new AdCreativeService();
        return $this->success($service->listForCampaign($access['owner_id'], $campaignId));
    }

    /** GET /api/ads/creatives/{id} */
    public function show(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $creative = (new AdCreativeService())->get($access['owner_id'], (int) ($params['id'] ?? 0));
        if ($creative === null) {
            return $this->error('الأصول الإعلانية غير موجود', 404);
        }
        return $this->success($creative);
    }

    /** POST /api/ads/creatives */
    public function create(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بإنشاء أصول على هذا الحساب', 403);
        }

        $campaignId = (int) $this->get('campaign_id', 0);
        if ($campaignId <= 0) {
            return $this->error('معرف الحملة مطلوب', 422);
        }

        try {
            $service = new AdCreativeService();
            $creative = $service->create($access['owner_id'], $campaignId, $this->all());
            if ($creative === null) {
                return $this->error('الحملة غير موجودة أو غير مملوكة', 404);
            }
            return $this->success($creative, 'تم إنشاء الأصول الإعلانية', 201);
        } catch (InvalidArgumentException $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        } catch (Exception $e) {
            Logger::error('AdCreative create error', ['message' => $e->getMessage()]);
            return $this->error('تعذر إنشاء الأصول الإعلانية', 500);
        }
    }

    /** PATCH /api/ads/creatives/{id} */
    public function update(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        $creative = (new AdCreativeService())->update($access['owner_id'], (int) ($params['id'] ?? 0), $this->all());
        if ($creative === null) {
            return $this->error('الأصول الإعلانية غير موجود', 404);
        }
        return $this->success($creative, 'تم تحديث الأصول الإعلانية');
    }

    /** POST /api/ads/creatives/{id}/status */
    public function setStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        $status = (string) $this->get('status', '');
        $service = new AdCreativeService();
        $creative = $service->setStatus($access['owner_id'], (int) ($params['id'] ?? 0), $status);
        if ($creative === null) {
            return $this->error('الأصول الإعلانية غير موجود أو حالة غير صالحة', 422);
        }
        return $this->success($creative, 'تم تحديث حالة الأصول الإعلانية');
    }

    /** DELETE /api/ads/creatives/{id} - أرشفة منطقية (تحافظ على البيانات) */
    public function delete(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        $service = new AdCreativeService();
        if (!$service->archive($access['owner_id'], (int) ($params['id'] ?? 0))) {
            return $this->error('الأصول الإعلانية غير موجود', 404);
        }
        return $this->success([], 'تمت أرشفة الأصول الإعلانية');
    }

    // ================================================================
    // التنويعات (Variants)
    // ================================================================

    /** POST /api/ads/creatives/{id}/variants */
    public function addVariant(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        try {
            $variant = (new AdCreativeService())->addVariant($access['owner_id'], (int) ($params['id'] ?? 0), $this->all());
            if ($variant === null) {
                return $this->error('الأصول الإعلانية غير موجود', 404);
            }
            return $this->success($variant, 'تمت إضافة التنويع', 201);
        } catch (Exception $e) {
            return $this->error('تعذر إضافة التنويع', 500);
        }
    }

    /** PATCH /api/ads/creative-variants/{id} */
    public function updateVariant(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        $variant = (new AdCreativeService())->updateVariant($access['owner_id'], (int) ($params['id'] ?? 0), $this->all());
        if ($variant === null) {
            return $this->error('التنويع غير موجود', 404);
        }
        return $this->success($variant, 'تم تحديث التنويع');
    }

    /** POST /api/ads/creative-variants/{id}/performance - أرقام أداء فعلية فقط */
    public function recordPerformance(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        try {
            $variant = (new AdCreativeService())->recordPerformance($access['owner_id'], (int) ($params['id'] ?? 0), $this->all());
            if ($variant === null) {
                return $this->error('التنويع غير موجود', 404);
            }
            return $this->success($variant, 'تم تحديث الأداء');
        } catch (InvalidArgumentException $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        } catch (Exception $e) {
            return $this->error('تعذر تحديث الأداء', 500);
        }
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

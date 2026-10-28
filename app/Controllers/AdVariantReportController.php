<?php

/**
 * Tourfecto - Ad/Variant Report Controller (بند 3)
 * تقارير على مستوى الإعلان/الـ variant بجوار AdReportService القائم
 * (مستوى الحملة). يجمع من ad_performance_reports (مزامنة فعلية) +
 * ad_creative_variants (أداء تنويعات خام حقيقي) مع مقاييس محسوبة عند
 * القراءة فقط — لا اختراع بيانات.
 *
 * عزل تينانت مطابق لمنهجية AdsController القائمة: `resolveAdsAccess()`
 * تحلّ المالك (owner_id) من المستخدم الحالي أو `?owner_id=` مع التحقق
 * من أدوار ad_team_members عبر AdPermissionService.
 *
 * @version 1.0.0
 */
class AdVariantReportController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    // ================================================================
    // التقارير
    // ================================================================

    /** GET /api/ads/reports/variants?period=&campaign_id=&owner_id= */
    public function report(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $period = (string) $this->get('period', 'weekly');
        if (!in_array($period, ['daily', 'weekly', 'monthly'], true)) {
            return $this->error('فترة غير صالحة (daily/weekly/monthly)', 422);
        }
        $campaignId = (int) $this->get('campaign_id', 0);

        $service = new AdVariantReportService();
        return $this->success($service->generate($access['owner_id'], $period, $campaignId > 0 ? $campaignId : null));
    }

    /** GET /api/ads/reports/variants/summary?owner_id= */
    public function summary(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }
        return $this->success((new AdVariantReportService())->variantSummary($access['owner_id']));
    }

    /** GET /api/ads/reports/best-variant?period=&min_impressions=&campaign_id=&owner_id= */
    public function bestVariant(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $period = (string) $this->get('period', 'weekly');
        if (!in_array($period, ['daily', 'weekly', 'monthly'], true)) {
            return $this->error('فترة غير صالحة (daily/weekly/monthly)', 422);
        }
        $minImpressions = (int) $this->get('min_impressions', 50);
        $campaignId = (int) $this->get('campaign_id', 0);

        $best = (new AdVariantReportService())->bestVariant(
            $access['owner_id'],
            max(1, $minImpressions),
            $period,
            $campaignId > 0 ? $campaignId : null
        );
        // null = مفيش variant عدّى حد الظهور لسه (حساب جديد) - مش خطأ
        return $this->success($best ?? ['best_variant' => null], $best === null ? 'لا توجد بيانات كافية بعد' : 'Success');
    }

    /** GET /api/ads/reports/creatives/{id}?owner_id= */
    public function creativeBreakdown(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $data = (new AdVariantReportService())->creativeBreakdown($access['owner_id'], (int) ($params['id'] ?? 0));
        if ($data === null) {
            return $this->error('الأصل الإعلاني غير موجود', 404);
        }
        return $this->success($data);
    }

    /** GET /api/ads/reports/campaigns/{id}?owner_id= */
    public function campaignBreakdown(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $data = (new AdVariantReportService())->campaignBreakdown($access['owner_id'], (int) ($params['id'] ?? 0));
        if ($data === null) {
            return $this->error('الحملة غير موجودة', 404);
        }
        return $this->success($data);
    }

    /** GET /api/ads/reports/variants/{id}?owner_id= */
    public function variantBreakdown(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $data = (new AdVariantReportService())->variantBreakdown($access['owner_id'], (int) ($params['id'] ?? 0));
        if ($data === null) {
            return $this->error('التنويع غير موجود', 404);
        }
        return $this->success($data);
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

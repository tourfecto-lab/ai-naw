<?php

/**
 * Tourfecto - Ad A/B Test Controller (بند 2)
 * تجارب A/B على تنويعات الأصول الإعلانية مع توزيع نسب قابل للضبط
 * ودلالة إحصائية حقيقية (chi-square على CTR مع تصحيح Yates).
 *
 * عزل تينانت مطابق لمنهجية AdsController القائمة: `resolveAdsAccess()`
 * تحلّ المالك (owner_id) من المستخدم الحالي أو `?owner_id=` مع التحقق
 * من أدوار ad_team_members عبر AdPermissionService، وكل وصول لأي سجل
 * يمر بعد ذلك بفحص ملكية user_id في AdAbTestService.
 *
 * @version 1.0.0
 */
class AdAbTestController extends Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    // ================================================================
    // التجارب
    // ================================================================

    /** GET /api/ads/ab-tests?campaign_id=&owner_id= */
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

        $service = new AdAbTestService();
        return $this->success($service->listForCampaign($access['owner_id'], $campaignId));
    }

    /** GET /api/ads/ab-tests/{id} */
    public function show(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $test = (new AdAbTestService())->get($access['owner_id'], (int) ($params['id'] ?? 0));
        if ($test === null) {
            return $this->error('التجربة غير موجودة', 404);
        }
        return $this->success($test);
    }

    /** POST /api/ads/ab-tests */
    public function create(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بإنشاء تجارب على هذا الحساب', 403);
        }

        $campaignId = (int) $this->get('campaign_id', 0);
        $creativeId = (int) $this->get('creative_id', 0);
        if ($campaignId <= 0 || $creativeId <= 0) {
            return $this->error('معرف الحملة ومعرف الأصل الإعلاني مطلوبان', 422);
        }

        try {
            $test = (new AdAbTestService())->createTest($access['owner_id'], $campaignId, $creativeId, (string) $this->get('name', ''));
            if ($test === null) {
                return $this->error('الحملة أو الأصل الإعلاني غير موجود أو غير مملوك', 404);
            }
            return $this->success($test, 'تم إنشاء التجربة', 201);
        } catch (InvalidArgumentException $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        } catch (Exception $e) {
            Logger::error('AdAbTest create error', ['message' => $e->getMessage()]);
            return $this->error('تعذر إنشاء التجربة', 500);
        }
    }

    /** POST /api/ads/ab-tests/{id}/start */
    public function start(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        try {
            $test = (new AdAbTestService())->startTest($access['owner_id'], (int) ($params['id'] ?? 0));
            if ($test === null) {
                return $this->error('التجربة غير موجودة', 404);
            }
            return $this->success($test, 'بدأت التجربة');
        } catch (InvalidArgumentException $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        } catch (Exception $e) {
            return $this->error('تعذر بدء التجربة', 500);
        }
    }

    /** POST /api/ads/ab-tests/{id}/complete - winner_variant_id = تنويع الأصول الفائز */
    public function complete(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        $winnerVariantId = (int) $this->get('winner_variant_id', 0);
        if ($winnerVariantId <= 0) {
            return $this->error('معرف التنويع الفائز مطلوب', 422);
        }

        try {
            $test = (new AdAbTestService())->completeTest($access['owner_id'], (int) ($params['id'] ?? 0), $winnerVariantId);
            if ($test === null) {
                return $this->error('التجربة غير موجودة', 404);
            }
            return $this->success($test, 'تم إعلان الفائز وإنهاء التجربة');
        } catch (InvalidArgumentException $e) {
            return $this->error(CustomerError::message($e->getMessage(), 400), 422);
        } catch (Exception $e) {
            return $this->error('تعذر إكمال التجربة', 500);
        }
    }

    /** DELETE /api/ads/ab-tests/{id} - أرشفة منطقية */
    public function delete(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        if (!(new AdAbTestService())->archiveTest($access['owner_id'], (int) ($params['id'] ?? 0))) {
            return $this->error('التجربة غير موجودة', 404);
        }
        return $this->success([], 'تمت أرشفة التجربة');
    }

    // ================================================================
    // أذرع التجربة
    // ================================================================

    /** POST /api/ads/ab-tests/{id}/variants */
    public function addVariant(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        $creativeVariantId = (int) $this->get('creative_variant_id', 0);
        if ($creativeVariantId <= 0) {
            return $this->error('معرف التنويع مطلوب', 422);
        }

        try {
            $test = (new AdAbTestService())->addVariant(
                $access['owner_id'],
                (int) ($params['id'] ?? 0),
                $creativeVariantId,
                (int) $this->get('weight_pct', 50),
                (bool) $this->get('is_control', false)
            );
            if ($test === null) {
                return $this->error('التجربة أو التنويع غير موجود', 404);
            }
            return $this->success($test, 'تمت إضافة ذراع التجربة', 201);
        } catch (Exception $e) {
            Logger::error('AdAbTest addVariant error', ['message' => $e->getMessage()]);
            return $this->error('تعذر إضافة ذراع التجربة', 500);
        }
    }

    /** PATCH /api/ads/ab-test-variants/{id} - تعديل الوزن */
    public function updateVariantWeight(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        $test = (new AdAbTestService())->updateVariantWeight(
            $access['owner_id'],
            (int) ($params['id'] ?? 0),
            (int) $this->get('weight_pct', 50)
        );
        if ($test === null) {
            return $this->error('ذراع التجربة غير موجود أو الوزن غير قابل للتعديل في الحالة الحالية', 422);
        }
        return $this->success($test, 'تم تحديث وزن الذراع');
    }

    /** DELETE /api/ads/ab-test-variants/{id} */
    public function removeVariant(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('manager');
        if (!$access) {
            return $this->error('غير مصرّح لك بتعديل هذا الحساب', 403);
        }

        $test = (new AdAbTestService())->removeVariant($access['owner_id'], (int) ($params['id'] ?? 0));
        if ($test === null) {
            return $this->error('ذراع التجربة غير موجود أو لا يمكن إزالته في الحالة الحالية', 422);
        }
        return $this->success($test, 'تمت إزالة ذراع التجربة');
    }

    // ================================================================
    // الإحصائيات والتنبؤ
    // ================================================================

    /** GET /api/ads/ab-tests/{id}/statistics */
    public function statistics(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $service = new AdAbTestService();
        $test = $service->get($access['owner_id'], (int) ($params['id'] ?? 0));
        if ($test === null) {
            return $this->error('التجربة غير موجودة', 404);
        }
        return $this->success($service->statistics($access['owner_id'], (int) ($params['id'] ?? 0)));
    }

    /** GET /api/ads/ab-tests/{id}/predict-winner */
    public function predictWinner(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $test = (new AdAbTestService())->get($access['owner_id'], (int) ($params['id'] ?? 0));
        if ($test === null) {
            return $this->error('التجربة غير موجودة', 404);
        }
        return $this->success((new AdAbTestService())->predictWinner($access['owner_id'], (int) ($params['id'] ?? 0)));
    }

    /** GET /api/ads/ab-tests/pick-variant?creative_id=&owner_id= - توزيع الحركة */
    public function pickVariantForTraffic(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $access = $this->resolveAdsAccess('viewer');
        if (!$access) {
            return $this->error('غير مصرّح لك بعرض هذا الحساب', 403);
        }

        $creativeId = (int) $this->get('creative_id', 0);
        if ($creativeId <= 0) {
            return $this->error('معرف الأصل الإعلاني مطلوب', 422);
        }
        return $this->success((new AdAbTestService())->pickVariantForTraffic($access['owner_id'], $creativeId));
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

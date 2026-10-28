<?php

/**
 * Tourfecto - Business Location Controller
 * Business Control Center - Phase 3
 * @version 1.0.0
 */
class BusinessLocationController extends Controller
{
    /**
     * نفس نسخة BusinessAccessService جوه الطلب الواحد - عشان الـroleCache
     * الجوه الـService يشتغل (بدل استعلامات متكررة لكل فحص). Phase 27.
     */
    private ?BusinessAccessService $accessService = null;

    private function access(): BusinessAccessService
    {
        if ($this->accessService === null) {
            $this->accessService = new BusinessAccessService();
        }
        return $this->accessService;
    }

    /**
     * يتأكد إن الـBusiness موجود ومتاح للمستخدم الحالي (RBAC عبر
     * BusinessAccessService - Phase 10-11) - نفس مبدأ IDOR-safety.
     */
    private function loadOwnedBusiness(int $businessId, int $userId): ?Business
    {
        return $this->access()->getAccessibleBusiness($businessId, $userId);
    }

    /**
     * يحمّل Location ويتأكد إن الـBusiness بتاعها متاح للمستخدم الحالي
     * مع صلاحية التعديل (canEdit). فحص على مستويين (location -> business
     * -> role) - مش كفاية نتأكد إن الـLocation موجودة، لازم نتأكد إن
     * الـBusiness اللي بتتبعها متاح للمستخدم مع صلاحية الكتابة فعلًا
     * (viewer يشوف بس). مستخدمة في عمليات التعديل/الحذف بس.
     */
    private function loadOwnedLocation(int $locationId, int $userId): ?BusinessLocation
    {
        $location = (new BusinessLocation())->find($locationId);
        if (!$location) {
            return null;
        }
        $businessId = (int) $location->getAttribute('business_id');
        if (!$this->access()->canEdit($businessId, $userId)) {
            return null;
        }
        return $location;
    }

    /** GET /api/business/{businessId}/locations */
    public function index(array $params = []): array
    {
        if (empty($this->user['id'])) {
            return $this->error('غير مسجل دخول', 401);
        }

        $business = $this->loadOwnedBusiness((int) ($params['businessId'] ?? 0), (int) $this->user['id']);
        if (!$business) {
            return $this->error('Business Profile غير موجود', 404);
        }

        $locations = (new BusinessLocation())->where(
            ['business_id' => (int) $business->getAttribute('id')],
            ['is_primary' => 'DESC', 'id' => 'ASC']
        );

        return $this->success(['locations' => array_map(fn ($l) => $l->toArray(), $locations)]);
    }

    /** POST /api/business/{businessId}/locations */
    public function store(array $params = []): array
    {
        if (empty($this->user['id'])) {
            return $this->error('غير مسجل دخول', 401);
        }
        $userId = (int) $this->user['id'];

        $business = $this->loadOwnedBusiness((int) ($params['businessId'] ?? 0), $userId);
        if (!$business) {
            return $this->error('Business Profile غير موجود', 404);
        }
        if (!$this->access()->canEdit((int) $business->getAttribute('id'), $userId)) {
            return $this->error('ليست لديك صلاحية تعديل البيانات', 403);
        }

        $validationError = $this->validateLocationInput(false);
        if ($validationError !== null) {
            return $validationError;
        }

        try {
            $service = new BusinessLocationService();
            $location = $service->create((int) $business->getAttribute('id'), $this->all());
        } catch (\Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('BusinessLocation create failed: ' . $e->getMessage());
            }
            return $this->error('تعذر إنشاء الموقع', 500);
        }

        (new BusinessContextService())->invalidate((int) $business->getAttribute('id'));

        BusinessAuditLog::record((int) $business->getAttribute('id'), $userId, 'location_created', 'success', 'location', (string) $location->getAttribute('id'));

        return $this->success(['location' => $location->toArray()], 'تم إنشاء الموقع', 201);
    }

    /** PUT /api/business/locations/{id} */
    public function update(array $params = []): array
    {
        if (empty($this->user['id'])) {
            return $this->error('غير مسجل دخول', 401);
        }

        $location = $this->loadOwnedLocation((int) ($params['id'] ?? 0), (int) $this->user['id']);
        if (!$location) {
            return $this->error('الموقع غير موجود', 404);
        }

        $validationError = $this->validateLocationInput(true);
        if ($validationError !== null) {
            return $validationError;
        }

        try {
            $service = new BusinessLocationService();
            if ($service->update($location, $this->all()) === false) {
                return $this->error('تعذر تحديث الموقع', 500);
            }
        } catch (\Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('BusinessLocation update failed: ' . $e->getMessage());
            }
            return $this->error('تعذر تحديث الموقع', 500);
        }

        (new BusinessContextService())->invalidate((int) $location->getAttribute('business_id'));

        BusinessAuditLog::record((int) $location->getAttribute('business_id'), (int) $this->user['id'], 'location_updated', 'success', 'location', (string) $location->getAttribute('id'));

        return $this->success(['location' => $location->toArray()], 'تم تحديث الموقع');
    }

    /** DELETE /api/business/locations/{id} */
    public function destroy(array $params = []): array
    {
        if (empty($this->user['id'])) {
            return $this->error('غير مسجل دخول', 401);
        }

        $location = $this->loadOwnedLocation((int) ($params['id'] ?? 0), (int) $this->user['id']);
        if (!$location) {
            return $this->error('الموقع غير موجود', 404);
        }

        $businessId = (int) $location->getAttribute('business_id'); // لازم قبل delete() - بتفضّي attributes الموديل بعد النجاح

        $service = new BusinessLocationService();
        if ($service->delete($location) === false) {
            return $this->error('تعذر حذف الموقع', 500);
        }

        (new BusinessContextService())->invalidate($businessId);

        BusinessAuditLog::record($businessId, (int) $this->user['id'], 'location_deleted', 'success', 'location', (string) $location->getAttribute('id'));

        return $this->success([], 'تم حذف الموقع');
    }

    private function validateLocationInput(bool $isUpdate = false): ?array
    {
        $rules = [
            'name' => 'max_length:255',
            'city' => 'max_length:150',
            'address' => 'max_length:500',
            'postal_code' => 'max_length:20',
            'phone' => 'max_length:50',
            'email' => 'email|max_length:255',
        ];

        if (!$this->validate($rules)) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        if ($this->has('country_code') && $this->get('country_code') !== '') {
            if (!preg_match('/^[A-Za-z]{2}$/', (string) $this->get('country_code'))) {
                return $this->error('كود الدولة غير صحيح', 422, ['country_code' => ['يجب أن يكون كود ISO 3166-1 من حرفين']]);
            }
        }

        foreach (['latitude', 'longitude'] as $coord) {
            if ($this->has($coord) && $this->get($coord) !== '' && $this->get($coord) !== null) {
                if (!is_numeric($this->get($coord))) {
                    return $this->error('إحداثيات غير صحيحة', 422, [$coord => ['يجب أن يكون رقم']]);
                }
                $value = (float) $this->get($coord);
                if ($coord === 'latitude' && ($value < -90 || $value > 90)) {
                    return $this->error('خط العرض خارج النطاق', 422, ['latitude' => ['يجب أن يكون بين -90 و 90']]);
                }
                if ($coord === 'longitude' && ($value < -180 || $value > 180)) {
                    return $this->error('خط الطول خارج النطاق', 422, ['longitude' => ['يجب أن يكون بين -180 و 180']]);
                }
            }
        }

        // اسم الموقع: مطلوب عند الإنشاء - فرع بلا اسم مبهم للفريق/الـAI Context
        if (!$isUpdate && (!$this->has('name') || trim((string) $this->get('name')) === '')) {
            return $this->error('اسم الموقع مطلوب', 422, ['name' => ['الحقل مطلوب']]);
        }

        return null;
    }
}

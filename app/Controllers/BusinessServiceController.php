<?php

/**
 * Tourfecto - Business Service Controller
 * Business Control Center - Phase 4
 * @version 1.0.0
 */
class BusinessServiceController extends Controller
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

    private function loadOwnedBusiness(int $businessId, int $userId): ?Business
    {
        return $this->access()->getAccessibleBusiness($businessId, $userId);
    }

    /** يحمّل Service مع فحص صلاحية التعديل على الـBusiness التابعة لها (viewer مش بيعدّل) */
    private function loadOwnedService(int $serviceId, int $userId): ?BusinessService
    {
        $service = (new BusinessService())->find($serviceId);
        if (!$service) {
            return null;
        }
        if (!$this->access()->canEdit((int) $service->getAttribute('business_id'), $userId)) {
            return null;
        }
        return $service;
    }

    /** GET /api/business/{businessId}/services */
    public function index(array $params = []): array
    {
        if (empty($this->user['id'])) {
            return $this->error('غير مسجل دخول', 401);
        }

        $business = $this->loadOwnedBusiness((int) ($params['businessId'] ?? 0), (int) $this->user['id']);
        if (!$business) {
            return $this->error('Business Profile غير موجود', 404);
        }

        $services = (new BusinessService())->where(
            ['business_id' => (int) $business->getAttribute('id')],
            ['active' => 'DESC', 'name' => 'ASC']
        );

        return $this->success(['services' => array_map(fn ($s) => $s->toArray(), $services)]);
    }

    /** POST /api/business/{businessId}/services */
    public function store(array $params = []): array
    {
        if (empty($this->user['id'])) {
            return $this->error('غير مسجل دخول', 401);
        }

        $business = $this->loadOwnedBusiness((int) ($params['businessId'] ?? 0), (int) $this->user['id']);
        if (!$business) {
            return $this->error('Business Profile غير موجود', 404);
        }
        if (!$this->access()->canEdit((int) $business->getAttribute('id'), (int) $this->user['id'])) {
            return $this->error('ليست لديك صلاحية تعديل البيانات', 403);
        }

        if (!$this->validate(['name' => 'required|max_length:255', 'description' => 'max_length:2000', 'category' => 'max_length:100'])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        $targetCheck = $this->validateTargetArrays();
        if (!$targetCheck['ok']) {
            return $this->error('بيانات غير صحيحة', 422, $targetCheck['error']);
        }

        $businessId = (int) $business->getAttribute('id');
        $slugManager = new BusinessServiceManager();
        $slug = $slugManager->generateUniqueSlug($businessId, (string) $this->get('name'));

        $service = new BusinessService();
        $service->setAttribute('business_id', $businessId);
        $service->setAttribute('name', trim((string) $this->get('name')));
        $service->setAttribute('slug', $slug);
        $service->setAttribute('active', 1);
        $this->applyOptionalFields($service);

        if ($service->save() === false) {
            return $this->error('تعذر إنشاء الخدمة', 500);
        }

        (new BusinessContextService())->invalidate($businessId);

        BusinessAuditLog::record($businessId, (int) $this->user['id'], 'service_created', 'success', 'service', (string) $service->getAttribute('id'));

        return $this->success(['service' => $service->toArray()], 'تم إنشاء الخدمة', 201);
    }

    /** PUT /api/business/services/{id} */
    public function update(array $params = []): array
    {
        if (empty($this->user['id'])) {
            return $this->error('غير مسجل دخول', 401);
        }

        $service = $this->loadOwnedService((int) ($params['id'] ?? 0), (int) $this->user['id']);
        if (!$service) {
            return $this->error('الخدمة غير موجودة', 404);
        }

        if (!$this->validate(['name' => 'max_length:255', 'description' => 'max_length:2000', 'category' => 'max_length:100'])) {
            return $this->error('بيانات غير صحيحة', 422, $this->getErrors());
        }

        $targetCheck = $this->validateTargetArrays();
        if (!$targetCheck['ok']) {
            return $this->error('بيانات غير صحيحة', 422, $targetCheck['error']);
        }

        if ($this->has('name') && trim((string) $this->get('name')) !== '') {
            $newName = trim((string) $this->get('name'));
            $service->setAttribute('name', $newName);
            // لو الاسم اتغيّر، نولّد slug جديد بس لو مفيش slug صريح
            // مبعوت من الفرونت إند - عشان مايتكسرش أي رابط خارجي (SEO)
            // كان بالفعل بيشاور على الـslug القديم من غير سبب واضح.
            if (!$this->has('slug')) {
                $slugManager = new BusinessServiceManager();
                $service->setAttribute('slug', $slugManager->generateUniqueSlug(
                    (int) $service->getAttribute('business_id'),
                    $newName,
                    (int) $service->getAttribute('id')
                ));
            }
        }

        if ($this->has('slug') && trim((string) $this->get('slug')) !== '') {
            $slugManager = new BusinessServiceManager();
            // حتى لو المستخدم بعت slug صريح، لازم نتأكد إنه لسه فريد
            // (مش نثق فيه كما هو من الـFrontend)
            $requestedSlug = trim((string) $this->get('slug'));
            $service->setAttribute('slug', $slugManager->generateUniqueSlug(
                (int) $service->getAttribute('business_id'),
                $requestedSlug,
                (int) $service->getAttribute('id')
            ));
        }

        $this->applyOptionalFields($service);

        if ($service->save() === false) {
            return $this->error('تعذر تحديث الخدمة', 500);
        }

        (new BusinessContextService())->invalidate((int) $service->getAttribute('business_id'));

        BusinessAuditLog::record((int) $service->getAttribute('business_id'), (int) $this->user['id'], 'service_updated', 'success', 'service', (string) $service->getAttribute('id'));

        return $this->success(['service' => $service->toArray()], 'تم تحديث الخدمة');
    }

    /** DELETE /api/business/services/{id} */
    public function destroy(array $params = []): array
    {
        if (empty($this->user['id'])) {
            return $this->error('غير مسجل دخول', 401);
        }

        $service = $this->loadOwnedService((int) ($params['id'] ?? 0), (int) $this->user['id']);
        if (!$service) {
            return $this->error('الخدمة غير موجودة', 404);
        }

        $businessId = (int) $service->getAttribute('business_id'); // لازم قبل delete() - بتفضّي attributes الموديل بعد النجاح

        if ($service->delete() === false) {
            return $this->error('تعذر حذف الخدمة', 500);
        }

        (new BusinessContextService())->invalidate($businessId);

        BusinessAuditLog::record($businessId, (int) $this->user['id'], 'service_deleted', 'success', 'service', (string) $service->getAttribute('id'));

        return $this->success([], 'تم حذف الخدمة');
    }

    private function applyOptionalFields(BusinessService $service): void
    {
        if ($this->has('description')) {
            $service->setAttribute('description', trim((string) $this->get('description')));
        }
        if ($this->has('category')) {
            $service->setAttribute('category', trim((string) $this->get('category')));
        }
        if ($this->has('active')) {
            $service->setAttribute('active', !empty($this->get('active')) ? 1 : 0);
        }
        if ($this->has('target_markets') && is_array($this->get('target_markets'))) {
            $markets = array_filter($this->get('target_markets'), 'is_string');
            $service->setAttribute('target_markets', json_encode(array_values(array_unique(array_map('strtoupper', $markets)))));
        }
        if ($this->has('target_languages') && is_array($this->get('target_languages'))) {
            $languages = array_filter($this->get('target_languages'), 'is_string');
            $service->setAttribute('target_languages', json_encode(array_values(array_unique($languages))));
        }
    }

    /**
     * فحص تنسيق مصفوفات target_markets/target_languages قبل الحفظ -
     * نفس قيد ISO اللي في BusinessTargetMarketController.
     * @return array{ok:bool,error?:array}
     */
    private function validateTargetArrays(): array
    {
        if ($this->has('target_markets') && $this->get('target_markets') !== null) {
            if (!is_array($this->get('target_markets'))) {
                return ['ok' => false, 'error' => ['target_markets' => ['يجب أن تكون قائمة (Array)']]];
            }
            foreach ($this->get('target_markets') as $code) {
                if (!is_string($code) || !preg_match('/^[A-Za-z]{2}$/', $code)) {
                    return ['ok' => false, 'error' => ['target_markets' => ['كل قيمة لازم تكون كود ISO 3166-1 من حرفين']]];
                }
            }
        }
        if ($this->has('target_languages') && $this->get('target_languages') !== null) {
            if (!is_array($this->get('target_languages'))) {
                return ['ok' => false, 'error' => ['target_languages' => ['يجب أن تكون قائمة (Array)']]];
            }
            foreach ($this->get('target_languages') as $lang) {
                if (!is_string($lang) || !preg_match('/^[A-Za-z]{2,3}$/', $lang)) {
                    return ['ok' => false, 'error' => ['target_languages' => ['كل قيمة لازم تكون كود ISO 639 من حرفين أو ثلاثة']]];
                }
            }
        }
        return ['ok' => true];
    }
}

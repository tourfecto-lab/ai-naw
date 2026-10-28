<?php

/**
 * Tourfecto - Business Target Market Controller
 * Business Control Center - Phase 5
 * @version 1.0.0
 */
class BusinessTargetMarketController extends Controller
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

    /** GET /api/business/{businessId}/markets */
    public function show(array $params = []): array
    {
        if (empty($this->user['id'])) {
            return $this->error('غير مسجل دخول', 401);
        }

        $business = $this->loadOwnedBusiness((int) ($params['businessId'] ?? 0), (int) $this->user['id']);
        if (!$business) {
            return $this->error('Business Profile غير موجود', 404);
        }

        $rows = (new BusinessTargetMarket())->where(['business_id' => (int) $business->getAttribute('id')], [], 1);
        if (empty($rows)) {
            // مفيش بيانات لسه - حالة طبيعية، مش خطأ (لسه مكمّلش Onboarding)
            return $this->success(['target_markets' => null]);
        }

        return $this->success(['target_markets' => $rows[0]->toArray()]);
    }

    /**
     * PUT /api/business/{businessId}/markets
     * Upsert: بينشئ لو مفيش، بيحدّث لو موجود - نفس السجل دايمًا (1:1)،
     * مفيش داعي لـstore/update منفصلين زي Locations/Services لأن مفيش
     * أكتر من نسخة ممكنة أصلًا.
     */
    public function upsert(array $params = []): array
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

        if ($this->has('customer_type') && $this->get('customer_type') !== '') {
            if (!in_array($this->get('customer_type'), BusinessTargetMarket::allowedCustomerTypes(), true)) {
                return $this->error('نوع العملاء غير صحيح', 422, ['customer_type' => ['يجب أن يكون b2b أو b2c أو both']]);
            }
        }

        foreach (['target_countries', 'target_cities', 'target_languages', 'customer_segments'] as $arrayField) {
            if ($this->has($arrayField) && !is_array($this->get($arrayField))) {
                return $this->error('بيانات غير صحيحة', 422, [$arrayField => ['يجب أن تكون قائمة (Array)']]);
            }
        }

        // فحص ISO لأكواد الدول - قائمة نفس النمط المستخدم في UserController
        // (ما فيش استدعاء مباشر لكلاس تاني هنا عمدًا - نفس القيد الموثّق
        // من أول المشروع: كلاسات Helper/Config جديدة محتاجة composer
        // dump-autoload مش متاح، فالفحص هنا بسيط بالـRegex بدل الاعتماد
        // على قائمة مركزية غير مضمونة التحميل).
        if ($this->has('target_countries')) {
            foreach ($this->get('target_countries') as $code) {
                if (!is_string($code) || !preg_match('/^[A-Za-z]{2}$/', $code)) {
                    return $this->error('كود دولة غير صحيح في target_countries', 422, ['target_countries' => ['كل قيمة لازم تكون كود ISO 3166-1 من حرفين']]);
                }
            }
        }

        // فحص ISO للغات - نفس منطق target_countries لكن بنمط ISO 639
        // (حرفين أو ثلاثة) لأن ده جدول لغات مش دول.
        if ($this->has('target_languages')) {
            foreach ($this->get('target_languages') as $lang) {
                if (!is_string($lang) || !preg_match('/^[A-Za-z]{2,3}$/', $lang)) {
                    return $this->error('كود لغة غير صحيح في target_languages', 422, ['target_languages' => ['كل قيمة لازم تكون كود ISO 639 من حرفين أو ثلاثة']]);
                }
            }
        }

        $businessId = (int) $business->getAttribute('id');
        $existing = (new BusinessTargetMarket())->where(['business_id' => $businessId], [], 1);
        $record = !empty($existing) ? $existing[0] : new BusinessTargetMarket();
        if (empty($existing)) {
            $record->setAttribute('business_id', $businessId);
        }

        if ($this->has('target_countries')) {
            $record->setAttribute('target_countries', json_encode(array_values(array_unique(array_map('strtoupper', $this->get('target_countries'))))));
        }
        if ($this->has('target_cities')) {
            $record->setAttribute('target_cities', json_encode(array_values(array_unique($this->get('target_cities')))));
        }
        if ($this->has('target_languages')) {
            $record->setAttribute('target_languages', json_encode(array_values(array_unique($this->get('target_languages')))));
        }
        if ($this->has('customer_segments')) {
            $record->setAttribute('customer_segments', json_encode(array_values(array_unique($this->get('customer_segments')))));
        }
        if ($this->has('customer_type')) {
            $record->setAttribute('customer_type', $this->get('customer_type'));
        }

        if ($record->save() === false) {
            return $this->error('تعذر حفظ الأسواق المستهدفة', 500);
        }

        (new BusinessContextService())->invalidate($businessId);

        BusinessAuditLog::record($businessId, (int) $this->user['id'], 'target_markets_updated', 'success', 'business', (string) $businessId);

        return $this->success(['target_markets' => $record->toArray()], 'تم الحفظ');
    }
}

<?php

/**
 * Tourfecto - Business API Key Service
 * Business Control Center Phase 12: Business-scoped API Keys
 * @version 1.0.0
 *
 * إدارة مفاتيح الـBusiness البرمجية (قائمة/إنشاء/إلغاء) مع قواعد الأعمال
 * مركزية هنا (الحد الأقصى، النطاقات المسموحة، التسجيل في الـAudit Log).
 * الـController بيتكفل بفحص الصلاحية الخام (owner/admin فقط) وبعدين
 * بيستدعي الـService ده.
 */
class BusinessApiKeyService
{
    /** الحد الأقصى للمفاتيح الفعّالة لكل Business - يمنع إغراق عشوائي */
    public const MAX_ACTIVE_KEYS = 10;

    /**
     * قائمة مفاتيح Business (المفعّلة والملغية) مرتبة بالأحدث أولًا.
     *
     * @return array<int,array>
     */
    public function list(int $businessId): array
    {
        // M2 (Phase 27 performance audit): الترتيب اتعمل في SQL (ORDER BY
        // created_at DESC) بدل جلب كل الصفوف وفرزها في PHP - نفس النتيجة،
        // استعلام واحد بدون usort.
        return array_map(
            fn ($key) => $key->toSafeArray(),
            (new BusinessApiKey())->where(['business_id' => $businessId], ['created_at' => 'DESC'], 0)
        );
    }

    /**
     * إنشاء مفتاح Business جديد (المفتاح الخام بيترجع مرة واحدة بس).
     *
     * @return array{ok:bool,error?:string,key?:array,raw_key?:string}
     */
    public function create(int $businessId, int $actorUserId, string $name, string $scope): array
    {
        $name = trim(strip_tags($name));
        if ($name === '' || mb_strlen($name) > 120) {
            return ['ok' => false, 'error' => 'اسم المفتاح مطلوب (بحد أقصى 120 حرف)'];
        }
        if (!in_array($scope, BusinessApiKey::allowedScopes(), true)) {
            return ['ok' => false, 'error' => 'نطاق المفتاح غير صالح'];
        }

        $activeCount = count(array_filter(
            (new BusinessApiKey())->where(['business_id' => $businessId], [], 0),
            fn ($k) => !$k->getAttribute('revoked_at')
        ));
        if ($activeCount >= self::MAX_ACTIVE_KEYS) {
            return ['ok' => false, 'error' => 'وصلت للحد الأقصى (' . self::MAX_ACTIVE_KEYS . ' مفاتيح فعّالة) - ألغِ مفتاح قديم أولًا'];
        }

        $result = BusinessApiKey::generateFor($businessId, $actorUserId, $name, $scope);

        BusinessAuditLog::record($businessId, $actorUserId, 'api_key_created', 'success', 'api_key', (string) $result['model']->getAttribute('id'), ['scope' => $scope]);

        $this->notifyOwner($businessId, $name, 'api_key_created');

        return ['ok' => true, 'key' => $result['model']->toSafeArray(), 'raw_key' => $result['raw_key']];
    }

    /**
     * إلغاء مفتاح. لازم يخص الـBusiness المعني (منع IDOR عبر الـkey id).
     *
     * @return array{ok:bool,error?:string,already_revoked?:bool}
     */
    public function revoke(int $businessId, int $actorUserId, int $keyId): array
    {
        $key = (new BusinessApiKey())->find($keyId);
        if (!$key || (int) $key->getAttribute('business_id') !== $businessId) {
            return ['ok' => false, 'error' => 'المفتاح غير موجود'];
        }
        if ($key->getAttribute('revoked_at')) {
            return ['ok' => true, 'already_revoked' => true];
        }

        $key->revoke();

        BusinessAuditLog::record($businessId, $actorUserId, 'api_key_revoked', 'success', 'api_key', (string) $keyId);

        $this->notifyOwner($businessId, (string) $key->getAttribute('name'), 'api_key_revoked');

        return ['ok' => true];
    }

    private function notifyOwner(int $businessId, string $keyName, string $event): void
    {
        if (!class_exists('BusinessNotificationService')) {
            return;
        }
        $business = (new Business())->find($businessId);
        if (!$business) {
            return;
        }
        $ownerId = (int) $business->getAttribute('owner_user_id');
        $businessName = trim((string) $business->getAttribute('trade_name'));
        if ($businessName === '') {
            $businessName = trim((string) $business->getAttribute('legal_name'));
        }
        if ($businessName === '') {
            $businessName = 'النشاط التجاري';
        }

        $payload = $event === 'api_key_created'
            ? BusinessNotificationService::apiKeyCreated($ownerId, $businessName, $keyName)
            : BusinessNotificationService::apiKeyRevoked($ownerId, $businessName, $keyName);

        BusinessNotificationService::push($payload);
    }
}

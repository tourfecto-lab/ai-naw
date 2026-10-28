<?php

/**
 * Tourfecto - Business API Keys Controller
 * Business Control Center Phase 12: Business-scoped API Keys
 * @version 1.0.0
 *
 * قائمة/إنشاء/إلغاء مفاتيح الـBusiness البرمجية.
 *
 * Authorization: إدارة المفاتيح فعل حساس - owner/admin بس (canManageKeys).
 * الـmember والـviewer مش بيتحكموا في المفاتيح. نفس نمط RBAC المركزي:
 * getAccessibleBusiness (404 للـbusinesses غير مصرّح بيها) + canManageKeys
 * (403 للـviewer/member المصرّح له بالعرض).
 */
class BusinessApiKeyController extends Controller
{
    /** GET /api/business/{businessId}/api-keys */
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
        if (!$access->canManageKeys($businessId, (int) $this->user['id'])) {
            return $this->error('ليست لديك صلاحية عرض مفاتيح الـBusiness', 403);
        }

        return $this->success([
            'keys' => (new BusinessApiKeyService())->list($businessId),
        ]);
    }

    /** POST /api/business/{businessId}/api-keys - { name, scope } */
    public function store(array $params = []): array
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
        if (!$access->canManageKeys($businessId, (int) $this->user['id'])) {
            return $this->error('ليست لديك صلاحية إدارة مفاتيح الـBusiness', 403);
        }

        $result = (new BusinessApiKeyService())->create(
            $businessId,
            (int) $this->user['id'],
            (string) $this->get('name', ''),
            (string) $this->get('scope', 'read')
        );

        if (!$result['ok']) {
            return $this->error($result['error'], 422);
        }

        return $this->success([
            'key' => $result['key'],
            'raw_key' => $result['raw_key'],
        ], 'تم إنشاء المفتاح بنجاح - احفظه الآن، لن يظهر كاملًا مرة أخرى', 201);
    }

    /** POST /api/business/{businessId}/api-keys/{id}/revoke */
    public function revoke(array $params = []): array
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
        if (!$access->canManageKeys($businessId, (int) $this->user['id'])) {
            return $this->error('ليست لديك صلاحية إدارة مفاتيح الـBusiness', 403);
        }

        $result = (new BusinessApiKeyService())->revoke(
            $businessId,
            (int) $this->user['id'],
            (int) ($params['id'] ?? 0)
        );

        if (!$result['ok']) {
            return $this->error($result['error'], 404);
        }

        if (!empty($result['already_revoked'])) {
            return $this->success([], 'المفتاح ملغي بالفعل');
        }
        return $this->success([], 'تم إلغاء المفتاح');
    }
}

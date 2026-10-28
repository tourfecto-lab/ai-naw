<?php

/**
 * Tourfecto - Business Audit Log Controller
 * Business Control Center Phase 13-14: Centralized Business Audit Log
 * @version 1.0.0
 *
 * قراءة السجل الموحّد لأحداث الـBusiness.
 *
 * Authorization: السجل فيه تفاصيل أمنية (مين عمل إيه) - owner/admin بس
 * (canReadAudit). الـmember والـviewer مش بيشوفوا السجل (الـviewer أصلاً
 * للعرض التجاري مش للتفاصيل الأمنية، والـmember بيلاقي حدوده).
 *
 * أمان: getAccessibleBusiness (404 للـbusinesses غير مصرّح بيها) + canReadAudit.
 */
class BusinessAuditLogController extends Controller
{
    /** GET /api/business/{businessId}/audit-log */
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
        if (!$access->canReadAudit($businessId, (int) $this->user['id'])) {
            return $this->error('ليست لديك صلاحية عرض سجل الـBusiness', 403);
        }

        $page = max(1, (int) $this->get('page', 1));
        $perPage = max(1, min(100, (int) $this->get('per_page', 20)));

        $result = BusinessAuditService::list(
            $businessId,
            [
                'action' => (string) $this->get('action', ''),
                'actor_user_id' => (string) $this->get('actor_user_id', ''),
                'search' => (string) $this->get('search', ''),
                'from' => (string) $this->get('from', ''),
                'to' => (string) $this->get('to', ''),
            ],
            $page,
            $perPage
        );

        return $this->success([
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
            'available_actions' => BusinessAuditService::actionLabels(),
        ]);
    }
}

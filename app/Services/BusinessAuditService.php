<?php

/**
 * Tourfecto - Business Audit Service
 * Business Control Center Phase 13-14: Centralized Business Audit Log
 * @version 1.0.0
 *
 * واجهة رفيعة فوق BusinessAuditLog - بتوحّد ثوابت أحداث الـBusiness
 * (عشان المتحكمات والخدمات ما يبعتروش على أسماء أحداث عشوائية كل مرة
 * وميبقاش في "solo strings" مبعثرة)، وتلفّ الـlistFor بتصفية إضافية
 * للأدوار (owner/admin بس يشوفوا السجل - الـmember والـviewer مش
 * محتاجين التفاصيل الأمنية دي).
 *
 * pure: actionLabels() بتعلّم الأسماء القابلة للعرض بدون DB.
 */
class BusinessAuditService
{
    /** أسماء الأحداث المعروفة على مستوى الـBusiness - مصدر الحقيقة الوحيد */
    public const ACTION_BUSINESS_CREATED = 'business_created';
    public const ACTION_BUSINESS_UPDATED = 'business_updated';
    public const ACTION_LOCATION_CREATED = 'location_created';
    public const ACTION_LOCATION_UPDATED = 'location_updated';
    public const ACTION_LOCATION_DELETED = 'location_deleted';
    public const ACTION_SERVICE_CREATED = 'service_created';
    public const ACTION_SERVICE_UPDATED = 'service_updated';
    public const ACTION_SERVICE_DELETED = 'service_deleted';
    public const ACTION_MARKETS_UPDATED = 'target_markets_updated';
    public const ACTION_AI_CONTEXT_UPDATED = 'ai_context_updated';
    public const ACTION_BRAND_UPDATED = 'brand_settings_updated';
    public const ACTION_MEMBER_INVITED = 'member_invited';
    public const ACTION_MEMBER_JOINED = 'member_joined';
    public const ACTION_MEMBER_REMOVED = 'member_removed';
    public const ACTION_MEMBER_ROLE_CHANGED = 'member_role_changed';
    public const ACTION_API_KEY_CREATED = 'api_key_created';
    public const ACTION_API_KEY_REVOKED = 'api_key_revoked';
    public const ACTION_ONBOARDING_STEP = 'onboarding_step_completed';

    /**
     * اسم عربي/واجهة قابل للعرض لكل حدث - pure.
     */
    public static function actionLabels(): array
    {
        return [
            self::ACTION_BUSINESS_CREATED => 'إنشاء الـBusiness',
            self::ACTION_BUSINESS_UPDATED => 'تعديل بيانات الـBusiness',
            self::ACTION_LOCATION_CREATED => 'إضافة موقع',
            self::ACTION_LOCATION_UPDATED => 'تعديل موقع',
            self::ACTION_LOCATION_DELETED => 'حذف موقع',
            self::ACTION_SERVICE_CREATED => 'إضافة خدمة',
            self::ACTION_SERVICE_UPDATED => 'تعديل خدمة',
            self::ACTION_SERVICE_DELETED => 'حذف خدمة',
            self::ACTION_MARKETS_UPDATED => 'تعديل الأسواق المستهدفة',
            self::ACTION_AI_CONTEXT_UPDATED => 'تعديل السياق الذكي',
            self::ACTION_BRAND_UPDATED => 'تعديل الهوية البصرية',
            self::ACTION_MEMBER_INVITED => 'دعوة عضو',
            self::ACTION_MEMBER_JOINED => 'انضمام عضو',
            self::ACTION_MEMBER_REMOVED => 'حذف عضو',
            self::ACTION_MEMBER_ROLE_CHANGED => 'تغيير دور عضو',
            self::ACTION_API_KEY_CREATED => 'إنشاء مفتاح API',
            self::ACTION_API_KEY_REVOKED => 'إلغاء مفتاح API',
            self::ACTION_ONBOARDING_STEP => 'إكمال خطوة إعداد',
        ];
    }

    public static function labelFor(string $action): string
    {
        $labels = self::actionLabels();
        return $labels[$action] ?? $action;
    }

    /**
     * تسجيل حدث (fire and forget) - بيمرر على BusinessAuditLog::record().
     */
    public static function record(
        int $businessId,
        int $actorUserId,
        string $action,
        string $result = 'success',
        ?string $objectType = null,
        ?string $objectId = null,
        array $meta = []
    ): void {
        BusinessAuditLog::record($businessId, $actorUserId, $action, $result, $objectType, $objectId, $meta);
    }

    /**
     * سجل Business مصفى ومقسّم صفحات، مع ترجمة الأحداث في نفس الخطوة.
     *
     * @return array{rows: array, total: int}
     */
    public static function list(int $businessId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $result = BusinessAuditLog::listFor($businessId, $filters, $page, $perPage);
        foreach ($result['rows'] as &$row) {
            $row['action_label'] = self::labelFor((string) ($row['action'] ?? ''));
        }
        unset($row);
        return $result;
    }
}

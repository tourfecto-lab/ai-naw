<?php

/**
 * Tourfecto - Business Access Service
 * Team Management + RBAC - Business Control Center Phase 11
 * @version 1.0.0
 *
 * نقطة الفحص المركزية الوحيدة لصلاحية أي مستخدم على أي Business. كل
 * المتحكمات (BusinessController, BusinessLocationController, ...) كانت
 * بتفحص `isOwnedBy()` كل واحدة لوحدها - دلوقتي لازم كله يعدي من هنا.
 *
 * الأدوار:
 *   owner  - مش مخزّن في `business_members`، بيتحدد عبر `businesses.owner_user_id`
 *   admin  - يدير بيانات الـBusiness كاملة + إدارة الفريق (دعوة/حذف/تغيير أدوار)
 *   member - يشوف ويعدّل بيانات الـBusiness، لكن ميقدرش يدير الفريق
 *   viewer - يشوف بس (read-only)
 *
 * الطبقة الخالصة: roleAllows()/roleRank()/allowedRoles() منطق خالص مفيش فيه
 * أي اتصال DB - عشان الاختبارات تشتغل offline (نفس نمط BusinessReadinessService
 * و SsrfGuard). الفحوص اللي محتاجة DB (roleOf/getAccessibleBusiness) بتبني
 * على الطبقة الخالصة دي.
 *
 * ملاحظة: الفحص هنا بيعتمد على الـbusinessId اللي بيوصله - المتحكم هو
 * المسؤول إنه يبعت businessId صحيح (مش بياخده من مكان خارجي متلاعب فيه
 * غير الـURL param، والفحص نفسه هو اللي بيقرر الصلاحية).
 */
class BusinessAccessService
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';
    public const ROLE_VIEWER = 'viewer';

    /** الصلاحيات المعروفة (capabilities) */
    public const CAP_VIEW = 'view';
    public const CAP_EDIT = 'edit';
    public const CAP_MANAGE_TEAM = 'manage_team';
    public const CAP_ADMINISTER_TEAM = 'administer_team';
    public const CAP_MANAGE_KEYS = 'manage_keys';
    public const CAP_READ_AUDIT = 'read_audit';

    /**
     * الأدوار المسموح تخزينها في `business_members` (owner مش بيتخزن هنا).
     * @return string[]
     */
    public static function allowedMemberRoles(): array
    {
        return [self::ROLE_ADMIN, self::ROLE_MEMBER, self::ROLE_VIEWER];
    }

    /**
     * ترتيب الدور رقميًا (owner أعلاها) - عشان مقارنات "هل دوري أعلى/يساوي"
     * تبقي واضحة بدل if chains طويلة. Pure - بيتستخدم في الاختبارات.
     */
    public static function roleRank(string $role): int
    {
        switch ($role) {
            case self::ROLE_OWNER:
                return 4;
            case self::ROLE_ADMIN:
                return 3;
            case self::ROLE_MEMBER:
                return 2;
            case self::ROLE_VIEWER:
                return 1;
            default:
                return 0;
        }
    }

    /**
     * هل الدور ده بيسمح بالصلاحية المطلوبة؟ خريطة الصلاحيات الوحيدة -
     * أي تغيير في سياسة الوصول بيتعمل هنا مش في المتحكمات. Pure.
     */
    public static function roleAllows(string $role, string $capability): bool
    {
        switch ($capability) {
            case self::CAP_VIEW:
                return in_array($role, [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_MEMBER, self::ROLE_VIEWER], true);
            case self::CAP_EDIT:
                return in_array($role, [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_MEMBER], true);
            case self::CAP_MANAGE_TEAM:
                return in_array($role, [self::ROLE_OWNER, self::ROLE_ADMIN], true);
            case self::CAP_ADMINISTER_TEAM:
                return $role === self::ROLE_OWNER;
            case self::CAP_MANAGE_KEYS:
                return in_array($role, [self::ROLE_OWNER, self::ROLE_ADMIN], true);
            case self::CAP_READ_AUDIT:
                return in_array($role, [self::ROLE_OWNER, self::ROLE_ADMIN], true);
            default:
                return false;
        }
    }

    /**
     * كاش جوه الطلب الواحد لنفس (businessId, userId) - منع تكرار نفس
     * استعلامات الدور (H1 - Phase 27 performance audit). الدور مبيتغيرش
     * جوه الطلب الواحد (مفيش كتابة بين الفحوص)، فالنتيجة آمنة تتكاش.
     */
    private array $roleCache = [];

    /** كاش للـBusiness المحمّل أثناء فحص الدور - ليعاد استخدامه بدل استعلام ثاني (H2). */
    private array $businessCache = [];

    /**
     * دور المستخدم الفعلي على الـBusiness ده، أو null لو مالهوش أي وصول.
     * owner بيتفحص الأول (مصدر الحقيقة businesses.owner_user_id)، وبعدين
     * عضو نشط في business_members.
     */
    public function roleOf(int $businessId, int $userId): ?string
    {
        $cacheKey = $businessId . ':' . $userId;
        if (array_key_exists($cacheKey, $this->roleCache)) {
            return $this->roleCache[$cacheKey];
        }
        $business = (new Business())->find($businessId);
        if (!$business) {
            return null;
        }
        $this->businessCache[$businessId] = $business;
        if ((int) $business->getAttribute('owner_user_id') === $userId) {
            return $this->roleCache[$cacheKey] = self::ROLE_OWNER;
        }
        $members = (new BusinessMember())->where(
            ['business_id' => $businessId, 'user_id' => $userId, 'status' => 'active'],
            [],
            1
        );
        if (empty($members)) {
            return null;
        }
        $role = $members[0]->getAttribute('role');
        $role = in_array($role, self::allowedMemberRoles(), true) ? $role : null;
        return $this->roleCache[$cacheKey] = $role;
    }

    public function canView(int $businessId, int $userId): bool
    {
        $role = $this->roleOf($businessId, $userId);
        return $role !== null && self::roleAllows($role, self::CAP_VIEW);
    }

    public function canEdit(int $businessId, int $userId): bool
    {
        $role = $this->roleOf($businessId, $userId);
        return $role !== null && self::roleAllows($role, self::CAP_EDIT);
    }

    public function canManageTeam(int $businessId, int $userId): bool
    {
        $role = $this->roleOf($businessId, $userId);
        return $role !== null && self::roleAllows($role, self::CAP_MANAGE_TEAM);
    }

    public function canAdministerTeam(int $businessId, int $userId): bool
    {
        $role = $this->roleOf($businessId, $userId);
        return $role !== null && self::roleAllows($role, self::CAP_ADMINISTER_TEAM);
    }

    /** إدارة مفاتيح API الخاصة بالـBusiness - owner/admin بس (تفاصيل أمنية حساسة) */
    public function canManageKeys(int $businessId, int $userId): bool
    {
        $role = $this->roleOf($businessId, $userId);
        return $role !== null && self::roleAllows($role, self::CAP_MANAGE_KEYS);
    }

    /** قراءة سجل الـBusiness - owner/admin بس (التفاصيل الأمنية مش للعرض العام) */
    public function canReadAudit(int $businessId, int $userId): bool
    {
        $role = $this->roleOf($businessId, $userId);
        return $role !== null && self::roleAllows($role, self::CAP_READ_AUDIT);
    }

    /**
     * بيحمّل الـBusiness لو المستخدم له أي وصول (view فأعلى)، وإلا null.
     * بديل موحّد لـ loadOwnedBusiness() المكررة في كل المتحكمات - بس لازم
     * المتحكم يستدعي بعدها canEdit() كمان لو العملية كتابة (viewer يشوف
     * بس مش بيعدّل). نفس مبدأ الـ404 مش الـ403 للـbusinesses مش مملوكة
     * (منع تسريب وجود موارد لمستخدمين تانيين) - لكن الـviewer المصرّح
     * له بيعرف الـBusiness موجودة (هو عضو فيها)، فبياخد 403 على الكتابة.
     */
    public function getAccessibleBusiness(int $businessId, int $userId): ?Business
    {
        $role = $this->roleOf($businessId, $userId);
        if ($role === null || !self::roleAllows($role, self::CAP_VIEW)) {
            return null;
        }
        // الـBusiness اتحمّل أصلاً جوه roleOf() - استرجعه من الكاش بدل
        // استعلام SELECT تاني (H2 - Phase 27).
        return $this->businessCache[$businessId] ?? null;
    }

    /**
     * أول Business ليوزر: لو مالك أي Business بيرجّع أول واحدة، وإلا أول
     * Business هو عضو نشط فيها (شريك/موظف) - عشان فريق كامل يفتح
     * /api/business و /api/business/overview ويشوفوا نفس الشركة.
     */
    public function resolveUserBusiness(int $userId): ?Business
    {
        $owned = (new Business())->where(['owner_user_id' => $userId], ['id' => 'ASC'], 1);
        if (!empty($owned)) {
            return $owned[0];
        }
        $membership = (new BusinessMember())->where(
            ['user_id' => $userId, 'status' => 'active'],
            ['id' => 'ASC'],
            1
        );
        if (empty($membership)) {
            return null;
        }
        return (new Business())->find((int) $membership[0]->getAttribute('business_id'));
    }
}

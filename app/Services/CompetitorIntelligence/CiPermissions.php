<?php

/**
 * Tourfecto - Competitor Intelligence: Permissions
 * @version 1.0.0
 *
 * المشروع الحالي لا يملك نظام أدوار/صلاحيات عام (Admin/Manager/
 * Analyst/Viewer) على مستوى المنصة - فقط عمود `users.role` بقيم
 * (super_admin, admin, manager, agent, user). عمل نظام RBAC عالمي جديد
 * كان سيكون Refactor شامل يخالف القاعدة رقم 41 (لا تعيد بناء المشروع).
 *
 * بدلاً من ذلك، هذا الكلاس يفعّل نموذج الأدوار الأربعة المطلوب
 * (Admin/Manager/Analyst/Viewer) *فقط داخل موديول Competitor
 * Intelligence*، مبنيًا فوق `users.role` الموجود فعليًا:
 *
 *   super_admin, admin  -> Admin      (كل الصلاحيات)
 *   manager             -> Manager    (كل شيء ماعدا إدارة الإعدادات العامة/الحذف النهائي)
 *   agent               -> Analyst    (عرض + إضافة + تحليل، بدون حذف/إدارة تنبيهات الآخرين)
 *   user                -> Admin على بياناته الخاصة فقط (هو صاحب الحساب/tenant)
 *
 * ملاحظة مهمة: عزل الـ Tenant (قاعدة 30) مستقل تمامًا عن هذا الكلاس
 * ويُطبَّق دائمًا أولاً في الـ Controller عبر فلترة `user_id` - أي
 * مستخدم مهما كان دوره لا يرى بيانات مستخدم آخر بغض النظر عن الصلاحية.
 */
class CiPermissions
{
    public const PERM_VIEW = 'view';
    public const PERM_ADD = 'add';
    public const PERM_EDIT = 'edit';
    public const PERM_DELETE = 'delete';
    public const PERM_MANAGE_MONITORING = 'manage_monitoring';
    public const PERM_MANAGE_ALERTS = 'manage_alerts';
    public const PERM_EXPORT = 'export';
    public const PERM_MANAGE_SETTINGS = 'manage_settings';

    private const ROLE_MAP = [
        'super_admin' => 'admin',
        'admin' => 'admin',
        'manager' => 'manager',
        'agent' => 'analyst',
        'user' => 'admin', // صاحب الحساب - صلاحية كاملة على بياناته الخاصة فقط
    ];

    private const MATRIX = [
        'admin' => [
            self::PERM_VIEW, self::PERM_ADD, self::PERM_EDIT, self::PERM_DELETE,
            self::PERM_MANAGE_MONITORING, self::PERM_MANAGE_ALERTS, self::PERM_EXPORT, self::PERM_MANAGE_SETTINGS,
        ],
        'manager' => [
            self::PERM_VIEW, self::PERM_ADD, self::PERM_EDIT,
            self::PERM_MANAGE_MONITORING, self::PERM_MANAGE_ALERTS, self::PERM_EXPORT,
        ],
        'analyst' => [
            self::PERM_VIEW, self::PERM_ADD, self::PERM_EXPORT,
        ],
        'viewer' => [
            self::PERM_VIEW,
        ],
    ];

    public static function ciRole(array $user): string
    {
        // fail-closed: أي سجل مستخدم من غير role أو بدور غير معروف بينزل
        // لـ viewer (أقل صلاحية). الصلاحية الكاملة على بياناتك (admin)
        // بتيجي بس لصاحب الحساب اللي دوره صراحة `user` - لأن عزل الـ
        // Tenant (قاعدة 30) هو اللي بيضمن الأمان في الحالة دي.
        $role = (string) ($user['role'] ?? '');
        return self::ROLE_MAP[$role] ?? 'viewer';
    }

    public static function can(array $user, string $permission): bool
    {
        $ciRole = self::ciRole($user);
        return in_array($permission, self::MATRIX[$ciRole] ?? [], true);
    }
}

<?php

/**
 * Tourfecto - CRM Permission Service (بند 30، 31)
 * @version 1.0.0
 *
 * مصفوفة الأدوار مبنية حرفيًا على القيم المطلوبة في الطلب الأصلي:
 * Admin/Manager/Sales/Support/Viewer × View/Create/Edit/Delete/Assign/
 * Export/Manage Settings.
 *
 * حدود معروفة وموثّقة (راجع CHANGELOG المرحلة 5 للتفصيل الكامل):
 * - هذه الطبقة تحل الـTenant الصحيح وتفرض الصلاحيات على: Contacts,
 *   Companies, Tasks, Notes, Appointments, Automation, Communication,
 *   Customer 360, Dashboard, Search, Import/Export - كل الموديولات التي
 *   بُنيت بالكامل في المراحل 1-4 الجديدة.
 * - Leads وDeals لا تزالان تعملان بالمنطق الأصلي غير المعدَّل من الملف
 *   الأصلي (CrmController::createDeal/listDeals/updateDealStage وما شابه)
 *   ولم يُطبَّق عليهما حل الـTenant/الصلاحيات الجديد هذا بعد - أي محاولة
 *   لمستخدم عضو فريق (غير صاحب الحساب) للوصول لـLeads/Deals حاليًا هتُعامله
 *   كـTenant منفصل بذاته (نفس السلوك القديم قبل هذه المرحلة).
 */
class CrmPermissionService
{
    public const ROLES = ['admin', 'manager', 'sales', 'support', 'viewer'];

    private const MATRIX = [
        'admin' => ['view', 'create', 'edit', 'delete', 'assign', 'export', 'manage_settings'],
        'manager' => ['view', 'create', 'edit', 'assign', 'export'],
        'sales' => ['view', 'create', 'edit'],
        'support' => ['view', 'create'],
        'viewer' => ['view'],
    ];

    private $teamService;

    public function __construct(?CrmTeamService $teamService = null)
    {
        $this->teamService = $teamService ?? new CrmTeamService();
    }

    /** الحساب (Tenant) الفعلي اللي المستخدم بيشتغل عليه - نفسه لو مالك، أو صاحب الفريق لو عضو */
    public function resolveTenantId(int $userId): int
    {
        $membership = $this->teamService->myMembership($userId);
        return $membership ? (int) $membership->getAttribute('tenant_user_id') : $userId;
    }

    /** الدور الفعلي للمستخدم - 'admin' دايمًا لصاحب الحساب نفسه (صلاحيات كاملة على بياناته) */
    public function roleFor(int $userId): string
    {
        $membership = $this->teamService->myMembership($userId);
        return $membership ? (string) $membership->getAttribute('role') : 'admin';
    }

    public function can(int $userId, string $permission): bool
    {
        $role = $this->roleFor($userId);
        return in_array($permission, self::MATRIX[$role] ?? [], true);
    }

    public function permissionsFor(int $userId): array
    {
        return self::MATRIX[$this->roleFor($userId)] ?? [];
    }
}

<?php

/**
 * Tourfecto - Workspace Permission Matrix
 * مصفوفة الصلاحيات لأدوار الـ Workspace (منفصلة تمامًا عن users.role
 * اللي بيتحكم في صلاحيات منصة Tourfecto الداخلية - AdminMiddleware
 * بيفحص role مش workspace_role، وده مقصود، متلمسوش).
 * @version 1.0.0
 */
class WorkspacePermissions
{
    /** كل صلاحية موجودة فعليًا وبتتفحص في الكود دلوقتي */
    private const MATRIX = [
        'admin' => ['manage_workspace', 'manage_team', 'manage_billing', 'view_billing'],
        'manager' => ['manage_team', 'view_billing'],
        'sales' => [],
        'support' => [],
        'viewer' => [],
    ];

    /**
     * هل صاحب الحساب نفسه أو عضو بدور معيّن عنده الصلاحية دي؟
     * صاحب الحساب (owner_user_id = NULL) عنده كل الصلاحيات دايمًا.
     */
    public static function can(User $user, string $capability): bool
    {
        if ($user->getAttribute('owner_user_id') === null) {
            return true; // صاحب الحساب - صلاحية كاملة على الـ Workspace بتاعه
        }
        $role = (string) ($user->getAttribute('workspace_role') ?? 'viewer');
        return in_array($capability, self::MATRIX[$role] ?? [], true);
    }

    /** كل الأدوار المتاحة - نفس القيم في migration الـ ENUM بالظبط */
    public static function roles(): array
    {
        return ['admin', 'manager', 'sales', 'support', 'viewer'];
    }
}

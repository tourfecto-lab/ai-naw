<?php

/**
 * Tourfecto - صلاحيات فريق العمل في السوشيال
 *
 * بيستخدم نفس فريق المنصة (crm_team_members): العضو بيشتغل على حساب صاحب الشركة (tenant) بدوره:
 *   admin/manager : كل حاجة (نشر، جدولة، اعتماد منشورات الفريق، الحسابات، الإعدادات)
 *   sales/support : كتابة منشورات (بتروح للمدير يعتمدها قبل النشر) + الرد على التعليقات
 *   viewer        : مشاهدة بس
 * صاحب الحساب نفسه (مش عضو في فريق حد) = admin.
 * @version 1.0.0
 */
final class SocialTeam
{
    public const MATRIX = [
        'admin' => ['view', 'draft', 'publish', 'inbox', 'manage'],
        'manager' => ['view', 'draft', 'publish', 'inbox', 'manage'],
        'sales' => ['view', 'draft', 'inbox'],
        'support' => ['view', 'draft', 'inbox'],
        'viewer' => ['view'],
    ];

    /** @return array{account_id:int,role:string,member:bool,can:string[]} */
    public static function context(int $userId): array
    {
        $account = $userId;
        $role = 'admin';
        $member = false;
        try {
            if (class_exists('CrmTeamMember')) {
                $m = (new CrmTeamMember())->membershipFor($userId);
                if ($m) {
                    $account = (int) $m->getAttribute('tenant_user_id') ?: $userId;
                    $role = (string) $m->getAttribute('role');
                    $member = $account !== $userId;
                }
            }
        } catch (Throwable $e) {
            // جدول الفريق مش موجود: الحساب شغال لوحده زي الأول
        }
        if (!isset(self::MATRIX[$role])) {
            $role = 'viewer';
        }
        return ['account_id' => $account, 'role' => $role, 'member' => $member, 'can' => self::MATRIX[$role]];
    }

    public static function can(array $ctx, string $permission): bool
    {
        return in_array($permission, $ctx['can'], true);
    }

    /** الأشخاص اللي بيتبلّغوا لما منشور محتاج اعتماد: صاحب الحساب + المديرين */
    public static function approvers(int $accountId): array
    {
        $ids = [$accountId];
        try {
            foreach (Database::getInstance()->query("SELECT member_user_id FROM crm_team_members WHERE tenant_user_id = ? AND role IN ('admin','manager')", [$accountId]) ?: [] as $r) {
                $ids[] = (int) $r['member_user_id'];
            }
        } catch (Throwable $e) {
        }
        return array_values(array_unique($ids));
    }
}

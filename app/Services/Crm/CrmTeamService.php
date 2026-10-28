<?php

/**
 * Tourfecto - CRM Team Service (بند 30)
 * @version 1.0.0
 *
 * قيد متعمّد وموثّق: إضافة عضو فريق تتطلب أن يكون له حساب Tourfecto فعلي
 * بالفعل (عبر البريد الإلكتروني) - لا يوجد نظام دعوة لبريد غير مسجّل بعد
 * (يحتاج تدفّق تسجيل/دعوة منفصل خارج نطاق هذه المرحلة). لو البريد غير
 * مسجّل، الخدمة تُرجع رسالة واضحة بدل ادّعاء إرسال دعوة لم تُبنَ فعليًا
 * (بند 39/16: لا تدّعي وظيفة غير مبنية).
 *
 * قيد آخر متعمّد: كل مستخدم عضو في فريق CRM واحد بس حاليًا (Unique
 * constraint على member_user_id) - تبسيط مقصود بدل بناء نظام "تبديل بين
 * أكثر من حساب" الأكبر والأعقد، وهو خارج نطاق الطلب الأصلي.
 */
class CrmTeamService
{
    public function addMember(int $tenantUserId, int $actorUserId, string $email, string $role): CrmTeamMember
    {
        if (!in_array($role, CrmPermissionService::ROLES, true)) {
            throw new Exception('دور غير معروف');
        }
        $user = User::findByEmail($email);
        if (!$user) {
            throw new Exception('لا يوجد حساب Tourfecto مسجّل بهذا البريد الإلكتروني - لازم يعمل حساب أولًا قبل إضافته للفريق');
        }
        $memberUserId = (int) $user->getAttribute('id');
        if ($memberUserId === $tenantUserId) {
            throw new Exception('صاحب الحساب عضو أساسي بالفعل بصلاحيات كاملة - مفيش داعي يضيف نفسه');
        }

        $existing = (new CrmTeamMember())->membershipFor($memberUserId);
        if ($existing) {
            throw new Exception('هذا المستخدم عضو بالفعل في فريق CRM آخر (كل مستخدم يقدر يكون عضو فريق واحد بس حاليًا)');
        }

        $member = new CrmTeamMember([
            'tenant_user_id' => $tenantUserId, 'member_user_id' => $memberUserId,
            'role' => $role, 'added_by_user_id' => $actorUserId,
        ]);
        $member->save();

        Notification::notify($memberUserId, 'crm_team', 'تمت إضافتك لفريق CRM', 'صلاحيتك: ' . $role, '/crm');
        ActivityLog::record('crm', 'team.member_added', [
            'user_id' => $tenantUserId, 'subject_type' => 'crm_team_members', 'subject_id' => (int) $member->getAttribute('id'),
            'meta' => ['member_email' => $email, 'role' => $role],
        ]);

        return $member;
    }

    public function updateRole(int $tenantUserId, int $memberRowId, string $role): CrmTeamMember
    {
        if (!in_array($role, CrmPermissionService::ROLES, true)) {
            throw new Exception('دور غير معروف');
        }
        $member = (new CrmTeamMember())->find($memberRowId);
        if (!$member || (int) $member->getAttribute('tenant_user_id') !== $tenantUserId) {
            throw new Exception('عضو الفريق غير موجود', 404);
        }
        $member->setAttribute('role', $role);
        $member->save();
        return $member;
    }

    public function removeMember(int $tenantUserId, int $memberRowId): bool
    {
        $member = (new CrmTeamMember())->find($memberRowId);
        if (!$member || (int) $member->getAttribute('tenant_user_id') !== $tenantUserId) {
            throw new Exception('عضو الفريق غير موجود', 404);
        }
        return $member->delete();
    }

    public function listForTenant(int $tenantUserId): array
    {
        return (new CrmTeamMember())->forTenant($tenantUserId);
    }

    /** يرجع بيانات عضوية المستخدم (لو عضو في فريق حساب تاني) أو null لو مالك حساب نفسه */
    public function myMembership(int $userId): ?CrmTeamMember
    {
        return (new CrmTeamMember())->membershipFor($userId);
    }
}

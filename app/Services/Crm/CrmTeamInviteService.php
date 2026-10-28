<?php

/**
 * Tourfecto - CRM Team Invite Service (المرحلة 13 - G9)
 * @version 1.0.0
 *
 * دعوة أعضاء فريق CRM عبر البريد الإلكتروني - يرفع القيد الموثّق في
 * CrmTeamService ("يجب أن يكون له حساب Tourfecto بالفعل") بأن يسمح
 * بدعوة بريد غير مسجّل عبر رابط قبول. يعيد استخدام جدول/موديل
 * WorkspaceInvite الموجود (same token/expiry/status lifecycle) فلا
 * يُبنى نظام دعوات موازٍ - نفس البنية التي يستخدمها
 * WorkspaceController::inviteMember.
 *
 * الدعوة المقبولة تُنشئ حسابًا (عبر User::create بنفس نمط
 * WorkspaceController::acceptInvite) ثم تُسجِّل العضو في
 * crm_team_members تلقائيًا. لو البريد مسجّل أصلًا، تُوجَّه إلى
 * CrmTeamService::addMember (السلوك الأصلي - بند 30).
 */
class CrmTeamInviteService
{
    private $teamService;

    public function __construct()
    {
        $this->teamService = new CrmTeamService();
    }

    /**
     * دعوة بريد إلكتروني.
     * - بريد مسجّل → addMember الأصلي (لا دعوة).
     * - بريد غير مسجّل → WorkspaceInvite + إرسال بريد برابط القبول.
     */
    public function invite(int $tenantUserId, int $actorUserId, string $email, string $role): array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            throw new Exception('البريد الإلكتروني مطلوب', 422);
        }
        if (!in_array($role, CrmPermissionService::ROLES, true)) {
            throw new Exception('دور غير معروف', 422);
        }

        $existing = User::findByEmail($email);
        if ($existing) {
            $member = $this->teamService->addMember($tenantUserId, $actorUserId, $email, $role);
            return [
                'mode' => 'direct_added',
                'member' => $member->toArray(),
                'invite' => null,
                'invite_url' => null,
                'email_sent' => false,
            ];
        }

        // منع تكرار دعوة معلّقة لنفس البريد
        $pending = (new WorkspaceInvite())->where(
            ['owner_user_id' => $tenantUserId, 'email' => $email, 'status' => 'pending'],
            [],
            1
        );
        if (!empty($pending)) {
            $existingInvite = $pending[0];
            if (!$existingInvite->isExpired()) {
                throw new Exception('يوجد دعوة معلّقة لهذا البريد مسبقًا', 409);
            }
        }

        $result = WorkspaceInvite::createFor($tenantUserId, $actorUserId, $email, $role);
        $inviteUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/') . '/crm/accept-invite?token=' . $result['token'];

        $mailSent = false;
        try {
            $mailer = new Mailer();
            if ($mailer->isConfigured()) {
                $mailer->send(
                    $email,
                    $email,
                    'دعوة للانضمام لفريق CRM على Tourfecto',
                    "تمت دعوتك للانضمام لفريق CRM على Tourfecto.<br><br>"
                    . "<a href=\"{$inviteUrl}\">اضغط هنا لقبول الدعوة</a><br><br>"
                    . 'الدعوة صالحة لمدة 7 أيام.'
                );
                $mailSent = true;
            }
        } catch (\Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('CRM team invite email failed: ' . $e->getMessage());
            }
        }

        ActivityLog::record('crm', 'team.invite_sent', [
            'user_id' => $tenantUserId, 'subject_type' => 'workspace_invites',
            'subject_id' => (int) $result['model']->getAttribute('id'),
            'meta' => ['email' => $email, 'role' => $role],
        ]);

        return [
            'mode' => 'invited',
            'member' => null,
            'invite' => $result['model']->toSafeArray(),
            'invite_url' => $inviteUrl,
            'email_sent' => $mailSent,
        ];
    }

    /** دعوات الحساب المعلّقة */
    public function listInvites(int $tenantUserId): array
    {
        $rows = (new WorkspaceInvite())->where(
            ['owner_user_id' => $tenantUserId, 'status' => 'pending'],
            [],
            0
        );
        return array_map(fn ($i) => $i->toSafeArray(), $rows);
    }

    /** إلغاء دعوة معلّقة */
    public function revokeInvite(int $tenantUserId, int $inviteId): bool
    {
        $invite = (new WorkspaceInvite())->find($inviteId);
        if (!$invite || (int) $invite->getAttribute('owner_user_id') !== $tenantUserId) {
            throw new Exception('الدعوة غير موجودة', 404);
        }
        if ((string) $invite->getAttribute('status') !== 'pending') {
            throw new Exception('الدعوة لم تعد معلّقة', 422);
        }
        return $invite->revoke();
    }

    /** عرض بيانات دعوة برمزها (لصفحة القبول العامة) */
    public function showInvite(string $token): array
    {
        $rows = (new WorkspaceInvite())->where(['token' => $token], [], 1);
        $invite = $rows[0] ?? null;
        if (!$invite || (string) $invite->getAttribute('status') !== 'pending' || $invite->isExpired()) {
            throw new Exception('الدعوة غير صالحة أو منتهية', 404);
        }
        $owner = (new User())->find((int) $invite->getAttribute('owner_user_id'));
        return [
            'email' => $invite->getAttribute('email'),
            'role' => $invite->getAttribute('role'),
            'owner_name' => $owner ? ($owner->getAttribute('company_name') ?: ($owner->getAttribute('first_name') . ' ' . $owner->getAttribute('last_name'))) : 'Tourfecto',
            'expires_at' => $invite->getAttribute('expires_at'),
        ];
    }

    /**
     * قبول دعوة (بلا تسجيل دخول): إنشاء حساب + تسجيل عضو في الفريق.
     * @param string $token
     * @param string $firstName
     * @param string $lastName
     * @param string $password
     */
    public function acceptInvite(string $token, string $firstName, string $lastName, string $password): array
    {
        $rows = (new WorkspaceInvite())->where(['token' => $token], [], 1);
        $invite = $rows[0] ?? null;
        if (!$invite || (string) $invite->getAttribute('status') !== 'pending' || $invite->isExpired()) {
            throw new Exception('الدعوة غير صالحة أو منتهية', 404);
        }
        if (strlen($password) < 8) {
            throw new Exception('كلمة المرور يجب أن تكون 8 أحرف على الأقل', 422);
        }

        $email = (string) $invite->getAttribute('email');
        if (User::findByEmail($email)) {
            throw new Exception('البريد الإلكتروني ده بقى مستخدم بالفعل - سجّل الدخول أولًا', 409);
        }

        $newUser = User::create([
            'email' => $email,
            'password' => $password,
            'first_name' => trim($firstName),
            'last_name' => trim($lastName),
            'role' => 'user',
            'status' => 'active',
        ]);
        if (!$newUser) {
            throw new Exception('تعذر إنشاء الحساب', 500);
        }

        $invite->markAccepted();

        $member = new CrmTeamMember([
            'tenant_user_id' => (int) $invite->getAttribute('owner_user_id'),
            'member_user_id' => (int) $newUser->getAttribute('id'),
            'role' => (string) $invite->getAttribute('role'),
            'added_by_user_id' => (int) $invite->getAttribute('invited_by'),
        ]);
        $member->save();

        ActivityLog::record('crm', 'team.invite_accepted', [
            'user_id' => (int) $invite->getAttribute('owner_user_id'),
            'subject_type' => 'workspace_invites', 'subject_id' => (int) $invite->getAttribute('id'),
            'meta' => ['member_user_id' => (int) $newUser->getAttribute('id')],
        ]);

        return ['user' => $newUser->toArray(), 'member' => $member->toArray()];
    }
}

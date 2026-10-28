<?php

/**
 * Tourfecto - Business Team Service
 * Team Management + RBAC - Business Control Center Phase 10
 * @version 1.0.0
 *
 * منطق العمل الكامل لفريق الـBusiness (دعوة/قبول/حذف/تغيير دور/قائمة)،
 * منفصل عن الـController زي باقي الـServices في الموديول. القواعد الدقيقة
 * (زي "الـadmin ميقدرش يعدّل أو يحذف admin تاني") مركزية هنا - الـController
 * بيعمل فحص الدخول الخام (canManageTeam) بس، والتفاصيل دي بتحسم في مكان واحد.
 *
 * قواعد الأدوار المطبقة هنا:
 *   - دعوة/حذف/تغيير دور: يتطلب دور الـactor (owner أو admin) - يتحقق
 *     الـController من canManageTeam قبل النداء.
 *   - الـadmin ميقدرش يحذف أو يعدّل على admin/owner - owner بس اللي بيعمل كده.
 *   - مفيش حد بيقدر يغيّر دور الـowner أو يحذفه من الفريق (owner مش مخزّن
 *     أصلاً في business_members - مستحيل فنيًا، والفحص هنا طبقة دفاع تانية).
 *   - owner_admin_ops: إضافة/إزالة دور admin بتتطلب الـowner.
 */
class BusinessTeamService
{
    public const INVITE_TTL_DAYS = 7;
    /** F5 (Phase 26): الحد الأقصى للدعوات المعلقة لكل Business */
    public const MAX_PENDING_INVITES = 25;

    /**
     * دعوة مستخدم للانضمام لفريق الـBusiness.
     * - لو البريد ده لمستخدم مسجل فعلًا: العضوية بتتفعّل فورًا (added).
     * - لو غير مسجل: بنعمل دعوة معلقة بتوكن قبول (invited) - لما يسجّل
     *   ويقبل بالتوكن، العضوية بتتفعّل.
     *
     * @return array{ok:bool,error?:string,type?:string,member?:array,invite_link?:string}
     */
    public function invite(int $businessId, int $actorUserId, string $email, string $role, ?string $actorRole = null): array
    {
        $email = strtolower(trim($email));
        if (!in_array($role, BusinessAccessService::allowedMemberRoles(), true)) {
            return ['ok' => false, 'error' => 'دور غير صالح'];
        }

        // F2 (Phase 26 security audit) - طبقة دفاع ثانية جوه الـService:
        // تولية دور admin حق المالك بس، حتى لو الـController اتخطى الفحص.
        // (نفس القاعدة اللي changeRole() بيفرضها على التعديلات.)
        if ($role === BusinessAccessService::ROLE_ADMIN) {
            $actorRole = $actorRole ?? (new BusinessAccessService())->roleOf($businessId, $actorUserId);
            if ($actorRole !== BusinessAccessService::ROLE_OWNER) {
                return ['ok' => false, 'error' => 'تولية دور admin يتطلب المالك'];
            }
        }

        $existing = (new Business())->find($businessId);
        if (!$existing) {
            return ['ok' => false, 'error' => 'Business غير موجود'];
        }

        $invitedUser = User::findByEmail($email);

        // منع إضافة المالك نفسه كعضو - ليه أقصى صلاحية فعلًا، وإضافته
        // كصف في business_members هتبقى نسخة مكررة من "مالك" (تخالف
        // مبدأ مصدر الحقيقة الوحيد). سواء اللي بيدعو هو المالك أو admin.
        if ($invitedUser && (int) $existing->getAttribute('owner_user_id') === (int) $invitedUser->getAttribute('id')) {
            return ['ok' => false, 'error' => 'المالك عضو بالفعل في الفريق'];
        }

        if ($invitedUser) {
            $invitedUserId = (int) $invitedUser->getAttribute('id');

            // منع التكرار: نفس المستخدم عضو بالفعل (نشط أو دعوة معلقة)
            $dup = (new BusinessMember())->where(['business_id' => $businessId, 'user_id' => $invitedUserId], [], 1);
            if (!empty($dup)) {
                return ['ok' => false, 'error' => 'هذا المستخدم عضو بالفعل في الفريق'];
            }

            $member = new BusinessMember();
            $member->setAttribute('business_id', $businessId);
            $member->setAttribute('user_id', $invitedUserId);
            $member->setAttribute('role', $role);
            $member->setAttribute('status', 'active');
            $member->setAttribute('invited_by_user_id', $actorUserId);

            if ($member->save() === false) {
                return ['ok' => false, 'error' => 'تعذر إضافة العضو'];
            }

            $this->notify(
                BusinessNotificationService::memberAdded(
                    $invitedUserId,
                    $this->businessName($existing),
                    $this->userName($actorUserId),
                    $role
                )
            );
            return ['ok' => true, 'type' => 'added', 'member' => $this->memberToArray($member)];
        }

        // F5 (Phase 26 security audit): حد أقصى للدعوات المعلقة لكل Business -
        // يمنع إغراق جدول business_members وصندوق إشعارات المالك بدعوات
        // لا نهائية (الـRateLimiter العام مش بيغطي الحالة دي).
        $pendingCount = count((new BusinessMember())->where(
            ['business_id' => $businessId, 'status' => 'invited'],
            [],
            0
        ));
        if ($pendingCount >= self::MAX_PENDING_INVITES) {
            return ['ok' => false, 'error' => 'وصلت للحد الأقصى من الدعوات المعلقة (' . self::MAX_PENDING_INVITES . ') - انتظر قبولها أو احذفها أولًا'];
        }

        // مستخدم غير مسجل -> دعوة معلقة
        $pendingDup = (new BusinessMember())->where(['business_id' => $businessId, 'invited_email' => $email, 'status' => 'invited'], [], 1);
        if (!empty($pendingDup)) {
            return ['ok' => false, 'error' => 'يوجد دعوة معلقة لهذا البريد بالفعل'];
        }

        $token = $this->generateInviteToken();
        $member = new BusinessMember();
        $member->setAttribute('business_id', $businessId);
        $member->setAttribute('role', $role);
        $member->setAttribute('status', 'invited');
        $member->setAttribute('invited_by_user_id', $actorUserId);
        $member->setAttribute('invited_email', $email);
        $member->setAttribute('invite_token', $token);
        $member->setAttribute('invite_expires_at', date('Y-m-d H:i:s', time() + self::INVITE_TTL_DAYS * 86400));

        if ($member->save() === false) {
            return ['ok' => false, 'error' => 'تعذر إنشاء الدعوة'];
        }

        $this->notify(
            BusinessNotificationService::inviteSent(
                (int) $existing->getAttribute('owner_user_id'),
                $this->businessName($existing),
                $email,
                $role
            )
        );

        return [
            'ok' => true,
            'type' => 'invited',
            'member' => $this->memberToArray($member),
            'invite_link' => '/api/business/' . $businessId . '/team/invite/' . $token . '/accept',
        ];
    }

    /**
     * قبول دعوة معلقة. لازم المستخدم الحالي يكون مسجل دخوله، وليكن
     * بريده مطابقًا لبريد الدعوة (بعد تطبيع lowercase)، والـtoken صحيح
     * وغير منتهي الصلاحية.
     */
    public function acceptInvite(int $businessId, string $token, int $userId, string $userEmail): array
    {
        $business = (new Business())->find($businessId);
        if (!$business) {
            return ['ok' => false, 'error' => 'Business غير موجود'];
        }

        $pending = (new BusinessMember())->where(
            ['business_id' => $businessId, 'invite_token' => $token, 'status' => 'invited'],
            [],
            1
        );
        if (empty($pending)) {
            return ['ok' => false, 'error' => 'دعوة غير صالحة أو منتهية'];
        }

        $member = $pending[0];
        $expiresAt = $member->getAttribute('invite_expires_at');
        if ($expiresAt !== null && strtotime((string) $expiresAt) < time()) {
            return ['ok' => false, 'error' => 'انتهت صلاحية الدعوة'];
        }

        $invitedEmail = strtolower(trim((string) $member->getAttribute('invited_email')));
        if ($invitedEmail !== '' && strtolower(trim($userEmail)) !== $invitedEmail) {
            // منع اعتراض الدعوة: بس اللي بريده هو بريد الدعوة يقدر يقبلها.
            return ['ok' => false, 'error' => 'الدعوة ليست موجهة لهذا الحساب'];
        }

        $member->setAttribute('user_id', $userId);
        $member->setAttribute('status', 'active');
        $member->setAttribute('invite_token', null);
        $member->setAttribute('invite_expires_at', null);

        if ($member->save() === false) {
            return ['ok' => false, 'error' => 'تعذر تفعيل العضوية'];
        }

        $this->notify(
            BusinessNotificationService::inviteAccepted(
                (int) $business->getAttribute('owner_user_id'),
                $this->businessName($business),
                $this->userName($userId)
            )
        );

        return ['ok' => true, 'member' => $this->memberToArray($member)];
    }

    /**
     * حذف عضو من الفريق.
     * القاعدة الدقيقة: الـactor بدوره بيوصل في actorRole، والـadmin
     * ميقدرش يحذف admin تاني (owner بس). Owner أصلاً مش مخزّن هنا.
     */
    public function remove(int $businessId, string $actorRole, int $memberId): array
    {
        $member = (new BusinessMember())->find($memberId);
        if (!$member || (int) $member->getAttribute('business_id') !== $businessId) {
            return ['ok' => false, 'error' => 'العضو غير موجود'];
        }

        $targetRole = (string) $member->getAttribute('role');
        if ($actorRole === BusinessAccessService::ROLE_ADMIN && $targetRole === BusinessAccessService::ROLE_ADMIN) {
            return ['ok' => false, 'error' => 'الـadmin لا يمكنه حذف admin آخر'];
        }

        if ($member->delete() === false) {
            return ['ok' => false, 'error' => 'تعذر حذف العضو'];
        }

        $targetUserId = $member->getAttribute('user_id');
        if ($targetUserId !== null) {
            $this->notify(
                BusinessNotificationService::memberRemoved(
                    (int) $targetUserId,
                    $this->businessName((new Business())->find($businessId))
                )
            );
        }
        return ['ok' => true];
    }

    /**
     * تغيير دور عضو.
     * - الـadmin ميقدرش يغيّر دور admin/owner تاني.
     * - تولية/إزالة دور admin (التعامل مع أدوار admin) بتتطلب الـowner.
     */
    public function changeRole(int $businessId, string $actorRole, int $memberId, string $newRole): array
    {
        if (!in_array($newRole, BusinessAccessService::allowedMemberRoles(), true)) {
            return ['ok' => false, 'error' => 'دور غير صالح'];
        }

        $member = (new BusinessMember())->find($memberId);
        if (!$member || (int) $member->getAttribute('business_id') !== $businessId) {
            return ['ok' => false, 'error' => 'العضو غير موجود'];
        }

        $targetRole = (string) $member->getAttribute('role');
        $actorIsOwner = $actorRole === BusinessAccessService::ROLE_OWNER;
        $touchingAdmin = $targetRole === BusinessAccessService::ROLE_ADMIN || $newRole === BusinessAccessService::ROLE_ADMIN;

        if ($touchingAdmin && !$actorIsOwner) {
            return ['ok' => false, 'error' => 'تغيير أدوار الـadmin يتطلب المالك'];
        }
        if ($targetRole === BusinessAccessService::ROLE_ADMIN && !$actorIsOwner) {
            return ['ok' => false, 'error' => 'الـadmin لا يمكنه تغيير دور admin آخر'];
        }

        $member->setAttribute('role', $newRole);
        if ($member->save() === false) {
            return ['ok' => false, 'error' => 'تعذر تحديث الدور'];
        }

        $targetUserId = $member->getAttribute('user_id');
        if ($targetUserId !== null) {
            $this->notify(
                BusinessNotificationService::roleChanged(
                    (int) $targetUserId,
                    $this->businessName((new Business())->find($businessId)),
                    $newRole
                )
            );
        }
        return ['ok' => true, 'member' => $this->memberToArray($member)];
    }

    /**
     * قائمة الفريق الكاملة: المالك (من businesses.owner_user_id + users)
     * + كل صفوف business_members (النشطة والمعلقة) مع بيانات المستخدم.
     */
    public function list(int $businessId): array
    {
        $business = (new Business())->find($businessId);
        if (!$business) {
            return [];
        }

        $team = [];
        $ownerId = (int) $business->getAttribute('owner_user_id');
        $ownerUser = (new User())->find($ownerId);
        if ($ownerUser) {
            $team[] = $this->userEntry($ownerUser, $businessId, BusinessAccessService::ROLE_OWNER, 'active');
        }

        $members = (new BusinessMember())->where(['business_id' => $businessId], ['role' => 'ASC', 'id' => 'ASC']);

        // H3 (Phase 27 performance audit): بدل استعلام User لكل عضو على حدة
        // (N+1 - فريق بـ25 عضو كان بيكلف 25 استعلام)، بنجيب كل المستخدمين
        // المطلوبين باستعلام واحد IN (...) ونربطهم بالـID.
        $memberUserIds = array_values(array_unique(array_filter(
            array_map(fn ($m) => $m->getAttribute('user_id'), $members),
            fn ($id) => $id !== null
        )));
        $usersById = $this->usersById($memberUserIds);

        foreach ($members as $member) {
            $team[] = $this->memberToArray($member, $usersById);
        }

        return $team;
    }

    /**
     * جلب عدد من المستخدمين باستعلام واحد (IN) بدل استعلام لكل ID -
     * H3 (Phase 27). بيرجع map بالـID => User، فاضي لو مفيش IDs.
     *
     * @param int[] $ids
     * @return array<int, User>
     */
    private function usersById(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::getInstance()->query(
            "SELECT * FROM `users` WHERE `id` IN ({$placeholders})",
            $ids
        );
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) ($row['id'] ?? 0)] = new User($row);
        }
        return $byId;
    }

    // ============================================
    // Helpers
    // ============================================

    private function generateInviteToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function memberToArray(BusinessMember $member, array $usersById = []): array
    {
        $userId = $member->getAttribute('user_id');
        if ($userId !== null) {
            $user = $usersById[(int) $userId] ?? null;
            if (!$user) {
                $user = (new User())->find((int) $userId);
            }
            if ($user) {
                return $this->userEntry($user, (int) $member->getAttribute('business_id'), (string) $member->getAttribute('role'), (string) $member->getAttribute('status'));
            }
        }
        $email = (string) $member->getAttribute('invited_email');
        return [
            'member_id' => (int) $member->getAttribute('id'),
            'business_id' => (int) $member->getAttribute('business_id'),
            'user_id' => $userId !== null ? (int) $userId : null,
            'email' => $email,
            'name' => $email,
            'role' => (string) $member->getAttribute('role'),
            'status' => (string) $member->getAttribute('status'),
            'invited_at' => (string) $member->getAttribute('created_at'),
        ];
    }

    private function userEntry(User $user, int $businessId, string $role, string $status): array
    {
        $id = (int) $user->getAttribute('id');
        $email = (string) $user->getAttribute('email');
        return [
            'member_id' => $id,
            'business_id' => $businessId,
            'user_id' => $id,
            'email' => $email,
            'name' => $this->userDisplayName($user),
            'role' => $role,
            'status' => $status,
        ];
    }

    private function userDisplayName(User $user): string
    {
        $display = (string) $user->getAttribute('display_name');
        if ($display !== '') {
            return $display;
        }
        $first = (string) $user->getAttribute('first_name');
        $last = (string) $user->getAttribute('last_name');
        $full = trim($first . ' ' . $last);
        return $full !== '' ? $full : (string) $user->getAttribute('email');
    }

    private function notify(array $payload): void
    {
        if (class_exists('BusinessNotificationService')) {
            BusinessNotificationService::push($payload);
        }
    }

    private function businessName(?Business $business): string
    {
        if (!$business) {
            return 'النشاط التجاري';
        }
        $trade = trim((string) $business->getAttribute('trade_name'));
        $legal = trim((string) $business->getAttribute('legal_name'));
        return $trade !== '' ? $trade : ($legal !== '' ? $legal : 'النشاط التجاري');
    }

    private function userName(int $userId): string
    {
        $user = (new User())->find($userId);
        if (!$user) {
            return 'مستخدم';
        }
        return $this->userDisplayName($user);
    }
}

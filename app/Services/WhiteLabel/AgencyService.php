<?php

/**
 * Tourfecto - Agency Service (White-Label)
 * الوكالة = مساحة عمل مملوكة لمستخدم users حقيقي، وعملاؤها = مستخدمون
 * حقيقيون تانيين مربوطين بجدول agency_clients. لا يوجد نظام دخول أو
 * جدول مستخدمين منفصل (خلافًا للموديول الأصلي ai-white-label-hub).
 * @version 1.0.0
 */
class AgencyService
{
    public function createAgency(int $ownerUserId, string $name): Agency
    {
        $slug = $this->uniqueSlug($name);

        $agency = new Agency([
            'owner_user_id' => $ownerUserId,
            'name' => $name,
            'slug' => $slug,
            'status' => 'active',
            'plan_seats' => 5,
        ]);
        $agency->save();

        // إنشاء إعدادات هوية بصرية افتراضية فورًا (بدل شاشة فاضية لحد ما يعدّل)
        $branding = new AgencyBranding([
            'agency_id' => (int) $agency->getAttribute('id'),
            'primary_color' => '#4F46E5',
            'secondary_color' => '#0EA5E9',
        ]);
        $branding->save();

        ActivityLog::record('white_label', 'agency.created', [
            'user_id' => $ownerUserId, 'agency_id' => (int) $agency->getAttribute('id'),
            'subject_type' => 'agencies', 'subject_id' => (int) $agency->getAttribute('id'),
        ]);

        return $agency;
    }

    public function addClient(int $agencyId, int $clientUserId, float $commissionRate = 10.00): AgencyClient
    {
        $agency = (new Agency())->find($agencyId);
        if (!$agency) {
            throw new Exception('الوكالة غير موجودة');
        }

        $existing = (new AgencyClient())->where(['agency_id' => $agencyId, 'client_user_id' => $clientUserId]);
        if (!empty($existing)) {
            throw new Exception('هذا العميل مضاف بالفعل لهذه الوكالة');
        }

        $currentCount = count((new AgencyClient())->where(['agency_id' => $agencyId]));
        if ($currentCount >= (int) $agency->getAttribute('plan_seats')) {
            throw new Exception('تم الوصول للحد الأقصى لعدد العملاء المسموح به في باقة الوكالة الحالية');
        }

        $client = new AgencyClient([
            'agency_id' => $agencyId,
            'client_user_id' => $clientUserId,
            'status' => 'active',
            'commission_rate' => $commissionRate,
        ]);
        $client->save();

        ActivityLog::record('white_label', 'agency.client_added', [
            'agency_id' => $agencyId, 'subject_type' => 'agency_clients', 'subject_id' => (int) $client->getAttribute('id'),
        ]);

        return $client;
    }

    private function uniqueSlug(string $name): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        $base = $base ?: 'agency';
        $slug = $base;
        $i = 1;
        while (!empty((new Agency())->where(['slug' => $slug]))) {
            $slug = $base . '-' . (++$i);
        }
        return $slug;
    }

    // ------------------------------------------------------------
    // دعوات العملاء (الرمز/الرابط)
    // ------------------------------------------------------------

    /**
     * إنشاء دعوة لعميل حقيقي (بريد مسجّل في تورفكتو) للانضمام للوكالة.
     * إعادة نفس البريد+الوكالة بدعوة pending موجودة بترجّع نفس الدعوة
     * (idempotent) بدل ما تنشئ مكررة. رمز الدعوة فريد وعشوائي.
     *
     * @throws Exception
     */
    public function createInvitation(int $agencyId, string $email, float $commissionRate = 10.00, int $invitedBy = 0, int $ttlHours = 72): AgencyInvitation
    {
        $agency = (new Agency())->find($agencyId);
        if (!$agency) {
            throw new Exception('الوكالة غير موجودة');
        }
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('بريد إلكتروني غير صحيح');
        }
        if ($commissionRate < 0 || $commissionRate > 100) {
            throw new Exception('نسبة العمولة لازم تكون بين 0 و 100');
        }

        // العميل المضاف فعلًا يتحقق منه الـ controller قبل الاستدعاء
        // (عبر User::findByEmail) - الخدمة تركز على منطق الدعوة.

        // idempotent: دعوة pending لنفس البريد+الوكالة ترجع كما هي
        $existing = (new AgencyInvitation())->where(['agency_id' => $agencyId, 'email' => $email, 'status' => 'pending']);
        if (!empty($existing)) {
            return $existing[0];
        }

        $invitation = new AgencyInvitation([
            'agency_id' => $agencyId,
            'email' => $email,
            'token' => $this->uniqueToken(),
            'commission_rate' => $commissionRate,
            'invited_by' => $invitedBy > 0 ? $invitedBy : null,
            'status' => 'pending',
            'expires_at' => date('Y-m-d H:i:s', time() + ((int) $ttlHours * 3600)),
        ]);
        $invitation->save();

        ActivityLog::record('white_label', 'agency.invitation_created', [
            'user_id' => $invitedBy, 'agency_id' => $agencyId,
            'subject_type' => 'agency_invitations', 'subject_id' => (int) $invitation->getAttribute('id'),
        ]);

        return $invitation;
    }

    /** البحث عن دعوة بالرمز */
    public function getInvitationByToken(string $token): ?AgencyInvitation
    {
        $rows = (new AgencyInvitation())->where(['token' => trim($token)]);
        return !empty($rows) ? $rows[0] : null;
    }

    /**
     * قبول دعوة من عميل حقيقي مسجّل دخوله بنفس البريد المدعو.
     * يتحقق من: صلاحية الرمز، الحالة pending، عدم الانتهاء، تطابق
     * البريد مع المستخدم الحالي، وحد مقاعد الوكالة. عند القبول يُضاف
     * العميل في agency_clients وتتحول الدعوة لحالة accepted.
     * Idempotent: عميل مضاف بالفعل → تعليم الدعوة accepted والرجوع.
     *
     * @throws Exception
     */
    public function acceptInvitation(int $userId, string $token): AgencyInvitation
    {
        $invitation = $this->getInvitationByToken($token);
        if (!$invitation) {
            throw new Exception('الدعوة غير صالحة');
        }
        if ($invitation->getAttribute('status') !== 'pending') {
            throw new Exception('هذه الدعوة لم تعد صالحة');
        }
        $expiresAt = $invitation->getAttribute('expires_at');
        if ($expiresAt && strtotime((string) $expiresAt) < time()) {
            throw new Exception('انتهت صلاحية هذه الدعوة');
        }

        $user = (new \User())->find($userId);
        $inviteEmail = strtolower((string) $invitation->getAttribute('email'));
        $userEmail = strtolower((string) ($user ? $user->getAttribute('email') : ''));
        if ($inviteEmail !== $userEmail || !$user) {
            throw new Exception('هذه الدعوة موجهة إلى بريد إلكتروني آخر');
        }

        $agencyId = (int) $invitation->getAttribute('agency_id');

        // Idempotent: العميل مضاف بالفعل → القبول مجرد تعليم للدعوة
        if (empty((new AgencyClient())->where(['agency_id' => $agencyId, 'client_user_id' => $userId]))) {
            $this->addClient($agencyId, $userId, (float) $invitation->getAttribute('commission_rate'));
        }

        $invitation->setAttribute('status', 'accepted');
        $invitation->setAttribute('accepted_at', date('Y-m-d H:i:s'));
        $invitation->save();

        ActivityLog::record('white_label', 'agency.invitation_accepted', [
            'user_id' => $userId, 'agency_id' => $agencyId,
            'subject_type' => 'agency_invitations', 'subject_id' => (int) $invitation->getAttribute('id'),
        ]);

        $agency = (new Agency())->find($agencyId);
        if ($agency) {
            \Notification::notify(
                (int) $agency->getAttribute('owner_user_id'),
                'agency_invitation_accepted',
                'قبول دعوة انضمام',
                'انضم العميل ' . $userEmail . ' لوكالتك',
                '/agency'
            );
        }

        return $invitation;
    }

    /** إلغاء دعوة معلقة */
    public function revokeInvitation(int $invitationId): void
    {
        $invitation = (new AgencyInvitation())->find($invitationId);
        if (!$invitation) {
            throw new Exception('الدعوة غير موجودة');
        }
        $invitation->setAttribute('status', 'revoked');
        $invitation->save();
    }

    /** قائمة دعوات الوكالة */
    public function listInvitations(int $agencyId): array
    {
        return (new AgencyInvitation())->where(['agency_id' => $agencyId], ['created_at' => 'DESC']);
    }

    // ------------------------------------------------------------
    // لوحة تحكم الوكيل (agencyStats / clientPerformance)
    // ------------------------------------------------------------

    /** إحصائيات لوحة تحكم الوكالة (كلها داخل عزل agency_id صارم) */
    public function agencyStats(int $agencyId): array
    {
        $agency = (new Agency())->find($agencyId);
        if (!$agency) {
            return [];
        }
        $db = Database::getInstance();

        $clients = (new AgencyClient())->where(['agency_id' => $agencyId]);
        $clientUserIds = array_map(fn ($c) => (int) $c->getAttribute('client_user_id'), $clients);
        $activeClients = array_values(array_filter($clients, fn ($c) => $c->getAttribute('status') === 'active'));

        $confirmedCount = 0;
        $totalRevenue = 0.0;
        if (!empty($clientUserIds)) {
            $placeholders = implode(',', array_fill(0, count($clientUserIds), '?'));
            $agg = $db->query(
                "SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS revenue
                 FROM bookings
                 WHERE status = 'confirmed' AND user_id IN ({$placeholders})",
                $clientUserIds
            );
            if (!empty($agg)) {
                $confirmedCount = (int) $agg[0]['cnt'];
                $totalRevenue = (float) $agg[0]['revenue'];
            }
        }

        $commissionRows = $db->query(
            'SELECT status, COALESCE(SUM(commission_amount), 0) AS total, COUNT(*) AS cnt
             FROM agency_commissions WHERE agency_id = ? GROUP BY status',
            [$agencyId]
        );
        $commissionTotals = ['pending' => 0.0, 'paid' => 0.0];
        $commissionCounts = ['pending' => 0, 'paid' => 0];
        foreach ($commissionRows as $row) {
            if (isset($commissionTotals[$row['status']])) {
                $commissionTotals[$row['status']] = (float) $row['total'];
                $commissionCounts[$row['status']] = (int) $row['cnt'];
            }
        }

        $recent = $db->query(
            'SELECT c.id, c.commission_amount, c.status, c.created_at, u.company_name AS client_name
             FROM agency_commissions c
             JOIN agency_clients ac ON ac.id = c.agency_client_id
             JOIN users u ON u.id = ac.client_user_id
             WHERE c.agency_id = ?
             ORDER BY c.created_at DESC
             LIMIT 5',
            [$agencyId]
        );

        return [
            'agency' => [
                'id' => $agencyId,
                'name' => $agency->getAttribute('name'),
                'slug' => $agency->getAttribute('slug'),
                'status' => $agency->getAttribute('status'),
                'plan_seats' => (int) $agency->getAttribute('plan_seats'),
                'created_at' => $agency->getAttribute('created_at'),
            ],
            'clients' => [
                'total' => count($clients),
                'active' => count($activeClients),
            ],
            'pending_invites' => count((new AgencyInvitation())->where(['agency_id' => $agencyId, 'status' => 'pending'])),
            'bookings' => [
                'confirmed_count' => $confirmedCount,
                'total_revenue' => round($totalRevenue, 2),
            ],
            'commissions' => [
                'pending_total' => round($commissionTotals['pending'], 2),
                'paid_total' => round($commissionTotals['paid'], 2),
                'pending_count' => $commissionCounts['pending'],
                'paid_count' => $commissionCounts['paid'],
            ],
            'recent_commissions' => $recent,
        ];
    }

    /** أداء كل عميل من عملاء الوكالة (عزل agency_id صارم) */
    public function clientPerformance(int $agencyId): array
    {
        $db = Database::getInstance();
        $clients = (new AgencyClient())->where(['agency_id' => $agencyId]);
        if (empty($clients)) {
            return [];
        }

        $clientUserIds = array_map(fn ($c) => (int) $c->getAttribute('client_user_id'), $clients);
        $placeholders = implode(',', array_fill(0, count($clientUserIds), '?'));

        $bookings = $db->query(
            "SELECT user_id, COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS revenue
             FROM bookings
             WHERE status = 'confirmed' AND user_id IN ({$placeholders})
             GROUP BY user_id",
            $clientUserIds
        );
        $bookingByUser = [];
        foreach ($bookings as $row) {
            $bookingByUser[(int) $row['user_id']] = $row;
        }

        $commissions = $db->query(
            'SELECT ac.client_user_id, c.status, COALESCE(SUM(c.commission_amount), 0) AS total
             FROM agency_commissions c
             JOIN agency_clients ac ON ac.id = c.agency_client_id
             WHERE c.agency_id = ? AND ac.client_user_id IN (' . $placeholders . ')
             GROUP BY ac.client_user_id, c.status',
            array_merge([$agencyId], $clientUserIds)
        );
        $commByUser = [];
        foreach ($commissions as $row) {
            $uid = (int) $row['client_user_id'];
            if (!isset($commByUser[$uid])) {
                $commByUser[$uid] = ['pending' => 0.0, 'paid' => 0.0];
            }
            $commByUser[$uid][$row['status']] = (float) $row['total'];
        }

        $userIds = $clientUserIds;
        $userPlaceholders = implode(',', array_fill(0, count($userIds), '?'));
        $users = $db->query("SELECT id, email, company_name FROM users WHERE id IN ({$userPlaceholders})", $userIds);
        $userById = [];
        foreach ($users as $u) {
            $userById[(int) $u['id']] = $u;
        }

        $out = [];
        foreach ($clients as $client) {
            $uid = (int) $client->getAttribute('client_user_id');
            $user = $userById[$uid] ?? null;
            if (!$user) {
                continue;
            }
            $b = $bookingByUser[$uid] ?? ['cnt' => 0, 'revenue' => 0];
            $c = $commByUser[$uid] ?? ['pending' => 0.0, 'paid' => 0.0];
            $out[] = [
                'agency_client_id' => (int) $client->getAttribute('id'),
                'client_id' => $uid,
                'email' => $user['email'],
                'company_name' => $user['company_name'],
                'status' => $client->getAttribute('status'),
                'commission_rate' => (float) $client->getAttribute('commission_rate'),
                'bookings_count' => (int) $b['cnt'],
                'revenue' => round((float) $b['revenue'], 2),
                'commission_pending_total' => round($c['pending'], 2),
                'commission_paid_total' => round($c['paid'], 2),
            ];
        }

        usort($out, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
        return $out;
    }

    /** رمز دعوة فريد */
    private function uniqueToken(): string
    {
        $token = bin2hex(random_bytes(32));
        while (!empty((new AgencyInvitation())->where(['token' => $token]))) {
            $token = bin2hex(random_bytes(32));
        }
        return $token;
    }
}

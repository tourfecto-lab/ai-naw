<?php

/**
 * Tourfecto - Enterprise Ads Team Permissions Service
 * @version 2.0.0
 *
 * إدارة صلاحيات الفريق وأدوار الأعضاء (owner, admin, manager, viewer)
 * مع ضمان العزل التام وتدرج الصلاحيات الهرمي.
 */
class AdPermissionService
{
    private const ROLE_RANK = ['viewer' => 1, 'manager' => 2, 'admin' => 3, 'owner' => 4];

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function resolveAccess(int $currentUserId, int $resourceOwnerUserId): array
    {
        if ($currentUserId === $resourceOwnerUserId) {
            return ['allowed' => true, 'role' => 'owner'];
        }

        $rows = $this->db->query(
            "SELECT role FROM ad_team_members WHERE owner_user_id = ? AND member_user_id = ? AND status = 'active' LIMIT 1",
            [$resourceOwnerUserId, $currentUserId]
        );

        if (empty($rows)) {
            return ['allowed' => false, 'role' => null];
        }

        return ['allowed' => true, 'role' => $rows[0]['role']];
    }

    public function hasMinRole(?string $role, string $minRole): bool
    {
        if ($role === null) {
            return false;
        }
        return (self::ROLE_RANK[$role] ?? 0) >= (self::ROLE_RANK[$minRole] ?? 99);
    }

    public function listMembers(int $ownerUserId): array
    {
        return $this->db->query(
            "SELECT tm.id, tm.role, tm.status, tm.created_at, u.company_name, u.email
             FROM ad_team_members tm JOIN users u ON u.id = tm.member_user_id
             WHERE tm.owner_user_id = ? AND tm.status = 'active' ORDER BY tm.created_at DESC",
            [$ownerUserId]
        );
    }

    public function addMemberByEmail(int $ownerUserId, string $email, string $role, int $invitedByUserId): array
    {
        if (!in_array($role, ['viewer', 'manager', 'admin'], true)) {
            return ['success' => false, 'error' => 'دور غير صالح'];
        }

        $userRows = $this->db->query("SELECT id FROM users WHERE email = ? LIMIT 1", [$email]);
        if (empty($userRows)) {
            return ['success' => false, 'error' => 'مفيش حساب Tourfecto مسجّل بهذا الإيميل - العضو لازم يكون له حساب بالفعل'];
        }
        $memberUserId = (int) $userRows[0]['id'];

        if ($memberUserId === $ownerUserId) {
            return ['success' => false, 'error' => 'إنت صاحب الحساب بالفعل'];
        }

        $existing = $this->db->query(
            "SELECT id, status FROM ad_team_members WHERE owner_user_id = ? AND member_user_id = ? LIMIT 1",
            [$ownerUserId, $memberUserId]
        );

        if (!empty($existing)) {
            $this->db->exec("UPDATE ad_team_members SET role = ?, status = 'active' WHERE id = ?", [$role, $existing[0]['id']]);
        } else {
            $this->db->exec(
                "INSERT INTO ad_team_members (owner_user_id, member_user_id, role, invited_by_user_id, status) VALUES (?, ?, ?, ?, 'active')",
                [$ownerUserId, $memberUserId, $role, $invitedByUserId]
            );
        }

        return ['success' => true];
    }

    public function updateMemberRole(int $ownerUserId, int $memberId, string $newRole): bool
    {
        if (!in_array($newRole, ['viewer', 'manager', 'admin'], true)) {
            return false;
        }
        return $this->db->exec(
            "UPDATE ad_team_members SET role = ? WHERE id = ? AND owner_user_id = ?",
            [$newRole, $memberId, $ownerUserId]
        ) > 0;
    }

    public function removeMember(int $ownerUserId, int $memberId): bool
    {
        return $this->db->exec(
            "UPDATE ad_team_members SET status = 'removed' WHERE id = ? AND owner_user_id = ?",
            [$memberId, $ownerUserId]
        ) > 0;
    }

    public function accountsUserBelongsTo(int $memberUserId): array
    {
        return $this->db->query(
            "SELECT tm.owner_user_id, tm.role, u.company_name
             FROM ad_team_members tm JOIN users u ON u.id = tm.owner_user_id
             WHERE tm.member_user_id = ? AND tm.status = 'active'",
            [$memberUserId]
        );
    }
}
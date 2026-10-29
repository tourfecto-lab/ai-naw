<?php
/**
 * Tourfecto - who an employee works for.
 *
 * The platform has two ways to add staff:
 *  - Workspace (Settings → Team): users.owner_user_id + users.workspace_role
 *  - Business Center → Team: business_members (admin/member/viewer) of a business the owner runs
 * Both are treated the same here, so features shared with staff (inbox, plan access) work either way.
 */
class TeamAccess
{
    private static array $ownerCache = [];

    /** The account owner this user works for, or null when the user is not someone's staff. */
    public static function ownerFor(array $user): ?int
    {
        $uid = (int) ($user['id'] ?? 0);
        if ($uid < 1) return null;
        if (!empty($user['owner_user_id']) && (int) $user['owner_user_id'] !== $uid) return (int) $user['owner_user_id'];
        if (array_key_exists($uid, self::$ownerCache)) return self::$ownerCache[$uid];
        $owner = null;
        try {
            // Someone with their own website is an owner first: their own plan and data apply.
            // (They can still open other companies' inboxes they are a member of; see ownersFor().)
            if (Database::getInstance()->query('SELECT id FROM websites WHERE user_id = ? LIMIT 1', [$uid])) {
                return self::$ownerCache[$uid] = null;
            }
            $r = Database::getInstance()->query(
                "SELECT b.owner_user_id FROM business_members m JOIN businesses b ON b.id = m.business_id
                 WHERE m.user_id = ? AND m.status = 'active' AND b.owner_user_id <> ? ORDER BY m.id LIMIT 1",
                [$uid, $uid]
            );
            $owner = $r ? (int) $r[0]['owner_user_id'] : null;
        } catch (Throwable $e) {
            $owner = null; // team tables not installed
        }
        return self::$ownerCache[$uid] = $owner;
    }

    /** The account whose plan and balance apply: the owner for staff, the user otherwise. */
    public static function billingOwner(array $user): int
    {
        return self::ownerFor($user) ?? (int) ($user['id'] ?? 0);
    }

    /**
     * Inbox role of $userId on $ownerId's conversations: admin, member, viewer, or null (no access).
     * Workspace roles map as admin/manager → admin, sales/support → member, viewer → viewer.
     */
    public static function inboxRole(int $ownerId, int $userId): ?string
    {
        if ($ownerId < 1 || $userId < 1) return null;
        if ($ownerId === $userId) return 'owner';
        $roles = [];
        try {
            $w = Database::getInstance()->query('SELECT owner_user_id, workspace_role FROM users WHERE id = ? LIMIT 1', [$userId]);
            if ($w && (int) $w[0]['owner_user_id'] === $ownerId) {
                $map = ['admin' => 'admin', 'manager' => 'admin', 'sales' => 'member', 'support' => 'member', 'viewer' => 'viewer'];
                $roles[] = $map[(string) ($w[0]['workspace_role'] ?? '')] ?? 'member';
            }
        } catch (Throwable $e) {
        error_log('[TeamAccess] ignored error: ' . $e->getMessage());
        }
        try {
            foreach (Database::getInstance()->query(
                "SELECT m.role FROM business_members m JOIN businesses b ON b.id = m.business_id
                 WHERE b.owner_user_id = ? AND m.user_id = ? AND m.status = 'active' AND m.role IN ('admin','member','viewer')",
                [$ownerId, $userId]
            ) ?: [] as $r) {
                $roles[] = (string) $r['role'];
            }
        } catch (Throwable $e) {
        error_log('[TeamAccess] ignored error: ' . $e->getMessage());
        }
        foreach (['admin', 'member', 'viewer'] as $best) {
            if (in_array($best, $roles, true)) return $best;
        }
        return null;
    }

    /** Owners whose inbox this user can open (excluding themselves), with the user's role for each. */
    public static function ownersFor(int $userId): array
    {
        $out = [];
        try {
            $w = Database::getInstance()->query('SELECT owner_user_id FROM users WHERE id = ? AND owner_user_id IS NOT NULL LIMIT 1', [$userId]);
            if ($w && (int) $w[0]['owner_user_id'] !== $userId) $out[(int) $w[0]['owner_user_id']] = true;
        } catch (Throwable $e) {
        error_log('[TeamAccess] ignored error: ' . $e->getMessage());
        }
        try {
            foreach (Database::getInstance()->query(
                "SELECT DISTINCT b.owner_user_id FROM business_members m JOIN businesses b ON b.id = m.business_id
                 WHERE m.user_id = ? AND m.status = 'active' AND b.owner_user_id <> ?",
                [$userId, $userId]
            ) ?: [] as $r) {
                $out[(int) $r['owner_user_id']] = true;
            }
        } catch (Throwable $e) {
        error_log('[TeamAccess] ignored error: ' . $e->getMessage());
        }
        $roles = [];
        foreach (array_keys($out) as $owner) {
            $role = self::inboxRole($owner, $userId);
            if ($role !== null) $roles[$owner] = $role;
        }
        return $roles;
    }

    /** Staff who can handle conversations for $ownerId (excluding the owner and viewers). */
    public static function agentIds(int $ownerId): array
    {
        $ids = [];
        try {
            foreach (Database::getInstance()->query("SELECT id, workspace_role FROM users WHERE owner_user_id = ? AND (is_active IS NULL OR is_active = 1)", [$ownerId]) ?: [] as $r) {
                if (($r['workspace_role'] ?? '') !== 'viewer') $ids[] = (int) $r['id'];
            }
        } catch (Throwable $e) {
        error_log('[TeamAccess] ignored error: ' . $e->getMessage());
        }
        try {
            foreach (Database::getInstance()->query(
                "SELECT DISTINCT m.user_id FROM business_members m JOIN businesses b ON b.id = m.business_id
                 WHERE b.owner_user_id = ? AND m.status = 'active' AND m.user_id IS NOT NULL AND m.role IN ('admin','member')",
                [$ownerId]
            ) ?: [] as $r) {
                $ids[] = (int) $r['user_id'];
            }
        } catch (Throwable $e) {
        error_log('[TeamAccess] ignored error: ' . $e->getMessage());
        }
        return array_values(array_unique(array_diff($ids, [$ownerId])));
    }
}

<?php

/**
 * Tourfecto - AI Voice Agent: switch the voice feature on or off for one account from the admin panel. The decision is stored in voice_feature_grants
 * and wins over the plan in both directions (grant or revoke); removing it hands the decision back to the plan. No subscription is required to grant.
 */
class VoiceFeatureAccess
{
    public const MAX_NUMBERS_LIMIT = 50;

    public static function clampMax(?int $n): ?int
    {
        return $n === null ? null : max(1, min(self::MAX_NUMBERS_LIMIT, $n));
    }

    /** @return list<array<string,mixed>> accounts matching an e-mail / company fragment, with their current voice access and where it comes from */
    public function search(string $q, int $limit = 20): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $rows = Database::getInstance()->query(
            'SELECT id, email, company_name, owner_user_id FROM users WHERE email LIKE ? OR company_name LIKE ? ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)),
            [$like, $like]
        ) ?: [];
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['is_staff_of'] = $r['owner_user_id'] ? (int) $r['owner_user_id'] : null; // staff use their owner's access: change the owner instead
            $g = VoiceAccessService::grant($r['id']);
            $r['source'] = $g === null ? 'plan' : 'admin';
            $r['voice_enabled'] = VoiceAccessService::featureEnabled($r['id']);
            $r['voice_max_numbers'] = VoiceAccessService::maxNumbers($r['id']);
            unset($r['owner_user_id']);
        }
        return $rows;
    }

    /** @param bool|null $enabled null removes the admin decision (the plan decides again) @throws InvalidArgumentException */
    public function set(int $userId, ?bool $enabled, ?int $maxNumbers, int $adminId): void
    {
        $u = Database::getInstance()->query('SELECT id, owner_user_id FROM users WHERE id = ? LIMIT 1', [$userId])[0] ?? null;
        if (!$u) {
            throw new InvalidArgumentException('User not found');
        }
        if (!empty($u['owner_user_id']) && (int) $u['owner_user_id'] !== $userId) {
            throw new InvalidArgumentException('This user is staff of another account; change the account owner instead');
        }
        if ($enabled === null) {
            Database::getInstance()->exec('DELETE FROM voice_feature_grants WHERE tenant_user_id = ?', [$userId]);
            return;
        }
        Database::getInstance()->exec(
            'INSERT INTO voice_feature_grants (tenant_user_id, enabled, max_numbers, granted_by_user_id, updated_at) VALUES (?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), max_numbers = IF(VALUES(enabled) = 1, COALESCE(VALUES(max_numbers), max_numbers), max_numbers), granted_by_user_id = VALUES(granted_by_user_id), updated_at = NOW()',
            [$userId, $enabled ? 1 : 0, self::clampMax($maxNumbers), $adminId]
        );
    }
}

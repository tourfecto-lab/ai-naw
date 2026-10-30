<?php

/** Tourfecto - AI Voice Agent: the people a call can be handed to (per company). */
class VoiceStaffService
{
    private function db(): Database
    {
        return Database::getInstance();
    }

    /** Pure validation of one staff entry. */
    public static function sanitize(array $in): array
    {
        $name = trim(strip_tags((string) ($in['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Name is required (max 120 chars)');
        }
        $phone = VoiceNumberRules::normalizeE164((string) ($in['phone'] ?? ''));
        $topics = is_array($in['topics'] ?? null) ? $in['topics'] : array_filter(array_map('trim', explode(',', (string) ($in['topics'] ?? 'any'))));
        $topics = $topics ?: ['any'];
        foreach ($topics as $t) {
            if ($t !== 'any' && !in_array($t, VoiceStaffRouting::TOPICS, true)) {
                throw new InvalidArgumentException('Unknown topic: ' . $t);
            }
        }
        $days = is_array($in['days'] ?? null) ? $in['days'] : array_filter(array_map('trim', explode(',', (string) ($in['days'] ?? '1,2,3,4,5,6,7'))));
        foreach ($days as $d) {
            if (!preg_match('/^[1-7]$/', (string) $d)) {
                throw new InvalidArgumentException('Days are ISO weekdays 1 (Mon) to 7 (Sun)');
            }
        }
        $from = (string) ($in['from_time'] ?? '00:00');
        $to = (string) ($in['to_time'] ?? '23:59');
        if (!VoiceStaffRouting::validTime($from) || !VoiceStaffRouting::validTime($to)) {
            throw new InvalidArgumentException('Times must be HH:MM');
        }
        return ['name' => $name, 'phone' => $phone, 'topics' => implode(',', array_unique($topics)), 'days' => implode(',', array_unique($days ?: [1, 2, 3, 4, 5, 6, 7])),
            'from_time' => $from, 'to_time' => $to, 'priority' => max(1, min(1000, (int) ($in['priority'] ?? 100))), 'active' => array_key_exists('active', $in) ? (filter_var($in['active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : 1];
    }

    public function list(int $tenantId): array
    {
        return $this->db()->query('SELECT id, name, phone, topics, days, from_time, to_time, priority, active FROM voice_staff WHERE tenant_user_id = ? ORDER BY priority, id', [$tenantId]) ?: [];
    }

    /** Active people only, for routing. */
    public function forRouting(int $tenantId): array
    {
        return $this->db()->query('SELECT id, name, phone, topics, days, from_time, to_time, priority, active FROM voice_staff WHERE tenant_user_id = ? AND active = 1', [$tenantId]) ?: [];
    }

    public function create(int $tenantId, int $actorUserId, array $in): int
    {
        $s = self::sanitize($in);
        $this->db()->exec(
            'INSERT INTO voice_staff (tenant_user_id, name, phone, topics, days, from_time, to_time, priority, active, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())',
            [$tenantId, $s['name'], $s['phone'], $s['topics'], $s['days'], $s['from_time'], $s['to_time'], $s['priority'], $s['active']]
        );
        $id = (int) $this->db()->getConnection()->lastInsertId();
        VoiceAuditService::record($tenantId, $actorUserId, 'staff.created', 'staff', $id);
        return $id;
    }

    public function update(int $tenantId, int $actorUserId, int $id, array $in): bool
    {
        if (!$this->db()->query('SELECT id FROM voice_staff WHERE id = ? AND tenant_user_id = ? LIMIT 1', [$id, $tenantId])) {
            return false;
        }
        $s = self::sanitize($in);
        $this->db()->exec(
            'UPDATE voice_staff SET name=?, phone=?, topics=?, days=?, from_time=?, to_time=?, priority=?, active=?, updated_at=NOW() WHERE id=? AND tenant_user_id=?',
            [$s['name'], $s['phone'], $s['topics'], $s['days'], $s['from_time'], $s['to_time'], $s['priority'], $s['active'], $id, $tenantId]
        );
        VoiceAuditService::record($tenantId, $actorUserId, 'staff.updated', 'staff', $id);
        return true;
    }

    public function delete(int $tenantId, int $actorUserId, int $id): bool
    {
        $ok = $this->db()->exec('DELETE FROM voice_staff WHERE id = ? AND tenant_user_id = ?', [$id, $tenantId]);
        VoiceAuditService::record($tenantId, $actorUserId, 'staff.deleted', 'staff', $id);
        return $ok;
    }
}

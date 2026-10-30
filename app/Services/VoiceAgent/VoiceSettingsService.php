<?php

/** Tourfecto - AI Voice & Sales Agent: per-tenant settings and agent profiles. Every query is scoped by $tenantId. */
class VoiceSettingsService
{
    public const LANGUAGES = ['en', 'ar', 'fr', 'de', 'es', 'it', 'ru', 'tr'];

    public const DEFAULTS = [
        'enabled' => 0, 'sandbox_mode' => 1, 'default_language' => 'en', 'outbound_enabled' => 0,
        'recording_enabled' => 0, 'monthly_budget_usd' => null, 'human_handoff_phone' => null,
    ];

    /** Pure: validate and normalise user input into the writable settings columns. */
    public static function sanitizeSettings(array $in): array
    {
        $out = [];
        foreach (['enabled', 'sandbox_mode', 'outbound_enabled', 'recording_enabled'] as $k) {
            if (array_key_exists($k, $in)) {
                $out[$k] = filter_var($in[$k], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
        }
        if (array_key_exists('default_language', $in)) {
            $lang = strtolower(trim((string) $in['default_language']));
            if (!in_array($lang, self::LANGUAGES, true)) {
                throw new InvalidArgumentException('Unsupported language');
            }
            $out['default_language'] = $lang;
        }
        if (array_key_exists('monthly_budget_usd', $in)) {
            $v = $in['monthly_budget_usd'];
            if ($v === null || $v === '') {
                $out['monthly_budget_usd'] = null;
            } elseif (!is_numeric($v) || (float) $v < 0 || (float) $v > 1000000) {
                throw new InvalidArgumentException('Invalid monthly budget');
            } else {
                $out['monthly_budget_usd'] = round((float) $v, 2);
            }
        }
        if (array_key_exists('human_handoff_phone', $in)) {
            $p = trim((string) $in['human_handoff_phone']);
            if ($p === '') {
                $out['human_handoff_phone'] = null;
            } elseif (!preg_match('/^\+[1-9][0-9]{6,14}$/', $p)) {
                throw new InvalidArgumentException('Handoff phone must be E.164 (+...)');
            } else {
                $out['human_handoff_phone'] = $p;
            }
        }
        return $out;
    }

    /** Pure: validate an agent profile. */
    public static function sanitizeAgent(array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Agent name is required (max 120 chars)');
        }
        $lang = strtolower(trim((string) ($in['language'] ?? 'en')));
        if (!in_array($lang, self::LANGUAGES, true)) {
            throw new InvalidArgumentException('Unsupported language');
        }
        $status = (string) ($in['status'] ?? 'draft');
        if (!in_array($status, ['draft', 'active', 'paused'], true)) {
            throw new InvalidArgumentException('Invalid status');
        }
        $voice = isset($in['voice']) ? mb_substr(trim((string) $in['voice']), 0, 60) : null;
        $greeting = isset($in['greeting']) ? mb_substr(trim((string) $in['greeting']), 0, 1000) : null;
        return ['name' => $name, 'language' => $lang, 'status' => $status, 'voice' => $voice ?: null, 'greeting' => $greeting ?: null];
    }

    public function get(int $tenantId): array
    {
        $r = Database::getInstance()->query('SELECT * FROM voice_agent_settings WHERE tenant_user_id = ? LIMIT 1', [$tenantId]);
        return $r ? $r[0] : self::DEFAULTS + ['tenant_user_id' => $tenantId];
    }

    public function save(int $tenantId, array $input): array
    {
        $clean = self::sanitizeSettings($input);
        if ($clean) {
            $row = $clean + $this->get($tenantId);
            unset($row['created_at'], $row['updated_at'], $row['tenant_user_id']);
            Database::getInstance()->exec(
                'INSERT INTO voice_agent_settings (tenant_user_id, enabled, sandbox_mode, default_language, outbound_enabled, recording_enabled, monthly_budget_usd, human_handoff_phone, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())
                 ON DUPLICATE KEY UPDATE enabled=VALUES(enabled), sandbox_mode=VALUES(sandbox_mode), default_language=VALUES(default_language),
                   outbound_enabled=VALUES(outbound_enabled), recording_enabled=VALUES(recording_enabled),
                   monthly_budget_usd=VALUES(monthly_budget_usd), human_handoff_phone=VALUES(human_handoff_phone), updated_at=NOW()',
                [$tenantId, $row['enabled'], $row['sandbox_mode'], $row['default_language'], $row['outbound_enabled'], $row['recording_enabled'], $row['monthly_budget_usd'], $row['human_handoff_phone']]
            );
        }
        return $this->get($tenantId);
    }

    public function listAgents(int $tenantId): array
    {
        return Database::getInstance()->query('SELECT id, name, language, voice, greeting, status, created_at, updated_at FROM voice_agents WHERE tenant_user_id = ? ORDER BY id DESC', [$tenantId]) ?: [];
    }

    public function createAgent(int $tenantId, int $actorUserId, array $input): int
    {
        $a = self::sanitizeAgent($input);
        $db = Database::getInstance();
        $db->exec(
            'INSERT INTO voice_agents (tenant_user_id, name, language, voice, greeting, status, created_by_user_id, created_at, updated_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())',
            [$tenantId, $a['name'], $a['language'], $a['voice'], $a['greeting'], $a['status'], $actorUserId]
        );
        return (int) $db->getConnection()->lastInsertId();
    }

    public function updateAgent(int $tenantId, int $agentId, array $input): bool
    {
        $a = self::sanitizeAgent($input);
        $own = Database::getInstance()->query('SELECT id FROM voice_agents WHERE id = ? AND tenant_user_id = ? LIMIT 1', [$agentId, $tenantId]);
        if (!$own) {
            return false;
        }
        return Database::getInstance()->exec(
            'UPDATE voice_agents SET name=?, language=?, voice=?, greeting=?, status=?, updated_at=NOW() WHERE id=? AND tenant_user_id=?',
            [$a['name'], $a['language'], $a['voice'], $a['greeting'], $a['status'], $agentId, $tenantId]
        );
    }
}

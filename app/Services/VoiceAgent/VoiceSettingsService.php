<?php

/** Tourfecto - AI Voice & Sales Agent: per-tenant settings and agent profiles. Every query is scoped by $tenantId. */
class VoiceSettingsService
{
    public const LANGUAGES = ['en', 'ar', 'fr', 'de', 'es', 'it', 'ru', 'tr'];

    public const DEFAULTS = [
        'enabled' => 0, 'sandbox_mode' => 1, 'default_language' => 'en', 'outbound_enabled' => 0,
        'recording_enabled' => 0, 'monthly_budget_usd' => null, 'human_handoff_phone' => null,
        'booking_mode' => 'request_only', 'booking_max_amount' => null, 'post_call_whatsapp' => 0, 'post_call_email' => 0, 'payment_link_pct' => 0, 'whatsapp_website_id' => null,
    ];

    public const BOOKING_MODES = ['request_only', 'create_pending', 'auto_confirm'];
    private const COLUMNS = ['enabled', 'sandbox_mode', 'default_language', 'outbound_enabled', 'recording_enabled', 'monthly_budget_usd', 'human_handoff_phone', 'booking_mode', 'booking_max_amount', 'post_call_whatsapp', 'post_call_email', 'payment_link_pct', 'whatsapp_website_id'];

    /** Pure: validate and normalise user input into the writable settings columns. */
    public static function sanitizeSettings(array $in): array
    {
        $out = [];
        foreach (['enabled', 'sandbox_mode', 'outbound_enabled', 'recording_enabled', 'post_call_whatsapp', 'post_call_email'] as $k) {
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
        if (array_key_exists('booking_mode', $in)) {
            if (!in_array((string) $in['booking_mode'], self::BOOKING_MODES, true)) {
                throw new InvalidArgumentException('Invalid booking mode');
            }
            $out['booking_mode'] = (string) $in['booking_mode'];
        }
        if (array_key_exists('booking_max_amount', $in)) {
            $v = $in['booking_max_amount'];
            if ($v === null || $v === '') {
                $out['booking_max_amount'] = null;
            } elseif (!is_numeric($v) || (float) $v < 0 || (float) $v > 1000000) {
                throw new InvalidArgumentException('Invalid booking limit');
            } else {
                $out['booking_max_amount'] = round((float) $v, 2);
            }
        }
        if (array_key_exists('payment_link_pct', $in)) {
            $v = filter_var($in['payment_link_pct'], FILTER_VALIDATE_INT);
            if ($v === false || ($v !== 0 && ($v < 5 || $v > 100))) {
                throw new InvalidArgumentException('Payment link percent must be 0 (off) or between 5 and 100');
            }
            $out['payment_link_pct'] = $v;
        }
        if (array_key_exists('whatsapp_website_id', $in)) {
            $v = $in['whatsapp_website_id'];
            $out['whatsapp_website_id'] = ($v === null || $v === '') ? null : max(1, (int) $v);
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
        if (!empty($clean['whatsapp_website_id']) && !Database::getInstance()->query('SELECT id FROM websites WHERE id = ? AND user_id = ? LIMIT 1', [$clean['whatsapp_website_id'], $tenantId])) {
            throw new InvalidArgumentException('Website not found');
        }
        if (($clean['booking_mode'] ?? null) === 'auto_confirm' && !array_key_exists('booking_max_amount', $clean) && ($this->get($tenantId)['booking_max_amount'] ?? null) === null) {
            throw new InvalidArgumentException('Set a maximum booking amount before enabling auto-confirm');
        }
        if ($clean) {
            $row = $clean + $this->get($tenantId);
            $vals = [];
            foreach (self::COLUMNS as $c) {
                $vals[] = $row[$c] ?? null;
            }
            $ph = implode(',', array_fill(0, count(self::COLUMNS), '?'));
            $upd = implode(', ', array_map(static fn ($c) => "$c=VALUES($c)", self::COLUMNS));
            Database::getInstance()->exec(
                'INSERT INTO voice_agent_settings (tenant_user_id, ' . implode(', ', self::COLUMNS) . ', created_at, updated_at) VALUES (?,' . $ph . ',NOW(),NOW()) ON DUPLICATE KEY UPDATE ' . $upd . ', updated_at=NOW()',
                array_merge([$tenantId], $vals)
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

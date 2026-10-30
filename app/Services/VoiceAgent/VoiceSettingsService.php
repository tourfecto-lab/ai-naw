<?php

/** Tourfecto - AI Voice & Sales Agent: per-tenant settings and agent profiles. Every query is scoped by $tenantId. */
class VoiceSettingsService
{
    public const LANGUAGES = ['en', 'ar', 'fr', 'de', 'es', 'it', 'ru', 'tr'];

    public const DEFAULTS = [
        'enabled' => 0, 'sandbox_mode' => 1, 'default_language' => 'en', 'outbound_enabled' => 0,
        'recording_enabled' => 0, 'monthly_budget_usd' => null, 'human_handoff_phone' => null,
        'budget_policy' => 'block', 'daily_minutes_cap' => 0, 'recording_retention_days' => 30, 'transcript_retention_days' => 90, 'allowed_dial_countries' => null, 'supervisor_enabled' => 1, 'supervisor_sample_pct' => 20, 'supervisor_daily_cap' => 20, 'timezone' => null, 'after_hours_mode' => 'ai', 'knowledge_website_id' => null, 'daily_outbound_cap' => 100, 'max_concurrent_calls' => 3, 'booking_mode' => 'request_only', 'booking_max_amount' => null, 'post_call_whatsapp' => 0, 'post_call_email' => 0, 'post_call_wa_template' => null, 'post_call_wa_template_lang' => null, 'auto_abandoned_recovery' => 0, 'auto_trip_reminders' => 0, 'auto_booking_confirmation' => 0, 'auto_followups_live_approved' => 0, 'payment_link_pct' => 0, 'whatsapp_website_id' => null,
    ];

    public const BOOKING_MODES = ['request_only', 'create_pending', 'auto_confirm'];
    private const COLUMNS = ['enabled', 'sandbox_mode', 'default_language', 'outbound_enabled', 'recording_enabled', 'monthly_budget_usd', 'human_handoff_phone', 'budget_policy', 'daily_minutes_cap', 'recording_retention_days', 'transcript_retention_days', 'allowed_dial_countries', 'supervisor_enabled', 'supervisor_sample_pct', 'supervisor_daily_cap', 'timezone', 'after_hours_mode', 'knowledge_website_id', 'daily_outbound_cap', 'max_concurrent_calls', 'booking_mode', 'booking_max_amount', 'post_call_whatsapp', 'post_call_email', 'post_call_wa_template', 'post_call_wa_template_lang', 'auto_abandoned_recovery', 'auto_trip_reminders', 'auto_booking_confirmation', 'auto_followups_live_approved', 'payment_link_pct', 'whatsapp_website_id'];

    /** Pure: validate and normalise user input into the writable settings columns. */
    public static function sanitizeSettings(array $in): array
    {
        $out = [];
        foreach (['supervisor_enabled', 'enabled', 'sandbox_mode', 'outbound_enabled', 'recording_enabled', 'post_call_whatsapp', 'post_call_email', 'auto_abandoned_recovery', 'auto_trip_reminders', 'auto_booking_confirmation', 'auto_followups_live_approved'] as $k) {
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
        if (array_key_exists('post_call_wa_template', $in)) {
            $v = trim((string) $in['post_call_wa_template']);
            if ($v !== '' && !preg_match('/^[a-z0-9_]{1,80}$/', $v)) {
                throw new InvalidArgumentException('Template name: lowercase letters, digits and underscores only');
            }
            $out['post_call_wa_template'] = $v === '' ? null : $v;
        }
        if (array_key_exists('post_call_wa_template_lang', $in)) {
            $v = trim((string) $in['post_call_wa_template_lang']);
            if ($v !== '' && !preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $v)) {
                throw new InvalidArgumentException('Template language like en or en_US');
            }
            $out['post_call_wa_template_lang'] = $v === '' ? null : $v;
        }
        if (array_key_exists('budget_policy', $in)) {
            if (!in_array((string) $in['budget_policy'], ['block', 'warn'], true)) {
                throw new InvalidArgumentException('Budget policy must be block or warn');
            }
            $out['budget_policy'] = (string) $in['budget_policy'];
        }
        if (array_key_exists('allowed_dial_countries', $in)) {
            $isos = is_array($in['allowed_dial_countries']) ? $in['allowed_dial_countries'] : array_filter(array_map('trim', explode(',', (string) $in['allowed_dial_countries'])));
            $known = array_column(VoiceMarkets::targets(), 'iso');
            $clean = [];
            foreach ($isos as $c) {
                $c = strtoupper((string) $c);
                if (!in_array($c, $known, true)) {
                    throw new InvalidArgumentException('Outbound calling is only available to supported countries: ' . implode(', ', $known));
                }
                $clean[$c] = $c;
            }
            $out['allowed_dial_countries'] = $clean ? implode(',', $clean) : null;
        }
        foreach (['daily_minutes_cap' => [0, 1440], 'recording_retention_days' => [1, 365], 'transcript_retention_days' => [1, 730], 'supervisor_sample_pct' => [0, 100], 'supervisor_daily_cap' => [0, 500]] as $k => [$lo, $hi]) {
            if (array_key_exists($k, $in)) {
                $v = filter_var($in[$k], FILTER_VALIDATE_INT);
                if ($v === false || $v < $lo || $v > $hi) {
                    throw new InvalidArgumentException("$k must be between $lo and $hi");
                }
                $out[$k] = $v;
            }
        }
        if (array_key_exists('timezone', $in)) {
            $v = trim((string) $in['timezone']);
            if ($v !== '' && !in_array($v, DateTimeZone::listIdentifiers(), true)) {
                throw new InvalidArgumentException('Unknown time zone');
            }
            $out['timezone'] = $v === '' ? null : $v;
        }
        if (array_key_exists('after_hours_mode', $in)) {
            if (!in_array((string) $in['after_hours_mode'], ['ai', 'message', 'forward'], true)) {
                throw new InvalidArgumentException('After-hours mode must be ai, message or forward');
            }
            $out['after_hours_mode'] = (string) $in['after_hours_mode'];
        }
        if (array_key_exists('knowledge_website_id', $in)) {
            $v = $in['knowledge_website_id'];
            $out['knowledge_website_id'] = ($v === null || $v === '') ? null : max(1, (int) $v);
        }
        if (array_key_exists('daily_outbound_cap', $in)) {
            $v = filter_var($in['daily_outbound_cap'], FILTER_VALIDATE_INT);
            if ($v === false || $v < 1 || $v > 10000) {
                throw new InvalidArgumentException('Daily outbound limit must be between 1 and 10000');
            }
            $out['daily_outbound_cap'] = $v;
        }
        if (array_key_exists('max_concurrent_calls', $in)) {
            $v = filter_var($in['max_concurrent_calls'], FILTER_VALIDATE_INT);
            if ($v === false || $v < 1 || $v > 50) {
                throw new InvalidArgumentException('Concurrent calls must be between 1 and 50');
            }
            $out['max_concurrent_calls'] = $v;
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
        $role = (string) ($in['role'] ?? 'reception');
        if (!in_array($role, VoiceRolePrompts::ROLES, true)) {
            throw new InvalidArgumentException('Invalid role');
        }
        $tone = (string) ($in['tone'] ?? 'friendly');
        if (!in_array($tone, VoiceRolePrompts::TONES, true)) {
            throw new InvalidArgumentException('Invalid tone');
        }
        $extra = [];
        foreach (is_array($in['languages'] ?? null) ? $in['languages'] : array_filter(explode(',', (string) ($in['languages'] ?? ''))) as $l) {
            $l = strtolower(trim((string) $l));
            if (!in_array($l, self::LANGUAGES, true)) {
                throw new InvalidArgumentException('Unsupported language: ' . $l);
            }
            if ($l !== $lang && !in_array($l, $extra, true)) {
                $extra[] = $l;
            }
        }
        $opening = isset($in['opening_text']) ? mb_substr(trim(strip_tags((string) $in['opening_text'])), 0, 400) : null;
        $instructions = isset($in['instructions']) ? mb_substr(trim(strip_tags((string) $in['instructions'])), 0, 1500) : null;
        return ['name' => $name, 'language' => $lang, 'status' => $status, 'voice' => $voice ?: null, 'role' => $role, 'tone' => $tone, 'languages' => $extra ? implode(',', $extra) : null, 'opening_text' => $opening ?: null, 'instructions' => $instructions ?: null];
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
        if (!empty($clean['knowledge_website_id']) && !Database::getInstance()->query('SELECT id FROM websites WHERE id = ? AND user_id = ? LIMIT 1', [$clean['knowledge_website_id'], $tenantId])) {
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
        return Database::getInstance()->query('SELECT id, name, language, languages, role, tone, voice, opening_text, instructions, status, created_at, updated_at FROM voice_agents WHERE tenant_user_id = ? ORDER BY id DESC', [$tenantId]) ?: [];
    }

    public function createAgent(int $tenantId, int $actorUserId, array $input): int
    {
        $a = self::sanitizeAgent($input);
        $db = Database::getInstance();
        $db->exec(
            'INSERT INTO voice_agents (tenant_user_id, name, language, languages, role, tone, voice, opening_text, instructions, status, created_by_user_id, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())',
            [$tenantId, $a['name'], $a['language'], $a['languages'], $a['role'], $a['tone'], $a['voice'], $a['opening_text'], $a['instructions'], $a['status'], $actorUserId]
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
            'UPDATE voice_agents SET name=?, language=?, languages=?, role=?, tone=?, voice=?, opening_text=?, instructions=?, status=?, updated_at=NOW() WHERE id=? AND tenant_user_id=?',
            [$a['name'], $a['language'], $a['languages'], $a['role'], $a['tone'], $a['voice'], $a['opening_text'], $a['instructions'], $a['status'], $agentId, $tenantId]
        );
    }
}

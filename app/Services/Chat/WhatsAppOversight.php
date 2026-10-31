<?php

/**
 * Admin oversight of WhatsApp marketing (the customer campaign engine in WhatsAppMarketingService) and the hub for the
 * platform's own WhatsApp marketing, which runs on the same engine under the admin's own account (so consent, approved
 * templates, quotas and opt-out handling are the same compliant ones; there is no second sending path).
 *
 * What the admin can do here: see every customer's campaigns with delivery numbers and quality flags, pause or cancel a
 * campaign with a written reason, and suspend or resume a whole account. Suspension is enforced where campaigns launch and
 * where messages are sent. Nothing here sends a message, changes a WhatsApp connection, scope, webhook or template.
 * Settings and the history live in system_settings (no migration).
 */
class WhatsAppOversight
{
    private const SUSPENDED = 'wa_marketing_suspended';
    private const AUDIT = 'wa_marketing_admin_audit';
    private const AUDIT_KEEP = 200;
    public const MIN_SENT_FOR_FLAGS = 20;
    public const FAILURE_FLAG = 0.20;

    private static function read(string $key, ?Database $db = null): array
    {
        try {
            $db = $db ?: Database::getInstance();
            $r = $db->query('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1', [$key]);
            $v = json_decode((string) ($r[0]['setting_value'] ?? ''), true);
            return is_array($v) ? $v : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function write(string $key, array $value, ?Database $db = null): void
    {
        $db = $db ?: Database::getInstance();
        $db->exec("INSERT INTO system_settings (setting_key, setting_value, is_secret, category) VALUES (?, ?, 0, 'whatsapp') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$key, json_encode($value)]);
    }

    public static function audit(string $action, array $detail, int $actor, ?Database $db = null): void
    {
        $log = self::read(self::AUDIT, $db);
        array_unshift($log, ['at' => gmdate('Y-m-d H:i:s'), 'actor_id' => $actor, 'action' => $action] + $detail);
        self::write(self::AUDIT, array_slice($log, 0, self::AUDIT_KEEP), $db);
    }

    /** Fails open: a settings problem must never stop a customer's campaign. */
    public static function suspended(int $userId, ?Database $db = null): bool
    {
        return isset(self::read(self::SUSPENDED, $db)[(string) $userId]);
    }

    /** @throws InvalidArgumentException */
    public static function assertNotSuspended(int $userId, ?Database $db = null): void
    {
        if (self::suspended($userId, $db)) {
            throw new InvalidArgumentException('WhatsApp marketing is paused for this account by the platform. Contact support.');
        }
    }

    private static function tenant(int $userId, ?Database $db = null): array
    {
        $db = $db ?: Database::getInstance();
        $u = $db->query('SELECT id, email, owner_user_id, COALESCE(NULLIF(company_name, \'\'), email) AS name FROM users WHERE id = ? LIMIT 1', [$userId])[0] ?? null;
        if (!$u) {
            throw new InvalidArgumentException('Customer not found');
        }
        if (!empty($u['owner_user_id'])) {
            throw new InvalidArgumentException('This is a team member: use the account owner');
        }
        return $u;
    }

    /** Suspend a whole account: its running or scheduled campaigns are paused now and nothing can launch or send. */
    public static function suspend(int $userId, string $reason, int $actor, ?Database $db = null): array
    {
        $db = $db ?: Database::getInstance();
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 300) {
            throw new InvalidArgumentException('Write the reason (up to 300 characters)');
        }
        $u = self::tenant($userId, $db);
        $list = self::read(self::SUSPENDED, $db);
        $list[(string) $userId] = ['reason' => $reason, 'at' => gmdate('Y-m-d H:i:s'), 'by' => $actor];
        self::write(self::SUSPENDED, $list, $db);
        $db->exec("UPDATE wa_marketing_campaigns SET state = 'paused', last_error = ? WHERE user_id = ? AND state IN ('running','scheduled')", [mb_substr('Paused by the platform: ' . $reason, 0, 255), $userId]);
        self::audit('suspend', ['customer' => $u['email'], 'reason' => $reason], $actor, $db);
        return ['customer' => $u['email']];
    }

    public static function resume(int $userId, int $actor, ?Database $db = null): array
    {
        $db = $db ?: Database::getInstance();
        $u = self::tenant($userId, $db);
        $list = self::read(self::SUSPENDED, $db);
        if (!isset($list[(string) $userId])) {
            throw new InvalidArgumentException('This account is not suspended');
        }
        unset($list[(string) $userId]);
        self::write(self::SUSPENDED, $list, $db);
        self::audit('resume', ['customer' => $u['email']], $actor, $db); // paused campaigns stay paused: the customer decides when to resume them
        return ['customer' => $u['email']];
    }

    public static function suspendedList(?Database $db = null): array
    {
        $db = $db ?: Database::getInstance();
        $list = self::read(self::SUSPENDED, $db);
        if (!$list) {
            return [];
        }
        $out = [];
        foreach ($db->query('SELECT id, email FROM users WHERE id IN (' . implode(',', array_map('intval', array_keys($list))) . ')') as $u) {
            $out[] = ['user_id' => (int) $u['id'], 'email' => $u['email']] + $list[(string) $u['id']];
        }
        return $out;
    }

    /** Pause or cancel one campaign of any customer, with a reason the customer can read in the campaign's last error. */
    public static function campaignAction(int $campaignId, string $action, string $reason, int $actor, ?Database $db = null): array
    {
        $db = $db ?: Database::getInstance();
        if (!in_array($action, ['pause', 'cancel'], true)) {
            throw new InvalidArgumentException('Choose pause or cancel');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 300) {
            throw new InvalidArgumentException('Write the reason (up to 300 characters)');
        }
        $c = $db->query('SELECT id, user_id, name, state FROM wa_marketing_campaigns WHERE id = ? LIMIT 1', [$campaignId])[0] ?? null;
        if (!$c) {
            throw new InvalidArgumentException('Campaign not found');
        }
        $res = (new WhatsAppMarketingService())->action((int) $c['user_id'], $campaignId, $action);
        $db->exec('UPDATE wa_marketing_campaigns SET last_error = ? WHERE id = ?', [mb_substr(($action === 'pause' ? 'Paused' : 'Cancelled') . ' by the platform: ' . $reason, 0, 255), $campaignId]);
        self::audit('campaign_' . $action, ['campaign_id' => $campaignId, 'name' => $c['name'], 'from' => $c['state'], 'reason' => $reason], $actor, $db);
        return ['state' => $res['state'] ?? null];
    }

    /** This month's numbers across all customers. */
    public static function kpis(?Database $db = null): array
    {
        $db = $db ?: Database::getInstance();
        $start = "DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01')";
        $c = $db->query("SELECT COUNT(*) AS total, SUM(state IN ('running','scheduled')) AS active, SUM(state = 'blocked') AS blocked, SUM(state = 'paused') AS paused, COUNT(DISTINCT user_id) AS customers FROM wa_marketing_campaigns WHERE created_at >= $start")[0] ?? [];
        $r = $db->query("SELECT SUM(r.state = 'accepted') AS accepted, SUM(r.state = 'failed') AS failed, SUM(r.state = 'uncertain') AS uncertain, SUM(r.state = 'skipped') AS skipped, SUM(r.state IN ('pending','processing')) AS pending FROM wa_marketing_recipients r JOIN wa_marketing_campaigns c ON c.id = r.campaign_id WHERE c.created_at >= $start")[0] ?? [];
        $o = (int) ($db->query("SELECT COUNT(*) c FROM wa_marketing_consent_events WHERE state = 'opted_out' AND created_at >= $start")[0]['c'] ?? 0);
        return [
            'campaigns' => (int) ($c['total'] ?? 0), 'active' => (int) ($c['active'] ?? 0), 'blocked' => (int) ($c['blocked'] ?? 0), 'paused' => (int) ($c['paused'] ?? 0), 'customers' => (int) ($c['customers'] ?? 0),
            'accepted' => (int) ($r['accepted'] ?? 0), 'failed' => (int) ($r['failed'] ?? 0), 'uncertain' => (int) ($r['uncertain'] ?? 0), 'skipped' => (int) ($r['skipped'] ?? 0), 'pending' => (int) ($r['pending'] ?? 0),
            'opt_outs' => $o, 'suspended_accounts' => count(self::read(self::SUSPENDED, $db)),
        ];
    }

    /** Quality flags from the numbers only (nothing is guessed). @return string[] */
    public static function flags(array $counts, string $state): array
    {
        $flags = [];
        $attempted = ($counts['accepted'] ?? 0) + ($counts['failed'] ?? 0);
        if ($attempted >= self::MIN_SENT_FOR_FLAGS && ($counts['failed'] ?? 0) / $attempted >= self::FAILURE_FLAG) {
            $flags[] = 'high_failure_rate';
        }
        if (($counts['uncertain'] ?? 0) > 0) {
            $flags[] = 'uncertain_sends';
        }
        if ($state === 'blocked') {
            $flags[] = 'blocked';
        }
        return $flags;
    }

    /** @return array{rows:array,total:int,pages:int} */
    public static function campaigns(string $state = '', string $q = '', int $page = 1, int $perPage = 25, ?Database $db = null, ?int $userId = null): array
    {
        $db = $db ?: Database::getInstance();
        $perPage = max(1, min(100, $perPage));
        $offset = (max(1, $page) - 1) * $perPage;
        $where = '1=1';
        $params = [];
        if (in_array($state, ['draft', 'scheduled', 'running', 'paused', 'blocked', 'completed', 'partial', 'cancelled'], true)) {
            $where .= ' AND c.state = ?';
            $params[] = $state;
        }
        if ($userId !== null) {
            $where .= ' AND c.user_id = ?';
            $params[] = $userId;
        }
        if ($q !== '') {
            $where .= ' AND (u.email LIKE ? OR c.name LIKE ? OR w.company_name LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like);
        }
        $total = (int) ($db->query("SELECT COUNT(*) c FROM wa_marketing_campaigns c JOIN users u ON u.id = c.user_id LEFT JOIN websites w ON w.id = c.website_id WHERE $where", $params)[0]['c'] ?? 0);
        $rows = $db->query("SELECT c.id, c.user_id, c.name, c.state, c.template_name, c.language, c.scheduled_at, c.created_at, c.last_error, u.email, COALESCE(NULLIF(u.company_name, ''), u.email) AS customer, w.company_name AS site FROM wa_marketing_campaigns c JOIN users u ON u.id = c.user_id LEFT JOIN websites w ON w.id = c.website_id WHERE $where ORDER BY c.id DESC LIMIT $perPage OFFSET $offset", $params) ?: [];
        $counts = [];
        if ($rows) {
            foreach ($db->query('SELECT campaign_id, state, COUNT(*) n FROM wa_marketing_recipients WHERE campaign_id IN (' . implode(',', array_map(static fn ($r) => (int) $r['id'], $rows)) . ') GROUP BY campaign_id, state') as $x) {
                $counts[(int) $x['campaign_id']][$x['state']] = (int) $x['n'];
            }
        }
        $suspended = self::read(self::SUSPENDED, $db);
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['user_id'] = (int) $r['user_id'];
            $r['counts'] = $counts[$r['id']] ?? [];
            $r['flags'] = self::flags($r['counts'], (string) $r['state']);
            $r['account_suspended'] = isset($suspended[(string) $r['user_id']]);
        }
        unset($r);
        return ['rows' => $rows, 'total' => $total, 'pages' => (int) ceil($total / $perPage)];
    }

    public static function auditLog(int $limit = 50, ?Database $db = null): array
    {
        $log = array_slice(self::read(self::AUDIT, $db), 0, max(1, $limit));
        $ids = array_values(array_unique(array_map('intval', array_column($log, 'actor_id'))));
        $names = [];
        if ($ids) {
            $db = $db ?: Database::getInstance();
            foreach ($db->query('SELECT id, email FROM users WHERE id IN (' . implode(',', $ids) . ')') as $u) {
                $names[(int) $u['id']] = $u['email'];
            }
        }
        foreach ($log as &$l) {
            $l['actor'] = $names[(int) ($l['actor_id'] ?? 0)] ?? '#' . (int) ($l['actor_id'] ?? 0);
        }
        return $log;
    }

    /**
     * The platform's own WhatsApp marketing, read from the admin's own account on the same engine.
     * Read-only: the sender connection state (never its secrets), template and consent counts, the public opt-in forms to share
     * (consent is the only way people enter the list) and the latest campaigns.
     */
    public static function hub(int $tenantId, ?int $websiteId = null, ?Database $db = null): array
    {
        $db = $db ?: Database::getInstance();
        $sites = $db->query('SELECT id, COALESCE(NULLIF(company_name, \'\'), main_url) AS name FROM websites WHERE user_id = ? ORDER BY id ASC LIMIT 20', [$tenantId]) ?: [];
        $wid = 0;
        foreach ($sites as $s) {
            if ($websiteId === null || (int) $s['id'] === $websiteId) {
                $wid = (int) $s['id'];
                break;
            }
        }
        if (!$wid) {
            return ['websites' => $sites, 'website_id' => 0];
        }
        $conn = $db->query("SELECT id, meta_json, connected_at FROM platform_connections WHERE website_id = ? AND platform = 'whatsapp_cloud' AND status = 'connected' ORDER BY id DESC LIMIT 1", [$wid])[0] ?? null;
        $display = $conn ? (string) (json_decode((string) $conn['meta_json'], true)['display_phone'] ?? '') : '';
        $tpl = [];
        foreach ($db->query('SELECT status, COUNT(*) n FROM wa_marketing_templates WHERE user_id = ? AND website_id = ? GROUP BY status', [$tenantId, $wid]) as $t) {
            $tpl[$t['status']] = (int) $t['n'];
        }
        $con = ['opted_in' => 0, 'opted_out' => 0, 'other' => 0];
        foreach ($db->query('SELECT consent_state, COUNT(*) n FROM wa_marketing_contacts WHERE user_id = ? AND website_id = ? GROUP BY consent_state', [$tenantId, $wid]) as $c) {
            $con[$c['consent_state'] === 'opted_in' ? 'opted_in' : ($c['consent_state'] === 'opted_out' ? 'opted_out' : 'other')] += (int) $c['n'];
        }
        $base = rtrim(defined('APP_URL') ? (string) APP_URL : '', '/');
        $forms = array_map(static fn ($f) => ['title' => $f['title'], 'language' => $f['language'], 'url' => $base . '/wa/signup/' . $f['token']],
            $db->query('SELECT title, language, token FROM wa_signup_forms WHERE user_id = ? AND website_id = ? AND active = 1 ORDER BY id DESC LIMIT 10', [$tenantId, $wid]) ?: []);
        $campaigns = self::campaigns('', '', 1, 10, $db, $tenantId)['rows'];
        return [
            'websites' => $sites, 'website_id' => $wid, 'connected' => (bool) $conn, 'sender' => $display,
            'templates' => $tpl, 'contacts' => $con, 'forms' => $forms, 'campaigns' => $campaigns,
            'suspended' => self::suspended($tenantId, $db),
        ];
    }
}

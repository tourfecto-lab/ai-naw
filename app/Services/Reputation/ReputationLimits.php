<?php

/**
 * Reputation: limits per platform plan, controlled by the admin (no code change, no second billing system).
 *
 * The customer's plan (starter / professional / enterprise, or "free" without a subscription) comes from the existing
 * subscription. Prices and AI reply credits (review_credits) stay in Plans Studio. This class adds what Plans Studio
 * does not meter for reputation: review requests per month, connected Google locations, team assignment, CSV import.
 *
 * Defaults are "unlimited / on" for every plan, so deploying this changes nothing for existing customers until the
 * admin sets a number. The one exception is SMS (paid per message), which is off (0) until the admin opens it; the SMS
 * channel could not be used before, so no existing customer is affected. A per-customer exception can be set through the existing feature overrides
 * (keys reputation_review_requests_monthly, reputation_sms_requests_monthly, reputation_locations, reputation_team_assignment, reputation_csv_import).
 * Every change is written to an audit trail (who, when, old -> new). Counts are soft limits: two requests created at
 * the same instant can overshoot by one.
 */
class ReputationLimitReached extends Exception
{
    public string $feature;

    public function __construct(string $message, string $feature)
    {
        parent::__construct($message);
        $this->feature = $feature;
    }
}

class ReputationLimits
{
    public const TIERS = ['free', 'starter', 'professional', 'enterprise'];
    public const NUMBER_KEYS = ['review_requests_monthly', 'sms_requests_monthly', 'locations'];
    public const BOOL_KEYS = ['team_assignment', 'csv_import'];
    public const UNLIMITED = -1;
    public const MAX_VALUE = 1000000;
    private const SETTING = 'reputation_limits';
    private const AUDIT = 'reputation_limits_audit';
    private const OVERRIDES = 'reputation_limit_overrides';
    public const MAX_OVERRIDES = 300;
    private const AUDIT_KEEP = 100;

    public const LABELS = [
        'review_requests_monthly' => 'Review requests per month',
        'sms_requests_monthly' => 'SMS review requests per month (paid per message)',
        'locations' => 'Connected Google locations',
        'team_assignment' => 'Assign reviews to team members',
        'csv_import' => 'CSV import from other review sites',
    ];

    public static function defaults(): array
    {
        // SMS is paid per message (about $0.40 per segment to Egypt, several segments per Arabic message): off until the admin opens it
        $tier = ['review_requests_monthly' => self::UNLIMITED, 'sms_requests_monthly' => 0, 'locations' => self::UNLIMITED, 'team_assignment' => true, 'csv_import' => true];
        return array_fill_keys(self::TIERS, $tier);
    }

    private static function read(string $key, ?Database $db = null): string
    {
        $db = $db ?: Database::getInstance();
        $r = $db->query('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1', [$key]);
        return (string) ($r[0]['setting_value'] ?? '');
    }

    private static function write(string $key, string $value, ?Database $db = null): void
    {
        $db = $db ?: Database::getInstance();
        $db->exec(
            "INSERT INTO system_settings (setting_key, setting_value, is_secret, category) VALUES (?, ?, 0, 'reputation') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
            [$key, $value]
        );
    }

    /** @return array<string,array<string,int|bool>> tier => limits (always complete, always valid) */
    public static function config(?Database $db = null): array
    {
        $cfg = self::defaults();
        try {
            $stored = json_decode(self::read(self::SETTING, $db), true);
        } catch (Throwable $e) {
            $stored = null;
        }
        if (is_array($stored)) {
            foreach (self::TIERS as $tier) {
                foreach (self::NUMBER_KEYS as $k) {
                    if (isset($stored[$tier][$k]) && is_numeric($stored[$tier][$k])) {
                        $v = (int) $stored[$tier][$k];
                        $cfg[$tier][$k] = ($v >= self::UNLIMITED && $v <= self::MAX_VALUE) ? $v : self::UNLIMITED;
                    }
                }
                foreach (self::BOOL_KEYS as $k) {
                    if (isset($stored[$tier][$k])) {
                        $cfg[$tier][$k] = (bool) $stored[$tier][$k];
                    }
                }
            }
        }
        return $cfg;
    }

    /**
     * @param array $in tier => [key => value]; only the keys that are sent change
     * @return array{config:array,changes:array}
     */
    public static function save(array $in, int $actorId, ?Database $db = null): array
    {
        $db = $db ?: Database::getInstance();
        $before = self::config($db);
        $after = $before;
        foreach ($in as $tier => $vals) {
            if (!in_array($tier, self::TIERS, true) || !is_array($vals)) {
                throw new InvalidArgumentException('Unknown plan: ' . (is_string($tier) ? $tier : '?'));
            }
            foreach ($vals as $k => $v) {
                if (in_array($k, self::NUMBER_KEYS, true)) {
                    if (!is_numeric($v) || (int) $v != $v || (int) $v < self::UNLIMITED || (int) $v > self::MAX_VALUE) {
                        throw new InvalidArgumentException(self::LABELS[$k] . ': use a whole number from 0 up, or -1 for unlimited');
                    }
                    $after[$tier][$k] = (int) $v;
                } elseif (in_array($k, self::BOOL_KEYS, true)) {
                    $after[$tier][$k] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
                } else {
                    throw new InvalidArgumentException('Unknown setting: ' . (is_string($k) ? $k : '?'));
                }
            }
        }
        $changes = [];
        foreach (self::TIERS as $tier) {
            foreach (array_merge(self::NUMBER_KEYS, self::BOOL_KEYS) as $k) {
                if ($before[$tier][$k] !== $after[$tier][$k]) {
                    $changes[] = ['plan' => $tier, 'key' => $k, 'from' => $before[$tier][$k], 'to' => $after[$tier][$k]];
                }
            }
        }
        if ($changes) {
            self::write(self::SETTING, json_encode($after), $db);
            self::recordAudit($changes, $actorId, $db);
        }
        return ['config' => $after, 'changes' => $changes];
    }

    /** Append one entry (who, when, old -> new) to the shared admin change history. */
    public static function recordAudit(array $changes, int $actorId, ?Database $db = null): void
    {
        $log = self::auditLog(self::AUDIT_KEEP, $db);
        array_unshift($log, ['at' => gmdate('Y-m-d H:i:s'), 'actor_id' => $actorId, 'changes' => $changes]);
        self::write(self::AUDIT, json_encode(array_slice($log, 0, self::AUDIT_KEEP)), $db);
    }

    /** @return array<int,array<string,int|bool>> account owner id => only the keys that differ from the plan */
    public static function overrides(?Database $db = null): array
    {
        try {
            $stored = json_decode(self::read(self::OVERRIDES, $db), true);
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        if (is_array($stored)) {
            foreach ($stored as $id => $vals) {
                if (!is_array($vals) || (int) $id <= 0) {
                    continue;
                }
                foreach ($vals as $k => $v) {
                    if (in_array($k, self::NUMBER_KEYS, true) && is_numeric($v) && (int) $v >= self::UNLIMITED && (int) $v <= self::MAX_VALUE) {
                        $out[(int) $id][$k] = (int) $v;
                    } elseif (in_array($k, self::BOOL_KEYS, true) && $v !== null) {
                        $out[(int) $id][$k] = (bool) $v;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Exception for one customer account: its own limits instead of the plan's, for the keys given (null removes a key).
     * @param array<string,mixed> $values key => number|bool|null
     * @return array{changes:array,overrides:array}
     * @throws InvalidArgumentException
     */
    public static function saveOverride(int $targetUserId, array $values, int $actorId, ?Database $db = null): array
    {
        $db = $db ?: Database::getInstance();
        $user = $db->query('SELECT id, email, owner_user_id FROM users WHERE id = ? LIMIT 1', [$targetUserId])[0] ?? null;
        if (!$user) {
            throw new InvalidArgumentException('Customer not found');
        }
        if (!empty($user['owner_user_id'])) {
            throw new InvalidArgumentException('This is a team member: set the exception on the account owner (' . (int) $user['owner_user_id'] . ')');
        }
        $all = self::overrides($db);
        $before = $all[$targetUserId] ?? [];
        $after = $before;
        foreach ($values as $k => $v) {
            if (!in_array($k, array_merge(self::NUMBER_KEYS, self::BOOL_KEYS), true)) {
                throw new InvalidArgumentException('Unknown setting');
            }
            if ($v === null || $v === '') {
                unset($after[$k]);
            } elseif (in_array($k, self::NUMBER_KEYS, true)) {
                if (!is_numeric($v) || (int) $v != $v || (int) $v < self::UNLIMITED || (int) $v > self::MAX_VALUE) {
                    throw new InvalidArgumentException(self::LABELS[$k] . ': use a whole number from 0 up, or -1 for unlimited');
                }
                $after[$k] = (int) $v;
            } else {
                $after[$k] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
            }
        }
        $changes = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $k) {
            if (($before[$k] ?? null) !== ($after[$k] ?? null)) {
                $changes[] = ['plan' => 'customer:' . $user['email'], 'key' => $k, 'from' => $before[$k] ?? 'plan', 'to' => $after[$k] ?? 'plan'];
            }
        }
        if ($changes) {
            if ($after) {
                if (!isset($all[$targetUserId]) && count($all) >= self::MAX_OVERRIDES) {
                    throw new InvalidArgumentException('Too many customer exceptions (' . self::MAX_OVERRIDES . '): remove some first');
                }
                $all[$targetUserId] = $after;
            } else {
                unset($all[$targetUserId]);
            }
            self::write(self::OVERRIDES, json_encode($all), $db);
            self::recordAudit($changes, $actorId, $db);
        }
        return ['changes' => $changes, 'overrides' => $all];
    }

    /** For the admin screen: exceptions with the customer's email and plan. */
    public static function overridesView(?Database $db = null): array
    {
        $db = $db ?: Database::getInstance();
        $all = self::overrides($db);
        if (!$all) {
            return [];
        }
        $out = [];
        foreach ($db->query('SELECT id, email, COALESCE(NULLIF(company_name, \'\'), email) AS name FROM users WHERE id IN (' . implode(',', array_map('intval', array_keys($all))) . ')') as $u) {
            $out[] = ['user_id' => (int) $u['id'], 'email' => $u['email'], 'name' => $u['name'], 'plan' => self::tierFor((int) $u['id'], $db), 'values' => $all[(int) $u['id']]];
        }
        usort($out, static fn ($a, $b) => strcmp($a['email'], $b['email']));
        return $out;
    }

    public static function auditLog(int $limit = 50, ?Database $db = null): array
    {
        try {
            $rows = json_decode(self::read(self::AUDIT, $db), true);
        } catch (Throwable $e) {
            return [];
        }
        return is_array($rows) ? array_slice($rows, 0, max(1, $limit)) : [];
    }

    private static function accountOwner(int $userId, ?Database $db = null): int
    {
        $db = $db ?: Database::getInstance();
        $r = $db->query('SELECT owner_user_id FROM users WHERE id = ? LIMIT 1', [$userId]);
        return (int) ($r[0]['owner_user_id'] ?? 0) ?: $userId;
    }

    public static function tierFor(int $userId, ?Database $db = null): string
    {
        $owner = self::accountOwner($userId, $db);
        try {
            $sub = Subscription::activeSubscriptionRow($owner);
        } catch (Throwable $e) {
            $sub = null;
        }
        $plan = (string) ($sub['plan_name'] ?? '');
        return in_array($plan, ['starter', 'professional', 'enterprise'], true) ? $plan : 'free';
    }

    /** @return int|bool effective value for this customer (per-customer override first, then the plan's setting) */
    public static function limit(int $userId, string $key, ?Database $db = null)
    {
        $owner = self::accountOwner($userId, $db);
        $own = self::overrides($db)[$owner][$key] ?? null;
        if ($own !== null) {
            return in_array($key, self::BOOL_KEYS, true) ? (bool) $own : (int) $own;
        }
        try {
            $override = FeatureResolver::value($owner, 'reputation_' . $key);
        } catch (Throwable $e) {
            $override = null;
        }
        if ($override !== null && $override !== '') {
            return in_array($key, self::BOOL_KEYS, true) ? (bool) $override : (int) $override;
        }
        return self::config($db)[self::tierFor($owner, $db)][$key];
    }

    public static function usage(int $userId, ?Database $db = null): array
    {
        $db = $db ?: Database::getInstance();
        $owner = self::accountOwner($userId, $db);
        $req = (int) ($db->query("SELECT COUNT(*) c FROM review_requests WHERE user_id = ? AND status <> 'failed' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')", [$owner])[0]['c'] ?? 0);
        $loc = (int) ($db->query("SELECT COUNT(*) c FROM platform_connections WHERE user_id = ? AND platform = 'google_business' AND status = 'connected'", [$owner])[0]['c'] ?? 0);
        $sms = (int) ($db->query("SELECT COUNT(*) c FROM review_requests WHERE user_id = ? AND channel = 'sms' AND status <> 'failed' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')", [$owner])[0]['c'] ?? 0);
        return ['review_requests_monthly' => $req, 'sms_requests_monthly' => $sms, 'locations' => $loc];
    }

    public static function summary(int $userId, ?Database $db = null): array
    {
        $u = self::usage($userId, $db);
        $out = ['plan' => self::tierFor($userId, $db)];
        foreach (self::NUMBER_KEYS as $k) {
            $out[$k] = ['used' => $u[$k], 'limit' => self::limit($userId, $k, $db)];
        }
        foreach (self::BOOL_KEYS as $k) {
            $out[$k] = self::limit($userId, $k, $db);
        }
        return $out;
    }

    private static function hint(string $plan): string
    {
        return $plan === 'enterprise' ? ' Contact support to raise it.' : ' Upgrade your plan to raise it.';
    }

    /** @throws ReputationLimitReached */
    public static function assertCanCreateRequest(int $userId, ?Database $db = null): void
    {
        $limit = (int) self::limit($userId, 'review_requests_monthly', $db);
        if ($limit === self::UNLIMITED) {
            return;
        }
        $used = self::usage($userId, $db)['review_requests_monthly'];
        if ($used >= $limit) {
            throw new ReputationLimitReached('You reached your plan limit of ' . $limit . ' review requests this month (' . $used . ' used).' . self::hint(self::tierFor($userId, $db)), 'review_requests_monthly');
        }
    }

    /** @throws ReputationLimitReached SMS costs money per message, so it has its own monthly cap */
    public static function assertCanSendSms(int $userId, ?Database $db = null): void
    {
        $limit = (int) self::limit($userId, 'sms_requests_monthly', $db);
        if ($limit === self::UNLIMITED) {
            return;
        }
        if ($limit === 0) {
            throw new ReputationLimitReached('SMS review requests are not included in your plan. Use WhatsApp or email, or contact support to enable SMS.', 'sms_requests_monthly');
        }
        $used = self::usage($userId, $db)['sms_requests_monthly'];
        if ($used >= $limit) {
            throw new ReputationLimitReached('You reached your plan limit of ' . $limit . ' SMS review requests this month (' . $used . ' used).' . self::hint(self::tierFor($userId, $db)), 'sms_requests_monthly');
        }
    }

    /** @throws ReputationLimitReached a website that already has a Google connection can always be reconnected */
    public static function assertCanConnectLocation(int $userId, int $websiteId, ?Database $db = null): void
    {
        $limit = (int) self::limit($userId, 'locations', $db);
        if ($limit === self::UNLIMITED) {
            return;
        }
        $db = $db ?: Database::getInstance();
        if ($db->query("SELECT 1 FROM platform_connections WHERE website_id = ? AND platform = 'google_business' LIMIT 1", [$websiteId])) {
            return;
        }
        $used = self::usage($userId, $db)['locations'];
        if ($used >= $limit) {
            throw new ReputationLimitReached('Your plan includes ' . $limit . ' connected Google location' . ($limit === 1 ? '' : 's') . ' (' . $used . ' connected).' . self::hint(self::tierFor($userId, $db)), 'locations');
        }
    }

    /** @throws ReputationLimitReached */
    public static function assertFeature(int $userId, string $key, ?Database $db = null): void
    {
        if (!in_array($key, self::BOOL_KEYS, true)) {
            throw new InvalidArgumentException('Unknown feature');
        }
        if (!self::limit($userId, $key, $db)) {
            throw new ReputationLimitReached(self::LABELS[$key] . ' is not included in your plan.' . self::hint(self::tierFor($userId, $db)), $key);
        }
    }

    /**
     * Tell the owner once per month and metric when use reaches 80% and 100% of a limit (in-app notification).
     * Never throws: a failed alert must not affect the request that triggered it.
     * @return array<int,string> the alerts sent, e.g. ['review_requests_monthly:80']
     */
    public static function maybeAlert(int $userId, ?Database $db = null): array
    {
        $sent = [];
        try {
            $db = $db ?: Database::getInstance();
            $owner = self::accountOwner($userId, $db);
            $usage = self::usage($owner, $db);
            $month = gmdate('Y-m');
            foreach (['review_requests_monthly' => 'review requests', 'sms_requests_monthly' => 'SMS review requests'] as $key => $label) {
                $limit = (int) self::limit($owner, $key, $db);
                if ($limit <= 0) {
                    continue; // unlimited or not allowed: nothing to warn about
                }
                $pct = (int) floor($usage[$key] / $limit * 100);
                foreach ([100, 80] as $step) {
                    if ($pct < $step) {
                        continue;
                    }
                    $flag = 'rep_limit_alert_' . $owner . '_' . $month . '_' . $key . '_' . $step;
                    if ($db->query('SELECT 1 FROM system_settings WHERE setting_key = ? LIMIT 1', [$flag])) {
                        break; // this step (and every lower one) was already reported this month
                    }
                    $db->exec("INSERT INTO system_settings (setting_key, setting_value, is_secret, category) VALUES (?, ?, 0, 'reputation')", [mb_substr($flag, 0, 80), gmdate('Y-m-d H:i:s')]);
                    if (class_exists('Notification')) {
                        Notification::notify($owner, 'reputation_limit', $step >= 100 ? 'You reached your ' . $label . ' limit' : 'You used 80% of your ' . $label . ' limit', $usage[$key] . ' of ' . $limit . ' this month.' . self::hint(self::tierFor($owner, $db)), '/review-requests');
                    }
                    $sent[] = $key . ':' . $step;
                    break; // one alert per metric per call: the highest step reached
                }
            }
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::warning('Reputation limit alert skipped', ['message' => $e->getMessage()]);
            }
        }
        return $sent;
    }

    /** Admin: this month's review requests and real AI cost per account (any account with either). */
    public static function usageReport(int $limit = 30, ?Database $db = null): array
    {
        $db = $db ?: Database::getInstance();
        $limit = max(1, min(100, $limit));
        $byUser = [];
        foreach ($db->query("SELECT user_id, COUNT(*) AS requests FROM review_requests WHERE status <> 'failed' AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01') GROUP BY user_id ORDER BY requests DESC LIMIT " . $limit) as $r) {
            $byUser[(int) $r['user_id']] = ['requests' => (int) $r['requests'], 'ai_calls' => 0, 'ai_cost' => 0.0];
        }
        try {
            foreach ($db->query("SELECT user_id, COUNT(*) AS calls, ROUND(SUM(cost_in_usd), 4) AS cost FROM api_usage_logs WHERE user_id IS NOT NULL AND feature IN ('review_reply','sentiment','review_content') AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01') GROUP BY user_id ORDER BY cost DESC LIMIT " . $limit) as $a) {
                $id = (int) $a['user_id'];
                $byUser[$id] = ($byUser[$id] ?? ['requests' => 0]) + ['ai_calls' => 0, 'ai_cost' => 0.0];
                $byUser[$id]['ai_calls'] = (int) $a['calls'];
                $byUser[$id]['ai_cost'] = (float) $a['cost'];
            }
        } catch (Throwable $e) {
            // the AI log is optional: the request counts still show
        }
        if (!$byUser) {
            return [];
        }
        $names = [];
        foreach ($db->query('SELECT id, email, COALESCE(NULLIF(company_name, \'\'), email) AS name FROM users WHERE id IN (' . implode(',', array_keys($byUser)) . ')') as $u) {
            $names[(int) $u['id']] = ['email' => $u['email'], 'name' => $u['name']];
        }
        $rows = [];
        foreach ($byUser as $id => $v) {
            $rows[] = [
                'user_id' => $id, 'email' => $names[$id]['email'] ?? '', 'name' => $names[$id]['name'] ?? ('#' . $id),
                'requests' => $v['requests'], 'ai_calls' => $v['ai_calls'], 'ai_cost' => $v['ai_cost'],
                'plan' => self::tierFor($id, $db), 'limit' => (int) self::limit($id, 'review_requests_monthly', $db),
            ];
        }
        usort($rows, static fn ($x, $y) => [$y['requests'], $y['ai_cost']] <=> [$x['requests'], $x['ai_cost']]);
        return array_slice($rows, 0, $limit);
    }
}

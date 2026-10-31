<?php

/**
 * Reputation: stand-alone plans bought from the wallet, decided entirely by the admin.
 *
 * The admin turns selling on or off (master switch), and for every plan sets its name, monthly and yearly price,
 * whether it is for sale, and its limits (same limits as Admin > Reputation limits). Everything is OFF by default.
 * It reuses the platform wallet (WalletService::chargeAmount, with an idempotency key) and the limits engine, so no second
 * billing system exists and usage is counted once: a customer who has both a platform plan and a stand-alone plan gets,
 * for every limit, the more generous of the two.
 *
 * Policy for existing subscribers (shown to the admin): stopping a plan's sale never cancels subscribers; a price change
 * applies at the next renewal and never to the period already paid; a failed renewal (not enough balance) ends the
 * plan, it is not retried in a loop. AI replies are not part of these plans: they keep using the platform's review
 * credits or wallet usage prices as before.
 * Needs migration 2026_10_31_000004_reputation_subscriptions.sql.
 */
class ReputationPlans
{
    public const KEYS = ['rep_starter', 'rep_pro', 'rep_agency'];
    private const SETTING = 'reputation_plans';
    private static ?bool $ready = null;

    public static function defaults(): array
    {
        $mk = static fn (string $name, float $m, float $y, array $l) => ['name' => $name, 'price_monthly' => $m, 'price_yearly' => $y, 'for_sale' => false, 'limits' => $l];
        return [
            'enabled' => false,
            'plans' => [
                'rep_starter' => $mk('Starter', 15.0, 150.0, ['review_requests_monthly' => 100, 'sms_requests_monthly' => 0, 'locations' => 1, 'team_assignment' => false, 'csv_import' => true]),
                'rep_pro' => $mk('Pro', 39.0, 390.0, ['review_requests_monthly' => 500, 'sms_requests_monthly' => 0, 'locations' => 3, 'team_assignment' => true, 'csv_import' => true]),
                'rep_agency' => $mk('Agency', 99.0, 990.0, ['review_requests_monthly' => 2000, 'sms_requests_monthly' => 0, 'locations' => 10, 'team_assignment' => true, 'csv_import' => true]),
            ],
        ];
    }

    public static function ready(?Database $db = null): bool
    {
        if (self::$ready !== null) {
            return self::$ready;
        }
        try {
            $db = $db ?: Database::getInstance();
            return self::$ready = (bool) $db->query("SHOW TABLES LIKE 'reputation_subscriptions'");
        } catch (Throwable $e) {
            return self::$ready = false;
        }
    }

    public static function flush(): void
    {
        self::$ready = null;
    }

    private static function sanitizeLimits($in, array $base): array
    {
        $out = $base;
        if (!is_array($in)) {
            return $out;
        }
        foreach (ReputationLimits::NUMBER_KEYS as $k) {
            if (isset($in[$k]) && is_numeric($in[$k]) && (int) $in[$k] >= -1 && (int) $in[$k] <= ReputationLimits::MAX_VALUE) {
                $out[$k] = (int) $in[$k];
            }
        }
        foreach (ReputationLimits::BOOL_KEYS as $k) {
            if (isset($in[$k])) {
                $out[$k] = (bool) $in[$k];
            }
        }
        return $out;
    }

    public static function config(?Database $db = null): array
    {
        $cfg = self::defaults();
        try {
            $db = $db ?: Database::getInstance();
            $r = $db->query('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1', [self::SETTING]);
            $stored = json_decode((string) ($r[0]['setting_value'] ?? ''), true);
        } catch (Throwable $e) {
            $stored = null;
        }
        if (is_array($stored)) {
            $cfg['enabled'] = !empty($stored['enabled']);
            foreach (self::KEYS as $k) {
                $p = $stored['plans'][$k] ?? null;
                if (!is_array($p)) {
                    continue;
                }
                if (isset($p['name']) && is_string($p['name']) && trim($p['name']) !== '') {
                    $cfg['plans'][$k]['name'] = mb_substr(trim($p['name']), 0, 40);
                }
                foreach (['price_monthly', 'price_yearly'] as $f) {
                    if (isset($p[$f]) && is_numeric($p[$f]) && $p[$f] >= 0 && $p[$f] <= 100000) {
                        $cfg['plans'][$k][$f] = round((float) $p[$f], 2);
                    }
                }
                $cfg['plans'][$k]['for_sale'] = !empty($p['for_sale']);
                $cfg['plans'][$k]['limits'] = self::sanitizeLimits($p['limits'] ?? null, $cfg['plans'][$k]['limits']);
            }
        }
        return $cfg;
    }

    /** @return array{config:array,changes:array} */
    public static function save(array $in, int $actorId, ?Database $db = null): array
    {
        $db = $db ?: Database::getInstance();
        $before = self::config($db);
        $after = $before;
        if (array_key_exists('enabled', $in)) {
            $after['enabled'] = filter_var($in['enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        foreach ((array) ($in['plans'] ?? []) as $key => $p) {
            if (!in_array($key, self::KEYS, true) || !is_array($p)) {
                throw new InvalidArgumentException('Unknown plan');
            }
            if (array_key_exists('name', $p)) {
                $name = trim((string) $p['name']);
                if ($name === '' || mb_strlen($name) > 40) {
                    throw new InvalidArgumentException('Plan name: 1 to 40 characters');
                }
                $after['plans'][$key]['name'] = $name;
            }
            foreach (['price_monthly', 'price_yearly'] as $f) {
                if (array_key_exists($f, $p)) {
                    if (!is_numeric($p[$f]) || $p[$f] < 0 || $p[$f] > 100000) {
                        throw new InvalidArgumentException('Price must be a number from 0 to 100000 (USD)');
                    }
                    $after['plans'][$key][$f] = round((float) $p[$f], 2);
                }
            }
            if (array_key_exists('for_sale', $p)) {
                $after['plans'][$key]['for_sale'] = filter_var($p['for_sale'], FILTER_VALIDATE_BOOLEAN);
            }
            foreach ((array) ($p['limits'] ?? []) as $lk => $lv) {
                if (in_array($lk, ReputationLimits::NUMBER_KEYS, true)) {
                    if (!is_numeric($lv) || (int) $lv != $lv || (int) $lv < -1 || (int) $lv > ReputationLimits::MAX_VALUE) {
                        throw new InvalidArgumentException(ReputationLimits::LABELS[$lk] . ': use a whole number from 0 up, or -1 for unlimited');
                    }
                    $after['plans'][$key]['limits'][$lk] = (int) $lv;
                } elseif (in_array($lk, ReputationLimits::BOOL_KEYS, true)) {
                    $after['plans'][$key]['limits'][$lk] = filter_var($lv, FILTER_VALIDATE_BOOLEAN);
                } else {
                    throw new InvalidArgumentException('Unknown limit');
                }
            }
        }
        $changes = [];
        if ($before['enabled'] !== $after['enabled']) {
            $changes[] = ['plan' => 'standalone', 'key' => 'selling', 'from' => $before['enabled'], 'to' => $after['enabled']];
        }
        foreach (self::KEYS as $k) {
            foreach (['name', 'price_monthly', 'price_yearly', 'for_sale'] as $f) {
                if ($before['plans'][$k][$f] !== $after['plans'][$k][$f]) {
                    $changes[] = ['plan' => 'standalone:' . $k, 'key' => $f, 'from' => $before['plans'][$k][$f], 'to' => $after['plans'][$k][$f]];
                }
            }
            foreach ($after['plans'][$k]['limits'] as $lk => $lv) {
                if ($before['plans'][$k]['limits'][$lk] !== $lv) {
                    $changes[] = ['plan' => 'standalone:' . $k, 'key' => $lk, 'from' => $before['plans'][$k]['limits'][$lk], 'to' => $lv];
                }
            }
        }
        if ($changes) {
            $db->exec("INSERT INTO system_settings (setting_key, setting_value, is_secret, category) VALUES (?, ?, 0, 'reputation') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [self::SETTING, json_encode($after)]);
            ReputationLimits::recordAudit($changes, $actorId, $db);
        }
        return ['config' => $after, 'changes' => $changes];
    }

    private static function owner(int $userId, ?Database $db = null): int
    {
        $db = $db ?: Database::getInstance();
        return (int) ($db->query('SELECT owner_user_id FROM users WHERE id = ? LIMIT 1', [$userId])[0]['owner_user_id'] ?? 0) ?: $userId;
    }

    public static function periodEnd(int $from, string $cycle): int
    {
        return $cycle === 'yearly' ? strtotime('+1 year', $from) : strtotime('+1 month', $from);
    }

    /** The active stand-alone subscription of the account (row), or null. */
    public static function active(int $userId, ?Database $db = null): ?array
    {
        if (!self::ready($db)) {
            return null;
        }
        $db = $db ?: Database::getInstance();
        $row = $db->query("SELECT * FROM reputation_subscriptions WHERE user_id = ? AND status = 'active' AND period_end > UTC_TIMESTAMP() LIMIT 1", [self::owner($userId, $db)])[0] ?? null;
        return $row ?: null;
    }

    /** Limits granted by the active stand-alone plan, or null when the account has none. */
    public static function limitsFor(int $userId, ?Database $db = null): ?array
    {
        $row = self::active($userId, $db);
        if (!$row) {
            return null;
        }
        return self::config($db)['plans'][$row['plan_key']]['limits'] ?? null;
    }

    /**
     * Buy a plan from the wallet. With no active plan: charged now and active at once. With an active plan: the change is
     * scheduled for the next renewal (nothing is charged now).
     * @return array{action:string,plan:string,cycle:string,charged:float,period_end:string}
     * @throws InvalidArgumentException invalid request; DomainException(json) not enough balance
     */
    public static function subscribe(int $userId, string $planKey, string $cycle, ?string $requestKey = null, ?Database $db = null): array
    {
        $db = $db ?: Database::getInstance();
        if (!self::ready($db)) {
            throw new InvalidArgumentException('Plans are not available yet: a database update is pending');
        }
        $cfg = self::config($db);
        if (!$cfg['enabled']) {
            throw new InvalidArgumentException('Stand-alone plans are not on sale right now');
        }
        if (!in_array($planKey, self::KEYS, true) || empty($cfg['plans'][$planKey]['for_sale'])) {
            throw new InvalidArgumentException('This plan is not on sale');
        }
        if (!in_array($cycle, ['monthly', 'yearly'], true)) {
            throw new InvalidArgumentException('Choose monthly or yearly');
        }
        $owner = self::owner($userId, $db);
        if ($owner !== $userId) {
            throw new InvalidArgumentException('Only the account owner can buy a plan');
        }
        $price = (float) $cfg['plans'][$planKey]['price_' . $cycle];
        $ref = 'rep_plan:' . $owner . ':' . substr(hash('sha256', $requestKey ?: bin2hex(random_bytes(8))), 0, 32);
        return $db->transaction(function () use ($db, $owner, $planKey, $cycle, $price, $ref, $cfg) {
            $db->query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$owner]);
            $row = $db->query('SELECT * FROM reputation_subscriptions WHERE user_id = ? LIMIT 1 FOR UPDATE', [$owner])[0] ?? null;
            if ($row && $row['last_ref'] === $ref) {
                return ['action' => 'already', 'plan' => $row['plan_key'], 'cycle' => $row['cycle'], 'charged' => 0.0, 'period_end' => $row['period_end']];
            }
            $now = time();
            $active = $row && $row['status'] === 'active' && strtotime($row['period_end'] . ' UTC') > $now;
            if ($active) {
                if ($row['plan_key'] === $planKey && $row['cycle'] === $cycle && (int) $row['auto_renew'] === 1 && !$row['pending_plan']) {
                    throw new InvalidArgumentException('You are already subscribed to this plan');
                }
                $same = $row['plan_key'] === $planKey && $row['cycle'] === $cycle;
                $db->query('UPDATE reputation_subscriptions SET pending_plan = ?, pending_cycle = ?, auto_renew = 1, last_ref = ? WHERE id = ?', [$same ? null : $planKey, $same ? null : $cycle, $ref, (int) $row['id']]);
                return ['action' => $same ? 'resumed' : 'scheduled', 'plan' => $planKey, 'cycle' => $cycle, 'charged' => 0.0, 'period_end' => $row['period_end']];
            }
            if ($price > 0) {
                $wallet = new WalletService();
                if (!$wallet->chargeAmount($owner, $price, 'reputation_plan_' . $planKey, 'Reputation plan "' . $cfg['plans'][$planKey]['name'] . '" (' . $cycle . ')', $ref)) {
                    $bal = $wallet->getBalance($owner);
                    throw new DomainException(json_encode(['balance' => $bal, 'required' => $price, 'shortfall' => round(max(0, $price - $bal), 2)]));
                }
            }
            $from = gmdate('Y-m-d H:i:s', $now);
            $to = gmdate('Y-m-d H:i:s', self::periodEnd($now, $cycle));
            if ($row) {
                $db->query("UPDATE reputation_subscriptions SET plan_key = ?, cycle = ?, price_paid = ?, status = 'active', auto_renew = 1, pending_plan = NULL, pending_cycle = NULL, period_start = ?, period_end = ?, last_ref = ? WHERE id = ?", [$planKey, $cycle, $price, $from, $to, $ref, (int) $row['id']]);
            } else {
                $db->query("INSERT INTO reputation_subscriptions (user_id, plan_key, cycle, price_paid, status, auto_renew, period_start, period_end, last_ref) VALUES (?, ?, ?, ?, 'active', 1, ?, ?, ?)", [$owner, $planKey, $cycle, $price, $from, $to, $ref]);
            }
            return ['action' => 'subscribed', 'plan' => $planKey, 'cycle' => $cycle, 'charged' => $price, 'period_end' => $to];
        });
    }

    public static function setAutoRenew(int $userId, bool $on, ?Database $db = null): bool
    {
        $db = $db ?: Database::getInstance();
        if (!self::ready($db)) {
            return false;
        }
        $owner = self::owner($userId, $db);
        $db->exec("UPDATE reputation_subscriptions SET auto_renew = ?, pending_plan = " . ($on ? 'pending_plan' : 'NULL') . ", pending_cycle = " . ($on ? 'pending_cycle' : 'NULL') . " WHERE user_id = ? AND status = 'active'", [$on ? 1 : 0, $owner]);
        return true;
    }

    /**
     * Renew every active subscription whose period ended. One wallet charge per period (idempotency key), the price in
     * force today, a pending plan change applied. Not enough balance ends the plan (no retry loop).
     * @return int renewals done
     */
    public static function renewDue(int $limit = 200, ?Database $db = null): int
    {
        $db = $db ?: Database::getInstance();
        if (!self::ready($db)) {
            return 0;
        }
        $cfg = self::config($db);
        $due = $db->query("SELECT user_id FROM reputation_subscriptions WHERE status = 'active' AND period_end <= UTC_TIMESTAMP() ORDER BY period_end ASC LIMIT " . max(1, min(1000, $limit)));
        $done = 0;
        foreach ($due as $d) {
            try {
                $res = $db->transaction(function () use ($db, $d, $cfg) {
                    $db->query('SELECT id FROM users WHERE id = ? FOR UPDATE', [(int) $d['user_id']]);
                    $row = $db->query("SELECT * FROM reputation_subscriptions WHERE user_id = ? AND status = 'active' AND period_end <= UTC_TIMESTAMP() LIMIT 1 FOR UPDATE", [(int) $d['user_id']])[0] ?? null;
                    if (!$row) {
                        return null;
                    }
                    if (!(int) $row['auto_renew']) {
                        $db->query("UPDATE reputation_subscriptions SET status = 'expired' WHERE id = ?", [(int) $row['id']]);
                        return ['expired', (int) $row['user_id'], 'You chose not to renew your reputation plan, so it ended.'];
                    }
                    $plan = $row['pending_plan'] ?: $row['plan_key'];
                    $cycle = $row['pending_cycle'] ?: $row['cycle'];
                    if (!isset($cfg['plans'][$plan])) {
                        $db->query("UPDATE reputation_subscriptions SET status = 'expired' WHERE id = ?", [(int) $row['id']]);
                        return ['expired', (int) $row['user_id'], 'Your reputation plan is no longer available, so it ended.'];
                    }
                    $price = (float) $cfg['plans'][$plan]['price_' . $cycle];
                    $periodEndTs = strtotime($row['period_end'] . ' UTC');
                    $ref = 'rep_plan_renew:' . $row['user_id'] . ':' . $periodEndTs;
                    if ($price > 0 && !(new WalletService())->chargeAmount((int) $row['user_id'], $price, 'reputation_plan_' . $plan, 'Renewal of reputation plan "' . $cfg['plans'][$plan]['name'] . '" (' . $cycle . ')', $ref)) {
                        $db->query("UPDATE reputation_subscriptions SET status = 'expired' WHERE id = ?", [(int) $row['id']]);
                        return ['expired', (int) $row['user_id'], 'Your reputation plan ended: the wallet balance was not enough for the renewal ($' . number_format($price, 2) . '). Add balance and subscribe again.'];
                    }
                    $from = gmdate('Y-m-d H:i:s', $periodEndTs);
                    $to = gmdate('Y-m-d H:i:s', self::periodEnd($periodEndTs, $cycle));
                    $db->query("UPDATE reputation_subscriptions SET plan_key = ?, cycle = ?, price_paid = ?, pending_plan = NULL, pending_cycle = NULL, period_start = ?, period_end = ?, last_ref = ? WHERE id = ?", [$plan, $cycle, $price, $from, $to, $ref, (int) $row['id']]);
                    return ['renewed', (int) $row['user_id'], 'Your reputation plan was renewed for $' . number_format($price, 2) . '.'];
                });
            } catch (Throwable $e) {
                if (class_exists('Logger')) {
                    Logger::warning('Reputation plan renewal failed', ['user_id' => (int) $d['user_id'], 'message' => $e->getMessage()]);
                }
                continue;
            }
            if ($res) {
                $done += $res[0] === 'renewed' ? 1 : 0;
                if (class_exists('Notification')) {
                    try {
                        Notification::notify($res[1], 'reputation_plan', $res[0] === 'renewed' ? 'Reputation plan renewed' : 'Reputation plan ended', $res[2], '/reputation/plans');
                    } catch (Throwable $e) {
                    }
                }
            }
        }
        return $done;
    }

    /** Admin: plans with subscriber counts and recurring revenue (monthly equivalent of what customers paid). */
    public static function adminView(?Database $db = null): array
    {
        $db = $db ?: Database::getInstance();
        $cfg = self::config($db);
        $stats = [];
        if (self::ready($db)) {
            foreach ($db->query("SELECT plan_key, COUNT(*) AS subs, SUM(CASE WHEN cycle = 'yearly' THEN price_paid / 12 ELSE price_paid END) AS mrr FROM reputation_subscriptions WHERE status = 'active' AND period_end > UTC_TIMESTAMP() GROUP BY plan_key") as $r) {
                $stats[$r['plan_key']] = ['subs' => (int) $r['subs'], 'mrr' => round((float) $r['mrr'], 2)];
            }
        }
        $plans = [];
        foreach (self::KEYS as $k) {
            $plans[] = ['key' => $k] + $cfg['plans'][$k] + ($stats[$k] ?? ['subs' => 0, 'mrr' => 0.0]);
        }
        return ['ready' => self::ready($db), 'enabled' => $cfg['enabled'], 'plans' => $plans];
    }
}

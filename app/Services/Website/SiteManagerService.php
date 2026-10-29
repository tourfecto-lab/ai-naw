<?php

/**
 * Per-site manager accounts: a username + password that opens ONE generated website's dashboard (see SiteManagerController).
 * The account is separate from Tourfecto users: it never gets $_SESSION['user'], so nothing outside /site-admin accepts it.
 * Passwords are hashed with password_hash; failed logins lock the account for a while; an unknown username costs the same
 * time as a wrong password so usernames cannot be probed.
 */
class SiteManagerService
{
    public const ROLES = ['manager', 'editor', 'viewer'];
    public const MAX_FAILED = 5;
    public const LOCK_MINUTES = 15;
    public const MAX_PER_SITE = 10;
    public const MIN_PASSWORD = 10;

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?: Database::getInstance();
    }

    /** @return string[] problems; empty when the password is acceptable */
    public static function passwordProblems(string $password, string $username = ''): array
    {
        $out = [];
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            $out[] = 'كلمة السر لازم تكون ' . self::MIN_PASSWORD . ' حروف على الأقل';
        }
        if (mb_strlen($password) > 100) {
            $out[] = 'كلمة السر طويلة جدًا';
        }
        if ($username !== '' && mb_strtolower($password) === mb_strtolower($username)) {
            $out[] = 'كلمة السر ما تكونش نفس اسم المستخدم';
        }
        if (!preg_match('/\pL/u', $password) || !preg_match('/\d/', $password)) {
            $out[] = 'كلمة السر لازم فيها حروف وأرقام';
        }
        return $out;
    }

    public static function generatePassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $p = '';
        for ($i = 0; $i < 14; $i++) {
            $p .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $p . random_int(10, 99);
    }

    private static function dummyHash(): string
    {
        static $h = null;
        return $h ??= password_hash('not-a-real-password', PASSWORD_DEFAULT);
    }

    public static function validUsername(string $u): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9._-]{3,59}$/D', $u);
    }

    private function ownsSite(int $ownerId, int $websiteId): bool
    {
        return (bool) $this->db->query('SELECT id FROM generated_websites WHERE id = ? AND user_id = ? LIMIT 1', [$websiteId, $ownerId]);
    }

    /** @return array{ok:bool,error?:string,id?:int} */
    public function create(int $ownerId, int $websiteId, string $username, string $displayName, string $role, string $password): array
    {
        $username = strtolower(trim($username));
        if (!$this->ownsSite($ownerId, $websiteId)) {
            return ['ok' => false, 'error' => 'الموقع مش موجود'];
        }
        if (!self::validUsername($username)) {
            return ['ok' => false, 'error' => 'اسم المستخدم 4-60 حرف إنجليزي صغير أو أرقام أو . _ -'];
        }
        if (!in_array($role, self::ROLES, true)) {
            return ['ok' => false, 'error' => 'صلاحية غير صحيحة'];
        }
        if ($problems = self::passwordProblems($password, $username)) {
            return ['ok' => false, 'error' => $problems[0]];
        }
        $count = (int) ($this->db->query('SELECT COUNT(*) AS c FROM site_manager_accounts WHERE website_id = ?', [$websiteId])[0]['c'] ?? 0);
        if ($count >= self::MAX_PER_SITE) {
            return ['ok' => false, 'error' => 'وصلت الحد الأقصى من الحسابات للموقع'];
        }
        if ($this->db->query('SELECT id FROM site_manager_accounts WHERE username = ? LIMIT 1', [$username])) {
            return ['ok' => false, 'error' => 'اسم المستخدم ده مستخدم قبل كده'];
        }
        $this->db->query(
            'INSERT INTO site_manager_accounts (website_id, owner_user_id, username, display_name, password_hash, role) VALUES (?, ?, ?, ?, ?, ?)',
            [$websiteId, $ownerId, $username, mb_substr(trim(strip_tags($displayName)), 0, 120), password_hash($password, PASSWORD_DEFAULT), $role]
        );
        $id = (int) ($this->db->query('SELECT id FROM site_manager_accounts WHERE username = ? LIMIT 1', [$username])[0]['id'] ?? 0);
        return ['ok' => true, 'id' => $id];
    }

    /** @return array[] accounts of a site (never the hash) */
    public function listFor(int $ownerId, int $websiteId): array
    {
        if (!$this->ownsSite($ownerId, $websiteId)) {
            return [];
        }
        return $this->db->query(
            'SELECT id, username, display_name, role, is_active, last_login_at, locked_until, created_at FROM site_manager_accounts WHERE website_id = ? ORDER BY id',
            [$websiteId]
        ) ?: [];
    }

    private function ownedAccount(int $ownerId, int $websiteId, int $accountId): ?array
    {
        $r = $this->db->query('SELECT * FROM site_manager_accounts WHERE id = ? AND website_id = ? AND owner_user_id = ? LIMIT 1', [$accountId, $websiteId, $ownerId]);
        return $r[0] ?? null;
    }

    /** @param array{role?:string,is_active?:mixed,password?:string} $changes */
    public function update(int $ownerId, int $websiteId, int $accountId, array $changes): array
    {
        $acc = $this->ownedAccount($ownerId, $websiteId, $accountId);
        if (!$acc) {
            return ['ok' => false, 'error' => 'الحساب مش موجود'];
        }
        $sets = [];
        $args = [];
        if (isset($changes['role'])) {
            if (!in_array($changes['role'], self::ROLES, true)) {
                return ['ok' => false, 'error' => 'صلاحية غير صحيحة'];
            }
            $sets[] = 'role = ?';
            $args[] = $changes['role'];
        }
        if (array_key_exists('is_active', $changes)) {
            $sets[] = 'is_active = ?';
            $args[] = $changes['is_active'] ? 1 : 0;
        }
        if (!empty($changes['password'])) {
            if ($problems = self::passwordProblems((string) $changes['password'], (string) $acc['username'])) {
                return ['ok' => false, 'error' => $problems[0]];
            }
            $sets[] = 'password_hash = ?';
            $args[] = password_hash((string) $changes['password'], PASSWORD_DEFAULT);
            $sets[] = 'failed_attempts = 0';
            $sets[] = 'locked_until = NULL';
        }
        if (!$sets) {
            return ['ok' => true];
        }
        $args[] = $accountId;
        $this->db->query('UPDATE site_manager_accounts SET ' . implode(', ', $sets) . ' WHERE id = ?', $args);
        return ['ok' => true];
    }

    public function delete(int $ownerId, int $websiteId, int $accountId): bool
    {
        if (!$this->ownedAccount($ownerId, $websiteId, $accountId)) {
            return false;
        }
        $this->db->query('DELETE FROM site_manager_accounts WHERE id = ?', [$accountId]);
        return true;
    }

    /** @return array{ok:bool,error?:string,account?:array} */
    public function authenticate(string $username, string $password, string $ip = ''): array
    {
        $username = strtolower(trim($username));
        $generic = ['ok' => false, 'error' => 'اسم المستخدم أو كلمة السر غلط'];
        $row = ($username !== '' && strlen($username) <= 60)
            ? ($this->db->query(
                'SELECT a.*, w.user_id AS site_owner FROM site_manager_accounts a JOIN generated_websites w ON w.id = a.website_id WHERE a.username = ? LIMIT 1',
                [$username]
            )[0] ?? null)
            : null;
        if (!$row) {
            password_verify($password, self::dummyHash()); // same cost as a real check
            return $generic;
        }
        if (!empty($row['locked_until']) && strtotime((string) $row['locked_until']) > time()) {
            return ['ok' => false, 'error' => 'الحساب مقفول مؤقتًا بسبب محاولات كتير. جرّب بعد شوية'];
        }
        if (!password_verify($password, (string) $row['password_hash'])) {
            $failed = (int) $row['failed_attempts'] + 1;
            if ($failed >= self::MAX_FAILED) {
                $this->db->query('UPDATE site_manager_accounts SET failed_attempts = 0, locked_until = DATE_ADD(NOW(), INTERVAL ' . self::LOCK_MINUTES . ' MINUTE) WHERE id = ?', [(int) $row['id']]);
            } else {
                $this->db->query('UPDATE site_manager_accounts SET failed_attempts = ? WHERE id = ?', [$failed, (int) $row['id']]);
            }
            return $generic;
        }
        // right password but switched off / the site changed hands: say nothing specific
        if ((int) $row['is_active'] !== 1 || (int) $row['site_owner'] !== (int) $row['owner_user_id']) {
            return $generic;
        }
        if (password_needs_rehash((string) $row['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->query('UPDATE site_manager_accounts SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), (int) $row['id']]);
        }
        $this->db->query('UPDATE site_manager_accounts SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW(), last_login_ip = ? WHERE id = ?', [mb_substr($ip, 0, 45), (int) $row['id']]);
        unset($row['password_hash']);
        return ['ok' => true, 'account' => $row];
    }

    /** The live account row for a session: re-read on every request so a switched-off account loses access immediately. */
    public function activeAccount(int $accountId, int $websiteId): ?array
    {
        $r = $this->db->query(
            'SELECT a.id, a.website_id, a.owner_user_id, a.username, a.display_name, a.role FROM site_manager_accounts a
             JOIN generated_websites w ON w.id = a.website_id AND w.user_id = a.owner_user_id
             WHERE a.id = ? AND a.website_id = ? AND a.is_active = 1 LIMIT 1',
            [$accountId, $websiteId]
        );
        return $r[0] ?? null;
    }

    /** Record something a manager did. Never throws: the action itself matters more than its log line. */
    public function log(int $accountId, int $websiteId, string $action, string $detail = ''): void
    {
        try {
            $this->db->query(
                'INSERT INTO site_manager_activity (website_id, account_id, action, detail) VALUES (?, ?, ?, ?)',
                [$websiteId, $accountId, mb_substr($action, 0, 40), mb_substr($detail, 0, 190)]
            );
            if (random_int(1, 100) === 1) {
                $this->db->query('DELETE FROM site_manager_activity WHERE created_at < DATE_SUB(NOW(), INTERVAL 180 DAY)');
            }
        } catch (Throwable $e) {
            // table not applied yet / transient error
        }
    }

    /** @return array[] latest actions of a site (owner view) */
    public function activityFor(int $ownerId, int $websiteId, int $limit = 30): array
    {
        if (!$this->ownsSite($ownerId, $websiteId)) {
            return [];
        }
        return $this->db->query(
            'SELECT a.action, a.detail, a.created_at, m.username FROM site_manager_activity a LEFT JOIN site_manager_accounts m ON m.id = a.account_id
             WHERE a.website_id = ? ORDER BY a.id DESC LIMIT ' . max(1, min(100, $limit)),
            [$websiteId]
        ) ?: [];
    }

    /** The manager changes their own password; the current one must be right (and counts as a failed attempt when it is not). */
    public function changeOwnPassword(int $accountId, int $websiteId, string $current, string $new): array
    {
        $row = $this->db->query('SELECT id, username, password_hash FROM site_manager_accounts WHERE id = ? AND website_id = ? AND is_active = 1 LIMIT 1', [$accountId, $websiteId])[0] ?? null;
        if (!$row) {
            return ['ok' => false, 'error' => 'الحساب مش موجود'];
        }
        if (!password_verify($current, (string) $row['password_hash'])) {
            $this->db->query('UPDATE site_manager_accounts SET failed_attempts = failed_attempts + 1 WHERE id = ?', [$accountId]);
            return ['ok' => false, 'error' => 'كلمة السر الحالية غلط'];
        }
        if ($problems = self::passwordProblems($new, (string) $row['username'])) {
            return ['ok' => false, 'error' => $problems[0]];
        }
        if (hash_equals($current, $new)) {
            return ['ok' => false, 'error' => 'اختار كلمة سر مختلفة عن الحالية'];
        }
        $this->db->query('UPDATE site_manager_accounts SET password_hash = ?, failed_attempts = 0 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $accountId]);
        return ['ok' => true];
    }

    /** What each role may do inside /site-admin */
    public static function can(string $role, string $action): bool
    {
        $matrix = [
            'viewer' => ['view'],
            'editor' => ['view', 'leads', 'reviews'],
            'manager' => ['view', 'leads', 'reviews', 'bookings', 'content'],
        ];
        return in_array($action, $matrix[$role] ?? [], true);
    }
}

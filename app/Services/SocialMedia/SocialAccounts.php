<?php

/**
 * Tourfecto - الحسابات المربوطة في السوشيال: صورة الصفحة واسمها وعدد المتابعين، واختيار الصفحات المستخدمة.
 *
 * - الصورة بتتجاب من Meta (فيسبوك/إنستجرام) وThreads وبتتحدّث كل يوم (روابط صور Meta بتنتهي بعد أيام).
 * - العميل بيختار الصفحات اللي بيشتغل عليها: الصفحة الموقوفة بتختفي من المحرر وما بتتحسبش من حسابات الباقة،
 *   فيقدر يختار مثلًا 3 صفحات من 10 ربطهم من فيسبوك. مفيش حاجة بتتمسح.
 * @version 1.0.0
 */
require_once __DIR__ . '/MetaSocialAPI.php';
require_once __DIR__ . '/ThreadsAPI.php';

final class SocialAccounts
{
    /** @var callable|null fn(string $token): MetaSocialAPI (للاختبارات) */
    public static $apiFactory = null;
    /** @var callable|null fn(string $token, string $userId): ThreadsAPI (للاختبارات) */
    public static $threadsFactory = null;

    private const STALE_HOURS = 20;
    private static ?bool $ready = null;

    public static function ready(): bool
    {
        if (self::$ready) {
            return true;
        }
        try {
            return self::$ready = (bool) Database::getInstance()->query("SHOW TABLES LIKE 'social_account_profiles'");
        } catch (Throwable $e) {
            return self::$ready = false;
        }
    }

    /** نفس migration ‏2026_10_23_000001 (احتياطي - برا أي transaction) */
    public static function ensureSchema(): void
    {
        $pdo = Database::getInstance()->getConnection();
        if (self::ready() || $pdo->inTransaction()) {
            return;
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS social_account_profiles (
                platform_connection_id INT(11) NOT NULL, avatar_url VARCHAR(1000) DEFAULT NULL, username VARCHAR(190) DEFAULT NULL,
                display_name VARCHAR(190) DEFAULT NULL, followers INT(11) DEFAULT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
                fetched_at DATETIME DEFAULT NULL, last_error VARCHAR(300) DEFAULT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (platform_connection_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            self::$ready = null;
        } catch (Throwable $e) {
            Logger::warning('Social accounts schema failed', ['message' => $e->getMessage()]);
        }
    }

    /** connection_id => بيانات العرض */
    public static function profiles(array $connectionIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $connectionIds)));
        if (!$ids || !self::ready()) {
            return [];
        }
        $out = [];
        foreach (Database::getInstance()->query('SELECT * FROM social_account_profiles WHERE platform_connection_id IN (' . implode(',', $ids) . ')') ?: [] as $r) {
            $out[(int) $r['platform_connection_id']] = $r;
        }
        return $out;
    }

    /** الصفحات اللي العميل وقّفها (ما بتظهرش في المحرر وما بتتحسبش في الباقة) */
    public static function inactiveIds(int $account): array
    {
        if (!self::ready()) {
            return [];
        }
        try {
            return array_map('intval', array_column(Database::getInstance()->query(
                'SELECT p.platform_connection_id FROM social_account_profiles p JOIN platform_connections c ON c.id = p.platform_connection_id
                 WHERE p.is_active = 0 AND (c.user_id = ? OR c.website_id IN (SELECT id FROM websites WHERE user_id = ?))', [$account, $account]
            ) ?: [], 'platform_connection_id'));
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function owned(int $account, int $connectionId): ?array
    {
        return Database::getInstance()->query(
            "SELECT id, platform FROM platform_connections WHERE id = ? AND (user_id = ? OR website_id IN (SELECT id FROM websites WHERE user_id = ?)) LIMIT 1",
            [$connectionId, $account, $account]
        )[0] ?? null;
    }

    public static function setActive(int $account, int $connectionId, bool $active): void
    {
        self::ensureSchema();
        if (!self::owned($account, $connectionId)) {
            throw new InvalidArgumentException('الحساب ده مش موجود');
        }
        Database::getInstance()->query(
            'INSERT INTO social_account_profiles (platform_connection_id, is_active) VALUES (?, ?) ON DUPLICATE KEY UPDATE is_active = VALUES(is_active)',
            [$connectionId, $active ? 1 : 0]
        );
    }

    /**
     * يحدّث بيانات الحسابات القديمة (أو اللي ملهاش بيانات). $account = null: كل العملاء (المهمة اليومية).
     * @return int عدد الحسابات اللي اتحدثت
     */
    public static function refreshStale(?int $account, int $max = 6): int
    {
        self::ensureSchema();
        if (!self::ready()) {
            return 0;
        }
        $db = Database::getInstance();
        $rows = $db->query(
            "SELECT c.id, c.platform, c.access_token, c.external_location_id FROM platform_connections c
              LEFT JOIN social_account_profiles p ON p.platform_connection_id = c.id
              WHERE c.platform IN ('facebook','instagram','threads') AND c.status = 'connected'
                AND (p.fetched_at IS NULL OR p.fetched_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . self::STALE_HOURS . " HOUR))"
            . ($account ? ' AND (c.user_id = ' . (int) $account . ' OR c.website_id IN (SELECT id FROM websites WHERE user_id = ' . (int) $account . '))' : '')
            . ' ORDER BY p.fetched_at IS NOT NULL, p.fetched_at, c.id LIMIT ' . max(1, min(500, $max))
        ) ?: [];
        $enc = new Encryption();
        $done = 0;
        foreach ($rows as $r) {
            $res = ['success' => false, 'error' => 'no token'];
            try {
                $token = $enc->decrypt((string) $r['access_token']);
                if ($token !== '' && $r['platform'] === 'threads') {
                    $api = self::$threadsFactory ? (self::$threadsFactory)($token, (string) $r['external_location_id']) : new ThreadsAPI($token, 'me');
                    $me = $api->me();
                    $res = $me['success'] ? ['success' => true, 'avatar' => $me['avatar'] ?? null, 'username' => $me['username'] ?: null, 'name' => null, 'followers' => null] : $me;
                } elseif ($token !== '') {
                    $api = self::$apiFactory ? (self::$apiFactory)($token) : new MetaSocialAPI($token);
                    $res = $api->getProfile((string) $r['platform'], (string) $r['external_location_id'], $token);
                }
            } catch (Throwable $e) {
                $res = ['success' => false, 'error' => $e->getMessage()];
            }
            if (!empty($res['success'])) {
                $db->query(
                    'INSERT INTO social_account_profiles (platform_connection_id, avatar_url, username, display_name, followers, fetched_at, last_error) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), NULL)
                     ON DUPLICATE KEY UPDATE avatar_url = VALUES(avatar_url), username = VALUES(username), display_name = VALUES(display_name), followers = VALUES(followers), fetched_at = VALUES(fetched_at), last_error = NULL',
                    [(int) $r['id'], $res['avatar'] ? mb_substr($res['avatar'], 0, 1000) : null, $res['username'] ? mb_substr($res['username'], 0, 190) : null, $res['name'] ? mb_substr($res['name'], 0, 190) : null, $res['followers']]
                );
                $done++;
            } else {
                // نسجّل المحاولة عشان ما نعيدهاش مع كل فتح للصفحة (هتتجرب تاني بعد 20 ساعة)
                $db->query(
                    'INSERT INTO social_account_profiles (platform_connection_id, fetched_at, last_error) VALUES (?, UTC_TIMESTAMP(), ?)
                     ON DUPLICATE KEY UPDATE fetched_at = VALUES(fetched_at), last_error = VALUES(last_error)',
                    [(int) $r['id'], mb_substr((string) ($res['error'] ?? 'failed'), 0, 300)]
                );
            }
        }
        return $done;
    }
}

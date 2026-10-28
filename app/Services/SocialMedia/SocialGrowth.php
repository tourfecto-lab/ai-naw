<?php

/**
 * Tourfecto - نمو المتابعين (لقطة يومية لكل حساب فيسبوك/إنستجرام)
 *
 * Meta ما بترجعش تاريخ عدد المتابعين، فبناخد لقطة يومية (SocialAccountSnapshotJob) ونرسم منها المنحنى.
 * series() بيرجّع لكل حساب: النقط اليومية + صافي النمو في الفترة + نسبة النمو.
 * @version 1.0.0
 */
require_once __DIR__ . '/MetaSocialAPI.php';

final class SocialGrowth
{
    /** @var callable|null حقن عميل Meta في الاختبارات */
    public static $apiFactory = null;

    public static function ready(): bool
    {
        try {
            return (bool) Database::getInstance()->query("SHOW TABLES LIKE 'social_account_snapshots'");
        } catch (Throwable $e) {
            return false;
        }
    }

    /** لقطة النهارده لحسابات (كل العملاء أو عميل واحد). بترجع عدد الحسابات اللي اتسجلت */
    public static function snapshot(?int $userId = null, int $limit = 200): int
    {
        if (!self::ready()) {
            return 0;
        }
        $db = Database::getInstance();
        $rows = $db->query(
            "SELECT c.id, c.platform, c.access_token, c.external_location_id FROM platform_connections c
              LEFT JOIN social_account_snapshots s ON s.platform_connection_id = c.id AND s.snap_date = UTC_DATE()
              WHERE c.platform IN ('facebook','instagram') AND c.status = 'connected' AND s.id IS NULL"
              . ($userId ? ' AND (c.user_id = ' . (int) $userId . ' OR c.website_id IN (SELECT id FROM websites WHERE user_id = ' . (int) $userId . '))' : '') . '
              ORDER BY c.id LIMIT ' . max(1, min(1000, $limit))
        ) ?: [];
        $enc = new Encryption();
        $done = 0;
        foreach ($rows as $r) {
            try {
                $token = $enc->decrypt((string) $r['access_token']);
                $api = self::$apiFactory ? (self::$apiFactory)($token) : new MetaSocialAPI($token);
                $st = $api->getAccountStats((string) $r['platform'], (string) $r['external_location_id'], $token);
            } catch (Throwable $e) {
                continue;
            }
            if (empty($st['success']) || $st['followers'] === null) {
                continue;
            }
            $db->query(
                'INSERT INTO social_account_snapshots (platform_connection_id, snap_date, followers, posts_count) VALUES (?, UTC_DATE(), ?, ?)
                 ON DUPLICATE KEY UPDATE followers = VALUES(followers), posts_count = VALUES(posts_count)',
                [(int) $r['id'], $st['followers'], $st['posts_count']]
            );
            $done++;
        }
        return $done;
    }

    /**
     * منحنى المتابعين لكل حساب في الفترة.
     * @return array [['connection_id','platform','name','points'=>[['date','followers']],'current'=>?int,'change'=>?int,'change_pct'=>?float]]
     */
    public static function series(int $userId, int $days = 30): array
    {
        if (!self::ready()) {
            return [];
        }
        $days = max(7, min(365, $days));
        $rows = Database::getInstance()->query(
            "SELECT c.id AS connection_id, c.platform, c.external_location_name AS name, s.snap_date, s.followers
               FROM social_account_snapshots s JOIN platform_connections c ON c.id = s.platform_connection_id
              WHERE (c.user_id = ? OR c.website_id IN (SELECT id FROM websites WHERE user_id = ?)) AND s.snap_date >= DATE_SUB(UTC_DATE(), INTERVAL {$days} DAY)
              ORDER BY c.id, s.snap_date",
            [$userId, $userId]
        ) ?: [];
        return self::summarize($rows);
    }

    /** تجميع الصفوف لمنحنى + صافي النمو (نقية - بتتختبر) */
    public static function summarize(array $rows): array
    {
        $by = [];
        foreach ($rows as $r) {
            $id = (int) $r['connection_id'];
            $by[$id] = $by[$id] ?? ['connection_id' => $id, 'platform' => (string) $r['platform'], 'name' => (string) $r['name'], 'points' => []];
            if ($r['followers'] !== null) {
                $by[$id]['points'][] = ['date' => (string) $r['snap_date'], 'followers' => (int) $r['followers']];
            }
        }
        foreach ($by as &$a) {
            $first = $a['points'][0]['followers'] ?? null;
            $last = $a['points'] ? end($a['points'])['followers'] : null;
            $a['current'] = $last;
            $a['change'] = ($first !== null && $last !== null) ? $last - $first : null;
            $a['change_pct'] = ($first && $last !== null) ? round(($last - $first) / $first * 100, 1) : null;
        }
        unset($a);
        return array_values($by);
    }
}

<?php

/**
 * كاش صغير لنتايج بحث جوجل المدفوعة اللي مش بتتغير بسرعة (تحليل المنافسين لدومين في سوق معيّن).
 * نفس الدومين بيتحلل أكتر من مرة (كزائر قبل التسجيل ثم وقت إنشاء الحساب)، فبنستخدم النتيجة الأخيرة بدل ما ندفع تاني.
 * مفيش بيانات عملاء هنا: دومين + سوق + نتيجة عامة.
 */
final class SeoApiCache
{
    private static bool $ready = false;

    private static function db(): ?Database
    {
        try {
            $db = Database::getInstance();
            if (!self::$ready) {
                $db->getConnection()->exec("CREATE TABLE IF NOT EXISTS seo_api_cache (
                    cache_key CHAR(64) NOT NULL PRIMARY KEY, payload MEDIUMTEXT NOT NULL, hits INT NOT NULL DEFAULT 0, fetched_at DATETIME NOT NULL, KEY idx_fetched (fetched_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                self::$ready = true;
            }
            return $db;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function get(string $key, int $ttlSeconds): ?array
    {
        $db = self::db();
        if (!$db) {
            return null;
        }
        try {
            $r = $db->query('SELECT payload FROM seo_api_cache WHERE cache_key = ? AND fetched_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . max(1, $ttlSeconds) . ' SECOND)', [$key]);
            if (!$r) {
                return null;
            }
            $db->query('UPDATE seo_api_cache SET hits = hits + 1 WHERE cache_key = ?', [$key]);
            $d = json_decode((string) $r[0]['payload'], true);
            return is_array($d) ? $d : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function put(string $key, array $payload): void
    {
        $db = self::db();
        if (!$db) {
            return;
        }
        try {
            $db->query('REPLACE INTO seo_api_cache (cache_key, payload, hits, fetched_at) VALUES (?, ?, 0, UTC_TIMESTAMP())', [$key, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) {
            // الكاش إضافة: لو فشل نكمّل من غيره
        }
    }
}

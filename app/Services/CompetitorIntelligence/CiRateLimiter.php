<?php

/**
 * Tourfecto - Competitor Intelligence: Rate Limiter
 * @version 1.0.0
 *
 * حماية endpoints مكلفة (AI calls, discovery خارجي) من الاستخدام المفرط
 * لكل مستخدم - خوارزمية fixed-window بسيطة بدون أي اعتماديات خارجية،
 * مخزّنة في جدول `ci_rate_limits` (عملي في البيئات اللي فيها أكتر من
 * process/app server لأن الكاونتر مش في الذاكرة المحلية لكل instance).
 *
 * الأرقام (window + limit) قابلة للضبط من مكان واحد جوه النصوص الثابتة
 * تحت. معنى `retry_after` هو عدد الثواني الباقية لنهاية النافذة الحالية.
 *
 * في حالات فشل الـ DB (الجدول مش موجود مثلًا) بنسجل خطأ ونسمح بالطلب -
 * مبدئ الـ fail-open هنا أأمن لسهولة الاستخدام من إننا نطلع للمستخدم 500
 * على كل طلب، والجدول معمول بـ migration إضافية، مش هدّامة.
 */
class CiRateLimiter
{
    /** نافذة/حدود كل scope - [limit, windowSeconds] */
    private const LIMITS = [
        'ai_ask' => [10, 60],          // 10 سؤال AI في الدقيقة
        'ai_profile' => [6, 300],      // 6 تحليلات ملف في 5 دقايق
        'ai_insights' => [5, 300],     // 5 فحص رؤى في 5 دقايق
        'ai_weekly_summary' => [4, 3600], // 4 ملخصات أسبوعية في الساعة
        'discovery_run' => [10, 3600], // 10 دورات اكتشاف في الساعة
        'report_generate' => [20, 3600], // 20 تقرير في الساعة
        'keyword_rankings_check' => [10, 1800], // 10 فحوصات ترتيبات SERP كل 30 دقيقة
        'battlecard_generate' => [10, 300],     // 10 بطاقات معركة كل 5 دقايق
        // M6 (SEO/AutoSeo, 2026-08-29)
        'seo_rank_tracking_check' => [10, 1800], // 10 فحوصات ترتيب كلمات (Rank Tracking) كل 30 دقيقة
        'seo_crawl_run' => [5, 900],             // 5 زحفات متعددة الصفحات كل 15 دقيقة
    ];

    /**
     * تسجيل محاولة وقراءة هل اتسمح ولا لأ.
     * @param string $scope مفتاح الـ limit (شوف LIMITS)
     * @param string $actorKey تمييز الـ actor (مثال: user:{id})
     * @return array{allowed:bool, remaining:int, retry_after:int}
     */
    public static function hit(string $scope, string $actorKey): array
    {
        $limit = self::LIMITS[$scope] ?? null;
        if ($limit === null) {
            return ['allowed' => true, 'remaining' => PHP_INT_MAX, 'retry_after' => 0];
        }
        [$maxHits, $windowSeconds] = $limit;

        $now = time();
        $windowStart = self::windowStart($now, $windowSeconds);
        $key = $scope . ':' . $actorKey;

        try {
            $db = Database::getInstance();
            $db->query(
                "INSERT INTO `ci_rate_limits` (`scope_key`, `window_start`, `hits`)
                 VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE `hits` = `hits` + 1",
                [$key, $windowStart]
            );
            $rows = $db->query(
                "SELECT `hits` FROM `ci_rate_limits` WHERE `scope_key` = ? AND `window_start` = ? LIMIT 1",
                [$key, $windowStart]
            );
            $hits = (int) ($rows[0]['hits'] ?? 1);

            // تنظيف عشوائي قديم مش مكلف (مرة تقريبًا من كل 100 استدعاء) -
            // بنشيل أي صفوف فاتت على بدايتها أكتر من 24 ساعة.
            if ($windowStart % 100 === 0) {
                $db->query("DELETE FROM `ci_rate_limits` WHERE `window_start` < ?", [$now - 86400]);
            }
        } catch (\Throwable $e) {
            error_log('CiRateLimiter: ' . $e->getMessage());
            return ['allowed' => true, 'remaining' => PHP_INT_MAX, 'retry_after' => 0];
        }

        $allowed = $hits <= $maxHits;
        $remaining = $allowed ? max(0, $maxHits - $hits) : 0;
        $retryAfter = $allowed ? 0 : max(0, $windowStart + $windowSeconds - $now);

        return ['allowed' => $allowed, 'remaining' => $remaining, 'retry_after' => $retryAfter];
    }

    /**
     * بداية نافذة الـ fixed-window لثانية معينة - منفصلة ومنطقها صافٍ
     * عشان يتبقى unit-testable بدون أي DB.
     */
    public static function windowStart(int $now, int $windowSeconds): int
    {
        if ($windowSeconds <= 0) {
            return $now;
        }
        return (int) floor($now / $windowSeconds) * $windowSeconds;
    }
}

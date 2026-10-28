<?php

/**
 * Tourfecto - تجميع لوحة "صحة النشر" (نقي، من غير DB): بياخد صفوف سجل النشر وصفوف صحة الاتصالات
 * ويطلّع لكل صفحة: نسبة النجاح، متوسط الزمن، أكتر الأخطاء، حالة الربط، وتنبيه.
 * الاتصالات اللي حالتها سيئة بس مالهاش محاولات نشر (توكن منتهي مثلًا) بتظهر برضه - دي أهم حالة تتنبّه لها.
 * @version 1.0.0
 */
final class PublishHealthReport
{
    public const ALERT_RATE = 0.8;
    public const ALERT_MIN_ATTEMPTS = 5;
    private const OK_OUTCOMES = ['published', 'reconciled_published'];
    private const BAD_OUTCOMES = ['failed', 'uncertain', 'preflight_failed', 'reconciled_not_published'];
    private const BAD_STATES = ['expired', 'invalid', 'missing_permissions', 'expiring'];

    /**
     * @param array $logRows [connection_id, platform, name, outcome, error_category, n, avg_ms]
     * @param array $healthRows [connection_id, platform, name, state, token_expires_at, data_access_expires_at, missing_scopes, last_error, checked_at]
     */
    public static function build(array $logRows, array $healthRows): array
    {
        $agg = [];
        foreach ($logRows as $r) {
            $k = (int) $r['connection_id'];
            $agg[$k] ??= ['platform' => $r['platform'] ?? null, 'name' => $r['name'] ?? null, 'outcomes' => [], 'errors' => [], 'ok' => 0, 'bad' => 0, 'ms' => []];
            $n = (int) $r['n'];
            $o = (string) $r['outcome'];
            $agg[$k]['outcomes'][$o] = ($agg[$k]['outcomes'][$o] ?? 0) + $n;
            if (in_array($o, self::OK_OUTCOMES, true)) {
                $agg[$k]['ok'] += $n;
                if (($r['avg_ms'] ?? null) !== null && $o === 'published') {
                    $agg[$k]['ms'][] = (float) $r['avg_ms'];
                }
            } elseif (in_array($o, self::BAD_OUTCOMES, true)) {
                $agg[$k]['bad'] += $n;
                if (!empty($r['error_category'])) {
                    $agg[$k]['errors'][$r['error_category']] = ($agg[$k]['errors'][$r['error_category']] ?? 0) + $n;
                }
            }
        }
        $health = [];
        foreach ($healthRows as $h) {
            $health[(int) $h['connection_id']] = $h;
        }

        $pages = [];
        foreach ($agg as $k => $a) {
            $total = $a['ok'] + $a['bad'];
            arsort($a['errors']);
            $h = $health[$k] ?? null;
            $pages[] = [
                'connection_id' => $k, 'platform' => $a['platform'] ?? ($h['platform'] ?? null), 'name' => $a['name'] ?? ($h['name'] ?? null),
                'attempts' => $total,
                'success_rate' => $total ? round($a['ok'] / $total * 100, 1) : null,
                'avg_publish_ms' => $a['ms'] ? (int) round(array_sum($a['ms']) / count($a['ms'])) : null,
                'top_errors' => $a['errors'], 'outcomes' => $a['outcomes'],
                'alert' => self::alert($a['ok'], $total, $h),
                'connection_health' => $h,
            ];
        }
        foreach ($health as $k => $h) {
            if (isset($agg[$k])) {
                continue;
            }
            $pages[] = [
                'connection_id' => $k, 'platform' => $h['platform'] ?? null, 'name' => $h['name'] ?? null,
                'attempts' => 0, 'success_rate' => null, 'avg_publish_ms' => null, 'top_errors' => [], 'outcomes' => [],
                'alert' => self::alert(0, 0, $h), 'connection_health' => $h,
            ];
        }
        // التنبيهات الأول، وبعدها الأقل نجاحًا
        usort($pages, static function ($x, $y) {
            if ($x['alert'] !== $y['alert']) {
                return $x['alert'] ? -1 : 1;
            }
            return ($x['success_rate'] ?? 101) <=> ($y['success_rate'] ?? 101);
        });
        return $pages;
    }

    private static function alert(int $ok, int $total, ?array $health): bool
    {
        if ($health && in_array($health['state'] ?? '', self::BAD_STATES, true)) {
            return true;
        }
        return $total >= self::ALERT_MIN_ATTEMPTS && ($ok / $total) < self::ALERT_RATE;
    }
}

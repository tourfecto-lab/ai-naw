<?php
/**
 * Tourfecto - تنبيه وقوع زيارات جوجل (من Search Console الحقيقي)
 *
 * كل يوم: نقارن آخر 7 أيام مكتملة بالـ 7 اللي قبلها لكل موقع مربوط بـ Search Console.
 * لو النقرات وقعت 30% أو أكتر (وكان فيه زيارات كفاية تتقاس) بنبعت تنبيه للعميل
 * فيه أكتر الصفحات اللي خسرت، مرة واحدة في الأسبوع للموقع الواحد.
 */
class SeoTrafficAlertService
{
    public const DROP = 0.30;
    public const MIN_CLICKS = 20;

    /** @var callable|null للاختبار: fn(int $uid, int $wid): ?array{0:object,1:string} */
    private $clientFactory;

    public function __construct(?callable $clientFactory = null)
    {
        $this->clientFactory = $clientFactory;
    }

    /** @return array{checked:int, alerts:int} */
    public function run(int $limit = 200): array
    {
        $db = Database::getInstance();
        $rows = $db->query("SELECT pc.user_id, pc.website_id FROM platform_connections pc WHERE pc.platform='google_search_console' AND pc.status='connected' AND pc.website_id IS NOT NULL ORDER BY pc.id DESC LIMIT " . (int) $limit);
        $checked = 0;
        $alerts = 0;
        $seen = [];
        foreach ($rows as $r) {
            $wid = (int) $r['website_id'];
            if (isset($seen[$wid])) {
                continue;
            }
            $seen[$wid] = true;
            try {
                $res = $this->checkWebsite((int) $r['user_id'], $wid);
                $checked++;
                if (!empty($res['alert'])) {
                    $alerts++;
                }
            } catch (Throwable $e) {
                Logger::warning('SEO traffic alert skipped', ['website_id' => $wid, 'error' => $e->getMessage()]);
            }
        }
        return ['checked' => $checked, 'alerts' => $alerts];
    }

    /** مقارنة أسبوعين لموقع واحد */
    public function checkWebsite(int $uid, int $wid, bool $notify = true): array
    {
        $client = $this->clientFactory ? ($this->clientFactory)($uid, $wid) : self::client($uid, $wid);
        if (!$client) {
            return ['state' => 'not_connected'];
        }
        [$api, $property] = $client;
        // بيانات جوجل بتتأخر يومين-تلاتة: آخر يوم مكتمل = من 3 أيام
        $end = gmdate('Y-m-d', time() - 3 * 86400);
        $start = gmdate('Y-m-d', time() - 9 * 86400);
        $pEnd = gmdate('Y-m-d', time() - 10 * 86400);
        $pStart = gmdate('Y-m-d', time() - 16 * 86400);
        $now = $api->getSearchAnalytics($property, $start, $end, ['page'], 500);
        $prev = $api->getSearchAnalytics($property, $pStart, $pEnd, ['page'], 500);
        if (empty($now['success']) || empty($prev['success'])) {
            return ['state' => 'unavailable'];
        }
        $sum = static fn ($rows) => array_sum(array_column($rows, 'clicks'));
        $a = $sum($now['rows']);
        $b = $sum($prev['rows']);
        $res = ['state' => 'measured', 'clicks' => $a, 'previous' => $b, 'change' => $b > 0 ? round(($a - $b) / $b, 3) : null, 'alert' => false, 'pages' => []];
        if ($b < self::MIN_CLICKS || $res['change'] === null || $res['change'] > -self::DROP) {
            return $res;
        }
        // أكتر الصفحات اللي خسرت نقرات
        $cur = [];
        foreach ($now['rows'] as $row) {
            $cur[(string) $row['page']] = (int) $row['clicks'];
        }
        $loss = [];
        foreach ($prev['rows'] as $row) {
            $p = (string) $row['page'];
            $d = (int) $row['clicks'] - ($cur[$p] ?? 0);
            if ($d > 0) {
                $loss[$p] = $d;
            }
        }
        arsort($loss);
        $res['pages'] = array_slice($loss, 0, 3, true);
        $res['alert'] = true;
        if ($notify) {
            $this->notify($uid, $wid, $res);
        }
        return $res;
    }

    private function notify(int $uid, int $wid, array $res): void
    {
        $key = 'seo_drop_alert_' . $wid . '_' . gmdate('o-W');
        try {
            $cache = new Cache();
            if ($cache->get($key)) {
                return;
            }
            $cache->set($key, 1, 8 * 86400);
        } catch (Throwable $e) {
        }
        $pct = (int) round(abs($res['change']) * 100);
        $lines = [];
        foreach ($res['pages'] as $page => $lost) {
            $lines[] = '• ' . preg_replace('#^https?://[^/]+#', '', $page) . ' (-' . $lost . ')';
        }
        $body = 'زيارات جوجل لموقعك وقعت ' . $pct . '% آخر 7 أيام (' . $res['clicks'] . ' نقرة بدل ' . $res['previous'] . ').'
            . ($lines ? "\nأكتر الصفحات اللي خسرت:\n" . implode("\n", $lines) : '')
            . "\nافتح خبير SEO وشغّل دورة جديدة عشان نعرف السبب (فهرسة، سرعة، أو تغيير في الصفحة).";
        try {
            Notification::notify($uid, 'seo_traffic_drop', 'تنبيه: زيارات جوجل وقعت ' . $pct . '%', $body, '/seo-expert');
        } catch (Throwable $e) {
        }
        try {
            ActivityLog::record('seo', 'seo.traffic_drop', ['user_id' => $uid, 'subject_type' => 'websites', 'subject_id' => $wid, 'meta' => ['change' => $res['change'], 'clicks' => $res['clicks'], 'previous' => $res['previous']]]);
        } catch (Throwable $e) {
        }
    }

    /** Search Console API بتوكن العميل (مع التجديد) */
    public static function client(int $uid, int $wid): ?array
    {
        $db = Database::getInstance();
        $r = $db->query("SELECT * FROM platform_connections WHERE user_id=? AND website_id=? AND platform='google_search_console' AND status='connected' ORDER BY id DESC LIMIT 1", [$uid, $wid])[0] ?? null;
        if (!$r || empty($r['external_location_id'])) {
            return null;
        }
        try {
            $enc = new Encryption();
            $token = $enc->decrypt($r['access_token']);
            if ($r['token_expires_at'] && strtotime($r['token_expires_at'] . ' UTC') <= time() + 60) {
                if (empty($r['refresh_token'])) {
                    return null;
                }
                $fresh = (new GoogleOAuthClient(GoogleOAuthClient::SCOPE_SEARCH_CONSOLE))->refreshAccessToken($enc->decrypt($r['refresh_token']));
                if (empty($fresh['success'])) {
                    return null;
                }
                $token = $fresh['access_token'];
                $db->query('UPDATE platform_connections SET access_token=?, token_expires_at=? WHERE id=?', [$enc->encrypt($token), gmdate('Y-m-d H:i:s', time() + (int) ($fresh['expires_in'] ?? 3600)), $r['id']]);
            }
            return [new GoogleSearchConsoleAPI($token), (string) $r['external_location_id']];
        } catch (Throwable $e) {
            return null;
        }
    }
}

<?php

/**
 * Visit analytics for generated websites. Privacy-safe by design: only daily aggregates are stored (views, unique visitors,
 * pages, traffic source). Unique visitors come from a hash of ip + user agent + day + a server secret that rotates every day
 * and is deleted after 2 days, so nothing identifies a person and nothing can be followed across days.
 * Tracking never breaks a page: every failure is swallowed.
 */
class SiteAnalytics
{
    private const BOT_PATTERN = '/bot|crawl|spider|slurp|facebookexternalhit|preview|monitor|curl|wget|python-requests|headless|lighthouse|pingdom|uptime|scanner/i';

    public static function isBot(string $ua): bool
    {
        return $ua === '' || (bool) preg_match(self::BOT_PATTERN, $ua);
    }

    /** direct / search / social / ads / other */
    public static function classifySource(string $referrer, string $ownHost = '', array $query = []): string
    {
        $medium = strtolower((string) ($query['utm_medium'] ?? ''));
        if (in_array($medium, ['cpc', 'ppc', 'paid', 'paidsocial', 'ads'], true) || isset($query['gclid'])) {
            return 'ads';
        }
        $host = strtolower((string) parse_url($referrer, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);
        if ($host === '' || ($ownHost !== '' && $host === preg_replace('/^www\./', '', strtolower($ownHost)))) {
            $src = strtolower((string) ($query['utm_source'] ?? ''));
            if ($src !== '' && preg_match('/facebook|instagram|tiktok|whatsapp|twitter|youtube|linkedin|snapchat/', $src)) {
                return 'social';
            }
            return 'direct';
        }
        if (preg_match('/(^|\.)(google|bing|yahoo|duckduckgo|yandex|baidu|ecosia|brave)\./', $host . '.')) {
            return 'search';
        }
        if (preg_match('/facebook|(^|\.)fb\.com|instagram|tiktok|youtube|youtu\.be|whatsapp|wa\.me|twitter|(^|\.)t\.co$|linkedin|pinterest|snapchat|reddit|telegram/', $host)) {
            return 'social';
        }
        return 'other';
    }

    public static function pageKey(string $page, string $slug = ''): string
    {
        $page = preg_replace('/[^a-z0-9_-]/i', '', $page) ?: 'home';
        $slug = preg_replace('/[^a-z0-9_-]/i', '', $slug);
        return mb_substr($slug !== '' ? $page . ':' . $slug : $page, 0, 100);
    }

    /** Count one human page view. Returns true when it was counted. */
    public static function track(int $websiteId, string $page, bool $ownerViewing = false, ?string $ua = null, ?string $referrer = null, ?string $ip = null, ?array $query = null, ?string $day = null): bool
    {
        try {
            $ua ??= (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
            if ($websiteId < 1 || $ownerViewing || self::isBot($ua) || (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && $day === null)) {
                return false;
            }
            if (($_SERVER['HTTP_PURPOSE'] ?? '') === 'prefetch' || ($_SERVER['HTTP_SEC_PURPOSE'] ?? '') !== '' && str_contains((string) $_SERVER['HTTP_SEC_PURPOSE'], 'prefetch')) {
                return false;
            }
            $db = Database::getInstance();
            $day ??= gmdate('Y-m-d');
            $referrer ??= (string) ($_SERVER['HTTP_REFERER'] ?? '');
            $ip ??= (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            $query ??= $_GET;
            $source = self::classifySource($referrer, (string) ($_SERVER['HTTP_HOST'] ?? ''), $query);
            $vhash = md5($ip . '|' . $ua . '|' . $day . '|' . self::secret());

            $db->query('INSERT INTO site_daily_stats (website_id, day, views, visitors) VALUES (?, ?, 1, 0) ON DUPLICATE KEY UPDATE views = views + 1', [$websiteId, $day]);
            $db->query('INSERT INTO site_daily_pages (website_id, day, page, views) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE views = views + 1', [$websiteId, $day, $page]);
            $db->query('INSERT INTO site_daily_sources (website_id, day, source, views) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE views = views + 1', [$websiteId, $day, $source]);
            // INSERT IGNORE: a repeat visitor on the same day changes nothing
            $db->query('INSERT IGNORE INTO site_visitor_seen (website_id, day, vhash) VALUES (?, ?, ?)', [$websiteId, $day, $vhash]);
            $new = $db->query('SELECT ROW_COUNT() AS n');
            if ((int) ($new[0]['n'] ?? 0) > 0) {
                $db->query('UPDATE site_daily_stats SET visitors = visitors + 1 WHERE website_id = ? AND day = ?', [$websiteId, $day]);
            }
            if (random_int(1, 200) === 1) {
                $db->query('DELETE FROM site_visitor_seen WHERE day < DATE_SUB(UTC_DATE(), INTERVAL 2 DAY)');
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function secret(): string
    {
        $k = function_exists('env') ? env('APP_KEY') : getenv('APP_KEY');
        return is_string($k) && $k !== '' ? $k : (defined('APP_URL') ? (string) APP_URL : 'tourfecto');
    }

    /** @return array{days:int,views:int,visitors:int,leads:int,bookings:int,conversion:float,daily:array,pages:array,sources:array} */
    public static function report(int $websiteId, int $ownerId, int $days = 30): array
    {
        $days = max(1, min(90, $days));
        $db = Database::getInstance();
        $from = gmdate('Y-m-d', time() - ($days - 1) * 86400);
        $rows = $db->query('SELECT day, views, visitors FROM site_daily_stats WHERE website_id = ? AND day >= ? ORDER BY day', [$websiteId, $from]) ?: [];
        $byDay = [];
        foreach ($rows as $r) {
            $byDay[$r['day']] = ['day' => $r['day'], 'views' => (int) $r['views'], 'visitors' => (int) $r['visitors']];
        }
        $daily = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', time() - $i * 86400);
            $daily[] = $byDay[$d] ?? ['day' => $d, 'views' => 0, 'visitors' => 0];
        }
        $views = array_sum(array_column($daily, 'views'));
        $visitors = array_sum(array_column($daily, 'visitors')); // sum of daily uniques (a person back on another day counts again)
        $pages = $db->query('SELECT page, SUM(views) AS views FROM site_daily_pages WHERE website_id = ? AND day >= ? GROUP BY page ORDER BY views DESC LIMIT 10', [$websiteId, $from]) ?: [];
        $sources = $db->query('SELECT source, SUM(views) AS views FROM site_daily_sources WHERE website_id = ? AND day >= ? GROUP BY source ORDER BY views DESC', [$websiteId, $from]) ?: [];
        $leads = (int) ($db->query('SELECT COUNT(*) AS c FROM website_leads WHERE website_id = ? AND created_at >= ?', [$websiteId, $from . ' 00:00:00'])[0]['c'] ?? 0);
        $bookings = (int) ($db->query(
            'SELECT COUNT(*) AS c FROM bookings b JOIN crm_products p ON p.id = b.product_id WHERE p.website_id = ? AND b.user_id = ? AND b.created_at >= ? AND b.status <> ?',
            [$websiteId, $ownerId, $from . ' 00:00:00', 'cancelled']
        )[0]['c'] ?? 0);
        return [
            'days' => $days, 'views' => $views, 'visitors' => $visitors, 'leads' => $leads, 'bookings' => $bookings,
            'conversion' => $visitors > 0 ? round((($leads + $bookings) / $visitors) * 100, 1) : 0.0,
            'daily' => $daily,
            'pages' => array_map(fn ($p) => ['page' => $p['page'], 'views' => (int) $p['views']], $pages),
            'sources' => array_map(fn ($s) => ['source' => $s['source'], 'views' => (int) $s['views']], $sources),
        ];
    }
}

<?php
/**
 * رحلات الموسم من موقع العميل نفسه (خارجي أو على المنصة): بنقرا الـ sitemap، ونختار صفحات الرحلات،
 * ونحفظ الاسم والوصف والسعر المنشور. التحديث بزرار من العميل، والنتيجة متخزنة عشان الصفحة تفتح بسرعة.
 */
final class SeasonSiteTrips
{
    public const MAX_PAGES = 60;
    // تحت الـ 60 ثانية الافتراضية لـ nginx/PHP-FPM عشان الطلب مايتقطعش
    private const BUDGET_SECONDS = 45;
    private const SKIP = '#/(?:blog|news|guides?|category|categories|tag|tags|author|page/\d+|cart|checkout|account|login|contact|about|privacy|terms|faq|wp-content|feed)(?:/|$)#i';
    private const TRIP_PATH = '#(?:tour|trip|excursion|cruise|safari|package|holiday|activit|day-?use|transfer|experience|رحل|جول)#iu';
    private Database $db;
    /** @var callable fn(string $url): array{code:int,body:string,url:string,error?:string} */
    private $http;

    public function __construct(?Database $db = null, ?callable $http = null)
    {
        $this->db = $db ?? Database::getInstance();
        // SafeHttp::exec بيرجّع المحتوى في 'response'؛ الكود هنا (وAiVisibilityEvidence) بيقرا 'body'
        $this->http = $http ?? static function (string $url): array {
            $r = SafeHttp::exec($url, [CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_ENCODING => '', CURLOPT_USERAGENT => 'TourfectoSeason/1.0', CURLOPT_RETURNTRANSFER => true]);
            $r['body'] = is_string($r['response'] ?? null) ? $r['response'] : '';
            return $r;
        };
    }

    private static bool $schemaReady = false;

    public static function ensureSchema(Database $db): void
    {
        if (self::$schemaReady) return;
        $db->getConnection()->exec("CREATE TABLE IF NOT EXISTS `season_site_trips` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `user_id` INT(11) NOT NULL,
            `website_id` INT(11) NOT NULL,
            `url` VARCHAR(700) NOT NULL,
            `url_hash` CHAR(40) NOT NULL,
            `name` VARCHAR(255) NOT NULL,
            `description` TEXT NULL,
            `price` VARCHAR(60) NOT NULL DEFAULT '',
            `image_url` VARCHAR(700) NOT NULL DEFAULT '',
            `fetched_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_site_url` (`website_id`, `url_hash`),
            KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // الجدول اتعمل قبل كده من غير عمود الصورة
        $has = $db->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'season_site_trips' AND COLUMN_NAME = 'image_url'");
        if (!$has) {
            $db->getConnection()->exec("ALTER TABLE `season_site_trips` ADD COLUMN `image_url` VARCHAR(700) NOT NULL DEFAULT '' AFTER `price`");
        }
        self::$schemaReady = true;
    }

    /** الرحلات المحفوظة للعميل (لو الجدول لسه متعملش بيرجّع فاضي) */
    public function stored(int $uid): array
    {
        try {
            // يعمل الجدول/العمود لو ناقصين بدل ما كل فتحة للصفحة تسجّل خطأ «Table doesn't exist»
            self::ensureSchema($this->db);
        } catch (Throwable $e) {
        error_log('[SeasonSiteTrips] ignored error: ' . $e->getMessage());
        }
        try {
            return $this->db->query('SELECT id, website_id, url, name, description, price, image_url, fetched_at FROM season_site_trips WHERE user_id = ? ORDER BY website_id, id LIMIT 200', [$uid]) ?: [];
        } catch (Throwable $e) {
            // جدول قديم من غير عمود الصورة: نعرض الرحلات من غير صور لحد السحب الجاي
            try {
                return $this->db->query('SELECT id, website_id, url, name, description, price, fetched_at FROM season_site_trips WHERE user_id = ? ORDER BY website_id, id LIMIT 200', [$uid]) ?: [];
            } catch (Throwable $e) {
                return [];
            }
        }
    }

    public function summary(int $uid): array
    {
        $rows = $this->stored($uid);
        return ['count' => count($rows), 'last' => $rows ? max(array_column($rows, 'fetched_at')) : null];
    }

    /** بيقرا مواقع العميل ويحدّث رحلاتها. بيرجّع عدد الصفحات اللي اتفحصت والرحلات اللي اتلقت لكل موقع. */
    public function sync(int $uid): array
    {
        self::ensureSchema($this->db);
        $deadline = microtime(true) + self::BUDGET_SECONDS;
        $sites = $this->db->query('SELECT * FROM websites WHERE user_id = ? ORDER BY id LIMIT 3', [$uid]) ?: [];
        $out = [];
        foreach ($sites as $site) {
            $base = (string) (($site['main_url'] ?? '') ?: ($site['url'] ?? '') ?: ($site['domain'] ?? ''));
            if ($base !== '' && !preg_match('#^https?://#i', $base)) $base = 'https://' . $base;
            if (!AiVisibilityDetector::safeUrl($base)) continue;
            $wid = (int) $site['id'];
            $recent = [];
            foreach ($this->db->query('SELECT url FROM season_site_trips WHERE website_id = ? AND user_id = ? AND image_url <> ? AND fetched_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 DAY)', [$wid, $uid, '']) ?: [] as $row) {
                $recent[(string) $row['url']] = true;
            }
            $found = $this->discover($base, $deadline, $recent);
            $now = gmdate('Y-m-d H:i:s');
            foreach ($found['trips'] as $t) {
                $this->db->query('INSERT INTO season_site_trips (user_id, website_id, url, url_hash, name, description, price, image_url, fetched_at) VALUES (?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), price = VALUES(price), image_url = VALUES(image_url), fetched_at = VALUES(fetched_at), user_id = VALUES(user_id)',
                    [$uid, $wid, $t['url'], sha1($t['url']), $t['name'], $t['description'], $t['price'], $t['image_url'] ?? '', $now]);
            }
            // صفحة اتشالت من الموقع = رحلة اتشالت؛ بس لو الفحص كمل ولقى رحلات (عشان عطل مؤقت ما يمسحش كل حاجة)
            if ($found['trips'] && $found['complete'] && !$found['skipped']) {
                $this->db->query('DELETE FROM season_site_trips WHERE website_id = ? AND user_id = ? AND fetched_at < ?', [$wid, $uid, $now]);
            }
            $out[] = ['website_id' => $wid, 'url' => $base, 'checked' => $found['checked'], 'trips' => count($found['trips']), 'skipped' => $found['skipped'], 'complete' => $found['complete']];
        }
        return ['sites' => $out, 'total' => array_sum(array_column($out, 'trips'))];
    }

    /** sitemap → صفحات شكلها رحلات → نفتحها ونتأكد إنها صفحة رحلة واحدة */
    /** @param array<string,bool> $skip روابط اتقرت قريب (المفتاح = الرابط) */
    public function discover(string $base, float $deadline, array $skip = []): array
    {
        $host = DataForSeoRankingSource::normalizeHost($base);
        $urls = $this->sitemapUrls($base, $host, $deadline);
        $bodies = [];
        $evidence = new AiVisibilityEvidence(function (string $url, string $method = 'GET') use (&$bodies) {
            $r = ($this->http)($url);
            $bodies[$url] = (string) ($r['body'] ?? '');
            return $r;
        });
        if (!$urls) {
            // من غير sitemap: لينكات الصفحة الرئيسية
            $home = $evidence->page($base);
            foreach ((array) ($home['links'] ?? []) as $l) {
                if (is_array($l) && is_string($l['url'] ?? null)) $urls[] = $l['url'];
            }
        }
        $candidates = [];
        foreach (array_unique($urls) as $u) {
            $path = (string) parse_url($u, PHP_URL_PATH);
            if ($path === '' || $path === '/' || preg_match(self::SKIP, $path) || !preg_match(self::TRIP_PATH, rawurldecode($path))) continue;
            if (!DataForSeoRankingSource::matchesHost($u, $host)) continue;
            // نسخ اللغات (?lang=ar ...) نفس الرحلة: صفحة واحدة لكل رحلة
            $candidates[] = preg_replace('/[?#].*$/', '', $u);
        }
        $candidates = array_values(array_unique($candidates));
        // الصفحات اللي اتقرت قريب بنعدّيها، فكل ضغطة بتكمّل على باقي الموقع لو كبير أو بطيء
        $before = count($candidates);
        $candidates = array_values(array_filter($candidates, static fn ($u) => !isset($skip[$u])));
        $skipped = $before - count($candidates);
        // أكتر من الحد = جزء بس من الموقع اتفحص، فمانمسحش رحلات قديمة على أساسه
        $complete = count($candidates) <= self::MAX_PAGES;
        $candidates = array_slice($candidates, 0, self::MAX_PAGES);
        $trips = [];
        $checked = 0;
        foreach ($candidates as $u) {
            if (microtime(true) > $deadline) { $complete = false; break; }
            $p = $evidence->page($u);
            $checked++;
            if (empty($p['ok']) || !SeoSchemaBuilder::isTourPage($p)) continue;
            $name = trim(preg_replace('/\s+/u', ' ', (string) (($p['h1'] ?? '') ?: ($p['title'] ?? ''))));
            $name = trim(preg_replace('/\s*[|–—-]\s*[^|–—-]{2,40}$/u', '', $name)) ?: $name; // "Trip name | Brand"
            if (mb_strlen($name) < 4) continue;
            $price = '';
            foreach ((array) ($p['prices'] ?? []) as $pr) { if (is_string($pr) && trim($pr) !== '') { $price = mb_substr(trim($pr), 0, 60); break; } }
            $trips[] = ['url' => mb_substr((string) $p['url'], 0, 700), 'name' => mb_substr($name, 0, 255), 'description' => mb_substr(trim((string) ($p['description'] ?? '')), 0, 600), 'price' => $price,
                'image_url' => self::image($bodies[$u] ?? '', (string) $p['url'])];
        }
        // صورة الموقع العامة (لوجو/صورة المشاركة الافتراضية) مش صورة رحلة
        $uses = array_count_values(array_filter(array_column($trips, 'image_url')));
        foreach ($trips as &$t) {
            if ($t['image_url'] !== '' && (($uses[$t['image_url']] ?? 0) >= 3 || preg_match('#(?:logo|og-image|default|placeholder|favicon)#i', $t['image_url']))) $t['image_url'] = '';
        }
        unset($t);
        return ['trips' => $trips, 'checked' => $checked, 'complete' => $complete, 'skipped' => $skipped];
    }

    /** الصورة الرئيسية للصفحة (og:image أو twitter:image) كرابط كامل آمن، أو '' */
    public static function image(string $html, string $pageUrl): string
    {
        if ($html === '') return '';
        foreach (['og:image:secure_url', 'og:image', 'twitter:image'] as $prop) {
            if (preg_match('/<meta[^>]+(?:property|name)=["\']' . preg_quote($prop, '/') . '["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m)
                || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]*(?:property|name)=["\']' . preg_quote($prop, '/') . '["\']/i', $html, $m)) {
                $src = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
                if (str_starts_with($src, '//')) $src = 'https:' . $src;
                elseif (str_starts_with($src, '/')) $src = preg_replace('#^(https?://[^/]+).*$#i', '$1', $pageUrl) . $src;
                return AiVisibilityDetector::safeUrl($src) ? mb_substr($src, 0, 700) : '';
            }
        }
        return '';
    }

    private function sitemapUrls(string $base, string $host, float $deadline): array
    {
        $root = rtrim(preg_replace('#^(https?://[^/]+).*$#i', '$1', $base), '/');
        $queue = [];
        $robots = $this->fetch($root . '/robots.txt');
        if ($robots !== null && preg_match_all('/^\s*sitemap:\s*(\S+)/im', $robots, $m)) $queue = $m[1];
        $queue = array_merge($queue, [$root . '/sitemap.xml', $root . '/sitemap_index.xml', $root . '/wp-sitemap.xml']);
        $pages = [];
        $seen = [];
        $files = 0;
        while ($queue && $files < 10 && microtime(true) < $deadline) {
            $sm = array_shift($queue);
            if (isset($seen[$sm]) || !DataForSeoRankingSource::matchesHost($sm, $host)) continue;
            $seen[$sm] = true;
            $xml = $this->fetch($sm);
            $files++;
            if ($xml === null || !preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $xml, $m)) continue;
            foreach ($m[1] as $loc) {
                $loc = html_entity_decode($loc, ENT_QUOTES | ENT_XML1, 'UTF-8');
                if (preg_match('#\.xml(?:\.gz)?(?:\?|$)#i', $loc)) {
                    // sitemap فرعي: صفحات/منتجات/رحلات الأول
                    if (preg_match(self::TRIP_PATH, $loc) || preg_match('#(?:page|product|post-type|listing)#i', $loc)) {
                        array_unshift($queue, $loc);
                    } else {
                        $queue[] = $loc;
                    }
                } elseif (AiVisibilityDetector::safeUrl($loc)) {
                    $pages[] = $loc;
                }
            }
            if (count($pages) > 3000) break;
        }
        return $pages;
    }

    private function fetch(string $url): ?string
    {
        if (!AiVisibilityDetector::safeUrl($url)) return null;
        try {
            $r = ($this->http)($url);
        } catch (Throwable $e) {
            return null;
        }
        return (($r['code'] ?? 0) === 200 && empty($r['error']) && strlen((string) ($r['body'] ?? '')) < 5000000) ? (string) $r['body'] : null;
    }
}

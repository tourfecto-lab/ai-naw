<?php

/**
 * Tourfecto - SEO: Multi-page Crawl Service (G1)
 * @version 1.0.0
 *
 * يسد فجوة "الزحف الكامل للموقع" (Ahrefs/SEMrush/Screaming Frog) بزحاف
 * نصي خفيف بـ PHP خالص (curl): يبدأ من الصفحة الرئيسية ويمشي بعرضٍ
 * (BFS) على الروابط الداخلية (نفس الدومين فقط) لحد حد أقصى من الصفحات
 * والعمق، ويفحص on-page أساسي لكل URL (title/meta description/H1/عدد
 * الكلمات/كود HTTP) ويحفظ النتائج في `seo_crawl_pages`، ويكشف التكرارات
 * (duplicate titles/H1) ويجمّع مقاييس على مستوى الموقع.
 *
 * المعايير (additive):
 *   - لا ينفّذ JavaScript ولا يحاكي متصفح (خارج النطاق G2 - يحتاج
 *     headless browser).
 *   - لا يزحف إلا روابط نفس الدومين (يحترم حدود النطاق) بتعمّق محدود.
 *   - يفشل بأمان لو الموقع غير قابل للوصول (error موثّق، لا بيانات وهمية).
 *
 * الاختبار: الـ fetcher قابل للحقن لفحص منطق الزحف بدون شبكة حقيقية.
 */
class SeoCrawlerService
{
    /** @var callable|null فاتح صفحات قابل للحقن (اختبارات) */
    private $fetcher;

    public function __construct($fetcher = null)
    {
        $this->fetcher = $fetcher;
    }

    /**
     * زحف الموقع بالكامل (محدود) وتخزين النتائج.
     *
     * @param Database $db
     * @param int      $websiteId
     * @param int      $userId
     * @param array    $opts {max_urls?:int, max_depth?:int, timeout?:int}
     * @return array{success:bool, website:?array, crawl_id:?string, summary:array, pages:array, error:?string}
     */
    public function crawl(Database $db, int $websiteId, int $userId, array $opts = []): array
    {
        $maxUrls = (int) ($opts['max_urls'] ?? 25);
        $maxUrls = max(3, min(100, $maxUrls));
        $maxDepth = (int) ($opts['max_depth'] ?? 3);
        $maxDepth = max(1, min(6, $maxDepth));

        $sites = $db->query(
            "SELECT id, user_id, main_url FROM websites WHERE id = ? AND user_id = ? LIMIT 1",
            [$websiteId, $userId]
        );
        if (empty($sites)) {
            return ['success' => false, 'website' => null, 'crawl_id' => null, 'summary' => [], 'pages' => [], 'error' => 'الموقع غير موجود'];
        }
        $site = $sites[0];
        $mainUrl = trim((string) ($site['main_url'] ?? ''));
        if (!preg_match('#^https?://#i', $mainUrl)) {
            return ['success' => false, 'website' => $site, 'crawl_id' => null, 'summary' => [], 'pages' => [], 'error' => 'رابط الموقع الأساسي غير صالح'];
        }

        $host = strtolower((string) parse_url($mainUrl, PHP_URL_HOST));
        $crawlId = 'crawl_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $base = rtrim($mainUrl, '/');

        // ---------- BFS ----------
        $visited = [];     // normalized url => depth
        $queue = [[$base . '/', 0]];
        $pages = [];
        $checkedAt = date('Y-m-d H:i:s');
        $startWall = microtime(true);
        $timeBudget = (float) ($opts['time_budget'] ?? 90);

        while (!empty($queue) && count($visited) < $maxUrls) {
            if (microtime(true) - $startWall > $timeBudget) {
                break;
            }
            [$url, $depth] = array_shift($queue);
            $norm = self::normalizeUrl($url);
            if ($norm === null || isset($visited[$norm])) {
                continue;
            }
            if (count($visited) >= $maxUrls) {
                break;
            }
            $visited[$norm] = $depth;

            $fetch = $this->fetch($url);
            $html = (string) ($fetch['body'] ?? '');
            $page = [
                'url' => $url,
                'status_code' => (int) ($fetch['code'] ?? 0),
                'depth' => $depth,
                'title' => null,
                'title_length' => null,
                'has_meta_description' => 0,
                'h1_count' => null,
                'h1_text' => null,
                'word_count' => null,
                'http_time_ms' => isset($fetch['time']) ? (int) round($fetch['time'] * 1000) : null,
                'fetch_error' => !empty($fetch['error']) ? mb_substr((string) $fetch['error'], 0, 250) : null,
            ];

            if ($fetch['error'] === null && $html !== '') {
                $page['title'] = self::extractTitle($html);
                $page['title_length'] = $page['title'] !== null ? mb_strlen($page['title']) : null;
                $page['has_meta_description'] = (int) self::hasMetaDescription($html);
                [$page['h1_count'], $page['h1_text']] = self::extractH1($html);
                $page['word_count'] = self::extractWordCount($html);
            }

            $pages[] = $page;

            // اكتشاف الروابط الداخلية (لو لسه في عمق للزحف)
            if ($depth < $maxDepth && ($fetch['error'] === null) && $html !== '') {
                $links = self::extractInternalLinks($html, $url, $host);
                foreach ($links as $link) {
                    $queue[] = [$link, $depth + 1];
                }
            }
        }

        // ---------- تخزين ----------
        $db->exec("DELETE FROM seo_crawl_pages WHERE website_id = ? AND user_id = ?", [$websiteId, $userId]);
        foreach ($pages as $p) {
            $db->query(
                "INSERT INTO seo_crawl_pages
                    (website_id, user_id, crawl_id, url, status_code, depth, title, title_length,
                     has_meta_description, h1_count, h1_text, word_count, http_time_ms, fetch_error, checked_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $websiteId, $userId, $crawlId, $p['url'], $p['status_code'], $p['depth'],
                    $p['title'], $p['title_length'], $p['has_meta_description'], $p['h1_count'],
                    $p['h1_text'], $p['word_count'], $p['http_time_ms'], $p['fetch_error'], $checkedAt,
                ]
            );
        }

        $summary = self::aggregate($pages, $maxUrls);

        return [
            'success' => true,
            'website' => ['id' => (int) $site['id'], 'main_url' => $mainUrl, 'host' => $host],
            'crawl_id' => $crawlId,
            'summary' => $summary,
            'pages' => $pages,
            'error' => null,
        ];
    }

    /**
     * آخر زحف محفوظ لموقع + ملخصه.
     * @return array|null ['crawl_id', 'checked_at', 'pages', 'summary']
     */
    public function lastCrawl(Database $db, int $websiteId, int $userId): ?array
    {
        $rows = $db->query(
            "SELECT crawl_id, MAX(checked_at) AS checked_at FROM seo_crawl_pages
             WHERE website_id = ? AND user_id = ? GROUP BY crawl_id ORDER BY checked_at DESC LIMIT 1",
            [$websiteId, $userId]
        );
        if (empty($rows)) {
            return null;
        }
        $crawlId = $rows[0]['crawl_id'];
        $pages = $db->query(
            "SELECT url, status_code, depth, title, title_length, has_meta_description, h1_count,
                    h1_text, word_count, http_time_ms, fetch_error
             FROM seo_crawl_pages WHERE website_id = ? AND user_id = ? AND crawl_id = ?
             ORDER BY depth ASC, id ASC",
            [$websiteId, $userId, $crawlId]
        );
        return [
            'crawl_id' => $crawlId,
            'checked_at' => $rows[0]['checked_at'],
            'pages' => $pages,
            'summary' => self::aggregate($pages),
        ];
    }

    // ============================================================
    // Helpers (قابلة للاختبار المباشر)
    // ============================================================

    /** طلب HTTP فعلي (curl) أو عبر الـ fetcher المحقون (اختبارات). */
    public function fetch(string $url): array
    {
        if ($this->fetcher !== null) {
            $res = call_user_func($this->fetcher, $url);
            return is_array($res) ? $res : ['body' => null, 'code' => 0, 'error' => 'invalid fetcher', 'time' => 0];
        }
        $start = microtime(true);
        $safe = SafeHttp::exec($url, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'TourfectoCrawler/1.0 (+SEO site crawler)',
        ], 4);
        $body = $safe['response'];
        $code = $safe['code'];
        $error = $safe['error'];
        return ['body' => $body === false ? null : $body, 'code' => $code, 'error' => $error === '' ? null : $error, 'time' => microtime(true) - $start];
    }

    /** تطبيع رابط داخلي: نفس الدومين، بلا anchors/query مكرر، بلا امتدادات ملفات. */
    public static function normalizeUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
        if ($path === '') {
            $path = '/';
        }
        return $scheme . '://' . $host . $port . $path;
    }

    /** استخراج الروابط الداخلية (نفس الدومين) من HTML مع حل المسارات النسبية. */
    public static function extractInternalLinks(string $html, string $baseUrl, string $host): array
    {
        if (!preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\']/i', $html, $m)) {
            return [];
        }
        $links = [];
        foreach ($m[1] as $href) {
            $href = trim($href);
            if ($href === '' || preg_match('#^(mailto:|tel:|data:|javascript:)#i', $href)) {
                continue;
            }
            $resolved = self::resolveUrl($baseUrl, $href);
            if ($resolved === null) {
                continue;
            }
            $linkHost = strtolower((string) parse_url($resolved, PHP_URL_HOST));
            if ($linkHost !== $host) {
                continue; // خارجي - لا نزحف خارج الدومين
            }
            $path = (string) parse_url($resolved, PHP_URL_PATH);
            // تجاهل امتدادات الملفات الثابتة (صور/مستندات) ووسوم الترحيم
            if (preg_match('/\.(png|jpe?g|gif|webp|avif|svg|ico|css|js|pdf|zip|mp4|webm|woff2?)$/i', $path)) {
                continue;
            }
            $normalized = self::normalizeUrl($resolved);
            if ($normalized !== null) {
                $links[$normalized] = true;
            }
        }
        return array_keys($links);
    }

    /** حل رابط نسبي/مطلق مقابل رابط أساسي. */
    public static function resolveUrl(string $base, string $link): ?string
    {
        if (preg_match('#^https?://#i', $link)) {
            return $link;
        }
        $baseParts = parse_url($base);
        if (!$baseParts || empty($baseParts['host'])) {
            return null;
        }
        $scheme = strtolower((string) ($baseParts['scheme'] ?? 'https'));
        $host = (string) $baseParts['host'];
        $port = isset($baseParts['port']) ? ':' . (int) $baseParts['port'] : '';
        if (strpos($link, '//') === 0) {
            return $scheme . ':' . $link;
        }
        if (strpos($link, '/') === 0) {
            return $scheme . '://' . $host . $port . $link;
        }
        $basePath = isset($baseParts['path']) ? (string) $baseParts['path'] : '/';
        $dir = preg_replace('#/[^/]*$#', '/', $basePath);
        if ($dir === '') {
            $dir = '/';
        }
        return $scheme . '://' . $host . $port . $dir . $link;
    }

    public static function extractTitle(string $html): ?string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $t = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            return $t === '' ? null : mb_substr($t, 0, 500);
        }
        return null;
    }

    public static function hasMetaDescription(string $html): bool
    {
        return (bool) preg_match('/<meta\s+[^>]*name=["\']description["\'][^>]*content=["\'][^"\']+/i', $html);
    }

    /** @return array{0:int,1:?string} [عدد H1، نص أول H1] */
    public static function extractH1(string $html): array
    {
        if (preg_match_all('/<h1\b[^>]*>(.*?)<\/h1>/is', $html, $m)) {
            $text = trim(html_entity_decode(strip_tags($m[1][0]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            return [count($m[0]), $text === '' ? null : mb_substr($text, 0, 500)];
        }
        return [0, null];
    }

    /** عدد كلمات النص المرئي (بعد إزالة script/style). */
    public static function extractWordCount(string $html): int
    {
        $body = '';
        if (preg_match('/<body[^>]*>(.*)<\/body>/is', $html, $bm)) {
            $body = $bm[1];
        }
        $text = preg_replace('/<(script|style|noscript)\b[^>]*>.*?<\/\1>/is', ' ', $body);
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)));
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        return count($words);
    }

    /** تجميع مقاييس الزحف من صفحاته. */
    public static function aggregate(array $pages, int $maxUrls = 25): array
    {
        $ok = 0;
        $failed = 0;
        $missingMeta = 0;
        $missingH1 = 0;
        $avgTime = 0.0;
        $wordCounts = [];
        $titles = [];
        $h1s = [];

        foreach ($pages as $p) {
            $code = (int) ($p['status_code'] ?? 0);
            if ($code >= 200 && $code < 400) {
                $ok++;
            } else {
                $failed++;
            }
            if (!empty($p['fetch_error'])) {
                $failed++;
            }
            if (!$p['has_meta_description']) {
                $missingMeta++;
            }
            if ($p['h1_count'] === 0) {
                $missingH1++;
            }
            if (!empty($p['http_time_ms'])) {
                $avgTime += (float) $p['http_time_ms'];
            }
            if (is_numeric($p['word_count'])) {
                $wordCounts[] = (int) $p['word_count'];
            }
            if (!empty($p['title'])) {
                $titles[strtolower((string) $p['title'])][] = (string) $p['url'];
            }
            if (!empty($p['h1_text'])) {
                $h1s[strtolower((string) $p['h1_text'])][] = (string) $p['url'];
            }
        }

        $duplicateTitles = array_values(array_filter(
            $titles,
            static fn ($urls) => count($urls) > 1
        ));

        $duplicateH1 = array_values(array_filter(
            $h1s,
            static fn ($urls) => count($urls) > 1
        ));

        return [
            'pages_checked' => count($pages),
            'pages_limit' => $maxUrls,
            'pages_ok' => $ok,
            'pages_with_errors' => $failed,
            'pages_missing_meta_description' => $missingMeta,
            'pages_missing_h1' => $missingH1,
            'duplicate_titles' => count($duplicateTitles),
            'duplicate_h1' => count($duplicateH1),
            'avg_response_ms' => count($pages) > 0 ? (int) round($avgTime / count($pages)) : 0,
            'avg_word_count' => count($wordCounts) > 0 ? (int) round(array_sum($wordCounts) / count($wordCounts)) : 0,
        ];
    }
}

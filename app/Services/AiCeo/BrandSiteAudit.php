<?php
require_once __DIR__ . '/../Brain/BrainSchema.php';

/**
 * TOURFECTO AI CEO (المرحلة 5) — فحص SEO/AEO لموقع Tourfecto نفسه (مش مواقع العملاء).
 *
 * بيقرا robots.txt و sitemap للموقع (brand_site_url في system_settings، وإلا APP_URL)، وبيفحص لحد 40 صفحة:
 * حالة الصفحة والسرعة، العنوان والوصف، H1، canonical، noindex، البيانات المنظمة (JSON-LD)، Open Graph، hreflang،
 * حجم المحتوى، والعناوين المكررة. وعلى مستوى الموقع: sitemap، و robots، و llms.txt (للظهور في إجابات الذكاء الاصطناعي).
 * مفيش ذكاء اصطناعي ولا تكلفة. الروابط ثابتة (دومين المنصة نفسها) مش من مستخدم، وبنتبع التحويلات على نفس الدومين بس.
 * كل فحص بيتحفظ في aiceo_brand_audits عشان التحسن يتقاس.
 */
final class BrandSiteAudit
{
    public const MAX_PAGES = 60;
    private const WEIGHTS = ['critical' => 25, 'warn' => 8, 'info' => 2];

    private Database $db;
    /** @var callable fn(string $url): array{code:int, body:string, ms:int} */
    private $fetch;

    public function __construct(?Database $db = null, ?callable $fetch = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->fetch = $fetch ?? [self::class, 'httpGet'];
        BrainSchema::ensure($this->db);
    }

    public function siteUrl(): string
    {
        $v = $this->db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'brand_site_url' LIMIT 1")[0]['setting_value'] ?? '';
        $url = trim((string) ($v ?: (defined('APP_URL') ? APP_URL : 'https://tourfecto.pro')));
        return rtrim(preg_match('#^https?://#', $url) ? $url : 'https://' . $url, '/');
    }

    public function run(): array
    {
        $base = $this->siteUrl();
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        $siteIssues = [];
        $robots = ($this->fetch)($base . '/robots.txt');
        $sitemaps = [];
        if ($robots['code'] === 200 && preg_match_all('/^\s*Sitemap:\s*(\S+)/mi', $robots['body'], $m)) $sitemaps = $m[1];
        if (!$sitemaps) {
            if ($robots['code'] === 200) $siteIssues[] = ['warn', 'robots_no_sitemap', 'robots.txt does not point to a sitemap'];
            $sitemaps = [$base . '/sitemap.xml'];
        }
        $urls = [];
        foreach (array_slice($sitemaps, 0, 3) as $sm) $urls = array_merge($urls, $this->sitemapUrls($sm, $host));
        $urls = array_values(array_unique(array_filter($urls, static fn ($u) => strtolower((string) parse_url($u, PHP_URL_HOST)) === $host)));
        if (!$urls) $siteIssues[] = ['critical', 'sitemap_missing', 'No sitemap URLs found (search engines and AI crawlers rely on it)'];
        array_unshift($urls, $base . '/');
        $urls = array_slice(array_values(array_unique($urls)), 0, self::MAX_PAGES);
        $llms = ($this->fetch)($base . '/llms.txt');
        if ($llms['code'] !== 200) $siteIssues[] = ['info', 'llms_txt_missing', 'No /llms.txt (a short guide that helps AI answer engines describe Tourfecto)'];

        $pages = [];
        foreach ($urls as $u) {
            $r = ($this->fetch)($u);
            $page = self::analyzePage($u, $r['code'], $r['body'], $r['ms'], $host);
            // صفحة في السايت ماب بتحوّل لصفحة تانية: المفروض السايت ماب يحط الصفحة النهائية
            if (!empty($r['redirects'])) array_unshift($page['issues'], ['warn', 'redirect_in_sitemap', 'Listed in the sitemap but redirects to another page: list the final page instead']);
            $pages[] = $page;
        }
        // عناوين مكررة على أكتر من صفحة (الصفحات اللي بتحوّل أو الـ canonical بتاعها صفحة تانية مش بتتحسب: مش هي اللي بتتفهرس)
        $titles = [];
        foreach ($pages as $p) {
            $skip = (bool) array_filter($p['issues'], static fn ($i) => in_array($i[1], ['redirect_in_sitemap', 'canonical_elsewhere'], true));
            if ($p['title'] !== '' && !$skip) $titles[mb_strtolower($p['title'])][] = $p['url'];
        }
        foreach ($titles as $t => $list) if (count($list) > 1) $siteIssues[] = ['warn', 'duplicate_title', count($list) . ' pages share the title "' . mb_substr($t, 0, 60) . '"'];

        $summary = self::summarize($pages, $siteIssues);
        $this->db->query('INSERT INTO aiceo_brand_audits (site_url, score, summary_json, pages_json, run_at) VALUES (?, ?, ?, ?, ?)',
            [$base, $summary['score'], json_encode($summary, JSON_UNESCAPED_UNICODE), json_encode($pages, JSON_UNESCAPED_UNICODE), gmdate('Y-m-d H:i:s')]);
        return ['site_url' => $base, 'summary' => $summary, 'pages' => $pages];
    }

    private function sitemapUrls(string $url, string $host, int $depth = 0): array
    {
        if (strtolower((string) parse_url($url, PHP_URL_HOST)) !== $host) return [];
        $r = ($this->fetch)($url);
        if ($r['code'] !== 200 || !preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $r['body'], $m)) return [];
        $locs = array_map('html_entity_decode', $m[1]);
        if (stripos($r['body'], '<sitemapindex') !== false && $depth === 0) {
            $out = [];
            foreach (array_slice($locs, 0, 5) as $child) $out = array_merge($out, $this->sitemapUrls($child, $host, 1));
            return $out;
        }
        return $locs;
    }

    /** تحليل صفحة واحدة (منطق نقي، بيتختبر لوحده) */
    public static function analyzePage(string $url, int $code, string $html, int $ms, string $host): array
    {
        $issues = [];
        $get = static fn (string $re) => preg_match($re, $html, $m) ? html_entity_decode(trim(strip_tags($m[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
        $title = $get('#<title[^>]*>(.*?)</title>#is');
        $desc = $get('#<meta[^>]+name=["\']description["\'][^>]*content=["\']([^"\']*)#i') ?: $get('#<meta[^>]+content=["\']([^"\']*)["\'][^>]*name=["\']description#i');
        $canonical = $get('#<link[^>]+rel=["\']canonical["\'][^>]*href=["\']([^"\']+)#i') ?: $get('#<link[^>]+href=["\']([^"\']+)["\'][^>]*rel=["\']canonical#i');
        $robots = strtolower($get('#<meta[^>]+name=["\']robots["\'][^>]*content=["\']([^"\']*)#i'));
        $h1 = preg_match_all('#<h1[\s>]#i', $html);
        preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $ld);
        $types = [];
        foreach ($ld[1] ?? [] as $json) if (preg_match_all('#"@type"\s*:\s*"([^"]+)"#', $json, $tm)) $types = array_merge($types, $tm[1]);
        $og = (bool) preg_match('#<meta[^>]+property=["\']og:title#i', $html) && (bool) preg_match('#<meta[^>]+property=["\']og:image#i', $html);
        $hreflang = preg_match_all('#<link[^>]+hreflang=#i', $html);
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags(preg_replace('#<(script|style|noscript)[^>]*>.*?</\1>#is', ' ', $html))));
        $words = $text === '' ? 0 : count(preg_split('/\s+/u', $text));
        preg_match_all('#<a[^>]+href=["\']([^"\'\#]+)#i', $html, $links);
        $internal = 0;
        foreach ($links[1] ?? [] as $l) if (str_starts_with($l, '/') || strtolower((string) parse_url($l, PHP_URL_HOST)) === $host) $internal++;

        if ($code === 0 || $code >= 400) $issues[] = ['critical', 'http_error', 'HTTP ' . $code];
        else {
            if ($ms > 3000) $issues[] = ['warn', 'slow', 'Took ' . round($ms / 1000, 1) . 's to load'];
            if (str_contains($robots, 'noindex')) $issues[] = ['critical', 'noindex', 'Page tells search engines not to index it'];
            if ($title === '') $issues[] = ['critical', 'title_missing', 'No <title>'];
            elseif (mb_strlen($title) < 15 || mb_strlen($title) > 65) $issues[] = ['warn', 'title_length', 'Title is ' . mb_strlen($title) . ' characters (best 15–65)'];
            if ($desc === '') $issues[] = ['warn', 'meta_desc_missing', 'No meta description'];
            elseif (mb_strlen($desc) < 50 || mb_strlen($desc) > 160) $issues[] = ['warn', 'meta_desc_length', 'Meta description is ' . mb_strlen($desc) . ' characters (best 50–160)'];
            if ($h1 === 0) $issues[] = ['warn', 'h1_missing', 'No H1 heading'];
            elseif ($h1 > 1) $issues[] = ['info', 'h1_multiple', $h1 . ' H1 headings'];
            if ($canonical === '') $issues[] = ['warn', 'canonical_missing', 'No canonical link'];
            elseif (($ch = strtolower((string) parse_url($canonical, PHP_URL_HOST))) !== '' && $ch !== $host) $issues[] = ['critical', 'canonical_other_host', 'Canonical points to ' . $ch];
            elseif (rtrim((string) parse_url($canonical, PHP_URL_PATH), '/') !== rtrim((string) parse_url($url, PHP_URL_PATH), '/')) $issues[] = ['warn', 'canonical_elsewhere', 'Canonical is ' . parse_url($canonical, PHP_URL_PATH) . ': this URL should not be in the sitemap'];
            if (!$types) $issues[] = ['info', 'structured_data_missing', 'No JSON-LD structured data (helps rich results and AI answers)'];
            if (!$og) $issues[] = ['info', 'open_graph_missing', 'No og:title/og:image (link previews on social)'];
            if ($words < 250) $issues[] = ['warn', 'thin_content', 'Only ' . $words . ' words of text'];
        }
        return ['url' => $url, 'code' => $code, 'ms' => $ms, 'title' => mb_substr($title, 0, 160), 'description_length' => mb_strlen($desc), 'h1' => $h1, 'canonical' => $canonical,
            'noindex' => str_contains($robots, 'noindex'), 'structured_data' => array_values(array_unique($types)), 'open_graph' => $og, 'hreflang' => $hreflang, 'words' => $words, 'internal_links' => $internal, 'issues' => $issues];
    }

    public static function summarize(array $pages, array $siteIssues): array
    {
        $count = [];
        $penalty = 0;
        foreach ($pages as $p) foreach ($p['issues'] as [$sev, $code]) { $count[$code] = ($count[$code] ?? 0) + 1; $penalty += self::WEIGHTS[$sev] ?? 0; }
        $sitePenalty = 0;
        foreach ($siteIssues as [$sev, $code]) { $count[$code] = ($count[$code] ?? 0) + 1; $sitePenalty += self::WEIGHTS[$sev] ?? 0; }
        $n = max(1, count($pages));
        $score = max(0, min(100, (int) round(100 - $penalty / $n - $sitePenalty)));
        $ok = array_filter($pages, static fn ($p) => $p['code'] > 0 && $p['code'] < 400);
        return ['score' => $score, 'pages' => count($pages), 'ok_pages' => count($ok), 'avg_ms' => $ok ? (int) round(array_sum(array_column($ok, 'ms')) / count($ok)) : null,
            'issue_counts' => $count, 'site_issues' => $siteIssues, 'critical' => array_sum(array_map(static fn ($p) => count(array_filter($p['issues'], static fn ($i) => $i[0] === 'critical')), $pages)) + count(array_filter($siteIssues, static fn ($i) => $i[0] === 'critical'))];
    }

    public function latest(): ?array
    {
        $r = $this->db->query('SELECT * FROM aiceo_brand_audits ORDER BY id DESC LIMIT 1')[0] ?? null;
        if (!$r) return null;
        $prev = $this->db->query('SELECT score, run_at FROM aiceo_brand_audits WHERE id < ? ORDER BY id DESC LIMIT 1', [(int) $r['id']])[0] ?? null;
        return ['site_url' => $r['site_url'], 'run_at' => $r['run_at'], 'score' => $r['score'] !== null ? (int) $r['score'] : null, 'previous_score' => $prev ? (int) $prev['score'] : null,
            'summary' => json_decode((string) $r['summary_json'], true), 'pages' => json_decode((string) $r['pages_json'], true)];
    }

    public static function httpGet(string $url): array
    {
        $started = microtime(true);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $code = 0;
        $body = '';
        $redirects = 0;
        for ($hop = 0; $hop < 4; $hop++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 12, CURLOPT_MAXFILESIZE => 3000000,
                CURLOPT_HEADER => false, CURLOPT_USERAGENT => 'TourfectoBrandAudit/1.0 (+https://tourfecto.pro)', CURLOPT_HTTPHEADER => ['Accept: text/html,application/xml,text/plain']]);
            $body = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $next = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);
            // بنتبع التحويل على نفس الدومين بس
            if ($code >= 300 && $code < 400 && $next !== '' && strtolower((string) parse_url($next, PHP_URL_HOST)) === $host) { $url = $next; $redirects++; continue; }
            break;
        }
        return ['code' => $code, 'body' => mb_substr($body, 0, 3000000), 'ms' => (int) round((microtime(true) - $started) * 1000), 'redirects' => $redirects];
    }
}

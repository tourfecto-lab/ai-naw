<?php

/**
 * مدونة مستضافة عندنا لعملاء Wix / Squarespace / أي موقع مالوش API نشر.
 *  - بتشتغل فورًا على tourfecto.pro/stories/{handle}
 *  - واختياري على دومين فرعي بتاع العميل (blog.example.com): العميل بيعمل TXT + CNAME، إحنا بنتأكد،
 *    والأدمن بيربط الدومين في aaPanel ويطلّع SSL (نفس نظام الدومينات المخصصة للمواقع المبنية) وبعدين «اتربط».
 * النشر بيمشي من نفس مسارات النشر الموجودة (يدوي/مجدول/الأتمتة) كـ custom_api بعنوان hosted://{websiteId}.
 * المقال بيتخزن نسخة (snapshot) وقت النشر، وإعادة النشر بتحدّثها.
 */
class HostedBlogService
{
    private static function L(string $ar, string $en): string
    {
        return function_exists('current_lang') && !str_starts_with(current_lang(), 'ar') ? $en : $ar;
    }

    private Database $db;
    private static bool $ready = false;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->ensureTables();
    }

    private function ensureTables(): void
    {
        if (self::$ready) return;
        $pdo = $this->db->getConnection();
        $pdo->exec("CREATE TABLE IF NOT EXISTS hosted_blogs (
            website_id BIGINT NOT NULL PRIMARY KEY,
            user_id BIGINT NOT NULL,
            handle VARCHAR(60) NOT NULL,
            domain VARCHAR(253) NULL,
            domain_token VARCHAR(64) NULL,
            domain_state ENUM('none','pending_dns','verified','live') NOT NULL DEFAULT 'none',
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_handle (handle),
            UNIQUE KEY uq_domain (domain)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS hosted_blog_posts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            website_id BIGINT NOT NULL,
            article_id INT NULL,
            slug VARCHAR(190) NOT NULL,
            lang VARCHAR(10) NOT NULL DEFAULT 'en',
            title VARCHAR(500) NOT NULL,
            excerpt VARCHAR(500) NULL,
            html LONGTEXT NOT NULL,
            image_url VARCHAR(500) NULL,
            published_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_site_slug (website_id, slug),
            KEY idx_site_date (website_id, published_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::$ready = true;
    }

    public static function endpoint(int $websiteId): string
    {
        return 'hosted://' . $websiteId;
    }

    public function get(int $websiteId): ?array
    {
        $r = $this->db->query('SELECT * FROM hosted_blogs WHERE website_id = ? LIMIT 1', [$websiteId]);
        return $r[0] ?? null;
    }

    public function byHandle(string $handle): ?array
    {
        $r = $this->db->query('SELECT * FROM hosted_blogs WHERE handle = ? LIMIT 1', [strtolower($handle)]);
        return $r[0] ?? null;
    }

    /** للدومين الفرعي بتاع العميل (بعد ما الأدمن يربطه) */
    public function byDomain(string $host): ?array
    {
        $host = preg_replace('/^www\./', '', strtolower($host));
        if ($host === '') return null;
        $r = $this->db->query("SELECT * FROM hosted_blogs WHERE domain = ? AND domain_state IN ('verified','live') LIMIT 1", [$host]);
        return $r[0] ?? null;
    }

    /** المدونة شغالة على دومينها؟ (اللينكات والـcanonical بتبقى عليه) */
    public static function isLiveDomain(array $blog): bool
    {
        return ($blog['domain_state'] ?? '') === 'live' && !empty($blog['domain']);
    }

    public static function baseUrl(array $blog): string
    {
        if (self::isLiveDomain($blog)) return 'https://' . $blog['domain'];
        return rtrim(defined('APP_URL') ? (string) APP_URL : '', '/') . '/stories/' . $blog['handle'];
    }

    public function activate(int $userId, int $websiteId, string $mainUrl): array
    {
        if ($b = $this->get($websiteId)) return $b;
        $host = preg_replace('/^www\./', '', strtolower((string) parse_url($mainUrl, PHP_URL_HOST)));
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', explode('.', $host)[0] ?: 'blog'), '-') ?: 'blog';
        $base = substr($base, 0, 40);
        $handle = $base;
        for ($i = 2; $this->byHandle($handle); $i++) $handle = $base . '-' . $i;
        $this->db->query('INSERT INTO hosted_blogs (website_id, user_id, handle) VALUES (?, ?, ?)', [$websiteId, $userId, $handle]);
        return $this->get($websiteId);
    }

    /** بيتنادي من CmsPublisher لما الموقع مربوط بـ hosted:// */
    public function publish(int $websiteId, array $a, bool $isTest = false): array
    {
        $blog = $this->get($websiteId);
        if (!$blog) return ['success' => false, 'error' => self::L('المدونة مش مفعّلة', 'The hosted blog is not active')];
        if ($isTest) return ['success' => true, 'url' => self::baseUrl($blog)];
        $title = trim((string) ($a['title'] ?? ''));
        $html = self::clean((string) ($a['content_html'] ?? ''));
        if ($title === '' || trim(strip_tags($html)) === '') return ['success' => false, 'error' => self::L('المقال فاضي', 'The article is empty')];
        $slug = self::slug((string) ($a['slug'] ?? '') ?: $title);
        $articleId = (int) ($a['article_id'] ?? 0);
        $lang = 'en';
        $image = null;
        if ($articleId > 0) {
            $row = $this->db->query('SELECT * FROM ai_articles WHERE id = ? AND website_id = ? LIMIT 1', [$articleId, $websiteId])[0] ?? null;
            if ($row) {
                $lang = preg_match('/^[a-z]{2}/', (string) $row['target_language'], $m) ? $m[0] : 'en';
                if (!empty($row['featured_image_path']) && class_exists('ArticleImageService')) $image = ArticleImageService::publicUrl($row['featured_image_path']);
            }
            // نفس المقال اتنشر قبل كده بـslug مختلف: نحدّثه بدل ما نكرّره
            $prev = $this->db->query('SELECT slug FROM hosted_blog_posts WHERE website_id = ? AND article_id = ? LIMIT 1', [$websiteId, $articleId]);
            if ($prev) $slug = $prev[0]['slug'];
        } elseif (preg_match('/\p{Arabic}/u', $title)) {
            $lang = 'ar';
        }
        $taken = $this->db->query('SELECT article_id FROM hosted_blog_posts WHERE website_id = ? AND slug = ? LIMIT 1', [$websiteId, $slug]);
        if ($taken && (int) $taken[0]['article_id'] !== $articleId) $slug = substr($slug, 0, 180) . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
        $this->db->query(
            'INSERT INTO hosted_blog_posts (website_id, article_id, slug, lang, title, excerpt, html, image_url, published_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE title = VALUES(title), excerpt = VALUES(excerpt), html = VALUES(html), image_url = COALESCE(VALUES(image_url), image_url), lang = VALUES(lang), updated_at = NOW()',
            [$websiteId, $articleId ?: null, $slug, $lang, mb_substr($title, 0, 500), mb_substr((string) ($a['meta_description'] ?? ''), 0, 500), $html, $image]
        );
        return ['success' => true, 'url' => self::baseUrl($blog) . '/' . rawurlencode($slug)];
    }

    public function posts(int $websiteId, int $page = 1, int $per = 12): array
    {
        $off = max(0, $page - 1) * $per;
        return $this->db->query('SELECT slug, lang, title, excerpt, image_url, published_at, updated_at, CHAR_LENGTH(html) AS len FROM hosted_blog_posts WHERE website_id = ? ORDER BY published_at DESC, id DESC LIMIT ' . (int) $per . ' OFFSET ' . (int) $off, [$websiteId]);
    }

    public function count(int $websiteId): int
    {
        return (int) ($this->db->query('SELECT COUNT(*) c FROM hosted_blog_posts WHERE website_id = ?', [$websiteId])[0]['c'] ?? 0);
    }

    public function post(int $websiteId, string $slug): ?array
    {
        $r = $this->db->query('SELECT * FROM hosted_blog_posts WHERE website_id = ? AND slug = ? LIMIT 1', [$websiteId, $slug]);
        return $r[0] ?? null;
    }

    /** دومين فرعي للمدونة: بنسجله ونطلع للعميل سجلات DNS المطلوبة */
    public function setDomain(int $websiteId, string $domain): array
    {
        $blog = $this->get($websiteId);
        if (!$blog) throw new RuntimeException(self::L('فعّل المدونة الأول', 'Activate the blog first'));
        $domain = CustomDomainService::normalize($domain);
        if ($domain === '') {
            $this->db->query("UPDATE hosted_blogs SET domain = NULL, domain_token = NULL, domain_state = 'none' WHERE website_id = ?", [$websiteId]);
            $this->relink($websiteId);
            return $this->get($websiteId);
        }
        $other = $this->db->query('SELECT website_id FROM hosted_blogs WHERE domain = ? AND website_id <> ? LIMIT 1', [$domain, $websiteId]);
        $site = $this->db->query('SELECT id FROM site_custom_domains WHERE domain = ? LIMIT 1', [$domain]);
        if ($other || $site) throw new RuntimeException(self::L('الدومين ده مربوط بحاجة تانية', 'This domain is already in use'));
        if ($blog['domain'] !== $domain) {
            $this->db->query("UPDATE hosted_blogs SET domain = ?, domain_token = ?, domain_state = 'pending_dns' WHERE website_id = ?", [$domain, bin2hex(random_bytes(16)), $websiteId]);
            $this->relink($websiteId);
        }
        return $this->get($websiteId);
    }

    public static function dnsRecords(array $blog): array
    {
        if (empty($blog['domain'])) return [];
        $target = (string) parse_url(defined('APP_URL') ? APP_URL : '', PHP_URL_HOST);
        return [
            ['type' => 'CNAME', 'name' => $blog['domain'], 'value' => $target],
            ['type' => 'TXT', 'name' => '_tourfecto-verification.' . $blog['domain'], 'value' => 'tourfecto=' . $blog['domain_token']],
        ];
    }

    /** بنتأكد من الـTXT (ملكية) والـCNAME (التوجيه) */
    public function verifyDomain(int $websiteId): array
    {
        $blog = $this->get($websiteId);
        if (!$blog || empty($blog['domain'])) throw new RuntimeException(self::L('ضيف الدومين الأول', 'Add the domain first'));
        if (in_array($blog['domain_state'], ['verified', 'live'], true)) return $blog;
        $want = 'tourfecto=' . $blog['domain_token'];
        $txt = false;
        foreach ((@dns_get_record('_tourfecto-verification.' . $blog['domain'], DNS_TXT) ?: []) as $r) if (hash_equals($want, (string) ($r['txt'] ?? ''))) $txt = true;
        if (!$txt) throw new RuntimeException(self::L('سجل TXT لسه مش ظاهر — ممكن ياخد لحد ساعة بعد ما تضيفه', 'TXT record not found yet — it can take up to an hour after adding it'));
        $target = strtolower((string) parse_url(defined('APP_URL') ? APP_URL : '', PHP_URL_HOST));
        $cname = false;
        foreach ((@dns_get_record($blog['domain'], DNS_CNAME) ?: []) as $r) if (rtrim(strtolower((string) ($r['target'] ?? '')), '.') === $target) $cname = true;
        if (!$cname) {
            // بعض مزودي DNS (Cloudflare proxy مثلًا) بيرجعوا A بدل CNAME: نقبل لو بيشاور على نفس السيرفر
            $ours = gethostbynamel($target) ?: [];
            foreach ((@dns_get_record($blog['domain'], DNS_A) ?: []) as $r) if (in_array($r['ip'] ?? '', $ours, true)) $cname = true;
        }
        if (!$cname) throw new RuntimeException(self::L('سجل CNAME لسه مش بيشاور على ', 'CNAME record does not point to ') . $target);
        $this->db->query("UPDATE hosted_blogs SET domain_state = 'verified' WHERE website_id = ?", [$websiteId]);
        try {
            if (class_exists('Notification')) {
                foreach ($this->db->query("SELECT id FROM users WHERE role IN ('admin','super_admin') LIMIT 5") as $adm) {
                    Notification::notify((int) $adm['id'], 'hosted_blog_domain', 'دومين مدونة محتاج ربط', $blog['domain'] . ' ← ضيفه في aaPanel وطلّع SSL، وبعدين اضغط «اتربط»', '/admin/hosted-blogs');
                }
            }
        } catch (Throwable $e) {
        }
        return $this->get($websiteId);
    }

    /** الأدمن: الدومينات اللي اتأكدت ومستنية ربط في aaPanel */
    public function adminList(): array
    {
        return $this->db->query("SELECT b.website_id, b.handle, b.domain, b.domain_state, b.updated_at, w.`" . WebsiteSchema::url() . "` AS main_url, u.email
            FROM hosted_blogs b JOIN websites w ON w.id = b.website_id LEFT JOIN users u ON u.id = b.user_id
            WHERE b.domain IS NOT NULL ORDER BY b.domain_state = 'verified' DESC, b.updated_at DESC LIMIT 200");
    }

    public function markLive(int $websiteId, bool $live): void
    {
        $this->db->query("UPDATE hosted_blogs SET domain_state = ? WHERE website_id = ? AND domain IS NOT NULL AND domain_state IN ('verified','live')", [$live ? 'live' : 'verified', $websiteId]);
        $this->relink($websiteId);
    }

    /** لما رابط المدونة يتغير: نحدّث روابط المقالات المنشورة عليها */
    private function relink(int $websiteId): void
    {
        $blog = $this->get($websiteId);
        if (!$blog) return;
        $base = self::baseUrl($blog);
        foreach ($this->db->query('SELECT article_id, slug FROM hosted_blog_posts WHERE website_id = ? AND article_id IS NOT NULL', [$websiteId]) as $p) {
            $this->db->query("UPDATE ai_articles SET published_url = ? WHERE id = ? AND status = 'published'", [$base . '/' . rawurlencode($p['slug']), (int) $p['article_id']]);
        }
    }

    public static function slug(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s);
        $s = trim(mb_substr($s, 0, 120), '-');
        return $s !== '' ? $s : 'post-' . substr(bin2hex(random_bytes(3)), 0, 6);
    }

    /** HTML المقال: بنشيل أي سكربت/أحداث/روابط javascript (المحتوى مولّد، بس الصفحة على دومين المنصة) */
    public static function clean(string $html): string
    {
        $html = preg_replace('~<(script|style|iframe|object|embed|form|input|button|textarea|select|link|meta|base)\b[^>]*>.*?</\1\s*>~is', '', $html);
        $html = preg_replace('~<(script|style|iframe|object|embed|form|input|button|textarea|select|link|meta|base)\b[^>]*/?>~i', '', $html);
        $html = preg_replace('~\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)~i', '', $html);
        $html = preg_replace('~(href|src)\s*=\s*(["\']?)\s*(javascript|data|vbscript):[^"\'>\s]*\2~i', '$1="#"', $html);
        return (string) $html;
    }
}

<?php

/**
 * Tourfecto - SEO Server-Side Proxy (Edge Rewriting)
 * @version 1.0.0
 *
 * التنفيذ التلقائي الحقيقي Server-Side: بدل الاعتماد على embed.js اللي
 * بيشتغل في المتصفح بس (واللي روبوتات الزحف ممكن تتجاهله)، الخدمة دي
 * بتجيب صفحة الموقع الأصلي وتعيد كتابة الـ HTML على مستوى السيرفر قبل
 * ما ترجع للمستخدم/للروبوت. النتيجة: جوجل وChatGPT وكل الزوّار بيشوفوا
 * النسخة المحسّنة مباشرة - مش مجرد حقن مؤقت في المتصفح.
 *
 * ده نفس اللي بتعمله Cloudflare (HTML Rewriter) وSearchPilot (SEO A/B)،
 * بس معمول للمواقع الخارجية من غير ما العميل يلمس سيرفر الاستضافة.
 */
class SeoProxyService
{
    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * عرض صفحة موقع مربوط بالمنصة بعد إعادة كتابتها Server-Side.
     *
     * @param string $embedToken توكن السكربت العام (من connectWebsite)
     * @param string $path المسار المطلوب (افتراضي '/')
     * @return array ['status'=>int, 'content_type'=>string, 'body'=>string]
     */
    public function render(string $embedToken, string $path = '/'): array
    {
        $sites = $this->db->query(
            "SELECT id, main_url, company_name, auto_pilot_mode, indexnow_key FROM websites
              WHERE embed_token = ? AND is_connected = 1 LIMIT 1",
            [$embedToken]
        );
        if (empty($sites)) {
            return ['status' => 404, 'content_type' => 'text/plain', 'body' => 'Tourfecto: site not connected'];
        }

        return $this->renderSite($sites[0], $path);
    }

    /**
     * خدمة صفحة/ملف لموقع معيّن بعد إعادة كتابته Server-Side.
     * بيُستخدم من render() (عبر embed_token) ومن وضع CNAME (عبر Host header).
     */
    public function renderSite(array $site, string $path = '/'): array
    {
        $origin = rtrim((string) $site['main_url'], '/');

        // ملف مفتاح IndexNow (بروتوكول IndexNow بيطلب وجود الملف على جذر
        // الدومين عشان يتحقق من ملكية المفتاح): بنخدمه Server-Side هنا.
        if (!empty($site['indexnow_key']) && $path === '/' . $site['indexnow_key'] . '.txt') {
            return ['status' => 200, 'content_type' => 'text/plain; charset=utf-8', 'body' => (string) $site['indexnow_key']];
        }

        // ملفات خاصة بيتم خدمتها Server-Side من غير ما نتعدى على الأصل
        if ($path === '/robots.txt' || $path === '/llms.txt' || $path === '/llms-full.txt' || $path === '/sitemap.xml') {
            return $this->serveAuxFile($site, $path, $origin);
        }

        // حماية من الحلقة: الـ origin لازم يكون موقع العميل مش المنصة نفسها
        if (self::isPlatformHost((string) (parse_url($origin, PHP_URL_HOST) ?? ''))) {
            return ['status' => 421, 'content_type' => 'text/plain; charset=utf-8', 'body' => 'Tourfecto proxy: origin points to the platform itself'];
        }

        $originUrl = $origin . $path;
        $fetched = $this->fetch($originUrl);
        if ($fetched['body'] === null) {
            return ['status' => $fetched['code'] ?: 502, 'content_type' => 'text/html', 'body' => 'Tourfecto proxy: origin unreachable (' . ($fetched['error'] ?: "HTTP {$fetched['code']}") . ')'];
        }

        $html = $this->rewrite($site['id'], (string) $fetched['body'], $originUrl, $_SERVER['HTTP_USER_AGENT'] ?? null);

        return ['status' => 200, 'content_type' => 'text/html; charset=utf-8', 'body' => $html];
    }

    /**
     * إعادة كتابة الـ HTML Server-Side بناء على الإصلاحات المعتمدة.
     *
     * @param string|null $userAgent لتوزيع نسخ تجارب SEO A/B بشكل حتمي
     */
    public static function sameHomepage(string $pageUrl,string $homepage): bool
    {
        return strtolower((string)parse_url($pageUrl,PHP_URL_HOST))===strtolower((string)parse_url($homepage,PHP_URL_HOST)) && rtrim((string)parse_url($pageUrl,PHP_URL_PATH),'/')===rtrim((string)parse_url($homepage,PHP_URL_PATH),'/');
    }

    public function rewrite(int $websiteId, string $html, string $pageUrl, ?string $userAgent = null): string
    {
        $fixes = $this->db->query(
            "SELECT field_name, injected_code FROM auto_seo_applied_fixes
              WHERE website_id = ? AND is_active = 1",
            [$websiteId]
        );

        // SEO A/B Testing: أي تجربة نشطة على حقل معيّن بتاخد أولوية على
        // القيمة الثابتة من auto_seo_applied_fixes (توزيع حتمي لكل صفحة).
        $abService = new SeoAbTestService($this->db);

        foreach ($fixes as $fix) {
            $field = (string) $fix['field_name'];
            $code  = (string) $fix['injected_code'];

            $variant = $abService->pickVariant($websiteId, $field, $pageUrl, $userAgent);
            if ($variant !== null) {
                $code = $variant['value'];
            }

            $html = $this->applyRewrite($html, $field, $code, $pageUrl, $websiteId);
        }

        $active=CompetitorSiteExecutionService::activePatch($websiteId);
        if ($active && self::sameHomepage($pageUrl,$active['url'])) $html=CompetitorSiteExecutionService::rewrite($html,$active['patch']);

        // علامة صغيرة بتوضح إن الصفحة معدّلة Server-Side (للتصحيح مش أكتر)
        if (stripos($html, 'tourfecto-seo-proxy') === false) {
            $html = str_ireplace(
                '</head>',
                "<!-- tourfecto-seo-proxy: optimized server-side -->\n</head>",
                $html
            );
        }

        return $html;
    }

    /**
     * دومينات المنصة نفسها (APP_URL + اسم الـ vhost الفعلي + PLATFORM_HOSTS في .env).
     * الـ proxy ممنوع يمسك أي طلب عليها، وإلا هيحوّل المنصة لنفسها ويفضل يلف
     * (طلب -> proxy -> نفس الدومين -> proxy ...) لحد ما يطلع "origin unreachable
     * (Operation timed out after 20002 milliseconds)". ده بيحصل لو عميل ربط
     * https://tourfecto.pro نفسه كموقع Auto SEO وحالته is_connected = 1.
     *
     * @return string[] دومينات صغيرة الحروف بدون www ولا بورت
     */
    public static function platformHosts(): array
    {
        $hosts = [];
        $add = static function ($h) use (&$hosts) {
            $h = strtolower(trim((string) $h));
            if (strpos($h, '://') !== false) {
                $h = (string) parse_url($h, PHP_URL_HOST);
            }
            if (($p = strpos($h, ':')) !== false) {
                $h = substr($h, 0, $p);
            }
            $h = preg_replace('/^www\./', '', $h);
            if ($h !== '' && $h !== 'localhost' && !filter_var($h, FILTER_VALIDATE_IP)) {
                $hosts[$h] = true;
            }
        };
        $add(function_exists('env') ? env('APP_URL') : (getenv('APP_URL') ?: ''));
        $add($_SERVER['SERVER_NAME'] ?? '');
        foreach (explode(',', (string) (function_exists('env') ? env('PLATFORM_HOSTS') : getenv('PLATFORM_HOSTS'))) as $h) {
            $add($h);
        }
        return array_keys($hosts);
    }

    /** هل الدومين ده (أو أي subdomain منه) تبع المنصة نفسها؟ */
    public static function isPlatformHost(string $host): bool
    {
        $host = strtolower(trim($host));
        if (($p = strpos($host, ':')) !== false) {
            $host = substr($host, 0, $p);
        }
        $host = preg_replace('/^www\./', '', $host);
        if ($host === '') {
            return false;
        }
        foreach (self::platformHosts() as $ph) {
            if ($host === $ph || substr($host, -strlen('.' . $ph)) === '.' . $ph) {
                return true;
            }
        }
        return false;
    }

    /**
     * البحث عن موقع مربوط من خلال الـ Host header (وضع CNAME الحقيقي).
     * العميل بيشير CNAME من subdomain بتاعه لسيرفرنا، فالطلب بيوصل من غير
     * مسار /s/{token} والـ Host header بيبقى دومينه هو.
     */
    public function findByHost(string $host): ?array
    {
        $host = strtolower(trim($host));
        if (($pos = strpos($host, ':')) !== false) {
            $host = substr($host, 0, $pos);
        }
        if ($host === '') {
            return null;
        }
        // المنصة نفسها: ما نمسكهاش أبدًا حتى لو في موقع عميل مربوط بنفس الدومين
        if (self::isPlatformHost($host)) {
            return null;
        }

        $sites = $this->db->query(
            "SELECT id, main_url, company_name, auto_pilot_mode, indexnow_key FROM websites WHERE is_connected = 1",
            []
        );
        foreach ($sites as $site) {
            $domain = strtolower((string) (parse_url((string) $site['main_url'], PHP_URL_HOST) ?? ''));
            if ($domain === '' || self::isPlatformHost($domain)) {
                continue;
            }
            // مطابقة مباشرة (www.example.com === www.example.com) أو أي
            // subdomain يشير CNAME لنا (blog.example.com -> example.com).
            if ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain) {
                return $site;
            }
        }
        return null;
    }

    /** تطبيق إعادة كتابة واحدة على الـ HTML */
    private function applyRewrite(string $html, string $field, string $code, string $pageUrl, ?int $websiteId = null): string
    {
        switch ($field) {
            case 'seo_title':
                $title = trim(strip_tags($code));
                if ($title !== '') {
                    if (preg_match('/<title[^>]*>.*?<\/title>/is', $html)) {
                        $html = preg_replace('/<title[^>]*>.*?<\/title>/is', '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>', $html, 1);
                    } else {
                        $html = str_ireplace('<head>', "<head>\n<title>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>', $html);
                    }
                }
                break;

            case 'seo_description':
                $desc = mb_substr(trim(strip_tags($code)), 0, 160);
                if ($desc !== '') {
                    $safe = htmlspecialchars($desc, ENT_QUOTES, 'UTF-8');
                    if (preg_match('/<meta\s+name=["\']description["\'][^>]*>/i', $html)) {
                        $html = preg_replace('/<meta\s+name=["\']description["\'][^>]*>/i', '<meta name="description" content="' . $safe . '">', $html, 1);
                    } else {
                        $html = str_ireplace('<head>', "<head>\n<meta name=\"description\" content=\"{$safe}\">", $html);
                    }
                }
                break;

            case 'canonical_url':
                $canonical = trim($code) !== '' ? trim($code) : $pageUrl;
                $safe = htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8');
                if (preg_match('/<link\s+[^>]*rel=["\']canonical["\'][^>]*>/i', $html)) {
                    $html = preg_replace('/<link\s+[^>]*rel=["\']canonical["\'][^>]*>/i', '<link rel="canonical" href="' . $safe . '">', $html, 1);
                } else {
                    $html = str_ireplace('<head>', "<head>\n<link rel=\"canonical\" href=\"{$safe}\">", $html);
                }
                break;

            case 'viewport':
                if (preg_match('/<meta\s+[^>]*name=["\']viewport["\'][^>]*>/i', $html)) {
                    $html = preg_replace('/<meta\s+[^>]*name=["\']viewport["\'][^>]*>/i', '<meta name="viewport" content="width=device-width, initial-scale=1">', $html, 1);
                } else {
                    $html = str_ireplace('<head>', '<head>' . "\n" . '<meta name="viewport" content="width=device-width, initial-scale=1">', $html);
                }
                break;

            case 'robots_meta':
                if (preg_match('/<meta\s+[^>]*name=["\']robots["\'][^>]*>/i', $html)) {
                    $html = preg_replace('/<meta\s+[^>]*name=["\']robots["\'][^>]*>/i', '<meta name="robots" content="' . htmlspecialchars(trim(strip_tags($code)), ENT_QUOTES, 'UTF-8') . '">', $html, 1);
                } else {
                    $html = str_ireplace('<head>', '<head>' . "\n" . '<meta name="robots" content="' . htmlspecialchars(trim(strip_tags($code)), ENT_QUOTES, 'UTF-8') . '">', $html);
                }
                break;

            case 'json_ld':
            case 'faq_schema':
            case 'speakable':
                $json = $this->normalizeJsonLd($code);
                if ($json !== '') {
                    $block = "\n<script type=\"application/ld+json\">\n" . $json . "\n</script>\n";
                    $html = str_ireplace('<head>', "<head>{$block}", $html);
                }
                break;

            case 'og_tags':
                // code بيحتوي على وسوم <meta property="og:..."> جاهزة
                if (trim(strip_tags($code)) === trim($code) && trim($code) !== '') {
                    // نص عادي مش HTML - نتجاهل (مش تنسيق OG صحيح)
                    break;
                }
                // نسمح بوسوم meta/link فقط، ونشيل أي وسوم خطرة (script/iframe)
                // أو معالجات أحداث من القيمة المحفوظة قبل حقنها في الـ head.
                $code = strip_tags($code, '<meta><link>');
                $code = preg_replace('/\son[a-z0-9_]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $code);
                $code = preg_replace('/javascript\s*:/i', '', $code);
                $code = trim($code);
                if ($code === '') {
                    break;
                }
                $html = str_ireplace('<head>', "<head>\n" . $code . "\n", $html);
                break;

            case 'image_alt':
                // Server-side: إضافة alt للصور من غير alt (تقريبية)
                $html = preg_replace_callback('/<img\s+([^>]*?)>/i', function ($m) {
                    $tag = $m[0];
                    if (preg_match('/alt=/i', $tag)) {
                        return $tag;
                    }
                    return str_replace('<img', '<img alt=""', $tag);
                }, $html);
                break;

            case 'image_lazy_load':
                $html = $this->applyLazyLoadingToHtml($html);
                break;

            case 'image_webp_convert':
                $html = $this->applyWebpConversion($html, $pageUrl);
                break;

            case 'hreflang_tags':
                $html = $this->applyHreflang($html, $pageUrl, $websiteId);
                break;

            default:
                break;
        }

        return $html;
    }

    private function applyLazyLoadingToHtml(string $html): string
    {
        $service = new ImageOptimizationService();
        return $service->applyLazyLoading($html);
    }

    private function applyWebpConversion(string $html, string $pageUrl): string
    {
        $service = new ImageOptimizationService();
        return $service->rewriteImageSrc($html, $pageUrl);
    }

    private function applyHreflang(string $html, string $pageUrl, ?int $websiteId): string
    {
        if (!$websiteId) {
            return $html;
        }

        $rows = $this->db->query(
            "SELECT target_languages FROM websites WHERE id = ? LIMIT 1",
            [$websiteId]
        );
        if (empty($rows) || empty($rows[0]['target_languages'])) {
            return $html;
        }

        $langs = json_decode((string) $rows[0]['target_languages'], true);
        if (!is_array($langs) || empty($langs)) {
            return $html;
        }

        $tags = [];
        foreach ($langs as $lang) {
            $langCode = is_array($lang) ? ($lang['code'] ?? '') : $lang;
            if ($langCode === '') {
                continue;
            }
            $href = $this->buildHreflangUrl($pageUrl, $langCode);
            $tags[] = '<link rel="alternate" hreflang="' . htmlspecialchars($langCode, ENT_QUOTES, 'UTF-8') . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
        }

        $tags[] = '<link rel="alternate" hreflang="x-default" href="' . htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') . '">';
        $block = "\n" . implode("\n", $tags) . "\n";

        if (stripos($html, '</head>') !== false) {
            $html = str_ireplace('</head>', $block . '</head>', $html);
        } else {
            $html = $block . $html;
        }

        return $html;
    }

    private function buildHreflangUrl(string $pageUrl, string $langCode): string
    {
        $parsed = parse_url($pageUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = !empty($parsed['port']) ? ':' . $parsed['port'] : '';
        $path = $parsed['path'] ?? '/';
        return "{$scheme}://{$host}{$port}/" . strtolower($langCode) . $path;
    }

    /** تحويل الكود المحفوظ لـ JSON-LD نظيف */
    private function normalizeJsonLd(string $code): string
    {
        $code = trim($code);
        // لو الكود جاي كـ <script type="application/ld+json">...</script>
        if (preg_match('/<script[^>]*>([\s\S]*?)<\/script>/i', $code, $m)) {
            $code = trim($m[1]);
        }
        $decoded = json_decode($code, true);
        if ($decoded !== null) {
            return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        }
        return htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    }

    /** خدمة ملفات robots.txt / llms.txt / sitemap من إصلاحاتنا (لو موجودة) */
    private function serveAuxFile(array $site, string $path, string $origin): array
    {
        $fieldMap = [
            '/robots.txt' => 'robots_txt',
            '/llms.txt' => 'llms_txt',
            '/sitemap.xml' => 'sitemap',
        ];
        $field = $fieldMap[$path] ?? null;

        if ($field !== null) {
            $rows = $this->db->query(
                "SELECT injected_code FROM auto_seo_applied_fixes
                  WHERE website_id = ? AND field_name = ? AND is_active = 1
                  ORDER BY id DESC LIMIT 1",
                [$site['id'], $field]
            );
            if (!empty($rows) && trim((string) $rows[0]['injected_code']) !== '') {
                $ct = $path === '/sitemap.xml' ? 'application/xml; charset=utf-8' : 'text/plain; charset=utf-8';
                return ['status' => 200, 'content_type' => $ct, 'body' => (string) $rows[0]['injected_code']];
            }
        }

        // لو مفيش نسخة محسّنة، نجيب الأصل
        $res = $this->fetch($origin . $path);
        if ($res['body'] !== null && $res['code'] < 400) {
            $ct = $path === '/sitemap.xml' ? 'application/xml; charset=utf-8' : 'text/plain; charset=utf-8';
            return ['status' => 200, 'content_type' => $ct, 'body' => (string) $res['body']];
        }

        return ['status' => 404, 'content_type' => 'text/plain', 'body' => 'Not found'];
    }

    /** جلب صفحة من السيرفر الأصلي (مع حماية SSRF + كاش) */
    private function fetch(string $url): array
    {
        // حماية SSRF: نمنع السيرفر من إنه يطلب شبكات داخلية / metadata / منافذ
        // غير قياسية حتى لو العميل حط main_url خبيثة.
        if (!class_exists('SsrfGuard', false)) {
            $ssrfFile = APP_PATH . '/Services/CompetitorIntelligence/SsrfGuard.php';
            if (file_exists($ssrfFile)) {
                require_once $ssrfFile;
            }
        }
        if (class_exists('SsrfGuard', false)) {
            $check = SsrfGuard::validateUrl($url);
            if (!$check['safe']) {
                return ['body' => null, 'code' => 0, 'error' => 'SSRF blocked: ' . ($check['reason'] ?? 'unsafe')];
            }
        }

        // كاش origin: نتجنب إعادة جلب نفس الصفحة من الأصل في كل طلب (نفس
        // دور الـ CDN). الصلاحية قصيرة عشان أي تعديل جديد يظهر بسرعة.
        $cache = new Cache();
        $cacheKey = 'seo_origin_' . md5($url);
        $cached = $cache->get($cacheKey);
        if (is_array($cached) && isset($cached['body'])) {
            return $cached;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => $_SERVER['HTTP_USER_AGENT'] ?? 'TourfectoSeoProxy/1.0',
            // علامة تمنع أي proxy تاني من إعادة تحويل نفس الطلب (قطع الحلقات)
            CURLOPT_HTTPHEADER => ['X-Tourfecto-Proxy: 1'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $result = ['body' => $body === false ? null : $body, 'code' => $code, 'error' => $error];

        if ($body !== false && $code === 200) {
            $cache->set($cacheKey, $result, 60);
        }

        return $result;
    }

    /**
     * معاينة التغييرات المقترحة قبل التطبيق الفعلي (بدون أي كتابة في قاعدة البيانات).
     *
     * بيجيب صفحة الموقع الأصلية، ويطبّق الإصلاحات الحالية النشطة + التغييرات
     * المقترحة، ويرجّع الـ title والوصف قبل/بعد عشان العميل يشوف الفرق.
     *
     * @param array $changes خريطة field_name => قيمة مقترحة (مثال: seo_title)
     * @return array ['success'=>bool, 'before'=>[], 'after'=>[], 'error'=>?string]
     */
    public function previewChanges(int $websiteId, array $changes): array
    {
        $sites = $this->db->query("SELECT main_url FROM websites WHERE id = ? LIMIT 1", [$websiteId]);
        if (empty($sites)) {
            return ['success' => false, 'error' => 'site not found'];
        }

        $origin = rtrim((string) $sites[0]['main_url'], '/');
        $fetched = $this->fetch($origin . '/');
        if ($fetched['body'] === null) {
            return ['success' => false, 'error' => 'origin unreachable (' . ($fetched['error'] ?: "HTTP {$fetched['code']}") . ')'];
        }

        $html = (string) $fetched['body'];
        $before = $this->extractSeoMeta($html);

        // طبّق الإصلاحات النشطة الحالية، وبعدها التغييرات المقترحة (تتجاوزها)
        $active = $this->db->query(
            "SELECT field_name, injected_code FROM auto_seo_applied_fixes WHERE website_id = ? AND is_active = 1",
            [$websiteId]
        );
        foreach ($active as $fix) {
            $html = $this->applyRewrite($html, (string) $fix['field_name'], (string) $fix['injected_code'], $origin . '/', $websiteId);
        }
        foreach ($changes as $field => $value) {
            $html = $this->applyRewrite($html, (string) $field, (string) $value, $origin . '/', $websiteId);
        }

        return ['success' => true, 'before' => $before, 'after' => $this->extractSeoMeta($html)];
    }

    /** استخراج title + meta description من HTML (للمعاينة والتقارير) */
    private function extractSeoMeta(string $html): array
    {
        $title = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $title = trim(strip_tags($m[1]));
        }
        $description = '';
        if (preg_match('/<meta\s+name=["\']description["\'][^>]*content=["\']([^"\']*)["\'][^>]*>/i', $html, $m)
            || preg_match('/<meta\s+[^>]*content=["\']([^"\']*)["\'][^>]*name=["\']description["\'][^>]*>/i', $html, $m)) {
            $description = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        }
        return ['title' => $title, 'description' => $description];
    }
}

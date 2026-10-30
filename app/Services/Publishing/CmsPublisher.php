<?php

/**
 * نشر مقالات على أنظمة المواقع الجاهزة (غير ووردبريس) بالـAPI الرسمي لكل نظام:
 *  - Shopify: Admin API (مدونة المتجر) - توكن «Custom app» بصلاحية write_content
 *  - Webflow: CMS API v2 (Collection المدونة) - توكن بصلاحية CMS
 *  - Ghost:   Admin API key (id:secret) → JWT
 * الاتصال بيتخزن كـ custom_api بعنوان داخلي (shopify://… / webflow://… / ghost://…)
 * عشان كل مسارات النشر الموجودة (يدوي/مجدول/الأتمتة) تشتغل عليه من غير تغيير.
 */
class CmsPublisher
{
    private static function L(string $ar, string $en): string
    {
        return function_exists('current_lang') && !str_starts_with(current_lang(), 'ar') ? $en : $ar;
    }

    public const SCHEMES = ['shopify', 'webflow', 'ghost'];

    /** @var callable|null للاختبارات: fn(method, url, headers, body) => ['code'=>int,'body'=>string] */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    /**
     * موقع PHP عليه tourfecto-connect.php: مفيش طلب خارجي. المقال بيتعرض على موقع العميل نفسه في /guides/{slug}
     * من خلال الملف (EdgeRules بيضم المقالات المنشورة بالرابط ده)، فاللي بنرجّعه هو الرابط اللي بيتحفظ مع المقال.
     * $query: السيرفر مش بيودّي /guides لـ index.php، فالرابط يبقى /?tf_article={slug} (connector://ID/q).
     */
    public static function publishViaConnector(int $websiteId, array $a, bool $isTest = false, bool $query = false): array
    {
        $db = Database::getInstance();
        $main = (string) ($db->query('SELECT main_url FROM websites WHERE id = ? LIMIT 1', [$websiteId])[0]['main_url'] ?? '');
        $host = strtolower((string) parse_url(preg_match('~^https?://~i', $main) ? $main : 'https://' . $main, PHP_URL_HOST));
        if ($host === '') return ['success' => false, 'error' => self::L('الموقع غير موجود', 'Website not found')];
        $base = 'https://' . $host . ($query ? '/?tf_article=' : '/guides/');
        if ($isTest) return ['success' => true, 'url' => $base];
        $title = trim((string) ($a['title'] ?? ''));
        if ($title === '' || trim(strip_tags((string) ($a['content_html'] ?? $a['content_markdown'] ?? ''))) === '') {
            return ['success' => false, 'error' => self::L('المقال فاضي', 'The article is empty')];
        }
        $id = (int) ($a['article_id'] ?? 0);
        // الملف بيقبل [a-z0-9-] بس: slug المقال، أو العنوان، أو guide-{id} لو العنوان عربي
        $ascii = static fn (string $s): string => trim(substr(trim(strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $s)), '-'), 0, 100), '-');
        $slug = $ascii((string) ($a['slug'] ?? '')) ?: $ascii($title) ?: 'guide-' . ($id ?: substr(bin2hex(random_bytes(3)), 0, 6));
        $candidate = $slug;
        for ($n = 2; $n < 50 && $db->query('SELECT id FROM ai_articles WHERE website_id = ? AND published_url = ? AND id <> ? LIMIT 1', [$websiteId, $base . $candidate, $id]); $n++) {
            $candidate = $slug . '-' . $n;
        }
        return ['success' => true, 'url' => $base . $candidate];
    }

    public static function isCmsEndpoint(string $endpoint): bool
    {
        return (bool) preg_match('~^(shopify|webflow|ghost|hosted|connector)://~', $endpoint);
    }

    /** عنوان داخلي للتخزين: shopify://store.myshopify.com/123 | webflow://COLLECTION_ID | ghost://blog.example.com */
    public static function endpoint(string $kind, string $location, string $sub = ''): string
    {
        return $kind . '://' . $location . ($sub !== '' ? '/' . $sub : '');
    }

    private function http(string $method, string $url, array $headers, ?string $body = null): array
    {
        if ($this->transport) return call_user_func($this->transport, $method, $url, $headers, $body);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'Tourfecto/1.0', CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return ['code' => $err ? 0 : $code, 'body' => (string) $res, 'error' => $err ?: null];
    }

    private static function fail(array $r, string $who): array
    {
        $j = json_decode($r['body'] ?? '', true);
        $msg = is_array($j) ? (string) ($j['errors'][0]['message'] ?? (is_string($j['errors'] ?? null) ? $j['errors'] : ($j['message'] ?? ''))) : '';
        if ($r['code'] === 401 || $r['code'] === 403) $msg = self::L('المفتاح غلط أو مالوش صلاحية كتابة المقالات', 'The key is wrong or lacks permission to write articles');
        return ['success' => false, 'error' => $who . ': ' . ($msg !== '' ? mb_substr($msg, 0, 200) : ($r['error'] ?? ('HTTP ' . $r['code'])))];
    }

    // ------------------------------------------------------------------ Shopify

    /** المدونات اللي في المتجر (عشان العميل يختار) */
    public function shopifyBlogs(string $shop, string $token): array
    {
        $r = $this->http('GET', "https://{$shop}/admin/api/2024-07/blogs.json", ['X-Shopify-Access-Token: ' . $token, 'Accept: application/json']);
        if ($r['code'] !== 200) return self::fail($r, 'Shopify');
        $blogs = array_map(fn ($b) => ['id' => (int) $b['id'], 'title' => (string) $b['title'], 'handle' => (string) ($b['handle'] ?? '')], json_decode($r['body'], true)['blogs'] ?? []);
        return ['success' => true, 'blogs' => $blogs];
    }

    // ------------------------------------------------------------------ Webflow

    /** الـCollections (عشان نلاقي مدونة الموقع) */
    public function webflowCollections(string $token): array
    {
        $r = $this->http('GET', 'https://api.webflow.com/v2/sites', ['Authorization: Bearer ' . $token, 'Accept: application/json']);
        if ($r['code'] !== 200) return self::fail($r, 'Webflow');
        $out = [];
        foreach (array_slice(json_decode($r['body'], true)['sites'] ?? [], 0, 5) as $site) {
            $c = $this->http('GET', 'https://api.webflow.com/v2/sites/' . rawurlencode((string) $site['id']) . '/collections', ['Authorization: Bearer ' . $token, 'Accept: application/json']);
            foreach ($c['code'] === 200 ? (json_decode($c['body'], true)['collections'] ?? []) : [] as $col) {
                $out[] = ['id' => (string) $col['id'], 'title' => (string) ($site['displayName'] ?? '') . ' / ' . (string) ($col['displayName'] ?? $col['slug'] ?? '')];
            }
        }
        return ['success' => true, 'collections' => $out];
    }

    // ------------------------------------------------------------------ Ghost

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function ghostJwt(string $adminKey): ?string
    {
        [$id, $secret] = array_pad(explode(':', $adminKey, 2), 2, '');
        if ($id === '' || !ctype_xdigit($secret)) return null;
        $now = time();
        $h = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => $id]));
        $p = self::b64(json_encode(['iat' => $now, 'exp' => $now + 300, 'aud' => '/admin/']));
        return $h . '.' . $p . '.' . self::b64(hash_hmac('sha256', $h . '.' . $p, hex2bin($secret), true));
    }

    public function ghostTest(string $host, string $adminKey): array
    {
        $jwt = self::ghostJwt($adminKey);
        if (!$jwt) return ['success' => false, 'error' => self::L('مفتاح Ghost لازم يكون بالشكل id:secret (من Integrations في لوحة Ghost)', 'The Ghost key must look like id:secret (from Integrations in Ghost admin)')];
        $r = $this->http('GET', "https://{$host}/ghost/api/admin/site/", ['Authorization: Ghost ' . $jwt, 'Accept-Version: v5.0']);
        return $r['code'] === 200 ? ['success' => true, 'title' => (string) (json_decode($r['body'], true)['site']['title'] ?? $host)] : self::fail($r, 'Ghost');
    }

    // ------------------------------------------------------------------ النشر

    /**
     * @param string $endpoint العنوان الداخلي (shopify://… إلخ)
     * @param string $secret التوكن/المفتاح (متفكّ التشفير)
     * @param array $a title, content_html, meta_description, slug
     * @return array ['success'=>bool, 'url'=>?string, 'error'=>?string]
     */
    public function publish(string $endpoint, string $secret, array $a, bool $isTest = false): array
    {
        // مدونة مستضافة عندنا (Wix وغيره): مفيش طلب خارجي - بنخزن المقال ونرجع رابطه
        if (preg_match('~^hosted://(\d+)$~', $endpoint, $hm)) {
            return (new HostedBlogService())->publish((int) $hm[1], $a, $isTest);
        }
        if (preg_match('~^connector://(\d+)(/q)?$~', $endpoint, $cm)) {
            return self::publishViaConnector((int) $cm[1], $a, $isTest, !empty($cm[2]));
        }
        if (!preg_match('~^(shopify|webflow|ghost)://([^/]+)(?:/(.+))?$~', $endpoint, $m)) {
            return ['success' => false, 'error' => self::L('نوع ربط غير معروف', 'Unknown connection type')];
        }
        [, $kind, $loc, $sub] = array_pad($m, 4, '');
        $title = (string) ($a['title'] ?? '');
        $html = (string) ($a['content_html'] ?? '');
        $excerpt = mb_substr((string) ($a['meta_description'] ?? ''), 0, 300);
        $slug = (string) ($a['slug'] ?? '');

        if ($kind === 'shopify') {
            if ($isTest) return $this->shopifyBlogs($loc, $secret) + ['url' => null];
            $body = json_encode(['article' => array_filter(['title' => $title, 'body_html' => $html, 'summary_html' => $excerpt !== '' ? '<p>' . htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8') . '</p>' : null, 'handle' => $slug ?: null, 'published' => true])], JSON_UNESCAPED_UNICODE);
            [$blogId, $blogHandle] = array_pad(explode(':', $sub, 2), 2, '');
            $r = $this->http('POST', "https://{$loc}/admin/api/2024-07/blogs/" . (int) $blogId . '/articles.json', ['X-Shopify-Access-Token: ' . $secret, 'Content-Type: application/json'], $body);
            if ($r['code'] < 200 || $r['code'] >= 300) return self::fail($r, 'Shopify');
            $art = json_decode($r['body'], true)['article'] ?? [];
            $url = ($blogHandle !== '' && !empty($art['handle'])) ? "https://{$loc}/blogs/" . rawurlencode($blogHandle) . '/' . rawurlencode((string) $art['handle']) : null;
            return ['success' => true, 'url' => $url, 'external_id' => (string) ($art['id'] ?? '')];
        }
        if ($kind === 'webflow') {
            if ($isTest) return $this->webflowCollections($secret) + ['url' => null];
            $body = json_encode(['isArchived' => false, 'isDraft' => false, 'fieldData' => array_filter(['name' => $title, 'slug' => $slug ?: null, 'post-body' => $html, 'post-summary' => $excerpt ?: null])], JSON_UNESCAPED_UNICODE);
            $r = $this->http('POST', 'https://api.webflow.com/v2/collections/' . rawurlencode($loc) . '/items/live', ['Authorization: Bearer ' . $secret, 'Content-Type: application/json'], $body);
            if ($r['code'] < 200 || $r['code'] >= 300) return self::fail($r, 'Webflow');
            return ['success' => true, 'url' => null, 'external_id' => (string) (json_decode($r['body'], true)['id'] ?? '')];
        }
        // ghost
        if ($isTest) return $this->ghostTest($loc, $secret) + ['url' => null];
        $jwt = self::ghostJwt($secret);
        if (!$jwt) return ['success' => false, 'error' => self::L('مفتاح Ghost غير صالح', 'Invalid Ghost key')];
        $body = json_encode(['posts' => [array_filter(['title' => $title, 'html' => $html, 'custom_excerpt' => $excerpt ?: null, 'slug' => $slug ?: null, 'status' => 'published'])]], JSON_UNESCAPED_UNICODE);
        $r = $this->http('POST', "https://{$loc}/ghost/api/admin/posts/?source=html", ['Authorization: Ghost ' . $jwt, 'Accept-Version: v5.0', 'Content-Type: application/json'], $body);
        if ($r['code'] < 200 || $r['code'] >= 300) return self::fail($r, 'Ghost');
        return ['success' => true, 'url' => (string) (json_decode($r['body'], true)['posts'][0]['url'] ?? '') ?: null];
    }
}

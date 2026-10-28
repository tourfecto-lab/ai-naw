<?php

/**
 * «اربط موقعك» الذكي: بنكتشف نظام موقع العميل تلقائي ونعرضله طريقة الربط المناسبة ليه بس:
 *  WordPress → الربط بضغطة (site-connect.js) | Shopify / Webflow / Ghost → الـAPI الرسمي |
 *  موقع مبرمج خاص → Webhook | Wix وغيره (مفيش API نشر مفتوح) → مقالات جاهزة للنسخ.
 * كل ربط بيتخزن في platform_connections (custom_api بعنوان داخلي) فالنشر اليدوي/المجدول/الأتمتة بيشتغل عليه.
 */
class SiteConnectController extends Controller
{
    private static function L(string $ar, string $en): string
    {
        return function_exists('current_lang') && !str_starts_with(current_lang(), 'ar') ? $en : $ar;
    }

    /** الطريقة المناسبة لكل نظام */
    private const METHOD = [
        'wordpress' => 'wordpress', 'shopify' => 'shopify', 'webflow' => 'webflow', 'ghost' => 'ghost',
        'nextjs' => 'webhook', 'nuxt' => 'webhook', 'react' => 'webhook', 'laravel' => 'webhook', 'lovable' => 'webhook',
    ];

    private function site(int $id): ?Website
    {
        $w = (new Website())->find($id);
        return $w && (int) $w->getAttribute('user_id') === (int) $this->user['id'] ? $w : null;
    }

    private function connection(int $websiteId): ?array
    {
        $rows = $this->db->query("SELECT id, platform, external_location_id, external_location_name, status, last_error, last_synced_at FROM platform_connections WHERE website_id=? AND platform IN ('wordpress','custom_api') AND status='connected' ORDER BY platform='wordpress' DESC, id DESC LIMIT 1", [$websiteId]);
        if (!$rows) return null;
        $r = $rows[0];
        $kind = $r['platform'] === 'wordpress' ? 'wordpress' : (preg_match('~^(shopify|webflow|ghost|hosted)://~', (string) $r['external_location_id'], $m) ? $m[1] : 'webhook');
        return ['kind' => $kind, 'name' => (string) $r['external_location_name'], 'last_synced_at' => $r['last_synced_at'], 'last_error' => $r['last_error']];
    }

    /** GET /api/site-connect/{id} - النظام المكتشف + الطريقة المقترحة + حالة الربط */
    public function detect(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $site = $this->site((int) ($params['id'] ?? 0));
        if (!$site) return $this->error(self::L('غير موجود', 'Not found'), 404);
        $id = (int) $site->getAttribute('id');
        $key = 'site_platform_' . $id;
        $pf = $_SESSION[$key] ?? null;
        if (!is_array($pf) || (time() - (int) ($pf['at'] ?? 0)) > 3600 || $this->get('refresh')) {
            try {
                $pf = (new SmartOnboardingService())->detectPlatform((string) $site->getAttribute('main_url')) + ['at' => time()];
            } catch (Throwable $e) {
                $pf = ['key' => 'unknown', 'label' => '', 'evidence' => '', 'publish' => 'copy', 'booking' => [], 'at' => time()];
            }
            $_SESSION[$key] = $pf;
        }
        unset($pf['at']);
        return $this->success(['platform' => $pf, 'method' => self::METHOD[$pf['key']] ?? 'copy', 'connection' => $this->connection($id), 'url' => (string) $site->getAttribute('main_url')]);
    }

    /** بعد ما الربط ينجح: نحفظه (ونوقف أي طريقة نشر تانية للموقع ده). false = ماتسجلش فعلًا */
    private function save(int $websiteId, string $endpoint, string $secret, string $name): bool
    {
        $ok = PublishConnection::saveCustomApi($this->db, $websiteId, (int) $this->user['id'], (new Encryption())->encrypt($secret), $endpoint, $name);
        if ($ok) $this->log('Site Connected', ['website_id' => $websiteId, 'method' => strtok($endpoint, ':')]);
        return $ok;
    }

    private function guard(array $params): array
    {
        if (!$this->isAuthenticated()) return [null, $this->error('Unauthorized', 401)];
        if (($e = $this->verifyCsrf()) || ($e = $this->rateLimitGuard('user', 'site_connect', 30, 3600))) return [null, $e];
        $site = $this->site((int) ($params['id'] ?? 0));
        return $site ? [$site, null] : [null, $this->error(self::L('غير موجود', 'Not found'), 404)];
    }

    private static function shopHost(string $v): ?string
    {
        $v = strtolower(trim(preg_replace('~^https?://~i', '', $v), '/ '));
        $v = explode('/', $v)[0];
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $v)) $v .= '.myshopify.com';
        return preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $v) ? $v : null;
    }

    /** POST /api/site-connect/{id}/shopify {shop, token, blog_id?, blog_handle?, blog_title?} */
    public function shopify(array $params = []): array
    {
        [$site, $e] = $this->guard($params);
        if ($e) return $e;
        $shop = self::shopHost((string) $this->get('shop'));
        $token = trim((string) $this->get('token'));
        if (!$shop) return $this->error(self::L('اكتب اسم المتجر بالشكل yourstore.myshopify.com', 'Enter the store as yourstore.myshopify.com'), 422);
        if (!preg_match('/^shpat_[A-Za-z0-9]{20,}$/', $token)) return $this->error(self::L('التوكن لازم يبدأ بـ shpat_ (Admin API access token)', 'The token must start with shpat_ (Admin API access token)'), 422);
        $cms = new CmsPublisher();
        $blogs = $cms->shopifyBlogs($shop, $token);
        if (empty($blogs['success'])) return $this->error($blogs['error'] ?? self::L('تعذر الاتصال بالمتجر', 'Could not reach the store'), 422);
        $blogId = (int) $this->get('blog_id', 0);
        if (!$blogId) return $this->success(['step' => 'pick_blog', 'blogs' => $blogs['blogs']]);
        $blog = array_values(array_filter($blogs['blogs'], fn ($b) => $b['id'] === $blogId))[0] ?? null;
        if (!$blog) return $this->error(self::L('المدونة مش موجودة في المتجر', 'That blog was not found in the store'), 422);
        if (!$this->save((int) $site->getAttribute('id'), CmsPublisher::endpoint('shopify', $shop, $blog['id'] . ':' . $blog['handle']), $token, 'Shopify · ' . $blog['title'])) return $this->error(self::L('الاتصال نجح بس ماقدرناش نحفظ الربط — بلّغ الدعم', 'Connected, but we could not save the connection — please contact support'), 500);
        return $this->success(['step' => 'connected'], self::L('المتجر اتربط - المقالات هتتنشر في مدونة «' . $blog['title'] . '»', 'Store connected - articles will publish to “' . $blog['title'] . '”'));
    }

    /** POST /api/site-connect/{id}/webflow {token, collection_id?} */
    public function webflow(array $params = []): array
    {
        [$site, $e] = $this->guard($params);
        if ($e) return $e;
        $token = trim((string) $this->get('token'));
        if (strlen($token) < 20) return $this->error(self::L('الصق الـAPI token من Webflow (Site settings → Apps & integrations)', 'Paste the API token from Webflow (Site settings → Apps & integrations)'), 422);
        $cms = new CmsPublisher();
        $cols = $cms->webflowCollections($token);
        if (empty($cols['success'])) return $this->error($cols['error'] ?? self::L('تعذر الاتصال بـ Webflow', 'Could not reach Webflow'), 422);
        $cid = trim((string) $this->get('collection_id', ''));
        if ($cid === '') return $this->success(['step' => 'pick_collection', 'collections' => $cols['collections']]);
        $col = array_values(array_filter($cols['collections'], fn ($c) => $c['id'] === $cid))[0] ?? null;
        if (!$col || !preg_match('/^[a-f0-9]{24}$/', $cid)) return $this->error(self::L('اختار مجموعة المدونة (Blog Posts)', 'Choose the blog collection (Blog Posts)'), 422);
        if (!$this->save((int) $site->getAttribute('id'), CmsPublisher::endpoint('webflow', $cid), $token, 'Webflow · ' . $col['title'])) return $this->error(self::L('الاتصال نجح بس ماقدرناش نحفظ الربط — بلّغ الدعم', 'Connected, but we could not save the connection — please contact support'), 500);
        return $this->success(['step' => 'connected'], self::L('Webflow اتربط - المقالات هتتنشر في «' . $col['title'] . '»', 'Webflow connected - articles will publish to “' . $col['title'] . '”'));
    }

    /** POST /api/site-connect/{id}/ghost {url, key} */
    public function ghost(array $params = []): array
    {
        [$site, $e] = $this->guard($params);
        if ($e) return $e;
        $host = strtolower((string) parse_url(preg_match('~^https?://~i', (string) $this->get('url')) ? (string) $this->get('url') : 'https://' . $this->get('url'), PHP_URL_HOST));
        $key = trim((string) $this->get('key'));
        if (!preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $host) || (class_exists('SsrfGuard') && !SsrfGuard::isSafe('https://' . $host))) return $this->error(self::L('رابط موقع Ghost غير صحيح', 'Invalid Ghost site URL'), 422);
        $t = (new CmsPublisher())->ghostTest($host, $key);
        if (empty($t['success'])) return $this->error($t['error'] ?? self::L('تعذر الاتصال بـ Ghost', 'Could not reach Ghost'), 422);
        if (!$this->save((int) $site->getAttribute('id'), CmsPublisher::endpoint('ghost', $host), $key, 'Ghost · ' . ($t['title'] ?? $host))) return $this->error(self::L('الاتصال نجح بس ماقدرناش نحفظ الربط — بلّغ الدعم', 'Connected, but we could not save the connection — please contact support'), 500);
        return $this->success(['step' => 'connected'], self::L('Ghost اتربط بنجاح', 'Ghost connected'));
    }

    /** POST /api/site-connect/{id}/webhook {endpoint, secret?} - لموقع مبرمج خاص */
    public function webhook(array $params = []): array
    {
        [$site, $e] = $this->guard($params);
        if ($e) return $e;
        $endpoint = trim((string) $this->get('endpoint'));
        if (!filter_var($endpoint, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($endpoint), 'https://') || (class_exists('SsrfGuard') && !SsrfGuard::isSafe($endpoint))) return $this->error(self::L('الرابط لازم يكون https ويوصل للسيرفر بتاعكم', 'The URL must be https and reach your server'), 422);
        $secret = trim((string) $this->get('secret', ''));
        if ($secret === '') $secret = bin2hex(random_bytes(20));
        $test = (new CustomApiPublisher())->publish($endpoint, $secret, ['article_id' => 0, 'title' => 'Tourfecto connection test', 'content_html' => '<p>test</p>', 'content_markdown' => 'test', 'meta_description' => '', 'slug' => 'tourfecto-test', 'suggested_keywords' => []], true);
        if (empty($test['success'])) return $this->error($test['error'] ?? self::L('الرابط مارجعش 200', 'The endpoint did not return 200'), 422);
        if (!$this->save((int) $site->getAttribute('id'), $endpoint, $secret, parse_url($endpoint, PHP_URL_HOST) ?: $endpoint)) return $this->error(self::L('الاتصال نجح بس ماقدرناش نحفظ الربط — بلّغ الدعم', 'Connected, but we could not save the connection — please contact support'), 500);
        return $this->success(['step' => 'connected', 'secret' => $secret], self::L('الربط اشتغل - السيرفر بتاعكم رد على رسالة الاختبار', 'Connected - your server answered the test message'));
    }

    /** POST /api/site-connect/{id}/disconnect */
    public function disconnect(array $params = []): array
    {
        [$site, $e] = $this->guard($params);
        if ($e) return $e;
        $this->db->exec("UPDATE platform_connections SET status='disconnected' WHERE website_id=? AND platform='custom_api'", [(int) $site->getAttribute('id')]);
        return $this->success([], self::L('الربط اتلغى', 'Disconnected'));
    }
}

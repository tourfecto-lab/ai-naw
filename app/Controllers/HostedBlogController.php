<?php

/**
 * المدونة المستضافة: الصفحات العامة (الرئيسية/المقال/RSS/sitemap) + التفعيل والدومين من اللوحة + قايمة الأدمن.
 */
class HostedBlogController extends Controller
{
    private static function L(string $ar, string $en): string
    {
        return function_exists('current_lang') && !str_starts_with(current_lang(), 'ar') ? $en : $ar;
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    private const UI = [
        'ar' => ['latest' => 'أحدث المقالات', 'read' => 'اقرأ المقال', 'min' => 'دقايق قراءة', 'more' => 'مقالات تانية', 'cta_t' => 'جاهز تخطط رحلتك؟', 'cta_b' => 'احجز مع فريقنا المحلي مباشرة — من غير وسطاء.', 'cta' => 'شوف الرحلات', 'home' => 'المدونة', 'empty' => 'أول المقالات جاية قريب.', 'older' => 'مقالات أقدم', 'newer' => 'أحدث', 'site' => 'الموقع الرئيسي'],
        'en' => ['latest' => 'Latest stories', 'read' => 'Read the story', 'min' => 'min read', 'more' => 'More stories', 'cta_t' => 'Ready to plan your trip?', 'cta_b' => 'Book directly with our local team — no middlemen.', 'cta' => 'See our tours', 'home' => 'Blog', 'empty' => 'The first stories are coming soon.', 'older' => 'Older stories', 'newer' => 'Newer', 'site' => 'Main website'],
    ];

    /** المدونة من الرابط: /stories/{handle} أو الدومين الفرعي بتاع العميل */
    private function resolve(array $params): array
    {
        $svc = new HostedBlogService();
        $blog = $svc->byHandle((string) ($params['handle'] ?? ''));
        if (!$blog) $this->notFound();
        $site = $this->db->query('SELECT id, `' . WebsiteSchema::url() . '` AS main_url, ' . WebsiteSchema::col('logo_url', 'logo_url') . ', ' . WebsiteSchema::nameExpr() . ' AS name, ' . WebsiteSchema::col('target_language', 'target_language') . ' FROM websites WHERE id = ?' . WebsiteSchema::alive() . ' LIMIT 1', [(int) $blog['website_id']])[0] ?? null;
        if (!$site) $this->notFound();
        $site['name'] = preg_replace('~^https?://(www\.)?~i', '', (string) $site['name']);
        // على الدومين الفرعي اللينكات بتبدأ من / وعلى المنصة من /stories/{handle}
        $host = preg_replace('/^www\./', '', strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''))));
        $local = !empty($blog['domain']) && $host === $blog['domain'] ? '' : '/stories/' . $blog['handle'];
        return [$svc, $blog, $site, $local];
    }

    private function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>404</title><p style="font:16px system-ui;text-align:center;margin-top:80px">Not found</p>';
        exit;
    }

    private function page(array $blog, array $site, string $lang, string $title, string $desc, string $canonical, string $body, string $head = ''): void
    {
        $T = self::UI[$lang === 'ar' ? 'ar' : 'en'];
        $dir = $lang === 'ar' ? 'rtl' : 'ltr';
        $local = $this->local;
        $logo = !empty($site['logo_url']) ? '<img src="' . self::e((string) $site['logo_url']) . '" alt="">' : '<span class="mk">' . self::e(mb_strtoupper(mb_substr($site['name'], 0, 1))) . '</span>';
        $main = self::e(self::utm((string) $site['main_url'], 'header'));
        $chat = '';
        try {
            if ((new WebChatService())->isLive((int) $site['id'])) $chat = WebChatService::snippet((int) $site['id']);
        } catch (Throwable $e) {
        }
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo '<!doctype html><html lang="' . self::e($lang) . '" dir="' . $dir . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . self::e($title) . '</title><meta name="description" content="' . self::e($desc) . '"><link rel="canonical" href="' . self::e($canonical) . '">'
            . '<meta property="og:title" content="' . self::e($title) . '"><meta property="og:description" content="' . self::e($desc) . '"><meta property="og:url" content="' . self::e($canonical) . '"><meta property="og:site_name" content="' . self::e($site['name']) . '">'
            . '<link rel="alternate" type="application/rss+xml" title="' . self::e($site['name']) . '" href="' . self::e(HostedBlogService::baseUrl($blog)) . '/feed">'
            . $head
            . '<style>' . self::CSS . '</style></head><body>'
            . '<header class="hb-top"><div class="hb-w"><a class="hb-brand" href="' . self::e($local ?: '/') . '">' . $logo . '<b>' . self::e($site['name']) . '</b><small>' . $T['home'] . '</small></a><a class="hb-main" href="' . $main . '">' . $T['site'] . ' ↗</a></div></header>'
            . '<main class="hb-w" style="width:100%;margin-bottom:56px">' . $body . '</main>'
            . '<footer class="hb-foot"><div class="hb-w">© ' . date('Y') . ' ' . self::e($site['name']) . ' · <a href="' . $main . '">' . self::e(preg_replace('~^https?://(www\.)?~i', '', rtrim((string) $site['main_url'], '/'))) . '</a></div></footer>'
            . $chat . '</body></html>';
        exit;
    }

    private string $local = '';

    private static function utm(string $url, string $content): string
    {
        if ($url === '') return '#';
        return $url . (str_contains($url, '?') ? '&' : '?') . 'utm_source=tourfecto_blog&utm_medium=referral&utm_content=' . $content;
    }

    private static function minutes(int $len): int
    {
        return max(1, (int) round($len / 1400));
    }

    private static function date(string $d, string $lang): string
    {
        $ts = strtotime($d) ?: time();
        if (class_exists('IntlDateFormatter')) {
            $f = new IntlDateFormatter($lang === 'ar' ? 'ar-EG' : 'en-GB', IntlDateFormatter::LONG, IntlDateFormatter::NONE);
            $s = $f->format($ts);
            if ($s) return $s;
        }
        return date('j M Y', $ts);
    }

    private function card(array $p, string $local, string $lang): string
    {
        $T = self::UI[$lang === 'ar' ? 'ar' : 'en'];
        $url = $local . '/' . rawurlencode($p['slug']);
        $img = $p['image_url'] ? '<img src="' . self::e($p['image_url']) . '" alt="" loading="lazy">' : '<span class="ph"></span>';
        return '<a class="hb-card" href="' . self::e($url) . '">' . $img . '<div><small>' . self::e(self::date($p['published_at'], $lang)) . ' · ' . self::minutes((int) $p['len']) . ' ' . $T['min'] . '</small><h3 dir="auto">' . self::e($p['title']) . '</h3>'
            . ($p['excerpt'] ? '<p dir="auto">' . self::e(mb_substr($p['excerpt'], 0, 170)) . '</p>' : '') . '<span class="go">' . $T['read'] . ' →</span></div></a>';
    }

    /** GET /stories/{handle} */
    public function index(array $params = []): void
    {
        [$svc, $blog, $site, $local] = $this->resolve($params);
        $this->local = $local;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $posts = $svc->posts((int) $site['id'], $page);
        $total = $svc->count((int) $site['id']);
        $lang = $posts ? $posts[0]['lang'] : (preg_match('/^ar/', (string) $site['target_language']) ? 'ar' : 'en');
        $T = self::UI[$lang === 'ar' ? 'ar' : 'en'];
        $body = '<section class="hb-hero"><h1 dir="auto">' . self::e($site['name']) . ' — ' . $T['home'] . '</h1></section>';
        $body .= $posts ? '<h2 class="hb-h">' . $T['latest'] . '</h2><div class="hb-grid">' . implode('', array_map(fn ($p) => $this->card($p, $local, $lang), $posts)) . '</div>' : '<p class="hb-empty">' . $T['empty'] . '</p>';
        $nav = '';
        if ($page > 1) $nav .= '<a href="' . self::e($local ?: '/') . ($page > 2 ? '?page=' . ($page - 1) : '') . '">← ' . $T['newer'] . '</a>';
        if ($page * 12 < $total) $nav .= '<a href="' . self::e(($local ?: '/') . '?page=' . ($page + 1)) . '">' . $T['older'] . ' →</a>';
        if ($nav) $body .= '<nav class="hb-pager">' . $nav . '</nav>';
        $canon = HostedBlogService::baseUrl($blog) . ($page > 1 ? '?page=' . $page : '');
        $head = $total === 0 ? '<meta name="robots" content="noindex">' : '';
        $this->page($blog, $site, $lang, $site['name'] . ' — ' . $T['home'], $site['name'] . ': ' . $T['latest'], $canon, $body, $head);
    }

    /** GET /stories/{handle}/{slug} */
    public function show(array $params = []): void
    {
        [$svc, $blog, $site, $local] = $this->resolve($params);
        $this->local = $local;
        $p = $svc->post((int) $site['id'], rawurldecode((string) ($params['slug'] ?? '')));
        if (!$p) $this->notFound();
        $lang = $p['lang'] ?: 'en';
        $T = self::UI[$lang === 'ar' ? 'ar' : 'en'];
        $canon = HostedBlogService::baseUrl($blog) . '/' . rawurlencode($p['slug']);
        $more = array_values(array_filter($svc->posts((int) $site['id'], 1, 4), fn ($x) => $x['slug'] !== $p['slug']));
        $ld = ['@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => mb_substr($p['title'], 0, 110), 'description' => (string) $p['excerpt'], 'datePublished' => date('c', strtotime($p['published_at'])), 'dateModified' => date('c', strtotime($p['updated_at'])),
            'mainEntityOfPage' => $canon, 'inLanguage' => $lang, 'author' => ['@type' => 'Organization', 'name' => $site['name'], 'url' => $site['main_url']], 'publisher' => ['@type' => 'Organization', 'name' => $site['name'], 'url' => $site['main_url']] + (!empty($site['logo_url']) ? ['logo' => ['@type' => 'ImageObject', 'url' => $site['logo_url']]] : [])]
            + ($p['image_url'] ? ['image' => $p['image_url']] : []);
        $head = '<script type="application/ld+json">' . json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>'
            . '<meta property="og:type" content="article">' . ($p['image_url'] ? '<meta property="og:image" content="' . self::e($p['image_url']) . '">' : '');
        $body = '<article class="hb-art"><a class="hb-back" href="' . self::e($local ?: '/') . '">← ' . $T['home'] . '</a>'
            . '<h1 dir="auto">' . self::e($p['title']) . '</h1><div class="hb-meta">' . self::e(self::date($p['published_at'], $lang)) . ' · ' . self::minutes(strlen($p['html'])) . ' ' . $T['min'] . '</div>'
            . ($p['image_url'] ? '<img class="hb-cover" src="' . self::e($p['image_url']) . '" alt="' . self::e($p['title']) . '">' : '')
            . '<div class="hb-body" dir="auto">' . $p['html'] . '</div>'
            . '<aside class="hb-cta"><div><b>' . $T['cta_t'] . '</b><p>' . $T['cta_b'] . '</p></div><a href="' . self::e(self::utm((string) $site['main_url'], 'article_cta')) . '">' . $T['cta'] . ' →</a></aside></article>';
        if ($more) $body .= '<h2 class="hb-h">' . $T['more'] . '</h2><div class="hb-grid">' . implode('', array_map(fn ($x) => $this->card($x, $local, $lang), array_slice($more, 0, 3))) . '</div>';
        $this->page($blog, $site, $lang, $p['title'] . ' | ' . $site['name'], (string) ($p['excerpt'] ?: mb_substr(trim(strip_tags($p['html'])), 0, 155)), $canon, $body, $head);
    }

    /** GET /stories/{handle}/feed */
    public function feed(array $params = []): void
    {
        [$svc, $blog, $site] = $this->resolve($params);
        $base = HostedBlogService::baseUrl($blog);
        header('Content-Type: application/rss+xml; charset=utf-8');
        $x = fn ($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        echo '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>' . $x($site['name']) . '</title><link>' . $x($base) . '</link><description>' . $x($site['name']) . '</description>';
        foreach ($svc->posts((int) $site['id'], 1, 30) as $p) {
            echo '<item><title>' . $x($p['title']) . '</title><link>' . $x($base . '/' . rawurlencode($p['slug'])) . '</link><guid>' . $x($base . '/' . rawurlencode($p['slug'])) . '</guid><pubDate>' . date(DATE_RSS, strtotime($p['published_at'])) . '</pubDate><description>' . $x($p['excerpt']) . '</description></item>';
        }
        echo '</channel></rss>';
        exit;
    }

    /** GET /stories/{handle}/sitemap (من غير .xml عشان nginx) */
    public function sitemap(array $params = []): void
    {
        [$svc, $blog, $site] = $this->resolve($params);
        $base = HostedBlogService::baseUrl($blog);
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>' . htmlspecialchars($base, ENT_XML1) . '</loc></url>';
        foreach ($svc->posts((int) $site['id'], 1, 1000) as $p) {
            echo '<url><loc>' . htmlspecialchars($base . '/' . rawurlencode($p['slug']), ENT_XML1) . '</loc><lastmod>' . date('Y-m-d', strtotime($p['updated_at'])) . '</lastmod></url>';
        }
        echo '</urlset>';
        exit;
    }

    // ===================== اللوحة =====================

    private function ownSite(int $id): ?array
    {
        if (!$this->isAuthenticated()) return null;
        $owner = !empty($this->user['owner_user_id']) ? (int) $this->user['owner_user_id'] : (int) $this->user['id'];
        $r = $this->db->query('SELECT id, `' . WebsiteSchema::url() . '` AS main_url FROM websites WHERE id = ? AND user_id = ?' . WebsiteSchema::alive() . ' LIMIT 1', [$id, $owner]);
        return $r[0] ?? null;
    }

    private function state(int $websiteId): array
    {
        $svc = new HostedBlogService();
        $blog = $svc->get($websiteId);
        if (!$blog) return ['active' => false];
        $connected = (bool) $this->db->query("SELECT id FROM platform_connections WHERE website_id = ? AND platform = 'custom_api' AND status = 'connected' AND external_location_id = ? LIMIT 1", [$websiteId, HostedBlogService::endpoint($websiteId)]);
        $pending = (int) ($this->db->query("SELECT COUNT(*) c FROM ai_articles a WHERE a.website_id = ? AND a.status = 'completed' AND NOT EXISTS (SELECT 1 FROM hosted_blog_posts p WHERE p.article_id = a.id)", [$websiteId])[0]['c'] ?? 0);
        return [
            'active' => true, 'connected' => $connected, 'url' => HostedBlogService::baseUrl($blog),
            'platform_url' => rtrim(defined('APP_URL') ? (string) APP_URL : '', '/') . '/stories/' . $blog['handle'],
            'posts' => $svc->count($websiteId), 'ready_articles' => $pending,
            'domain' => $blog['domain'], 'domain_state' => $blog['domain_state'], 'dns' => HostedBlogService::dnsRecords($blog),
        ];
    }

    /** GET /api/hosted-blog/{id} */
    public function status(array $params = []): array
    {
        $site = $this->ownSite((int) ($params['id'] ?? 0));
        if (!$site) return $this->error(self::L('غير موجود', 'Not found'), 404);
        return $this->success($this->state((int) $site['id']));
    }

    /** POST /api/hosted-blog/{id}/activate - تفعيل + تحويل النشر عليها */
    public function activate(array $params = []): array
    {
        if ($e = $this->verifyCsrf()) return $e;
        $site = $this->ownSite((int) ($params['id'] ?? 0));
        if (!$site) return $this->error(self::L('غير موجود', 'Not found'), 404);
        $id = (int) $site['id'];
        (new HostedBlogService())->activate((int) $this->user['id'], $id, (string) $site['main_url']);
        if (!PublishConnection::saveCustomApi($this->db, $id, (int) $this->user['id'], (new Encryption())->encrypt(bin2hex(random_bytes(8))), HostedBlogService::endpoint($id), self::L('مدونة Tourfecto', 'Tourfecto hosted blog'))) {
            return $this->error(self::L('المدونة اتعملت بس ماقدرناش نربطها بالنشر — بلّغ الدعم', 'The blog was created but could not be linked for publishing — please contact support'), 500);
        }
        $this->log('Hosted Blog Activated', ['website_id' => $id]);
        return $this->success($this->state($id), self::L('المدونة شغالة ✓', 'Your blog is live ✓'));
    }

    /** POST /api/hosted-blog/{id}/publish-ready - ينشر المقالات الجاهزة اللي لسه ماتنشرتش */
    public function publishReady(array $params = []): array
    {
        if ($e = $this->verifyCsrf()) return $e;
        $site = $this->ownSite((int) ($params['id'] ?? 0));
        if (!$site) return $this->error(self::L('غير موجود', 'Not found'), 404);
        $id = (int) $site['id'];
        $svc = new HostedBlogService();
        if (!$svc->get($id)) return $this->error(self::L('فعّل المدونة الأول', 'Activate the blog first'), 422);
        $done = 0;
        foreach ($this->db->query("SELECT a.id FROM ai_articles a WHERE a.website_id = ? AND a.status = 'completed' AND NOT EXISTS (SELECT 1 FROM hosted_blog_posts p WHERE p.article_id = a.id) ORDER BY a.id LIMIT 30", [$id]) as $row) {
            $a = (new AIArticle())->find((int) $row['id']);
            if (!$a) continue;
            $md = $a->contentForPresentation();
            $r = $svc->publish($id, ['article_id' => (int) $row['id'], 'title' => (string) $a->getAttribute('title'), 'content_html' => ContentFormatter::markdownToHtml($md), 'meta_description' => (string) $a->getAttribute('meta_description'), 'slug' => (string) $a->getAttribute('slug')]);
            if (empty($r['success'])) continue;
            $this->db->exec("UPDATE ai_articles SET status = 'published', published_at = NOW(), published_url = ? WHERE id = ?", [$r['url'], (int) $row['id']]);
            $done++;
        }
        return $this->success($this->state($id), sprintf(self::L('اتنشر %d مقال', '%d articles published'), $done));
    }

    /** POST /api/hosted-blog/{id}/domain {domain} */
    public function domain(array $params = []): array
    {
        if ($e = $this->verifyCsrf()) return $e;
        $site = $this->ownSite((int) ($params['id'] ?? 0));
        if (!$site) return $this->error(self::L('غير موجود', 'Not found'), 404);
        try {
            (new HostedBlogService())->setDomain((int) $site['id'], (string) $this->get('domain', ''));
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
        return $this->success($this->state((int) $site['id']));
    }

    /** POST /api/hosted-blog/{id}/verify */
    public function verify(array $params = []): array
    {
        if ($e = $this->verifyCsrf()) return $e;
        if ($e = $this->rateLimitGuard('user', 'hosted_blog_verify', 20, 3600)) return $e;
        $site = $this->ownSite((int) ($params['id'] ?? 0));
        if (!$site) return $this->error(self::L('غير موجود', 'Not found'), 404);
        try {
            (new HostedBlogService())->verifyDomain((int) $site['id']);
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 422);
        }
        return $this->success($this->state((int) $site['id']), self::L('الدومين اتأكد ✓ — فريقنا هيفعّله خلال يوم عمل', 'Domain verified ✓ — our team will switch it on within one business day'));
    }

    // ===================== الأدمن =====================

    private function isAdmin(): bool
    {
        return $this->isAuthenticated() && in_array($this->user['role'] ?? '', ['admin', 'super_admin'], true);
    }

    /** GET /admin/hosted-blogs */
    public function adminPage(array $params = []): array
    {
        if (!$this->isAdmin()) return $this->error('غير مصرح', 403);
        $rows = (new HostedBlogService())->adminList();
        $badge = ['pending_dns' => ['#6b7280', 'مستني DNS العميل'], 'verified' => ['#d97706', 'محتاج ربط في aaPanel'], 'live' => ['#16a34a', 'شغال']];
        $tr = '';
        foreach ($rows as $r) {
            [$c, $l] = $badge[$r['domain_state']] ?? ['#6b7280', $r['domain_state']];
            $btn = $r['domain_state'] === 'verified' ? '<button class="p-btn primary xs" data-hb-live="' . (int) $r['website_id'] . '" data-on="1">اتربط ✓</button>'
                : ($r['domain_state'] === 'live' ? '<button class="p-btn outline xs" data-hb-live="' . (int) $r['website_id'] . '" data-on="0">رجّعه</button>' : '');
            $tr .= '<tr><td dir="ltr"><b>' . self::e((string) $r['domain']) . '</b><br><small>' . self::e((string) $r['main_url']) . '</small></td><td>' . self::e((string) $r['email']) . '</td><td><span style="color:' . $c . ';font-weight:700">● ' . $l . '</span></td><td>' . $btn . '</td></tr>';
        }
        $target = self::e((string) parse_url(defined('APP_URL') ? APP_URL : '', PHP_URL_HOST));
        $body = '<div class="p-card" dir="rtl"><div class="p-card-head"><h3>📰 دومينات المدونات المستضافة</h3><span class="p-card-sub">العميل بيعمل CNAME + TXT وبنتأكد تلقائي. بعدها: aaPanel ← Website ← ' . $target . ' ← Domain manager ← ضيف الدومين ← SSL ← Let\'s Encrypt، وبعدين اضغط «اتربط».</span></div>'
            . ($tr ? '<table class="p-table"><thead><tr><th>الدومين</th><th>العميل</th><th>الحالة</th><th></th></tr></thead><tbody>' . $tr . '</tbody></table>' : '<div class="p-empty">مفيش دومينات لسه.</div>') . '</div>';
        $js = "document.addEventListener('click',async e=>{const b=e.target.closest('[data-hb-live]');if(!b)return;b.disabled=true;const r=await window.Panel.fetchJSON('/api/admin/hosted-blogs/'+b.dataset.hbLive+'/live',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({on:b.dataset.on==='1'})});if(r&&r.success)location.reload();else{b.disabled=false;alert((r&&r.error)||'خطأ');}});";
        echo $this->renderPanelPage('hosted-blogs', 'المدونات المستضافة', 'ربط الدومينات الفرعية لمدونات العملاء', $body, $js);
        exit;
    }

    /** POST /api/admin/hosted-blogs/{id}/live {on} */
    public function adminLive(array $params = []): array
    {
        if (!$this->isAdmin()) return $this->error('غير مصرح', 403);
        if ($e = $this->verifyCsrf()) return $e;
        (new HostedBlogService())->markLive((int) ($params['id'] ?? 0), filter_var($this->get('on'), FILTER_VALIDATE_BOOLEAN));
        return $this->success([], 'تم');
    }

    private const CSS = ':root{--ink:#14202e;--mut:#5b6776;--line:#e6e9ee;--bg:#fbfaf7;--acc:#0f766e}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:flex;flex-direction:column;background:var(--bg);color:var(--ink);font:17px/1.75 system-ui,-apple-system,"Segoe UI",Tahoma,"Noto Sans Arabic",sans-serif}a{color:var(--acc)}'
        . '.hb-w{max-width:1080px;margin:0 auto;padding:0 20px}.hb-top{background:#fff;border-bottom:1px solid var(--line)}.hb-top .hb-w{display:flex;align-items:center;justify-content:space-between;height:66px;gap:12px}'
        . '.hb-brand{display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--ink)}.hb-brand img{height:38px;width:auto;max-width:140px;object-fit:contain}.hb-brand .mk{width:36px;height:36px;border-radius:10px;background:var(--acc);color:#fff;display:grid;place-items:center;font-weight:800}.hb-brand b{font-size:17px}.hb-brand small{color:var(--mut);font-size:13px;border-inline-start:1px solid var(--line);padding-inline-start:10px}'
        . '.hb-main{font-size:14px;text-decoration:none;border:1px solid var(--line);padding:7px 14px;border-radius:999px;color:var(--ink);white-space:nowrap}'
        . '.hb-hero{padding:46px 0 10px}.hb-hero h1{font-size:clamp(26px,4vw,40px);line-height:1.2;margin:0}.hb-h{font-size:20px;margin:34px 0 14px}.hb-empty{color:var(--mut);padding:40px 0}'
        . '.hb-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:20px}.hb-card{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden;text-decoration:none;color:var(--ink);display:flex;flex-direction:column;transition:transform .15s,box-shadow .15s}.hb-card:hover{transform:translateY(-2px);box-shadow:0 10px 26px rgba(20,32,46,.08)}'
        . '.hb-card img,.hb-card .ph{width:100%;aspect-ratio:16/9;object-fit:cover;display:block;background:linear-gradient(135deg,#e7f3f1,#f5efe3)}.hb-card div{padding:16px 18px 18px}.hb-card small{color:var(--mut);font-size:13px}.hb-card h3{font-size:18px;line-height:1.35;margin:6px 0 8px}.hb-card p{color:var(--mut);font-size:15px;line-height:1.6;margin:0 0 10px}.hb-card .go{color:var(--acc);font-weight:600;font-size:14px}'
        . '.hb-pager{display:flex;justify-content:space-between;margin:28px 0}.hb-art{max-width:740px;margin:0 auto;padding:30px 0 10px}.hb-back{font-size:14px;text-decoration:none}.hb-art h1{font-size:clamp(28px,4.4vw,42px);line-height:1.2;margin:14px 0 8px}.hb-meta{color:var(--mut);font-size:14px;margin-bottom:22px}'
        . '.hb-cover{width:100%;border-radius:16px;margin:0 0 26px;aspect-ratio:16/9;object-fit:cover}.hb-body h2,.hb-body h3{font-size:26px;line-height:1.3;margin:38px 0 10px}.hb-body h4{font-size:20px;line-height:1.35;margin:28px 0 8px}.hb-body ul,.hb-body ol{padding-inline-start:24px}.hb-body li{margin:6px 0}.hb-body li::marker{color:var(--acc)}.hb-body img{max-width:100%;border-radius:12px}.hb-body table{border-collapse:collapse;width:100%;font-size:15px}.hb-body td,.hb-body th{border:1px solid var(--line);padding:8px}.hb-body blockquote{margin:24px 0;padding:12px 20px;border-inline-start:4px solid var(--acc);background:#eef6f5;border-radius:0 12px 12px 0;color:var(--ink)}.hb-body blockquote p{margin:0}'
        . '.hb-cta{margin:40px 0 10px;background:#0f2a2a;color:#fff;border-radius:18px;padding:22px 24px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}.hb-cta p{margin:4px 0 0;opacity:.8;font-size:15px}.hb-cta a{background:#fff;color:#0f2a2a;text-decoration:none;font-weight:700;padding:11px 20px;border-radius:999px;white-space:nowrap}'
        . '.hb-foot{margin-top:auto;border-top:1px solid var(--line);padding:22px 0;color:var(--mut);font-size:14px;background:#fff}'
        . '@media(max-width:600px){body{font-size:16px}.hb-brand small{display:none}.hb-hero{padding-top:28px}}';
}

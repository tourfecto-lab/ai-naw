<?php

/** صفحة «خطة المقالات» + الـAPI بتاعها (عرض/بناء/تعديل/صور) */
class ContentCalendarController extends Controller
{
    private static function L(string $ar, string $en): string
    {
        return function_exists('current_lang') && !str_starts_with(current_lang(), 'ar') ? $en : $ar;
    }

    /** صاحب الحساب (عضو الفريق بيشتغل على مواقع صاحب الحساب وباقته) */
    private function owner(): int
    {
        return !empty($this->user['owner_user_id']) ? (int) $this->user['owner_user_id'] : (int) $this->user['id'];
    }

    private function sites(): array
    {
        $col = Website::urlColumn();
        return $this->db->query("SELECT id, `{$col}` AS main_url FROM websites WHERE user_id = ?" . WebsiteSchema::alive() . " ORDER BY id ASC", [$this->owner()]);
    }

    private function ownSite(int $id): bool
    {
        return (bool) $this->db->query('SELECT id FROM websites WHERE id = ? AND user_id = ?' . WebsiteSchema::alive() . ' LIMIT 1', [$id, $this->owner()]);
    }

    /** المواقع المبنية عندنا ولسه مش متضافة كموقع (عشان نعرض «فعّل» بدل «ضيف موقعك») */
    private function builtSites(): array
    {
        try {
            return $this->db->query("SELECT id, slug, status, custom_domain, content_json FROM generated_websites WHERE user_id = ? ORDER BY status = 'published' DESC, id ASC LIMIT 5", [$this->owner()]);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** المقالات بتتنشر فين (عشان العميل يعرف المقال هيروح فين في يومه) */
    private function destination(int $websiteId): array
    {
        $c = $this->db->query("SELECT platform, external_location_id, external_location_name FROM platform_connections WHERE website_id = ? AND platform IN ('wordpress','custom_api') AND status = 'connected' ORDER BY platform = 'wordpress' DESC, id DESC LIMIT 1", [$websiteId])[0] ?? null;
        if (!$c) return ['kind' => 'none'];
        if ($c['platform'] === 'wordpress') return ['kind' => 'wordpress', 'name' => 'WordPress'];
        $ep = (string) $c['external_location_id'];
        if (str_starts_with($ep, 'connector://')) {
            return ['kind' => 'connector', 'name' => self::L('ملف Tourfecto على موقعك (‎/guides)', 'Tourfecto file on your site (/guides)')];
        }
        if (str_starts_with($ep, 'hosted://')) {
            $b = (new HostedBlogService())->get($websiteId);
            return ['kind' => 'hosted', 'name' => self::L('مدونة Tourfecto', 'Tourfecto blog'), 'url' => $b ? HostedBlogService::baseUrl($b) : null];
        }
        return ['kind' => preg_match('~^(shopify|webflow|ghost)://~', $ep, $m) ? $m[1] : 'webhook', 'name' => (string) $c['external_location_name']];
    }

    /** GET /content-calendar */
    public function index(array $params = []): void
    {
        $sites = $this->sites();
        $empty = '';
        if (!$sites) {
            $built = $this->builtSites();
            $e = fn ($x) => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
            if ($built) {
                $g = $built[0];
                $name = (string) ((json_decode((string) $g['content_json'], true) ?: [])['business_name'] ?? $g['slug']);
                $empty = '<div class="p-card ccal-start"><div style="font-size:34px">📅</div><div style="flex:1;min-width:240px"><b style="font-size:16px">' . self::L('فعّل خطة المقالات لموقعك', 'Turn on the article plan for your site') . ' — ' . $e($name) . '</b>'
                    . '<p class="p-cell-muted" style="margin:6px 0 0;line-height:1.7">' . self::L('بنجهّزلك مقالات الشهر بمواعيدها وصورها، وبتتنشر تلقائي على مدونة احترافية باسم شركتك مربوطة بموقعك.', 'We plan the month’s articles with dates and images, published automatically to a professional blog under your brand, linked to your site.') . '</p></div>'
                    . '<button type="button" class="p-btn primary" data-ccal-activate="' . (int) $g['id'] . '">' . self::L('فعّل الخطة', 'Turn on the plan') . '</button></div>';
            } else {
                $empty = '<div class="p-card ccal-start"><div style="font-size:34px">🌐</div><div style="flex:1;min-width:240px"><b style="font-size:16px">' . self::L('خطة المقالات بتشتغل على موقعك', 'The article plan works on your website') . '</b>'
                    . '<p class="p-cell-muted" style="margin:6px 0 0;line-height:1.7">' . self::L('ضيف رابط موقعك الحالي، أو ابنِ موقع حجز جاهز — والخطة بتتجهز لوحدها بعدها.', 'Add your current website, or build a booking site — the plan gets prepared automatically after that.') . '</p></div>'
                    . '<div style="display:flex;gap:8px;flex-wrap:wrap"><a class="p-btn primary" href="/websites">' . self::L('ضيف رابط موقعك', 'Add your website') . '</a><a class="p-btn outline" href="/website-builder">' . self::L('ابنِ موقع حجز', 'Build a booking site') . '</a></div></div>';
            }
        }
        $opts = '';
        foreach ($sites as $s) $opts .= '<option value="' . (int) $s['id'] . '">' . htmlspecialchars(preg_replace('~^https?://(www\.)?~i', '', rtrim((string) $s['main_url'], '/')), ENT_QUOTES, 'UTF-8') . '</option>';
        $css = htmlspecialchars(asset_v('/assets/css/content-calendar.css'), ENT_QUOTES, 'UTF-8');
        $js = htmlspecialchars(asset_v('/assets/js/content-calendar.js'), ENT_QUOTES, 'UTF-8');
        $body = '<link rel="stylesheet" href="' . $css . '"><div id="ccal" class="ccal"' . ($sites ? '' : ' data-empty="1"') . '>'
            . ($sites ? '<div class="ccal-top"><select id="ccalSite" class="p-select"' . (count($sites) < 2 ? ' hidden' : '') . '>' . $opts . '</select></div><div id="ccalBody"><div class="p-loading-row">…</div></div>'
                : $empty)
            . '</div><script defer src="' . $js . '"></script>';
        echo $this->renderPanelPage('content_calendar', self::L('خطة المقالات', 'Article plan'), self::L('مقالات الشهر الجاي جاهزة بمواعيدها وصورها — مبنية على رحلاتك ومواسمها وبحث جوجل الحقيقي', 'Next month’s articles, scheduled with images — built on your trips, their seasons and real Google searches'), $body);
        exit;
    }

    /**
     * POST /api/content-calendar/activate-built {generated_id}
     * موقع مبني عندنا: بنضيفه كموقع (بنفس رابطه العام) + مدونة مستضافة للنشر + نجهّز الخطة.
     */
    public function activateBuilt(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($e = $this->verifyCsrf()) return $e;
        if ($e = $this->rateLimitGuard('user', 'content_calendar_activate', 5, 3600)) return $e;
        $gid = (int) $this->get('generated_id');
        $g = null;
        foreach ($this->builtSites() as $x) if ((int) $x['id'] === $gid) $g = $x;
        if (!$g) return $this->error(self::L('غير موجود', 'Not found'), 404);
        $wid = (new ContentCalendarService($this->db))->linkBuiltSite($this->owner(), $g);
        if ($wid <= 0) return $this->error(self::L('تعذر التفعيل', 'Could not turn it on'), 500);
        $this->log('Content Calendar Activated For Built Site', ['generated_id' => $gid, 'website_id' => $wid]);
        return $this->success(['website_id' => $wid], self::L('اتفعّلت ✓ — بنجهّز خطة الشهر', 'Turned on ✓ — preparing this month’s plan'));
    }

    /** GET /api/content-calendar/{site} */
    public function show(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $site = (int) ($params['site'] ?? 0);
        if (!$this->ownSite($site)) return $this->error(self::L('غير موجود', 'Not found'), 404);
        $svc = new ContentCalendarService($this->db);
        $q = class_exists('PlanUsage') ? PlanUsage::quota($this->owner(), 'article_generation') : [];
        return $this->success([
            'items' => $svc->items($site),
            'per_month' => ContentCalendarService::perMonth($this->owner()),
            'remaining' => array_key_exists('remaining', $q) ? $q['remaining'] : 0,
            'has_plan' => !empty($q['subscription_id']),
            'publish_to' => $this->destination($site),
            'today' => date('Y-m-d'),
        ]);
    }

    /** POST /api/content-calendar/{site}/build */
    public function build(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($e = $this->verifyCsrf()) return $e;
        if ($e = $this->rateLimitGuard('user', 'content_calendar_build', 6, 3600)) return $e;
        $site = (int) ($params['site'] ?? 0);
        if (!$this->ownSite($site)) return $this->error(self::L('غير موجود', 'Not found'), 404);
        $r = (new ContentCalendarService($this->db))->build($this->owner(), $site, 30);
        if (empty($r['success'])) return $this->error($r['error'] ?? 'Error', 422, ['reason' => $r['reason'] ?? null]);
        $msg = $r['added'] ? sprintf(self::L('اتضاف %d مقال للخطة', '%d articles added to the plan'), $r['added']) : self::L('خطة الشهر كاملة بالفعل', 'This month’s plan is already full');
        return array_merge($this->show($params), ['message' => $msg]);
    }

    private function item(array $params): array
    {
        if (!$this->isAuthenticated()) return [null, $this->error('Unauthorized', 401)];
        if ($e = $this->verifyCsrf()) return [null, $e];
        $svc = new ContentCalendarService($this->db);
        $row = $svc->get((int) ($params['id'] ?? 0), $this->owner());
        return $row ? [[$svc, $row], null] : [null, $this->error(self::L('غير موجود', 'Not found'), 404)];
    }

    /** POST /api/content-calendar/item/{id} {title, keyword, date} */
    public function update(array $params = []): array
    {
        [$x, $e] = $this->item($params);
        if ($e) return $e;
        [$svc, $row] = $x;
        $f = [];
        foreach (['title', 'keyword', 'date'] as $k) if ($this->get($k) !== null) $f[$k] = (string) $this->get($k);
        $r = $svc->update((int) $row['id'], $this->owner(), $f);
        if (empty($r['success'])) return $this->error($r['error'], 422);
        return array_merge($this->show(['site' => $row['website_id']]), ['message' => self::L('اتحفظ ✓', 'Saved ✓')]);
    }

    /** POST /api/content-calendar/item/{id}/skip */
    public function skip(array $params = []): array
    {
        [$x, $e] = $this->item($params);
        if ($e) return $e;
        [$svc, $row] = $x;
        if (!$svc->skip((int) $row['id'], $this->owner())) return $this->error(self::L('المقال ده بدأ يتكتب', 'This article is already being written'), 422);
        return $this->show(['site' => $row['website_id']]);
    }

    /** POST /api/content-calendar/item/{id}/image (ملف) */
    public function upload(array $params = []): array
    {
        [$x, $e] = $this->item($params);
        if ($e) return $e;
        if ($e = $this->rateLimitGuard('user', 'content_calendar_upload', 40, 3600)) return $e;
        [$svc, $row] = $x;
        if ($row['status'] !== 'planned') return $this->error(self::L('المقال ده بدأ يتكتب', 'This article is already being written'), 422);
        $r = (new SiteMediaService())->store((int) $row['website_id'], 'article', $_FILES['file'] ?? [], '/uploads/articles/' . $this->owner());
        if (empty($r['success'])) return $this->error($r['error'] ?? 'Error', 422);
        $svc->setImage((int) $row['id'], $this->owner(), (string) $r['url'], 'upload');
        return array_merge($this->show(['site' => $row['website_id']]), ['message' => self::L('الصورة اتغيرت ✓', 'Image updated ✓')]);
    }

    /** GET /api/content-calendar/item/{id}/preview - المقال بعد ما اتكتب */
    public function preview(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $p = (new ContentCalendarService($this->db))->preview((int) ($params['id'] ?? 0), $this->owner());
        if (!$p) return $this->error(self::L('المقال لسه ماتكتبش', 'The article hasn’t been written yet'), 404);
        return $this->success($p);
    }

    /** POST /api/content-calendar/item/{id}/write-now */
    public function writeNow(array $params = []): array
    {
        [$x, $e] = $this->item($params);
        if ($e) return $e;
        if ($e = $this->rateLimitGuard('user', 'content_calendar_write_now', 10, 3600)) return $e;
        [$svc, $row] = $x;
        $r = $svc->writeNow((int) $row['id'], $this->owner());
        if (empty($r['success'])) return $this->error($r['error'], 422);
        return array_merge($this->show(['site' => $row['website_id']]), ['message' => self::L('بدأنا نكتبه ✓ — هيبقى جاهز للمعاينة خلال دقايق، وبيتنشر في يومه', 'Writing started ✓ — ready to preview in a few minutes, published on its day')]);
    }

    /** POST /api/content-calendar/item/{id}/retry */
    public function retry(array $params = []): array
    {
        [$x, $e] = $this->item($params);
        if ($e) return $e;
        if ($e = $this->rateLimitGuard('user', 'content_calendar_retry', 10, 3600)) return $e;
        [$svc, $row] = $x;
        $r = $svc->retry((int) $row['id'], $this->owner());
        if (empty($r['success'])) return $this->error($r['error'], 422);
        return array_merge($this->show(['site' => $row['website_id']]), ['message' => self::L('بدأنا نكتبه تاني ✓ — هيبقى جاهز للمعاينة خلال دقايق', 'Writing again ✓ — ready to preview in a few minutes')]);
    }

    /** POST /api/content-calendar/item/{id}/image-ai */
    public function imageAi(array $params = []): array
    {
        [$x, $e] = $this->item($params);
        if ($e) return $e;
        if ($e = $this->rateLimitGuard('user', 'content_calendar_ai_image', 10, 3600)) return $e;
        [$svc, $row] = $x;
        $r = $svc->generateImage((int) $row['id'], $this->owner());
        if (empty($r['success'])) return $this->error($r['error'] ?? 'Error', 422);
        return array_merge($this->show(['site' => $row['website_id']]), ['message' => self::L('الصورة جاهزة ✓', 'Image ready ✓')]);
    }
}

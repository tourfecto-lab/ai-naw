<?php

/**
 * «خطة السوشيال»: نفس تجربة السيو للفيسبوك وإنستجرام في صفحة واحدة -
 * حلّل صفحتي (درجة من 100 بأسباب) ← منافسينك (تلقائي من مواقعهم) ← خطة بوستات متجددة مربوطة برحلاتك ومواسمك ومقالاتك وتقييماتك.
 * كل البيانات حقيقية (Meta / موقع المنافس / بيانات العميل)، والنشر بيمشي على محرك الخطة الشهرية الموجود بكل ضماناته.
 */
class SocialPlanController extends Controller
{
    private ?array $teamCtx = null;

    private static function L(string $ar, string $en): string
    {
        return function_exists('current_lang') && !str_starts_with(current_lang(), 'ar') ? $en : $ar;
    }

    private function team(): array
    {
        return $this->teamCtx ??= SocialTeam::context((int) ($this->user['id'] ?? 0));
    }

    /** الحساب صاحب بيانات السوشيال (حتى لو اللي داخل عضو في فريقه) */
    private function owner(): int
    {
        return (int) $this->team()['account_id'];
    }

    private function canManage(): ?array
    {
        return SocialTeam::can($this->team(), 'manage') ? null : $this->error(self::L('دورك في الفريق مايسمحش بالتعديل', 'Your team role can’t make changes'), 403);
    }

    private function website(): ?array
    {
        return $this->db->query('SELECT id, `' . WebsiteSchema::url() . '` AS url FROM websites WHERE user_id = ?' . WebsiteSchema::alive() . ' ORDER BY id ASC LIMIT 1', [$this->owner()])[0] ?? null;
    }

    /** GET /social-plan */
    public function index(array $params = []): void
    {
        $css = htmlspecialchars(asset_v('/assets/css/social-plan.css'), ENT_QUOTES, 'UTF-8');
        $js = htmlspecialchars(asset_v('/assets/js/social-plan.js'), ENT_QUOTES, 'UTF-8');
        $body = '<link rel="stylesheet" href="' . $css . '"><div id="splan" class="splan"><div class="p-loading-row">…</div></div><script defer src="' . $js . '"></script>';
        echo $this->renderPanelPage('social_plan', self::L('خطة السوشيال', 'Social plan'),
            self::L('حلّل صفحتك، اعرف منافسينك، وخطة بوستات بتتنشر لوحدها — مربوطة برحلاتك ومواسمك ومقالاتك وتقييماتك', 'Analyse your page, know your competitors, and a post plan that publishes itself — tied to your trips, seasons, articles and reviews'), $body);
        exit;
    }

    /** الخطة المتجددة/الأحدث للموقع + بوستاتها الجاية */
    private function planSummary(int $owner, int $wid): ?array
    {
        if (!class_exists('SocialMonthlyPlanService')) return null;
        $svc = new SocialMonthlyPlanService($this->db);
        if (!$svc->ready()) return null;
        $plans = array_values(array_filter($svc->listPlans($owner), fn ($p) => (int) $p['website_id'] === $wid && !in_array($p['status'], ['cancelled', 'failed'], true)));
        if (!$plans) return null;
        usort($plans, fn ($a, $b) => (int) $b['id'] <=> (int) $a['id']);
        $d = $svc->detail($owner, (int) $plans[0]['id']);
        $items = [];
        foreach ($d['items'] as $i) {
            if (($i['effective_status'] ?? $i['state'] ?? '') === 'skipped') continue;
            $items[] = ['id' => (int) $i['id'], 'at' => $i['scheduled_local'], 'platform' => $i['platform'], 'page' => $i['page_name'], 'theme' => $i['theme'],
                'content' => mb_substr((string) $i['content'], 0, 600), 'image' => $i['preview_url'] ?? null, 'format' => $i['format'] ?? 'photo', 'status' => $i['effective_status'] ?? $i['state'],
                'post_id' => !empty($i['social_post_id']) ? (int) $i['social_post_id'] : null, 'likes' => $i['likes'] !== null ? (int) $i['likes'] : null, 'comments' => $i['comments'] !== null ? (int) $i['comments'] : null, 'error' => $i['last_error'] ?? null];
        }
        usort($items, fn ($a, $b) => strcmp((string) $a['at'], (string) $b['at']));
        $p = $d['plan'];
        return ['id' => (int) $p['id'], 'status' => $p['status'], 'version' => (int) $p['version'], 'renew' => !empty($p['renew']), 'auto_approve' => !empty($p['auto_approve']),
            'starts_on' => $p['starts_on'] ?? null, 'ends_on' => $p['ends_on'] ?? null, 'renew_on' => $p['renew_on'] ?? null, 'strategy' => $p['strategy']['summary'] ?? null,
            'error' => $p['error_message'] ?? null, 'counts' => $d['counts'], 'items' => $items, 'worker_ready' => (bool) ($d['worker']['ready'] ?? false)];
    }

    /** نتايج الشهر ده: حجوزات وإيراد من لينكات البوستات + عملاء محتملين من التعليقات + ردود خاصة اتبعتت لوحدها */
    private function results(int $owner): array
    {
        $from = gmdate('Y-m-01 00:00:00');
        $to = gmdate('Y-m-d H:i:s', time() + 60);
        $out = ['month' => gmdate('Y-m'), 'tracking' => class_exists('SocialAttribution') && SocialAttribution::ready(), 'clicks' => 0, 'bookings' => 0, 'revenue' => 0.0, 'currency' => null,
            'top_posts' => [], 'leads' => 0, 'lead_trips' => [], 'auto_replies' => 0];
        if ($out['tracking']) {
            $t = SocialAttribution::totals($owner, $from, $to);
            $out = array_merge($out, ['clicks' => $t['clicks'], 'bookings' => $t['bookings'], 'revenue' => $t['revenue'], 'currency' => $t['currency']]);
            try {
                $posts = $this->db->query("SELECT sp.id, sp.content FROM social_posts sp WHERE sp.user_id = ? AND sp.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 45 DAY) AND EXISTS (SELECT 1 FROM social_links l WHERE l.social_post_id = sp.id AND l.clicks > 0) ORDER BY sp.id DESC LIMIT 60", [$owner]);
                $stats = SocialAttribution::forPosts($owner, array_column($posts, 'id'));
                foreach ($posts as $p) {
                    $st = $stats[(int) $p['id']] ?? null;
                    if (!$st || ($st['clicks'] === 0 && $st['bookings'] === 0)) continue;
                    $out['top_posts'][] = ['id' => (int) $p['id'], 'text' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $p['content'])), 0, 110)] + $st;
                }
                usort($out['top_posts'], fn ($a, $b) => [$b['bookings'], $b['revenue'], $b['clicks']] <=> [$a['bookings'], $a['revenue'], $a['clicks']]);
                $out['top_posts'] = array_slice($out['top_posts'], 0, 5);
            } catch (Throwable $e) {
            error_log('[SocialPlanController] ignored error: ' . $e->getMessage());
            }
        }
        try {
            new SocialCommentLeads($this->db);
            $rows = $this->db->query('SELECT trip_name, COUNT(*) AS n FROM social_comment_leads WHERE user_id = ? AND created_at >= ? GROUP BY trip_name ORDER BY n DESC', [$owner, $from]);
            foreach ($rows as $r) {
                $out['leads'] += (int) $r['n'];
                if ($r['trip_name'] !== null && $r['trip_name'] !== '' && count($out['lead_trips']) < 4) $out['lead_trips'][] = ['trip' => (string) $r['trip_name'], 'n' => (int) $r['n']];
            }
        } catch (Throwable $e) {
        error_log('[SocialPlanController] ignored error: ' . $e->getMessage());
        }
        try {
            $out['auto_replies'] = (int) ($this->db->query('SELECT COUNT(*) AS n FROM social_comments WHERE user_id = ? AND auto_handled = 1 AND private_replied_at >= ?', [$owner, $from])[0]['n'] ?? 0);
        } catch (Throwable $e) {
        error_log('[SocialPlanController] ignored error: ' . $e->getMessage());
        }
        return $out;
    }

    /** حالة الرد الخاص التلقائي على التعليقات */
    private function inbox(int $owner): array
    {
        $svc = new SocialCommentInbox($this->db);
        $ready = $svc->ready() && $svc->privateReady();
        return ['ready' => $ready, 'allowed' => !class_exists('SocialPackages') || SocialPackages::has($owner, 'auto_private'),
            'on' => $ready && $svc->settings($owner)['auto_private']];
    }

    /** لغات الخطة: لغة محتوى الموقع افتراضيًا، ولغات أسواق السيو بتاعته الأول (★) */
    private function languages(int $owner, ?array $site): array
    {
        $default = 'en';
        $market = [];
        if ($site) {
            try { $default = class_exists('ContentLanguageService') ? (string) ContentLanguageService::forWebsite((int) $site['id'], $owner) : 'en'; } catch (Throwable $e) { error_log('[SocialPlanController] content language failed: ' . $e->getMessage()); }
            try {
                foreach ((new SeoMarketService($this->db))->markets((int) $site['id'], $owner) as $m) $market[strtolower(substr((string) $m['language_code'], 0, 2))] = true;
            } catch (Throwable $e) {
            error_log('[SocialPlanController] ignored error: ' . $e->getMessage());
            }
        }
        $default = isset(SocialMonthlyPlanService::LANGUAGES[substr($default, 0, 2)]) ? substr($default, 0, 2) : 'en';
        $out = [];
        foreach (SocialMonthlyPlanService::LANGUAGES as $code => [$name, $design]) {
            $out[] = ['code' => $code, 'name' => $name, 'design' => $design, 'market' => isset($market[$code]), 'default' => $code === $default];
        }
        usort($out, fn ($a, $b) => [$b['default'], $b['market']] <=> [$a['default'], $a['market']]);
        return $out;
    }

    /** GET /api/social-plan */
    public function show(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        $owner = $this->owner();
        $site = $this->website();
        $audit = new SocialAuditService($this->db);
        $comp = new SocialCompetitorDiscovery($this->db);
        $hub = null;
        if ($site && class_exists('BusinessHubService')) {
            try {
                $prof = (new BusinessHubService($this->db))->profile($owner, (int) $site['id']);
                $hub = $prof && empty($prof['needs_reconfirmation']) && !empty($prof['facts']['business_name']);
            } catch (Throwable $e) {
                $hub = null;
            }
        }
        $photos = 0;
        try { $photos = count((new SocialMonthlyPlanService($this->db))->usableMediaIds($owner, 10)); } catch (Throwable $e) { error_log('[SocialPlanController] usable media failed: ' . $e->getMessage()); }
        return $this->success([
            'site' => $site ? ['id' => (int) $site['id'], 'url' => $site['url']] : null,
            'accounts' => $audit->latest($owner),
            'intake' => $this->intake($owner),
            'competitors' => $comp->overview($owner),
            'plan' => $site ? $this->planSummary($owner, (int) $site['id']) : null,
            'results' => $this->results($owner),
            'languages' => $this->languages($owner, $site),
            'inbox' => $this->inbox($owner),
            'ready' => ['profile' => $hub, 'photos' => $photos, 'autopilot_allowed' => class_exists('SocialAutopilotService') && SocialAutopilotService::planAllowsAutopilot($owner)],
            'can_manage' => SocialTeam::can($this->team(), 'manage'),
        ]);
    }

    /** POST /api/social-plan/audit {connection_id} أو {url} */
    public function audit(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($e = $this->verifyCsrf()) return $e;
        if ($e = $this->rateLimitGuard('user', 'social_audit', 12, 3600)) return $e;
        $owner = $this->owner();
        $svc = new SocialAuditService($this->db);
        $cid = (int) $this->get('connection_id', 0);
        $url = trim((string) $this->get('url', ''));
        if (!$cid && $url !== '') {
            if (!preg_match('~(facebook\.com|fb\.com|instagram\.com)/~i', $url)) return $this->error(self::L('حط لينك صفحة فيسبوك أو حساب إنستجرام', 'Paste a Facebook page or Instagram account link'), 422);
            $m = $svc->matchUrl($owner, $url);
            if (!$m) return $this->error(self::L('الصفحة دي مش مربوطة بحسابك لسه — اربطها الأول عشان نقرا أرقامها الحقيقية من Meta', 'This page isn’t connected yet — connect it first so we can read its real numbers from Meta'), 422, ['connect' => '/social#accounts']);
            $cid = (int) $m['id'];
        }
        if (!$cid) return $this->error(self::L('اختار الصفحة', 'Choose the page'), 422);
        $r = $svc->run($owner, $cid);
        if (empty($r['success'])) return $this->error($r['error'], 422);
        return array_merge($this->show($params), ['message' => self::L('التحليل جاهز ✓', 'Analysis ready ✓')]);
    }

    /** اللينكات اللي الزائر كتبها في الفحص المجاني (كوكي موقّعة) + حالة كل واحد جوه حسابه. مفيش أي رقم هنا. */
    private function intake(int $owner): ?array
    {
        $tok = (string) ($_COOKIE[FreeSocialCheckService::COOKIE] ?? '');
        $in = $tok !== '' ? FreeSocialCheckService::open($tok) : null;
        if (!$in) {
            return null;
        }
        $audit = new SocialAuditService($this->db);
        $own = [];
        foreach (['fb', 'ig'] as $k) {
            if (empty($in[$k])) {
                continue;
            }
            $m = $audit->matchUrl($owner, $in[$k]['url']);
            $own[] = ['platform' => $in[$k]['platform'], 'handle' => $in[$k]['handle'], 'url' => $in[$k]['url'], 'connected' => (bool) $m, 'connection_id' => $m ? (int) $m['id'] : null];
        }
        $tracked = [];
        try {
            foreach ($this->db->query("SELECT username FROM social_competitors WHERE user_id = ? AND platform = 'instagram'", [$owner]) ?: [] as $r) {
                $tracked[] = strtolower((string) $r['username']);
            }
        } catch (Throwable $e) {
        }
        $comps = [];
        foreach ($in['comp'] as $c) {
            $comps[] = $c['platform'] === 'instagram'
                ? ['platform' => 'instagram', 'handle' => $c['handle'], 'url' => $c['url'], 'tracked' => in_array($c['handle'], $tracked, true)]
                : ['platform' => 'facebook', 'handle' => $c['handle'], 'url' => $c['url'], 'ad_library' => SocialCompetitorDiscovery::adLibraryUrl(null, $c['url'], '')];
        }
        return ['own' => $own, 'competitors' => $comps];
    }

    /** POST /api/social-plan/intake/apply - ينفّذ التحليل على اللينكات المحفوظة: تحليل الصفحات المربوطة + إضافة منافسي إنستجرام */
    public function applyIntake(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($e = $this->canManage()) return $e;
        if ($e = $this->verifyCsrf()) return $e;
        if ($e = $this->rateLimitGuard('user', 'social_intake_apply', 6, 3600)) return $e;
        $owner = $this->owner();
        $intake = $this->intake($owner);
        if (!$intake) return $this->error(self::L('مفيش فحص محفوظ. ابدأ من صفحة تحليل السوشيال.', 'No saved check. Start from the social analysis page.'), 404);
        $audit = new SocialAuditService($this->db);
        $results = [];
        $fbH = $igH = null;
        foreach ($intake['own'] as $o) {
            if ($o['platform'] === 'facebook') $fbH = $o['handle']; else $igH = $o['handle'];
        }
        (new SocialIntelService($this->db))->markConverted($fbH, $igH);
        foreach ($intake['own'] as $o) {
            if (!$o['connected']) {
                $results[] = ['kind' => 'own', 'target' => $o['handle'], 'platform' => $o['platform'], 'status' => 'needs_connect', 'text' => self::L('الصفحة دي مش مربوطة بحسابك لسه - اربطها الأول عشان نقرا أرقامها من Meta.', 'This page is not connected yet - connect it first so we can read its numbers from Meta.')];
                continue;
            }
            try {
                $r = $audit->run($owner, (int) $o['connection_id']);
                $results[] = ['kind' => 'own', 'target' => $o['handle'], 'platform' => $o['platform'], 'status' => !empty($r['success']) ? 'done' : 'failed', 'text' => !empty($r['success']) ? self::L('التحليل جاهز', 'Analysis ready') : (string) ($r['error'] ?? '')];
            } catch (Throwable $e) {
                Logger::warning('Intake audit failed', ['message' => $e->getMessage()]);
                $results[] = ['kind' => 'own', 'target' => $o['handle'], 'platform' => $o['platform'], 'status' => 'failed', 'text' => self::L('تعذر التحليل دلوقتي', 'Could not analyse right now')];
            }
        }
        foreach ($intake['competitors'] as $c) {
            if ($c['platform'] !== 'instagram') {
                $results[] = ['kind' => 'competitor', 'target' => $c['handle'], 'platform' => 'facebook', 'status' => 'link', 'text' => self::L('Meta مابتسمحش بقراية أرقام صفحات فيسبوك التانية - إعلاناته الشغالة في مكتبة الإعلانات الرسمية.', 'Meta does not allow reading other Facebook pages’ numbers - his running ads are in the official Ad Library.'), 'url' => $c['ad_library']];
                continue;
            }
            if (!empty($c['tracked'])) {
                $results[] = ['kind' => 'competitor', 'target' => $c['handle'], 'platform' => 'instagram', 'status' => 'done', 'text' => self::L('بيتتابع بالفعل', 'Already tracked')];
                continue;
            }
            try {
                SocialPackages::assertCompetitorSlot($owner);
                (new SocialCompetitors($this->db))->add($owner, $c['handle']);
                $results[] = ['kind' => 'competitor', 'target' => $c['handle'], 'platform' => 'instagram', 'status' => 'done', 'text' => self::L('اتضاف وبيتتابع', 'Added and tracked')];
            } catch (SocialPackageLimit $e) {
                $results[] = ['kind' => 'competitor', 'target' => $c['handle'], 'platform' => 'instagram', 'status' => 'limit', 'text' => $e->getMessage()];
            } catch (InvalidArgumentException $e) {
                $results[] = ['kind' => 'competitor', 'target' => $c['handle'], 'platform' => 'instagram', 'status' => 'failed', 'text' => $e->getMessage()];
            } catch (Throwable $e) {
                Logger::warning('Intake competitor add failed', ['message' => $e->getMessage()]);
                $results[] = ['kind' => 'competitor', 'target' => $c['handle'], 'platform' => 'instagram', 'status' => 'failed', 'text' => self::L('مقدرناش نوصل لإنستجرام دلوقتي، جرّب تاني', 'Could not reach Instagram right now, try again')];
            }
        }
        return array_merge($this->show($params), ['intake_results' => $results, 'message' => self::L('اتنفّذ اللي اتقدر عليه ✓', 'Done what could be done ✓')]);
    }

    /** POST /api/social-plan/intake/dismiss - يمسح اللينكات المحفوظة */
    public function dismissIntake(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($e = $this->verifyCsrf()) return $e;
        if (!headers_sent()) {
            setcookie(FreeSocialCheckService::COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
        }
        unset($_COOKIE[FreeSocialCheckService::COOKIE]);
        return $this->success(['dismissed' => true]);
    }

    /** POST /api/social-plan/competitors/refresh */
    public function competitors(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($e = $this->verifyCsrf()) return $e;
        if ($e = $this->rateLimitGuard('user', 'social_competitor_discovery', 6, 3600)) return $e;
        $r = (new SocialCompetitorDiscovery($this->db))->discover($this->owner(), true);
        $msg = !empty($r['none']) ? self::L('مفيش منافسين متسجلين لسه — ضيفهم من «مركز المنافسين» أو صفحة الموقع', 'No competitors saved yet — add them in the Competitor hub or the website page')
            : sprintf(self::L('لقينا صفحات سوشيال لـ %d منافس', 'Found social pages for %d competitors'), (int) $r['found']);
        return array_merge($this->show($params), ['message' => $msg]);
    }

    /** POST /api/social-plan/competitors/find - «اكتشف منافسيني الحقيقيين» (من بيانات جوجل لموقعك، كاش 7 أيام) */
    public function findCompetitors(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($e = $this->canManage()) return $e;
        if ($e = $this->verifyCsrf()) return $e;
        if ($e = $this->rateLimitGuard('user', 'social_competitor_find', 4, 3600)) return $e;
        $owner = $this->owner();
        $site = $this->website();
        if (!$site || trim((string) $site['url']) === '') return $this->error(self::L('ضيف موقعك الأول عشان نلاقي منافسينك', 'Add your website first so we can find your competitors'), 422);
        $lang = 'en';
        foreach ($this->languages($owner, $site) as $l) {
            if (!empty($l['default'])) { $lang = (string) $l['code']; break; }
        }
        $countries = [];
        try {
            foreach ((new SeoMarketService($this->db))->markets((int) $site['id'], $owner) as $m) {
                $cc = SmartOnboardingService::countryForLocation((int) ($m['location_code'] ?? 0));
                if ($cc !== null) $countries[] = $cc;
            }
        } catch (Throwable $e) {
            error_log('[SocialPlanController] markets for competitor find failed: ' . $e->getMessage());
        }
        try {
            $r = (new SocialCompetitorDiscovery($this->db))->findReal($owner, (int) $site['id'], (string) $site['url'], $lang, array_values(array_unique($countries)), filter_var($this->get('force'), FILTER_VALIDATE_BOOLEAN));
        } catch (Throwable $e) {
            Logger::error('Social competitor find failed', ['error' => $e->getMessage()]);
            return $this->error(self::L('تعذر البحث عن المنافسين دلوقتي — جرّب كمان شوية', 'Could not search for competitors right now — try again shortly'), 503);
        }
        if (!empty($r['none'])) {
            $why = ['monthly_budget_reached' => self::L('البحث المدفوع وصل للحد الشهري للمنصة', 'The platform’s monthly paid-search limit was reached'), 'search_disabled' => self::L('البحث المدفوع مقفول حاليًا', 'Paid search is currently off'),
                'site_not_ranked' => self::L('موقعك لسه مش ظاهر في جوجل فمفيش كلمات مشتركة نقارن بيها', 'Your site is not visible on Google yet, so there are no shared keywords to compare'), 'provider_not_configured' => self::L('مصدر بيانات جوجل مش متاح دلوقتي', 'The Google data source is not available right now')][(string) ($r['reason'] ?? '')] ?? '';
            $msg = self::L('مالقيناش منافسين مؤكدين لموقعك.', 'We found no confirmed competitors for your site.') . ($why !== '' ? ' ' . $why . '.' : '') . ' ' . self::L('ضيف منافسين بإيدك من «مركز المنافسين».', 'Add competitors manually from the Competitor Center.');
        } else {
            $from = ['google_data' => self::L('من بيانات جوجل لموقعك', 'from Google data for your site'), 'cache' => self::L('من نتيجة محفوظة', 'from a saved result'), 'global_cache' => self::L('من نتيجة محفوظة لنفس الموقع', 'from a saved result for the same site'),
                'ci_candidates' => self::L('من مرشحي المنافسين اللي في حسابك', 'from competitor candidates already in your account'),
                'directory' => self::L('شركات في نفس سوقك ظهرت كمنافسين لعملاء تانيين (مش مقاسة من جوجل لموقعك)', 'companies in your market that showed up as competitors for other customers (not measured on Google for your site)')][(string) ($r['source'] ?? '')] ?? '';
            $msg = sprintf(self::L('لقينا %d منافس', 'Found %d competitors'), (int) $r['found']) . ($from !== '' ? ' ' . $from : '')
                . ($r['tracked'] ? sprintf(self::L(' — و%d بيتتابعوا بالأرقام على إنستجرام', ' — %d tracked with numbers on Instagram'), (int) $r['tracked']) : '')
                . ($r['limit_note'] ? ' — ' . $r['limit_note'] : '');
        }
        return array_merge($this->show($params), ['message' => $msg, 'find' => $r]);
    }

    /** POST /api/social-plan/auto-reply {on}: الرد الخاص التلقائي على اللي بيسأل عن السعر/الحجز */
    public function autoReply(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($e = $this->canManage()) return $e;
        if ($e = $this->verifyCsrf()) return $e;
        $owner = $this->owner();
        $on = filter_var($this->get('on'), FILTER_VALIDATE_BOOLEAN);
        $svc = new SocialCommentInbox($this->db);
        if (!$svc->ready() || !$svc->privateReady()) return $this->error(self::L('صندوق التعليقات لسه مش متفعّل على السيرفر', 'The comment inbox isn’t set up on the server yet'), 503);
        if ($on && class_exists('SocialPackages') && !SocialPackages::has($owner, 'auto_private')) {
            return $this->error(self::L('الرد التلقائي في الخاص مش ضمن باقتك — رقّي الباقة لتفعيله', 'Automatic private replies aren’t in your plan — upgrade to turn them on'), 403, ['upgrade' => '/pricing']);
        }
        $cur = $svc->settings($owner);
        $svc->saveSettings($owner, ['auto_private' => $on, 'auto_intents' => $cur['auto_intents'],
            // القالب الافتراضي مايتخزنش عشان يفضل يتبع لغة العميل
            'private_template' => in_array($cur['private_template'], [SocialCommentInbox::DEFAULT_TEMPLATE_AR, SocialCommentInbox::DEFAULT_TEMPLATE_EN], true) ? '' : $cur['private_template'],
            'public_ack' => in_array($cur['public_ack'], [SocialCommentInbox::DEFAULT_ACK_AR, SocialCommentInbox::DEFAULT_ACK_EN], true) ? '' : $cur['public_ack']
        ]);
        return array_merge($this->show($params), ['message' => $on ? self::L('الرد التلقائي اشتغل ✓ — أي حد يسأل عن السعر أو الحجز هيوصله رد في الخاص فيه الرحلة وسعرها ولينكها', 'Auto-reply is on ✓ — anyone asking about price or booking gets a private message with the trip, its price and link')
            : self::L('الرد التلقائي اتقفل', 'Auto-reply is off')]);
    }

    /** POST /api/social-plan/start {auto_approve, post_count} */
    public function start(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($e = $this->canManage()) return $e;
        if ($e = $this->verifyCsrf()) return $e;
        if ($e = $this->rateLimitGuard('user', 'social_smart_start', 4, 3600)) return $e;
        $site = $this->website();
        if (!$site) return $this->error(self::L('ضيف موقعك الأول', 'Add your website first'), 422);
        try {
            $r = (new SocialAutopilotService($this->db))->smartStart($this->owner(), (int) $site['id'], filter_var($this->get('auto_approve'), FILTER_VALIDATE_BOOLEAN), (int) $this->get('post_count', 12), (string) $this->get('language', ''));
        } catch (InvalidArgumentException $e) {
            return $this->error(class_exists('CustomerError') ? CustomerError::message($e->getMessage(), 422) : $e->getMessage(), 422);
        } catch (Throwable $e) {
            Logger::error('Social smart start failed', ['error' => $e->getMessage()]);
            return $this->error(self::L('تعذر البدء دلوقتي — جرّب كمان شوية', 'Could not start right now — try again shortly'), 503);
        }
        $msg = !empty($r['existing']) ? self::L('خطتك المتجددة شغالة بالفعل', 'Your rolling plan is already running')
            : self::L('بدأنا نجهّز بوستاتك ✓ — هتبقى جاهزة للمراجعة خلال دقايق', 'We’re preparing your posts ✓ — ready to review in a few minutes');
        return array_merge($this->show($params), ['message' => $msg]);
    }
}

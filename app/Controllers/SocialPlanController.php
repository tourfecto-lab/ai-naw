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

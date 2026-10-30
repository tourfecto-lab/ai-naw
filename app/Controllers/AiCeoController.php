<?php
require_once __DIR__ . '/../Services/AiCeo/AiCeoStore.php';
require_once __DIR__ . '/../Services/AiCeo/PlatformSnapshot.php';
require_once __DIR__ . '/../Services/AiCeo/AiCeoChat.php';
require_once __DIR__ . '/../Services/AiCeo/AiCostIntelligence.php';
require_once __DIR__ . '/../Services/AiCeo/TourismBusinessDb.php';
require_once __DIR__ . '/../Services/AiCeo/SalesEngine.php';
require_once __DIR__ . '/../Services/AiCeo/BrandSiteAudit.php';
require_once __DIR__ . '/../Services/AiCeo/BrandGrowth.php';
require_once __DIR__ . '/../Services/AiCeo/CustomerSuccess.php';
require_once __DIR__ . '/../Services/AiCeo/OpsAdvisor.php';
require_once __DIR__ . '/../Services/AiCeo/FinanceStrategy.php';
require_once __DIR__ . '/../Services/AiCeo/ExecutiveIntel.php';
require_once __DIR__ . '/../Services/AiCeo/AiCeoActivation.php';

/**
 * TOURFECTO AI CEO — AI Mission Control (لوحة الأدمن). العقل التنفيذي لإدارة شركة Tourfecto نفسها.
 *
 * الصلاحيات:
 *   المشاهدة والمحادثة والإيقاف الطارئ: أدمن عنده system.manage.
 *   الموافقات، وتغيير الوضع (AUTO/APPROVAL)، وإعادة التشغيل بعد الإيقاف: صاحب المنصة (PLATFORM_OWNER) بس:
 *   super_admin أو المالك الأساسي (AdminAccess::isPrimaryOwner).
 * الصورة بتتحسب من خدمات الأدمن وبتتخزن 10 دقايق (system_settings: aiceo_snapshot) عشان الصفحة والمحادثة يبقوا سريعين.
 */
class AiCeoController extends Controller
{
    private const SNAPSHOT_TTL = 600;

    private function allowed(): bool
    {
        if (!$this->isAuthenticated() || !in_array($this->user['role'] ?? '', ['admin', 'super_admin'], true)) return false;
        return !class_exists('AdminAccess') || AdminAccess::can($this->user, 'system.manage');
    }

    private function owner(): bool
    {
        if (!$this->allowed()) return false;
        return ($this->user['role'] ?? '') === 'super_admin' || (class_exists('AdminAccess') && AdminAccess::isPrimaryOwner($this->user));
    }

    public function index(array $params = []): array
    {
        if (!$this->allowed()) return $this->error('غير مصرح', 403);
        $js = htmlspecialchars(asset_v('/assets/js/admin-ai-ceo.js'), ENT_QUOTES, 'UTF-8');
        $css = htmlspecialchars(asset_v('/assets/css/brain.css'), ENT_QUOTES, 'UTF-8');
        $body = '<link rel="stylesheet" href="' . $css . '"><style>#aiCeo,#aiCeo .br-head,#aiCeo h3,#aiCeo h4,#aiCeo .br-tile,#aiCeo .p-table th,#aiCeo .p-table td{text-align:left}#aiCeo [dir=rtl]{text-align:right}</style><section id="aiCeo" dir="ltr" lang="en" data-no-translate data-csrf="' . htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') . '"></section><script defer src="' . $js . '"></script>';
        echo $this->renderPanelPage('ai-ceo', 'AI Mission Control', 'TOURFECTO AI CEO — runs Tourfecto itself, separate from customer brains', $body);
        exit;
    }

    /** GET /api/admin/ai-ceo?refresh=1 */
    public function data(array $params = []): array
    {
        if (!$this->allowed()) return $this->error('غير مصرح', 403);
        header('Cache-Control: private, no-store');
        try {
            $store = new AiCeoStore($this->db);
            [$snap, $fresh] = $this->snapshot((bool) $this->get('refresh', false));
            $decisions = $fresh ? $store->sync($snap) : $store->list();
            return $this->success(['settings' => $store->settings(), 'owner' => $this->owner(), 'snapshot' => $snap, 'decisions' => $decisions,
                'activity' => $store->activity(40), 'memory' => $store->memory(20), 'chat' => array_reverse($store->memory(12, 'chat')), 'csrf_token' => Csrf::token()]);
        } catch (Throwable $e) {
            Logger::error('AI CEO data failed', ['error' => $e->getMessage()]);
            return $this->error('AI Mission Control could not load right now.', 503);
        }
    }

    /** POST /api/admin/ai-ceo/decisions/{id}  {status, note} */
    public function decision(array $params = []): array
    {
        if ($e = $this->guard(true)) return $e;
        try {
            $store = new AiCeoStore($this->db);
            return $this->success(['decisions' => $store->setStatus((int) ($params['id'] ?? 0), (string) $this->get('status'), (int) $this->user['id'], (string) $this->get('note', '')), 'activity' => $store->activity(40)]);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** POST /api/admin/ai-ceo/mode  {mode: auto|approval} */
    public function mode(array $params = []): array
    {
        if ($e = $this->guard(true)) return $e;
        try {
            $store = new AiCeoStore($this->db);
            $settings = $store->setMode((string) $this->get('mode'), (int) $this->user['id']);
            $store->autoExecute();
            return $this->success(['settings' => $settings, 'decisions' => $store->list(), 'activity' => $store->activity(40)]);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** POST /api/admin/ai-ceo/pause  {on: bool, reason} — أي أدمن يقدر يوقف؛ صاحب المنصة بس يرجّع التشغيل */
    public function pause(array $params = []): array
    {
        $on = filter_var($this->get('on', true), FILTER_VALIDATE_BOOLEAN);
        if ($e = $this->guard(!$on)) return $e;
        $store = new AiCeoStore($this->db);
        return $this->success(['settings' => $store->setPaused($on, (int) $this->user['id'], (string) $this->get('reason', '')), 'activity' => $store->activity(40)]);
    }

    /** POST /api/admin/ai-ceo/chat  {message} */
    public function chat(array $params = []): array
    {
        if ($e = $this->guard(false)) return $e;
        if ($e = $this->rateLimitGuard('user', 'ai_ceo_chat', 60, 3600)) return $e;
        $q = trim(mb_substr((string) $this->get('message', ''), 0, 1000));
        if ($q === '') return $this->error('Write a question first.', 422);
        try {
            $store = new AiCeoStore($this->db);
            [$snap] = $this->snapshot(false);
            $costs = new AiCostIntelligence($this->db);
            // نفس السؤال على نفس الأرقام (بعد آخر تحديث للصورة، وفي آخر 30 دقيقة) = نفس الإجابة من غير طلب جديد
            $cache = function (string $question) use ($snap): ?string {
                $since = max((string) ($snap['generated_at'] ?? ''), gmdate('Y-m-d H:i:s', time() - 1800));
                return $this->db->query("SELECT body FROM aiceo_memory WHERE kind = 'chat' AND title = ? AND created_at >= ? AND body IS NOT NULL ORDER BY id DESC LIMIT 1", [mb_substr($question, 0, 255), $since])[0]['body'] ?? null;
            };
            $r = (new AiCeoChat(null, fn () => $costs->aiCeoBlocked(), $cache, (int) $this->user['id']))->answer($q, $snap, $store->list(), $store->settings());
            // الإجابات اللي مقدرش يجاوبها (مفيش ذكاء اصطناعي/الميزانية خلصت) مابتتحفظش، عشان ماتتعادش من الكاش
            if ($r['source'] !== 'none') $store->remember('chat', $q, $r['answer'], null, (int) $this->user['id']);
            $store->log((int) $this->user['id'], 'chat', ['intent' => $r['intent'], 'source' => $r['source']]);
            return $this->success($r + ['asked_at' => gmdate('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            Logger::error('AI CEO chat failed', ['error' => $e->getMessage()]);
            return $this->error('The AI CEO could not answer right now.', 503);
        }
    }

    /** GET /api/admin/ai-ceo/costs — AI Cost Optimization: الإنفاق والتوقع والميزانيات والاستهلاك غير الطبيعي والتوفير */
    public function costs(array $params = []): array
    {
        if (!$this->allowed()) return $this->error('غير مصرح', 403);
        header('Cache-Control: private, no-store');
        try {
            return $this->success((new AiCostIntelligence($this->db))->report() + ['owner' => $this->owner()]);
        } catch (Throwable $e) {
            Logger::error('AI CEO costs failed', ['error' => $e->getMessage()]);
            return $this->error('AI cost data could not load right now.', 503);
        }
    }

    /** POST /api/admin/ai-ceo/budgets — صاحب المنصة بس (حدود ومراقبة، مش صرف) */
    public function budgets(array $params = []): array
    {
        if ($e = $this->guard(true)) return $e;
        try {
            $costs = new AiCostIntelligence($this->db);
            $in = array_intersect_key((array) $this->get('budgets', []), AiCostIntelligence::BUDGET_KEYS);
            $saved = $costs->saveBudgets($in);
            (new AiCeoStore($this->db))->log((int) $this->user['id'], 'budgets_changed', $in);
            return $this->success(['budgets' => $saved]);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ------------------------------------------------------------------ المرحلة 3: قاعدة شركات السياحة

    /** GET /api/admin/ai-ceo/companies?q=&country=&city=&status=&track=&min_score=&has_email=&converted=&page= */
    public function companies(array $params = []): array
    {
        if (!$this->allowed()) return $this->error('غير مصرح', 403);
        header('Cache-Control: private, no-store');
        try {
            $db = new TourismBusinessDb($this->db);
            $f = [];
            foreach (['q', 'country', 'city', 'status', 'track', 'min_score'] as $k) $f[$k] = (string) $this->get($k, '');
            $f['has_email'] = filter_var($this->get('has_email', false), FILTER_VALIDATE_BOOLEAN);
            $f['converted'] = filter_var($this->get('converted', false), FILTER_VALIDATE_BOOLEAN);
            return $this->success(['results' => $db->search($f, (int) $this->get('page', 1)), 'stats' => $db->stats(), 'owner' => $this->owner()]);
        } catch (Throwable $e) {
            Logger::error('Tourism DB search failed', ['error' => $e->getMessage()]);
            return $this->error('The company database could not load right now.', 503);
        }
    }

    /** GET /api/admin/ai-ceo/companies/{id} */
    public function company(array $params = []): array
    {
        if (!$this->allowed()) return $this->error('غير مصرح', 403);
        try {
            return $this->success((new TourismBusinessDb($this->db))->profile((int) ($params['id'] ?? 0)));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    /** GET /api/admin/ai-ceo/companies-duplicates */
    public function duplicates(array $params = []): array
    {
        if (!$this->allowed()) return $this->error('غير مصرح', 403);
        return $this->success(['groups' => (new TourismBusinessDb($this->db))->duplicates()]);
    }

    /** POST /api/admin/ai-ceo/companies/{id}/consent  {event, channel, note} — أي أدمن يقدر يسجّل اعتراض أو إيقاف تواصل (حماية) */
    public function consent(array $params = []): array
    {
        if ($e = $this->guard(false)) return $e;
        try {
            $id = (int) ($params['id'] ?? 0);
            $event = (string) $this->get('event');
            (new TourismBusinessDb($this->db))->recordConsent($id, $event, (string) $this->get('channel', 'all'), (int) $this->user['id'], (string) $this->get('note', ''));
            (new AiCeoStore($this->db))->log((int) $this->user['id'], 'company_consent', ['lead_id' => $id, 'event' => $event]);
            return $this->success([], 'Saved');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** POST /api/admin/ai-ceo/companies/{id}/erase — طلب حذف (صاحب المنصة بس) */
    public function erase(array $params = []): array
    {
        if ($e = $this->guard(true)) return $e;
        try {
            $id = (int) ($params['id'] ?? 0);
            (new TourismBusinessDb($this->db))->erase($id, (int) $this->user['id'], (string) $this->get('note', ''));
            (new AiCeoStore($this->db))->log((int) $this->user['id'], 'company_erased', ['lead_id' => $id]);
            return $this->success([], 'Erased');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** POST /api/admin/ai-ceo/companies-merge  {primary_id, duplicate_id, reason} أو {undo: merge_id} — صاحب المنصة بس */
    public function merge(array $params = []): array
    {
        if ($e = $this->guard(true)) return $e;
        try {
            $db = new TourismBusinessDb($this->db);
            $store = new AiCeoStore($this->db);
            if ($undo = (int) $this->get('undo', 0)) {
                $db->undoMerge($undo);
                $store->log((int) $this->user['id'], 'company_merge_undone', ['merge_id' => $undo]);
                return $this->success(['groups' => $db->duplicates()]);
            }
            $id = $db->merge((int) $this->get('primary_id', 0), (int) $this->get('duplicate_id', 0), (int) $this->user['id'], (string) $this->get('reason', 'manual'));
            $store->log((int) $this->user['id'], 'company_merged', ['merge_id' => $id, 'primary_id' => (int) $this->get('primary_id', 0), 'duplicate_id' => (int) $this->get('duplicate_id', 0)]);
            return $this->success(['merge_id' => $id, 'groups' => $db->duplicates()]);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ------------------------------------------------------------------ المرحلة 4: المبيعات

    /** GET /api/admin/ai-ceo/sales */
    public function sales(array $params = []): array
    {
        if (!$this->allowed()) return $this->error('غير مصرح', 403);
        header('Cache-Control: private, no-store');
        try {
            $e = new SalesEngine($this->db);
            $reports = $this->db->query("SELECT title, body, created_at FROM aiceo_memory WHERE kind = 'result' AND title LIKE 'Sales %report%' ORDER BY id DESC LIMIT 8") ?: [];
            return $this->success(['settings' => $e->settings(), 'pipeline' => $e->pipeline(), 'queue' => $e->approvalQueue(100), 'active_noncompliant' => $e->activeNonCompliant(100),
                'today' => $e->periodReport(date('Y-m-d 00:00:00'), date('Y-m-d 00:00:00', strtotime('+1 day'))), 'reports' => $reports, 'owner' => $this->owner()]);
        } catch (Throwable $ex) {
            Logger::error('AI CEO sales failed', ['error' => $ex->getMessage()]);
            return $this->error('Sales data could not load right now.', 503);
        }
    }

    /** POST /api/admin/ai-ceo/sales/{action}  settings|approve|reevaluate|pause|crm */
    public function salesAction(array $params = []): array
    {
        $action = (string) ($params['action'] ?? '');
        // مراجعة الطابور حماية (بترجّع موافقات بس)، أي أدمن يقدر؛ الباقي لصاحب المنصة
        if ($e = $this->guard($action !== 'reevaluate')) return $e;
        try {
            $eng = new SalesEngine($this->db);
            $store = new AiCeoStore($this->db);
            $actor = (int) $this->user['id'];
            switch ($action) {
                case 'settings':
                    $out = $eng->saveSettings((array) $this->get('settings', []));
                    $store->log($actor, 'sales_settings_changed', ['basis' => $out['basis'], 'targets' => $out['targets']]);
                    return $this->success(['settings' => $out]);
                case 'approve':
                    $n = $eng->approveCompliant((array) $this->get('ids', []), filter_var($this->get('all', false), FILTER_VALIDATE_BOOLEAN));
                    $store->log($actor, 'sales_approved', ['count' => $n]);
                    return $this->success(['approved' => $n]);
                case 'reevaluate':
                    $n = $eng->reevaluateApproved();
                    $store->log($actor, 'sales_reevaluated', ['unapproved' => $n]);
                    return $this->success(['unapproved' => $n]);
                case 'pause':
                    $n = $eng->pauseSequences((array) $this->get('ids', []));
                    $store->log($actor, 'sales_sequences_paused', ['count' => $n]);
                    return $this->success(['paused' => $n]);
                case 'crm':
                    $owner = class_exists('AdminAccess') ? AdminAccess::primaryOwnerId() : 0;
                    $n = $eng->handoffToCrm($owner ?: $actor, $actor);
                    $store->log($actor, 'sales_crm_handoff', ['count' => $n, 'crm_user_id' => $owner ?: $actor]);
                    return $this->success(['moved' => $n]);
            }
            return $this->error('Unknown action', 404);
        } catch (InvalidArgumentException $ex) {
            return $this->error($ex->getMessage(), 422);
        } catch (Throwable $ex) {
            Logger::error('AI CEO sales action failed', ['action' => $action, 'error' => $ex->getMessage()]);
            return $this->error('The action failed.', 503);
        }
    }

    // ------------------------------------------------------------------ المرحلة 5: نمو علامة Tourfecto

    /** GET /api/admin/ai-ceo/brand */
    public function brand(array $params = []): array
    {
        if (!$this->allowed()) return $this->error('غير مصرح', 403);
        header('Cache-Control: private, no-store');
        try {
            $audit = new BrandSiteAudit($this->db);
            $bg = new BrandGrowth($this->db);
            $plans = $this->db->query("SELECT id, title, body, created_at FROM aiceo_memory WHERE kind = 'plan' AND title LIKE 'Brand content plan %' ORDER BY id DESC LIMIT 3") ?: [];
            return $this->success(['site_url' => $audit->siteUrl(), 'audit' => $audit->latest(), 'acquisition' => $bg->acquisition(30), 'presence' => $bg->presence($audit->siteUrl()),
                'plans' => array_map(static fn ($p) => ['id' => (int) $p['id'], 'title' => $p['title'], 'created_at' => $p['created_at'], 'plan' => json_decode((string) $p['body'], true)], $plans),
                'features' => count(BrandGrowth::verifiedFeatures()), 'owner' => $this->owner()]);
        } catch (Throwable $e) {
            Logger::error('AI CEO brand failed', ['error' => $e->getMessage()]);
            return $this->error('Brand data could not load right now.', 503);
        }
    }

    /** POST /api/admin/ai-ceo/brand/audit — فحص موقع Tourfecto دلوقتي (ببلاش، أي أدمن، مرتين في الساعة) */
    public function brandAudit(array $params = []): array
    {
        if ($e = $this->guard(false)) return $e;
        if ($e = $this->rateLimitGuard('user', 'ai_ceo_brand_audit', 2, 3600)) return $e;
        if (function_exists('set_time_limit')) @set_time_limit(300);
        try {
            $r = (new BrandSiteAudit($this->db))->run();
            (new AiCeoStore($this->db))->log((int) $this->user['id'], 'brand_audit', ['score' => $r['summary']['score'], 'pages' => $r['summary']['pages']]);
            return $this->success(['audit' => (new BrandSiteAudit($this->db))->latest()]);
        } catch (Throwable $e) {
            Logger::error('AI CEO brand audit failed', ['error' => $e->getMessage()]);
            return $this->error('The site audit failed.', 503);
        }
    }

    /** POST /api/admin/ai-ceo/brand/plan  {month, markets[]} — خطة المحتوى الشهرية (ذكاء اصطناعي على ميزانية AI CEO، صاحب المنصة بس) */
    public function brandPlan(array $params = []): array
    {
        if ($e = $this->guard(true)) return $e;
        if ((new AiCeoStore($this->db))->settings()['paused']) return $this->error('The AI CEO is on emergency stop.', 409);
        if (function_exists('set_time_limit')) @set_time_limit(180);
        try {
            $bg = new BrandGrowth($this->db);
            $out = $bg->monthlyPlan((int) $this->user['id'], (string) $this->get('month', date('Y-m', strtotime('first day of next month'))), (array) $this->get('markets', ['us', 'eu', 'gcc']), $bg->acquisition(30), new AiCostIntelligence($this->db));
            $store = new AiCeoStore($this->db);
            $store->remember('plan', $out['title'], json_encode($out['plan'] + ['_warnings' => $out['warnings']], JSON_UNESCAPED_UNICODE), null, (int) $this->user['id']);
            $store->log((int) $this->user['id'], 'brand_plan_created', ['title' => $out['title'], 'warnings' => count($out['warnings'])]);
            return $this->success($out);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            $m = $e->getMessage();
            $msg = str_starts_with($m, 'budget_') ? 'The AI CEO reached its ' . substr($m, 7) . ' AI budget. Raise it in AI Costs.' : ($m === 'invalid_plan' ? 'The AI returned an unusable plan. Try again.' : 'AI is not available right now.');
            return $this->error($msg, 503);
        }
    }

    // ------------------------------------------------------------------ المرحلة 6: نجاح العملاء والإيرادات

    /** GET /api/admin/ai-ceo/success */
    public function customerSuccess(array $params = []): array
    {
        if (!$this->allowed()) return $this->error('غير مصرح', 403);
        header('Cache-Control: private, no-store');
        try {
            return $this->success(['report' => (new CustomerSuccess($this->db))->report(), 'owner' => $this->owner()]);
        } catch (Throwable $e) {
            Logger::error('AI CEO success failed', ['error' => $e->getMessage()]);
            return $this->error('Customer success data could not load right now.', 503);
        }
    }

    /** POST /api/admin/ai-ceo/success/settings  {support_minutes, support_cost_hour, market_median_price} */
    public function successSettings(array $params = []): array
    {
        if ($e = $this->guard(true)) return $e;
        try {
            $s = (new CustomerSuccess($this->db))->saveSettings((array) $this->get('settings', []));
            (new AiCeoStore($this->db))->log((int) $this->user['id'], 'success_settings', $s);
            return $this->success(['settings' => $s]);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ------------------------------------------------------------------ المرحلة 7: مراقبة المنصة ومستشار التطوير

    /** GET /api/admin/ai-ceo/ops */
    public function ops(array $params = []): array
    {
        if (!$this->allowed()) return $this->error('غير مصرح', 403);
        header('Cache-Control: private, no-store');
        try {
            $o = new OpsAdvisor($this->db);
            $health = null;
            try { $h = (new PlatformHealthService($this->db))->report(); $health = ['overall' => $h['overall'] ?? null, 'counts' => $h['counts'] ?? [], 'sections' => array_map(static fn ($s) => ['checks' => array_map(static fn ($c) => ['label' => $c['label'] ?? $c['key'] ?? '', 'status' => $c['status'] ?? '', 'message' => mb_substr((string) ($c['message'] ?? ''), 0, 220), 'action' => $c['action'] ?? null], $s['checks'] ?? [])], $h['sections'] ?? [])]; } catch (Throwable $e) {}
            return $this->success(['checks' => $o->lastChecks(24), 'incidents' => $o->incidents('open'), 'resolved' => $o->incidents('resolved', 20), 'recurring' => $o->recurring(), 'backlog' => $o->backlog(),
                'done' => $o->backlog('done', 20), 'providers' => $o->providers(), 'abuse' => $o->abuse(), 'backups' => $o->backups(), 'health' => $health, 'settings' => $o->settings(), 'owner' => $this->owner()]);
        } catch (Throwable $e) {
            Logger::error('AI CEO ops failed', ['error' => $e->getMessage()]);
            return $this->error('Platform operations data could not load right now.', 503);
        }
    }

    /** POST /api/admin/ai-ceo/ops/run — فحص الصفحات والحوادث دلوقتي (أي أدمن، 6 مرات في الساعة) */
    public function opsRun(array $params = []): array
    {
        if ($e = $this->guard(false)) return $e;
        if ($e = $this->rateLimitGuard('user', 'ai_ceo_ops_run', 6, 3600)) return $e;
        if (function_exists('set_time_limit')) @set_time_limit(240);
        try {
            $r = (new OpsAdvisor($this->db))->runCycle();
            (new AiCeoStore($this->db))->log((int) $this->user['id'], 'ops_cycle', $r);
            return $this->success(['result' => $r]);
        } catch (Throwable $e) {
            Logger::error('AI CEO ops run failed', ['error' => $e->getMessage()]);
            return $this->error('The checks failed to run.', 503);
        }
    }

    /** POST /api/admin/ai-ceo/ops/{action}  backlog-add | backlog-status | issue | root-cause | settings */
    public function opsAction(array $params = []): array
    {
        $action = (string) ($params['action'] ?? '');
        if ($e = $this->guard($action !== 'root-cause')) return $e;
        $o = new OpsAdvisor($this->db);
        $store = new AiCeoStore($this->db);
        $uid = (int) $this->user['id'];
        try {
            switch ($action) {
                case 'backlog-add':
                    $id = $o->addBacklog(['source' => 'manual'] + array_intersect_key((array) $this->get('item', []), array_flip(['service', 'problem', 'evidence', 'severity', 'improvement', 'cost_estimate', 'benefit', 'test_method', 'needs_approval', 'metric_before'])));
                    $store->log($uid, 'backlog_added', ['id' => $id]);
                    return $this->success(['id' => $id]);
                case 'backlog-status':
                    $o->setBacklogStatus((int) $this->get('id', 0), (string) $this->get('status', ''), $this->get('metric_after') !== null ? (string) $this->get('metric_after') : null);
                    $store->log($uid, 'backlog_status', ['id' => (int) $this->get('id', 0), 'status' => (string) $this->get('status', '')]);
                    return $this->success([]);
                case 'issue':
                    $url = $o->createIssue((int) $this->get('id', 0));
                    $store->log($uid, 'backlog_issue', ['id' => (int) $this->get('id', 0), 'url' => $url]);
                    return $this->success(['url' => $url]);
                case 'root-cause':
                    $o->setRootCause((int) $this->get('id', 0), (string) $this->get('text', ''));
                    $store->log($uid, 'incident_root_cause', ['id' => (int) $this->get('id', 0)]);
                    return $this->success([]);
                case 'settings':
                    $s = $o->saveSettings((array) $this->get('settings', []));
                    $store->log($uid, 'ops_settings', ['base_url' => $s['base_url'], 'backup_dir' => $s['backup_dir'] !== '', 'github_issues' => $s['github_issues']]);
                    return $this->success(['settings' => $s]);
            }
            return $this->error('Unknown action', 404);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage() === 'github_not_enabled' ? 'GitHub issues are off. Turn them on in settings and set GITHUB_ISSUES_TOKEN and GITHUB_ISSUES_REPO in .env.' : 'GitHub did not accept the issue.', 409);
        }
    }

    // ------------------------------------------------------------------ المرحلة 8: الإدارة المالية والاستراتيجية

    /** GET /api/admin/ai-ceo/finance */
    public function finance(array $params = []): array
    {
        if (!$this->allowed()) return $this->error('غير مصرح', 403);
        header('Cache-Control: private, no-store');
        try {
            $f = new FinanceStrategy($this->db);
            [$snap] = $this->snapshot(false);
            $current = FinanceStrategy::metricValues($snap);
            $base = $f->baseline();
            $presets = [];
            foreach (FinanceStrategy::presets() as $k => $p) $presets[$k] = ['label' => $p['label'], 'levers' => $p['levers'], 'result' => FinanceStrategy::simulate($base, $p['levers'])];
            $board = $this->db->query("SELECT id, title, body, created_at FROM aiceo_memory WHERE kind = 'plan' AND title LIKE 'Boardroom:%' ORDER BY id DESC LIMIT 5") ?: [];
            return $this->success(['pnl' => $f->pnl(6), 'costs' => $f->costItems(), 'markets' => $f->markets(), 'savings' => $f->savings(), 'baseline' => $base, 'presets' => $presets,
                'goals' => $f->goals($current), 'current' => $current, 'metrics' => FinanceStrategy::METRICS, 'settings' => $f->settings(),
                'boardroom' => array_map(static fn ($b) => ['id' => (int) $b['id'], 'title' => $b['title'], 'created_at' => $b['created_at'], 'answer' => json_decode((string) $b['body'], true)], $board), 'owner' => $this->owner()]);
        } catch (Throwable $e) {
            Logger::error('AI CEO finance failed', ['error' => $e->getMessage()]);
            return $this->error('Finance data could not load right now.', 503);
        }
    }

    /** POST /api/admin/ai-ceo/finance/{action}  scenario | simulate (أي أدمن، حسابات بس) · cost | cost-delete | goal | goal-status | task | task-status | capacity | boardroom (صاحب المنصة) */
    public function financeAction(array $params = []): array
    {
        $action = (string) ($params['action'] ?? '');
        if ($e = $this->guard(!in_array($action, ['scenario', 'simulate'], true))) return $e;
        $f = new FinanceStrategy($this->db);
        $store = new AiCeoStore($this->db);
        $uid = (int) $this->user['id'];
        try {
            switch ($action) {
                case 'scenario': return $this->success(['result' => FinanceStrategy::scenarios((array) $this->get('input', []))]);
                case 'simulate': return $this->success(['result' => FinanceStrategy::simulate($f->baseline(), (array) $this->get('levers', []))]);
                case 'cost':
                    $id = $f->saveCostItem((array) $this->get('item', []));
                    $store->log($uid, 'finance_cost_saved', ['id' => $id]);
                    return $this->success(['id' => $id]);
                case 'cost-delete':
                    $f->deleteCostItem((int) $this->get('id', 0));
                    $store->log($uid, 'finance_cost_deleted', ['id' => (int) $this->get('id', 0)]);
                    return $this->success([]);
                case 'goal':
                    [$snap] = $this->snapshot(false);
                    $id = $f->addGoal((array) $this->get('goal', []), FinanceStrategy::metricValues($snap), $uid);
                    $store->log($uid, 'goal_added', ['id' => $id]);
                    return $this->success(['id' => $id]);
                case 'goal-status':
                    $f->setGoalStatus((int) $this->get('id', 0), (string) $this->get('status', ''));
                    $store->log($uid, 'goal_status', ['id' => (int) $this->get('id', 0), 'status' => (string) $this->get('status', '')]);
                    return $this->success([]);
                case 'task':
                    $id = $f->addTask((int) $this->get('goal_id', 0), (string) $this->get('title', ''), $this->get('week') ? (string) $this->get('week') : null);
                    return $this->success(['id' => $id]);
                case 'task-status':
                    $f->setTaskStatus((int) $this->get('id', 0), (string) $this->get('status', ''));
                    return $this->success([]);
                case 'capacity':
                    $f->saveCapacity($this->get('capacity_customers', ''));
                    $store->log($uid, 'finance_capacity', ['value' => (string) $this->get('capacity_customers', '')]);
                    return $this->success([]);
                case 'boardroom':
                    if ($store->settings()['paused']) return $this->error('The AI CEO is on emergency stop.', 409);
                    if ($e = $this->rateLimitGuard('user', 'ai_ceo_boardroom', 10, 3600)) return $e;
                    if (function_exists('set_time_limit')) @set_time_limit(180);
                    [$snap] = $this->snapshot(false);
                    $ctx = ['snapshot' => array_intersect_key($snap, array_flip(['revenue', 'signups', 'trials', 'customers', 'ai_cost', 'sales', 'brand', 'success', 'ops'])), 'pnl' => $f->pnl(3)['months'],
                        'markets' => array_slice($f->markets(), 0, 10), 'baseline' => $f->baseline()];
                    $out = $f->boardroom($uid, (string) $this->get('question', ''), $ctx, new AiCostIntelligence($this->db));
                    $store->remember('plan', $out['title'], json_encode($out['answer'], JSON_UNESCAPED_UNICODE), null, $uid);
                    $store->log($uid, 'boardroom', ['title' => $out['title']]);
                    return $this->success($out);
            }
            return $this->error('Unknown action', 404);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            $m = $e->getMessage();
            return $this->error(str_starts_with($m, 'budget_') ? 'The AI CEO reached its ' . substr($m, 7) . ' AI budget. Raise it in AI Costs.' : ($m === 'invalid_plan' ? 'The AI returned an unusable answer. Try again.' : 'AI is not available right now.'), 503);
        }
    }

    // ------------------------------------------------------------------ المرحلة 9: الذكاء التنفيذي ومؤشر صحة الشركة

    /** GET /api/admin/ai-ceo/executive */
    public function executive(array $params = []): array
    {
        if (!$this->allowed()) return $this->error('غير مصرح', 403);
        header('Cache-Control: private, no-store');
        try {
            $x = new ExecutiveIntel($this->db);
            [$snap] = $this->snapshot(false);
            $health = $x->health($snap);
            $decisions = (new AiCeoStore($this->db))->list();
            $reports = $this->db->query("SELECT id, title, body, created_at FROM aiceo_memory WHERE kind = 'result' AND title LIKE 'Executive report%' ORDER BY id DESC LIMIT 10") ?: [];
            $comp = $x->competitors();
            return $this->success(['health' => $health, 'weights' => ExecutiveIntel::WEIGHTS, 'today' => $x->report('daily', $snap, $health, $decisions), 'reports' => $reports,
                'review' => $x->decisionReview(30), 'lessons' => $x->lessons(), 'data_quality' => $x->dataQuality($snap), 'ai_quality' => $x->aiQuality(),
                'competitors' => $comp, 'prices' => ExecutiveIntel::priceComparison($comp, $x->ourPlans()), 'requests' => $x->customerRequests(), 'signals' => $x->marketSignals(),
                'knowledge' => $x->knowledge(), 'owner' => $this->owner()]);
        } catch (Throwable $e) {
            Logger::error('AI CEO executive failed', ['error' => $e->getMessage()]);
            return $this->error('Executive data could not load right now.', 503);
        }
    }

    /** POST /api/admin/ai-ceo/executive/{action}  competitor | competitor-delete | competitors-check | market-median | lesson | knowledge (صاحب المنصة) */
    public function executiveAction(array $params = []): array
    {
        $action = (string) ($params['action'] ?? '');
        if ($e = $this->guard(true)) return $e;
        $x = new ExecutiveIntel($this->db);
        $store = new AiCeoStore($this->db);
        $uid = (int) $this->user['id'];
        try {
            switch ($action) {
                case 'competitor':
                    $id = $x->saveCompetitor((array) $this->get('item', []));
                    $store->log($uid, 'competitor_saved', ['id' => $id]);
                    return $this->success(['id' => $id]);
                case 'competitor-delete':
                    $x->deleteCompetitor((int) $this->get('id', 0));
                    $store->log($uid, 'competitor_deleted', ['id' => (int) $this->get('id', 0)]);
                    return $this->success([]);
                case 'competitors-check':
                    if ($e = $this->rateLimitGuard('user', 'ai_ceo_competitors_check', 4, 3600)) return $e;
                    $n = $x->checkCompetitors();
                    $store->log($uid, 'competitors_checked', ['changed' => $n]);
                    return $this->success(['changed' => $n]);
                case 'market-median':
                    $p = ExecutiveIntel::priceComparison($x->competitors(), $x->ourPlans());
                    if ($p['competitor_median'] === null) return $this->error('Add competitor prices first.', 422);
                    $this->db->query("INSERT INTO system_settings (setting_key, setting_value, is_secret, category) VALUES ('cs_market_median_price', ?, 0, 'ai_ceo') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [(string) $p['competitor_median']]);
                    $store->log($uid, 'market_median_set', ['value' => $p['competitor_median']]);
                    return $this->success(['value' => $p['competitor_median']]);
                case 'lesson':
                case 'knowledge':
                    $title = trim((string) $this->get('title', ''));
                    if ($title === '' || mb_strlen($title) > 200) return $this->error('Enter a short title.', 422);
                    $store->remember('note', ($action === 'lesson' ? 'Lesson: ' : 'Knowledge: ') . $title, mb_substr((string) $this->get('body', ''), 0, 20000), null, $uid);
                    $store->log($uid, $action . '_added', ['title' => mb_substr($title, 0, 120)]);
                    return $this->success([]);
            }
            return $this->error('Unknown action', 404);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    // ------------------------------------------------------------------ المرحلة 10: التجهيز والتفعيل التدريجي

    /** GET /api/admin/ai-ceo/activation */
    public function activation(array $params = []): array
    {
        if (!$this->allowed()) return $this->error('غير مصرح', 403);
        header('Cache-Control: private, no-store');
        try {
            return $this->success((new AiCeoActivation($this->db))->report() + ['owner' => $this->owner()]);
        } catch (Throwable $e) {
            Logger::error('AI CEO activation failed', ['error' => $e->getMessage()]);
            return $this->error('Setup data could not load right now.', 503);
        }
    }

    /** POST /api/admin/ai-ceo/memory  {kind: note|plan, title, body} — ملاحظة أو خطة في الذاكرة المؤسسية */
    public function memory(array $params = []): array
    {
        if ($e = $this->guard(false)) return $e;
        $kind = (string) $this->get('kind', 'note');
        $title = trim(mb_substr((string) $this->get('title', ''), 0, 255));
        if (!in_array($kind, ['note', 'plan'], true) || $title === '') return $this->error('A title and a kind (note or plan) are required.', 422);
        $store = new AiCeoStore($this->db);
        $store->remember($kind, $title, trim((string) $this->get('body', '')) ?: null, null, (int) $this->user['id']);
        $store->log((int) $this->user['id'], 'memory_added', ['kind' => $kind]);
        return $this->success(['memory' => $store->memory(20)]);
    }

    private function guard(bool $ownerOnly): ?array
    {
        if (!$this->allowed()) return $this->error('غير مصرح', 403);
        if ($ownerOnly && !$this->owner()) return $this->error('Only the platform owner can do this.', 403);
        if ($e = $this->verifyCsrf()) return $e;
        return $this->rateLimitGuard('user', 'ai_ceo_write', 120, 3600);
    }

    /** @return array{0:array,1:bool} الصورة، وهل اتحسبت دلوقتي */
    private function snapshot(bool $refresh): array
    {
        if (!$refresh) {
            $row = $this->db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'aiceo_snapshot' LIMIT 1")[0]['setting_value'] ?? null;
            $cached = $row ? json_decode((string) $row, true) : null;
            if (is_array($cached) && !empty($cached['generated_at']) && strtotime($cached['generated_at'] . ' UTC') > time() - self::SNAPSHOT_TTL) return [$cached, false];
        }
        $snap = (new PlatformSnapshot($this->db))->collect();
        $this->db->query("INSERT INTO system_settings (setting_key, setting_value, is_secret, category) VALUES ('aiceo_snapshot', ?, 0, 'ai_ceo')
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [json_encode($snap, JSON_UNESCAPED_UNICODE)]);
        return [$snap, true];
    }
}

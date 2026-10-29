<?php
require_once __DIR__ . '/../Services/AiCeo/AiCeoStore.php';
require_once __DIR__ . '/../Services/AiCeo/PlatformSnapshot.php';
require_once __DIR__ . '/../Services/AiCeo/AiCeoChat.php';
require_once __DIR__ . '/../Services/AiCeo/AiCostIntelligence.php';
require_once __DIR__ . '/../Services/AiCeo/TourismBusinessDb.php';
require_once __DIR__ . '/../Services/AiCeo/SalesEngine.php';

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
        $body = '<link rel="stylesheet" href="' . $css . '"><section id="aiCeo" dir="ltr" lang="en" data-no-translate data-csrf="' . htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') . '"></section><script defer src="' . $js . '"></script>';
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

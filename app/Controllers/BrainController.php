<?php
require_once __DIR__ . '/../Services/Brain/CompanyBrainService.php';
require_once __DIR__ . '/../Services/Brain/BrainDecisionStore.php';
require_once __DIR__ . '/../Services/Brain/SharedIntelligence.php';
require_once __DIR__ . '/../Services/Brain/BrainActions.php';

/** العقل الاستراتيجي لكل شركة: الفهم + القرارات + النتايج. كل حاجة مقيّدة بحساب المستخدم الحالي. */
class BrainController extends Controller
{
    public function index(array $params = []): void
    {
        if (!$this->isAuthenticated()) { header('Location: /login'); exit; }
        $esc = static fn (string $p) => htmlspecialchars(asset_v($p), ENT_QUOTES, 'UTF-8');
        $body = '<link rel="stylesheet" href="' . $esc('/assets/css/brain.css') . '"><section id="brainRoot" aria-live="polite" data-csrf="' . htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') . '"></section><script defer src="' . $esc('/assets/js/brain.js') . '"></script>';
        $ar = current_lang() === 'ar';
        echo $this->renderPanelPage('brain', $ar ? 'العقل الاستراتيجي' : 'Strategic brain', $ar ? 'فاهم شركتك، وبيرتّب أولوياتك، وبيقيس نتيجة كل قرار' : 'Understands your business, ranks your priorities and measures every decision', $body);
        exit;
    }

    /** GET /api/brain?website_id= */
    public function data(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        header('Cache-Control: no-store, private');
        try {
            // الأدمن قافل العقل للكل
            if (!BrainConfig::get('brain_enabled', $this->db)) return $this->success(['disabled' => true, 'websites' => [], 'snapshot' => null, 'decisions' => [], 'results' => []]);
            [$uid, $wid, $sites] = $this->site();
            if (!$wid) return $this->success(['websites' => [], 'snapshot' => null, 'decisions' => [], 'results' => []]);
            $snapshot = (new CompanyBrainService($this->db))->snapshot($uid, $wid);
            $store = new BrainDecisionStore($this->db);
            // الأرقام المجمّعة بتتشاف بس للشركات اللي بتشارك نتايجها هي كمان
            $sharing = new SharedIntelligence($this->db);
            $optedIn = $sharing->optedIn($uid);
            $patterns = $optedIn ? $sharing->patterns() : [];
            $autonomy = $store->autonomy($uid) && (bool) BrainConfig::get('brain_autonomy_allowed', $this->db);
            return $this->success(['websites' => $sites, 'website_id' => $wid, 'snapshot' => $snapshot, 'decisions' => $store->sync($uid, $wid, $snapshot, $patterns, $autonomy, current_lang() === 'en' ? 'en' : 'ar'), 'results' => $store->results($uid, $wid),
                'sharing' => ['opted_in' => $optedIn, 'patterns' => count($patterns), 'min_companies' => SharedIntelligence::MIN_COMPANIES], 'autonomy' => $autonomy]);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Logger::error('Brain unavailable', ['user_id' => (int) $this->user['id'], 'error' => $e->getMessage()]);
            return $this->error(current_lang() === 'ar' ? 'تعذر تحميل العقل دلوقتي. جرّب تاني بعد شوية.' : 'The brain could not load right now. Please try again shortly.', 503);
        }
    }

    /** POST /api/brain/decisions/{id}  {status, website_id} */
    public function decision(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($e = $this->verifyCsrf()) return $e;
        try {
            [$uid, $wid] = $this->site();
            if (!$wid) return $this->error('Website not found', 404);
            $snapshot = (new CompanyBrainService($this->db))->snapshot($uid, $wid, false);
            $store = new BrainDecisionStore($this->db);
            $decisions = $store->setStatus($uid, $wid, (int) ($params['id'] ?? 0), (string) $this->get('status'), $snapshot, current_lang() === 'en' ? 'en' : 'ar');
            return $this->success(['decisions' => $decisions, 'results' => $store->results($uid, $wid)]);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Logger::error('Brain decision update failed', ['user_id' => (int) $this->user['id'], 'error' => $e->getMessage()]);
            return $this->error(current_lang() === 'ar' ? 'تعذر حفظ القرار.' : 'Could not save the decision.', 503);
        }
    }

    /** GET /api/brain/decisions/{id}/action — التنفيذ جوه الخدمة: هيحصل إيه وبيكلّف قد إيه، قبل الموافقة */
    public function actionPreview(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        try {
            [$uid, , $action] = $this->decisionAction((int) ($params['id'] ?? 0));
            return $this->success(['preview' => (new BrainActions($this->db))->preview($uid, $action)]);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Logger::error('Brain action preview failed', ['user_id' => (int) $this->user['id'], 'error' => $e->getMessage()]);
            return $this->error(current_lang() === 'ar' ? 'تعذر تجهيز التنفيذ.' : 'Could not prepare this action.', 503);
        }
    }

    /** POST /api/brain/decisions/{id}/action  {confirm: true} — بينفّذ بعد موافقة صريحة، والنتيجة بتروح طابور الموافقة في الخدمة */
    public function actionRun(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($e = $this->verifyCsrf()) return $e;
        if (!filter_var($this->get('confirm', false), FILTER_VALIDATE_BOOLEAN)) return $this->error(current_lang() === 'ar' ? 'لازم توافق الأول.' : 'Please confirm first.', 422);
        if ($e = $this->rateLimitGuard('user', 'brain_action', 6, 3600)) return $e;
        try {
            [$uid, $wid, $action, $row] = $this->decisionAction((int) ($params['id'] ?? 0));
            $result = (new BrainActions($this->db))->run($uid, $action);
            $store = new BrainDecisionStore($this->db);
            // القرار بقى "شغالين عليه"؛ بيتقفل لما الردود تتنشر فعلًا والرقم ينزل
            if ($row['status'] === 'proposed' && $result['drafted'] > 0) {
                $store->setStatus($uid, $wid, (int) $row['id'], 'approved', (new CompanyBrainService($this->db))->snapshot($uid, $wid, false), current_lang() === 'en' ? 'en' : 'ar');
            }
            $this->log('Brain service action', ['decision' => $row['decision_key'], 'action' => $action, 'drafted' => $result['drafted'], 'skipped' => $result['skipped']]);
            return $this->success(['result' => $result, 'decisions' => $store->list($uid, $wid), 'results' => $store->results($uid, $wid)]);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'busy') return $this->error(current_lang() === 'ar' ? 'فيه تنفيذ شغال دلوقتي. استنى دقيقة.' : 'Another action is running. Please wait a minute.', 409);
            Logger::error('Brain action failed', ['user_id' => (int) $this->user['id'], 'error' => $e->getMessage()]);
            return $this->error(current_lang() === 'ar' ? 'تعذر التنفيذ.' : 'The action failed.', 503);
        } catch (Throwable $e) {
            Logger::error('Brain action failed', ['user_id' => (int) $this->user['id'], 'error' => $e->getMessage()]);
            return $this->error(current_lang() === 'ar' ? 'تعذر التنفيذ.' : 'The action failed.', 503);
        }
    }

    /** @return array{0:int,1:int,2:string,3:array} القرار لازم يكون بتاع المستخدم وموقعه، وليه تنفيذ جوه خدمة */
    private function decisionAction(int $id): array
    {
        [$uid, $wid] = $this->site();
        $row = $this->db->query('SELECT * FROM brain_decisions WHERE id = ? AND user_id = ? AND website_id = ? LIMIT 1', [$id, $uid, $wid])[0] ?? null;
        if (!$row) throw new InvalidArgumentException('Decision not found');
        $action = BrainActions::forDecision((string) $row['decision_key']);
        if ($action === null) throw new InvalidArgumentException('No in-service action for this decision');
        return [$uid, $wid, $action, $row];
    }

    /** POST /api/brain/autonomy  {on: bool} — التشغيل الذاتي: القرارات اللي مافيهاش صرف بتتحول لمهام وتنبيهات لوحدها */
    public function autonomy(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($e = $this->verifyCsrf()) return $e;
        try {
            $store = new BrainDecisionStore($this->db);
            $store->setAutonomy((int) $this->user['id'], filter_var($this->get('on', false), FILTER_VALIDATE_BOOLEAN));
            return $this->success(['autonomy' => $store->autonomy((int) $this->user['id'])]);
        } catch (Throwable $e) {
            Logger::error('Brain autonomy update failed', ['user_id' => (int) $this->user['id'], 'error' => $e->getMessage()]);
            return $this->error(current_lang() === 'ar' ? 'تعذر حفظ الاختيار.' : 'Could not save your choice.', 503);
        }
    }

    /** POST /api/brain/sharing  {on: bool} — مشاركة نتايج القرارات من غير هوية (اختياري) */
    public function sharing(array $params = []): array
    {
        if (!$this->isAuthenticated()) return $this->error('Unauthorized', 401);
        if ($e = $this->verifyCsrf()) return $e;
        try {
            $s = new SharedIntelligence($this->db);
            $s->setOptIn((int) $this->user['id'], filter_var($this->get('on', false), FILTER_VALIDATE_BOOLEAN));
            return $this->success(['opted_in' => $s->optedIn((int) $this->user['id'])]);
        } catch (Throwable $e) {
            Logger::error('Brain sharing update failed', ['user_id' => (int) $this->user['id'], 'error' => $e->getMessage()]);
            return $this->error(current_lang() === 'ar' ? 'تعذر حفظ الاختيار.' : 'Could not save your choice.', 503);
        }
    }

    /** @return array{0:int,1:int,2:array} المستخدم، والموقع المختار (لازم يكون بتاعه)، ومواقعه */
    private function site(): array
    {
        $uid = (int) $this->user['id'];
        $sites = (new CompanyBrainService($this->db))->websites($uid);
        $want = (int) $this->get('website_id', 0);
        $ids = array_map(static fn ($s) => (int) $s['id'], $sites);
        $wid = in_array($want, $ids, true) ? $want : ($ids[0] ?? 0);
        return [$uid, $wid, $sites];
    }
}

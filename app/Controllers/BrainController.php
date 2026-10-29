<?php
require_once __DIR__ . '/../Services/Brain/CompanyBrainService.php';
require_once __DIR__ . '/../Services/Brain/BrainDecisionStore.php';

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
            [$uid, $wid, $sites] = $this->site();
            if (!$wid) return $this->success(['websites' => [], 'snapshot' => null, 'decisions' => [], 'results' => []]);
            $snapshot = (new CompanyBrainService($this->db))->snapshot($uid, $wid);
            $store = new BrainDecisionStore($this->db);
            return $this->success(['websites' => $sites, 'website_id' => $wid, 'snapshot' => $snapshot, 'decisions' => $store->sync($uid, $wid, $snapshot), 'results' => $store->results($uid, $wid)]);
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
            $snapshot = (new CompanyBrainService($this->db))->snapshot($uid, $wid);
            $store = new BrainDecisionStore($this->db);
            $decisions = $store->setStatus($uid, $wid, (int) ($params['id'] ?? 0), (string) $this->get('status'), $snapshot);
            return $this->success(['decisions' => $decisions, 'results' => $store->results($uid, $wid)]);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Logger::error('Brain decision update failed', ['user_id' => (int) $this->user['id'], 'error' => $e->getMessage()]);
            return $this->error(current_lang() === 'ar' ? 'تعذر حفظ القرار.' : 'Could not save the decision.', 503);
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

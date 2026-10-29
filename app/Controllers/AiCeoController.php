<?php
require_once __DIR__ . '/../Services/AiCeo/AiCeoStore.php';
require_once __DIR__ . '/../Services/AiCeo/PlatformSnapshot.php';
require_once __DIR__ . '/../Services/AiCeo/AiCeoChat.php';

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
            $r = (new AiCeoChat())->answer($q, $snap, $store->list(), $store->settings());
            $store->remember('chat', $q, $r['answer'], null, (int) $this->user['id']);
            $store->log((int) $this->user['id'], 'chat', ['intent' => $r['intent'], 'source' => $r['source']]);
            return $this->success($r + ['asked_at' => gmdate('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            Logger::error('AI CEO chat failed', ['error' => $e->getMessage()]);
            return $this->error('The AI CEO could not answer right now.', 503);
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

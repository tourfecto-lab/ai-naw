<?php
require_once __DIR__ . '/../Services/Brain/BrainSchema.php';
require_once __DIR__ . '/../Services/Brain/BrainConfig.php';
require_once __DIR__ . '/../Services/Brain/SharedIntelligence.php';
require_once __DIR__ . '/../Services/Brain/MarketIntelligenceService.php';

/**
 * لوحة الأدمن للعقل الاستراتيجي: حالة العقل عند الشركات، وصحة مصادر السوق، وإعدادات العقل،
 * وحدود استهلاك الذكاء الاصطناعي لكل شركة (AIGovernor). باقي التحكم موجود في صفحات قايمة بالفعل
 * (توزيع المزودين، تكاليف الاستخدام، الباقات والأسعار، ميزانية بيانات السيو) والصفحة بتربط بيها.
 * الأرقام هنا مجمّعة على مستوى المنصة؛ مفيش بيانات شركة بعينها غير العدد.
 */
class BrainAdminController extends Controller
{
    private const AI_KEYS = ['ai_limit_per_minute' => [0, 600], 'ai_limit_per_day' => [0, 100000], 'ai_limit_usd_per_day' => [0, 10000], 'ai_limit_usd_per_month' => [0, 100000]];

    private function allowed(): bool
    {
        return $this->isAuthenticated() && in_array($this->user['role'] ?? '', ['admin', 'super_admin'], true);
    }

    public function index(array $params = []): array
    {
        if (!$this->allowed()) return $this->error('غير مصرح', 403);
        $js = htmlspecialchars(asset_v('/assets/js/admin-brain.js'), ENT_QUOTES, 'UTF-8');
        $css = htmlspecialchars(asset_v('/assets/css/brain.css'), ENT_QUOTES, 'UTF-8');
        $body = '<link rel="stylesheet" href="' . $css . '"><section id="adminBrain" data-csrf="' . htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') . '"></section><script defer src="' . $js . '"></script>';
        echo $this->renderPanelPage('admin-brain', 'العقل الاستراتيجي — الإدارة', 'الحالة، والمصادر، والإعدادات، وحدود الذكاء الاصطناعي', $body);
        exit;
    }

    /** GET /api/admin/brain */
    public function data(array $params = []): array
    {
        if (!$this->allowed()) return $this->error('غير مصرح', 403);
        header('Cache-Control: private, no-store');
        try {
            BrainSchema::ensure($this->db);
            $q = fn (string $sql) => $this->db->query($sql) ?: [];
            $status = [];
            foreach ($q('SELECT status, COUNT(*) AS c FROM brain_decisions GROUP BY status') as $r) $status[$r['status']] = (int) $r['c'];
            $outcomes = [];
            foreach ($q('SELECT outcome, COUNT(*) AS c FROM brain_decisions WHERE outcome IS NOT NULL GROUP BY outcome') as $r) $outcomes[$r['outcome']] = (int) $r['c'];
            $areas = $q("SELECT area, COUNT(*) AS total, SUM(outcome = 'improved') AS improved, SUM(outcome = 'worse') AS worse FROM brain_decisions GROUP BY area ORDER BY total DESC");
            $ai = AIGovernor::effectiveLimits();
            $aiMonth = $q("SELECT COUNT(*) AS calls, COALESCE(SUM(cost_in_usd), 0) AS cost FROM api_usage_logs WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")[0] ?? ['calls' => 0, 'cost' => 0];
            return $this->success([
                'companies' => (int) ($q('SELECT COUNT(DISTINCT user_id) AS c FROM brain_decisions')[0]['c'] ?? 0),
                'autonomy_on' => (int) ($q('SELECT COUNT(*) AS c FROM brain_settings WHERE autonomy = 1')[0]['c'] ?? 0),
                'sharing_on' => (int) ($q('SELECT COUNT(*) AS c FROM brain_sharing WHERE opted_in = 1')[0]['c'] ?? 0),
                'shared_patterns' => count((new SharedIntelligence($this->db))->patterns()),
                'executed_today' => (int) ($q("SELECT COUNT(*) AS c FROM action_executions WHERE source_type = 'brain' AND created_at >= CURDATE()")[0]['c'] ?? 0),
                'status' => $status, 'outcomes' => $outcomes, 'areas' => $areas,
                'sources' => $q('SELECT source, fetched_at, status, error, rows_saved FROM market_fetch_log ORDER BY fetched_at DESC LIMIT 60'),
                'settings' => BrainConfig::all($this->db),
                'ai_limits' => $ai, 'ai_month' => ['calls' => (int) $aiMonth['calls'], 'cost' => round((float) $aiMonth['cost'], 2)],
                'csrf_token' => Csrf::token(),
            ]);
        } catch (Throwable $e) {
            Logger::error('Brain admin data failed', ['error' => $e->getMessage()]);
            return $this->error('تعذر تحميل بيانات العقل.', 503);
        }
    }

    private function guard(): ?array
    {
        if (!$this->allowed()) return $this->error('غير مصرح', 403);
        if ($e = $this->verifyCsrf()) return $e;
        return $this->rateLimitGuard('user', 'brain_admin', 30, 60);
    }

    /** POST /api/admin/brain/settings  {brain: {...}, ai: {...}} */
    public function save(array $params = []): array
    {
        if ($e = $this->guard()) return $e;
        try {
            $brain = (array) $this->get('brain', []);
            $settings = BrainConfig::save($this->db, $brain);
            $ai = (array) $this->get('ai', []);
            foreach (self::AI_KEYS as $key => [$min, $max]) {
                if (!array_key_exists($key, $ai)) continue;
                $v = $ai[$key];
                if ($v === '' || $v === null) {
                    // فاضي = يرجع لإعداد .env
                    $this->db->query('DELETE FROM system_settings WHERE setting_key = ?', [$key]);
                    continue;
                }
                if (!is_numeric($v) || (float) $v < $min || (float) $v > $max) throw new InvalidArgumentException('قيمة غير صالحة: ' . $key);
                $this->db->query("INSERT INTO system_settings (setting_key, setting_value, is_secret, category) VALUES (?, ?, 0, 'ai') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)", [$key, (string) (str_contains($key, 'usd') ? round((float) $v, 2) : (int) $v)]);
            }
            AIGovernor::resetAdminLimits();
            $this->log('Brain admin settings saved', ['brain' => $settings, 'ai' => array_intersect_key($ai, self::AI_KEYS)]);
            return $this->success(['settings' => $settings, 'ai_limits' => AIGovernor::effectiveLimits()]);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Logger::error('Brain admin save failed', ['error' => $e->getMessage()]);
            return $this->error('تعذر حفظ الإعدادات.', 503);
        }
    }

    /** POST /api/admin/brain/market-refresh  — يحدّث مصادر السوق الافتراضية دلوقتي (مصر + الأسواق الأساسية) */
    public function marketRefresh(array $params = []): array
    {
        if ($e = $this->guard()) return $e;
        try {
            $svc = new MarketIntelligenceService($this->db);
            $p = MarketIntelligenceService::detect((string) $this->get('destination_text', ''), '');
            $done = [];
            foreach (array_keys(MarketIntelligenceService::TTL) as $src) {
                if ($src === 'eurostat' && !in_array($p['destination'], MarketIntelligenceService::EUROSTAT_GEO, true)) continue;
                $done[$src] = $svc->refresh($src, $p['destination'], $p['markets']);
            }
            return $this->success(['destination' => $p['destination'], 'rows' => $done]);
        } catch (Throwable $e) {
            Logger::error('Brain admin market refresh failed', ['error' => $e->getMessage()]);
            return $this->error('تعذر التحديث.', 503);
        }
    }
}

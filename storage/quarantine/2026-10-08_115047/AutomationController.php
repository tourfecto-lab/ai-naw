<?php

/**
 * Tourfecto - مركز الأتمتة (صفحة العميل + API)
 *
 * العميل بيختار لكل خدمة: أوتوماتيك / بموافقتي / يتعلم مني / يدوي،
 * ويشوف تقدّم التعلّم، ويوافق أو يعدّل أو يرفض اللي مستنيه، ويقيّم
 * اللي اتعمل أوتوماتيك.
 *
 * @version 1.0.0
 */
class AutomationController extends Controller
{
    /** @var AutomationEngine */
    private $engine;

    public function __construct()
    {
        parent::__construct();
        $this->engine = AutomationEngine::instance();
    }

    private function uid(): int
    {
        return (int) ($this->user['id'] ?? 0);
    }

    private function L(string $ar, string $en): string
    {
        return (function_exists('current_lang') && current_lang() !== 'ar') ? $en : $ar;
    }

    // =====================================================================
    // API
    // =====================================================================

    /** GET /api/automation */
    public function overview(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $uid = $this->uid();
        $counts = [];
        try {
            $counts = $this->engine->counts($uid);
        } catch (Throwable $e) {
            return $this->error($this->L('مركز الأتمتة محتاج تحديث قاعدة البيانات (migration)', 'Automation Center needs its database migration'), 503);
        }
        $connected = $this->connections($uid);

        $features = [];
        foreach (AutomationCatalog::all() as $key => $def) {
            $policy = $this->engine->getPolicy($uid, $key);
            $buckets = [];
            foreach ($def['buckets'] as $bKey => $bName) {
                $bucketMode = $policy['bucket_modes'][$bKey] ?? null;
                $item = [
                    'key' => $bKey,
                    'name' => AutomationCatalog::label($bName),
                    'mode' => $bucketMode,
                    'effective' => $this->engine->effectiveMode($uid, $key, $bKey),
                ];
                if (($bucketMode ?? $policy['mode']) === 'learning') {
                    $item['learning'] = $this->engine->learningStats($uid, $key, $bKey);
                }
                $buckets[] = $item;
            }
            $features[] = [
                'key' => $key,
                'icon' => $def['icon'],
                'name' => AutomationCatalog::label($def['name']),
                'desc' => AutomationCatalog::label($def['desc']),
                'learning_type' => $def['learning'],
                'mode' => $policy['mode'],
                'is_default' => $policy['is_default'],
                'buckets' => $buckets,
                'counts' => $counts[$key] ?? ['pending' => 0, 'auto_30d' => 0, 'approved_30d' => 0, 'failed' => 0],
                'connected' => $this->isFeatureConnected($key, $def['requires'], $connected),
                'links' => $this->linkSummary($uid, $key, $policy),
                'requires' => $key === 'website_fixes' ? 'website' : $def['requires'],
                'partial' => $key === 'website_fixes' && !$connected['website_fix'] && $connected['website_any'],
                'examples' => $def['learning'] === 'text' ? count($this->engine->styleExamples($uid, $key, null, 10)) : null,
            ];
        }

        $pendingTotal = 0;
        foreach ($counts as $c) {
            $pendingTotal += $c['pending'];
        }
        return $this->success([
            'features' => $features,
            'pending_total' => $pendingTotal,
            'websites' => $this->websitesForConnect($uid),
            'setup' => $this->serverSetup(),
        ]);
    }

    /** PUT /api/automation/{feature}  {mode, bucket_modes?} */
    public function updatePolicy(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $feature = (string) ($params['feature'] ?? '');
        $mode = (string) $this->get('mode', '');
        $bucketModes = $this->get('bucket_modes');
        if (!AutomationCatalog::exists($feature) || !in_array($mode, AutomationCatalog::MODES, true)) {
            return $this->error($this->L('اختيار غير صالح', 'Invalid choice'), 422);
        }
        if ($bucketModes !== null && !is_array($bucketModes)) {
            return $this->error($this->L('اختيار غير صالح', 'Invalid choice'), 422);
        }
        $res = $this->engine->setPolicy($this->uid(), $feature, $mode, $bucketModes);
        if (empty($res['success'])) {
            return $this->error($this->L('تعذر الحفظ', 'Could not save'), 422);
        }
        $this->log('automation_policy_updated', ['feature' => $feature, 'mode' => $mode]);
        return $this->success(['policy' => $res['policy']], $this->L('اتحفظ', 'Saved'));
    }

    /** GET /api/automation/pending?feature= */
    public function pending(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $feature = (string) $this->get('feature', '');
        $rows = $this->engine->pending($this->uid(), 100, $feature !== '' && AutomationCatalog::exists($feature) ? $feature : null);
        return $this->success(['items' => array_map([$this, 'present'], $rows)]);
    }

    /** GET /api/automation/activity */
    public function activity(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $rows = $this->engine->activity($this->uid(), 60);
        return $this->success(['items' => array_map([$this, 'present'], $rows)]);
    }

    /** POST /api/automation/decisions/{id}/approve  {text?} */
    public function approve(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $text = $this->get('text');
        $res = $this->engine->decide($this->uid(), (int) ($params['id'] ?? 0), 'approve', is_string($text) ? $text : null, true);
        if (empty($res['success'])) {
            return $this->error($this->decisionError($res['error'] ?? ''), $res['error'] === 'not_found' ? 404 : 409);
        }
        $exec = $res['execution'] ?? ['success' => true];
        $msg = !empty($exec['success'])
            ? (!empty($res['graduated']) ? $this->L('اتنفّذ. والفئة دي بقت أوتوماتيك من دلوقتي 🎉', 'Done. This category now runs automatically 🎉') : $this->L('اتنفّذ', 'Done'))
            : $this->L('اتوافق عليه بس التنفيذ فشل: ', 'Approved but execution failed: ') . ($exec['error'] ?? '');
        return $this->success($res, $msg);
    }

    /** POST /api/automation/decisions/{id}/reject */
    public function reject(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $res = $this->engine->decide($this->uid(), (int) ($params['id'] ?? 0), 'reject');
        if (empty($res['success'])) {
            return $this->error($this->decisionError($res['error'] ?? ''), ($res['error'] ?? '') === 'not_found' ? 404 : 409);
        }
        $this->afterReject((int) ($params['id'] ?? 0));
        return $this->success($res, $this->L('اترفض ومش هيتنفّذ', 'Rejected'));
    }

    /** POST /api/automation/approve-all {feature?} - موافقة على كل المستني زي ما هو */
    public function approveAll(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $feature = (string) $this->get('feature', '');
        $rows = $this->engine->pending($this->uid(), 100, $feature !== '' && AutomationCatalog::exists($feature) ? $feature : null);
        $ok = 0;
        $failed = 0;
        foreach ($rows as $r) {
            $res = $this->engine->decide($this->uid(), (int) $r['id'], 'approve', null, true);
            (!empty($res['success']) && !empty(($res['execution'] ?? ['success' => true])['success'])) ? $ok++ : $failed++;
        }
        return $this->success(['approved' => $ok, 'failed' => $failed], $this->L("اتوافق على {$ok}", "Approved {$ok}"));
    }

    /** POST /api/automation/decisions/{id}/feedback {verdict: good|bad} */
    public function feedback(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $verdict = (string) $this->get('verdict', '');
        $res = $this->engine->feedback($this->uid(), (int) ($params['id'] ?? 0), $verdict);
        if (empty($res['success'])) {
            return $this->error($this->decisionError('not_found'), 404);
        }
        $msg = !empty($res['demoted'])
            ? $this->L('شكرًا. الفئة دي رجعت لوضع "يتعلم مني"، وهتسألك تاني لحد ما تفهم أسلوبك.', 'Thanks. This category is back to "learn from me".')
            : $this->L('شكرًا على رأيك', 'Thanks for the feedback');
        return $this->success($res, $msg);
    }

    private function afterReject(int $decisionId): void
    {
        // رفض رد تقييم من هنا = تجاهله في صفحة التقييمات كمان
        try {
            $rows = $this->db->query('SELECT feature_key, subject_type, subject_id FROM automation_decisions WHERE id = ? AND user_id = ?', [$decisionId, $this->uid()]);
            if (empty($rows)) {
                return;
            }
            $r = $rows[0];
            if ($r['feature_key'] === 'review_replies') {
                $this->db->exec("UPDATE reviews SET reply_status = 'rejected' WHERE id = ? AND user_id = ?", [(int) $r['subject_id'], $this->uid()]);
            } elseif ($r['feature_key'] === 'chat_replies') {
                (new ApprovalSystem())->reject((int) $r['subject_id'], $this->uid(), 'automation_center');
            } elseif ($r['feature_key'] === 'ads_optimizations') {
                (new AdAutopilotEngine())->rejectPendingAction($this->uid(), (int) $r['subject_id']);
            } elseif ($r['feature_key'] === 'website_fixes' && $r['subject_type'] === 'wo_fix') {
                $this->db->exec("UPDATE wo_fixes SET status = 'dismissed' WHERE id = ?", [(int) $r['subject_id']]);
            }
        } catch (Throwable $e) {
        }
    }

    private function decisionError(string $code): string
    {
        return $code === 'already_decided'
            ? $this->L('الإجراء ده اتاخد فيه قرار قبل كده', 'This item was already decided')
            : $this->L('الإجراء مش موجود', 'Item not found');
    }

    private function present(array $r): array
    {
        $def = AutomationCatalog::get($r['feature_key']);
        $editable = $def && ($def['learning'] === 'text' || in_array($r['feature_key'], ['seo_content', 'review_requests'], true));
        return [
            'id' => (int) $r['id'],
            'feature' => $r['feature_key'],
            'feature_name' => $def ? AutomationCatalog::label($def['name']) : $r['feature_key'],
            'bucket' => $r['bucket'],
            'bucket_name' => $def && isset($def['buckets'][$r['bucket']]) ? AutomationCatalog::label($def['buckets'][$r['bucket']]) : $r['bucket'],
            'status' => $r['status'],
            'mode' => $r['mode_at_decision'],
            'title' => $r['title'],
            'context' => $r['context_text'],
            'draft' => $r['draft_text'],
            'final' => $r['final_text'],
            'similarity' => $r['similarity'] !== null ? (float) $r['similarity'] : null,
            'feedback' => $r['feedback'],
            'error' => $r['error_message'],
            'executed' => !empty($r['executed_at']),
            'editable' => $editable,
            'created_at' => $r['created_at'],
            'at' => $r['executed_at'] ?? $r['decided_at'] ?? $r['created_at'],
        ];
    }

    /** GET /api/automation/{feature}/connections - الحاجات المربوطة فعلًا اللي الخدمة ممكن تشتغل عليها */
    public function connectionsList(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $feature = (string) ($params['feature'] ?? '');
        if (!AutomationCatalog::exists($feature)) {
            return $this->error($this->L('خدمة غير معروفة', 'Unknown service'), 404);
        }
        $policy = $this->engine->getPolicy($this->uid(), $feature);
        $items = $this->linkableConnections($this->uid(), $feature);
        foreach ($items as &$it) {
            $it['enabled'] = $it['website_id'] === null || $policy['scope_websites'] === null || in_array($it['website_id'], $policy['scope_websites'], true);
        }
        unset($it);
        $def = AutomationCatalog::get($feature);
        return $this->success([
            'items' => $items,
            'all' => $policy['scope_websites'] === null,
            'requires' => $feature === 'website_fixes' ? 'website' : $def['requires'],
        ]);
    }

    /** PUT /api/automation/{feature}/scope {website_ids: [..] | null} */
    public function updateScope(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $feature = (string) ($params['feature'] ?? '');
        $ids = $this->get('website_ids');
        if (!AutomationCatalog::exists($feature) || ($ids !== null && !is_array($ids))) {
            return $this->error($this->L('اختيار غير صالح', 'Invalid choice'), 422);
        }
        $uid = $this->uid();
        if ($ids !== null) {
            // مواقع العميل بس
            $own = array_map(static function ($w) { return (int) $w['id']; }, $this->websitesForConnect($uid));
            $ids = array_values(array_intersect(array_map('intval', $ids), $own));
        }
        try {
            $res = $this->engine->setScope($uid, $feature, $ids);
        } catch (Throwable $e) {
            return $this->error($this->L('محتاج تشغّل ملف قاعدة البيانات 2026_10_08_000002_automation_scope.sql', 'Run migration 2026_10_08_000002_automation_scope.sql'), 503);
        }
        return $this->success(['policy' => $res['policy'] ?? null], $this->L('اتحفظ', 'Saved'));
    }

    /**
     * الحاجات المربوطة على المنصة اللي الخدمة دي تقدر تشتغل عليها.
     * كل عنصر: website_id (null = حساب مش مرتبط بموقع)، site، label، kind
     */
    private function linkableConnections(int $uid, string $feature): array
    {
        $rows = function (string $sql, array $p = []) {
            try {
                return $this->db->query($sql, $p);
            } catch (Throwable $e) {
                Logger::warning('Automation linkable query failed', ['error' => $e->getMessage()]);
                return [];
            }
        };
        $sites = [];
        foreach ($this->websitesForConnect($uid) as $w) {
            $sites[$w['id']] = $w;
        }
        $siteName = function ($id) use ($sites) {
            return isset($sites[(int) $id]) ? $sites[(int) $id]['name'] : '#' . $id;
        };
        $pcRows = function (string $platforms) use ($rows, $uid) {
            return $rows(
                "SELECT pc.id, pc.website_id, pc.platform, pc.external_location_name, pc.external_account_id
                   FROM platform_connections pc LEFT JOIN websites w ON w.id = pc.website_id
                  WHERE (pc.user_id = ? OR w.user_id = ?) AND pc.platform IN ({$platforms}) AND pc.status = 'connected'
                  ORDER BY pc.id DESC",
                [$uid, $uid]
            );
        };
        $platformName = [
            'google_business' => 'Google Business', 'ultramsg' => 'WhatsApp (UltraMsg)', 'whatsapp' => 'WhatsApp',
            'wati' => 'WhatsApp (WATI)', 'messenger' => 'Messenger', 'facebook' => 'Facebook', 'instagram' => 'Instagram',
            'wordpress' => 'WordPress', 'custom_api' => 'API', 'meta_ads' => 'Meta Ads', 'google_ads' => 'Google Ads', 'tiktok_ads' => 'TikTok Ads',
        ];
        $out = [];
        $add = function ($websiteId, string $kind, string $label) use (&$out, $siteName) {
            $out[] = [
                'website_id' => $websiteId === null ? null : (int) $websiteId,
                'site' => $websiteId === null ? '' : $siteName($websiteId),
                'kind' => $kind,
                'label' => $label,
            ];
        };

        switch ($feature) {
            case 'review_replies':
                foreach ($pcRows("'google_business'") as $r) {
                    $add($r['website_id'], 'Google Business', (string) ($r['external_location_name'] ?: 'Google Business'));
                }
                break;
            case 'chat_replies':
                foreach ($pcRows("'ultramsg','whatsapp','wati','messenger','facebook','instagram'") as $r) {
                    $add($r['website_id'], $platformName[$r['platform']] ?? $r['platform'], $platformName[$r['platform']] ?? $r['platform']);
                }
                foreach ($rows("SELECT DISTINCT website_id FROM bot_settings WHERE user_id = ? AND is_enabled = 1 AND website_id IS NOT NULL", [$uid]) as $r) {
                    $add($r['website_id'], $this->L('شات الموقع', 'Website chat'), $this->L('شات الموقع', 'Website chat'));
                }
                break;
            case 'website_fixes':
                foreach ($sites as $w) {
                    if ($w['embed']) {
                        $add($w['id'], 'Auto SEO', $this->L('كود Auto SEO على الموقع', 'Auto SEO snippet'));
                    }
                }
                foreach ($rows("SELECT DISTINCT a.website_id FROM wo_audits a INNER JOIN generated_websites g ON g.id = a.generated_website_id WHERE a.user_id = ? AND a.website_id IS NOT NULL", [$uid]) as $r) {
                    $add($r['website_id'], $this->L('منشئ المواقع', 'Website builder'), $this->L('موقع من منشئ المواقع', 'Built with website builder'));
                }
                break;
            case 'seo_content':
                foreach ($pcRows("'wordpress','custom_api'") as $r) {
                    $add($r['website_id'], $platformName[$r['platform']], $platformName[$r['platform']]);
                }
                foreach ($sites as $w) {
                    if ($w['embed']) {
                        $add($w['id'], 'Auto SEO', $this->L('كود Auto SEO (فهرسة بس)', 'Auto SEO snippet (indexing)'));
                    }
                }
                break;
            case 'review_requests':
                foreach ($sites as $w) {
                    $add($w['id'], $this->L('موقع', 'Website'), $this->L('واتساب / إيميل', 'WhatsApp / email'));
                }
                break;
            case 'ads_optimizations':
                foreach ($pcRows("'meta_ads','google_ads','tiktok_ads'") as $r) {
                    $add(null, $platformName[$r['platform']] ?? $r['platform'], (string) ($r['external_location_name'] ?: ($r['external_account_id'] ?: ($platformName[$r['platform']] ?? $r['platform']))));
                }
                break;
        }

        // من غير تكرار لنفس الموقع ونفس النوع
        $seen = [];
        return array_values(array_filter($out, function ($i) use (&$seen) {
            $k = $i['website_id'] . '|' . $i['kind'] . '|' . $i['label'];
            if (isset($seen[$k])) {
                return false;
            }
            $seen[$k] = true;
            return true;
        }));
    }

    /** ملخص للكارت: مربوط على إيه وشغّال على إيه */
    private function linkSummary(int $uid, string $feature, array $policy): array
    {
        $items = $this->linkableConnections($uid, $feature);
        $active = [];
        foreach ($items as $it) {
            if ($it['website_id'] === null || $policy['scope_websites'] === null || in_array($it['website_id'], $policy['scope_websites'], true)) {
                $active[] = trim(($it['site'] !== '' ? $it['site'] . ' · ' : '') . $it['label']);
            }
        }
        return ['available' => count($items), 'active' => array_values(array_unique($active))];
    }

    private function isFeatureConnected(string $key, ?string $requires, array $c): bool
    {
        if ($requires === null) {
            return true;
        }
        if ($key === 'website_fixes') {
            return $c['website_fix'];
        }
        if ($key === 'seo_content') {
            return $c['website_publish'];
        }
        return $c[$requires] ?? false;
    }

    /** مواقع العميل وحالة ربط كل واحد (عشان نافذة الربط) */
    private function websitesForConnect(int $uid): array
    {
        try {
            $rows = $this->db->query(
                "SELECT w.id, w.company_name, w.main_url, COALESCE(w.is_connected, 0) AS embed_connected,
                        (SELECT COUNT(*) FROM platform_connections pc WHERE pc.website_id = w.id AND pc.platform IN ('wordpress','custom_api') AND pc.status = 'connected') AS wp_connected,
                        (SELECT COUNT(*) FROM platform_connections pc WHERE pc.website_id = w.id AND pc.platform = 'google_business' AND pc.status = 'connected') AS google_connected,
                        (SELECT COUNT(*) FROM platform_connections pc WHERE pc.website_id = w.id AND pc.platform IN ('ultramsg','whatsapp','messenger','instagram','facebook','wati') AND pc.status = 'connected') AS chat_connected
                   FROM websites w WHERE w.user_id = ? ORDER BY w.id DESC LIMIT 50",
                [$uid]
            );
        } catch (Throwable $e) {
            // سيرفر ناقصه عمود (مثلاً is_connected): نعرض المواقع على الأقل
            Logger::warning('Automation websitesForConnect fallback', ['error' => $e->getMessage()]);
            try {
                $rows = $this->db->query(
                    "SELECT w.id, w.company_name, w.main_url, 0 AS embed_connected, 0 AS wp_connected, 0 AS google_connected, 0 AS chat_connected
                       FROM websites w WHERE w.user_id = ? ORDER BY w.id DESC LIMIT 50",
                    [$uid]
                );
            } catch (Throwable $e2) {
                return [];
            }
        }
        return array_map(static function ($r) {
            return [
                'id' => (int) $r['id'],
                'name' => (string) ($r['company_name'] ?: $r['main_url']),
                'url' => (string) $r['main_url'],
                'embed' => (int) $r['embed_connected'] > 0,
                'wp' => (int) $r['wp_connected'] > 0,
                'google' => (int) $r['google_connected'] > 0,
                'chat' => (int) $r['chat_connected'] > 0,
            ];
        }, $rows);
    }

    /** هل السيرفر نفسه متظبط للربط (مفاتيح OAuth)؟ عشان مانوديش العميل لصفحة خطأ */
    private function serverSetup(): array
    {
        $ok = static function (callable $f): bool {
            try {
                return (bool) $f();
            } catch (Throwable $e) {
                return false;
            }
        };
        return [
            'google' => $ok(function () { return class_exists('GoogleOAuthClient') && (new GoogleOAuthClient())->isConfigured(); }),
            'meta_ads' => $ok(function () { return class_exists('MetaOAuthClient') && (new MetaOAuthClient())->isConfigured(); }),
            'google_ads' => $ok(function () {
                return class_exists('GoogleOAuthClient') && (new GoogleOAuthClient(GoogleOAuthClient::SCOPE_ADS, rtrim((string) (defined('APP_URL') ? APP_URL : ''), '/') . '/ads/connect/google-ads/callback'))->isConfigured();
            }),
        ];
    }

    /** هل الخدمة مربوطة ولا لسه (عشان الصفحة تقول للعميل يربط الأول) */
    private function connections(int $uid): array
    {
        $count = function (string $sql, array $params) : int {
            try {
                $r = $this->db->query($sql, $params);
                return (int) (array_values($r[0] ?? [0])[0] ?? 0);
            } catch (Throwable $e) {
                Logger::warning('Automation connection check failed', ['error' => $e->getMessage()]);
                return 0;
            }
        };
        // الربط بيتحسب على مواقع العميل نفسها (مش بس الصفوف اللي باسمه)،
        // عشان ربط عمله عضو في الفريق أو قبل نقل الموقع يتحسب برضه.
        $pc = function (string $platforms) use ($count, $uid): bool {
            return $count(
                "SELECT COUNT(*) FROM platform_connections pc
                  LEFT JOIN websites w ON w.id = pc.website_id
                  WHERE (pc.user_id = ? OR w.user_id = ?) AND pc.platform IN ({$platforms}) AND pc.status = 'connected'",
                [$uid, $uid]
            ) > 0;
        };
        $embed = $count('SELECT COUNT(*) FROM websites WHERE user_id = ? AND is_connected = 1', [$uid]) > 0;
        $builder = $count('SELECT COUNT(*) FROM generated_websites WHERE user_id = ?', [$uid]) > 0;
        $publish = $pc("'wordpress','custom_api'");

        return [
            'google_business' => $pc("'google_business'"),
            'chat_channel' => $pc("'ultramsg','whatsapp','messenger','instagram','facebook','wati'")
                || $count('SELECT COUNT(*) FROM chat_messages WHERE user_id = ?', [$uid]) > 0,
            // الإصلاحات التلقائية محتاجة كود Auto SEO أو موقع من منشئ المواقع
            'website_fix' => $embed || $builder,
            // نشر المقالات بيشتغل مع WordPress/API أو كود Auto SEO أو منشئ المواقع
            'website_publish' => $publish || $embed || $builder,
            'website_any' => $publish || $embed || $builder || $pc("'google_search_console'"),
            'ads' => $pc("'meta_ads','google_ads','tiktok_ads'"),
        ];
    }

    // =====================================================================
    // الصفحة
    // =====================================================================

    /** GET /automation */
    public function index(array $params = []): array
    {
        $L = function (string $ar, string $en): string {
            return $this->L($ar, $en);
        };
        $i18n = json_encode([
            'auto' => $L('أوتوماتيك', 'Automatic'),
            'approval' => $L('بموافقتي', 'Ask me first'),
            'learning' => $L('يتعلم مني', 'Learn from me'),
            'manual' => $L('يدوي', 'Manual'),
            'auto_hint' => $L('بيشتغل لوحده من غير ما يسألك', 'Runs on its own'),
            'approval_hint' => $L('بيجهّز وبيستنى موافقتك', 'Prepares and waits for you'),
            'learning_hint' => $L('بيستنى موافقتك ويتعلم من تعديلاتك، ولما يتأكد بيكمّل لوحده', 'Asks you, learns from your edits, then takes over'),
            'manual_hint' => $L('مش بيعمل أي حاجة لوحده', 'Does nothing on its own'),
            'same' => $L('زي الخدمة', 'Same as service'),
            'not_connected' => $L('مش مربوط لسه', 'Not connected yet'),
            'partial_fix' => $L('موقعك مربوط (WordPress أو Search Console)، بس الإصلاحات التلقائية محتاجة كود Auto SEO', 'Your site is connected, but automatic fixes need the Auto SEO snippet'),
            'connect' => $L('اربط', 'Connect'),
            'pending' => $L('مستني موافقتك', 'Waiting for you'),
            'auto_30d' => $L('اتعمل لوحده (30 يوم)', 'Done automatically (30d)'),
            'approved_30d' => $L('وافقت عليه (30 يوم)', 'You approved (30d)'),
            'examples' => $L('مثال من أسلوبك', 'examples of your style'),
            'graduated' => $L('اتعلّم وبقى أوتوماتيك', 'Learned - now automatic'),
            'progress' => $L('وافقت من غير تعديل على', 'Approved without changes'),
            'of_last' => $L('من آخر', 'of last'),
            'needed' => $L('فاضل حوالي', 'About'),
            'needed2' => $L('موافقة ويبقى أوتوماتيك', 'more approvals to go automatic'),
            'no_samples' => $L('لسه مفيش قرارات. وافق أو عدّل أول ما يوصلك حاجة.', 'No decisions yet.'),
            'approve' => $L('وافق وانشر', 'Approve'),
            'save_approve' => $L('احفظ تعديلي ووافق', 'Save my edit & approve'),
            'edit' => $L('عدّل', 'Edit'),
            'reject' => $L('ارفض', 'Reject'),
            'approve_all' => $L('وافق على الكل', 'Approve all'),
            'empty_pending' => $L('مفيش حاجة مستنية موافقتك 👌', 'Nothing waiting for you 👌'),
            'empty_activity' => $L('لسه مفيش نشاط', 'No activity yet'),
            'good' => $L('مناسب', 'Good'),
            'bad' => $L('مش مناسب', 'Not right'),
            'their_text' => $L('اللي وصل', 'What came in'),
            'draft' => $L('المسودة', 'Draft'),
            'sent' => $L('اللي اتنفّذ', 'What was done'),
            'st_auto_executed' => $L('اتعمل أوتوماتيك', 'Done automatically'),
            'st_approved' => $L('وافقت عليه', 'Approved'),
            'st_approved_edited' => $L('وافقت بعد تعديل', 'Approved with edits'),
            'st_rejected' => $L('رفضته', 'Rejected'),
            'st_failed' => $L('فشل', 'Failed'),
            'st_executed' => $L('اتنفّذ', 'Done'),
            'st_cancelled' => $L('اتلغى', 'Cancelled'),
            'st_expired' => $L('انتهى', 'Expired'),
            'st_waiting_exec' => $L('اتوافق عليه - هنحاول ننفّذه تاني', 'Approved - will retry'),
            'retry_note' => $L('المنصة هتحاول تنفّذه تاني لوحدها كل ساعة لمدة 3 أيام.', 'We will retry automatically every hour for 3 days.'),
            'saved' => $L('اتحفظ', 'Saved'),
            'confirm_all' => $L('توافق على كل الحاجات المستنية زي ما هي؟', 'Approve everything as is?'),
            'bucket_title' => $L('لكل نوع لوحده', 'Per type'),
            'lk_on' => $L('شغّال على:', 'Running on:'),
            'lk_none' => $L('مربوط عندك بس مختارتش تشغّله على أي حاجة', 'Connected, but not enabled on anything'),
            'lk_manage' => $L('المربوط', 'Connections'),
            'lk_title' => $L('اختار الخدمة تشتغل على إيه', 'Choose where this runs'),
            'lk_help' => $L('دي الحاجات اللي ربطتها على المنصة. علّم على اللي عايز الخدمة تشتغل عليه.', 'These are your connected accounts and sites. Tick where this service should run.'),
            'lk_new' => $L('+ ربط جديد', '+ New connection'),
            'lk_save' => $L('احفظ', 'Save'),
            'lk_connected' => $L('مربوط', 'Connected'),
            'lk_all_sites' => $L('شغّال على كل مواقعك', 'Runs on all your sites'),
            'lk_no_sites' => $L('مفيش مواقع على حسابك لسه. ضيف موقعك الأول من صفحة المواقع.', 'No sites on your account yet. Add your site first.'),
            'lk_all_accounts' => $L('بتشتغل على كل حسابات الإعلانات المربوطة', 'Runs on all connected ad accounts'),
            'cx_title_google_business' => $L('ربط Google Business', 'Connect Google Business'),
            'cx_title_chat_channel' => $L('ربط واتساب أو ماسنجر', 'Connect WhatsApp or Messenger'),
            'cx_title_website' => $L('ربط موقعك', 'Connect your website'),
            'cx_title_ads' => $L('ربط حسابات الإعلانات', 'Connect ad accounts'),
            'cx_site' => $L('الموقع', 'Website'),
            'cx_no_sites' => $L('لازم تضيف موقعك الأول.', 'Add your website first.'),
            'cx_add_site' => $L('ضيف موقع', 'Add website'),
            'cx_connected' => $L('مربوط ✓', 'Connected ✓'),
            'cx_google_steps' => $L('هتفتح صفحة جوجل: سجّل دخول بالحساب اللي عليه نشاطك على الخريطة، اضغط «سماح»، وبعدين اختار الفرع. هترجع هنا لوحدك.', 'Google will open: sign in with the account that owns your Maps listing, allow access, pick the location. You will come back here.'),
            'cx_google_go' => $L('افتح جوجل واربط', 'Open Google and connect'),
            'cx_server_missing' => $L('الربط ده لسه مش متفعّل على السيرفر: بيانات الربط مع', 'This connection is not enabled on the server yet: credentials for'),
            'cx_server_missing2' => $L('ناقصة. لازم الأدمن يحطها من لوحة الأدمن ← التكاملات، وبعدها الزرار هيشتغل.', 'are missing. An admin must add them in Admin → Integrations.'),
            'cx_wa' => $L('واتساب (UltraMsg)', 'WhatsApp (UltraMsg)'),
            'cx_fb' => $L('ماسنجر فيسبوك', 'Facebook Messenger'),
            'cx_ig' => $L('إنستجرام', 'Instagram'),
            'cx_wa_help' => $L('من حسابك على ultramsg.com: افتح الـ Instance وانسخ Instance ID والـ Token.', 'From ultramsg.com open your instance and copy the Instance ID and Token.'),
            'cx_meta_help' => $L('من Meta Business: رقم الصفحة (Page ID) و Page Access Token.', 'From Meta Business: Page ID and Page Access Token.'),
            'cx_instance' => 'Instance ID',
            'cx_token' => 'Token',
            'cx_page' => 'Page ID',
            'cx_access' => 'Page Access Token',
            'cx_save' => $L('اربط', 'Connect'),
            'cx_webhook' => $L('آخر خطوة: انسخ الرابط ده وحطه في إعدادات Webhook في UltraMsg عشان الرسايل توصل للمنصة:', 'Last step: paste this URL in UltraMsg webhook settings:'),
            'cx_copy' => $L('انسخ', 'Copy'),
            'cx_copied' => $L('اتنسخ', 'Copied'),
            'cx_web_help' => $L('هنطلعلك كود صغير تحطه في موقعك قبل </head> (أو في إضافة Header في WordPress). بعدها الإصلاحات بتتطبق لوحدها.', 'We generate a small snippet to paste before </head> (or a WordPress header plugin). Fixes then apply automatically.'),
            'cx_web_go' => $L('طلّع كود الربط', 'Generate connection code'),
            'cx_web_code' => $L('انسخ الكود ده وحطه في موقعك قبل </head>:', 'Copy this and paste it before </head>:'),
            'cx_web_builder' => $L('أو اعمل موقع جاهز بمنشئ المواقع (بيبقى مربوط لوحده)', 'Or build a site with the website builder (connected automatically)'),
            'cx_meta_ads' => $L('اربط Meta (فيسبوك وإنستجرام)', 'Connect Meta (Facebook & Instagram)'),
            'cx_google_ads' => $L('اربط Google Ads', 'Connect Google Ads'),
            'cx_ads_help' => $L('هتفتح صفحة الشركة، توافق، وتختار حساب الإعلانات.', 'You will approve access and pick the ad account.'),
            'cx_done' => $L('اتربط ✓', 'Connected ✓'),
            'cx_error' => $L('ماتربطش:', 'Not connected:'),
            'learn_sub' => $L('المنصة بتتعلم من كل رد بتكتبه أو تعدّله، حتى في الوضع اليدوي.', 'Learns from every reply you write or edit.'),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        $connectLinks = json_encode([
            'google_business' => '/reputation/platforms',
            'chat_channel' => '/chat/settings',
            'website' => '/auto-seo',
            'ads' => '/ads/connections',
        ]);

        $tPendingTab = $L('مستني موافقتك', 'Waiting for you');
        $tActivityTab = $L('اللي اتعمل', 'What was done');
        $tServices = $L('الخدمات', 'Services');
        $tIntro = $L('بعد ما تربط حساباتك، المنصة بتشتغل لوحدها. اختار لكل خدمة: تشتغل أوتوماتيك، أو تستنى موافقتك، أو تتعلم من شغلك وبعدين تكمّل لوحدها.', 'After you connect your accounts the platform works on its own. For each service choose: automatic, ask first, or learn from you then take over.');
        $tLoading = $L('بنحمّل...', 'Loading...');

        $body = <<<HTML
<style>
.am-intro{margin:0 0 16px;color:var(--panel-text-muted);font-size:14px;line-height:1.7;max-width:820px}
.am-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px}
.am-card{display:flex;flex-direction:column;gap:12px}
.am-head{display:flex;gap:12px;align-items:flex-start}
.am-ico{flex:0 0 40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:var(--panel-accent-light);color:var(--panel-accent)}
.am-title{font-weight:700;font-size:15px;margin:0}
.am-desc{font-size:12.5px;color:var(--panel-text-muted);margin:4px 0 0;line-height:1.6}
.am-seg{display:grid;grid-template-columns:repeat(4,1fr);gap:4px;background:var(--panel-card-bg-2,rgba(127,127,127,.08));padding:4px;border-radius:12px}
.am-seg button{border:0;background:none;color:var(--panel-text-muted);font:inherit;font-size:12.5px;font-weight:600;padding:8px 4px;border-radius:9px;cursor:pointer}
.am-seg button.on{background:var(--panel-card-bg);color:var(--panel-text);box-shadow:0 1px 4px rgba(0,0,0,.18)}
.am-seg button.on[data-mode=auto]{color:var(--panel-success)}
.am-seg button.on[data-mode=learning]{color:var(--panel-info)}
.am-seg button.on[data-mode=approval]{color:var(--panel-warning)}
.am-hint{font-size:12px;color:var(--panel-text-muted);min-height:16px}
.am-stats{display:flex;gap:14px;flex-wrap:wrap;font-size:12px;color:var(--panel-text-muted)}
.am-stats b{color:var(--panel-text);font-size:14px}
.am-stats .warn b{color:var(--panel-warning)}
.am-buckets{border-top:1px solid var(--panel-border);padding-top:10px;display:flex;flex-direction:column;gap:8px}
.am-bucket{display:grid;grid-template-columns:1fr auto;gap:6px 10px;align-items:center;font-size:13px}
.am-bucket select{font-size:12px;padding:5px 8px;border-radius:8px;background:var(--panel-card-bg);color:var(--panel-text);border:1px solid var(--panel-border)}
.am-learn{grid-column:1/-1;font-size:11.5px;color:var(--panel-text-muted)}
.am-bar{height:6px;border-radius:99px;background:rgba(127,127,127,.18);overflow:hidden;margin-top:4px}
.am-bar span{display:block;height:100%;background:var(--panel-info)}
.am-bar.done span{background:var(--panel-success)}
.am-off{font-size:12px;color:var(--panel-warning);display:flex;gap:8px;align-items:center}
.am-tabs{display:flex;gap:8px;margin:26px 0 12px;align-items:center;flex-wrap:wrap}
.am-tab{border:1px solid var(--panel-border);background:var(--panel-card-bg);color:var(--panel-text-muted);font:inherit;font-size:13px;font-weight:600;padding:8px 14px;border-radius:999px;cursor:pointer}
.am-tab.on{border-color:var(--panel-accent);color:var(--panel-text)}
.am-count{display:inline-block;min-width:20px;padding:1px 6px;border-radius:99px;background:var(--panel-danger);color:#fff;font-size:11px;margin-inline-start:6px}
.am-item{display:flex;flex-direction:column;gap:8px;padding:14px 16px;border-bottom:1px solid var(--panel-border)}
.am-item:last-child{border-bottom:0}
.am-item-top{display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:12px;color:var(--panel-text-muted)}
.am-item-title{font-weight:600;color:var(--panel-text);font-size:13.5px}
.am-quote{font-size:13px;line-height:1.7;background:rgba(127,127,127,.07);border-radius:10px;padding:8px 12px;white-space:pre-wrap}
.am-label{font-size:11px;color:var(--panel-text-muted);margin-bottom:-4px}
.am-item textarea{width:100%;min-height:90px;font:inherit;font-size:13px;line-height:1.7;padding:10px 12px;border-radius:10px;border:1px solid var(--panel-accent);background:var(--panel-card-bg);color:var(--panel-text);box-sizing:border-box}
.am-actions{display:flex;gap:8px;flex-wrap:wrap}
.am-cx{display:flex;flex-direction:column;gap:10px}
.am-cx .am-cx-input{width:100%;box-sizing:border-box}
.am-cx-tabs{display:flex;gap:6px;flex-wrap:wrap}
.am-cx-code{width:100%;min-height:90px;box-sizing:border-box;font:12px/1.5 monospace;padding:10px;border-radius:10px;border:1px solid var(--panel-border);background:var(--panel-card-bg);color:var(--panel-text)}
.am-ok{color:var(--panel-success);font-weight:700;margin:4px 0}
.am-linked{display:flex;flex-wrap:wrap;gap:6px;align-items:center;font-size:12px}
.am-linked-lbl{color:var(--panel-success);font-weight:700}
.am-chip{background:var(--panel-success-light);color:var(--panel-text);border-radius:999px;padding:3px 10px;font-size:12px}
.am-lk-list{display:flex;flex-direction:column;gap:8px}
.am-lk-row{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid var(--panel-border);border-radius:10px;cursor:pointer;font-size:13px}
.am-lk-row input{margin-top:3px}
.am-lk-row small{display:block;color:var(--panel-text-muted);font-size:11.5px;margin-top:2px}
@media (max-width:520px){.am-grid{grid-template-columns:1fr}.am-seg button{font-size:11.5px;padding:8px 2px}}
</style>
<p class="am-intro">{$tIntro}</p>
<div class="am-grid" id="amGrid"><div class="p-card"><div class="p-cell-muted">{$tLoading}</div></div></div>
<div class="am-tabs">
  <button class="am-tab on" data-tab="pending" id="amTabPending">{$tPendingTab}<span class="am-count" id="amPendingCount" style="display:none">0</span></button>
  <button class="am-tab" data-tab="activity" id="amTabActivity">{$tActivityTab}</button>
  <span style="flex:1"></span>
  <button class="p-btn xs outline" id="amApproveAll" style="display:none"></button>
</div>
<div class="p-modal-overlay" id="amConnectModal">
  <div class="p-modal">
    <div class="p-modal-head"><h3 id="amCxTitle"></h3><button class="p-modal-close" type="button" onclick="P.closeModal('amConnectModal')">×</button></div>
    <div class="p-modal-body am-cx" id="amCxBody"></div>
  </div>
</div>
<div class="p-card" style="padding:0" id="amList"><div class="p-cell-muted" style="padding:16px">{$tLoading}</div></div>
HTML;

        $script = <<<JS
const T = {$i18n};
const CONNECT = {$connectLinks};
let CX = { websites: [], setup: {} };
function siteSelect(id, flag) {
  if (!CX.websites.length) return '<p class="am-hint">' + T.cx_no_sites + ' <a class="p-btn xs outline" href="/websites">' + T.cx_add_site + '</a></p>';
  return '<label class="am-label" for="' + id + '">' + T.cx_site + '</label><select class="p-select am-cx-input" id="' + id + '">' + CX.websites.map(w => '<option value="' + w.id + '">' + esc(w.name) + (flag && w[flag] ? ' — ' + T.cx_connected : '') + '</option>').join('') + '</select>';
}
function serverMissing(what) { return '<div class="am-off" style="display:block;line-height:1.7">⚠️ ' + T.cx_server_missing + ' ' + what + ' ' + T.cx_server_missing2 + '</div>'; }
function field(id, label, type) { return '<label class="am-label" for="' + id + '">' + label + '</label><input class="p-input am-cx-input" id="' + id + '" type="' + (type || 'text') + '" autocomplete="off">'; }
function copyBox(text) { return '<textarea class="am-cx-code" readonly dir="ltr">' + esc(text) + '</textarea><button type="button" class="p-btn xs outline" data-copy>' + T.cx_copy + '</button>'; }
async function post(url, body) { return P.fetchJSON(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }); }
async function openLinks(feature) {
  const res = await P.fetchJSON('/api/automation/' + feature + '/connections');
  if (!res.success) { P.toast(res.error || 'Error', 'error'); return; }
  const d = res.data;
  if (!d.items.length) {
    if (!d.requires) { P.toast(T.lk_no_sites, 'info'); return; }
    openConnect(d.requires); return;
  }
  const f = state.features.find(x => x.key === feature);
  document.getElementById('amCxTitle').textContent = (f ? f.name + ' · ' : '') + T.lk_title;
  const box = document.getElementById('amCxBody');
  let h = '<p class="am-hint">' + T.lk_help + '</p><div class="am-lk-list">';
  d.items.forEach((it, i) => {
    const site = it.website_id === null ? '' : '<b>' + esc(it.site) + '</b> · ';
    h += '<label class="am-lk-row"><input type="checkbox" data-wid="' + (it.website_id === null ? '' : it.website_id) + '"' + (it.enabled ? ' checked' : '') + (it.website_id === null ? ' disabled' : '') + '>'
      + '<span>' + site + esc(it.label) + '<small>' + esc(it.kind) + '</small></span></label>';
  });
  h += '</div>';
  if (d.items.every(it => it.website_id === null)) h += '<p class="am-hint">' + T.lk_all_accounts + '</p>';
  h += '<div class="am-actions"><button type="button" class="p-btn primary" id="lkSave">' + T.lk_save + '</button>'
    + (d.requires ? '<button type="button" class="p-btn outline" id="lkNew">' + T.lk_new + '</button>' : '') + '</div>';
  box.innerHTML = h;
  // نفس الموقع ممكن يظهر أكتر من مرة (مثلاً واتساب + شات الموقع): التعليم بيتزامن
  box.querySelectorAll('input[data-wid]').forEach(cb => cb.addEventListener('change', () => {
    box.querySelectorAll('input[data-wid="' + cb.dataset.wid + '"]').forEach(o => { o.checked = cb.checked; });
  }));
  document.getElementById('lkSave').addEventListener('click', async () => {
    const boxes = [...box.querySelectorAll('input[data-wid]')].filter(cb => cb.dataset.wid !== '');
    const ids = [...new Set(boxes.filter(cb => cb.checked).map(cb => Number(cb.dataset.wid)))];
    const allIds = [...new Set(boxes.map(cb => Number(cb.dataset.wid)))];
    const body = { website_ids: ids.length === allIds.length ? null : ids };
    const r = await P.fetchJSON('/api/automation/' + feature + '/scope', { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    P.toast(r.success ? T.saved : (r.error || 'Error'), r.success ? 'success' : 'error');
    if (r.success) { P.closeModal('amConnectModal'); loadOverview(); }
  });
  const nb = document.getElementById('lkNew');
  if (nb) nb.addEventListener('click', () => openConnect(d.requires));
  P.openModal('amConnectModal');
}
function openConnect(req) {
  const box = document.getElementById('amCxBody');
  document.getElementById('amCxTitle').textContent = T['cx_title_' + req] || T.connect;
  let h = '';
  if (req === 'google_business') {
    if (!CX.setup.google) h = serverMissing('Google (GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET)');
    else h = siteSelect('cxSite', 'google') + '<p class="am-hint">' + T.cx_google_steps + '</p><button type="button" class="p-btn primary" id="cxGo"' + (CX.websites.length ? '' : ' disabled') + '>' + T.cx_google_go + '</button>';
  } else if (req === 'chat_channel') {
    h = siteSelect('cxSite', 'chat')
      + '<div class="am-cx-tabs"><button type="button" class="am-tab on" data-ch="wa">' + T.cx_wa + '</button><button type="button" class="am-tab" data-ch="messenger">' + T.cx_fb + '</button><button type="button" class="am-tab" data-ch="instagram">' + T.cx_ig + '</button></div>'
      + '<div data-pane="wa"><p class="am-hint">' + T.cx_wa_help + '</p>' + field('cxInstance', T.cx_instance) + field('cxToken', T.cx_token, 'password') + '</div>'
      + '<div data-pane="meta" style="display:none"><p class="am-hint">' + T.cx_meta_help + '</p>' + field('cxPage', T.cx_page) + field('cxAccess', T.cx_access, 'password') + '</div>'
      + '<button type="button" class="p-btn primary" id="cxGo"' + (CX.websites.length ? '' : ' disabled') + '>' + T.cx_save + '</button><div id="cxResult"></div>';
  } else if (req === 'website') {
    h = siteSelect('cxSite', 'embed') + '<p class="am-hint">' + esc(T.cx_web_help) + '</p><button type="button" class="p-btn primary" id="cxGo"' + (CX.websites.length ? '' : ' disabled') + '>' + T.cx_web_go + '</button><div id="cxResult"></div><p class="am-hint"><a href="/website-builder">' + T.cx_web_builder + '</a></p>';
  } else if (req === 'ads') {
    h = '<p class="am-hint">' + T.cx_ads_help + '</p><div class="am-actions">'
      + (CX.setup.meta_ads ? '<a class="p-btn primary" href="/ads/connect/meta">' + T.cx_meta_ads + '</a>' : '')
      + (CX.setup.google_ads ? '<a class="p-btn outline" href="/ads/connect/google-ads">' + T.cx_google_ads + '</a>' : '') + '</div>'
      + (!CX.setup.meta_ads ? serverMissing('Meta (META_APP_ID / META_APP_SECRET)') : '')
      + (!CX.setup.google_ads ? serverMissing('Google Ads') : '');
  }
  box.innerHTML = h;
  let channel = 'wa';
  box.querySelectorAll('[data-ch]').forEach(t => t.addEventListener('click', () => {
    channel = t.dataset.ch;
    box.querySelectorAll('[data-ch]').forEach(x => x.classList.toggle('on', x === t));
    box.querySelector('[data-pane=wa]').style.display = channel === 'wa' ? '' : 'none';
    box.querySelector('[data-pane=meta]').style.display = channel === 'wa' ? 'none' : '';
  }));
  const go = document.getElementById('cxGo');
  if (go) go.addEventListener('click', async () => {
    const site = (document.getElementById('cxSite') || {}).value;
    const out = document.getElementById('cxResult');
    if (req === 'google_business') { location.href = '/reputation/connect/google/' + encodeURIComponent(site) + '?return=/automation'; return; }
    go.disabled = true;
    let res;
    if (req === 'chat_channel') {
      res = channel === 'wa'
        ? await post('/api/chat/connect/ultramsg', { website_id: site, instance_id: document.getElementById('cxInstance').value.trim(), api_key: document.getElementById('cxToken').value.trim() })
        : await post('/api/chat/connect/' + channel, { website_id: site, page_id: document.getElementById('cxPage').value.trim(), access_token: document.getElementById('cxAccess').value.trim() });
      if (res.success) {
        out.innerHTML = '<p class="am-ok">' + T.cx_done + '</p>' + (res.data && res.data.webhook_url ? '<p class="am-hint">' + T.cx_webhook + '</p>' + copyBox(res.data.webhook_url) : '');
      }
    } else if (req === 'website') {
      res = await post('/api/auto-seo/connect', { website_id: site, method: 'script' });
      if (res.success) {
        const code = (res.data && (res.data.embed_code || (res.data.connection && res.data.connection.embed_code))) || '';
        out.innerHTML = '<p class="am-ok">' + T.cx_done + '</p><p class="am-hint">' + esc(T.cx_web_code) + '</p>' + copyBox(code);
      }
    }
    go.disabled = false;
    if (res && !res.success) out.innerHTML = '<div class="am-off" style="display:block">⚠️ ' + T.cx_error + ' ' + esc(res.error || '') + '</div>';
    out.querySelectorAll('[data-copy]').forEach(b => b.addEventListener('click', () => {
      const ta = b.previousElementSibling; ta.select();
      (navigator.clipboard ? navigator.clipboard.writeText(ta.value) : Promise.reject()).catch(() => document.execCommand('copy'));
      b.textContent = T.cx_copied;
    }));
    if (res && res.success) loadOverview();
  });
  P.openModal('amConnectModal');
}
const MODES = ['auto','learning','approval','manual'];
let state = { features: [], tab: 'pending' };
const esc = P.esc;

function modeBtns(cur) {
  return '<div class="am-seg" role="radiogroup">' + MODES.map(m =>
    '<button type="button" role="radio" aria-checked="' + (m===cur) + '" data-mode="' + m + '" class="' + (m===cur?'on':'') + '">' + T[m] + '</button>').join('') + '</div>';
}
function learnLine(l) {
  if (!l) return '';
  if (l.graduated) return '<div class="am-learn">✅ ' + T.graduated + '<div class="am-bar done"><span style="width:100%"></span></div></div>';
  if (!l.samples) return '<div class="am-learn">' + T.no_samples + '</div>';
  const pct = Math.round(l.rate * 100);
  const goal = Math.round(l.threshold * 100);
  const fill = Math.min(100, Math.round(Math.min(l.samples / l.min_samples, 1) * Math.min(l.rate / l.threshold, 1) * 100));
  return '<div class="am-learn">' + T.progress + ' ' + l.good + ' ' + T.of_last + ' ' + l.samples + ' (' + pct + '% / ' + goal + '%)'
    + (l.needed ? ' · ' + T.needed + ' ' + l.needed + ' ' + T.needed2 : '')
    + '<div class="am-bar"><span style="width:' + fill + '%"></span></div></div>';
}
function card(f) {
  const c = f.counts || {};
  let h = '<div class="p-card am-card" data-feature="' + f.key + '">'
    + '<div class="am-head"><div class="am-ico">' + (window.TF_ICONS && TF_ICONS[f.icon] || '⚡') + '</div><div><h3 class="am-title">' + esc(f.name) + '</h3><p class="am-desc">' + esc(f.desc) + '</p></div></div>'
    + modeBtns(f.mode)
    + '<div class="am-hint">' + esc(T[f.mode + '_hint']) + '</div>';
  const lk = f.links || { available: 0, active: [] };
  if (lk.available > 0) {
    h += '<div class="am-linked">' + (lk.active.length
        ? '<span class="am-linked-lbl">✓ ' + T.lk_on + '</span> ' + lk.active.slice(0, 3).map(n => '<span class="am-chip">' + esc(n) + '</span>').join('') + (lk.active.length > 3 ? '<span class="am-chip">+' + (lk.active.length - 3) + '</span>' : '')
        : '<span class="am-off">⚠️ ' + T.lk_none + '</span>')
      + ' <button type="button" class="p-btn xs outline" data-links="' + f.key + '">' + T.lk_manage + ' (' + lk.available + ')</button></div>';
  } else if (f.connected && f.requires) {
    h += '<div class="am-linked"><span class="am-linked-lbl">✓ ' + T.lk_connected + '</span> <button type="button" class="p-btn xs outline" data-links="' + f.key + '">' + T.connect + '</button></div>';
  } else if (f.requires) {
    h += '<div class="am-off">⚠️ ' + (f.partial ? T.partial_fix : T.not_connected) + ' <button type="button" class="p-btn xs primary" data-links="' + f.key + '">' + T.connect + '</button></div>';
  } else {
    h += '<div class="am-linked"><span class="am-linked-lbl">✓ ' + T.lk_all_sites + '</span> <button type="button" class="p-btn xs outline" data-links="' + f.key + '">' + T.lk_manage + '</button></div>';
  }
  h += '<div class="am-stats">'
    + (c.pending ? '<span class="warn"><b>' + c.pending + '</b> ' + T.pending + '</span>' : '')
    + '<span><b>' + (c.auto_30d||0) + '</b> ' + T.auto_30d + '</span>'
    + '<span><b>' + (c.approved_30d||0) + '</b> ' + T.approved_30d + '</span>'
    + (f.examples ? '<span><b>' + f.examples + '</b> ' + T.examples + '</span>' : '')
    + '</div>';
  if (f.buckets.length > 1 || f.buckets.some(b => b.learning)) {
    h += '<div class="am-buckets">';
    f.buckets.forEach(b => {
      h += '<div class="am-bucket" data-bucket="' + b.key + '"><span>' + esc(b.name) + '</span>';
      if (f.buckets.length > 1) {
        h += '<select aria-label="' + esc(b.name) + '"><option value="">' + T.same + '</option>' + MODES.map(m => '<option value="' + m + '"' + (b.mode===m?' selected':'') + '>' + T[m] + '</option>').join('') + '</select>';
      } else { h += '<span></span>'; }
      h += learnLine(b.learning) + '</div>';
    });
    h += '</div>';
  }
  if (f.learning_type === 'text') h += '<div class="am-hint">' + T.learn_sub + '</div>';
  return h + '</div>';
}
async function save(feature, mode, bucketModes) {
  const body = { mode: mode };
  if (bucketModes) body.bucket_modes = bucketModes;
  const res = await P.fetchJSON('/api/automation/' + feature, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  P.toast(res.success ? T.saved : (res.error || 'Error'), res.success ? 'success' : 'error');
  loadOverview();
}
function bucketMap(cardEl) {
  const map = {};
  cardEl.querySelectorAll('.am-bucket select').forEach(s => { const b = s.closest('.am-bucket').dataset.bucket; if (s.value) map[b] = s.value; });
  return map;
}
async function loadOverview() {
  const res = await P.fetchJSON('/api/automation');
  const grid = document.getElementById('amGrid');
  if (!res.success) { grid.innerHTML = '<div class="p-card"><div class="p-empty">' + esc(res.error || 'Error') + '</div></div>'; return; }
  state.features = res.data.features;
  CX.websites = res.data.websites || [];
  CX.setup = res.data.setup || {};
  grid.innerHTML = state.features.map(card).join('');
  const n = res.data.pending_total || 0;
  const cnt = document.getElementById('amPendingCount');
  cnt.textContent = n; cnt.style.display = n ? '' : 'none';
  grid.querySelectorAll('[data-links]').forEach(b => b.addEventListener('click', () => openLinks(b.dataset.links)));
  grid.querySelectorAll('.am-card').forEach(el => {
    const f = el.dataset.feature;
    el.querySelectorAll('.am-seg button').forEach(b => b.addEventListener('click', () => save(f, b.dataset.mode, bucketMap(el))));
    el.querySelectorAll('.am-bucket select').forEach(s => s.addEventListener('change', () => {
      const cur = state.features.find(x => x.key === f).mode; save(f, cur, bucketMap(el));
    }));
  });
}
function statusBadge(it) {
  if ((it.status === 'approved' || it.status === 'approved_edited') && !it.executed) return '<span class="p-badge gold">' + esc(T.st_waiting_exec) + '</span>';
  const cls = { auto_executed:'green', approved:'green', approved_edited:'info', rejected:'danger', failed:'danger' }[it.status] || 'gold';
  return '<span class="p-badge ' + cls + '">' + esc(T['st_' + it.status] || it.status) + '</span>';
}
function itemHtml(it) {
  const top = '<div class="am-item-top"><span class="p-badge info">' + esc(it.feature_name) + '</span><span>' + esc(it.bucket_name) + '</span><span>·</span><span>' + esc(P.timeAgo ? P.timeAgo(it.at) : it.at) + '</span>' + (state.tab==='activity' ? statusBadge(it) : '') + '</div>';
  let h = '<div class="am-item" data-id="' + it.id + '">' + top;
  if (it.title) h += '<div class="am-item-title">' + esc(it.title) + '</div>';
  if (it.context) h += '<div class="am-label">' + T.their_text + '</div><div class="am-quote" dir="auto">' + esc(it.context) + '</div>';
  if (state.tab === 'pending') {
    if (it.draft) h += '<div class="am-label">' + T.draft + '</div>' + (it.editable ? '<textarea dir="auto">' + esc(it.draft) + '</textarea>' : '<div class="am-quote" dir="auto">' + esc(it.draft) + '</div>');
    h += '<div class="am-actions"><button class="p-btn primary xs" data-act="approve">' + T.approve + '</button><button class="p-btn danger xs" data-act="reject">' + T.reject + '</button></div>';
  } else {
    const done = it.final || it.draft;
    if (done) h += '<div class="am-label">' + (it.status === 'rejected' ? T.draft : T.sent) + '</div><div class="am-quote" dir="auto">' + esc(done) + '</div>';
    if (it.error) h += '<div class="am-off">⚠️ ' + esc(it.error) + (it.executed ? '' : ' · ' + T.retry_note) + '</div>';
    if (it.status === 'auto_executed') {
      h += '<div class="am-actions">'
        + '<button class="p-btn xs ' + (it.feedback==='good'?'success':'outline') + '" data-act="good">👍 ' + T.good + '</button>'
        + '<button class="p-btn xs ' + (it.feedback==='bad'?'danger':'outline') + '" data-act="bad">👎 ' + T.bad + '</button></div>';
    }
  }
  return h + '</div>';
}
async function loadList() {
  const list = document.getElementById('amList');
  const res = await P.fetchJSON(state.tab === 'pending' ? '/api/automation/pending' : '/api/automation/activity');
  const items = (res.success && res.data.items) || [];
  const all = document.getElementById('amApproveAll');
  all.style.display = state.tab === 'pending' && items.length > 1 ? '' : 'none';
  all.textContent = T.approve_all + ' (' + items.length + ')';
  if (!items.length) { list.innerHTML = '<div class="p-empty" style="padding:28px">' + (state.tab==='pending' ? T.empty_pending : T.empty_activity) + '</div>'; return; }
  list.innerHTML = items.map(itemHtml).join('');
  list.querySelectorAll('.am-item').forEach(el => {
    const id = el.dataset.id;
    const ta = el.querySelector('textarea');
    const orig = ta ? ta.value : null;
    if (ta) ta.addEventListener('input', () => { const b = el.querySelector('[data-act=approve]'); b.textContent = ta.value !== orig ? T.save_approve : T.approve; });
    el.querySelectorAll('[data-act]').forEach(btn => btn.addEventListener('click', async () => {
      const act = btn.dataset.act;
      btn.disabled = true;
      let res;
      if (act === 'approve') {
        const body = ta && ta.value !== orig ? { text: ta.value } : {};
        res = await P.fetchJSON('/api/automation/decisions/' + id + '/approve', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
      } else if (act === 'reject') {
        res = await P.fetchJSON('/api/automation/decisions/' + id + '/reject', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
      } else {
        res = await P.fetchJSON('/api/automation/decisions/' + id + '/feedback', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ verdict: act }) });
      }
      btn.disabled = false;
      const execFailed = res.success && res.data && res.data.execution && !res.data.execution.success && !res.data.execution.deferred;
      P.toast(res.message || res.error || '', res.success && !execFailed ? 'success' : 'error');
      loadOverview(); loadList();
    }));
  });
}
document.querySelectorAll('.am-tab').forEach(t => t.addEventListener('click', () => {
  document.querySelectorAll('.am-tab').forEach(x => x.classList.toggle('on', x === t));
  state.tab = t.dataset.tab; loadList();
}));
document.getElementById('amApproveAll').addEventListener('click', async () => {
  if (!confirm(T.confirm_all)) return;
  const res = await P.fetchJSON('/api/automation/approve-all', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
  P.toast(res.message || res.error || '', res.success ? 'success' : 'error');
  loadOverview(); loadList();
});
loadOverview(); loadList();
JS;

        $icons = [];
        foreach (AutomationCatalog::all() as $def) {
            $icons[$def['icon']] = icon_svg($def['icon'], 20);
        }
        $script = 'window.TF_ICONS = ' . json_encode($icons, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ";\n(function(){\n" . $script . "\n})();";

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('automation', $L('مركز الأتمتة', 'Automation Center'), $L('كل اللي المنصة بتعمله لوحدها بعد الربط', 'Everything the platform does on its own'), $body, $script);
        exit;
    }
}

<?php
require_once dirname(__DIR__) . '/Core/IntegrationManager.php';
/**
 * Tourfecto - Integrations Controller
 * إدارة التكاملات الخارجية (Slack, Zapier, HubSpot, Algolia, Calendly,
 * Zoom, Mixpanel, OneSignal...) من لوحة التحكم.
 *
 * الوصول: admin / super_admin فقط (المفاتيح أسرار حساسة). يوفر:
 *   1) GET  /api/integrations           - قائمة كل التكاملات + حالتها
 *   2) GET  /api/integrations/{key}     - حالة تكامل محدد (مفاتيح مقنّعة)
 *   3) POST /api/integrations/{key}/save  - حفظ مفاتيح تكامل معيّن
 *   4) POST /api/integrations/{key}/test  - اختبار اتصال فعلي بالخدمة
 *
 * الأمان: التحقق من الدور أولاً (401 للمستخدمين العاديين)، ولا تُرجَع
 * أي قيمة حساسة كاملة للواجهة أبدًا - المفاتيح بتتقنّع (mask) زي ما
 * SystemSettingsService بتعمل مع باقي إعدادات الأدمن.
 *
 * @version 1.0.0
 * @date 2026-08-24
 */
class IntegrationsController extends Controller
{
    /** التحقق من صلاحية الأدمن (admin/super_admin) */
    private function requireAdmin(): bool
    {
        if (!$this->isAuthenticated()) {
            return false;
        }
        if (!in_array($this->user['role'] ?? 'user', ['admin', 'super_admin'], true)) {
            return false;
        }
        return true;
    }

    /**
     * GET /api/integrations
     * كل التكاملات المسجّلة مع حالة كل واحد (configured / missing keys).
     * ممكن التصفية بـ ?category=ai|google|meta|payments|notifications|...
     */
    public function index(array $params = []): array
    {
        if (!$this->requireAdmin()) {
            return $this->error('غير مصرّح لك بالوصول', 403);
        }

        try {
            $registry = IntegrationManager::all();
            $category = $this->get('category', '');

            $integrations = [];
            foreach ($registry as $key => $meta) {
                if ($category !== '' && ($meta['category'] ?? '') !== $category) {
                    continue;
                }

                $configured = false;
                try {
                    $configured = IntegrationManager::isConfigured($key);
                } catch (Throwable $e) {
                    // تكامل فيه خطأ إعداد - نعرضه كـ not configured مع الملاحظة
                }

                $integrations[] = [
                    'key' => $key,
                    'label' => $meta['label'] ?? $key,
                    'category' => $meta['category'] ?? 'general',
                    'auth_type' => $meta['auth_type'] ?? 'api_key',
                    'configured' => $configured,
                    'env_keys' => $this->requiredKeys($meta),
                ];
            }

            return $this->success([
                'integrations' => $integrations,
                'categories' => $this->categories($registry),
                'configured_count' => count(array_filter($integrations, fn ($i) => $i['configured'])),
                'total_count' => count($integrations),
            ]);
        } catch (Exception $e) {
            Logger::error('IntegrationsController::index failed', ['message' => $e->getMessage()]);
            return $this->error('تعذر تحميل التكاملات', 500);
        }
    }

    /**
     * صفحة ويب "الربط والتكاملات" (GET /integrations) - متاحة لكل المستخدمين.
     * للعميل: حالة ربط كل منصة (Google/Meta/TikTok/YouTube/...) بخطوات الربط.
     * للأدمن: نفس المحتوى + لوحة إدارة مفاتيح التكاملات (API).
     */
    public function showIntegrationsPage(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/integrations'));
            exit;
        }

        $userId = (int) $this->user['id'];
        $isAdmin = in_array($this->user['role'] ?? 'user', ['admin', 'super_admin'], true);

        // المنصات المعروضة للعميل مع بيانات الربط - روابط الربط هي
        // صفحات الـ OAuth الحقيقية الموجودة في web.php.
        $platforms = [
            'google_ads' => [
                'label' => 'Google Ads',
                'desc' => 'إدارة حملات Google الإعلانية ومزامنتها',
                'connect' => '/ads/connect/google',
                'icon' => 'search',
            ],
            'meta_ads' => [
                'label' => 'Meta Ads (Facebook / Instagram)',
                'desc' => 'إدارة حملات فيسبوك وانستجرام الإعلانية',
                'connect' => '/ads/connect/meta',
                'icon' => 'megaphone',
            ],
            'google_search_console' => [
                'label' => 'Google Search Console',
                'desc' => 'بيانات البحث وظهور موقعك في Google',
                'connect' => '/search-console/choose',
                'icon' => 'globe',
            ],
            'google_analytics' => [
                'label' => 'Google Analytics 4',
                'desc' => 'مقاييس الزيارات والسلوك على موقعك',
                'connect' => '/google-analytics/choose',
                'icon' => 'bar-chart',
            ],
            'google_business' => [
                'label' => 'Google Business Profile',
                'desc' => 'إدارة ملفك التجاري ومراجعات Google',
                'connect' => '/reputation/connect/google/choose',
                'icon' => 'map-pin',
            ],
            'tripadvisor' => [
                'label' => 'TripAdvisor',
                'desc' => 'مراجعات وصفحة Tripadvisor',
                'connect' => null,
                'icon' => 'star',
            ],
            'tiktok' => [
                'label' => 'TikTok',
                'desc' => 'نشر الفيديوهات على TikTok',
                'connect' => '/social/connect/tiktok',
                'icon' => 'smartphone',
            ],
            'youtube' => [
                'label' => 'YouTube',
                'desc' => 'نشر الفيديوهات على YouTube Shorts',
                'connect' => '/social/connect/youtube',
                'icon' => 'monitor',
            ],
        ];

        // حالة الربط الفعلية من جدول platform_connections
        $connected = [];
        try {
            $rows = $this->db->query(
                "SELECT platform, status, external_account_id, last_synced_at, last_error, connected_at
                 FROM platform_connections WHERE user_id = ? ORDER BY platform",
                [$userId]
            );
            foreach ($rows as $row) {
                $connected[$row['platform']] = $row;
            }
        } catch (Throwable $e) {
            // الجدول مش موجود - نتعامل مع الكل كغير مربوط
        }

        $cardsHtml = '';
        foreach ($platforms as $key => $p) {
            $conn = $connected[$key] ?? null;
            $icon = icon_svg($p['icon']);
            if ($conn && $conn['status'] === 'connected') {
                $pill = '<span class="pill green">مربوط</span>';
                $account = $conn['external_account_id'] ? '<div class="p-cell-muted" style="font-size:12px;">' . htmlspecialchars($conn['external_account_id'], ENT_QUOTES, 'UTF-8') . '</div>' : '';
                $action = $p['connect'] ? '<a href="' . $p['connect'] . '" class="p-btn outline xs">إعادة الربط</a>' : '<span class="p-cell-muted" style="font-size:12px;">لا يحتاج ربط</span>';
            } elseif ($conn && $conn['status'] === 'token_expired') {
                $pill = '<span class="pill yellow">انتهت الصلاحية</span>';
                $account = '';
                $action = $p['connect'] ? '<a href="' . $p['connect'] . '" class="p-btn outline xs">إعادة الربط</a>' : '';
            } else {
                $pill = '<span class="pill gray">غير مربوط</span>';
                $account = '';
                $action = $p['connect'] ? '<a href="' . $p['connect'] . '" class="p-btn primary xs">ربط الحساب</a>' : '<span class="p-cell-muted" style="font-size:12px;">لا يحتاج ربط</span>';
            }

            $cardsHtml .= '<div class="p-card" style="margin-bottom:12px;">'
                . '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">'
                . '<div style="display:flex;align-items:center;gap:12px;min-width:0;">'
                . '<span class="ic" style="width:40px;height:40px;flex-shrink:0;">' . $icon . '</span>'
                . '<div style="min-width:0;">'
                . '<div style="font-weight:700;">' . htmlspecialchars($p['label'], ENT_QUOTES, 'UTF-8') . ' ' . $pill . '</div>'
                . '<div class="p-cell-muted" style="font-size:12px;">' . htmlspecialchars($p['desc'], ENT_QUOTES, 'UTF-8') . '</div>'
                . $account
                . '</div></div>'
                . '<div style="flex-shrink:0;">' . $action . '</div>'
                . '</div></div>';
        }

        // ملاحظات عامة للعميل
        $notes = '<div class="p-card" style="margin-top:16px;">'
            . '<div class="p-card-head"><h3>ملاحظات</h3></div>'
            . '<div class="p-cell-muted" style="font-size:13px;line-height:1.8;">'
            . 'بعض التكاملات (مثل Google Ads وMeta Ads) تحتاج تفعيل من إدارة المنصة أولاً. '
            . 'عند ربط حساب، يُخزَّن الوصول بأمان ويمكنك فصله في أي وقت من صفحات كل موديول.'
            . '</div></div>';

        $adminBlock = '';
        if ($isAdmin) {
            $adminBlock = '<div class="p-card" style="margin-top:16px;">'
                . '<div class="p-card-head"><h3>إدارة مفاتيح التكاملات (للمسؤول)</h3></div>'
                . '<div class="p-cell-muted" style="margin-bottom:10px;">حالة تكوين كل تكامل على مستوى المنصة والمفاتيح المطلوبة.</div>'
                . '<div id="adminIntegrationsBox"><div class="p-loading-row">جارِ التحميل...</div></div>'
                . '</div>';
        }

        $body = '<div class="p-card" style="margin-bottom:16px;">'
            . '<div class="p-card-head"><h3>حساباتك المربوطة</h3></div>'
            . '<div class="p-cell-muted" style="margin-bottom:14px;">اربط حسابات المنصات الخارجية للاستفادة من كل موديول.</div>'
            . $cardsHtml
            . $notes
            . $adminBlock
            . '</div>';

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc;
    const adminBox = document.getElementById('adminIntegrationsBox');
    if (!adminBox) return;

    (async function loadAdminIntegrations() {
        const res = await fetch('/api/integrations', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(r => r.json());
        if (!res.success) { adminBox.innerHTML = '<div class="p-cell-muted">' + esc(res.error || 'تعذر التحميل') + '</div>'; return; }
        if (!res.data.integrations || !res.data.integrations.length) { adminBox.innerHTML = '<div class="p-cell-muted">لا توجد تكاملات</div>'; return; }
        const rows = res.data.integrations.map(i => `
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border-color, #eee);">
                <div>
                    <strong>${esc(i.label)}</strong>
                    <div class="p-cell-muted" style="font-size:12px;">${esc(i.category)} - ${esc(i.auth_type)}</div>
                </div>
                <span class="pill ${i.configured ? 'green' : 'yellow'}">${i.configured ? 'مفعّل' : 'Setup Required'}</span>
            </div>`).join('');
        adminBox.innerHTML = rows;
    })();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('_integrations', 'الربط والتكاملات', 'حالة حساباتك المربوطة بالمنصات الخارجية', $body, $script);
        exit;
    }

    /**
     * GET /api/integrations/{key}
     * حالة تكامل محدد + المفاتيح المطلوبة (أسماء فقط، بلا قيم).
     */
    public function status(array $params = []): array
    {
        if (!$this->requireAdmin()) {
            return $this->error('غير مصرّح لك بالوصول', 403);
        }

        $key = (string) ($params['key'] ?? '');
        $meta = IntegrationManager::meta($key);
        if ($meta === null) {
            return $this->error('التكامل غير موجود', 404);
        }

        $configured = false;
        try {
            $configured = IntegrationManager::isConfigured($key);
        } catch (Throwable $e) {
        }

        return $this->success([
            'key' => $key,
            'label' => $meta['label'] ?? $key,
            'category' => $meta['category'] ?? 'general',
            'auth_type' => $meta['auth_type'] ?? 'api_key',
            'configured' => $configured,
            'env_keys' => $this->requiredKeys($meta),
        ]);
    }

    /**
     * POST /api/integrations/{key}/save
     * حفظ مفاتيح تكامل معيّن في system_settings (قيم حساسة بتتشفّر).
     * body: { "values": { "SLACK_BOT_TOKEN": "...", ... } }
     */
    public function save(array $params = []): array
    {
        if (!$this->requireAdmin()) {
            return $this->error('غير مصرّح لك بالوصول', 403);
        }

        $key = (string) ($params['key'] ?? '');
        $meta = IntegrationManager::meta($key);
        if ($meta === null) {
            return $this->error('التكامل غير موجود', 404);
        }

        $values = (array) $this->get('values', []);
        if (empty($values)) {
            return $this->error('مفيش مفاتيح للتحديث', 422);
        }

        $allowed = $this->requiredKeys($meta);
        $settings = new SystemSettingsService();
        $saved = 0;

        foreach ($values as $envKey => $value) {
            // نسمح فقط بالمفاتيح المصرّح بها لهذا التكامل
            if (!in_array($envKey, $allowed, true)) {
                continue;
            }
            $value = trim((string) $value);
            // سطر فارغ = محذوف (مش تخزين قيمة فاضية)
            if ($value === '') {
                continue;
            }

            $settingKey = $this->envToSettingKey($envKey);
            $settings->set($settingKey, $value);
            $legacyIntegrationKey = 'integration_' . strtolower($envKey);
            if ($legacyIntegrationKey !== $settingKey) {
                $settings->set($legacyIntegrationKey, $value);
            }
            $saved++;
        }

        if ($saved === 0) {
            return $this->error('مافيش مفاتيح صالحة اتحفظت', 422);
        }

        return $this->success([
            'saved' => $saved,
            'configured' => IntegrationManager::isConfigured($key),
        ], 'تم حفظ إعدادات التكامل');
    }

    /**
     * POST /api/integrations/{key}/test
     * اختبار اتصال فعلي بالتكامل (بيروح للـ API الحقيقي ويشوف إيه الرد).
     */
    public function test(array $params = []): array
    {
        if (!$this->requireAdmin()) {
            return $this->error('غير مصرّح لك بالوصول', 403);
        }

        $key = (string) ($params['key'] ?? '');
        $meta = IntegrationManager::meta($key);
        if ($meta === null) {
            return $this->error('التكامل غير موجود', 404);
        }

        if (!IntegrationManager::isConfigured($key)) {
            return $this->error('التكامل مش مكتمل الإعداد - املأ المفاتيح الأول', 422);
        }

        try {
            $integration = IntegrationManager::get($key);
            $action = $this->get('action', 'test');
            $result = $integration->request($action, [], ['test' => true]);

            if (!empty($result['success'])) {
                return $this->success([
                    'ok' => true,
                    'http_code' => $result['http_code'] ?? null,
                ], 'الاتصال بالتكامل ناجح');
            }

            return $this->error('فشل الاتصال: ' . ($result['error'] ?? 'خطأ غير معروف'), 502);
        } catch (Throwable $e) {
            Logger::error('IntegrationsController::test failed', ['integration' => $key, 'message' => $e->getMessage()]);
            return $this->error('فشل الاتصال: ' . $e->getMessage(), 502);
        }
    }

    /** تجميع الفئات المتاحة للعرض */
    private function categories(array $registry): array
    {
        $cats = [];
        foreach ($registry as $meta) {
            $cat = $meta['category'] ?? 'general';
            if (!isset($cats[$cat])) {
                $cats[$cat] = ['key' => $cat, 'count' => 0];
            }
            $cats[$cat]['count']++;
        }
        return array_values($cats);
    }

    private function requiredKeys(array $meta): array
    {
        $keys = $meta['env_keys'] ?? [];
        if (!empty($meta['enabled_env'])) {
            $keys[] = $meta['enabled_env'];
        }
        return array_values(array_unique($keys));
    }

    /** تحويل اسم متغير env لاسم مفتاح setting (lowercase underscore) */
    private function envToSettingKey(string $envKey): string
    {
        $map = [
            'GEMINI_API_KEY' => 'gemini_api_key',
            'GOOGLE_CLIENT_ID' => 'google_client_id',
            'GOOGLE_CLIENT_SECRET' => 'google_client_secret',
            'GOOGLE_MAPS_API_KEY' => 'google_maps_api_key',
            'GOOGLE_ADS_DEVELOPER_TOKEN' => 'google_ads_developer_token',
            'GOOGLE_ADS_LOGIN_CUSTOMER_ID' => 'google_ads_login_customer_id',
            'GOOGLE_ADS_OAUTH_REDIRECT_URI' => 'google_ads_oauth_redirect_uri',
            'META_APP_ID' => 'meta_app_id',
            'META_APP_SECRET' => 'meta_app_secret',
            'MAIL_HOST' => 'mail_host',
            'MAIL_PORT' => 'mail_port',
            'MAIL_USERNAME' => 'mail_username',
            'MAIL_PASSWORD' => 'mail_password',
            'MAIL_ENCRYPTION' => 'mail_encryption',
            'MAIL_FROM_ADDRESS' => 'mail_from_address',
            'MAIL_FROM_NAME' => 'mail_from_name',
            'SUPPORT_WHATSAPP_NUMBER' => 'support_whatsapp_number',
        ];

        return $map[$envKey] ?? ('integration_' . strtolower($envKey));
    }
}

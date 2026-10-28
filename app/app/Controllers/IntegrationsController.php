<?php
require_once dirname(__DIR__) . '/Core/IntegrationManager.php';
require_once dirname(__DIR__) . '/Services/Integrations/ConnectionGuide.php';
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
                    'env_keys' => array_values($meta['env_keys'] ?? []),
                    'optional_keys' => array_values($meta['optional_keys'] ?? []),
                    'importance' => $meta['importance'] ?? 'optional',
                    'description' => $meta['description'] ?? '',
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
                'connect' => '/integrations',
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

        $otaCustomerDescriptions=[
            'getyourguide'=>'Your GetYourGuide account.',
            'viator'=>'Your Viator account.',
            'bokun'=>'Connect your Bókun account.',
            'rezdy'=>'Connect your Rezdy account.',
            'booking'=>'Your Booking.com property account.',
            'expedia'=>'Your Expedia property account.',
            'klook'=>'Your Klook account.',
        ];
        foreach(OtaProviderCatalog::all() as $otaKey=>$otaMeta){$platforms[$otaKey]=['label'=>$otaMeta['label'],'desc'=>$isAdmin?$otaMeta['description']:$otaCustomerDescriptions[$otaKey],'connect'=>null,'icon'=>'globe'];}

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
        $isLinked = [];
        $guideTotal = 0;
        $guideLinked = 0;
        $todo = [];
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

            // منصات بتتربط لكل موقع على حدة: زرار ربط مباشر جنب كل موقع
            // (بدل لينك عام بيرجّع العميل لصفحة تانية).
            $perWebsite = [
                'google_business' => ['url' => '/reputation/connect/google/', 'label' => 'ربط Google Business'],
                'tripadvisor' => ['url' => '/reputation/connect/tripadvisor/', 'label' => 'ربط TripAdvisor'],
                'google_search_console' => ['url' => '/search-console/connect/', 'label' => 'ربط Search Console'],
                'google_analytics' => ['url' => '/google-analytics/connect/', 'label' => 'ربط Google Analytics'],
            ];
            if (isset($perWebsite[$key])) {
                $account = '';
                $action = '';
                $linkedCount = 0;
                try {
                    $sites = (new Website())->where(['user_id' => $userId], ['created_at' => 'DESC']);
                    foreach ($sites as $site) {
                        $siteId = (int) $site->getAttribute('id');
                        $label = htmlspecialchars((string) $site->getAttribute('main_url'), ENT_QUOTES, 'UTF-8');
                        $links = (new PlatformConnection())->where(['user_id' => $userId, 'website_id' => $siteId, 'platform' => $key], [], 1);
                        $linkStatus = !empty($links) ? (string) $links[0]->getAttribute('status') : '';
                        $linked = $linkStatus === 'connected';
                        if ($linked) {
                            $linkedCount++;
                        }
                        $status = $linked ? 'مربوط' : ($linkStatus === 'token_expired' ? 'انتهت الصلاحية - اربط تاني' : 'غير مربوط');
                        $button = $linked ? 'إعادة الربط' : $perWebsite[$key]['label'];
                        $action .= '<div style="margin:8px 0;overflow-wrap:anywhere;"><div class="p-cell-muted">' . $label . ' — ' . $status . '</div><a class="p-btn ' . ($linked ? 'outline' : 'primary') . ' xs" href="' . $perWebsite[$key]['url'] . $siteId . '">' . $button . '</a></div>';
                    }
                    if (empty($sites)) {
                        $action = '<a class="p-btn primary xs" href="/websites">أضف موقعك الأول</a>';
                    }
                } catch (Throwable $e) {
                    $action = '<span class="p-cell-muted">تعذر تحميل المواقع</span>';
                    Logger::error('Integrations website list failed', ['platform' => $key, 'message' => $e->getMessage()]);
                }
                $pill = $linkedCount > 0 ? '<span class="pill green">مربوط</span>' : '<span class="pill gray">غير مربوط</span>';
                $isLinked[$key] = $linkedCount > 0;
            }

            if(isset(OtaProviderCatalog::all()[$key])){
                $pill='<span class="pill gray">Per website</span>';$account='';$action='';
                $csrf=htmlspecialchars(Csrf::token(),ENT_QUOTES,'UTF-8');
                try{
                    $grants=$key==='bokun'?OtaIntegrationAdmin::authorizedSites($userId):[];
                    $sites=OtaIntegrationAdmin::customerAccounts($userId,$key);
                    foreach($sites as $site){
                        $wid=(int)$site['website_id'];$label=htmlspecialchars($site['label'],ENT_QUOTES,'UTF-8');
                        $linked=isset($grants[$wid]);$state=$linked?'Connected — data sync unavailable':match($site['state']){
                            'verified_read_only','synced_read_only','Supplier credentials verified; read-only'=>'Connected — read-only',
                            'Partner catalog connected'=>'Catalog connected',
                            'syncing'=>'Updating',
                            'partial'=>'Connected — update incomplete',
                            default=>'Not connected',
                        };
                        $action.='<div style="margin:10px 0;max-width:340px;"><div class="p-cell-muted">'.$label.' — '.htmlspecialchars($state,ENT_QUOTES,'UTF-8').'</div>';
                        if(in_array($key,['bokun','rezdy'],true)){
                            $action.='<form method="post" action="/ota/connect/'.$key.'"><input type="hidden" name="csrf_token" value="'.$csrf.'"><input type="hidden" name="website_id" value="'.$wid.'">';
                            if($key==='bokun'){
                                if($linked)$action.='<button class="p-btn xs" name="operation" value="disconnect">Disconnect</button>';
                                else $action.='<label>Bókun account subdomain <input class="p-input" name="domain" placeholder="mytours" pattern="[a-zA-Z0-9-]+" maxlength="63" required autocomplete="off"></label>';
                                $enabled=OtaIntegrationAdmin::appValue('BOKUN_CLIENT_ID')!==''&&OtaIntegrationAdmin::appValue('BOKUN_CLIENT_SECRET')!=='';
                                $action.='<button class="p-btn primary xs" name="operation" value="connect" '.($linked?'formnovalidate ': '').($enabled?'':'disabled').'>'.($linked?'Reconnect':'Connect').'</button>';
                                if($linked)$action.='<label>Account subdomain <input class="p-input" name="domain" placeholder="mytours" maxlength="63"></label>';
                                if(!$enabled)$action.='<div class="p-cell-muted">Currently unavailable</div>';
                            }else{
                                $action.='<details><summary class="p-btn primary xs">Connect</summary><label>Your Rezdy connection key <input class="p-input" type="password" name="api_key" autocomplete="new-password" required maxlength="2048"></label><p>Find this key in your Rezdy account settings. We never ask for your password.</p><button class="p-btn primary xs" name="operation" value="connect">Verify and connect</button></details>';
                                if($state!=='Not connected')$action.='<button class="p-btn xs" name="operation" value="disconnect" formnovalidate>Disconnect</button>';
                            }
                            $action.='</form>';
                        }else{
                            $reason=in_array($key,['viator','getyourguide'],true)?'Partner catalog API access does not authorize your supplier account. Supplier connectivity requires a separate approved integration.':'Awaiting an approved and implemented connectivity adapter. Signing into the provider does not connect your account to Tourfecto.';
                            if($isAdmin)$action.='<details><summary class="p-btn xs">Connect — requirements</summary><p>'.htmlspecialchars($reason,ENT_QUOTES,'UTF-8').'</p><a target="_blank" rel="noopener noreferrer" href="'.htmlspecialchars(OtaProviderCatalog::get($key)['docs'],ENT_QUOTES,'UTF-8').'">Provider connection requirements</a></details>';
                            else $action.='<button type="button" class="p-btn xs" disabled>Coming soon</button>';
                        }
                        $action.='</div>';
                    }
                    if(!$sites)$action='<a class="p-btn primary xs" href="/websites">Add a website first</a>';
                }catch(Throwable $e){$action='<span class="p-cell-muted">Connection unavailable. Please contact support.</span>';}
            }

            if (!isset($isLinked[$key])) {
                $isLinked[$key] = $conn && $conn['status'] === 'connected';
            }
            $missingKeys = ConnectionGuide::missingKeys($key, [IntegrationManager::class, 'settingValue']);
            if (!empty($missingKeys) && !$isLinked[$key]) {
                // الأدمن لسه مجهّزش مفاتيح المنصة: زرار الربط هيفشل، فمنعرضوش للعميل
                $pill = '<span class="pill yellow">جاري التجهيز</span>';
                $action = $isAdmin
                    ? '<span class="p-cell-muted" style="font-size:12px;">ناقص مفاتيح: ' . htmlspecialchars(implode(', ', $missingKeys), ENT_QUOTES, 'UTF-8') . '</span>'
                    : '<span class="p-cell-muted" style="font-size:12px;">الربط هيتفتح قريب</span>';
            }
            if (ConnectionGuide::get($key) !== null) {
                $guideTotal++;
                if ($isLinked[$key]) {
                    $guideLinked++;
                } elseif (empty($missingKeys)) {
                    $todo[] = $p['label'];
                }
            }
            $guideHtml = ConnectionGuide::renderHtml($key);

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
                . '</div>'
                . $guideHtml
                . '</div>';
        }

        // ملاحظات عامة للعميل
        $notes = '<div class="p-card" style="margin-top:16px;">'
            . '<div class="p-card-head"><h3>ملاحظات</h3></div>'
            . '<div class="p-cell-muted" style="font-size:13px;line-height:1.8;">'
            . 'Connect your accounts using the available buttons. Integrations marked Coming soon are not available yet. '
            . 'Your account access is stored securely. You can disconnect an account at any time.'
            . '</div></div>';

        $adminBlock = '';
        if ($isAdmin) {
            $adminBlock = '<div class="p-card" style="margin-top:16px;">'
                . '<div class="p-card-head"><h3>إدارة مفاتيح التكاملات (للمسؤول)</h3></div>'
                . '<div class="p-cell-muted" style="margin-bottom:10px;">حالة تكوين كل تكامل على مستوى المنصة والمفاتيح المطلوبة.</div>'
                . '<div id="adminIntegrationsBox"><div class="p-loading-row">جارِ التحميل...</div></div>'
                . '</div>';
        }

        $notice=$_SESSION['ota_connection_notice']??'';unset($_SESSION['ota_connection_notice']);
        $otaNotice=is_string($notice)&&$notice!==''?'<div class="p-card" role="status">'.htmlspecialchars($notice,ENT_QUOTES,'UTF-8').'</div>':'';
        $todoHtml = '';
        if (!empty($todo)) {
            $todoHtml = '<div class="p-cell-muted" style="margin-top:6px;font-size:13px;">لسه مستنيين تربط: '
                . htmlspecialchars(implode('، ', $todo), ENT_QUOTES, 'UTF-8')
                . '. افتح "إزاي أربط؟" تحت كل خدمة وامشي على الخطوات.</div>';
        }
        $summary = '<div class="p-card" style="margin-bottom:16px;">'
            . '<div style="font-weight:700;font-size:16px;">ربطت ' . $guideLinked . ' من ' . $guideTotal . ' خدمات</div>'
            . '<div class="p-cell-muted" style="font-size:13px;">كل خدمة تربطها، المنصة بتشتغل عليها لوحدها بدالك. الحاجات اللي محتاجة منك خطوة مكتوبة تحت كل خدمة.</div>'
            . $todoHtml
            . '</div>';
        $body = $otaNotice.$summary.'<div class="p-card" style="margin-bottom:16px;">'
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
            // أسماء المتغيرات نفسها مش سر (القيم بس هي السر)، والصفحة دي للأدمن بس.
            // كانت بتترجع مخفية (OPE****) فالواجهة كانت بتبعتها كده في الحفظ
            // والسيرفر يرفضها: "مافيش مفاتيح صالحة اتحفظت".
            'env_keys' => array_values($meta['env_keys'] ?? []),
            'env_keys_set' => $this->keysSet($meta['env_keys'] ?? []),
            'optional_keys' => array_values($meta['optional_keys'] ?? []),
            'description' => $meta['description'] ?? '',
            'importance' => $meta['importance'] ?? 'optional',
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
        if((array_key_exists($key,OtaProviderCatalog::all())||$key==='bokun_app')&&($csrfError=$this->verifyCsrf()))return $csrfError;
        $meta = IntegrationManager::meta($key);
        if ($meta === null) {
            return $this->error('التكامل غير موجود', 404);
        }

        $values = (array) $this->get('values', []);
        if (empty($values)) {
            return $this->error('مفيش مفاتيح للتحديث', 422);
        }

        $allowed = $meta['env_keys'] ?? [];
        $settings = new SystemSettingsService();
        $saved = 0;

        foreach ($values as $envKey => $value) {
            $envKey = (string) $envKey;
            // متصفح لسه شايل الصفحة القديمة بيبعت الاسم مخفي (OPE****): نرجّعه لاسمه الحقيقي لو مفيش لبس
            if (!in_array($envKey, $allowed, true) && strpos($envKey, '*') !== false) {
                $matches = array_values(array_filter($allowed, fn ($a) => $this->maskKeyName($a) === $envKey));
                if (count($matches) === 1) {
                    $envKey = $matches[0];
                }
            }
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
            // الخدمات (Google/Meta...) بتقرا google_client_id من صفحة الإعدادات الأول:
            // نحدّثها كمان عشان القيمة الجديدة تتستخدم فعلًا ومايفضلش القديم شغال
            if (SystemSettingsService::hasKey(strtolower($envKey))) {
                $settings->set(strtolower($envKey), $value);
            }
            if((array_key_exists($key,OtaProviderCatalog::all())||$key==='bokun_app')&&!hash_equals($value,(string)$settings->get($settingKey,'')))return $this->error('Unable to persist OTA settings. Check settings storage.',503);
            $saved++;
        }

        if ($saved === 0) {
            return $this->error('مافيش مفاتيح صالحة اتحفظت', 422);
        }

        // الخدمات اللي ليها مفتاح تشغيل (ULTRAMSG_ENABLED, SLACK_ENABLED ...) كانت
        // بتفضل مقفولة حتى بعد حفظ المفاتيح. الحفظ من هنا = تشغيل.
        $enabledEnv = (string) ($meta['enabled_env'] ?? '');
        if ($enabledEnv !== '' && !array_key_exists($enabledEnv, $values)) {
            $settings->set($this->envToSettingKey($enabledEnv), 'true');
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
        if((array_key_exists($key,OtaProviderCatalog::all())||$key==='bokun_app')&&($csrfError=$this->verifyCsrf()))return $csrfError;
        $meta = IntegrationManager::meta($key);
        if ($meta === null) {
            return $this->error('التكامل غير موجود', 404);
        }

        if (!IntegrationManager::isConfigured($key)) {
            return $this->error('التكامل مش مكتمل الإعداد - املأ المفاتيح الأول', 422);
        }

        // اختبار حقيقي مباشر مع المزوّد للتكاملات المهمة (كتير من كلاسات
        // التكاملات المسجّلة مش موجودة أصلًا، فالزرار كان دايمًا بيفشل).
        $builtin = $this->builtinTest($key);
        if ($builtin !== null) {
            self::recordCheck($key, (bool) $builtin['ok'], (string) $builtin['message']);
            return $builtin['ok']
                ? $this->success(['ok' => true, 'http_code' => $builtin['http'] ?? null], $builtin['message'])
                : $this->error($builtin['message'], 422);
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

            return $this->error('فشل الاتصال: ' . ($result['error'] ?? 'خطأ غير معروف'), 422);
        } catch (Throwable $e) {
            Logger::error('IntegrationsController::test failed', ['integration' => $key, 'message' => $e->getMessage()]);
            return $this->error('مفيش اختبار تلقائي للتكامل ده لسه. المفاتيح محفوظة، والتأكيد الحقيقي بيحصل أول ما الخدمة تستخدمه.', 422);
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

    /** إخفاء آخر أحرف اسم مفتاح الـ env عشان ما يتعرضش كامل للواجهة */
    /**
     * اختبار تلقائي من cron/admin_watchdog (من غير أدمن ولا إرسال إيميل تجربة).
     * بيرجّع null لو التكامل مالوش اختبار تلقائي أو مش متظبط.
     */
    public function runAutomatedCheck(string $key): ?array
    {
        if (IntegrationManager::meta($key) === null || !IntegrationManager::isConfigured($key)) {
            return null;
        }
        $this->user = [];
        try {
            $r = $this->builtinTest($key);
        } catch (Throwable $e) {
            $r = ['ok' => false, 'message' => 'خطأ أثناء الاختبار: ' . $e->getMessage()];
        }
        if ($r !== null) {
            self::recordCheck($key, (bool) $r['ok'], (string) $r['message']);
        }
        return $r;
    }

    /** آخر نتيجة اختبار (لصفحة صحة المنصة والتنبيهات) */
    public static function recordCheck(string $key, bool $ok, string $message): void
    {
        try {
            Database::getInstance()->query(
                'INSERT INTO integration_checks (integration_key, ok, message, fails_in_row, checked_at) VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE ok = VALUES(ok), message = VALUES(message), checked_at = NOW(),
                    fails_in_row = IF(VALUES(ok) = 1, 0, fails_in_row + 1)',
                [$key, $ok ? 1 : 0, mb_substr($message, 0, 1000), $ok ? 0 : 1]
            );
        } catch (Throwable $e) {
        }
    }

    /**
     * اختبار مباشر: طلب صغير للمزوّد بالمفتاح المحفوظ.
     * بيرجّع null لو التكامل مالوش اختبار هنا (يكمّل على كلاس التكامل).
     */
    private function builtinTest(string $key): ?array
    {
        $v = fn (string $k): string => $this->integrationValue($k);
        if(array_key_exists($key,OtaProviderCatalog::all())||$key==='bokun_app')return OtaIntegrationAdmin::verify($key,$v);
        switch ($key) {
            case 'openai':
                return $this->httpCheck('GET', 'https://api.openai.com/v1/models', ['Authorization: Bearer ' . $v('OPENAI_API_KEY')], 'OpenAI');
            case 'anthropic':
                return $this->httpCheck('GET', 'https://api.anthropic.com/v1/models', ['x-api-key: ' . $v('ANTHROPIC_API_KEY'), 'anthropic-version: 2023-06-01'], 'Anthropic');
            case 'gemini':
                return $this->httpCheck('GET', 'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode($v('GEMINI_API_KEY')), [], 'Gemini');
            case 'stripe':
                return $this->httpCheck('GET', 'https://api.stripe.com/v1/balance', ['Authorization: Bearer ' . $v('STRIPE_API_KEY')], 'Stripe');
            case 'whatsapp':
                return $this->httpCheck('GET', 'https://graph.facebook.com/v19.0/' . rawurlencode($v('WHATSAPP_PHONE_ID')) . '?fields=id', ['Authorization: Bearer ' . $v('WHATSAPP_ACCESS_TOKEN')], 'WhatsApp');
            case 'meta_ads':
                // التطبيق نفسه: App ID + Secret بيطلعوا app access token
                return $this->httpCheck('GET', 'https://graph.facebook.com/oauth/access_token?grant_type=client_credentials&client_id=' . rawurlencode($v('META_APP_ID')) . '&client_secret=' . rawurlencode($v('META_APP_SECRET')), [], 'Meta');
            case 'paypal':
                $live = strtolower($v('PAYPAL_MODE')) === 'live';
                return $this->httpCheck('POST', 'https://api-m.' . ($live ? '' : 'sandbox.') . 'paypal.com/v1/oauth2/token', [
                    'Authorization: Basic ' . base64_encode($v('PAYPAL_CLIENT_ID') . ':' . $v('PAYPAL_CLIENT_SECRET')),
                    'Content-Type: application/x-www-form-urlencoded',
                ], 'PayPal', 'grant_type=client_credentials');
            case 'google_search_console':
            case 'google_analytics':
            case 'google_business':
                return $this->googleOAuthCheck('GOOGLE_OAUTH_REDIRECT_URI');
            case 'google_ads':
                $g = $this->googleOAuthCheck('GOOGLE_ADS_OAUTH_REDIRECT_URI');
                if ($g['ok'] && $v('GOOGLE_ADS_DEVELOPER_TOKEN') === '') {
                    return ['ok' => false, 'message' => 'بيانات Google سليمة، بس GOOGLE_ADS_DEVELOPER_TOKEN ناقص. هاته من Google Ads ← Tools ← API Center.'];
                }
                return $g;
            case 'youtube':
                return $this->googleOAuthCheck('YOUTUBE_OAUTH_REDIRECT_URI');
            case 'deepseek':
                return $this->httpCheck('GET', rtrim(defined('DEEPSEEK_API_BASE_URL') ? DEEPSEEK_API_BASE_URL : 'https://api.deepseek.com/v1', '/') . '/models', ['Authorization: Bearer ' . $v('DEEPSEEK_API_KEY')], 'DeepSeek');
            case 'kimi':
                return $this->httpCheck('GET', rtrim(defined('KIMI_API_BASE_URL') ? KIMI_API_BASE_URL : 'https://api.moonshot.ai/v1', '/') . '/models', ['Authorization: Bearer ' . $v('KIMI_API_KEY')], 'Kimi');
            case 'paymob':
                $r = $this->httpCheck('POST', 'https://accept.paymob.com/api/auth/tokens', ['Content-Type: application/json'], 'Paymob', json_encode(['api_key' => $v('PAYMOB_API_KEY')]));
                if ($r['ok'] && (!ctype_digit($v('PAYMOB_INTEGRATION_ID')) || !ctype_digit($v('PAYMOB_IFRAME_ID')))) {
                    return ['ok' => false, 'message' => 'الـ API Key سليم، بس PAYMOB_INTEGRATION_ID و PAYMOB_IFRAME_ID لازم يكونوا أرقام (من Paymob ← Developers ← Payment Integrations / iframes).'];
                }
                return $r;
            case 'smtp':
                return $this->smtpCheck();
            case 'google_maps':
                $r = $this->httpRaw('GET', 'https://maps.googleapis.com/maps/api/geocode/json?address=Cairo&key=' . rawurlencode($v('GOOGLE_MAPS_API_KEY')), []);
                if ($r['error'] !== '') {
                    return ['ok' => false, 'message' => 'السيرفر مش قادر يوصل لـ Google Maps: ' . $r['error']];
                }
                $j = json_decode($r['body'], true) ?: [];
                if (($j['status'] ?? '') === 'OK') {
                    return ['ok' => true, 'http' => $r['http'], 'message' => 'مفتاح Google Maps شغال ✓'];
                }
                return ['ok' => false, 'message' => 'Google Maps رفض المفتاح (' . ($j['status'] ?? $r['http']) . '): ' . mb_substr((string) ($j['error_message'] ?? 'فعّل Geocoding و Places API للمفتاح'), 0, 160)];
            case 'google_indexing':
                $raw = $v('GOOGLE_SERVICE_ACCOUNT_JSON');
                $j = json_decode(trim($raw), true) ?: json_decode((string) base64_decode($raw, true), true);
                if (strpos($raw, 'AIza') === 0) {
                    return ['ok' => false, 'message' => 'ده API Key عادي (بيبدأ بـ AIza) - مكانه Google Maps / Places. هنا محتاج ملف Service Account (JSON): Google Cloud ← IAM ← Service Accounts ← Keys ← Add key ← JSON، وافتح الملف وانسخ كل اللي فيه.'];
                }
                if (!is_array($j) || empty($j['client_email']) || empty($j['private_key'])) {
                    return ['ok' => false, 'message' => 'ده مش ملف Service Account. افتح ملف الـ JSON اللي نزل من Google Cloud وانسخ كل اللي فيه من { لحد }.'];
                }
                return ['ok' => true, 'message' => 'ملف الـ Service Account سليم ✓ (' . $j['client_email'] . '). ضيف الإيميل ده كـ Owner في Search Console لكل موقع.'];
            case 'meta_capi':
                return $this->httpCheck('GET', 'https://graph.facebook.com/v19.0/' . rawurlencode($v('META_CAPI_PIXEL_ID')) . '?fields=id,name', ['Authorization: Bearer ' . $v('META_CAPI_ACCESS_TOKEN')], 'Meta Pixel');
            case 'ultramsg':
                $r = $this->httpRaw('GET', 'https://api.ultramsg.com/' . rawurlencode($v('ULTRAMSG_INSTANCE_ID')) . '/instance/status?token=' . rawurlencode($v('ULTRAMSG_API_KEY')), []);
                if ($r['error'] !== '') {
                    return ['ok' => false, 'message' => 'السيرفر مش قادر يوصل لـ UltraMsg: ' . $r['error']];
                }
                $j = json_decode($r['body'], true) ?: [];
                $st = strtolower((string) ($j['status']['accountStatus']['status'] ?? ''));
                if ($st === 'authenticated') {
                    return ['ok' => true, 'message' => 'UltraMsg متصل والرقم شغال ✓'];
                }
                if (isset($j['error'])) {
                    return ['ok' => false, 'message' => 'UltraMsg رفض البيانات: ' . (is_string($j['error']) ? $j['error'] : json_encode($j['error'], JSON_UNESCAPED_UNICODE))];
                }
                return ['ok' => false, 'message' => 'البيانات سليمة بس الرقم مش متوصّل (' . ($st ?: 'غير معروف') . '). افتح لوحة UltraMsg وامسح الـ QR من واتساب.'];
            case 'wati':
                $base = rtrim($v('WATI_BASE_URL') ?: 'https://live-server.wati.io', '/');
                $tok = $v('WATI_API_KEY');
                return $this->httpCheck('GET', $base . '/api/v1/getContacts?pageSize=1', ['Authorization: ' . (stripos($tok, 'bearer ') === 0 ? $tok : 'Bearer ' . $tok)], 'WATI');
            case 'keyword_research':
                $url = $v('KEYWORD_RESEARCH_API_URL');
                if (!preg_match('#^https://#', $url)) {
                    return ['ok' => false, 'message' => 'KEYWORD_RESEARCH_API_URL لازم يكون رابط https://'];
                }
                return $this->httpCheck('POST', $url, ['Content-Type: application/json', 'Authorization: Bearer ' . $v('KEYWORD_RESEARCH_API_KEY')], 'Keyword Research', json_encode(['keywords' => ['tourfecto test'], 'test' => true]));
            case 'tiktok':
                return $this->httpCheck('POST', 'https://open.tiktokapis.com/v2/oauth/token/', ['Content-Type: application/x-www-form-urlencoded'], 'TikTok',
                    http_build_query(['client_key' => $v('TIKTOK_CLIENT_KEY'), 'client_secret' => $v('TIKTOK_CLIENT_SECRET'), 'grant_type' => 'client_credentials']));
            case 'contentsquare':
                return preg_match('/^[a-z0-9]{8,20}$/i', $v('CONTENTSQUARE_TAG_ID'))
                    ? ['ok' => true, 'message' => 'رقم الـ Tag شكله سليم ✓ (اتأكد من الإحصائيات في لوحة Contentsquare بعد ساعة).']
                    : ['ok' => false, 'message' => 'CONTENTSQUARE_TAG_ID شكله غلط.'];
        }
        return null;
    }

    /**
     * Google OAuth مايتختبرش من غير ما حد يسجّل دخول، فبنتأكد إن البيانات
     * سليمة الشكل، وإن Google عارف الـ Client ID ده (endpoint الـ token
     * بيرجّع invalid_client لو الـ ID أو الـ Secret غلط).
     */
    private function googleOAuthCheck(string $redirectKey): array
    {
        $id = $this->integrationValue('GOOGLE_CLIENT_ID');
        $secret = $this->integrationValue('GOOGLE_CLIENT_SECRET');
        $redirect = $this->integrationValue($redirectKey);
        if (!preg_match('/\.apps\.googleusercontent\.com$/', $id)) {
            return ['ok' => false, 'message' => 'GOOGLE_CLIENT_ID شكله غلط: لازم ينتهي بـ .apps.googleusercontent.com'];
        }
        if ($redirect !== '' && !preg_match('#^https://#', $redirect)) {
            return ['ok' => false, 'message' => $redirectKey . ' لازم يبدأ بـ https://'];
        }
        $r = $this->httpRaw('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['client_id' => $id, 'client_secret' => $secret, 'grant_type' => 'authorization_code', 'code' => 'tourfecto-test', 'redirect_uri' => $redirect ?: 'https://example.com']));
        if ($r['error'] !== '') {
            return ['ok' => false, 'message' => 'السيرفر مش قادر يوصل لـ Google: ' . $r['error']];
        }
        $err = (string) (json_decode($r['body'], true)['error'] ?? '');
        if ($err === 'invalid_client' || $err === 'unauthorized_client') {
            return ['ok' => false, 'message' => 'Google رفض الـ Client ID أو الـ Client Secret. راجعهم من Google Cloud ← Credentials.'];
        }
        // invalid_grant = الكود التجريبي اترفض، يعني الـ ID والـ Secret نفسهم اتقبلوا
        return ['ok' => true, 'http' => $r['http'], 'message' => 'بيانات Google سليمة ✓ - الربط الفعلي بيحصل لما العميل يدوس "ربط". اتأكد إن ' . ($redirect ?: 'رابط الرجوع') . ' متضاف في Google Cloud.'];
    }

    /** SMTP: اتصال + تسجيل دخول حقيقي (من غير ما نبعت إيميل) */
    private function smtpCheck(): array
    {
        $host = $this->integrationValue('MAIL_HOST');
        $port = (int) ($this->integrationValue('MAIL_PORT') ?: 587);
        $user = $this->integrationValue('MAIL_USERNAME');
        $pass = $this->integrationValue('MAIL_PASSWORD');
        $enc = strtolower($this->integrationValue('MAIL_ENCRYPTION') ?: ($port === 465 ? 'ssl' : 'tls'));
        if ($host === '') {
            return ['ok' => false, 'message' => 'MAIL_HOST فاضي'];
        }
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $fp = @stream_socket_client($remote, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            return ['ok' => false, 'message' => "مش قادر أوصل لـ {$host}:{$port} ({$errstr}). راجع الـ Host والـ Port، وإن البورت مفتوح في جدار حماية السيرفر."];
        }
        stream_set_timeout($fp, 10);
        $read = function () use ($fp): string {
            $out = '';
            while (($line = fgets($fp, 515)) !== false) {
                $out .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $out;
        };
        $cmd = function (string $c) use ($fp, $read): string {
            fwrite($fp, $c . "\r\n");
            return $read();
        };
        $read();
        $ehlo = $cmd('EHLO tourfecto.local');
        if ($enc === 'tls') {
            if (strpos($cmd('STARTTLS'), '220') !== 0 || !@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($fp);
                return ['ok' => false, 'message' => 'السيرفر رفض TLS. جرّب MAIL_ENCRYPTION = ssl مع بورت 465.'];
            }
            $cmd('EHLO tourfecto.local');
        }
        if ($user !== '') {
            $cmd('AUTH LOGIN');
            $cmd(base64_encode($user));
            $res = $cmd(base64_encode($pass));
            if (strpos($res, '235') !== 0) {
                $cmd('QUIT');
                fclose($fp);
                return ['ok' => false, 'message' => 'السيرفر وصلناه بس رفض اليوزر أو الباسورد: ' . trim(mb_substr($res, 0, 140))];
            }
        }
        $cmd('QUIT');
        fclose($fp);

        // تسجيل الدخول نجح: نبعت إيميل تجربة حقيقي للأدمن بنفس الطريقة اللي المنصة بتبعت بيها
        $to = (string) ($this->user['email'] ?? '');
        $sent = '';
        if ($to !== '' && class_exists('Mailer')) {
            $res = (new Mailer())->send($to, 'Tourfecto Admin', 'اختبار البريد - Tourfecto',
                '<div dir="rtl" style="font-family:Tahoma,Arial;font-size:15px">لو الرسالة دي وصلتك يبقى بريد المنصة شغال ✓<br><small>' . date('Y-m-d H:i') . '</small></div>');
            if (empty($res['success'])) {
                return ['ok' => false, 'message' => "تسجيل الدخول نجح، بس الإرسال فشل: " . ($res['error'] ?? 'غير معروف') . $this->mailFromHint($user)];
            }
            $sent = " واتبعت إيميل تجربة لـ {$to} - لو ماوصلش في دقيقة شوف Spam.";
        }
        $dns = $this->mailDnsWarnings($host);
        return ['ok' => true, 'message' => "البريد شغال ✓ (اتصلنا بـ {$host} وسجّلنا دخول)." . $sent . $this->mailFromHint($user) . $dns];
    }

    /** Hostinger وأغلب السيرفرات بيرفضوا/بيرموا الإيميل لو المرسل غير الإيميل اللي بنسجّل بيه */
    private function mailFromHint(string $user): string
    {
        $from = $this->integrationValue('MAIL_FROM_ADDRESS');
        if ($from !== '' && $user !== '' && strcasecmp($from, $user) !== 0) {
            return " ⚠️ MAIL_FROM_ADDRESS ({$from}) مختلف عن MAIL_USERNAME ({$user}) - خليهم نفس الإيميل، وإلا الرسايل ممكن تتشال من غير ما تتبعت.";
        }
        return '';
    }

    /** SPF / DKIM / DMARC للدومين اللي بنبعت منه (أشهر سبب إن Gmail يرمي الرسالة) */
    private function mailDnsWarnings(string $smtpHost): string
    {
        $from = $this->integrationValue('MAIL_FROM_ADDRESS') ?: $this->integrationValue('MAIL_USERNAME');
        $domain = strtolower((string) substr(strrchr($from, '@') ?: '', 1));
        if ($domain === '' || !function_exists('dns_get_record')) {
            return '';
        }
        $txt = function (string $name): array {
            $out = [];
            foreach ((array) @dns_get_record($name, DNS_TXT) as $r) {
                $out[] = strtolower((string) ($r['txt'] ?? ''));
            }
            return $out;
        };
        $warn = [];
        $spf = array_values(array_filter($txt($domain), fn ($t) => strpos($t, 'v=spf1') === 0));
        $isHostinger = stripos($smtpHost, 'hostinger') !== false;
        if (!$spf) {
            $warn[] = 'مفيش SPF';
        } elseif ($isHostinger && strpos($spf[0], 'hostinger') === false) {
            $warn[] = 'الـ SPF مافيهوش include:_spf.mail.hostinger.com';
        }
        if (!$txt('_dmarc.' . $domain)) {
            $warn[] = 'مفيش DMARC';
        }
        if ($isHostinger) {
            $dkim = false;
            foreach (['hostingermail-a', 'hostingermail-b', 'hostingermail1'] as $sel) {
                if (@dns_get_record($sel . '._domainkey.' . $domain, DNS_CNAME) || $txt($sel . '._domainkey.' . $domain)) {
                    $dkim = true;
                    break;
                }
            }
            if (!$dkim) {
                $warn[] = 'مفيش DKIM';
            }
        }
        if (!$warn) {
            return " DNS الدومين {$domain} سليم (SPF/DKIM/DMARC).";
        }
        return " ⚠️ دومين {$domain}: " . implode('، ', $warn) . ' - Gmail غالبًا هيرمي الرسايل أو يحطها Spam. ضيف السجلات دي في DNS الدومين (من Hostinger ← Emails ← DNS settings، وانسخها في المكان اللي الدومين بيشاور عليه).';
    }

    private function httpCheck(string $method, string $url, array $headers, string $name, ?string $body = null): array
    {
        $r = $this->httpRaw($method, $url, $headers, $body);
        if ($r['error'] !== '') {
            return ['ok' => false, 'message' => 'السيرفر مش قادر يوصل لـ ' . $name . ': ' . $r['error']];
        }
        if ($r['http'] >= 200 && $r['http'] < 300) {
            return ['ok' => true, 'http' => $r['http'], 'message' => 'الاتصال بـ ' . $name . ' ناجح ✓'];
        }
        $j = json_decode($r['body'], true);
        $detail = '';
        if (is_array($j)) {
            $e = $j['error'] ?? $j['message'] ?? '';
            $detail = is_array($e) ? (string) ($e['message'] ?? $e['type'] ?? '') : (string) $e;
            if ($detail === '' && isset($j['error_description'])) {
                $detail = (string) $j['error_description'];
            }
        }
        $hint = in_array($r['http'], [401, 403], true) ? 'المفتاح غلط أو مالوش صلاحية' : ($r['http'] === 429 ? 'الحساب وصل للحد أو الرصيد خلص' : 'رد غير متوقع');
        return ['ok' => false, 'http' => $r['http'], 'message' => $name . ' رفض الاتصال (' . $r['http'] . '): ' . $hint . ($detail !== '' ? ' - ' . mb_substr($detail, 0, 160) : '')];
    }

    private function httpRaw(string $method, string $url, array $headers, ?string $body = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        $res = ['http' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => is_string($out) ? $out : '', 'error' => $out === false ? curl_error($ch) : ''];
        curl_close($ch);
        return $res;
    }

    /** القيمة المحفوظة من اللوحة، وإلا من .env */
    private function integrationValue(string $envKey): string
    {
        try {
            $svc = new SystemSettingsService();
            $v = $svc->get($this->envToSettingKey($envKey), '');
            if ($v === '' && SystemSettingsService::hasKey(strtolower($envKey))) {
                $v = $svc->get(strtolower($envKey), ''); // صفحة الأدمن ← الإعدادات
            }
            if ($v !== '') {
                return trim($v);
            }
        } catch (Throwable $e) {
        }
        if (defined($envKey) && (string) constant($envKey) !== '') {
            return trim((string) constant($envKey));
        }
        $e = function_exists('env') ? env($envKey) : getenv($envKey);
        return is_string($e) ? trim($e) : '';
    }

    /** أنهي مفاتيح ليها قيمة محفوظة بالفعل (من غير ما نرجّع القيمة) */
    private function keysSet(array $envKeys): array
    {
        $out = [];
        $settings = null;
        try {
            $settings = new SystemSettingsService();
        } catch (Throwable $e) {
        }
        foreach ($envKeys as $k) {
            $has = false;
            try {
                $has = $this->integrationValue($k) !== '';
            } catch (Throwable $e) {
            }
            $out[$k] = $has;
        }
        return $out;
    }

    private function maskKeyName(string $envKey): string
    {
        $len = strlen($envKey);
        if ($len <= 4) {
            return $envKey;
        }
        return substr($envKey, 0, 3) . str_repeat('*', $len - 3);
    }

    /** تحويل اسم متغير env لاسم مفتاح setting (lowercase underscore) */
    private function envToSettingKey(string $envKey): string
    {
        return 'integration_' . strtolower($envKey);
    }
}

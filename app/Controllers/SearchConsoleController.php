<?php
require_once __DIR__ . '/../Services/Security/OAuthState.php';

/**
 * Tourfecto - Search Console Controller
 * ربط حساب Google Search Console الخاص بكل عميل (OAuth) بكل موقع من مواقعه،
 * وجلب بيانات الأداء في نتائج البحث (clicks/impressions/ctr/position).
 * @version 1.0.0
 *
 * نفس فلسفة ReputationController::connectGoogleBusiness/googleOAuthCallback،
 * لكن هنا "الفرع" اللي العميل بيختاره هو site في Search Console مش location
 * في Business Profile، فمفيش حاجة اسمها account/location - بس site_url.
 *
 * المتطلبات قبل ما ده يشتغل فعليًا (خارج الكود، من Google Cloud Console -
 * نفس المشروع المستخدم لـ Google Business بالظبط):
 *  1) تفعيل "Google Search Console API" في المشروع.
 *  2) إضافة GOOGLE_SEARCH_CONSOLE_REDIRECT_URI كـ Authorized redirect URI
 *     في نفس OAuth Client، وإضافة قيمته في .env.
 *  3) GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET نفسهم المستخدمين في .env
 *     لـ Google Business (نفس المشروع، عميل OAuth واحد كفاية لأكتر من API).
 */
class SearchConsoleController extends Controller
{
    private function userId(): ?int
    {
        return $this->user['id'] ?? null;
    }

    private function oauthClient(bool $allowSubmit=false): GoogleOAuthClient
    {
        // env() also reads $_ENV/$_SERVER when putenv() is disabled on hosting.
        $redirectUri = trim((string) (defined('GOOGLE_SEARCH_CONSOLE_REDIRECT_URI')
            ? GOOGLE_SEARCH_CONSOLE_REDIRECT_URI
            : env('GOOGLE_SEARCH_CONSOLE_REDIRECT_URI', '')));

        if ($redirectUri === '') {
            $appUrl = defined('APP_URL') ? APP_URL : env('APP_URL', '');
            $redirectUri = $appUrl ? rtrim((string) $appUrl, '/') . '/search-console/callback' : '';
        }

        // An empty string deliberately fails configuration instead of falling
        // back to GoogleOAuthClient's default Business Profile callback.
        return new GoogleOAuthClient($allowSubmit?GoogleOAuthClient::SCOPE_SEARCH_CONSOLE_WRITE:GoogleOAuthClient::SCOPE_SEARCH_CONSOLE, $redirectUri);
    }

    private function renderOAuthError(string $message): void
    {
        $message = CustomerError::oauthMessage($message);
        $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $websiteId = (int) ($_SESSION['gsc_oauth_temp']['website_id'] ?? 0);
        $retry = $websiteId > 0 ? '<a class="p-btn primary" href="/search-console/connect/' . $websiteId . '">إعادة ربط الحساب</a><br><br>' : '';
        $body = '<div class="p-card"><div class="p-empty"><div class="p-empty-icon">⚠️</div>' . $safe
            . '<br><br>' . $retry . '<a href="/websites" class="p-btn primary">الرجوع لمواقعي</a></div></div>';
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('_websites', 'تعذر الربط', 'Google Search Console', $body, '');
    }

    // ============================================
    // GET /search-console/connect/{website_id} - يبدأ تدفّق OAuth
    // ============================================
    private static function returnPath($path): string{return in_array($path,['/seo-expert','/business-hub','/websites','/international-seo'],true)?$path:'/websites';}
    private static function propertyMatches(string $property,string $url): bool{
        if(!preg_match('#^https?://#i',$url))$url='https://'.$url;$host=strtolower((string)parse_url($url,PHP_URL_HOST));$host=preg_replace('/^www\./','',$host);if($host==='')return false;
        if(str_starts_with($property,'sc-domain:')){$domain=strtolower(substr($property,10));return $host===$domain||str_ends_with($host,'.'.$domain);}
        $p=parse_url($property);if(!$p||!in_array($p['scheme']??'', ['http','https'],true)||!empty($p['user'])||!empty($p['pass']))return false;
        return preg_replace('/^www\./','',strtolower($p['host']??''))===$host;
    }
    private static function hostOf(string $url): string
    {
        if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        return (string) preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
    }

    /** خصائص Search Console اللي بتطابق الموقع، الأنسب الأول: Domain (بتغطي كل الموقع) ← https ← http */
    public static function matchingProperties(array $sites, string $websiteUrl): array
    {
        $m = array_values(array_filter($sites, fn ($s) => self::propertyMatches((string) ($s['site_url'] ?? ''), $websiteUrl)));
        $rank = fn (array $s) => (($s['permission_level'] ?? '') === 'siteUnverifiedUser' ? 10 : 0)
            + (str_starts_with((string) $s['site_url'], 'sc-domain:') ? 0 : (str_starts_with((string) $s['site_url'], 'https://') ? 1 : 2));
        usort($m, fn ($a, $b) => $rank($a) <=> $rank($b));
        return $m;
    }

    /** الخاصية اللي نربطها من غير ما نسأل: واحدة بس متوثّقة بتطابق، أو Domain متوثّقة. غير كده العميل يختار. */
    public static function autoProperty(array $matches): ?string
    {
        $usable = array_values(array_filter($matches, fn ($s) => ($s['permission_level'] ?? '') !== 'siteUnverifiedUser'));
        if (!$usable) return null;
        if (count($usable) === 1 || str_starts_with((string) $usable[0]['site_url'], 'sc-domain:')) return (string) $usable[0]['site_url'];
        return null;
    }

    /** بعد الربط: صفحة الموقع نفسه (بيظهر فيها الربط والأرقام) بدل قايمة المواقع */
    private static function afterConnectPath(array $temp): string
    {
        $path = self::returnPath($temp['return_to'] ?? '/websites');
        return $path === '/websites' ? '/websites/' . (int) ($temp['website_id'] ?? 0) : $path;
    }

    private bool $allowSitemapSubmit=false;
    public function allowSitemapSubmit(array $params=[]): array {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        if($e=$this->verifyCsrf())return $e;
        if((string)$this->get('allow_sitemap_submit','')!=='1')return $this->error('Confirm Allow sitemap submit first',422);
        if(!$this->db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='seo_sitemap_permissions'"))return $this->error('Apply Phase 6 migration first',503);
        $this->allowSitemapSubmit=true;return $this->connect($params);
    }
    public function connect(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/websites'));
            exit;
        }

        $websiteId = (int) ($params['website_id'] ?? 0);
        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->userId()) {
            $this->renderOAuthError('الموقع غير موجود أو ملكش صلاحية عليه');
            exit;
        }

        // خدمة السيو بتبعت خريطة الموقع لجوجل لوحدها، فبنطلب الإذن ده مرة واحدة وقت الربط
        if (!$this->allowSitemapSubmit && $this->get('return_to') === '/seo-expert' && $this->db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='seo_sitemap_permissions'")) {
            $this->allowSitemapSubmit = true;
        }
        $oauth = $this->oauthClient($this->allowSitemapSubmit);
        if (!$oauth->isConfigured()) {
            $this->renderOAuthError('ربط Search Console لسه مش مفعّل من إدارة النظام (بيانات OAuth ناقصة في إعدادات السيرفر - راجع GOOGLE_SEARCH_CONSOLE_REDIRECT_URI في .env).');
            exit;
        }

        $nonce = bin2hex(random_bytes(16));
        $_SESSION['gsc_oauth_nonce'] = $nonce;

        $state = OAuthState::issue(['aud' => 'search_console', 'allow_sitemap_submit'=>$this->allowSitemapSubmit, 'nonce' => $nonce, 'website_id' => $websiteId,'return_to'=>self::returnPath($this->get('return_to','/websites'))], (int) ($this->user['id'] ?? 0));

        header('Location: ' . $oauth->buildAuthUrl($state));
        exit;
    }

    // ============================================
    // GET /search-console/callback - Google بيرجّع العميل هنا
    // ============================================
    public function callback(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $error = $this->get('error');
        if ($error) {
            $this->renderOAuthError('العميل رفض الموافقة أو حصل خطأ من Google: ' . $error);
            exit;
        }

        $code = $this->get('code');
        $state = $this->get('state');
        if (!$code || !$state) {
            $this->renderOAuthError('رد غير مكتمل من Google');
            exit;
        }

        $decodedState = OAuthState::verify((string) $state, (int) ($this->user['id'] ?? 0), $_SESSION['gsc_oauth_nonce'] ?? null, 'search_console');
        // تصحيح: التحقق بقى من state موقّع مربوط بالمستخدم - مش معتمد على جلسة PHP بس
        if (!$decodedState) {
            $this->renderOAuthError('انتهت صلاحية الجلسة أو محاولة غير موثوقة، جرّب تربط الحساب تاني');
            exit;
        }

        $websiteId = (int) ($decodedState['website_id'] ?? 0);

        $oauth = $this->oauthClient(!empty($decodedState['allow_sitemap_submit']));
        $tokenResult = $oauth->exchangeCodeForTokens($code);

        if (!$tokenResult['success']) {
            $this->renderOAuthError('فشل تبادل التوكن مع Google: ' . ($tokenResult['error'] ?? ''));
            exit;
        }

        // بنخزن التوكنات مؤقتًا في الجلسة لحد ما العميل يختار الـ site بتاعه
        $_SESSION['gsc_oauth_temp'] = [
            'return_to'=>self::returnPath($decodedState['return_to']??'/websites'),
            'allow_sitemap_submit'=>!empty($decodedState['allow_sitemap_submit']),
            'granted_scope'=>(string)($tokenResult['scope']??''),
            'issued_at' => time(),
            'website_id' => $websiteId,
            'access_token' => $tokenResult['access_token'],
            'refresh_token' => $tokenResult['refresh_token'] ?? null,
            'expires_in' => $tokenResult['expires_in'],
        ];
        unset($_SESSION['gsc_oauth_nonce']);

        header('Location: /search-console/choose');
        exit;
    }

    // ============================================
    // GET /search-console/choose - يختار العميل site
    // ============================================
    public function showSitePicker(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $temp = $_SESSION['gsc_oauth_temp'] ?? null;
        if (!$temp) {
            header('Location: /websites');
            exit;
        }

        $api = new GoogleSearchConsoleAPI($temp['access_token']);
        $sitesResult = $api->listSites();

        if (!$sitesResult['success']) {
            Logger::warning('SearchConsoleController discovery failed', ['user_id' => (int) ($this->user['id'] ?? 0)]);
            $this->renderOAuthError('تعذر تحميل مواقع Search Console. أعد الربط أو حاول مرة أخرى لاحقًا.');
            exit;
        }

        $website=(new Website())->find((int)$temp['website_id']);if(!$website||(int)$website->getAttribute('user_id')!==(int)$this->userId()){$this->renderOAuthError('الموقع غير متاح لهذا الحساب');exit;}
        $websiteUrl=(string)$website->getAttribute('main_url');

        // العميل مايختارش حاجة لو موقعه واضح: خاصية واحدة بتطابقه (أو Domain اللي بتغطي الموقع كله) = ربط على طول
        $matches = self::matchingProperties($sitesResult['sites'], $websiteUrl);
        $auto = self::autoProperty($matches);
        if ($auto !== null) {
            $saved = $this->saveConnection($temp, $auto);
            if ($saved['ok']) {
                header('Location: ' . self::afterConnectPath($temp));
                exit;
            }
            $this->renderOAuthError($saved['error']);
            exit;
        }
        $matchUrls = array_column($matches, 'site_url');
        $sites = array_merge($matches, array_values(array_filter($sitesResult['sites'], fn ($s) => !in_array($s['site_url'] ?? '', $matchUrls, true))));
        foreach ($sites as &$s) {
            $s['match'] = in_array($s['site_url'] ?? '', $matchUrls, true);
        }
        unset($s);

        $returnJson=json_encode(self::returnPath($temp['return_to']??'/websites'));
        $csrfJson=json_encode(Csrf::token());
        $optionsJson = json_encode($sites, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $host = htmlspecialchars(self::hostOf($websiteUrl), ENT_QUOTES, 'UTF-8');
        $retry = '/search-console/connect/' . (int) $temp['website_id'];
        $head = $matches
            ? '<h3>ده موقعك على Search Console؟</h3><span class="p-card-sub">لقينا أكتر من خاصية لـ ' . $host . ' - الموصى بيها الأولى</span>'
            : '<h3>مالقيناش ' . $host . ' في حساب جوجل ده</h3><span class="p-card-sub">ضيفه في Search Console مرة واحدة، أو اربط بحساب جوجل تاني</span>';
        $help = $matches ? '' : '<div class="alert alert-info" style="line-height:1.9">'
            . '1) افتح <a href="https://search.google.com/search-console/welcome" target="_blank" rel="noopener">Search Console</a> واختار «Domain» واكتب <b dir="ltr">' . $host . '</b>.<br>'
            . '2) جوجل هيديك سطر TXT يتحط في إعدادات الدومين (لو مش بتعدّلها بنفسك ابعته للي عامل موقعك).<br>'
            . '3) بعد ما جوجل يأكد، ارجع هنا ودوس «اربط تاني».'
            . '<div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap"><a class="p-btn primary sm" href="' . $retry . '">🔄 اربط تاني</a><a class="p-btn outline sm" href="' . $retry . '">حساب جوجل تاني</a></div></div>'
            . ($sites ? '<p class="p-cell-muted">أو اختار من المواقع اللي في حسابك:</p>' : '');

        $body = '<div class="p-card"><div class="p-card-head">' . $head . '</div>' . $help . '<div id="siteOptions"></div></div>';

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    const sites = __OPTIONS_JSON__;
    const PERM = { siteOwner: 'مالك', siteFullUser: 'صلاحية كاملة', siteRestrictedUser: 'صلاحية محدودة', siteUnverifiedUser: 'مش متوثّق - مش هيجيب بيانات' };

    document.getElementById('siteOptions').innerHTML = sites.map((s, i) => `
        <div class="p-card" style="background:var(--panel-bg,#f7f8fa);margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
            <div>
                <strong style="direction:ltr;display:inline-block;">${esc(s.site_url)}</strong>${i === 0 && s.match ? ' <span class="pill green">موصى بيه</span>' : ''}<br>
                <span class="p-cell-muted">${esc(PERM[s.permission_level] || s.permission_level)}</span>
            </div>
            <button class="p-btn ${s.match ? 'primary' : 'outline'}" onclick="selectSite(${i})">اختيار</button>
        </div>`).join('');

    window.selectSite = async function (i) {
        const s = sites[i];
        const res = await fetchJSON('/api/search-console/finalize', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ site_url: s.site_url, csrf_token: __CSRF_JSON__ }),
        });
        if (res.success) {
            toast('تم ربط Google Search Console بنجاح ✔', 'success');
            window.location.href = res.data?.return_to || __RETURN_JSON__;
        } else {
            toast(res.error || 'تعذر إتمام الربط', 'error');
        }
    };
})();
JS;
        $script = str_replace(['__OPTIONS_JSON__','__RETURN_JSON__','__CSRF_JSON__'], [$optionsJson,$returnJson,$csrfJson], $script);

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('_websites', 'اختيار الموقع', 'Google Search Console', $body, $script);
        exit;
    }

    // ============================================
    // POST /api/search-console/finalize
    // ============================================
    public function finalize(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        if($error=$this->verifyCsrf())return $error;
        $temp = $_SESSION['gsc_oauth_temp'] ?? null;
        if (!$temp) {
            return $this->error('انتهت جلسة الربط، جرّب تاني', 422);
        }

        $siteUrl = $this->get('site_url');
        if (!$siteUrl) {
            return $this->error('اختيار الموقع مطلوب', 422);
        }

        if(!is_string($siteUrl)||strlen($siteUrl)>2000)return $this->error('اختيار الموقع غير صالح',422);
        try {
            $available=(new GoogleSearchConsoleAPI($temp['access_token']))->listSites();if(empty($available['success'])||!in_array($siteUrl,array_column($available['sites'],'site_url'),true))return $this->error('تعذر التحقق من صلاحية خاصية Search Console المختارة؛ أعد الربط بالحساب الصحيح',422);
        } catch (Exception $e) {
            Logger::error('Finalize Search Console Connection Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر حفظ الربط', 500);
        }
        $saved = $this->saveConnection($temp, $siteUrl);
        if (!$saved['ok']) {
            return $this->error($saved['error'], $saved['code']);
        }
        return $this->success(['return_to' => self::afterConnectPath($temp)], 'تم ربط Google Search Console بنجاح');
    }

    /**
     * بيحفظ ربط Search Console لخاصية اتأكدنا إنها في حساب جوجل بتاع العميل (من listSites).
     * @return array{ok:bool,error:string,code:int}
     */
    private function saveConnection(array $temp, string $siteUrl): array
    {
        try {
            if(time()-(int)($temp['issued_at']??0)>900)return ['ok'=>false,'error'=>'انتهت جلسة اختيار الموقع؛ أعد الربط','code'=>422];
            $website=(new Website())->find((int)$temp['website_id']);if(!$website||(int)$website->getAttribute('user_id')!==(int)$this->userId())return ['ok'=>false,'error'=>'الموقع غير متاح','code'=>403];
            $encryption = new Encryption();
            $existing = (new PlatformConnection())->where([
                'website_id' => $temp['website_id'],
                'platform' => 'google_search_console',
            ], [], 1);

            $oldRefresh=!empty($existing)?$existing[0]->getAttribute('refresh_token'):null;
            $data = [
                'website_id' => $temp['website_id'],
                'user_id' => $this->userId(),
                'platform' => 'google_search_console',
                'access_token' => $encryption->encrypt($temp['access_token']),
                'refresh_token' => $temp['refresh_token'] ? $encryption->encrypt($temp['refresh_token']) : null,
                'token_expires_at' => gmdate('Y-m-d H:i:s', (int) ($temp['issued_at'] ?? time()) + (int) $temp['expires_in']),
                'external_account_id' => null,
                'external_location_id' => $siteUrl,
                'external_location_name' => $siteUrl,
                'status' => 'connected',
                'last_error' => null,
            ];

            // نفس طريقة Google Analytics: نحدّث الربط الموجود بعد التأكد إنه بتاع نفس العميل
            if (!empty($existing)) {
                $connection = $existing[0];
                if ((int) $connection->getAttribute('user_id') !== (int) $this->userId()) {
                    return ['ok' => false, 'error' => 'غير مصرّح لك بتعديل هذا الاتصال', 'code' => 403];
                }
                foreach ($data as $key => $value) {
                    $connection->setAttribute($key, $value);
                }
            } else {
                $connection = new PlatformConnection($data);
            }
            if(empty($temp['refresh_token'])&&$oldRefresh)$connection->setAttribute('refresh_token',$oldRefresh);
            if(!$connection->save())throw new RuntimeException('Connection save failed');

            if($this->db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='seo_sitemap_permissions'")){
            // Missing write consent or a reused readonly refresh token never enables submissions.
            $allow=!empty($temp['allow_sitemap_submit'])&&SitemapSubmissionService::granted((string)($temp['granted_scope']??''))&&!empty($temp['refresh_token']);
            $this->db->exec('INSERT INTO seo_sitemap_permissions(website_id,user_id,connection_id,granted_scope,enabled,updated_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE connection_id=VALUES(connection_id),granted_scope=VALUES(granted_scope),enabled=VALUES(enabled),result_json=NULL,checked_at=NULL,updated_at=UTC_TIMESTAMP()',[(int)$temp['website_id'],(int)$this->userId(),(int)$connection->getAttribute('id'),(string)($temp['granted_scope']??''),$allow?1:0]);
            if($allow)try{(new SitemapSubmissionService($this->db))->run((int)$this->userId(),(int)$temp['website_id']);}catch(Throwable $e){Logger::warning('Initial sitemap submission pending',['website_id'=>$temp['website_id']]);}
            }
            unset($_SESSION['gsc_oauth_temp']);

            $this->log('Google Search Console Connected', ['website_id' => $temp['website_id'], 'site_url' => $siteUrl]);

            return ['ok' => true, 'error' => '', 'code' => 200];
        } catch (Exception $e) {
            Logger::error('Finalize Search Console Connection Error', ['message' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'تعذر حفظ الربط', 'code' => 500];
        }
    }

    // ============================================
    // POST /api/search-console/disconnect/{website_id}
    // ============================================
    public function disconnect(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $websiteId = (int) ($params['website_id'] ?? 0);
        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->userId()) {
            return $this->error('الموقع غير موجود', 404);
        }

        $connections = (new PlatformConnection())->where([
            'website_id' => $websiteId,
            'platform' => 'google_search_console',
        ], [], 1);

        if (empty($connections)) {
            return $this->error('مفيش اتصال Search Console لهذا الموقع', 404);
        }

        $connections[0]->setAttribute('status', 'disconnected');
        $connections[0]->setAttribute('access_token', null);
        $connections[0]->setAttribute('refresh_token', null);
        $connections[0]->save();

        if($this->db->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='seo_sitemap_permissions'"))$this->db->exec('UPDATE seo_sitemap_permissions SET enabled=0,updated_at=UTC_TIMESTAMP() WHERE website_id=? AND user_id=?',[$websiteId,(int)$this->userId()]);

        $this->log('Google Search Console Disconnected', ['website_id' => $websiteId]);

        return $this->success([], 'تم فصل Search Console');
    }

    // ============================================
    // GET /api/search-console/stats/{website_id} - ملخص آخر 28 يوم
    // ============================================
    public function stats(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $websiteId = (int) ($params['website_id'] ?? 0);
        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->userId()) {
            return $this->error('الموقع غير موجود', 404);
        }

        $connections = (new PlatformConnection())->where([
            'website_id' => $websiteId,
            'platform' => 'google_search_console',
            'status' => 'connected',
        ], [], 1);

        if (empty($connections)) {
            return $this->error('الموقع ده لسه مش متربط بـ Search Console', 404);
        }

        $connection = $connections[0];
        $encryption = new Encryption();

        try {
            $accessToken = $encryption->decrypt($connection->getAttribute('access_token'));

            // لو التوكن قرب ينتهي، نجدده الأول بالـ refresh_token المحفوظ
            if ((new PlatformConnection($connection->toArray()))->isTokenExpired() && $connection->getAttribute('refresh_token')) {
                $refreshToken = $encryption->decrypt($connection->getAttribute('refresh_token'));
                $refreshed = $this->oauthClient()->refreshAccessToken($refreshToken);

                if ($refreshed['success']) {
                    $accessToken = $refreshed['access_token'];
                    $connection->setAttribute('access_token', $encryption->encrypt($accessToken));
                    $connection->setAttribute('token_expires_at', date('Y-m-d H:i:s', time() + (int) $refreshed['expires_in']));
                    $connection->save();
                }
            }

            $api = new GoogleSearchConsoleAPI($accessToken);
            $siteUrl = (string) $connection->getAttribute('external_location_id');

            $summary = $api->getSummary($siteUrl);
            if (!$summary['success']) {
                $connection->setAttribute('status', 'error');
                $connection->setAttribute('last_error', $summary['error'] ?? 'Unknown error');
                $connection->save();
                return $this->error('تعذر جلب بيانات Search Console: ' . ($summary['error'] ?? ''), 502);
            }

            $topQueries = $api->getSearchAnalytics($siteUrl, $summary['summary']['start_date'], $summary['summary']['end_date'], ['query'], 10);

            $connection->setAttribute('last_synced_at', date('Y-m-d H:i:s'));
            $connection->save();

            return $this->success([
                'site_url' => $siteUrl,
                'summary' => $summary['summary'],
                'top_queries' => $topQueries['success'] ? $topQueries['rows'] : [],
            ]);
        } catch (Exception $e) {
            Logger::error('Search Console Stats Error', ['website_id' => $websiteId, 'message' => $e->getMessage()]);
            return $this->error('تعذر جلب بيانات Search Console', 500);
        }
    }
}

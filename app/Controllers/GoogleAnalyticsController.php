<?php
require_once __DIR__ . '/../Services/Security/OAuthState.php';

/**
 * Tourfecto - Google Analytics 4 (GA4) Controller
 * ربط حساب Google Analytics الخاص بكل عميل (OAuth) بكل موقع، وجلب بيانات
 * الزيارات (sessions/users/conversions) اللي بتكمّل قياس CTR من Search Console.
 * @version 1.0.0
 *
 * نفس فلسفة SearchConsoleController::connect/callback/choose، لكن هنا
 * "الفرع" اللي العميل بيختاره هو GA4 property (properties/123) مش site_url.
 *
 * المتطلبات قبل ما ده يشتغل فعليًا (خارج الكود، من Google Cloud Console):
 *  1) تفعيل "Google Analytics Data API" و"Google Analytics Admin API" في المشروع.
 *  2) إضافة GOOGLE_ANALYTICS_REDIRECT_URI كـ Authorized redirect URI
 *     في نفس OAuth Client، وإضافة قيمته في .env.
 *  3) GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET نفسهم المستخدمين في .env
 *     لبقية تكاملات Google (نفس المشروع، عميل OAuth واحد كفاية لأكتر من API).
 */
class GoogleAnalyticsController extends Controller
{
    private function userId(): ?int
    {
        return $this->user['id'] ?? null;
    }

    private function oauthClient(): GoogleOAuthClient
    {
        $redirectUri = defined('GOOGLE_ANALYTICS_REDIRECT_URI')
            ? GOOGLE_ANALYTICS_REDIRECT_URI
            : (env('GOOGLE_ANALYTICS_REDIRECT_URI') ?: '');

        if (!$redirectUri) {
            $redirectUri = rtrim((string) (defined('APP_URL') ? APP_URL : env('APP_URL')), '/') . '/google-analytics/callback';
        }
        return new GoogleOAuthClient(GoogleOAuthClient::SCOPE_ANALYTICS, $redirectUri);
    }

    private function renderOAuthError(string $message): void
    {
        $message = CustomerError::oauthMessage($message);
        $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $websiteId = (int) ($_SESSION['ga4_oauth_temp']['website_id'] ?? 0);
        $retry = $websiteId > 0 ? '<a class="p-btn primary" href="/google-analytics/connect/' . $websiteId . '">إعادة ربط الحساب</a><br><br>' : '';
        $body = '<div class="p-card"><div class="p-empty"><div class="p-empty-icon">⚠️</div>' . $safe
            . '<br><br>' . $retry . '<a href="/websites" class="p-btn primary">الرجوع لمواقعي</a></div></div>';
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('_websites', 'تعذر الربط', 'Google Analytics', $body, '');
    }

    // ============================================
    // GET /google-analytics/connect/{website_id} - يبدأ تدفّق OAuth
    // ============================================
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

        $oauth = $this->oauthClient();
        if (!$oauth->isConfigured()) {
            $this->renderOAuthError('ربط Google Analytics لسه مش مفعّل من إدارة النظام (بيانات OAuth ناقصة في إعدادات السيرفر - راجع GOOGLE_ANALYTICS_REDIRECT_URI في .env).');
            exit;
        }

        $nonce = bin2hex(random_bytes(16));
        $_SESSION['ga4_oauth_nonce'] = $nonce;

        $state = OAuthState::issue(['aud' => 'ga4', 'nonce' => $nonce, 'website_id' => $websiteId], (int) ($this->user['id'] ?? 0));

        header('Location: ' . $oauth->buildAuthUrl($state));
        exit;
    }

    // ============================================
    // GET /google-analytics/callback - Google بيرجّع العميل هنا
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

        $decodedState = OAuthState::verify((string) $state, (int) ($this->user['id'] ?? 0), $_SESSION['ga4_oauth_nonce'] ?? null, 'ga4');
        // تصحيح: التحقق بقى من state موقّع مربوط بالمستخدم - مش معتمد على جلسة PHP بس
        if (!$decodedState) {
            $this->renderOAuthError('انتهت صلاحية الجلسة أو محاولة غير موثوقة، جرّب تربط الحساب تاني');
            exit;
        }

        $websiteId = (int) ($decodedState['website_id'] ?? 0);

        $oauth = $this->oauthClient();
        $tokenResult = $oauth->exchangeCodeForTokens($code);

        if (!$tokenResult['success']) {
            $this->renderOAuthError('فشل تبادل التوكن مع Google: ' . ($tokenResult['error'] ?? ''));
            exit;
        }

        $_SESSION['ga4_oauth_temp'] = [
            'issued_at' => time(),
            'website_id' => $websiteId,
            'access_token' => $tokenResult['access_token'],
            'refresh_token' => $tokenResult['refresh_token'] ?? null,
            'expires_in' => $tokenResult['expires_in'],
        ];
        unset($_SESSION['ga4_oauth_nonce']);

        header('Location: /google-analytics/choose');
        exit;
    }

    // ============================================
    // GET /google-analytics/choose - يختار العميل property
    // ============================================
    public function showPropertyPicker(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $temp = $_SESSION['ga4_oauth_temp'] ?? null;
        if (!$temp) {
            header('Location: /websites');
            exit;
        }

        $api = new GoogleAnalyticsAPI($temp['access_token']);
        $result = $api->listAccounts();

        if (!$result['success']) {
            Logger::warning('GoogleAnalyticsController discovery failed', ['user_id' => (int) ($this->user['id'] ?? 0)]);
            $this->renderOAuthError('تعذر تحميل خصائص Google Analytics. أعد الربط أو حاول مرة أخرى لاحقًا.');
            exit;
        }
        if (empty($result['properties'])) {
            $this->renderOAuthError('لا توجد خصائص Google Analytics متاحة لهذا الحساب. تأكد من اختيار الحساب الصحيح وصلاحية الوصول.');
            exit;
        }

        $optionsJson = json_encode($result['properties'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $body = <<<'HTML'
        <div class="p-card">
            <div class="p-card-head"><h3>اختر خاصية Google Analytics 4</h3><span class="p-card-sub">لقينا أكتر من property مرتبطة بحسابك</span></div>
            <div id="propertyOptions"></div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    const properties = __OPTIONS_JSON__;

    document.getElementById('propertyOptions').innerHTML = properties.map((p, i) => `
        <div class="p-card" style="background:var(--panel-bg,#f7f8fa);margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;">
            <div>
                <strong>${esc(p.property_name)}</strong><br>
                <span class="p-cell-muted" style="direction:ltr;display:inline-block;">${esc(p.property_id)}</span>
            </div>
            <button class="p-btn primary" onclick="selectProperty(${i})">اختيار</button>
        </div>`).join('');

    window.selectProperty = async function (i) {
        const p = properties[i];
        const res = await fetchJSON('/api/google-analytics/finalize', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ property_id: p.property_id, property_name: p.property_name }),
        });
        if (res.success) {
            toast('تم ربط Google Analytics بنجاح ✔', 'success');
            window.location.href = '/auto-seo';
        } else {
            toast(res.error || 'تعذر إتمام الربط', 'error');
        }
    };
})();
JS;
        $script = str_replace('__OPTIONS_JSON__', $optionsJson, $script);

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('_websites', 'اختيار الخاصية', 'Google Analytics', $body, $script);
        exit;
    }

    // ============================================
    // POST /api/google-analytics/finalize
    // ============================================
    public function finalize(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }

        $temp = $_SESSION['ga4_oauth_temp'] ?? null;
        if (!$temp) {
            return $this->error('انتهت جلسة الربط، جرّب تاني', 422);
        }

        $propertyId = $this->get('property_id');
        $propertyName = $this->get('property_name', '');
        if (!$propertyId) {
            return $this->error('اختيار الخاصية مطلوب', 422);
        }

        try {
            $encryption = new Encryption();
            $existing = (new PlatformConnection())->where([
                'website_id' => $temp['website_id'],
                'platform' => 'google_analytics',
            ], [], 1);

            $data = [
                'website_id' => $temp['website_id'],
                'user_id' => $this->userId(),
                'platform' => 'google_analytics',
                'access_token' => $encryption->encrypt($temp['access_token']),
                'refresh_token' => $temp['refresh_token'] ? $encryption->encrypt($temp['refresh_token']) : null,
                'token_expires_at' => date('Y-m-d H:i:s', (int) ($temp['issued_at'] ?? time()) + (int) $temp['expires_in']),
                'external_account_id' => null,
                'external_location_id' => $propertyId,
                'external_location_name' => $propertyName ?: $propertyId,
                'status' => 'connected',
                'last_error' => null,
            ];

            if (!empty($existing)) {
                $connection = $existing[0];
                if ((int) $connection->getAttribute('user_id') !== (int) $this->userId()) {
                    return $this->error('غير مصرّح لك بتعديل هذا الاتصال', 403);
                }
                foreach ($data as $key => $value) {
                    $connection->setAttribute($key, $value);
                }
            } else {
                $connection = new PlatformConnection($data);
            }
            $connection->save();

            unset($_SESSION['ga4_oauth_temp']);

            $this->log('Google Analytics Connected', ['website_id' => $temp['website_id'], 'property_id' => $propertyId]);

            return $this->success([], 'تم ربط Google Analytics بنجاح');
        } catch (Exception $e) {
            Logger::error('Finalize Google Analytics Connection Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر حفظ الربط', 500);
        }
    }

    // ============================================
    // POST /api/google-analytics/disconnect/{website_id}
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
            'platform' => 'google_analytics',
        ], [], 1);

        if (empty($connections)) {
            return $this->error('مفيش اتصال Google Analytics لهذا الموقع', 404);
        }

        $connections[0]->setAttribute('status', 'disconnected');
        $connections[0]->setAttribute('access_token', null);
        $connections[0]->setAttribute('refresh_token', null);
        $connections[0]->save();

        $this->log('Google Analytics Disconnected', ['website_id' => $websiteId]);

        return $this->success([], 'تم فصل Google Analytics');
    }

    // ============================================
    // GET /api/google-analytics/stats/{website_id} - ملخص آخر 28 يوم
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
            'platform' => 'google_analytics',
            'status' => 'connected',
        ], [], 1);

        if (empty($connections)) {
            return $this->error('الموقع ده لسه مش متربط بـ Google Analytics', 404);
        }

        $connection = $connections[0];
        $encryption = new Encryption();

        try {
            $accessToken = $encryption->decrypt($connection->getAttribute('access_token'));

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

            $api = new GoogleAnalyticsAPI($accessToken);
            $propertyId = (string) $connection->getAttribute('external_location_id');

            $summary = $api->getSummary($propertyId, 28);
            if (!$summary['success']) {
                $connection->setAttribute('status', 'error');
                $connection->setAttribute('last_error', $summary['error'] ?? 'Unknown error');
                $connection->save();
                return $this->error('تعذر جلب بيانات Google Analytics: ' . ($summary['error'] ?? ''), 502);
            }

            $traffic = $api->getTrafficSources($propertyId, 28, 10);

            $connection->setAttribute('last_synced_at', date('Y-m-d H:i:s'));
            $connection->save();

            return $this->success([
                'property_id' => $propertyId,
                'summary' => $summary['summary'],
                'traffic_sources' => $traffic['success'] ? $traffic['rows'] : [],
            ]);
        } catch (Exception $e) {
            Logger::error('Google Analytics Stats Error', ['website_id' => $websiteId, 'message' => $e->getMessage()]);
            return $this->error('تعذر جلب بيانات Google Analytics', 500);
        }
    }
}

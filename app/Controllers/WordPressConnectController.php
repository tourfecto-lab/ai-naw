<?php

/**
 * Tourfecto - WordPress Connect Controller
 * ربط WordPress بأسهل طريقة + توثيق الملكية في نفس الخطوة:
 *
 *  GET  /api/websites/{id}/platform             يتعرّف على الموقع ويرجّع رابط "الربط بضغطة" (شاشة موافقة WordPress الرسمية)
 *  GET  /wordpress/callback                     WordPress بيرجّع العميل هنا بعد الموافقة (site_url, user_login, password)
 *  POST /api/websites/{id}/wordpress/connect    ربط يدوي (اسم مستخدم + Application Password)
 *  GET  /api/websites/{id}/wordpress/status     حالة الربط (?test=1 لاختبار حي)
 *  POST /api/websites/{id}/wordpress/disconnect فصل
 *
 * حساب أدمن (manage_options) = العميل بيتحكم في الموقع فعلاً، فالموقع بيتوثّق تلقائيًا.
 * حساب غير أدمن (محرر/كاتب) = بيتربط للنشر بس من غير توثيق ملكية.
 *
 * الباسورد بيتخزّن مشفّر في platform_connections (نفس تخزين نظام النشر القديم) وعمره ما بيتسجّل أو بيتبعت في رد.
 *
 * @version 1.0.0
 */
class WordPressConnectController extends Controller
{
    /** معرّف ثابت للتطبيق (UUID) عشان WordPress يعرض اسمه ويمنع التكرار */
    private const APP_ID = '7a1f6c3e-5b2d-4e8a-9c41-2f0d8b6a3e17';
    private const STATE_TTL = 900;

    // ------------------------------------------------------------------ state (حماية CSRF للـ callback)

    private function stateSecret(): string
    {
        $k = (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY) ? ENCRYPTION_KEY : 'tourfecto-fallback-wp-connect-secret';
        return hash('sha256', 'tourfecto-wp-connect|' . $k, true);
    }

    public function makeState(int $userId, int $websiteId, ?int $now = null): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode(['u' => $userId, 'w' => $websiteId, 'e' => ($now ?? time()) + self::STATE_TTL, 'n' => bin2hex(random_bytes(6))])), '+/', '-_'), '=');
        return $payload . '.' . substr(hash_hmac('sha256', $payload, $this->stateSecret()), 0, 32);
    }

    /** @return array{u:int,w:int}|null */
    public function readState(string $state, ?int $now = null): ?array
    {
        $parts = explode('.', $state, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        $expected = substr(hash_hmac('sha256', $parts[0], $this->stateSecret()), 0, 32);
        if (!hash_equals($expected, $parts[1])) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true);
        if (!is_array($data) || !isset($data['u'], $data['w'], $data['e']) || (int) $data['e'] < ($now ?? time())) {
            return null;
        }
        return ['u' => (int) $data['u'], 'w' => (int) $data['w']];
    }

    protected function connector(): WordPressConnector
    {
        return new WordPressConnector();
    }

    private function userId(): int
    {
        return (int) ($this->user['id'] ?? 0);
    }

    private function ownedWebsite(int $websiteId): ?Website
    {
        $site = (new Website())->find($websiteId);
        if (!$site || (int) $site->getAttribute('user_id') !== $this->userId()) {
            return null;
        }
        return $site;
    }

    private function appUrl(): string
    {
        return rtrim((string) (defined('APP_URL') ? APP_URL : (function_exists('env') ? env('APP_URL') : '')), '/');
    }

    // ------------------------------------------------------------------ GET /api/websites/{id}/platform

    public function platform(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $id = (int) ($params['id'] ?? 0);
        $site = $this->ownedWebsite($id);
        if (!$site) {
            return $this->error('Not found', 404);
        }
        $mainUrl = WordPressConnector::normalizeSiteUrl((string) $site->getAttribute('main_url'));
        $conn = $this->connectionRow($id);

        $detect = $this->connector()->detect($mainUrl);
        $authorizeUrl = null;
        $authEndpoint = self::authorizationEndpoint($mainUrl, $detect);
        if ($authEndpoint !== null && parse_url($this->appUrl(), PHP_URL_SCHEME) === 'https') {
            $state = $this->makeState($this->userId(), $id);
            $authorizeUrl = $authEndpoint
                . (strpos($authEndpoint, '?') === false ? '?' : '&')
                . http_build_query([
                    'app_name' => 'Tourfecto',
                    'app_id' => self::APP_ID,
                    'success_url' => $this->appUrl() . '/wordpress/callback?state=' . $state,
                    'reject_url' => $this->appUrl() . '/websites/' . $id . '?wp=rejected',
                ], '', '&', PHP_QUERY_RFC3986);
        }
        return $this->success([
            'url' => $mainUrl,
            'verified' => (int) $site->getAttribute('is_verified') === 1,
            'is_wordpress' => $detect['is_wordpress'],
            'https' => $detect['https'],
            'app_passwords' => $detect['app_passwords'],
            'authorize_url' => $authorizeUrl,
            'site_name' => $detect['site_name'],
            'detect_error' => $detect['error'],
            'connected' => $conn !== null && ($conn['status'] ?? '') === 'connected',
            'connection' => $conn ? ['site_url' => $conn['external_location_id'], 'account' => $conn['external_location_name'], 'status' => $conn['status']] : null,
        ]);
    }

    /** A missing public REST authentication advertisement need not disable browser consent. */
    public static function authorizationEndpoint(string $siteUrl, array $detect): ?string
    {
        $site=parse_url($siteUrl);
        if (!$site || ($site['scheme']??'')!=='https' || empty($site['host']) || isset($site['user']) || isset($site['pass'])) return null;
        $advertised=$detect['authorize_url']??null;
        if (is_string($advertised)) {
            $target=parse_url($advertised);
            if ($target && ($target['scheme']??'')==='https' && !isset($target['user']) && !isset($target['pass'])
                && WordPressConnector::hostsEqual((string)($target['host']??''),(string)$site['host'])
                && ($target['port']??443)===($site['port']??443)) return $advertised;
        }
        // WordPress itself checks support, the logged-in user's eligibility and consent.
        // Never enable passwords or grant permissions from this fallback.
        return !empty($detect['is_wordpress']) ? rtrim($siteUrl,'/').'/wp-admin/authorize-application.php' : null;
    }

    // ------------------------------------------------------------------ الربط (مشترك بين اليدوي والـ callback)

    /**
     * @return array{success:bool, reason:string, verified:bool, connected:bool, account:?string, is_admin:bool}
     */
    public function finishConnect(Website $site, string $siteUrl, string $username, string $appPassword): array
    {
        $siteUrl = WordPressConnector::normalizeSiteUrl($siteUrl);
        $res = ['success' => false, 'reason' => 'unknown', 'verified' => false, 'connected' => false, 'account' => null, 'is_admin' => false, 'http' => 0, 'wp_code' => ''];
        $c = $this->connector();

        $bad = $c->checkTarget($siteUrl, (string) $site->getAttribute('main_url'), true);
        if ($bad !== null) {
            $res['reason'] = $bad;
            return $res;
        }
        $test = $c->inspectUser($siteUrl, $username, $appPassword);
        if (!$test['success']) {
            $res['reason'] = $test['reason'];
            $res['http'] = (int) ($test['http'] ?? 0);
            $res['wp_code'] = (string) ($test['wp_code'] ?? '');
            // تشخيص بدون أي بيانات دخول: السبب + كود HTTP + كود خطأ WordPress
            Logger::warning('WordPress connect failed', ['website_id' => $site->getAttribute('id'), 'reason' => $res['reason'], 'http' => $res['http'], 'wp_code' => $res['wp_code']]);
            return $res;
        }
        $user = $test['user'];
        if (!$user['can_publish']) {
            $res['reason'] = 'forbidden';
            return $res;
        }

        try {
            $this->storeConnection((int) $site->getAttribute('id'), $siteUrl, $username, $appPassword, $user);
        } catch (Throwable $e) {
            Logger::error('WordPress connect: store failed', ['website_id' => $site->getAttribute('id'), 'message' => $e->getMessage()]);
            $res['reason'] = 'unknown';
            return $res;
        }

        $verified = (int) $site->getAttribute('is_verified') === 1;
        if ($user['is_admin'] && !$verified) {
            $site->setAttribute('is_verified', 1);
            $site->save();
            $verified = true;
            $this->log('Website Verified', ['website_id' => $site->getAttribute('id'), 'method' => 'wordpress_app_password']);
        }
        $this->log('WordPress Connected', ['website_id' => $site->getAttribute('id'), 'admin' => $user['is_admin']]);

        return ['success' => true, 'reason' => 'ok', 'verified' => $verified, 'connected' => true, 'account' => $user['name'], 'is_admin' => $user['is_admin']];
    }

    private function storeConnection(int $websiteId, string $siteUrl, string $username, string $appPassword, array $user): void
    {
        $encrypted = (new Encryption())->encrypt($username . ':' . $appPassword);
        $existing = $this->db->query(
            "SELECT id FROM platform_connections WHERE website_id = ? AND platform = 'wordpress' ORDER BY id DESC LIMIT 1",
            [$websiteId]
        );
        if (!empty($existing)) {
            $this->db->query(
                "UPDATE platform_connections SET user_id = ?, access_token = ?, refresh_token = NULL, token_expires_at = NULL,
                        external_account_id = ?, external_location_id = ?, external_location_name = ?, status = 'connected', last_error = NULL
                  WHERE id = ?",
                [$this->userId(), $encrypted, (string) $user['id'], $siteUrl, $user['name'], (int) $existing[0]['id']]
            );
            return;
        }
        $this->db->query(
            "INSERT INTO platform_connections (website_id, user_id, platform, access_token, external_account_id, external_location_id, external_location_name, status)
             VALUES (?, ?, 'wordpress', ?, ?, ?, ?, 'connected')",
            [$websiteId, $this->userId(), $encrypted, (string) $user['id'], $siteUrl, $user['name']]
        );
    }

    private function connectionRow(int $websiteId): ?array
    {
        try {
            $rows = $this->db->query(
                "SELECT id, status, external_location_id, external_location_name, last_error FROM platform_connections
                  WHERE website_id = ? AND platform = 'wordpress' ORDER BY id DESC LIMIT 1",
                [$websiteId]
            );
            return !empty($rows) ? $rows[0] : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    // ------------------------------------------------------------------ POST /api/websites/{id}/wordpress/connect

    public function connect(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $id = (int) ($params['id'] ?? 0);
        $site = $this->ownedWebsite($id);
        if (!$site) {
            return $this->error('Not found', 404);
        }
        $username = trim((string) $this->get('username', ''));
        $password = trim((string) $this->get('app_password', ''));
        if ($username === '' || $password === '') {
            return $this->error('missing_fields', 422, ['reason' => 'missing_fields']);
        }
        $siteUrl = (string) $site->getAttribute('main_url');

        $res = $this->finishConnect($site, $siteUrl, $username, $password);
        if (!$res['success']) {
            return $this->error($res['reason'], 422, ['reason' => $res['reason'], 'http' => $res['http'], 'wp_code' => $res['wp_code']]);
        }
        return $this->success(['verified' => $res['verified'], 'connected' => true, 'account' => $res['account'], 'is_admin' => $res['is_admin']]);
    }

    // ------------------------------------------------------------------ GET /wordpress/callback

    /** WordPress بيرجّع المتصفح هنا. مفيش JSON: كله redirects لصفحة الموقع. */
    public function callback(array $params = []): void
    {
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');

        if (!$this->isAuthenticated()) {
            $this->redirect('/login');
            return;
        }
        $state = $this->readState((string) $this->get('state', ''));
        $websiteId = $state['w'] ?? 0;
        if (!$state || $state['u'] !== $this->userId()) {
            $this->redirect('/websites?wp=error&reason=state_invalid');
            return;
        }
        $site = $this->ownedWebsite($websiteId);
        if (!$site) {
            $this->redirect('/websites?wp=error&reason=state_invalid');
            return;
        }
        $back = '/websites/' . $websiteId;

        $siteUrl = (string) $this->get('site_url', '');
        $username = (string) $this->get('user_login', '');
        $password = (string) $this->get('password', '');
        if ($username === '' || $password === '') {
            $this->redirect($back . '?wp=rejected');
            return;
        }

        $res = $this->finishConnect($site, $siteUrl, $username, $password);
        if (!$res['success']) {
            $this->redirect($back . '?wp=error&reason=' . rawurlencode($res['reason']) . '&http=' . (int) $res['http'] . '&code=' . rawurlencode($res['wp_code']));
            return;
        }
        $this->redirect($back . '?wp=' . ($res['verified'] ? 'ok' : 'ok_unverified'));
    }

    protected function redirect(string $to): void
    {
        if (!headers_sent()) {
            header('Location: ' . $to, true, 302);
        }
    }

    // ------------------------------------------------------------------ status / disconnect

    public function status(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $id = (int) ($params['id'] ?? 0);
        if (!$this->ownedWebsite($id)) {
            return $this->error('Not found', 404);
        }
        $conn = $this->connectionRow($id);
        if (!$conn) {
            return $this->success(['connected' => false]);
        }
        $out = ['connected' => ($conn['status'] ?? '') === 'connected', 'site_url' => $conn['external_location_id'], 'account' => $conn['external_location_name'], 'last_error' => $conn['last_error']];

        if ((string) $this->get('test', '') === '1' && $out['connected']) {
            try {
                $row = $this->db->query("SELECT access_token FROM platform_connections WHERE id = ? LIMIT 1", [(int) $conn['id']]);
                $plain = (new Encryption())->decrypt((string) ($row[0]['access_token'] ?? ''));
                [$u, $p] = array_pad(explode(':', $plain, 2), 2, '');
                $t = $this->connector()->inspectUser((string) $conn['external_location_id'], $u, $p);
                $out['test'] = ['ok' => $t['success'], 'reason' => $t['reason']];
                $this->db->query(
                    "UPDATE platform_connections SET status = ?, last_error = ? WHERE id = ?",
                    [$t['success'] ? 'connected' : 'error', $t['success'] ? null : $t['reason'], (int) $conn['id']]
                );
                $out['connected'] = $t['success'];
            } catch (Throwable $e) {
                $out['test'] = ['ok' => false, 'reason' => 'unknown'];
            }
        }
        return $this->success($out);
    }

    public function disconnect(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $id = (int) ($params['id'] ?? 0);
        if (!$this->ownedWebsite($id)) {
            return $this->error('Not found', 404);
        }
        $n = (int) $this->db->query(
            "DELETE FROM platform_connections WHERE website_id = ? AND user_id = ? AND platform = 'wordpress'",
            [$id, $this->userId()]
        );
        $this->log('WordPress Disconnected', ['website_id' => $id]);
        return $this->success(['disconnected' => $n > 0]);
    }
}

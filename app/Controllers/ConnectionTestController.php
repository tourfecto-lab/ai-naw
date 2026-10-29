<?php

/**
 * Tourfecto - «اختبار الربط» و«فصل الربط» لكل حساب مربوط في صفحة الربط والتكاملات
 *
 * الاختبار بيعمل نداء حقيقي خفيف للخدمة نفسها بالتوكن المحفوظ (وبيجدّده لو منتهي)،
 * مش مجرد قراءة حالة من قاعدة البيانات - عشان العميل يعرف فعلًا إن الربط شغّال.
 * النتيجة بتتسجّل (last_error / status) عشان باقي الصفحات تعرف لو الربط وقع.
 */
class ConnectionTestController extends Controller
{
    /** الخدمات اللي ليها اختبار ونداء التحقق بتاع كل واحدة */
    private const GOOGLE_PROBES = [
        'google_business' => ['https://mybusinessaccountmanagement.googleapis.com/v1/accounts?pageSize=1', 'Google Business Profile API'],
        'google_search_console' => ['https://www.googleapis.com/webmasters/v3/sites', 'Search Console API'],
        'google_analytics' => ['https://analyticsadmin.googleapis.com/v1beta/accountSummaries?pageSize=1', 'Google Analytics Admin API'],
        'google_ads' => ['https://oauth2.googleapis.com/tokeninfo', null],
        'youtube' => ['https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true', 'YouTube Data API v3'],
    ];

    /** GET /api/integrations/connections/{platform}/test[?website_id=] */
    public function test(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($e = $this->rateLimitGuard('user', 'connection_test', 30, 600)) {
            return $e;
        }
        $platform = (string) ($params['platform'] ?? '');
        $userId = $this->ownerId();

        if ($platform === 'website') {
            return $this->testWebsite($userId, (int) $this->get('website_id', 0));
        }

        $conn = $this->connection($userId, $platform, (int) $this->get('website_id', 0));
        if (!$conn) {
            return $this->error('الحساب ده مش مربوط - دوس «ربط الحساب» الأول', 404);
        }

        try {
            if (isset(self::GOOGLE_PROBES[$platform])) {
                $result = $this->testGoogle($conn, $platform);
            } elseif ($platform === 'meta_ads') {
                $result = $this->testMeta($conn);
            } elseif ($platform === 'tiktok') {
                $result = $this->testTikTok($conn);
            } else {
                return $this->error('مفيش اختبار للخدمة دي لسه', 422);
            }
        } catch (Throwable $e) {
            $result = ['ok' => false, 'message' => $this->friendly($e->getMessage()), 'auth' => (bool) preg_match('/refresh|invalid_grant/i', $e->getMessage())];
        }

        $this->record($conn, $result);
        return $result['ok']
            ? $this->success(['ok' => true, 'account' => $result['account'] ?? null, 'checked_at' => date('Y-m-d H:i:s')], $result['message'])
            : $this->error($result['message'], 422, ['ok' => false, 'reconnect' => true]);
    }

    /** POST /api/integrations/connections/{platform}/disconnect {website_id?} */
    public function disconnect(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        $platform = (string) ($params['platform'] ?? '');
        if (!in_array($platform, ['google_business', 'google_search_console', 'google_analytics', 'google_ads', 'meta_ads', 'tiktok', 'youtube'], true)) {
            return $this->error('الخدمة دي مش بتتفصل من هنا', 422);
        }
        $userId = $this->ownerId();
        $websiteId = (int) $this->get('website_id', 0);
        $sql = "UPDATE platform_connections SET status = 'disconnected', access_token = NULL, refresh_token = NULL, token_expires_at = NULL, last_error = NULL
                WHERE user_id = ? AND platform = ? AND status <> 'disconnected'";
        $args = [$userId, $platform];
        if ($websiteId > 0) {
            $sql .= ' AND website_id = ?';
            $args[] = $websiteId;
        }
        $this->db->query($sql, $args);
        if (class_exists('ActivityLog')) {
            ActivityLog::record('integrations', 'connection.disconnected', ['user_id' => (int) $this->user['id'], 'meta' => ['platform' => $platform, 'website_id' => $websiteId ?: null]]);
        }
        return $this->success(['platform' => $platform], 'اتفصل الربط. بياناتك اللي اتسحبت قبل كده لسه موجودة، وتقدر تربط تاني في أي وقت.');
    }

    // ------------------------------------------------------------------ الاختبارات

    private function testGoogle(array $conn, string $platform): array
    {
        $model = (new PlatformConnection())->find((int) $conn['id']);
        if (!$model) {
            return ['ok' => false, 'message' => 'الربط مش موجود'];
        }
        $token = (new GoogleReviewSyncService())->getValidAccessToken($model);
        [$url, $apiName] = self::GOOGLE_PROBES[$platform];
        if ($platform === 'google_ads') {
            $url .= '?access_token=' . rawurlencode($token);
            [$code, $body] = $this->http($url, []);
        } else {
            [$code, $body] = $this->http($url, ['Authorization: Bearer ' . $token]);
        }
        $json = json_decode($body, true) ?: [];
        if ($code >= 200 && $code < 300) {
            $account = $conn['external_location_name'] ?: match ($platform) {
                'google_business' => $json['accounts'][0]['accountName'] ?? null,
                'google_search_console' => isset($json['siteEntry']) ? count($json['siteEntry']) . ' site(s)' : null,
                'google_analytics' => $json['accountSummaries'][0]['displayName'] ?? null,
                'google_ads' => $json['email'] ?? null,
                'youtube' => $json['items'][0]['snippet']['title'] ?? null,
                default => null,
            };
            return ['ok' => true, 'account' => $account, 'message' => 'الربط شغّال' . ($account ? ' - ' . $account : '')];
        }
        $err = (string) ($json['error']['message'] ?? $json['error_description'] ?? ('HTTP ' . $code));
        if ($apiName && preg_match('/has not been used|is disabled|SERVICE_DISABLED|accessNotConfigured/i', $err)) {
            return ['ok' => false, 'message' => 'الخدمة دي لسه بتتفعّل من فريق المنصة (' . $apiName . '). بلّغنا وهنظبطها.', 'platform_side' => true];
        }
        if ($code === 401 || $code === 403 || preg_match('/invalid_grant|invalid_token|insufficient/i', $err)) {
            return ['ok' => false, 'auth' => true, 'message' => 'Google رفض الوصول للحساب - غالبًا الصلاحية اتشالت أو انتهت. دوس «إعادة الربط» ووافق على كل الصلاحيات.'];
        }
        return ['ok' => false, 'message' => 'Google مارجعش رد سليم دلوقتي. جرّب تاني بعد دقيقة.'];
    }

    private function testMeta(array $conn): array
    {
        $token = (new Encryption())->decrypt((string) $conn['access_token']);
        $account = (string) ($conn['external_account_id'] ?? '');
        $url = $account !== ''
            ? 'https://graph.facebook.com/v19.0/' . rawurlencode($account) . '?fields=name,account_status'
            : 'https://graph.facebook.com/v19.0/me?fields=name';
        [$code, $body] = $this->http($url, ['Authorization: Bearer ' . $token]);
        $json = json_decode($body, true) ?: [];
        if ($code >= 200 && $code < 300 && empty($json['error'])) {
            $name = $json['name'] ?? $account;
            $inactive = isset($json['account_status']) && (int) $json['account_status'] !== 1;
            return ['ok' => !$inactive, 'account' => $name, 'message' => $inactive
                ? 'الربط شغّال بس حساب الإعلانات «' . $name . '» موقوف عند Meta - راجعه من Meta Business Suite.'
                : 'الربط شغّال - ' . $name];
        }
        $codeMeta = (int) ($json['error']['code'] ?? 0);
        if ($codeMeta === 190 || $code === 401) {
            return ['ok' => false, 'auth' => true, 'message' => 'صلاحية الربط مع Meta انتهت أو اتشالت. دوس «إعادة الربط».'];
        }
        return ['ok' => false, 'message' => 'Meta مارجعتش رد سليم دلوقتي. جرّب تاني بعد دقيقة.'];
    }

    private function testTikTok(array $conn): array
    {
        $token = (new Encryption())->decrypt((string) $conn['access_token']);
        [$code, $body] = $this->http('https://open.tiktokapis.com/v2/user/info/?fields=display_name', ['Authorization: Bearer ' . $token]);
        $json = json_decode($body, true) ?: [];
        if ($code === 200 && ($json['error']['code'] ?? 'ok') === 'ok') {
            $name = $json['data']['user']['display_name'] ?? null;
            return ['ok' => true, 'account' => $name, 'message' => 'الربط شغّال' . ($name ? ' - ' . $name : '')];
        }
        return ['ok' => false, 'auth' => true, 'message' => 'TikTok رفض الوصول - غالبًا الصلاحية انتهت. دوس «إعادة الربط».'];
    }

    /** موقع العميل: بيفتح ولا لأ، وسرعته، وهل كود المنصة متركّب عليه */
    private function testWebsite(int $userId, int $websiteId): array
    {
        $args = [$userId];
        $sql = 'SELECT id, main_url, company_name FROM websites WHERE user_id = ?';
        if ($websiteId > 0) {
            $sql .= ' AND id = ?';
            $args[] = $websiteId;
        }
        $site = $this->db->query($sql . ' ORDER BY id LIMIT 1', $args)[0] ?? null;
        if (!$site) {
            return $this->error('مفيش موقع مضاف - ضيف رابط موقعك الأول', 404);
        }
        $url = (string) $site['main_url'];
        $start = microtime(true);
        $r = SafeHttp::exec($url, [CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => 'TourfectoBot/1.0 (+https://tourfecto.pro)', CURLOPT_ENCODING => '']);
        $ms = (int) round((microtime(true) - $start) * 1000);
        $code = (int) ($r['code'] ?? 0);
        if (!$r['response'] || $code === 0) {
            // من غير الرابط في الرسالة: فلتر الأخطاء بيعتبر أي https:// تفاصيل تقنية ويخفي الرسالة كلها
            return $this->error('مش قادرين نفتح موقعك - اتأكد إن الموقع شغّال وإن الرابط في صفحة «المواقع» مكتوب صح.', 422, ['ok' => false]);
        }
        if ($code >= 400) {
            return $this->error('موقعك بيرد بخطأ (' . $code . ') - افتحه بنفسك واتأكد إنه شغّال.', 422, ['ok' => false]);
        }
        $html = is_string($r['response']) ? (string) $r['response'] : '';
        $hasEmbed = $html !== '' && stripos($html, '/embed.js') !== false;
        $speed = $ms < 1500 ? 'سريع' : ($ms < 4000 ? 'متوسط' : 'بطيء');
        return $this->success([
            'ok' => true, 'status' => $code, 'ms' => $ms, 'embed_installed' => $hasEmbed, 'url' => $url,
        ], 'موقعك شغّال - بيفتح في ' . number_format($ms / 1000, 1) . ' ثانية (' . $speed . ')' . ($hasEmbed ? ' · كود المنصة متركّب' : ''));
    }

    // ------------------------------------------------------------------ أدوات

    /** أعضاء الفريق بيشتغلوا على حسابات صاحب الشركة */
    private function ownerId(): int
    {
        return !empty($this->user['owner_user_id']) ? (int) $this->user['owner_user_id'] : (int) $this->user['id'];
    }

    private function connection(int $userId, string $platform, int $websiteId): ?array
    {
        $sql = "SELECT * FROM platform_connections WHERE user_id = ? AND platform = ? AND status IN ('connected', 'error', 'token_expired')";
        $args = [$userId, $platform];
        if ($websiteId > 0) {
            $sql .= ' AND website_id = ?';
            $args[] = $websiteId;
        }
        return $this->db->query($sql . ' ORDER BY id DESC LIMIT 1', $args)[0] ?? null;
    }

    /** @return array{0:int,1:string} */
    private function http(string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => $headers]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, is_string($body) ? $body : ''];
    }

    private function record(array $conn, array $result): void
    {
        try {
            if ($result['ok']) {
                $this->db->query("UPDATE platform_connections SET status = 'connected', last_error = NULL WHERE id = ?", [(int) $conn['id']]);
            } elseif (!empty($result['auth'])) {
                // الخدمة رفضت الصلاحية فعلًا - نعلّمها عشان الصفحات التانية تعرض «إعادة الربط».
                // أي فشل مؤقت (شبكة، الخدمة مارديتش) مابيعلّمش الربط إنه واقف
                $this->db->query("UPDATE platform_connections SET status = 'error', last_error = ? WHERE id = ?", [mb_substr($result['message'], 0, 500), (int) $conn['id']]);
            }
        } catch (Throwable $e) {
        error_log('[ConnectionTestController] ignored error: ' . $e->getMessage());
        }
    }

    private function friendly(string $message): string
    {
        if (stripos($message, 'refresh') !== false || stripos($message, 'invalid_grant') !== false) {
            return 'صلاحية الربط انتهت ومش قادرين نجددها لوحدنا - دوس «إعادة الربط».';
        }
        return 'تعذّر الاختبار دلوقتي - جرّب تاني بعد دقيقة.';
    }
}

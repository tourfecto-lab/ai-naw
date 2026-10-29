<?php

/**
 * TikTok Ads: connect an advertiser account (TikTok for Business portal auth), show its status, and publish a PAUSED video
 * campaign from a saved ad campaign. Off until TIKTOK_ADS_APP_ID / TIKTOK_ADS_APP_SECRET exist in the environment (a separate
 * TikTok for Business app, not the content-posting one). Independent of the Meta / Google ads flows.
 */
class TikTokAdsController extends Controller
{
    private static function appId(): string
    {
        return trim((string) (function_exists('env') ? env('TIKTOK_ADS_APP_ID', '') : getenv('TIKTOK_ADS_APP_ID')));
    }

    private static function secret(): string
    {
        return trim((string) (function_exists('env') ? env('TIKTOK_ADS_APP_SECRET', '') : getenv('TIKTOK_ADS_APP_SECRET')));
    }

    private static function redirectUri(): string
    {
        $v = trim((string) (function_exists('env') ? env('TIKTOK_ADS_REDIRECT_URI', '') : getenv('TIKTOK_ADS_REDIRECT_URI')));
        return $v !== '' ? $v : rtrim(defined('APP_URL') ? (string) APP_URL : '', '/') . '/ads/connect/tiktok/callback';
    }

    private static function configured(): bool
    {
        return self::appId() !== '' && self::secret() !== '';
    }

    private function page(string $title, string $html): void
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ads', $title, 'TikTok Ads', '<div class="p-card"><div class="p-empty">' . $html . '<br><br><a href="/ads" class="p-btn primary">Back to Ads</a></div></div>', '');
    }

    /** GET /ads/connect/tiktok */
    public function connect(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/ads'));
            exit;
        }
        if (!self::configured()) {
            $this->page('TikTok Ads', 'TikTok Ads is not enabled yet: the platform administrator has to add the TikTok for Business app keys first.');
            exit;
        }
        $nonce = bin2hex(random_bytes(16));
        $_SESSION['tiktok_ads_nonce'] = $nonce;
        $state = OAuthState::issue(['aud' => 'tiktok_ads', 'nonce' => $nonce], (int) $this->user['id']);
        header('Location: ' . TikTokAdsAPI::authUrl(self::appId(), self::redirectUri(), $state));
        exit;
    }

    /** GET /ads/connect/tiktok/callback */
    public function callback(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }
        $code = (string) ($this->get('auth_code') ?: $this->get('code'));
        $state = (string) $this->get('state');
        if ($code === '' || $state === '') {
            $this->page('Could not connect', 'TikTok returned an incomplete answer.');
            exit;
        }
        if (!OAuthState::verify($state, (int) $this->user['id'], $_SESSION['tiktok_ads_nonce'] ?? null, 'tiktok_ads')) {
            $this->page('Could not connect', 'The session expired or the request could not be trusted. Start the connection again.');
            exit;
        }
        unset($_SESSION['tiktok_ads_nonce']);
        $ex = TikTokAdsAPI::exchangeAuthCode(self::appId(), self::secret(), $code);
        if (empty($ex['success'])) {
            Logger::warning('TikTok ads token exchange failed', ['error' => mb_substr((string) ($ex['error'] ?? ''), 0, 200)]);
            $this->page('Could not connect', 'TikTok did not accept the authorisation. Start the connection again.');
            exit;
        }
        $advertisers = (new TikTokAdsAPI($ex['access_token']))->advertisers($ex['advertiser_ids'] ?? []);
        if (!$advertisers) {
            $this->page('No advertiser account', 'This TikTok login has no ad account. Create one in TikTok Ads Manager, then connect again.');
            exit;
        }
        $_SESSION['tiktok_ads_temp'] = ['token' => $ex['access_token'], 'advertisers' => $advertisers];
        if (count($advertisers) === 1) {
            $this->saveConnection($advertisers[0]);
            unset($_SESSION['tiktok_ads_temp']);
            header('Location: /ads?tiktok=connected');
            exit;
        }
        $buttons = '';
        foreach ($advertisers as $a) {
            $buttons .= '<button class="p-btn outline" style="display:block;width:100%;margin-bottom:8px" onclick="pick(\'' . htmlspecialchars($a['id'], ENT_QUOTES, 'UTF-8') . '\')">'
                . htmlspecialchars($a['name'] ?: $a['id'], ENT_QUOTES, 'UTF-8') . ' <span class="p-cell-muted">(' . htmlspecialchars($a['currency'], ENT_QUOTES, 'UTF-8') . ')</span></button>';
        }
        $script = "window.pick=async function(id){const r=await window.Panel.fetchJSON('/api/ads/tiktok/choose-account',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({advertiser_id:id})});if(r.success){location.href='/ads?tiktok=connected';}else{window.Panel.toast(r.error||'Could not connect','error');}};";
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ads', 'Choose a TikTok ad account', 'TikTok Ads', '<div class="p-card"><div class="p-card-head"><h3>Choose the ad account</h3></div>' . $buttons . '</div>', $script);
        exit;
    }

    /** POST /api/ads/tiktok/choose-account */
    public function chooseAccount(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        $temp = $_SESSION['tiktok_ads_temp'] ?? null;
        if (!$temp) {
            return $this->error('The session expired, connect again.', 400);
        }
        foreach ($temp['advertisers'] as $a) {
            if ($a['id'] === (string) $this->get('advertiser_id')) {
                $this->saveConnection($a);
                unset($_SESSION['tiktok_ads_temp']);
                return $this->success([], 'TikTok ad account connected');
            }
        }
        return $this->error('Unknown ad account', 422);
    }

    private function saveConnection(array $advertiser): void
    {
        $temp = $_SESSION['tiktok_ads_temp'];
        $uid = (int) $this->user['id'];
        $site = $this->db->query('SELECT id FROM websites WHERE user_id = ? ORDER BY id ASC LIMIT 1', [$uid]);
        $token = (new Encryption())->encrypt((string) $temp['token']);
        $expires = date('Y-m-d H:i:s', time() + 10 * 365 * 86400); // TikTok business tokens do not expire
        $existing = $this->db->query("SELECT id FROM platform_connections WHERE user_id = ? AND platform = 'tiktok_ads' LIMIT 1", [$uid]);
        if ($existing) {
            $this->db->exec("UPDATE platform_connections SET access_token = ?, token_expires_at = ?, external_account_id = ?, status = 'connected', last_error = NULL, connected_at = NOW() WHERE id = ?", [$token, $expires, $advertiser['id'], $existing[0]['id']]);
        } else {
            $this->db->exec("INSERT INTO platform_connections (website_id, user_id, platform, access_token, token_expires_at, external_account_id, status) VALUES (?, ?, 'tiktok_ads', ?, ?, ?, 'connected')", [(int) ($site[0]['id'] ?? 0), $uid, $token, $expires, $advertiser['id']]);
        }
    }

    /** GET /api/ads/tiktok/status */
    public function status(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!self::configured()) {
            return $this->success(['configured' => false, 'connected' => false]);
        }
        $row = $this->db->query("SELECT external_account_id FROM platform_connections WHERE user_id = ? AND platform = 'tiktok_ads' AND status = 'connected' LIMIT 1", [(int) $this->user['id']]);
        return $this->success(['configured' => true, 'connected' => !empty($row), 'external_account_id' => $row[0]['external_account_id'] ?? null]);
    }

    /** POST /api/ads/tiktok/disconnect */
    public function disconnect(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        $this->db->exec("UPDATE platform_connections SET status = 'disconnected', access_token = NULL WHERE user_id = ? AND platform = 'tiktok_ads'", [(int) $this->user['id']]);
        return $this->success([], 'TikTok Ads disconnected');
    }

    /** POST /api/ads/campaigns/{id}/publish-tiktok - one PAUSED video campaign, nothing spends until it is switched on in TikTok. */
    public function publish(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        $uid = (int) $this->user['id'];
        $campaign = (new AdCampaign())->find((int) ($params['id'] ?? 0));
        if (!$campaign || $campaign->getAttribute('deleted_at') || (int) $campaign->getAttribute('user_id') !== $uid) {
            return $this->error('Campaign not found', 404);
        }
        if (trim((string) $campaign->getAttribute('external_campaign_id')) !== '') {
            return $this->error('This campaign is already published. Duplicate it to publish again.', 409);
        }
        $conn = $this->db->query("SELECT * FROM platform_connections WHERE user_id = ? AND platform = 'tiktok_ads' AND status = 'connected' LIMIT 1", [$uid])[0] ?? null;
        if (!$conn || empty($conn['access_token'])) {
            return $this->error('Connect your TikTok ad account first.', 422);
        }
        $copy = (new AdCopy())->where(['campaign_id' => (int) $campaign->getAttribute('id'), 'status' => 'approved']);
        $quick = $this->get('copy');
        $copyData = $copy ? $copy[0]->toArray() : (is_array($quick) ? $quick : []);
        if (trim((string) ($copyData['primary_text'] ?? $copyData['headline'] ?? '')) === '') {
            return $this->error('An approved ad text is required.', 422);
        }
        $dest = trim((string) $this->get('destination_url', '')) ?: trim((string) $campaign->getAttribute('landing_page_url'));
        if ($dest !== '' && !preg_match('#^https?://#i', $dest)) {
            $dest = 'https://' . $dest;
        }
        if (!filter_var($dest, FILTER_VALIDATE_URL)) {
            return $this->error('Enter a valid destination link.', 422);
        }
        $countriesIn = $this->get('countries');
        $countries = AdGeo::toIso2(is_array($countriesIn) ? $countriesIn : (array) preg_split('/[,،]/u', (string) $countriesIn));
        $budget = (float) $this->get('daily_budget', $campaign->getAttribute('daily_budget'));
        if ($budget <= 0) {
            return $this->error('Enter a daily budget.', 422);
        }

        $videoUrl = trim((string) $this->get('video_url', ''));
        $itemId = (int) $this->get('video_media_item_id', 0);
        if ($itemId > 0) {
            try {
                $item = (new MediaItem())->find($itemId);
                if ($item && (int) $item->getAttribute('user_id') === $uid && (string) $item->getAttribute('type') === 'short_video') {
                    $videoUrl = PrivateMedia::publishUrl($item, 3600);
                }
            } catch (Throwable $e) {
                $videoUrl = '';
            }
        }

        try {
            $api = new TikTokAdsAPI((new Encryption())->decrypt((string) $conn['access_token']));
            $payload = ['name' => (string) $campaign->getAttribute('name'), 'daily_budget' => $budget, 'objective' => (string) $campaign->getAttribute('objective')];
            $result = $api->createVideoCampaign((string) $conn['external_account_id'], $payload, $copyData, $dest, [
                'countries' => $countries, 'video_url' => $videoUrl, 'display_name' => (string) ($this->ownerBusiness($uid)['business_name'] ?? $campaign->getAttribute('name')),
            ]);
        } catch (Throwable $e) {
            Logger::error('tiktok.publish failed', ['campaign_id' => (int) $campaign->getAttribute('id'), 'message' => mb_substr($e->getMessage(), 0, 200)]);
            return $this->error('Could not publish to TikTok.', 500);
        }
        if (empty($result['success'])) {
            return $this->error('TikTok ' . ($result['step'] ?? 'publish') . ': ' . mb_substr((string) ($result['error'] ?? ''), 0, 500), !empty($result['uncertain']) ? 409 : 422);
        }

        $fill = [
            'external_campaign_id' => $result['external_campaign_id'], 'external_adset_id' => $result['external_adgroup_id'] ?? null,
            'platform_connection_id' => $conn['id'], 'daily_budget' => $budget, 'target_countries_json' => json_encode($countries),
            'status' => 'paused', 'published_at' => date('Y-m-d H:i:s'),
        ];
        if (self::platformEnumAllowsTikTok($this->db)) {
            $fill['platform'] = 'tiktok_ads';
        }
        $campaign->fill($fill);
        $campaign->save();
        ActivityLog::record('ads', 'ad_campaign.published', ['subject_type' => 'ad_campaigns', 'subject_id' => (int) $campaign->getAttribute('id'), 'meta' => ['platform' => 'tiktok_ads', 'external_campaign_id' => $result['external_campaign_id'], 'countries' => $countries]]);
        return $this->success(['campaign' => $campaign->toArray(), 'warning' => $result['warning'] ?? null, 'summary' => ['countries' => $countries, 'daily_budget' => (string) $budget, 'ads' => 1]], 'The campaign was created on TikTok, paused. Review it in TikTok Ads Manager and switch it on when ready.');
    }

    /** ad_campaigns.platform is an ENUM; it only accepts 'tiktok_ads' after migration 2026_10_30_000001 has been applied. */
    private static function platformEnumAllowsTikTok($db): bool
    {
        try {
            $r = $db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_campaigns' AND COLUMN_NAME = 'platform' LIMIT 1");
            return stripos((string) ($r[0]['COLUMN_TYPE'] ?? ''), 'tiktok_ads') !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function ownerBusiness(int $ownerId): array
    {
        try {
            return $this->db->query('SELECT * FROM businesses WHERE owner_user_id = ? ORDER BY id ASC LIMIT 1', [$ownerId])[0] ?? [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

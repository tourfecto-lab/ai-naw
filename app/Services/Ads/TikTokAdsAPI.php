<?php

/**
 * TikTok Marketing API (business-api.tiktok.com, v1.3) - video ads, always created PAUSED (operation_status DISABLE).
 *
 * Flow for one ad: video upload -> cover image -> custom identity -> campaign -> ad group -> ad. A failure after the
 * campaign exists deletes it again, so no half-built campaign is left in the advertiser account.
 * Written from the public API reference; it has not been run against a live TikTok advertiser account yet.
 */
final class TikTokAdsAPI
{
    private const BASE = 'https://business-api.tiktok.com/open_api/v1.3';
    private const PORTAL = 'https://business-api.tiktok.com/portal/auth';

    /** Test seam: callable(string $method, string $path, array $data): array (the decoded JSON body). */
    public static $testTransport = null;

    private string $accessToken;

    public function __construct(string $accessToken)
    {
        $this->accessToken = $accessToken;
    }

    /** Our objectives -> [TikTok objective_type, billing_event, optimization_goal, note] */
    public static function objectivePlan(string $objective): array
    {
        switch ($objective) {
            case 'awareness':
                return ['REACH', 'CPM', 'REACH', null];
            case 'engagement':
                return ['VIDEO_VIEWS', 'CPV', 'VIDEO_VIEW', null];
            case 'sales':
            case 'leads':
                return ['TRAFFIC', 'CPC', 'CLICK', 'Conversion optimisation needs a TikTok Pixel, which is not connected yet, so the campaign optimises for landing page clicks.'];
            default:
                return ['TRAFFIC', 'CPC', 'CLICK', null];
        }
    }

    public static function authUrl(string $appId, string $redirectUri, string $state): string
    {
        return self::PORTAL . '?' . http_build_query(['app_id' => $appId, 'state' => $state, 'redirect_uri' => $redirectUri]);
    }

    /** @return array{success: bool, access_token?: string, advertiser_ids?: string[], error?: string} */
    public static function exchangeAuthCode(string $appId, string $secret, string $authCode): array
    {
        $r = (new self(''))->call('POST', '/oauth2/access_token/', ['app_id' => $appId, 'secret' => $secret, 'auth_code' => $authCode], false);
        if (!$r['success']) {
            return ['success' => false, 'error' => $r['error']];
        }
        $token = (string) ($r['data']['access_token'] ?? '');
        if ($token === '') {
            return ['success' => false, 'error' => 'TikTok returned no access token'];
        }
        return ['success' => true, 'access_token' => $token, 'advertiser_ids' => array_map('strval', (array) ($r['data']['advertiser_ids'] ?? []))];
    }

    /** @return array<int, array{id: string, name: string, currency: string, timezone: string}> */
    public function advertisers(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $r = $this->call('GET', '/advertiser/info/', ['advertiser_ids' => json_encode(array_values($ids)), 'fields' => json_encode(['advertiser_id', 'name', 'currency', 'timezone'])]);
        $out = [];
        foreach ((array) ($r['data']['list'] ?? []) as $a) {
            $out[] = ['id' => (string) ($a['advertiser_id'] ?? ''), 'name' => (string) ($a['name'] ?? ''), 'currency' => strtoupper((string) ($a['currency'] ?? '')), 'timezone' => (string) ($a['timezone'] ?? '')];
        }
        return $out;
    }

    /** ISO country code -> TikTok location id (country level). */
    public function locationIds(string $advertiserId, array $isoCodes, string $objectiveType): array
    {
        $r = $this->call('GET', '/tools/region/', ['advertiser_id' => $advertiserId, 'placements' => json_encode(['PLACEMENT_TIKTOK']), 'objective_type' => $objectiveType, 'level_range' => 'COUNTRY']);
        $want = array_map('strtoupper', $isoCodes);
        $ids = [];
        foreach ((array) ($r['data']['region_info'] ?? []) as $reg) {
            if (in_array(strtoupper((string) ($reg['region_code'] ?? '')), $want, true) && !empty($reg['location_id'])) {
                $ids[strtoupper((string) $reg['region_code'])] = (string) $reg['location_id'];
            }
        }
        return $ids;
    }

    public function setCampaignStatus(string $advertiserId, string $campaignId, string $status): array
    {
        $op = ['ENABLE' => 'ENABLE', 'DELETE' => 'DELETE'][strtoupper($status)] ?? 'DISABLE';
        return $this->call('POST', '/campaign/status/update/', ['advertiser_id' => $advertiserId, 'campaign_ids' => [$campaignId], 'operation_status' => $op]);
    }

    public function pauseCampaign(string $advertiserId, string $campaignId): array
    {
        return $this->setCampaignStatus($advertiserId, $campaignId, 'DISABLE');
    }

    public function resumeCampaign(string $advertiserId, string $campaignId): array
    {
        return $this->setCampaignStatus($advertiserId, $campaignId, 'ENABLE');
    }

    public function deleteCampaign(string $advertiserId, string $campaignId): array
    {
        return $this->setCampaignStatus($advertiserId, $campaignId, 'DELETE');
    }

    /** TikTok budgets live on the ad group; the daily budget is in the advertiser's currency. */
    public function updateAdGroupBudget(string $advertiserId, string $adGroupId, float $dailyBudget): array
    {
        if ($adGroupId === '' || $dailyBudget <= 0) {
            return ['success' => false, 'error' => 'A single ad group and a positive budget are required.'];
        }
        return $this->call('POST', '/adgroup/update/', ['advertiser_id' => $advertiserId, 'adgroup_id' => $adGroupId, 'budget_mode' => 'BUDGET_MODE_DAY', 'budget' => $dailyBudget]);
    }

    /** @return array{success: bool, rows?: array, error?: string} spend / clicks / impressions per campaign */
    public function campaignReport(string $advertiserId, string $startDate, string $endDate): array
    {
        $r = $this->call('GET', '/report/integrated/get/', [
            'advertiser_id' => $advertiserId, 'report_type' => 'BASIC', 'data_level' => 'AUCTION_CAMPAIGN',
            'dimensions' => json_encode(['campaign_id']), 'metrics' => json_encode(['campaign_name', 'spend', 'impressions', 'clicks', 'ctr', 'cpc', 'cpm']),
            'start_date' => $startDate, 'end_date' => $endDate, 'page_size' => 200,
        ]);
        return $r['success'] ? ['success' => true, 'rows' => (array) ($r['data']['list'] ?? [])] : ['success' => false, 'error' => $r['error']];
    }

    /**
     * Campaigns of an advertiser with their last 30 days of results, in the shape AdsSyncService expects.
     * spend / clicks / impressions are 30-day totals (TikTok reports are per day); budgets come from the ad groups.
     * @return array{success: bool, campaigns?: array, error?: string}
     */
    public function listCampaignsWithInsights(string $advertiserId): array
    {
        $camps = $this->call('GET', '/campaign/get/', ['advertiser_id' => $advertiserId, 'fields' => json_encode(['campaign_id', 'campaign_name', 'objective_type', 'operation_status', 'create_time']), 'page_size' => 200]);
        if (!$camps['success']) {
            return ['success' => false, 'error' => $camps['error']];
        }
        $currency = ($this->advertisers([$advertiserId])[0]['currency'] ?? '') ?: null;

        $budgets = [];
        $groupIds = [];
        $groups = $this->call('GET', '/adgroup/get/', ['advertiser_id' => $advertiserId, 'fields' => json_encode(['adgroup_id', 'campaign_id', 'budget', 'budget_mode']), 'page_size' => 1000]);
        foreach ((array) ($groups['data']['list'] ?? []) as $g) {
            $groupIds[(string) ($g['campaign_id'] ?? '')][] = (string) ($g['adgroup_id'] ?? '');
            if (($g['budget_mode'] ?? '') === 'BUDGET_MODE_DAY') {
                $budgets[(string) ($g['campaign_id'] ?? '')] = ($budgets[(string) ($g['campaign_id'] ?? '')] ?? 0.0) + (float) ($g['budget'] ?? 0);
            }
        }

        $daily = [];
        $report = $this->call('GET', '/report/integrated/get/', [
            'advertiser_id' => $advertiserId, 'report_type' => 'BASIC', 'data_level' => 'AUCTION_CAMPAIGN',
            'dimensions' => json_encode(['campaign_id', 'stat_time_day']), 'metrics' => json_encode(['spend', 'impressions', 'clicks', 'conversion']),
            'start_date' => gmdate('Y-m-d', time() - 29 * 86400), 'end_date' => gmdate('Y-m-d'), 'page_size' => 1000,
        ]);
        foreach ((array) ($report['data']['list'] ?? []) as $row) {
            $id = (string) ($row['dimensions']['campaign_id'] ?? '');
            $day = substr((string) ($row['dimensions']['stat_time_day'] ?? ''), 0, 10);
            if ($id !== '' && $day !== '') {
                $m = (array) ($row['metrics'] ?? []);
                $daily[$id][] = ['date' => $day, 'spend' => (float) ($m['spend'] ?? 0), 'impressions' => (int) ($m['impressions'] ?? 0), 'clicks' => (int) ($m['clicks'] ?? 0), 'conversions' => (float) ($m['conversion'] ?? 0)];
            }
        }

        $objectives = ['TRAFFIC' => 'traffic', 'REACH' => 'awareness', 'VIDEO_VIEWS' => 'engagement', 'WEB_CONVERSIONS' => 'sales', 'LEAD_GENERATION' => 'leads'];
        $status = ['ENABLE' => 'active', 'DISABLE' => 'paused', 'DELETE' => 'removed'];
        $out = [];
        foreach ((array) ($camps['data']['list'] ?? []) as $c) {
            $id = (string) ($c['campaign_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $days = $daily[$id] ?? [];
            $out[] = [
                'external_campaign_id' => $id,
                'name' => (string) ($c['campaign_name'] ?? $id),
                'objective' => $objectives[(string) ($c['objective_type'] ?? '')] ?? 'traffic',
                'daily_budget' => $budgets[$id] ?? 0.0,
                // Only a campaign with exactly one ad group has an unambiguous budget to change automatically.
                'external_adset_id' => count($groupIds[$id] ?? []) === 1 ? $groupIds[$id][0] : null,
                'status' => $status[(string) ($c['operation_status'] ?? '')] ?? 'paused',
                'impressions' => array_sum(array_column($days, 'impressions')),
                'clicks' => array_sum(array_column($days, 'clicks')),
                'spend' => round(array_sum(array_column($days, 'spend')), 2),
                'started_at' => (string) ($c['create_time'] ?? '') ?: null,
                'ended_at' => null,
                'currency' => $currency,
                'daily' => $days,
                'insights_available' => !empty($report['success']),
            ];
        }
        return ['success' => true, 'campaigns' => $out];
    }

    public static function validVideoUrl(string $url): bool
    {
        return (bool) preg_match('#^https://[^\s/]+/[^\s]+$#i', $url);
    }

    /**
     * One paused video ad. $opts: objective, countries[], video_url, display_name, cta, pixel-less.
     * @return array{success: bool, step?: string, error?: string, external_campaign_id?: string, external_adgroup_id?: string, ad_id?: string, warning?: ?string}
     */
    public function createVideoCampaign(string $advertiserId, array $campaign, array $copy, string $destinationUrl, array $opts): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) ($copy['primary_text'] ?? $copy['headline'] ?? '')));
        $text = mb_strlen($text) > 100 ? (string) (GoogleAdsAPI::fitText($text, 100) ?? mb_substr($text, 0, 100)) : $text;
        if ($text === '') {
            return ['success' => false, 'step' => 'preflight', 'error' => 'An approved ad text is required.'];
        }
        if (!self::validVideoUrl((string) ($opts['video_url'] ?? ''))) {
            return ['success' => false, 'step' => 'preflight', 'error' => 'TikTok ads are video ads: pick a video from your library or paste a public https video link.'];
        }
        $countries = array_values(array_filter(array_map('strtoupper', (array) ($opts['countries'] ?? []))));
        if (!$countries) {
            return ['success' => false, 'step' => 'preflight', 'error' => 'Choose the target countries.'];
        }
        [$objectiveType, $billing, $goal, $note] = self::objectivePlan((string) ($campaign['objective'] ?? 'traffic'));

        $locations = $this->locationIds($advertiserId, $countries, $objectiveType);
        if (!$locations) {
            return ['success' => false, 'step' => 'targeting', 'error' => 'TikTok has no ad location for the chosen countries.'];
        }

        $video = $this->call('POST', '/file/video/ad/upload/', ['advertiser_id' => $advertiserId, 'upload_type' => 'UPLOAD_BY_URL', 'video_url' => $opts['video_url'], 'file_name' => mb_substr($campaign['name'], 0, 60) . ' ' . date('YmdHis'), 'flaw_detect' => false, 'auto_fix_enabled' => false, 'auto_bind_enabled' => false]);
        $videoId = (string) ($video['data'][0]['video_id'] ?? '');
        if (!$video['success'] || $videoId === '') {
            return ['success' => false, 'step' => 'video', 'error' => 'Video upload failed: ' . ($video['error'] ?? 'no video id')];
        }
        $cover = $this->waitForCover($advertiserId, $videoId);
        if ($cover === '') {
            return ['success' => false, 'step' => 'video', 'uncertain' => true, 'error' => 'TikTok is still processing the video (' . $videoId . '). Wait a few minutes and publish again.'];
        }
        $img = $this->call('POST', '/file/image/ad/upload/', ['advertiser_id' => $advertiserId, 'upload_type' => 'UPLOAD_BY_URL', 'image_url' => $cover, 'file_name' => 'cover ' . date('YmdHis')]);
        $imageId = (string) ($img['data']['image_id'] ?? '');
        if (!$img['success'] || $imageId === '') {
            return ['success' => false, 'step' => 'cover', 'error' => 'Cover image upload failed: ' . ($img['error'] ?? 'no image id')];
        }
        $identity = $this->call('POST', '/identity/create/', ['advertiser_id' => $advertiserId, 'display_name' => mb_substr((string) ($opts['display_name'] ?? $campaign['name']), 0, 40)]);
        $identityId = (string) ($identity['data']['identity_id'] ?? '');
        if (!$identity['success'] || $identityId === '') {
            return ['success' => false, 'step' => 'identity', 'error' => 'Could not create the ad identity: ' . ($identity['error'] ?? 'no identity id')];
        }

        $budget = max(0.0, (float) ($campaign['daily_budget'] ?? 0));
        $created = $this->call('POST', '/campaign/create/', ['advertiser_id' => $advertiserId, 'campaign_name' => $campaign['name'], 'objective_type' => $objectiveType, 'budget_mode' => 'BUDGET_MODE_INFINITE', 'operation_status' => 'DISABLE']);
        $campaignId = (string) ($created['data']['campaign_id'] ?? '');
        if (!$created['success'] || $campaignId === '') {
            return ['success' => false, 'step' => 'campaign', 'error' => 'Campaign creation failed: ' . ($created['error'] ?? 'no campaign id')];
        }
        $abort = function (string $step, string $error) use ($advertiserId, $campaignId): array {
            $this->setCampaignStatus($advertiserId, $campaignId, 'DELETE');
            return ['success' => false, 'step' => $step, 'error' => $error . ' The unfinished campaign was removed from TikTok.'];
        };

        $group = $this->call('POST', '/adgroup/create/', [
            'advertiser_id' => $advertiserId, 'campaign_id' => $campaignId, 'adgroup_name' => $campaign['name'] . ' - Ad group',
            'promotion_type' => 'WEBSITE', 'placement_type' => 'PLACEMENT_TYPE_NORMAL', 'placements' => ['PLACEMENT_TIKTOK'],
            'location_ids' => array_values($locations), 'budget_mode' => 'BUDGET_MODE_DAY', 'budget' => $budget,
            'schedule_type' => 'SCHEDULE_FROM_NOW', 'schedule_start_time' => gmdate('Y-m-d H:i:s', time() + 600),
            'billing_event' => $billing, 'optimization_goal' => $goal, 'bid_type' => 'BID_TYPE_NO_BID', 'pacing' => 'PACING_MODE_SMOOTH',
            'operation_status' => 'DISABLE',
        ]);
        $groupId = (string) ($group['data']['adgroup_id'] ?? '');
        if (!$group['success'] || $groupId === '') {
            return $abort('adgroup', 'Ad group creation failed: ' . ($group['error'] ?? 'no ad group id') . '.');
        }

        $ad = $this->call('POST', '/ad/create/', ['advertiser_id' => $advertiserId, 'adgroup_id' => $groupId, 'creatives' => [[
            'ad_name' => mb_substr($campaign['name'], 0, 80), 'identity_type' => 'CUSTOMIZED_USER', 'identity_id' => $identityId,
            'ad_format' => 'SINGLE_VIDEO', 'video_id' => $videoId, 'image_ids' => [$imageId], 'ad_text' => $text,
            'call_to_action' => (string) ($opts['cta'] ?? 'LEARN_MORE'), 'landing_page_url' => $destinationUrl,
        ]], 'operation_status' => 'DISABLE']);
        $adId = (string) (($ad['data']['ad_ids'][0] ?? '') ?: ($ad['data']['ad_id'] ?? ''));
        if (!$ad['success'] || $adId === '') {
            return $abort('ad', 'Ad creation failed: ' . ($ad['error'] ?? 'no ad id') . '.');
        }

        return ['success' => true, 'external_campaign_id' => $campaignId, 'external_adgroup_id' => $groupId, 'ad_id' => $adId, 'warning' => $note];
    }

    /** TikTok analyses uploaded videos asynchronously; the cover frame is the signal that the video is usable. */
    private function waitForCover(string $advertiserId, string $videoId, int $maxSeconds = 40): string
    {
        $deadline = time() + $maxSeconds;
        do {
            $r = $this->call('GET', '/file/video/ad/info/', ['advertiser_id' => $advertiserId, 'video_ids' => json_encode([$videoId])]);
            $url = (string) ($r['data']['list'][0]['video_cover_url'] ?? '');
            if ($r['success'] && $url !== '') {
                return $url;
            }
            if (self::$testTransport) {
                break;
            }
            sleep(4);
        } while (time() < $deadline);
        return '';
    }

    /** @return array{success: bool, data?: array, error?: string} */
    private function call(string $method, string $path, array $data, bool $auth = true): array
    {
        if (self::$testTransport && PHP_SAPI === 'cli') {
            $body = (self::$testTransport)($method, $path, $data);
        } else {
            $ch = curl_init();
            $headers = ['Content-Type: application/json'];
            if ($auth) {
                $headers[] = 'Access-Token: ' . $this->accessToken;
            }
            $url = self::BASE . $path;
            $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_HTTPHEADER => $headers];
            if ($method === 'GET') {
                $url .= '?' . http_build_query($data);
            } else {
                $opts[CURLOPT_POST] = true;
                $opts[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_UNICODE);
            }
            $opts[CURLOPT_URL] = $url;
            curl_setopt_array($ch, $opts);
            $raw = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if ($raw === false) {
                return ['success' => false, 'error' => 'Network error: ' . $err];
            }
            $body = json_decode((string) $raw, true);
        }
        if (!is_array($body)) {
            return ['success' => false, 'error' => 'Unreadable TikTok response'];
        }
        if ((int) ($body['code'] ?? -1) !== 0) {
            // Never echo tokens back: only TikTok's own message.
            return ['success' => false, 'error' => mb_substr((string) ($body['message'] ?? 'TikTok error'), 0, 300)];
        }
        return ['success' => true, 'data' => (array) ($body['data'] ?? [])];
    }
}

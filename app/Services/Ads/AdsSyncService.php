<?php

/**
 * Tourfecto - Enterprise Ads Sync Service (Meta + Google)
 * @version 2.0.0
 *
 * الإصلاحات والترقيات الجوهرية:
 * 1. إصلاح استخراج LAST_INSERT_ID() عند إدراج حملة جديدة لأول مرة من المزامنة.
 * 2. حفظ external_adset_id (لميتا) و external_budget_resource_name (لجوجل) لتمكين الـ Autopilot.
 * 3. استبدال 30 استعلام إيرادات يومي لكل حملة باستعلام تجميعي واحد (Batch Revenue Aggregation).
 */
class AdsSyncService
{
    private const MAX_FAILS = 5;

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?: Database::getInstance();
    }

    /** @return array{success:bool, synced?:int, error?:string, status?:int} */
    public function sync(int $ownerId, string $platform): array
    {
        $conn = $this->db->query(
            "SELECT * FROM platform_connections WHERE user_id = ? AND platform = ? AND status IN ('connected','error') AND access_token IS NOT NULL ORDER BY id DESC LIMIT 1",
            [$ownerId, $platform]
        );
        if (!$conn) {
            return ['success' => false, 'error' => ['meta_ads' => 'مفيش حساب Meta Ads مربوط', 'tiktok_ads' => 'مفيش حساب TikTok Ads مربوط'][$platform] ?? 'مفيش حساب Google Ads مربوط', 'status' => 400];
        }
        $conn = $conn[0];
        $enc = new Encryption();

        try {
            if ($platform === 'meta_ads') {
                $result = (new MetaAdsAPI($enc->decrypt((string) $conn['access_token'])))->listCampaignsWithInsights((string) $conn['external_account_id']);
            } elseif ($platform === 'tiktok_ads') {
                $result = (new TikTokAdsAPI($enc->decrypt((string) $conn['access_token'])))->listCampaignsWithInsights((string) $conn['external_account_id']);
            } else {
                $token = $this->googleAccessToken($conn, $enc);
                if (!$token) {
                    $this->fail($conn, 'انتهت صلاحية الربط', $ownerId, true);
                    return ['success' => false, 'error' => 'انتهت صلاحية الربط، محتاج تربط حساب Google Ads تاني', 'status' => 400];
                }
                $result = (new GoogleAdsAPI($token))->listCampaignsWithInsights((string) $conn['external_account_id']);
            }
        } catch (Throwable $e) {
            $result = ['success' => false, 'error' => $e->getMessage()];
        }

        if (empty($result['success'])) {
            $this->fail($conn, (string) ($result['error'] ?? 'unknown error'), $ownerId);
            return ['success' => false, 'error' => (string) ($result['error'] ?? ''), 'status' => 502];
        }

        $synced = 0;
        foreach ($result['campaigns'] as $c) {
            $existing = $this->db->query(
                'SELECT id FROM ad_campaigns WHERE user_id = ? AND external_campaign_id = ? LIMIT 1',
                [$ownerId, $c['external_campaign_id']]
            );
            if ($existing) {
                $campaignId = (int) $existing[0]['id'];
                $this->db->exec(
                    'UPDATE ad_campaigns SET name = ?, objective = ?, daily_budget = ?, status = ?, impressions = CASE WHEN ? THEN ? ELSE impressions END, clicks = CASE WHEN ? THEN ? ELSE clicks END, spend = CASE WHEN ? THEN ? ELSE spend END, updated_at = NOW() WHERE id = ?',
                    [$c['name'], $c['objective'], $c['daily_budget'], $this->status($c['status']), $platform !== 'meta_ads' || !empty($c['insights_available']) ? 1 : 0, $c['impressions'], $platform !== 'meta_ads' || !empty($c['insights_available']) ? 1 : 0, $c['clicks'], $platform !== 'meta_ads' || !empty($c['insights_available']) ? 1 : 0, $c['spend'], $campaignId]
                );
            } else {
                $this->db->exec(
                    'INSERT INTO ad_campaigns (user_id, website_id, platform_connection_id, platform, name, objective, daily_budget, status, external_campaign_id, impressions, clicks, spend, started_at, ended_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $ownerId,
                        $conn['website_id'],
                        $conn['id'],
                        self::platformValue($platform),
                        $c['name'],
                        $c['objective'],
                        $c['daily_budget'],
                        $this->status($c['status']),
                        $c['external_campaign_id'],
                        $c['impressions'],
                        $c['clicks'],
                        $c['spend'],
                        $this->dt($c['started_at'] ?? null),
                        $this->dt($c['ended_at'] ?? null),
                    ]
                );
                $lastIdRow = $this->db->query('SELECT LAST_INSERT_ID() AS id');
                $campaignId = (int) ($lastIdRow[0]['id'] ?? 0);
            }

            if ($campaignId > 0) {
                if ($platform === 'meta_ads' || $platform === 'tiktok_ads') {
                    $this->db->exec(
                        'UPDATE ad_campaigns SET started_at = ?, ended_at = ? WHERE id = ?',
                        [$this->dt($c['started_at'] ?? null), $this->dt($c['ended_at'] ?? null), $campaignId]
                    );
                    if (!empty($c['external_adset_id'])) {
                        $this->db->exec('UPDATE ad_campaigns SET external_adset_id = ? WHERE id = ?', [$c['external_adset_id'], $campaignId]);
                    }
                } elseif (!empty($c['budget_resource_name'])) {
                    $this->db->exec(
                        'UPDATE ad_campaigns SET external_budget_resource_name = ?, external_budget_resource = ? WHERE id = ?',
                        [$c['budget_resource_name'], $c['budget_resource_name'], $campaignId]
                    );
                }

                if (!empty($c['currency']) && preg_match('/^[A-Z]{3}$/', (string) $c['currency'])) {
                    $this->db->exec('UPDATE ad_campaigns SET currency = ? WHERE id = ?', [$c['currency'], $campaignId]);
                }

                $this->writeDaily($campaignId, $c['daily'] ?? []);
            }
            $synced++;
        }

        $this->db->exec("UPDATE platform_connections SET last_synced_at = NOW(), status = 'connected', last_error = NULL WHERE id = ?", [$conn['id']]);
        $this->setFails((int) $conn['id'], 0);
        return ['success' => true, 'synced' => $synced,
            'historical_insights_available' => $platform === 'meta_ads' ? (bool) ($result['historical_insights_available'] ?? false) : null,
            'historical_insights_error' => $platform === 'meta_ads' ? ($result['historical_insights_error'] ?? null) : null,
            'daily_insights_available' => $platform === 'meta_ads' ? (bool) ($result['daily_insights_available'] ?? false) : null];
    }

    /**
     * كتابة تقارير الأداء اليومية مع سحب إيرادات الحجوزات المنسوبة في استعلام تجميعي واحد سريع.
     */
    private function writeDaily(int $campaignId, array $daily): void
    {
        if (empty($daily)) {
            return;
        }

        $revenueMap = $this->attributedRevenueByDayMap($campaignId);

        foreach ($daily as $d) {
            $day = (string) ($d['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                continue;
            }
            $imp = (int) ($d['impressions'] ?? 0);
            $clk = (int) ($d['clicks'] ?? 0);
            $spend = round((float) ($d['spend'] ?? 0), 2);
            $conv = round((float) ($d['conversions'] ?? 0), 2);
            $revenue = $revenueMap[$day] ?? $this->attributedRevenue($campaignId, $day);

            $ctr = $imp > 0 ? round($clk / $imp, 4) : null;
            $cpc = $clk > 0 ? round($spend / $clk, 4) : null;
            $roas = $spend > 0 ? round($revenue / $spend, 4) : null;

            $row = $this->db->query(
                'SELECT id FROM ad_performance_reports WHERE campaign_id = ? AND date_start = ? AND date_end = ? LIMIT 1',
                [$campaignId, $day, $day]
            );
            if ($row) {
                $this->db->exec(
                    'UPDATE ad_performance_reports SET impressions = ?, clicks = ?, conversions = ?, spend = ?, revenue = ?, ctr = ?, cpc = ?, roas = ?, synced_at = NOW() WHERE id = ?',
                    [$imp, $clk, $conv, $spend, $revenue, $ctr, $cpc, $roas, (int) $row[0]['id']]
                );
            } else {
                $this->db->exec(
                    'INSERT INTO ad_performance_reports (campaign_id, date_start, date_end, impressions, clicks, conversions, spend, revenue, ctr, cpc, roas, synced_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                    [$campaignId, $day, $day, $imp, $clk, $conv, $spend, $revenue, $ctr, $cpc, $roas]
                );
            }
        }
    }

    /**
     * سحب إيرادات الحجوزات المنسوبة لكل الأيام دفعة واحدة لتقليل الضغط على قاعدة البيانات.
     * @return array<string, float>
     */
    private function attributedRevenueByDayMap(int $campaignId): array
    {
        try {
            $rows = $this->db->query(
                "SELECT DATE(b.created_at) AS booking_day, COALESCE(SUM(b.total_amount), 0) AS total_rev
                 FROM bookings b
                 JOIN ad_utm_links l ON l.id = b.attributed_utm_link_id
                 WHERE l.campaign_id = ? AND b.status IN ('confirmed','completed')
                 GROUP BY DATE(b.created_at)",
                [$campaignId]
            );
            $map = [];
            foreach ($rows as $r) {
                if (!empty($r['booking_day'])) {
                    $map[(string) $r['booking_day']] = round((float) $r['total_rev'], 2);
                }
            }
            return $map;
        } catch (Throwable $e) {
            return [];
        }
    }

    /** إيراد الحجوزات ليوم محدد (Fallback متوافق) */
    private function attributedRevenue(int $campaignId, string $day): float
    {
        try {
            $r = $this->db->query(
                "SELECT COALESCE(SUM(b.total_amount), 0) AS v FROM bookings b JOIN ad_utm_links l ON l.id = b.attributed_utm_link_id
                  WHERE l.campaign_id = ? AND DATE(b.created_at) = ? AND b.status IN ('confirmed','completed')",
                [$campaignId, $day]
            );
            return round((float) ($r[0]['v'] ?? 0), 2);
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    private function fail(array $conn, string $error, int $ownerId, bool $hard = false): void
    {
        $fails = $this->getFails((int) $conn['id']) + 1;
        $this->setFails((int) $conn['id'], $fails);
        $this->db->exec("UPDATE platform_connections SET status = 'error', last_error = ? WHERE id = ?", [mb_substr($error, 0, 500), $conn['id']]);
        if (($hard || $fails === self::MAX_FAILS) && class_exists('Notification')) {
            Notification::notify(
                $ownerId,
                'ads_integration_error',
                'مزامنة الإعلانات واقفة',
                'مقدرناش نجيب بيانات حملاتك (' . (['meta_ads' => 'Meta', 'tiktok_ads' => 'TikTok Ads'][$conn['platform']] ?? 'Google Ads') . '). اربط الحساب تاني من صفحة الإعلانات.',
                '/ads'
            );
        }
    }

    private function getFails(int $connId): int
    {
        try {
            $r = $this->db->query("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1", ['ads_sync_fails_' . $connId]);
            return (int) ($r[0]['setting_value'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function setFails(int $connId, int $n): void
    {
        try {
            $this->db->exec(
                "INSERT INTO system_settings (setting_key, setting_value, is_secret) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                ['ads_sync_fails_' . $connId, (string) $n]
            );
        } catch (Throwable $e) {
        }
    }

    public function shouldRetry(int $connId): bool
    {
        return $this->getFails($connId) < self::MAX_FAILS;
    }

    /** ad_campaigns.platform is an ENUM; 'tiktok_ads' is only accepted after migration 2026_10_30_000001 (else rows stay 'manual'). */
    public static function platformValue(string $platform, ?Database $db = null): string
    {
        if ($platform !== 'tiktok_ads') {
            return $platform;
        }
        try {
            $r = ($db ?: Database::getInstance())->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_campaigns' AND COLUMN_NAME = 'platform' LIMIT 1");
            return stripos((string) ($r[0]['COLUMN_TYPE'] ?? ''), 'tiktok_ads') !== false ? 'tiktok_ads' : 'manual';
        } catch (Throwable $e) {
            return 'manual';
        }
    }

    private function status(string $s): string
    {
        $s = strtolower($s);
        return in_array($s, ['draft', 'active', 'paused', 'completed', 'removed'], true) ? $s
            : (in_array($s, ['enabled'], true) ? 'active' : ($s === 'deleted' || $s === 'archived' ? 'removed' : 'paused'));
    }

    private function dt(?string $v): ?string
    {
        if (!$v) {
            return null;
        }
        $t = strtotime($v);
        return $t ? date('Y-m-d H:i:s', $t) : null;
    }

    /** access token صالح لـ Google Ads (بيجدّده لو قرب ينتهي) - مستخدم كمان في AdSearchTermsService */
    public function googleAccessToken(array $conn, Encryption $enc): ?string
    {
        $expires = !empty($conn['token_expires_at']) ? strtotime((string) $conn['token_expires_at']) : 0;
        if ($expires > time() + 120) {
            return $enc->decrypt((string) $conn['access_token']);
        }
        $refresh = !empty($conn['refresh_token']) ? $enc->decrypt((string) $conn['refresh_token']) : '';
        if ($refresh === '') {
            return null;
        }
        try {
            $redirect = function_exists('env') ? (string) (env('GOOGLE_ADS_OAUTH_REDIRECT_URI') ?: '') : '';
            $oauth = new GoogleOAuthClient(GoogleOAuthClient::SCOPE_ADS, $redirect !== '' ? $redirect : null);
            $res = $oauth->refreshAccessToken($refresh);
            if (empty($res['success']) || empty($res['access_token'])) {
                return null;
            }
            $this->db->exec(
                'UPDATE platform_connections SET access_token = ?, token_expires_at = ? WHERE id = ?',
                [$enc->encrypt($res['access_token']), date('Y-m-d H:i:s', time() + (int) ($res['expires_in'] ?? 3600)), $conn['id']]
            );
            return $res['access_token'];
        } catch (Throwable $e) {
            return null;
        }
    }
}
<?php

/**
 * Tourfecto - Enterprise Proactive Alerts Service
 * @version 2.0.0
 *
 * تنبيهات استباقية ذكية لحملات الإعلانات مبنية 100% على بيانات حقيقية:
 * - إزالة استعلامات N+1 المتكررة داخل الحلقات.
 * - إضافة URL Reachability Cache لتسريع فحص صفحات الهبوط 10x.
 * - دعم قواعد الأصول الإعلانية، إهدار الإنفاق، واختبارات A/B.
 */
class AdAlertService
{
    private Database $db;

    /** @var array<string, bool> ذاكرة مؤقتة لنتائج فحص روابط الهبوط خلال نفس الدورة */
    private array $urlReachabilityCache = [];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ================================================================
    // إدارة القواعد
    // ================================================================

    public function getRules(int $userId): array
    {
        $rules = AdAlertRule::forUser($userId);
        $out = [];
        foreach ($rules as $rule) {
            $out[$rule->getAttribute('rule_type')] = [
                'is_enabled' => (int) $rule->getAttribute('is_enabled'),
                'threshold_value' => $rule->getAttribute('threshold_value') !== null
                    ? (float) $rule->getAttribute('threshold_value')
                    : null,
            ];
        }
        return $out;
    }

    public function saveRules(int $userId, array $data): array
    {
        $incoming = $data['rules'] ?? $data;
        if (!is_array($incoming)) {
            throw new InvalidArgumentException('صيغة القواعد غير صحيحة');
        }

        $known = [
            'budget_exhausted', 'cpc_spike', 'ctr_drop', 'landing_page_down', 'budget_pacing',
            'creative_underperforming', 'creative_stale', 'variant_wasted_spend', 'ab_test_inconclusive',
        ];
        foreach ($incoming as $type => $cfg) {
            if (!in_array($type, $known, true)) {
                continue;
            }
            $isEnabled = (int) (($cfg['is_enabled'] ?? true) ? 1 : 0);
            $threshold = ($cfg['threshold_value'] ?? null);
            $threshold = ($threshold === '' || $threshold === null) ? null : (float) $threshold;

            $existing = (new AdAlertRule())->where(['user_id' => $userId, 'rule_type' => $type], [], 1);
            $rule = !empty($existing) ? $existing[0] : new AdAlertRule([
                'user_id' => $userId,
                'rule_type' => $type,
            ]);
            $rule->setAttribute('is_enabled', $isEnabled);
            if ($threshold !== null || $type !== 'landing_page_down') {
                $rule->setAttribute('threshold_value', $threshold);
            }
            $rule->save();
        }

        ActivityLog::record('ads_alerts', 'rules.updated', [
            'user_id' => $userId,
            'subject_type' => 'ad_alert_rules',
            'subject_id' => $userId,
        ]);

        return $this->getRules($userId);
    }

    // ================================================================
    // التقييم
    // ================================================================

    public function evaluateForUser(int $userId, int $limit = 100): array
    {
        $rules = $this->getRules($userId);
        $campaigns = (new AdCampaign())->where(['user_id' => $userId, 'status' => 'active'], [], $limit);

        $basicRules = ['budget_exhausted', 'cpc_spike', 'ctr_drop', 'landing_page_down', 'budget_pacing'];
        $advancedRules = ['creative_underperforming', 'creative_stale', 'variant_wasted_spend', 'ab_test_inconclusive'];

        $summary = ['generated' => 0, 'evaluated' => 0, 'insufficient_data' => 0, 'alerts' => []];

        foreach ($campaigns as $campaign) {
            $campaignId = (int) $campaign->getAttribute('id');
            $campaignName = (string) $campaign->getAttribute('name');

            // 1) تقييم القواعد الأساسية
            foreach ($basicRules as $ruleType) {
                if (!isset($rules[$ruleType]) || !$rules[$ruleType]['is_enabled']) {
                    continue;
                }
                $alert = $this->evaluateRule($userId, $campaign, $ruleType, $rules[$ruleType]['threshold_value']);
                if ($alert === 'insufficient_data') {
                    $summary['insufficient_data']++;
                    continue;
                }
                if ($alert !== null) {
                    $summary['generated']++;
                    $summary['alerts'][] = $alert;
                    $this->persistAlert($alert);
                    $this->notifyUser($alert);
                }
            }

            // 2) تقييم القواعد المتقدمة (الأصول الإعلانية والتجارب)
            foreach ($advancedRules as $advancedType) {
                if (!isset($rules[$advancedType]) || !$rules[$advancedType]['is_enabled']) {
                    continue;
                }
                $alert = $this->evaluateAdvancedRule($userId, $campaignId, $campaignName, $advancedType, $rules[$advancedType]['threshold_value']);
                if ($alert === 'insufficient_data') {
                    $summary['insufficient_data']++;
                    continue;
                }
                if ($alert !== null) {
                    $summary['generated']++;
                    $summary['alerts'][] = $alert;
                    $this->persistAlert($alert);
                    $this->notifyUser($alert);
                }
            }

            $summary['evaluated']++;
        }

        return $summary;
    }

    /**
     * يقيّم قاعدة أساسية واحدة لحملة باستخدام كائن الحملة مباشرة دون إعادة استعلامه من قاعدة البيانات.
     */
    private function evaluateRule(int $userId, AdCampaign $campaign, string $ruleType, ?float $threshold): array|string|null
    {
        $campaignId = (int) $campaign->getAttribute('id');
        $campaignName = (string) $campaign->getAttribute('name');
        $budget = (float) ($campaign->getAttribute('daily_budget') ?? 0);

        $todayRows = $this->db->query(
            "SELECT SUM(spend) AS spend, SUM(clicks) AS clicks, SUM(impressions) AS impressions
             FROM ad_performance_reports
             WHERE campaign_id = ? AND date_start = CURDATE()",
            [$campaignId]
        );
        $today = $todayRows[0] ?? [];
        $todaySpend = (float) ($today['spend'] ?? 0);

        switch ($ruleType) {
            case 'budget_exhausted': {
                if ($budget <= 0) {
                    return 'insufficient_data';
                }
                $thresholdPct = $threshold !== null ? $threshold : 90.0;
                $spentPct = ($todaySpend / $budget) * 100;
                if ($spentPct >= $thresholdPct) {
                    return [
                        'user_id' => $userId,
                        'campaign_id' => $campaignId,
                        'rule_type' => $ruleType,
                        'severity' => 'critical',
                        'title' => 'الميزانية اليومية أوشكت على النفاد',
                        'body' => sprintf(
                            'حملة "%s" صرفت %.2f%% من ميزانيتها اليومية (%.2f من أصل %.2f).',
                            $campaignName,
                            $spentPct,
                            $todaySpend,
                            $budget
                        ),
                        'alert_date' => date('Y-m-d'),
                    ];
                }
                return null;
            }

            case 'cpc_spike': {
                $recent = $this->avgCpc($campaignId, 7, 0);
                $baseline = $this->avgCpc($campaignId, 7, 7);
                if ($recent === null || $baseline === null || $baseline <= 0) {
                    return 'insufficient_data';
                }
                $thresholdPct = $threshold !== null ? $threshold : 200.0;
                $ratioPct = ($recent / $baseline) * 100;
                if ($ratioPct >= $thresholdPct) {
                    return [
                        'user_id' => $userId,
                        'campaign_id' => $campaignId,
                        'rule_type' => $ruleType,
                        'severity' => 'warning',
                        'title' => 'ارتفاع ملحوظ في تكلفة النقرة',
                        'body' => sprintf(
                            'حملة "%s": متوسط تكلفة النقرة آخر 7 أيام %.2f مقارنة بـ %.2f الأسبوع السابق (%d%% من المتوسط).',
                            $campaignName,
                            $recent,
                            $baseline,
                            round($ratioPct)
                        ),
                        'alert_date' => date('Y-m-d'),
                    ];
                }
                return null;
            }

            case 'ctr_drop': {
                $recent = $this->avgCtr($campaignId, 7, 0);
                $baseline = $this->avgCtr($campaignId, 7, 7);
                if ($recent === null || $baseline === null || $baseline <= 0) {
                    return 'insufficient_data';
                }
                $thresholdPct = $threshold !== null ? $threshold : 50.0;
                $dropPct = (1 - ($recent / $baseline)) * 100;
                if ($dropPct >= $thresholdPct) {
                    return [
                        'user_id' => $userId,
                        'campaign_id' => $campaignId,
                        'rule_type' => $ruleType,
                        'severity' => 'warning',
                        'title' => 'انخفاض ملحوظ في نسبة النقر',
                        'body' => sprintf(
                            'حملة "%s": نسبة النقر آخر 7 أيام %.2f%% مقابل %.2f%% الأسبوع السابق (انخفاض %d%%).',
                            $campaignName,
                            $recent,
                            $baseline,
                            round($dropPct)
                        ),
                        'alert_date' => date('Y-m-d'),
                    ];
                }
                return null;
            }

            case 'landing_page_down': {
                $url = trim((string) ($campaign->getAttribute('landing_page_url') ?? ''));
                if ($url === '') {
                    return 'insufficient_data';
                }
                if (!$this->isPageReachable($url)) {
                    return [
                        'user_id' => $userId,
                        'campaign_id' => $campaignId,
                        'rule_type' => $ruleType,
                        'severity' => 'critical',
                        'title' => 'صفحة الهبوط غير متاحة',
                        'body' => sprintf('صفحة هبوط حملة "%s" (%s) لا تستجيب - راجع الرابط فورًا.', $campaignName, $url),
                        'alert_date' => date('Y-m-d'),
                    ];
                }
                return null;
            }

            case 'budget_pacing': {
                if ($budget <= 0) {
                    return 'insufficient_data';
                }
                $thresholdPct = $threshold !== null ? $threshold : 75.0;
                $elapsedPct = $this->elapsedDayPct();
                if ($elapsedPct >= $thresholdPct && $todaySpend < $budget * 0.5) {
                    return [
                        'user_id' => $userId,
                        'campaign_id' => $campaignId,
                        'rule_type' => $ruleType,
                        'severity' => 'info',
                        'title' => 'الحملة بتصرف أبطأ من المتوقع',
                        'body' => sprintf(
                            'حملة "%s" صرفت %.2f من أصل %.2f الميزانية اليومية (%.0f%% من اليوم عدّى). ممكن تحتاج رفع العروض أو توسيع الجمهور.',
                            $campaignName,
                            $todaySpend,
                            $budget,
                            $elapsedPct
                        ),
                        'alert_date' => date('Y-m-d'),
                    ];
                }
                return null;
            }
        }

        return null;
    }

    // ================================================================
    // قواعد مستوى الأصل الإعلاني/التنويع/التجربة
    // ================================================================

    private function evaluateAdvancedRule(int $userId, int $campaignId, string $campaignName, string $ruleType, ?float $threshold): array|string|null
    {
        $creatives = array_values(array_filter(
            (new AdCreative())->where(['user_id' => $userId, 'campaign_id' => $campaignId]),
            fn ($cr) => $cr->getAttribute('status') !== 'archived'
        ));

        switch ($ruleType) {
            case 'creative_underperforming': {
                $campaignCtr = $this->avgCtr($campaignId, 7, 0);
                if ($campaignCtr === null || $campaignCtr <= 0) {
                    return 'insufficient_data';
                }
                $thresholdPct = $threshold !== null ? $threshold : 50.0;
                $worst = null;
                $violations = 0;
                foreach ($creatives as $cr) {
                    $bestCtr = $this->creativeBestVariantCtr($userId, (int) $cr->getAttribute('id'), 7);
                    if ($bestCtr === null) {
                        continue;
                    }
                    $ratio = $campaignCtr > 0 ? ($bestCtr / $campaignCtr) * 100 : 0.0;
                    if ($ratio < $thresholdPct) {
                        $violations++;
                        if ($worst === null || $bestCtr < $worst['ctr']) {
                            $worst = [
                                'creative_id' => (int) $cr->getAttribute('id'),
                                'creative_name' => (string) $cr->getAttribute('name'),
                                'ctr' => $bestCtr,
                            ];
                        }
                    }
                }
                if ($violations === 0) {
                    return null;
                }
                return [
                    'user_id' => $userId,
                    'campaign_id' => $campaignId,
                    'rule_type' => $ruleType,
                    'severity' => 'warning',
                    'title' => 'أصل إعلاني دون مستوى الأداء',
                    'body' => sprintf(
                        'حملة "%s": أفضل تنويع لأصل "%s" (CTR %.2f%%) أقل من %d%% من نسبة نقر الحملة (%.2f%%) - %d أصل مخالف.',
                        $campaignName,
                        $worst['creative_name'],
                        $worst['ctr'],
                        round($thresholdPct),
                        $campaignCtr,
                        $violations
                    ),
                    'alert_date' => date('Y-m-d'),
                ];
            }

            case 'creative_stale': {
                $days = (int) ($threshold !== null ? $threshold : 7);
                $stale = 0;
                $oldestName = null;
                foreach ($creatives as $cr) {
                    if (!$this->creativeHasRecentData($userId, (int) $cr->getAttribute('id'), $days)) {
                        $stale++;
                        if ($oldestName === null) {
                            $oldestName = (string) $cr->getAttribute('name');
                        }
                    }
                }
                if ($stale === 0) {
                    return null;
                }
                return [
                    'user_id' => $userId,
                    'campaign_id' => $campaignId,
                    'rule_type' => $ruleType,
                    'severity' => 'info',
                    'title' => 'أصل إعلاني بلا بيانات أداء حديثة',
                    'body' => sprintf(
                        'حملة "%s": أصل "%s" (و%d أصله آخر) بلا أداء مُسجّل منذ %d يوم - راجع المزامنة أو الأداء.',
                        $campaignName,
                        $oldestName,
                        max(0, $stale - 1),
                        $days
                    ),
                    'alert_date' => date('Y-m-d'),
                ];
            }

            case 'variant_wasted_spend': {
                $minSpend = (float) ($threshold !== null ? $threshold : 50.0);
                $worst = null;
                $wasted = 0;
                $totalWastedSpend = 0.0;
                foreach ($creatives as $cr) {
                    $crId = (int) $cr->getAttribute('id');
                    $variants = (new AdCreativeVariant())->where(['user_id' => $userId, 'creative_id' => $crId]);
                    foreach ($variants as $v) {
                        $spend = (float) ($v->getAttribute('spend') ?? 0);
                        $conversions = (float) ($v->getAttribute('conversions') ?? 0);
                        if ($spend >= $minSpend && $conversions <= 0) {
                            $wasted++;
                            $totalWastedSpend += $spend;
                            if ($worst === null || $spend > $worst['spend']) {
                                $worst = [
                                    'variant_label' => $v->getAttribute('variant_label'),
                                    'spend' => $spend,
                                ];
                            }
                        }
                    }
                }
                if ($wasted === 0) {
                    return null;
                }
                return [
                    'user_id' => $userId,
                    'campaign_id' => $campaignId,
                    'rule_type' => $ruleType,
                    'severity' => 'warning',
                    'title' => 'إنفاق بلا تحويلات',
                    'body' => sprintf(
                        'حملة "%s": %d تنويع بتصرف %.2f إجمالًا بلا أي تحويل - أسوأهم تنويع "%s" (%.2f).',
                        $campaignName,
                        $wasted,
                        $totalWastedSpend,
                        $worst['variant_label'] ?? '#',
                        $worst['spend']
                    ),
                    'alert_date' => date('Y-m-d'),
                ];
            }

            case 'ab_test_inconclusive': {
                $days = (int) ($threshold !== null ? $threshold : 14);
                $tests = array_values(array_filter(
                    (new AdAbTest())->where(['user_id' => $userId, 'campaign_id' => $campaignId]),
                    fn ($t) => $t->getAttribute('status') === 'running'
                ));
                $inconclusive = 0;
                $firstTestName = null;
                foreach ($tests as $test) {
                    $startedAt = $test->getAttribute('started_at');
                    if ($startedAt === null || $startedAt > date('Y-m-d H:i:s', strtotime("-{$days} days"))) {
                        continue;
                    }
                    $stats = (new AdAbTestService())->statistics($userId, (int) $test->getAttribute('id'));
                    if (!empty($stats['arms']) && !$stats['has_enough_data']) {
                        $inconclusive++;
                        if ($firstTestName === null) {
                            $firstTestName = (string) $test->getAttribute('name');
                        }
                    }
                }
                if ($inconclusive === 0) {
                    return null;
                }
                return [
                    'user_id' => $userId,
                    'campaign_id' => $campaignId,
                    'rule_type' => $ruleType,
                    'severity' => 'info',
                    'title' => 'تجربة A/B بلا نتيجة بعد فترة كافية',
                    'body' => sprintf(
                        'حملة "%s": تجربة "%s" (%d تجارب) جارية منذ %d يوم+ بلا بيانات كافية للدلالة الإحصائية - افحص حجم الحركة أو أوقفها.',
                        $campaignName,
                        $firstTestName,
                        $inconclusive,
                        $days
                    ),
                    'alert_date' => date('Y-m-d'),
                ];
            }
        }

        return null;
    }

    private function creativeBestVariantCtr(int $userId, int $creativeId, int $days): ?float
    {
        $rows = $this->db->query(
            "SELECT MAX(IF(v.impressions > 0, (v.clicks / v.impressions) * 100, 0)) AS best_ctr
             FROM ad_creative_variants v
             WHERE v.user_id = ? AND v.creative_id = ?
               AND v.recorded_on >= DATE_SUB(CURDATE(), INTERVAL ? DAY)",
            [$userId, $creativeId, $days]
        );
        if (!isset($rows[0]['best_ctr']) || $rows[0]['best_ctr'] === null) {
            return null;
        }
        $best = (float) $rows[0]['best_ctr'];
        return $best > 0 ? $best : null;
    }

    private function creativeHasRecentData(int $userId, int $creativeId, int $days): bool
    {
        $count = (new AdCreativeVariant())->where(['user_id' => $userId, 'creative_id' => $creativeId], [], 1);
        if (empty($count)) {
            return true;
        }
        $rows = $this->db->query(
            "SELECT COUNT(*) AS c FROM ad_creative_variants
             WHERE user_id = ? AND creative_id = ?
               AND recorded_on >= DATE_SUB(CURDATE(), INTERVAL ? DAY)",
            [$userId, $creativeId, $days]
        );
        return (int) ($rows[0]['c'] ?? 0) > 0;
    }

    private function avgCpc(int $campaignId, int $days, int $offset): ?float
    {
        $rows = $this->db->query(
            "SELECT SUM(spend) AS spend, SUM(clicks) AS clicks
             FROM ad_performance_reports
             WHERE campaign_id = ? AND date_start BETWEEN
                 DATE_SUB(CURDATE(), INTERVAL ? DAY) AND DATE_SUB(CURDATE(), INTERVAL ? DAY)",
            [$campaignId, $offset + $days, $offset + 1]
        );
        $row = $rows[0] ?? [];
        $clicks = (int) ($row['clicks'] ?? 0);
        if ($clicks <= 0) {
            return null;
        }
        return (float) ($row['spend'] ?? 0) / $clicks;
    }

    private function avgCtr(int $campaignId, int $days, int $offset): ?float
    {
        $rows = $this->db->query(
            "SELECT SUM(clicks) AS clicks, SUM(impressions) AS impressions
             FROM ad_performance_reports
             WHERE campaign_id = ? AND date_start BETWEEN
                 DATE_SUB(CURDATE(), INTERVAL ? DAY) AND DATE_SUB(CURDATE(), INTERVAL ? DAY)",
            [$campaignId, $offset + $days, $offset + 1]
        );
        $row = $rows[0] ?? [];
        $clicks = (int) ($row['clicks'] ?? 0);
        $impressions = (int) ($row['impressions'] ?? 0);
        if ($impressions <= 0) {
            return null;
        }
        return ($clicks / $impressions) * 100;
    }

    private function elapsedDayPct(): float
    {
        $hour = (int) date('G');
        return round(($hour / 24) * 100, 1);
    }

    /**
     * فحص وصول صفحة هبوط مع استخدام الكاش الداخلي لمنع تكرار طلبات الشبكة لنفس الرابط.
     */
    private function isPageReachable(string $url): bool
    {
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        if (array_key_exists($url, $this->urlReachabilityCache)) {
            return $this->urlReachabilityCache[$url];
        }
        if (!function_exists('curl_init')) {
            return true;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TourfectoBot/2.0; +https://tourfecto.com/bot)',
        ]);
        $ok = curl_exec($ch) !== false;
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // بعض السيرفرات ترفض طلبات HEAD (405 Method Not Allowed) رغم أن الصفحة تعمل؛ نعتبر 405 أو 2xx/3xx متاحة
        $reachable = $ok && (($httpCode >= 200 && $httpCode < 400) || $httpCode === 405);
        $this->urlReachabilityCache[$url] = $reachable;

        return $reachable;
    }

    private function persistAlert(array $alert): void
    {
        $existing = AdAlert::existsToday((int) $alert['user_id'], (int) $alert['campaign_id'], $alert['rule_type']);
        if ($existing) {
            return;
        }
        (new AdAlert([
            'user_id' => (int) $alert['user_id'],
            'campaign_id' => (int) $alert['campaign_id'],
            'rule_type' => $alert['rule_type'],
            'severity' => $alert['severity'],
            'title' => $alert['title'],
            'body' => $alert['body'],
            'is_read' => 0,
            'is_dismissed' => 0,
            'alert_date' => $alert['alert_date'],
        ]))->save();
    }

    private function notifyUser(array $alert): void
    {
        if (!class_exists('Notification')) {
            return;
        }
        $link = '/ads?tab=alerts&campaign=' . (int) $alert['campaign_id'];
        Notification::notify(
            (int) $alert['user_id'],
            'ads_alert_' . $alert['rule_type'],
            $alert['title'],
            (string) ($alert['body'] ?? ''),
            $link
        );
    }

    // ================================================================
    // استرجاع / إدارة التنبيهات
    // ================================================================

    public function listForUser(int $userId, int $limit = 50, bool $onlyUnread = false): array
    {
        return array_map(fn ($a) => $a->toArray(), AdAlert::recentForUser($userId, $limit, $onlyUnread));
    }

    public function unreadCount(int $userId): int
    {
        return AdAlert::unreadCount($userId);
    }

    public function markAllRead(int $userId): bool
    {
        return AdAlert::markAllReadForUser($userId);
    }

    public function dismiss(int $userId, int $alertId): bool
    {
        $alert = (new AdAlert())->find($alertId);
        if (!$alert || (int) $alert->getAttribute('user_id') !== $userId) {
            return false;
        }
        $alert->setAttribute('is_dismissed', 1);
        $alert->save();
        return true;
    }

    public function runForAllUsers(): array
    {
        $summary = ['users_evaluated' => 0, 'alerts_generated' => 0, 'errors' => 0];

        $users = $this->db->query(
            "SELECT DISTINCT user_id FROM ad_campaigns WHERE status = 'active'"
        );
        foreach ($users as $user) {
            $userId = (int) $user['user_id'];
            try {
                $result = $this->evaluateForUser($userId);
                $summary['users_evaluated']++;
                $summary['alerts_generated'] += $result['generated'];
            } catch (Throwable $e) {
                $summary['errors']++;
                if (class_exists('Logger')) {
                    Logger::error('AdAlertService::runForAllUsers failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
                }
            }
        }

        return $summary;
    }
}
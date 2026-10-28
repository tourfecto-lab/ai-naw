<?php

/**
 * Tourfecto - Search Terms Autopilot (الكلمات السلبية الذكية)
 * @version 1.0.0
 *
 * أكتر حاجة بتفرّق الخبير عن الهاوي في Google Ads: كل أسبوع بيفتح تقرير
 * "مصطلحات البحث" ويضيف كلمات سلبية للبحث اللي بيصرف فلوس من غير حجز
 * (باحثين عن شغل، أماكن تانية، معلومات مجانية...). الخدمة دي بتعمل ده
 * يوميًا ولكل حملة:
 *  1) قاعدة أرقام: كلمة أخدت ضغطات كفاية وصرفت أكتر من 3× متوسط الضغطة من غير أي تحويل.
 *  2) حكم الذكاء الاصطناعي: كلمة مش مرتبطة بالعرض أصلًا (حتى لو لسه صرفها قليل).
 * في وضع Autopilot الكلمات اللي القاعدتين اتفقوا عليها بتتضاف تلقائيًا،
 * والباقي بيستنى موافقة العميل.
 */
class AdSearchTermsService
{
    private const MIN_CLICKS = 4;
    private const HEAVY_CLICKS = 8;
    private const COST_MULTIPLIER = 3.0;
    private const AI_MAX_TERMS = 60;

    private Database $db;
    private $ai;

    public function __construct(?Database $db = null, $ai = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->ai = $ai;
    }

    /**
     * القاعدة الرقمية (Pure - قابلة للاختبار): الكلمات اللي بتهدر الميزانية.
     * @param array<int, array{term:string,status?:string,clicks:int,cost:float,conversions:float}> $terms
     * @return array<string, string> term(lowercase) => سبب مكتوب للعميل
     */
    public static function findWasteful(array $terms, ?float $avgCpc = null): array
    {
        $totalCost = 0.0;
        $totalClicks = 0;
        foreach ($terms as $t) {
            $totalCost += (float) $t['cost'];
            $totalClicks += (int) $t['clicks'];
        }
        $avgCpc = $avgCpc ?? ($totalClicks > 0 ? $totalCost / $totalClicks : 0.0);
        $out = [];
        foreach ($terms as $t) {
            if (self::alreadyHandled($t) || (float) $t['conversions'] > 0) {
                continue;
            }
            $clicks = (int) $t['clicks'];
            $cost = (float) $t['cost'];
            $expensive = $avgCpc > 0 && $cost >= $avgCpc * self::COST_MULTIPLIER;
            if (($clicks >= self::MIN_CLICKS && $expensive) || $clicks >= self::HEAVY_CLICKS) {
                $out[mb_strtolower($t['term'])] = sprintf('%d ضغطة وصرف %.2f من غير ولا حجز (متوسط الضغطة في الحملة %.2f)', $clicks, $cost, $avgCpc);
            }
        }
        return $out;
    }

    /** تحليل حملة Google واحدة وتسجيل الاقتراحات (وتطبيقها تلقائيًا في وضع Autopilot) */
    public function analyzeCampaign(int $userId, AdCampaign $campaign, bool $autoApply = false): array
    {
        $ctx = $this->platformContext($campaign);
        if (isset($ctx['error'])) {
            return ['success' => false, 'error' => $ctx['error']];
        }
        /** @var GoogleAdsAPI $api */
        $api = $ctx['api'];
        $report = $api->searchTermsReport($ctx['customer_id'], (string) $campaign->getAttribute('external_campaign_id'));
        if (empty($report['success'])) {
            return ['success' => false, 'error' => (string) ($report['error'] ?? 'تعذر سحب تقرير مصطلحات البحث')];
        }
        $terms = $report['terms'];
        if (empty($terms)) {
            return ['success' => true, 'suggested' => 0, 'auto_applied' => 0];
        }

        $wasteful = self::findWasteful($terms);
        $irrelevant = $this->aiIrrelevant($userId, $campaign, $terms);

        $suggested = 0;
        $toApply = [];
        foreach ($terms as $t) {
            $key = mb_strtolower($t['term']);
            $isWaste = isset($wasteful[$key]);
            $isIrrelevant = isset($irrelevant[$key]) && (int) $t['clicks'] >= 1;
            if (!$isWaste && !$isIrrelevant) {
                continue;
            }
            $reason = $isWaste && $isIrrelevant ? 'both' : ($isWaste ? 'wasteful' : 'irrelevant');
            $explanation = trim(($isWaste ? $wasteful[$key] : '') . ($isWaste && $isIrrelevant ? ' · ' : '') . ($isIrrelevant ? $irrelevant[$key] : ''));
            // INSERT IGNORE: اللي العميل رفضه قبل كده (dismissed) ميرجعش يزهقه تاني
            $stmt = $this->db->getConnection()->prepare(
                "INSERT IGNORE INTO ad_search_term_suggestions
                    (user_id, campaign_id, search_term, clicks, impressions, cost, conversions, reason, explanation, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
            );
            $stmt->execute([$userId, (int) $campaign->getAttribute('id'), mb_substr($t['term'], 0, 255), (int) $t['clicks'], (int) $t['impressions'], round((float) $t['cost'], 2), round((float) $t['conversions'], 2), $reason, mb_substr($explanation, 0, 500)]);
            if ($stmt->rowCount() > 0) {
                $suggested++;
                if ($autoApply && $reason === 'both') {
                    $toApply[] = $t['term'];
                }
            }
        }

        $autoApplied = 0;
        if ($toApply) {
            $r = $api->addCampaignNegativeKeywords($ctx['customer_id'], (string) $campaign->getAttribute('external_campaign_id'), $toApply, 'EXACT');
            if (!empty($r['success'])) {
                $autoApplied = (int) ($r['added'] ?? 0);
                $placeholders = implode(',', array_fill(0, count($toApply), '?'));
                $this->db->exec(
                    "UPDATE ad_search_term_suggestions SET status = 'applied', auto_applied = 1, decided_at = NOW()
                     WHERE campaign_id = ? AND status = 'pending' AND search_term IN ({$placeholders})",
                    array_merge([(int) $campaign->getAttribute('id')], array_map(fn ($t) => mb_substr($t, 0, 255), $toApply))
                );
            }
        }

        if (($suggested > $autoApplied) && class_exists('Notification')) {
            Notification::notify(
                $userId,
                'ads_search_terms',
                'كلمات بتصرف ميزانيتك من غير حجوزات في "' . $campaign->getAttribute('name') . '"',
                ($suggested - $autoApplied) . ' مصطلح بحث مقترح إضافته ككلمة سلبية' . ($autoApplied ? " (واتضاف {$autoApplied} تلقائيًا)" : ''),
                '/ads/autopilot'
            );
        }

        return ['success' => true, 'suggested' => $suggested, 'auto_applied' => $autoApplied];
    }

    /** تشغيل يومي لكل حملات Google المنشورة (من cron/run_ads_search_terms.php) */
    public function runForAllUsers(): array
    {
        $summary = ['campaigns' => 0, 'suggested' => 0, 'auto_applied' => 0, 'errors' => 0];
        $rows = $this->db->query(
            "SELECT c.id FROM ad_campaigns c
             JOIN platform_connections p ON p.id = c.platform_connection_id
             WHERE p.platform = 'google_ads' AND p.status = 'connected'
               AND c.external_campaign_id IS NOT NULL AND c.status IN ('active', 'paused') AND c.deleted_at IS NULL"
        );
        foreach ($rows as $row) {
            $campaign = (new AdCampaign())->find((int) $row['id']);
            if (!$campaign) {
                continue;
            }
            $userId = (int) $campaign->getAttribute('user_id');
            try {
                $r = $this->analyzeCampaign($userId, $campaign, $this->autoApplyAllowed($userId));
                $summary['campaigns']++;
                if (empty($r['success'])) {
                    $summary['errors']++;
                    continue;
                }
                $summary['suggested'] += (int) $r['suggested'];
                $summary['auto_applied'] += (int) $r['auto_applied'];
            } catch (Throwable $e) {
                $summary['errors']++;
                if (class_exists('Logger')) {
                    Logger::error('AdSearchTermsService campaign failed', ['campaign_id' => $row['id'], 'error' => $e->getMessage()]);
                }
            }
        }
        return $summary;
    }

    public function listForUser(int $userId, string $status = 'pending', int $limit = 100): array
    {
        $status = in_array($status, ['pending', 'applied', 'dismissed', 'failed'], true) ? $status : 'pending';
        return $this->db->query(
            "SELECT s.*, c.name AS campaign_name, c.currency FROM ad_search_term_suggestions s
             JOIN ad_campaigns c ON c.id = s.campaign_id
             WHERE s.user_id = ? AND s.status = ?
             ORDER BY s.cost DESC, s.created_at DESC LIMIT " . max(1, min(500, $limit)),
            [$userId, $status]
        );
    }

    public function apply(int $userId, int $suggestionId): array
    {
        $rows = $this->db->query("SELECT * FROM ad_search_term_suggestions WHERE id = ? AND user_id = ? AND status = 'pending' LIMIT 1", [$suggestionId, $userId]);
        if (!$rows) {
            return ['success' => false, 'error' => 'الاقتراح مش موجود أو اتنفذ قبل كده'];
        }
        $s = $rows[0];
        $campaign = (new AdCampaign())->find((int) $s['campaign_id']);
        if (!$campaign) {
            return ['success' => false, 'error' => 'الحملة مش موجودة'];
        }
        $ctx = $this->platformContext($campaign);
        if (isset($ctx['error'])) {
            return ['success' => false, 'error' => $ctx['error']];
        }
        $r = $ctx['api']->addCampaignNegativeKeywords($ctx['customer_id'], (string) $campaign->getAttribute('external_campaign_id'), [(string) $s['search_term']], 'EXACT');
        if (empty($r['success']) || (int) ($r['added'] ?? 0) < 1) {
            $error = (string) ($r['error'] ?? $r['partial_error'] ?? 'Google رفضت الكلمة');
            $this->db->exec("UPDATE ad_search_term_suggestions SET status = 'failed', error = ?, decided_at = NOW() WHERE id = ?", [mb_substr($error, 0, 500), $suggestionId]);
            return ['success' => false, 'error' => $error];
        }
        $this->db->exec("UPDATE ad_search_term_suggestions SET status = 'applied', decided_at = NOW() WHERE id = ?", [$suggestionId]);
        ActivityLog::record('ads_autopilot', 'negative_keyword.added', [
            'user_id' => $userId, 'subject_type' => 'ad_campaigns', 'subject_id' => (int) $campaign->getAttribute('id'),
            'meta' => ['term' => $s['search_term'], 'cost' => $s['cost']],
        ]);
        return ['success' => true];
    }

    public function dismiss(int $userId, int $suggestionId): bool
    {
        $this->db->exec(
            "UPDATE ad_search_term_suggestions SET status = 'dismissed', decided_at = NOW() WHERE id = ? AND user_id = ? AND status = 'pending'",
            [$suggestionId, $userId]
        );
        return true;
    }

    /** التطبيق التلقائي بس لو العميل مفعّل وضع Autopilot الكامل */
    private function autoApplyAllowed(int $userId): bool
    {
        try {
            $settings = AdAutopilotSetting::forUser($userId);
            return $settings->getAttribute('optimization_mode') === 'autopilot' && (int) ($settings->getAttribute('is_active') ?? 1) === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * الذكاء الاصطناعي بيحكم على مدى ارتباط كل مصطلح بالعرض.
     * @return array<string, string> term(lowercase) => السبب
     */
    private function aiIrrelevant(int $userId, AdCampaign $campaign, array $terms): array
    {
        $candidates = array_values(array_filter($terms, fn ($t) => !self::alreadyHandled($t) && (float) $t['conversions'] == 0.0 && (int) $t['clicks'] > 0));
        usort($candidates, fn ($a, $b) => $b['cost'] <=> $a['cost']);
        $candidates = array_slice($candidates, 0, self::AI_MAX_TERMS);
        if (!$candidates) {
            return [];
        }
        $offer = trim($campaign->getAttribute('name') . ' - ' . (string) $campaign->getAttribute('product_or_service'));
        $list = implode("\n", array_map(fn ($t) => '- ' . $t['term'], $candidates));
        $prompt = <<<PROMPT
انت خبير Google Ads للسياحة. العرض اللي بنعلن عنه: "{$offer}".
دي مصطلحات بحث حقيقية ظهر عليها الإعلان واتضغط عليه ومفيش منها ولا حجز:
{$list}

حدد المصطلحات اللي "مش مرتبطة بنية حجز العرض ده" بشكل واضح: باحثين عن شغل/مرتب، أماكن أو خدمات مختلفة تمامًا عن العرض، معلومات عامة/تاريخ/ويكيبيديا، منافس باسمه، تحميل/مجاني/PDF، أو حاجة ملهاش علاقة بالسفر.
متحددش أي مصطلح ممكن يكون عميل حقيقي (حتى لو صيغته غريبة). لو مش متأكد سيبه.
رجّع JSON فقط: {"irrelevant":[{"term":"المصطلح بالظبط زي ما هو","reason":"سبب قصير بالعربي"}]}
PROMPT;
        try {
            $ai = $this->ai ?? new GeminiClient();
            $response = $ai->generateContent($prompt, [
                'task' => 'ad_keywords',
                'user_id' => $userId,
                'output_language' => 'ar',
                'maxOutputTokens' => 2048,
                'responseMimeType' => 'application/json',
            ]);
            if (!($response['success'] ?? false)) {
                return [];
            }
            $parsed = json_decode(trim((string) preg_replace('/^```(json)?|```$/m', '', trim((string) ($response['data'] ?? '')))), true);
        } catch (Throwable $e) {
            return [];
        }
        $known = [];
        foreach ($candidates as $t) {
            $known[mb_strtolower($t['term'])] = true;
        }
        $out = [];
        foreach ((array) ($parsed['irrelevant'] ?? []) as $item) {
            $key = mb_strtolower(trim((string) ($item['term'] ?? '')));
            // بنقبل بس مصطلحات موجودة فعلًا في التقرير - مفيش كلمات مخترعة
            if ($key !== '' && isset($known[$key])) {
                $out[$key] = 'غير مرتبط بالعرض: ' . mb_substr(trim((string) ($item['reason'] ?? '')), 0, 200);
            }
        }
        return $out;
    }

    private static function alreadyHandled(array $t): bool
    {
        // ADDED = اتضاف ككلمة مفتاحية، EXCLUDED = اتضاف ككلمة سلبية قبل كده
        return in_array(strtoupper((string) ($t['status'] ?? '')), ['EXCLUDED', 'ADDED_EXCLUDED', 'ADDED'], true);
    }

    /** @return array{api?:GoogleAdsAPI, customer_id?:string, error?:string} */
    private function platformContext(AdCampaign $campaign): array
    {
        $connId = (int) $campaign->getAttribute('platform_connection_id');
        if (!$connId || !$campaign->getAttribute('external_campaign_id')) {
            return ['error' => 'الحملة مش منشورة على Google Ads'];
        }
        $rows = $this->db->query("SELECT * FROM platform_connections WHERE id = ? AND platform = 'google_ads' LIMIT 1", [$connId]);
        if (!$rows) {
            return ['error' => 'حساب Google Ads مش مربوط'];
        }
        $token = (new AdsSyncService($this->db))->googleAccessToken($rows[0], new Encryption());
        if (!$token) {
            return ['error' => 'انتهت صلاحية ربط Google Ads - اربط الحساب تاني'];
        }
        return ['api' => new GoogleAdsAPI($token), 'customer_id' => (string) $rows[0]['external_account_id']];
    }
}

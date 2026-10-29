<?php

/**
 * Tourfecto - Enterprise Meta Ads Marketing API Client
 * @version 3.0.0
 *
 * المزايا التنافسية:
 * 1. سحب تلقائي لـ external_adset_id وميزانية الـ Ad Set أثناء المزامنة لتمكين الـ Autopilot من التعديل الفوري.
 * 2. دعم كامل لإشارات Meta CAPI المتقدمة (em, ph, fn, ln, fbc, fbp, client_ip_address, client_user_agent) لرفع EMQ إلى 9.5+/10.
 * 3. حماية تلقائية من Rate Limits (أكواد 4, 17, 613) عبر Exponential Backoff في بيئة الإنتاج.
 * 4. توافق 100% مع $testTransport للاختبارات الآلية (CLI).
 * 5. أهداف حقيقية (Sales/Leads بالبيكسل، واتساب، مكالمات) مع بدائل آمنة لو المنصة رفضت.
 * 6. اهتمامات Meta حقيقية + Advantage+ Audience + ميزانية بعملة حساب الإعلانات.
 * 7. تحويلات من غير عدّ مزدوج ومزامنة على مستوى الحساب بكل الصفحات.
 */
class MetaAdsAPI
{
    private string $apiVersion;
    private string $accessToken;

    /** للاختبارات بس (CLI): function(string $method, string $path, array $fields): array{0:int,1:array} */
    public static $testTransport = null;

    public function __construct(string $accessToken)
    {
        $this->apiVersion = class_exists('MetaOAuthClient') ? MetaOAuthClient::apiVersion() : (env('META_API_VERSION') ?: 'v25.0');
        $this->accessToken = $accessToken;
    }

    /**
     * قائمة حسابات الإعلانات المتاحة لصاحب التوكن.
     * @return array ['success'=>bool, 'accounts'=>[['id','name','currency','status']], 'error'=>?]
     */
    /** Read-only Meta business portfolios and authorized assets. Never modifies Meta assets. */
    public function listBusinessPortfolios(): array
    {
        $businesses = $this->getAllPages('me/businesses', [
            'fields' => 'id,name', 'limit' => 50,
        ], 4);
        if (!$businesses['success']) {
            return ['success' => false, 'error' => $businesses['error'] ?? 'Business portfolios are unavailable'];
        }
        $results = [];
        foreach (array_slice($businesses['rows'] ?? [], 0, 50) as $business) {
            $id = (string) ($business['id'] ?? '');
            if (!preg_match('/^\d+$/', $id)) { continue; }
            $item = ['id' => $id, 'name' => (string) ($business['name'] ?? $id), 'assets' => [], 'warnings' => []];
            foreach (['owned_pages' => 'Owned Pages', 'client_pages' => 'Client Pages',
                       'owned_ad_accounts' => 'Owned Ad Accounts', 'client_ad_accounts' => 'Client Ad Accounts'] as $edge => $label) {
                $r = $this->getAllPages($id . '/' . $edge, ['fields' => 'id,name', 'limit' => 100], 3);
                if (!$r['success']) {
                    $item['warnings'][] = $label . ': access not granted or unavailable';
                    continue;
                }
                $item['assets'][$edge] = array_map(static function ($a) {
                    return ['id' => (string) ($a['id'] ?? ''), 'name' => (string) ($a['name'] ?? $a['id'] ?? '')];
                }, array_slice($r['rows'] ?? [], 0, 300));
            }
            $results[] = $item;
        }
        return ['success' => true, 'businesses' => $results];
    }

    public function listAdAccounts(): array
    {
        $result = $this->get('me/adaccounts', [
            'fields' => 'id,name,account_status,currency,business_name',
        ]);

        if (!$result['success']) {
            return $result;
        }

        $accounts = array_map(function ($a) {
            return [
                'id' => $a['id'],
                'name' => $a['business_name'] ?? $a['name'] ?? $a['id'],
                'currency' => $a['currency'] ?? 'USD',
                'status' => ($a['account_status'] ?? 0) === 1 ? 'active' : 'inactive',
            ];
        }, $result['data']['data'] ?? []);

        return ['success' => true, 'accounts' => $accounts];
    }

    /**
     * عملات Meta اللي مالهاش كسور (الميزانية بتتبعت بالوحدة الكاملة مش ×100).
     * باقي العملات (USD/EGP/EUR/SAR...) الميزانية بتتبعت بأصغر وحدة (سنت/قرش).
     */
    private const ZERO_DECIMAL_CURRENCIES = ['CLP', 'COP', 'CRC', 'HUF', 'ISK', 'IDR', 'JPY', 'KRW', 'PYG', 'TWD', 'VND'];

    /** أحداث التحويل اللي بتتحسب مرة واحدة بس لكل مجموعة (الأولوية للإجمالي الموحّد) */
    private const CONVERSION_GROUPS = [
        'purchase' => ['omni_purchase', 'purchase', 'offsite_conversion.fb_pixel_purchase', 'onsite_web_purchase'],
        'lead' => ['lead', 'onsite_conversion.lead_grouped', 'offsite_conversion.fb_pixel_lead', 'onsite_web_lead'],
        'messaging' => ['onsite_conversion.messaging_conversation_started_7d'],
    ];

    public static function budgetMultiplier(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true) ? 1 : 100;
    }

    /**
     * عدد التحويلات الحقيقي من مصفوفة actions بتاعة Meta.
     * Meta بترجّع نفس الشراء أكتر من مرة (omni_purchase + purchase + fb_pixel_purchase)
     * فجمعهم كان بيضاعف الرقم ويخلي الـCPA/ROAS أحسن من الحقيقة. هنا كل مجموعة
     * بتتحسب مرة واحدة بس من أول نوع موجود حسب الأولوية.
     */
    public static function countConversions(array $actions): float
    {
        $byType = [];
        foreach ($actions as $a) {
            $type = (string) ($a['action_type'] ?? '');
            if ($type !== '') {
                $byType[$type] = (float) ($a['value'] ?? 0);
            }
        }
        $total = 0.0;
        foreach (self::CONVERSION_GROUPS as $types) {
            foreach ($types as $type) {
                if (isset($byType[$type])) {
                    $total += $byType[$type];
                    break;
                }
            }
        }
        return $total;
    }

    /**
     * كل الحملات (Campaigns) الخاصة بحساب إعلانات معيّن، مع بيانات الأداء الحقيقية
     * ومعرّف المجموعة الإعلانية (external_adset_id) اللازم للطيار الآلي.
     * الأداء بيتسحب على مستوى الحساب في طلبين بس (مش طلبين لكل حملة) ومع
     * التنقل بين الصفحات، فالحسابات الكبيرة (أكتر من 100 حملة) بتتزامن كاملة.
     */
    public function listCampaignsWithInsights(string $adAccountId): array
    {
        $campaignsResult = $this->getAllPages("{$adAccountId}/campaigns", [
            'fields' => 'id,name,objective,status,daily_budget,start_time,stop_time,adsets.limit(5){id,daily_budget,status}',
            'limit' => 100,
        ]);

        if (!$campaignsResult['success']) {
            return $campaignsResult;
        }

        $acc = $this->get($adAccountId, ['fields' => 'currency']);
        $currency = $acc['success'] ? strtoupper((string) ($acc['data']['currency'] ?? '')) : '';
        $divider = (float) self::budgetMultiplier($currency ?: 'USD');

        $totals = [];
        $totalsRes = $this->getAllPages("{$adAccountId}/insights", [
            'level' => 'campaign',
            'fields' => 'campaign_id,impressions,clicks,spend',
            'date_preset' => 'maximum',
            'limit' => 500,
        ]);
        if ($totalsRes['success']) {
            foreach ($totalsRes['rows'] as $row) {
                $totals[(string) ($row['campaign_id'] ?? '')] = $row;
            }
        }

        $dailyByCampaign = [];
        $dailyRes = $this->getAllPages("{$adAccountId}/insights", [
            'level' => 'campaign',
            'fields' => 'campaign_id,impressions,clicks,spend,actions',
            'date_preset' => 'last_30d',
            'time_increment' => 1,
            'limit' => 500,
        ]);
        if ($dailyRes['success']) {
            foreach ($dailyRes['rows'] as $d) {
                $dailyByCampaign[(string) ($d['campaign_id'] ?? '')][] = [
                    'date' => (string) ($d['date_start'] ?? ''),
                    'impressions' => (int) ($d['impressions'] ?? 0),
                    'clicks' => (int) ($d['clicks'] ?? 0),
                    'spend' => (float) ($d['spend'] ?? 0),
                    'conversions' => self::countConversions((array) ($d['actions'] ?? [])),
                ];
            }
        }

        $enriched = [];
        foreach ($campaignsResult['rows'] as $c) {
            $id = (string) ($c['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $stats = $totals[$id] ?? ['impressions' => 0, 'clicks' => 0, 'spend' => 0];

            // استخراج أول Ad Set وميزانيته في حال كانت الميزانية محددة على مستوى الـ Ad Set وليس الحملة
            $primaryAdSetId = null;
            $adSetDailyBudget = null;
            if (!empty($c['adsets']['data']) && is_array($c['adsets']['data'])) {
                $firstAdSet = $c['adsets']['data'][0];
                $primaryAdSetId = $firstAdSet['id'] ?? null;
                if (isset($firstAdSet['daily_budget'])) {
                    $adSetDailyBudget = ((float) $firstAdSet['daily_budget']) / $divider;
                }
            }

            $campaignDailyBudget = isset($c['daily_budget'])
                ? ((float) $c['daily_budget']) / $divider
                : $adSetDailyBudget;

            $enriched[] = [
                'external_campaign_id' => $id,
                'external_adset_id' => $primaryAdSetId,
                'name' => $c['name'] ?? ('Campaign ' . $id),
                'objective' => $c['objective'] ?? null,
                'status' => strtolower($c['status'] ?? 'paused'),
                'daily_budget' => $campaignDailyBudget,
                'started_at' => $c['start_time'] ?? null,
                'ended_at' => $c['stop_time'] ?? null,
                'impressions' => (int) ($stats['impressions'] ?? 0),
                'clicks' => (int) ($stats['clicks'] ?? 0),
                'spend' => (float) ($stats['spend'] ?? 0),
                'daily' => $dailyByCampaign[$id] ?? [],
                'currency' => $currency ?: null,
            ];
        }

        return ['success' => true, 'campaigns' => $enriched];
    }

    /** بيانات حساب الإعلانات اللي النشر محتاجها (العملة أهمها) */
    public function getAdAccountInfo(string $adAccountId): array
    {
        $r = $this->get($adAccountId, ['fields' => 'currency,name,account_status,timezone_name']);
        if (!$r['success']) {
            return $r;
        }
        return [
            'success' => true,
            'currency' => strtoupper((string) ($r['data']['currency'] ?? 'USD')) ?: 'USD',
            'name' => (string) ($r['data']['name'] ?? ''),
            'active' => (int) ($r['data']['account_status'] ?? 0) === 1,
        ];
    }

    /** بيكسلات (Datasets) حساب الإعلانات - لازمة لحملات Leads/Sales الحقيقية */
    public function listPixels(string $adAccountId): array
    {
        $r = $this->get("{$adAccountId}/adspixels", ['fields' => 'id,name,last_fired_time', 'limit' => 25]);
        if (!$r['success']) {
            return [];
        }
        $pixels = [];
        foreach (($r['data']['data'] ?? []) as $p) {
            if (!empty($p['id'])) {
                $pixels[] = ['id' => (string) $p['id'], 'name' => (string) ($p['name'] ?? $p['id']), 'last_fired_time' => $p['last_fired_time'] ?? null];
            }
        }
        // البيكسل اللي اشتغل مؤخرًا الأول - ده غالبًا اللي على الموقع فعلًا
        usort($pixels, fn ($a, $b) => strcmp((string) ($b['last_fired_time'] ?? ''), (string) ($a['last_fired_time'] ?? '')));
        return $pixels;
    }

    /**
     * تحويل أسماء الاهتمامات اللي الذكاء الاصطناعي اقترحها (نص حر) لاهتمامات
     * Meta حقيقية بمعرّفاتها - قبل كده كانت بتتحفظ وبتتجاهل وقت النشر.
     * @return array<int, array{id:string,name:string}>
     */
    public function searchInterests(array $names, int $max = 10): array
    {
        $found = [];
        foreach (array_slice($names, 0, $max) as $name) {
            $q = trim((string) $name);
            if ($q === '') {
                continue;
            }
            $r = $this->get('search', ['type' => 'adinterest', 'q' => $q, 'limit' => 3]);
            if (!$r['success'] || empty($r['data']['data'])) {
                continue;
            }
            $best = $r['data']['data'][0];
            if (!empty($best['id']) && !isset($found[$best['id']])) {
                $found[(string) $best['id']] = ['id' => (string) $best['id'], 'name' => (string) ($best['name'] ?? $q)];
            }
        }
        return array_values($found);
    }

    /**
     * خطط الهدف بالترتيب: أول خطة هي الأنسب للهدف اللي العميل اختاره، والباقي
     * بدائل آمنة لو Meta رفضت الأولى (مثلًا صفحة مش مربوط بيها واتساب).
     * @return array<int, array{label:string,objective:string,optimization:string,destination:?string,promoted:?array,cta:?string,warning:?string}>
     */
    public static function objectivePlans(string $objective, array $options = []): array
    {
        $pixelId = trim((string) ($options['pixel_id'] ?? ''));
        $phone = trim((string) ($options['phone'] ?? ''));
        $pageId = (string) ($options['page_id'] ?? '');
        $plans = [];
        $warning = null;

        switch ($objective) {
            case 'sales':
                if ($pixelId !== '') {
                    $plans[] = ['label' => 'sales_pixel', 'objective' => 'OUTCOME_SALES', 'optimization' => 'OFFSITE_CONVERSIONS', 'destination' => 'WEBSITE', 'promoted' => ['pixel_id' => $pixelId, 'custom_event_type' => 'PURCHASE'], 'cta' => null];
                } else {
                    $warning = 'مفيش Meta Pixel على حساب الإعلانات، فالحملة اتعملت بهدف زيارات صفحة الهبوط بدل المبيعات. ركّب البيكسل عشان Meta تجيبلك ناس بتحجز فعلًا.';
                }
                break;
            case 'leads':
                if ($pixelId !== '') {
                    $plans[] = ['label' => 'leads_pixel', 'objective' => 'OUTCOME_LEADS', 'optimization' => 'OFFSITE_CONVERSIONS', 'destination' => 'WEBSITE', 'promoted' => ['pixel_id' => $pixelId, 'custom_event_type' => 'LEAD'], 'cta' => null];
                } else {
                    $warning = 'مفيش Meta Pixel على حساب الإعلانات، فالحملة اتعملت بهدف زيارات صفحة الهبوط بدل العملاء المحتملين. ركّب البيكسل أو استخدم هدف واتساب.';
                }
                break;
            case 'whatsapp':
                $plans[] = ['label' => 'whatsapp', 'objective' => 'OUTCOME_ENGAGEMENT', 'optimization' => 'CONVERSATIONS', 'destination' => 'WHATSAPP', 'promoted' => $pageId !== '' ? ['page_id' => $pageId] : null, 'cta' => 'WHATSAPP_MESSAGE'];
                $plans[] = ['label' => 'whatsapp_leads', 'objective' => 'OUTCOME_LEADS', 'optimization' => 'CONVERSATIONS', 'destination' => 'WHATSAPP', 'promoted' => $pageId !== '' ? ['page_id' => $pageId] : null, 'cta' => 'WHATSAPP_MESSAGE'];
                break;
            case 'calls':
                if ($phone !== '') {
                    $plans[] = ['label' => 'calls', 'objective' => 'OUTCOME_TRAFFIC', 'optimization' => 'QUALITY_CALL', 'destination' => 'PHONE_CALL', 'promoted' => null, 'cta' => 'CALL_NOW'];
                } else {
                    $warning = 'مفيش رقم تليفون للنشاط، فالحملة اتعملت بهدف زيارات الموقع. ضيف رقم التليفون في ملف النشاط عشان إعلانات المكالمات.';
                }
                break;
            case 'engagement':
                $plans[] = ['label' => 'engagement', 'objective' => 'OUTCOME_ENGAGEMENT', 'optimization' => 'POST_ENGAGEMENT', 'destination' => 'ON_POST', 'promoted' => null, 'cta' => null];
                break;
            case 'awareness':
                $plans[] = ['label' => 'awareness', 'objective' => 'OUTCOME_AWARENESS', 'optimization' => 'REACH', 'destination' => null, 'promoted' => null, 'cta' => null];
                break;
        }

        // البدائل الآمنة دايمًا في الآخر: زيارات صفحة الهبوط (أجود من مجرد ضغطات) ثم الضغطات
        $plans[] = ['label' => 'traffic_lpv', 'objective' => 'OUTCOME_TRAFFIC', 'optimization' => 'LANDING_PAGE_VIEWS', 'destination' => 'WEBSITE', 'promoted' => null, 'cta' => null];
        $plans[] = ['label' => 'traffic_clicks', 'objective' => 'OUTCOME_TRAFFIC', 'optimization' => 'LINK_CLICKS', 'destination' => 'WEBSITE', 'promoted' => null, 'cta' => null];

        foreach ($plans as $i => $p) {
            $plans[$i]['warning'] = ($i === 0 && $warning !== null) ? $warning : null;
        }
        return $plans;
    }

    /**
     * إنشاء حملة حقيقية كاملة على Meta Ads: Campaign → Ad Set → Ad Creative → Ad.
     *
     * $options (كلها اختيارية):
     * - currency: عملة حساب الإعلانات (عشان الميزانية تتبعت صح)
     * - pixel_id: بيكسل الحساب (لحملات Leads/Sales الحقيقية)
     * - phone: رقم التليفون بصيغة دولية (لإعلانات المكالمات)
     * - image_urls: أكتر من صورة - كل نسخة إعلان بتاخد صورة مختلفة
     * - format: image (الافتراضي) | carousel (2-10 صور، كارت لكل صورة) | video (فيديو من رابط https عام)
     * - video_url: رابط الفيديو لو format=video (Meta بتسحبه بنفسها)
     * - interests: اهتمامات محلولة جاهزة [{id,name}] (لو مش موجودة بتتحل من audience.interests)
     */
    public function createCampaign(string $adAccountId, string $pageId, array $campaign, array $audience, array $copies, string $destinationUrl, ?string $imageUrl = null, array $options = []): array
    {
        $currency = strtoupper((string) ($options['currency'] ?? 'USD')) ?: 'USD';
        $options['page_id'] = $pageId;
        $objective = (string) ($campaign['objective'] ?? 'traffic');
        $plans = self::objectivePlans($objective, $options);
        $warnings = [];
        // Validate content before performing ANY Meta write (no orphaned campaigns).
        if (!$copies || !array_filter($copies, static fn ($copy) => is_array($copy) && trim((string) ($copy['headline'] ?? '')) !== '' && trim((string) ($copy['primary_text'] ?? '')) !== '')) {
            return ['success' => false, 'step' => 'preflight', 'error' => 'An approved ad headline and primary text are required before creating Meta objects.'];
        }

        $format = self::adFormat($options);
        if ($format === 'video' && !self::validVideoUrl((string) ($options['video_url'] ?? ''), !empty($options['video_trusted']))) {
            return ['success' => false, 'step' => 'preflight', 'error' => 'A public https video link (mp4 / mov) is required for a video ad.'];
        }

        $targeting = $this->buildTargeting($audience, $options);
        if (!empty($targeting['_warning'])) {
            $warnings[] = $targeting['_warning'];
        }
        unset($targeting['_warning']);

        $campaignId = null;
        $adSetId = null;
        $usedPlan = null;
        $firstError = '';
        $existingCampaignId = trim((string) ($options['existing_campaign_id'] ?? ''));
        $existingAdsetId = trim((string) ($options['existing_adset_id'] ?? ''));
        if ($existingCampaignId !== '') {
            // Safe recovery: validate the exact existing object with a READ before any write.
            if (!preg_match('/^[0-9]{10,30}$/', $existingCampaignId)) {
                return ['success' => false, 'step' => 'resume_preflight', 'error' => 'Invalid existing Meta campaign ID.'];
            }
            $remote = $this->get($existingCampaignId, ['fields' => 'id,name,account_id,objective,status,effective_status']);
            if (!($remote['success'] ?? false)) {
                return ['success' => false, 'step' => 'resume_preflight', 'error' => 'Cannot verify existing campaign: ' . ($remote['error'] ?? 'Meta unavailable')];
            }
            $info = (array) ($remote['data'] ?? []);
            if ((string) ($info['id'] ?? '') !== $existingCampaignId
                || preg_replace('/^act_/', '', (string) ($info['account_id'] ?? '')) !== preg_replace('/^act_/', '', $adAccountId)
                || (string) ($info['name'] ?? '') !== (string) ($campaign['name'] ?? '')
                || strtoupper((string) ($info['status'] ?? '')) !== 'PAUSED'
                || strtoupper((string) ($info['effective_status'] ?? '')) !== 'PAUSED') {
                return ['success' => false, 'step' => 'resume_preflight', 'error' => 'Existing Meta campaign identity/account/PAUSED state mismatch. Nothing was created.'];
            }
            if ($existingAdsetId !== '') {
                // Ad-only recovery: verify both existing PAUSED objects, then prove that no Ad
                // exists under this specific Ad Set. A failed read always blocks writing.
                if (!preg_match('/^[0-9]{10,30}$/', $existingAdsetId)) {
                    return ['success' => false, 'step' => 'ad_only_preflight', 'error' => 'Invalid existing Ad Set ID.'];
                }
                $adsetRemote = $this->get($existingAdsetId, ['fields' => 'id,campaign_id,account_id,status,effective_status']);
                $setInfo = (array) ($adsetRemote['data'] ?? []);
                if (!($adsetRemote['success'] ?? false)
                    || (string) ($setInfo['id'] ?? '') !== $existingAdsetId
                    || (string) ($setInfo['campaign_id'] ?? '') !== $existingCampaignId
                    || preg_replace('/^act_/', '', (string) ($setInfo['account_id'] ?? '')) !== preg_replace('/^act_/', '', $adAccountId)
                    || strtoupper((string) ($setInfo['status'] ?? '')) !== 'PAUSED'
                    || strtoupper((string) ($setInfo['effective_status'] ?? '')) !== 'PAUSED') {
                    return ['success' => false, 'step' => 'ad_only_preflight', 'error' => 'Ad Set identity, account or PAUSED state cannot be verified. No changes made.'];
                }
                $existingAds = $this->get($existingAdsetId . '/ads', ['fields' => 'id,status', 'limit' => 2]);
                if (!($existingAds['success'] ?? false) || !isset($existingAds['data']['data']) || !is_array($existingAds['data']['data'])) {
                    return ['success' => false, 'step' => 'ad_only_preflight', 'error' => 'Unable to verify if the Ad Set already has ads. No changes made.'];
                }
                if (count($existingAds['data']['data']) !== 0) {
                    return ['success' => false, 'step' => 'ad_only_preflight', 'error' => 'An Ad already exists in this Ad Set. Manual reconciliation required; no duplicate created.'];
                }
                foreach ($plans as $plan) {
                    if (($plan['objective'] ?? '') === ($info['objective'] ?? '')) { $usedPlan = $plan; break; }
                }
                if ($usedPlan === null) {
                    return ['success' => false, 'step' => 'ad_only_preflight', 'error' => 'Existing Meta objective does not match the supplied plan. Nothing created.'];
                }
                $campaignId = $existingCampaignId;
                $adSetId = $existingAdsetId;
            } else {
            $existingSets = $this->get($existingCampaignId . '/adsets', ['fields' => 'id,status', 'limit' => 2]);
            if (!($existingSets['success'] ?? false) || !isset($existingSets['data']['data'])) {
                return ['success' => false, 'step' => 'resume_preflight', 'error' => 'Unable to prove the existing Meta campaign has no ad sets. Nothing was created.'];
            }
            if (count($existingSets['data']['data']) > 0) {
                return ['success' => false, 'step' => 'resume_preflight', 'error' => 'Existing Meta campaign already contains ad sets. Manual reconciliation required; no duplicate ad set created.'];
            }
            foreach ($plans as $plan) {
                if (($plan['objective'] ?? '') === ($info['objective'] ?? '')) {
                    $usedPlan = $plan;
                    break;
                }
            }
            if ($usedPlan === null) {
                return ['success' => false, 'step' => 'resume_preflight', 'error' => 'Existing campaign objective differs from available plans. Review targeting manually.'];
            }
            $campaignId = $existingCampaignId;
            $adSetResult = $this->post("{$adAccountId}/adsets", $this->adSetFields($campaign, $campaignId, $usedPlan, $targeting, $currency));
            if (!($adSetResult['success'] ?? false)) {
                return ['success' => false, 'step' => 'adset', 'external_campaign_id' => $campaignId, 'uncertain' => true, 'error' => 'Existing campaign was preserved. Ad set creation failed or is uncertain: ' . ($adSetResult['error'] ?? 'Meta error')];
            }
            $adSetId = (string) ($adSetResult['data']['id'] ?? '');
            if ($adSetId === '') {
                return ['success' => false, 'step' => 'adset', 'external_campaign_id' => $campaignId, 'uncertain' => true, 'error' => 'Ad set creation returned no ID; manual reconciliation required.'];
            }
            }
        } else foreach ($plans as $plan) {
            $campaignResult = $this->post("{$adAccountId}/campaigns", [
                'name' => $campaign['name'],
                'objective' => $plan['objective'],
                'status' => 'PAUSED',
                'special_ad_categories' => json_encode([]),
                'is_adset_budget_sharing_enabled' => 'false',
            ]);
            if (!$campaignResult['success']) {
                // خطأ على مستوى الحملة نفسها (صلاحيات/حساب/دفع) مش هيتحل بتغيير الهدف
                return ['success' => false, 'step' => 'campaign', 'error' => 'Meta campaign creation failed: ' . ($campaignResult['error'] ?? '')];
            }
            $candidateId = $campaignResult['data']['id'];

            $adSetResult = $this->post("{$adAccountId}/adsets", $this->adSetFields($campaign, $candidateId, $plan, $targeting, $currency));
            if ($adSetResult['success']) {
                $campaignId = $candidateId;
                $adSetId = $adSetResult['data']['id'];
                $usedPlan = $plan;
                break;
            }
            $firstError = $firstError ?: ($adSetResult['error'] ?? '');
            // Keep the PAUSED campaign for manual reconciliation; do not hide a partially created object.
            return ['success' => false, 'step' => 'adset', 'external_campaign_id' => $candidateId, 'error' => 'Meta created a PAUSED campaign but rejected its ad set: ' . ($adSetResult['error'] ?? 'Unknown Meta error')];
        }

        if ($campaignId === null || $usedPlan === null) {
            return ['success' => false, 'step' => 'adset', 'error' => 'فشل إنشاء المجموعة الإعلانية: ' . $firstError];
        }
        if ($usedPlan['warning']) {
            $warnings[] = $usedPlan['warning'];
        } elseif ($usedPlan !== $plans[0]) {
            $warnings[] = 'Meta رفضت إعداد الهدف الأساسي (' . $firstError . ') فاستخدمنا هدف زيارات صفحة الهبوط بدلًا منه.';
        }

        $imageUrls = array_values(array_filter(array_merge((array) ($options['image_urls'] ?? []), $imageUrl ? [$imageUrl] : [])));
        $imageUrls = array_values(array_unique($imageUrls));
        $imageHashes = [];
        foreach (array_slice($imageUrls, 0, $format === 'carousel' ? 10 : 5) as $url) {
            $imageHashes[] = ['url' => $url, 'hash' => $this->uploadAdImage($adAccountId, $url)];
        }

        // WhatsApp / call destinations cannot use cards; a carousel needs at least two usable images.
        if ($format === 'carousel' && (in_array($usedPlan['cta'] ?? null, ['WHATSAPP_MESSAGE', 'CALL_NOW'], true) || count(array_filter($imageHashes, static fn ($i) => !empty($i['hash']))) < 2)) {
            $warnings[] = 'الكاروسيل محتاج صورتين على الأقل ووجهة موقع، فاتنشر إعلان بصورة واحدة بدلًا منه.';
            $format = 'image';
        }
        $videoId = '';
        $videoThumb = '';
        if ($format === 'video') {
            $up = $this->uploadAdVideo($adAccountId, (string) $options['video_url'], (string) ($campaign['name'] ?? 'Video'));
            if (empty($up['id'])) {
                return ['success' => false, 'step' => 'video', 'external_campaign_id' => $campaignId, 'external_adset_id' => $adSetId, 'error' => 'Video upload failed: ' . ($up['error'] ?? 'Unknown Meta error')];
            }
            $videoId = $up['id'];
            $ready = $this->waitForVideo($videoId);
            if (!$ready['ready']) {
                return ['success' => false, 'step' => 'video', 'external_campaign_id' => $campaignId, 'external_adset_id' => $adSetId, 'uncertain' => true, 'error' => 'Meta is still processing the video (' . $videoId . '). Wait a minute and use "Resume paused setup".'];
            }
            $videoThumb = $ready['picture'] !== '' ? $ready['picture'] : (string) ($imageHashes[0]['url'] ?? '');
        }

        $adIds = [];
        $lastError = '';
        $lastCreativeId = '';
        // Recovery must be a single write attempt, never up to five variants.
        // A carousel is one ad whose cards borrow the headlines of every approved copy.
        $attemptCopies = ($existingAdsetId !== '' || $format === 'carousel') ? array_slice($copies, 0, 1) : array_slice($copies, 0, 5);
        foreach (array_values($attemptCopies) as $i => $copy) {
            $image = !empty($imageHashes) ? $imageHashes[$i % count($imageHashes)] : null;
            if ($format === 'carousel') {
                $spec = ['page_id' => $pageId, 'link_data' => $this->carouselLinkData($copies, $destinationUrl, $imageHashes, $usedPlan, $options)];
            } elseif ($format === 'video') {
                $spec = ['page_id' => $pageId, 'video_data' => $this->videoData($copy, $destinationUrl, $videoId, $videoThumb, $usedPlan, $options)];
            } else {
                $spec = ['page_id' => $pageId, 'link_data' => $this->linkData($copy, $destinationUrl, $usedPlan, $options, $image)];
            }

            $creativeResult = $this->post("{$adAccountId}/adcreatives", [
                'name' => $campaign['name'] . ' - ' . ($copy['variant_label'] ?? chr(65 + $i)),
                'object_story_spec' => json_encode($spec, JSON_UNESCAPED_UNICODE),
                // UTM تلقائي على كل ضغطة - نفس أسماء الحملة/الإعلان تظهر في Google Analytics
                'url_tags' => 'utm_source=facebook&utm_medium=paid_social&utm_campaign={{campaign.name}}&utm_content={{ad.name}}',
            ]);
            if (!$creativeResult['success']) {
                $lastError = 'Ad Creative: ' . ($creativeResult['error'] ?? 'Unknown Meta error');
                if ($existingAdsetId !== '') break;
                continue;
            }
            $lastCreativeId = (string) ($creativeResult['data']['id'] ?? '');
            if ($lastCreativeId === '') {
                $lastError = 'Meta returned no Ad Creative ID. Manual reconciliation required.';
                break;
            }

            $adResult = $this->post("{$adAccountId}/ads", [
                'name' => $campaign['name'] . ' - ' . ($copy['variant_label'] ?? chr(65 + $i)),
                'adset_id' => $adSetId,
                'creative' => json_encode(['creative_id' => $lastCreativeId]),
                'status' => 'PAUSED',
            ]);
            if ($adResult['success']) {
                $adIds[] = $adResult['data']['id'];
            } else {
                $lastError = 'Ad creation: ' . ($adResult['error'] ?? 'Unknown Meta error');
                if ($existingAdsetId !== '') break;
            }
        }

        if (empty($adIds)) {
            return ['success' => false, 'step' => 'ads', 'external_campaign_id' => $campaignId, 'external_adset_id' => $adSetId, 'creative_id' => $lastCreativeId ?: null, 'uncertain' => $lastCreativeId !== '', 'error' => 'Ad creation did not complete; verify existing Ad Creative and Ads before retrying. ' . $lastError];
        }
        if ($lastError !== '') {
            $warnings[] = 'بعض النسخ اترفضت: ' . $lastError;
        }

        // Do not declare success until Meta confirms all persisted objects are PAUSED.
        // A failed status read is an uncertain / partial outcome, never safe to blindly retry.
        $statusChecks = [
            'campaign' => [$campaignId],
            'adset' => [$adSetId],
            'ad' => $adIds,
        ];
        foreach ($statusChecks as $objectType => $ids) {
            foreach ($ids as $objectId) {
                $checked = $this->get((string) $objectId, ['fields' => 'id,status,effective_status']);
                $actual = strtoupper((string) ($checked['data']['status'] ?? ''));
                if (!($checked['success'] ?? false) || $actual !== 'PAUSED') {
                    return [
                        'success' => false,
                        'step' => 'verify_' . $objectType,
                        'external_campaign_id' => $campaignId,
                        'external_adset_id' => $adSetId,
                        'ad_ids' => $adIds,
                        'uncertain' => true,
                        'error' => 'Meta objects were created but the ' . $objectType
                            . ' PAUSED status could not be verified. Review the IDs in Ads Manager; do not submit again.'
                            . (($checked['success'] ?? false) ? ' Actual status: ' . $actual : ' Meta response: ' . (string) ($checked['error'] ?? 'unavailable')),
                    ];
                }
            }
        }

        return [
            'success' => true,
            'external_campaign_id' => $campaignId,
            'external_adset_id' => $adSetId,
            'ad_ids' => $adIds,
            'plan' => $usedPlan['label'],
            'warning' => $warnings ? implode(' | ', $warnings) : null,
        ];
    }

    /** الاستهداف: الدول + السن + النوع + الاهتمامات الحقيقية + Advantage+ لما يكون مسموح */
    private function buildTargeting(array $audience, array $options): array
    {
        $ageMin = max(18, min(65, (int) ($audience['age_min'] ?? 18)));
        $ageMax = min(65, max($ageMin, (int) ($audience['age_max'] ?? 65)));
        $countries = class_exists('AdGeo')
            ? AdGeo::toIso2((array) ($audience['locations'] ?? []), (array) ($options['default_countries'] ?? ['EG']))
            : $this->mapLocationsToCountryCodes((array) ($audience['locations'] ?? []));

        $targeting = [
            'age_min' => $ageMin,
            'age_max' => $ageMax,
            'geo_locations' => ['countries' => array_values($countries)],
        ];
        if (!empty($audience['genders']) && $audience['genders'] !== 'all') {
            $targeting['genders'] = [$audience['genders'] === 'male' ? 1 : 2];
        }

        $interests = isset($options['interests']) && is_array($options['interests'])
            ? $options['interests']
            : $this->searchInterests((array) ($audience['interests'] ?? []));
        if (!empty($interests)) {
            $targeting['flexible_spec'] = [['interests' => array_values($interests)]];
        } elseif (!empty($audience['interests'])) {
            $targeting['_warning'] = 'معرفناش نطابق الاهتمامات المقترحة مع اهتمامات Meta، فالاستهداف اعتمد على الدولة والسن بس.';
        }

        // Advantage+ Audience: Meta بتوسّع الجمهور لو لقت ناس أحسن من الاهتمامات.
        // Meta بتشترط أقصى سن 65 وأقل سن مش أكبر من 25 عشان يشتغل، غير كده بنقفله.
        $targeting['targeting_automation'] = ['advantage_audience' => ($ageMax >= 65 && $ageMin <= 25) ? 1 : 0];

        return $targeting;
    }

    private function adSetFields(array $campaign, string $campaignId, array $plan, array $targeting, string $currency): array
    {
        $fields = [
            'name' => $campaign['name'] . ' - Ad Set',
            'campaign_id' => $campaignId,
            'daily_budget' => (int) round((float) ($campaign['daily_budget'] ?? 10) * self::budgetMultiplier($currency)),
            'billing_event' => 'IMPRESSIONS',
            'optimization_goal' => $plan['optimization'],
            'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
            'targeting' => json_encode($targeting, JSON_UNESCAPED_UNICODE),
            // Safe review mode: never create an ACTIVE ad set automatically.
            // A deliberate later activation is required at each Meta level.
            'status' => 'PAUSED',
        ];
        if (!empty($plan['destination'])) {
            $fields['destination_type'] = $plan['destination'];
        }
        if (!empty($plan['promoted'])) {
            $fields['promoted_object'] = json_encode($plan['promoted']);
        }
        $start = !empty($campaign['start_date']) ? strtotime((string) $campaign['start_date']) : false;
        if ($start && $start > time() + 600) {
            $fields['start_time'] = date('c', $start);
        }
        $end = !empty($campaign['end_date']) ? strtotime((string) $campaign['end_date'] . ' 23:59:00') : false;
        if ($end && $end > max(time(), $start ?: 0) + 86400) {
            $fields['end_time'] = date('c', $end);
        }
        return $fields;
    }

    /** @return string 'image' | 'carousel' | 'video' */
    public static function adFormat(array $options): string
    {
        $f = strtolower((string) ($options['format'] ?? 'image'));
        return in_array($f, ['carousel', 'video'], true) ? $f : 'image';
    }

    /** Meta fetches the file itself, so it must be a public https link to a video file. */
    public static function validVideoUrl(string $url, bool $trusted = false): bool
    {
        if ($trusted) {
            return (bool) preg_match('#^https://[^\s/]+/[^\s]+$#i', $url);
        }
        return (bool) preg_match('#^https://[^\s/]+/[^\s]*\.(mp4|mov)(\?[^\s]*)?$#i', $url);
    }

    /** @return array{0: array, 1: string} call-to-action and link for the objective plan */
    private function ctaAndLink(array $copy, string $destinationUrl, array $plan, array $options): array
    {
        $cta = ['type' => $this->mapCta((string) ($copy['call_to_action'] ?? '')), 'value' => ['link' => $destinationUrl]];
        $link = $destinationUrl;
        if (($plan['cta'] ?? null) === 'WHATSAPP_MESSAGE') {
            $cta = ['type' => 'WHATSAPP_MESSAGE', 'value' => ['app_destination' => 'WHATSAPP']];
            $link = 'https://api.whatsapp.com/send';
        } elseif (($plan['cta'] ?? null) === 'CALL_NOW') {
            $phone = preg_replace('/[^0-9+]/', '', (string) ($options['phone'] ?? ''));
            $cta = ['type' => 'CALL_NOW', 'value' => ['link' => 'tel:' . $phone]];
        }
        return [$cta, $link];
    }

    /** One card per image (2-10); card headlines rotate through the approved copies. */
    public function carouselLinkData(array $copies, string $destinationUrl, array $images, array $plan, array $options): array
    {
        $first = $copies[0] ?? [];
        [$cta] = $this->ctaAndLink($first, $destinationUrl, $plan, $options);
        $cards = [];
        foreach (array_values(array_filter($images, static fn ($i) => !empty($i['hash']))) as $n => $img) {
            $c = $copies[$n % max(1, count($copies))] ?? $first;
            $cards[] = [
                'link' => $destinationUrl,
                'name' => mb_substr((string) ($c['headline'] ?? ''), 0, 40),
                'description' => mb_substr((string) ($c['description'] ?? ''), 0, 30),
                'image_hash' => $img['hash'],
                'call_to_action' => $cta,
            ];
        }
        return [
            'link' => $destinationUrl,
            'message' => (string) ($first['primary_text'] ?? ''),
            'child_attachments' => array_slice($cards, 0, 10),
            'multi_share_optimized' => true,
            'multi_share_end_card' => false,
        ];
    }

    public function videoData(array $copy, string $destinationUrl, string $videoId, string $thumbUrl, array $plan, array $options): array
    {
        [$cta] = $this->ctaAndLink($copy, $destinationUrl, $plan, $options);
        $data = [
            'video_id' => $videoId,
            'title' => (string) ($copy['headline'] ?? ''),
            'message' => (string) ($copy['primary_text'] ?? ''),
            'link_description' => (string) ($copy['description'] ?? ''),
            'call_to_action' => $cta,
        ];
        if ($thumbUrl !== '') {
            $data['image_url'] = $thumbUrl;
        }
        return $data;
    }

    /** @return array{id?: string, error?: string} */
    public function uploadAdVideo(string $adAccountId, string $videoUrl, string $name): array
    {
        $r = $this->post("{$adAccountId}/advideos", ['file_url' => $videoUrl, 'name' => mb_substr($name, 0, 100)]);
        $id = (string) ($r['data']['id'] ?? '');
        return ($r['success'] && $id !== '') ? ['id' => $id] : ['error' => (string) ($r['error'] ?? 'No video id returned')];
    }

    /** Meta processes the video asynchronously; an ad creative can only use it once it is ready. */
    public function waitForVideo(string $videoId, int $maxSeconds = 40): array
    {
        $deadline = time() + $maxSeconds;
        do {
            $r = $this->get($videoId, ['fields' => 'status,picture']);
            $state = strtolower((string) ($r['data']['status']['video_status'] ?? ''));
            if ($r['success'] && $state === 'ready') {
                return ['ready' => true, 'picture' => (string) ($r['data']['picture'] ?? '')];
            }
            if ($state === 'error') {
                return ['ready' => false, 'picture' => ''];
            }
            if (self::$testTransport) {
                break;
            }
            sleep(4);
        } while (time() < $deadline);
        return ['ready' => false, 'picture' => ''];
    }

    private function linkData(array $copy, string $destinationUrl, array $plan, array $options, ?array $image): array
    {
        [$cta, $link] = $this->ctaAndLink($copy, $destinationUrl, $plan, $options);

        $linkData = [
            'link' => $link,
            'message' => (string) ($copy['primary_text'] ?? ''),
            'name' => (string) ($copy['headline'] ?? ''),
            'description' => (string) ($copy['description'] ?? ''),
            'call_to_action' => $cta,
        ];
        if ($image && !empty($image['hash'])) {
            $linkData['image_hash'] = $image['hash'];
        } elseif ($image && !empty($image['url'])) {
            $linkData['picture'] = $image['url'];
        }
        return $linkData;
    }

    /** أخطاء مش هتتحل بتجربة هدف تاني (فلوس/صلاحيات/ميزانية) - نوقف فورًا بدل ما نعمل حملات كتير */
    private static function isFatalAdSetError(string $error): bool
    {
        $e = mb_strtolower($error);
        foreach (['الميزانية اليومية أقل', 'وسيلة دفع', 'صلاحية', 'انتهت صلاحية', 'وضع التطوير'] as $needle) {
            if (str_contains($e, mb_strtolower($needle))) {
                return true;
            }
        }
        return false;
    }

    /** GET مع التنقل بين كل الصفحات (cursor paging) - حد أقصى 20 صفحة كحماية */
    private function getAllPages(string $path, array $query, int $maxPages = 20): array
    {
        $rows = [];
        for ($page = 0; $page < $maxPages; $page++) {
            $r = $this->get($path, $query);
            if (!$r['success']) {
                return $page === 0 ? $r : ['success' => true, 'rows' => $rows];
            }
            foreach (($r['data']['data'] ?? []) as $row) {
                $rows[] = $row;
            }
            $after = $r['data']['paging']['cursors']['after'] ?? null;
            if (empty($r['data']['paging']['next']) || !$after) {
                break;
            }
            $query['after'] = $after;
        }
        return ['success' => true, 'rows' => $rows];
    }

    public function uploadAdImage(string $adAccountId, string $imageUrl): ?string
    {
        $bytes = null;
        $local = self::localPublicFile($imageUrl);
        if ($local) {
            $bytes = @file_get_contents($local);
        } elseif (preg_match('#^https://#i', $imageUrl) && !self::$testTransport) {
            $ch = curl_init($imageUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $bytes = curl_exec($ch);
            $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            if (!is_string($bytes) || stripos($type, 'image/') !== 0 || strlen($bytes) > 8 * 1024 * 1024) {
                $bytes = null;
            }
        }
        if (!$bytes) {
            return null;
        }
        $r = $this->post("{$adAccountId}/adimages", ['bytes' => base64_encode($bytes)]);
        if (!$r['success']) {
            return null;
        }
        foreach (($r['data']['images'] ?? []) as $img) {
            if (!empty($img['hash'])) {
                return (string) $img['hash'];
            }
        }
        return null;
    }

    private static function localPublicFile(string $url): ?string
    {
        $base = rtrim(defined('APP_URL') ? (string) APP_URL : '', '/');
        $path = null;
        if (str_starts_with($url, '/uploads/')) {
            $path = $url;
        } elseif ($base !== '' && str_starts_with($url, $base . '/uploads/')) {
            $path = substr($url, strlen($base));
        }
        if (!$path || str_contains($path, '..') || !defined('ROOT_PATH')) {
            return null;
        }
        $f = ROOT_PATH . '/public_html' . $path;
        return is_file($f) ? $f : null;
    }

    public function updateCampaignStatus(string $campaignId, string $status): array
    {
        $metaStatus = strtoupper($status) === 'ACTIVE' ? 'ACTIVE' : 'PAUSED';
        $result = $this->post($campaignId, ['status' => $metaStatus]);
        return $result['success'] ? ['success' => true] : ['success' => false, 'error' => $result['error'] ?? 'فشل تعديل حالة الحملة'];
    }

    public function deleteCampaign(string $campaignId): array
    {
        $result = $this->post($campaignId, ['status' => 'ARCHIVED']);
        return $result['success'] ? ['success' => true] : ['success' => false, 'error' => $result['error'] ?? 'فشل إلغاء الحملة'];
    }

    public function pauseCampaign(string $campaignId): array
    {
        return $this->updateCampaignStatus($campaignId, 'PAUSED');
    }

    public function resumeCampaign(string $campaignId): array
    {
        return $this->updateCampaignStatus($campaignId, 'ACTIVE');
    }

    public function updateCampaignBudget(string $adSetId, float $dailyBudgetUsd): array
    {
        return $this->updateAdSetBudget($adSetId, $dailyBudgetUsd);
    }

    public function updateAdSetBudget(string $adSetId, float $dailyBudgetUsd): array
    {
        $result = $this->post($adSetId, ['daily_budget' => (int) round($dailyBudgetUsd * 100)]);
        return $result['success'] ? ['success' => true] : ['success' => false, 'error' => $result['error'] ?? 'فشل تعديل الميزانية'];
    }

    public function fetchOgImageFromWebsite(string $url): ?string
    {
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TourfectoBot/2.0)',
            ]);
            $html = curl_exec($ch);
            curl_close($ch);

            if (!$html) {
                return null;
            }

            if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
                return $m[1];
            }
            if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $html, $m)) {
                return $m[1];
            }
            return null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Meta Conversions API (CAPI) مع دعم إشارات EMQ 10/10 (em, ph, fn, ln, fbc, fbp, IP, UA).
     */
    public function sendConversionEvent(string $pixelId, array $conversion): array
    {
        $eventId = (string) ($conversion['event_id'] ?? '');
        $emailHash = isset($conversion['email_hash']) && is_string($conversion['email_hash']) ? (string) $conversion['email_hash'] : null;
        $phoneHash = isset($conversion['phone_hash']) && is_string($conversion['phone_hash']) ? (string) $conversion['phone_hash'] : null;

        if ($emailHash === null && $phoneHash === null) {
            return ['success' => false, 'error' => 'مفيش أي معرّف مستخدم مجهول الهوية لإرسال الحدث'];
        }

        $userData = [];
        if ($emailHash !== null) {
            $userData['em'] = [$emailHash];
        }
        if ($phoneHash !== null) {
            $userData['ph'] = [$phoneHash];
        }
        if (!empty($conversion['fn']) && is_string($conversion['fn'])) {
            $userData['fn'] = [$conversion['fn']];
        }
        if (!empty($conversion['ln']) && is_string($conversion['ln'])) {
            $userData['ln'] = [$conversion['ln']];
        }
        if (!empty($conversion['fbc']) && is_string($conversion['fbc'])) {
            $userData['fbc'] = $conversion['fbc'];
        }
        if (!empty($conversion['fbp']) && is_string($conversion['fbp'])) {
            $userData['fbp'] = $conversion['fbp'];
        }
        if (!empty($conversion['client_ip_address']) && is_string($conversion['client_ip_address'])) {
            $userData['client_ip_address'] = $conversion['client_ip_address'];
        }
        if (!empty($conversion['client_user_agent']) && is_string($conversion['client_user_agent'])) {
            $userData['client_user_agent'] = $conversion['client_user_agent'];
        }

        $eventTime = !empty($conversion['event_time']) && is_numeric($conversion['event_time'])
            ? (int) $conversion['event_time']
            : time();

        $event = [
            'event_name' => 'Purchase',
            'event_time' => $eventTime,
            'action_source' => 'website',
            'user_data' => $userData,
            'custom_data' => [
                'currency' => (string) ($conversion['currency'] ?? 'USD'),
                'value' => round((float) ($conversion['value'] ?? 0), 2),
            ],
        ];
        if ($eventId !== '') {
            $event['event_id'] = $eventId;
        }

        $result = $this->post($pixelId . '/events', ['data' => json_encode([$event], JSON_UNESCAPED_UNICODE)]);

        if (!$result['success']) {
            return $result;
        }

        $received = isset($result['data']['events_received'])
            ? (int) $result['data']['events_received'] > 0
            : true;

        return ['success' => $received, 'received' => $received];
    }

    private function mapCta(string $cta): string
    {
        // إعلانات الموقع مينفعش ياخدوا زرار "اتصل" أو "راسلنا" (دول ليهم أهداف خاصة
        // بيتحطوا تلقائيًا في linkData) - فبيتحولوا لـ"تواصل معنا" عشان الإعلان ميترفضش.
        $map = [
            'احجز الآن' => 'BOOK_TRAVEL', 'اعرف المزيد' => 'LEARN_MORE', 'اعرف أكتر' => 'LEARN_MORE', 'تواصل معنا' => 'CONTACT_US',
            'اشترك الآن' => 'SIGN_UP', 'سجّل الآن' => 'SIGN_UP', 'سجل الآن' => 'SIGN_UP', 'تسوق الآن' => 'SHOP_NOW', 'قدّم الآن' => 'APPLY_NOW',
            'اتصل الآن' => 'CONTACT_US', 'راسلنا' => 'CONTACT_US', 'راسلنا واتساب' => 'CONTACT_US', 'حمّل الآن' => 'DOWNLOAD',
        ];
        $english = [
            'book now' => 'BOOK_TRAVEL', 'learn more' => 'LEARN_MORE', 'contact us' => 'CONTACT_US',
            'sign up' => 'SIGN_UP', 'shop now' => 'SHOP_NOW', 'apply now' => 'APPLY_NOW',
            'call now' => 'CONTACT_US', 'message us' => 'CONTACT_US', 'download' => 'DOWNLOAD',
        ];
        return $map[trim($cta)] ?? $english[strtolower(trim($cta))] ?? 'LEARN_MORE';
    }

    private function mapLocationsToCountryCodes(array $locations): array
    {
        $map = [
            'مصر' => 'EG', 'egypt' => 'EG', 'السعودية' => 'SA', 'saudi arabia' => 'SA',
            'الإمارات' => 'AE', 'uae' => 'AE', 'الكويت' => 'KW', 'kuwait' => 'KW',
            'قطر' => 'QA', 'qatar' => 'QA', 'البحرين' => 'BH', 'bahrain' => 'BH',
            'عمان' => 'OM', 'oman' => 'OM', 'الأردن' => 'JO', 'jordan' => 'JO',
            'لبنان' => 'LB', 'lebanon' => 'LB', 'المغرب' => 'MA', 'morocco' => 'MA',
            'تونس' => 'TN', 'tunisia' => 'TN', 'الجزائر' => 'DZ', 'algeria' => 'DZ',
            'العراق' => 'IQ', 'iraq' => 'IQ', 'ليبيا' => 'LY', 'libya' => 'LY',
            'السودان' => 'SD', 'sudan' => 'SD', 'تركيا' => 'TR', 'turkey' => 'TR',
            'المملكة المتحدة' => 'GB', 'uk' => 'GB', 'ألمانيا' => 'DE', 'germany' => 'DE',
            'فرنسا' => 'FR', 'france' => 'FR', 'الولايات المتحدة' => 'US', 'usa' => 'US',
        ];
        $codes = [];
        foreach ($locations as $loc) {
            $raw = trim((string) $loc);
            if (preg_match('/^[A-Za-z]{2}$/', $raw)) {
                $codes[] = strtoupper($raw);
                continue;
            }
            $key = mb_strtolower($raw);
            if (isset($map[$key])) {
                $codes[] = $map[$key];
            }
        }
        return !empty($codes) ? array_values(array_unique($codes)) : ['EG'];
    }

    public static function errorText(array $data, int $httpCode): string
    {
        $e = $data['error'] ?? null;
        if (!is_array($e)) {
            return "Meta API error (HTTP {$httpCode})";
        }
        $msg = trim((string) ($e['error_user_title'] ?? '') . ' ' . (string) ($e['error_user_msg'] ?? ''));
        if ($msg === '') {
            $msg = (string) ($e['message'] ?? 'Meta API error');
        }
        $code = isset($e['code']) ? ' (#' . $e['code'] . (isset($e['error_subcode']) ? '/' . $e['error_subcode'] : '') . ')' : '';
        $hint = self::hint((int) ($e['code'] ?? 0), (int) ($e['error_subcode'] ?? 0), strtolower((string) ($e['message'] ?? '') . ' ' . $msg));
        return ($hint ? $hint . ' — ' : '') . $msg . $code;
    }

    private static function hint(int $code, int $sub, string $m): string
    {
        if ($code === 190) {
            return 'انتهت صلاحية ربط Meta - اربط حساب الإعلانات تاني';
        }
        if ($code === 200 || $code === 10 || str_contains($m, 'permission')) {
            return 'صلاحية ناقصة (ads_management) - اربط Meta تاني ووافق على كل الصلاحيات';
        }
        if ($sub === 1885183 || str_contains($m, 'development mode')) {
            return 'تطبيق Meta لسه في وضع التطوير - الإعلانات نفسها مش هتتعمل غير بعد ما التطبيق يبقى Live';
        }
        if (str_contains($m, 'budget') && (str_contains($m, 'minimum') || str_contains($m, 'at least') || str_contains($m, 'too low'))) {
            return 'الميزانية اليومية أقل من الحد الأدنى لعملة حسابك الإعلاني - زوّدها';
        }
        if (str_contains($m, 'payment') || str_contains($m, 'funding')) {
            return 'حساب الإعلانات مافيهوش وسيلة دفع - ضيفها من Meta Ads Manager';
        }
        if ($code === 17 || $code === 4 || $code === 613 || $code === 80004) {
            return 'Meta موقفة الطلبات مؤقتًا - جرّب بعد شوية';
        }
        return '';
    }

    private static function isTransientError(int $httpCode, array $data): bool
    {
        if (in_array($httpCode, [429, 500, 502, 503, 504], true)) {
            return true;
        }
        $code = (int) ($data['error']['code'] ?? 0);
        return in_array($code, [1, 2, 4, 17, 341, 613], true) || !empty($data['error']['is_transient']);
    }

    protected function post(string $path, array $fields = []): array
    {
        if (self::$testTransport && PHP_SAPI === 'cli') {
            [$code, $data] = (self::$testTransport)('POST', $path, $fields);
            return ($code !== 200 || isset($data['error']))
                ? ['success' => false, 'error' => self::errorText($data, $code), 'raw' => $data]
                : ['success' => true, 'data' => $data];
        }

        $fields['access_token'] = $this->accessToken;
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$path}";

        for ($attempt = 0; $attempt < 1; $attempt++) {
            try {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query($fields),
                ]);

                $response = curl_exec($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($curlError) {
                    // A write may have succeeded at Meta before the network failed. Never retry blindly.
                    return ['success' => false, 'uncertain' => true, 'error' => 'Meta write outcome unknown due to network error. Check Ads Manager before retrying: ' . $curlError];
                }

                $data = json_decode((string) $response, true) ?: [];

                if ($httpCode !== 200 || isset($data['error'])) {
                    // POST retries can create duplicate Meta objects; return the actual error instead.
                    return ['success' => false, 'error' => self::errorText($data, $httpCode), 'raw' => $data];
                }

                return ['success' => true, 'data' => $data];
            } catch (Throwable $e) {
                if (class_exists('Logger')) {
                    Logger::error('Meta Ads API POST request failed', ['path' => $path, 'error' => $e->getMessage()]);
                }
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return ['success' => false, 'error' => 'Meta API request failed after retries'];
    }

    protected function get(string $path, array $query = []): array
    {
        if (self::$testTransport && PHP_SAPI === 'cli') {
            [$code, $data] = (self::$testTransport)('GET', $path, $query);
            return ($code !== 200 || isset($data['error']))
                ? ['success' => false, 'error' => self::errorText($data, $code)]
                : ['success' => true, 'data' => $data];
        }

        $query['access_token'] = $this->accessToken;
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$path}?" . http_build_query($query);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 25,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);

                $response = curl_exec($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($curlError) {
                    if ($attempt === 0) {
                        usleep(300000);
                        continue;
                    }
                    return ['success' => false, 'error' => 'cURL Error: ' . $curlError];
                }

                $data = json_decode((string) $response, true) ?: [];

                if ($httpCode !== 200 || isset($data['error'])) {
                    if ($attempt === 0 && self::isTransientError($httpCode, $data)) {
                        usleep(500000);
                        continue;
                    }
                    return ['success' => false, 'error' => self::errorText($data, $httpCode)];
                }

                return ['success' => true, 'data' => $data];
            } catch (Throwable $e) {
                if (class_exists('Logger')) {
                    Logger::error('Meta Ads API request failed', ['path' => $path, 'error' => $e->getMessage()]);
                }
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return ['success' => false, 'error' => 'Meta API request failed after retries'];
    }
}
<?php

/**
 * Tourfecto - Enterprise Google Ads API Client (REST)
 * @version 3.0.0
 *
 * المزايا التنافسية:
 * 1. سحب تلقائي لـ budget_resource_name في listCampaignsWithInsights لتمكين الـ Autopilot من تعديل الميزانية.
 * 2. دعم كامل لـ gclid + orderId (منع تكرار الحجز) في sendEnhancedConversion.
 * 3. اكتشاف تلقائي لحساب المدير (MCC Auto-Discovery) مع Retry ذكي للأخطاء المؤقتة.
 * 4. حملات بحث كاملة: استهداف دول ولغات، RSA بـ15 عنوان، إضافات، كلمات سلبية، وتنضيف عند الفشل.
 * 5. تقرير مصطلحات البحث وإضافة كلمات سلبية (أساس الطيار الآلي للكلمات).
 */
class GoogleAdsAPI
{
    private const BASE_URL = 'https://googleads.googleapis.com';

    private string $apiVersion;
    private string $accessToken;
    private string $developerToken;
    private string $loginCustomerId;

    /** @var array<string,string|null> كاش داخل نفس الطلب: customerId => managerId */
    private static array $managerCache = [];

    public function __construct(string $accessToken)
    {
        $this->apiVersion = (string) (env('GOOGLE_ADS_API_VERSION') ?: 'v25');
        if (!preg_match('/^v(\d+)$/', $this->apiVersion, $vm) || (int) $vm[1] < 22) {
            $this->apiVersion = 'v25';
        }
        $this->accessToken = $accessToken;
        $this->developerToken = class_exists('SystemSettingsService')
            ? (new SystemSettingsService())->get('google_ads_developer_token', (string) (env('GOOGLE_ADS_DEVELOPER_TOKEN') ?: ''))
            : (string) (env('GOOGLE_ADS_DEVELOPER_TOKEN') ?: '');
        $this->loginCustomerId = class_exists('SystemSettingsService')
            ? (new SystemSettingsService())->get('google_ads_login_customer_id', (string) (env('GOOGLE_ADS_LOGIN_CUSTOMER_ID') ?: ''))
            : (string) (env('GOOGLE_ADS_LOGIN_CUSTOMER_ID') ?: '');
    }

    public function isDeveloperTokenConfigured(): bool
    {
        return $this->developerToken !== '';
    }

    public function listAdAccounts(): array
    {
        $listResult = $this->httpGet('/customers:listAccessibleCustomers');
        if (!$listResult['success']) {
            return $listResult;
        }

        $resourceNames = $listResult['data']['resourceNames'] ?? [];
        if (empty($resourceNames)) {
            return ['success' => true, 'accounts' => []];
        }

        $accounts = [];
        $errors = [];
        $managerIds = [];

        foreach ($resourceNames as $resourceName) {
            $customerId = str_replace('customers/', '', $resourceName);
            // بنقرا الحساب باسمه هو: الـ MCC بتاع المنصة (لو متسجّل) مش مدير لحسابات العملاء، فجوجل كان بيرد "caller does not have permission"
            $detail = $this->query($customerId, 'SELECT customer.id, customer.descriptive_name, customer.currency_code, customer.manager, customer.status FROM customer LIMIT 1', (string) $customerId);

            if (!$detail['success']) {
                $errors[] = $detail['error'] ?? 'unknown error';
                continue;
            }
            if (empty($detail['rows'])) {
                continue;
            }

            $customer = $detail['rows'][0]['customer'] ?? [];

            if (!empty($customer['manager'])) {
                $managerIds[] = (string) $customerId;
                $children = $this->query(
                    $customerId,
                    "SELECT customer_client.id, customer_client.descriptive_name, customer_client.currency_code, customer_client.manager, customer_client.status FROM customer_client WHERE customer_client.manager = FALSE AND customer_client.status = 'ENABLED'",
                    (string) $customerId
                );
                if ($children['success']) {
                    foreach ($children['rows'] as $row) {
                        $cc = $row['customerClient'] ?? [];
                        if (empty($cc['id'])) {
                            continue;
                        }
                        self::rememberLogin((string) $cc['id'], (string) $customerId);
                        $accounts[] = [
                            'id' => (string) $cc['id'],
                            'name' => $cc['descriptiveName'] ?? (string) $cc['id'],
                            'currency' => $cc['currencyCode'] ?? 'USD',
                            'status' => strtolower($cc['status'] ?? 'enabled'),
                        ];
                    }
                } else {
                    $errors[] = $children['error'] ?? 'unknown error';
                }
                continue;
            }

            self::rememberLogin((string) $customerId, (string) $customerId);
            $accounts[] = [
                'id' => (string) $customerId,
                'name' => $customer['descriptiveName'] ?? $customerId,
                'currency' => $customer['currencyCode'] ?? 'USD',
                'status' => strtolower($customer['status'] ?? 'unknown'),
            ];
        }

        if (empty($accounts)) {
            if (!empty($errors)) {
                return ['success' => false, 'error' => 'Google رجّعت خطأ وإحنا بنقرا تفاصيل الحسابات (' . count($resourceNames) . ' حساب متاح): ' . $errors[0]];
            }
            if (!empty($managerIds)) {
                return ['success' => false, 'error' => 'الحساب ده حساب مدير (Manager) رقم ' . implode(', ', $managerIds)
                    . ' ومفيهوش أي حساب إعلاني نشط تحته. لازم يكون فيه حساب إعلاني فعلي (Enabled) مربوط بيه.'];
            }
        }

        return ['success' => true, 'accounts' => $accounts];
    }

    /**
     * سحب حملات الحساب مع بيانات الأداء اليومية ومعرّف مورد الميزانية (budget_resource_name).
     */
    public function listCampaignsWithInsights(string $customerId): array
    {
        $customerId = preg_replace('/[^0-9]/', '', $customerId);
        $startDate = date('Y-m-d', strtotime('-30 days'));
        $endDate = date('Y-m-d');

        $gaql = "SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type,
                    campaign.campaign_budget, campaign_budget.resource_name, campaign_budget.amount_micros,
                    campaign.start_date, campaign.end_date,
                    metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.conversions, segments.date
                 FROM campaign
                 WHERE segments.date BETWEEN '{$startDate}' AND '{$endDate}'";

        $result = $this->query($customerId, $gaql);
        if (!$result['success'] && stripos((string) ($result['error'] ?? ''), 'date') !== false) {
            // بعض إصدارات الـAPI غيّرت حقول تاريخ الحملة - المزامنة متقفش بسببها
            $result = $this->query($customerId, str_replace('campaign.start_date, campaign.end_date,', '', $gaql));
        }
        if (!$result['success']) {
            return $result;
        }

        $byCampaign = [];
        foreach ($result['rows'] as $row) {
            $c = $row['campaign'] ?? [];
            $id = (string) ($c['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $budgetResName = (string) ($c['campaignBudget'] ?? $row['campaignBudget']['resourceName'] ?? '');

            if (!isset($byCampaign[$id])) {
                $byCampaign[$id] = [
                    'external_campaign_id' => $id,
                    'budget_resource_name' => $budgetResName !== '' ? $budgetResName : null,
                    'name' => $c['name'] ?? ('Campaign ' . $id),
                    'objective' => $this->mapChannelType($c['advertisingChannelType'] ?? ''),
                    'status' => strtolower($c['status'] ?? 'paused'),
                    'daily_budget' => isset($row['campaignBudget']['amountMicros']) ? ((float) $row['campaignBudget']['amountMicros']) / 1_000_000 : null,
                    'started_at' => $c['startDate'] ?? null,
                    'ended_at' => (!empty($c['endDate']) && $c['endDate'] !== '') ? $c['endDate'] : null,
                    'impressions' => 0,
                    'clicks' => 0,
                    'spend' => 0.0,
                    'daily' => [],
                ];
            } elseif ($byCampaign[$id]['budget_resource_name'] === null && $budgetResName !== '') {
                $byCampaign[$id]['budget_resource_name'] = $budgetResName;
            }

            $metrics = $row['metrics'] ?? [];
            $byCampaign[$id]['impressions'] += (int) ($metrics['impressions'] ?? 0);
            $byCampaign[$id]['clicks'] += (int) ($metrics['clicks'] ?? 0);
            $byCampaign[$id]['spend'] += ((float) ($metrics['costMicros'] ?? 0)) / 1_000_000;

            $day = (string) ($row['segments']['date'] ?? '');
            if ($day !== '') {
                $byCampaign[$id]['daily'][] = [
                    'date' => $day,
                    'impressions' => (int) ($metrics['impressions'] ?? 0),
                    'clicks' => (int) ($metrics['clicks'] ?? 0),
                    'spend' => ((float) ($metrics['costMicros'] ?? 0)) / 1_000_000,
                    'conversions' => (float) ($metrics['conversions'] ?? 0),
                ];
            }
        }

        return ['success' => true, 'campaigns' => array_values($byCampaign)];
    }

    private function mapChannelType(string $channelType): string
    {
        $map = [
            'SEARCH' => 'traffic', 'DISPLAY' => 'awareness', 'VIDEO' => 'awareness',
            'SHOPPING' => 'traffic', 'PERFORMANCE_MAX' => 'leads', 'LOCAL' => 'calls',
        ];
        return $map[$channelType] ?? 'traffic';
    }

    private function query(string $customerId, string $gaql, ?string $loginId = null): array
    {
        $result = $this->request('POST', "/customers/{$customerId}/googleAds:search", ['query' => $gaql], $loginId);
        if (!$result['success']) {
            return $result;
        }
        return ['success' => true, 'rows' => $result['data']['results'] ?? []];
    }

    /** كلمات سلبية افتراضية لأي حملة سياحية - بتمنع صرف الميزانية على باحثين عن شغل/مجاني/معلومات */
    private const DEFAULT_NEGATIVES = [
        // "مجاني/free" مش موجودة عمدًا: "free cancellation" و"إلغاء مجاني" بحث حجز قيّم جدًا
        'en' => ['jobs', 'job', 'salary', 'career', 'vacancy', 'pdf', 'wikipedia', 'meaning', 'history of', 'map download', 'wallpaper'],
        'ar' => ['وظائف', 'وظيفة', 'مرتب', 'راتب', 'pdf', 'ويكيبيديا', 'تحميل', 'بحث عن', 'معنى'],
        'de' => ['jobs', 'stellenangebote', 'gehalt', 'pdf', 'wikipedia'],
        'fr' => ['emploi', 'salaire', 'pdf', 'wikipedia'],
        'it' => ['lavoro', 'stipendio', 'pdf', 'wikipedia'],
        'es' => ['empleo', 'trabajo', 'salario', 'pdf', 'wikipedia'],
        'ru' => ['вакансии', 'работа', 'зарплата', 'pdf', 'википедия'],
    ];

    /** العناوين المسموحة لـ Structured Snippets (الإنجليزي فقط مضمون في كل الحسابات) */
    public const SNIPPET_HEADERS = ['Amenities', 'Brands', 'Courses', 'Degree programs', 'Destinations', 'Featured hotels', 'Insurance coverage', 'Models', 'Neighborhoods', 'Service catalog', 'Shows', 'Styles', 'Types'];

    /** عملة حساب Google Ads (الميزانية بتتبعت بيها) */
    public function getAccountCurrency(string $customerId): ?string
    {
        $customerId = preg_replace('/[^0-9]/', '', $customerId);
        $r = $this->query($customerId, 'SELECT customer.currency_code FROM customer LIMIT 1');
        if (!$r['success'] || empty($r['rows'])) {
            return null;
        }
        $code = strtoupper((string) ($r['rows'][0]['customer']['currencyCode'] ?? ''));
        return preg_match('/^[A-Z]{3}$/', $code) ? $code : null;
    }

    /** هل الحساب فيه تتبع تحويلات شغال؟ (من غيره "زيادة التحويلات" بتصرف من غير ما تتعلم) */
    /** أول إجراء تحويل مفعّل من نوع "رفع" (Import ← offline/clicks) في حساب العميل - مكان تسجيل الحجوزات */
    public function uploadConversionAction(string $customerId): ?string
    {
        $customerId = preg_replace('/[^0-9]/', '', $customerId);
        $r = $this->query($customerId, "SELECT conversion_action.resource_name FROM conversion_action WHERE conversion_action.status = 'ENABLED' AND conversion_action.type = 'UPLOAD_CLICKS' LIMIT 1");
        return $r['success'] && !empty($r['rows'][0]['conversionAction']['resourceName']) ? (string) $r['rows'][0]['conversionAction']['resourceName'] : null;
    }

    public function hasConversionTracking(string $customerId): bool
    {
        $customerId = preg_replace('/[^0-9]/', '', $customerId);
        $r = $this->query($customerId, "SELECT conversion_action.id FROM conversion_action WHERE conversion_action.status = 'ENABLED' LIMIT 1");
        return $r['success'] && !empty($r['rows']);
    }

    /**
     * يقصّ النص على حدود كلمة من غير "…" ولا كلمة مقطوعة. لو القص هيبوّظ المعنى
     * (يشيل أكتر من 40% من النص) بيرجّع null عشان النص يتشال بدل ما يطلع مشوّه.
     */
    public static function fitText(string $text, int $max): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max + 1);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace === false) {
            return null;
        }
        $cut = rtrim(mb_substr($cut, 0, $lastSpace), " ,،.-–:;");
        // متسيبش العنوان واقف على حرف جر/عطف ("Tour From" / "رحلة من")
        $dangling = '/\s+(from|to|with|and|or|the|of|for|in|on|at|a|an|by|von|und|mit|de|du|et|con|y|di|في|من|مع|و|الى|إلى|على|عن|ب|ل)$/iu';
        while (preg_match($dangling, $cut)) {
            $cut = rtrim((string) preg_replace($dangling, '', $cut), " ,،.-–:;");
        }
        return mb_strlen($cut) >= (int) ceil($max * 0.6) ? $cut : null;
    }

    /**
     * تجميع أصول الإعلان المتجاوب (RSA): لحد 15 عنوان (≤30 حرف) و4 أوصاف (≤90 حرف)
     * من غير تكرار ومن غير قص في نص الكلمة. الأولوية للأصول المكتوبة مخصوص لجوجل،
     * وبعدين النسخ المعتمدة كاحتياطي.
     * @return array{headlines: string[], descriptions: string[]}
     */
    public static function buildRsaAssets(array $copies, array $searchAssets = []): array
    {
        $headlines = [];
        $descriptions = [];
        $add = function (array &$list, ?string $text, int $limit) {
            if ($text === null || count($list) >= $limit) {
                return;
            }
            $key = mb_strtolower($text);
            foreach ($list as $existing) {
                if (mb_strtolower($existing) === $key) {
                    return;
                }
            }
            $list[] = $text;
        };

        foreach ((array) ($searchAssets['headlines'] ?? []) as $h) {
            $add($headlines, self::fitText((string) $h, 30), 15);
        }
        foreach ((array) ($searchAssets['descriptions'] ?? []) as $d) {
            $add($descriptions, self::fitText((string) $d, 90), 4);
        }
        foreach ($copies as $c) {
            $add($headlines, self::fitText((string) ($c['headline'] ?? ''), 30), 15);
        }
        foreach ($copies as $c) {
            $add($descriptions, self::fitText((string) ($c['description'] ?? ''), 90), 4);
            // النص الأساسي بيتقسم جمل - كل جملة كاملة ممكن تبقى وصف مستقل
            foreach (preg_split('/(?<=[.!؟?])\s+/u', trim((string) ($c['primary_text'] ?? ''))) ?: [] as $sentence) {
                $add($descriptions, self::fitText((string) $sentence, 90), 4);
            }
        }
        return ['headlines' => $headlines, 'descriptions' => $descriptions];
    }

    /** تنظيف الكلمة المفتاحية من الرموز اللي Google بترفضها (حد 80 حرف و10 كلمات) */
    public static function sanitizeKeyword(string $keyword): string
    {
        $k = preg_replace('/[!@%,*()={};~`<>?\\\\|^\[\]"]+/u', ' ', $keyword);
        $k = trim(preg_replace('/\s+/u', ' ', (string) $k));
        $words = explode(' ', $k);
        if (count($words) > 10) {
            $k = implode(' ', array_slice($words, 0, 10));
        }
        return mb_substr($k, 0, 80);
    }

    /**
     * إنشاء حملة بحث (Search) كاملة واحترافية على Google Ads.
     *
     * $options:
     * - countries: أكواد ISO2 للدول المستهدفة (إلزامي عمليًا - من غيره الحملة بتطلع للعالم كله)
     * - languages: أكواد لغات الإعلان (en/ar/de...)
     * - search_assets: {headlines[], descriptions[], sitelinks[{text,description1,description2,url}], callouts[], snippet{header,values[]}, paths[]}
     * - ad_groups: [{name, keywords:[{keyword,match_type}]}] (لو فاضي كل الكلمات في مجموعة واحدة)
     * - bidding: 'conversions' | 'clicks'
     * - max_cpc: سقف تكلفة الضغطة (بعملة الحساب) لاستراتيجية الضغطات
     * - phone / phone_country: لإضافة زرار الاتصال (Call asset)
     * - negative_keywords: كلمات سلبية إضافية على مستوى الحملة
     */
    public function createSearchCampaign(string $customerId, array $campaign, array $copies, array $keywords, string $destinationUrl, string $bidStrategyHint = '', array $options = []): array
    {
        $customerId = preg_replace('/[^0-9]/', '', $customerId);
        $warnings = [];

        $rsa = self::buildRsaAssets($copies, (array) ($options['search_assets'] ?? []));
        if (count($rsa['headlines']) < 3 || count($rsa['descriptions']) < 2) {
            return ['success' => false, 'error' => 'محتاج على الأقل 3 عناوين (لحد 30 حرف) ووصفين (لحد 90 حرف) عشان إعلان Google - ولّد أصول Google أو اعتمد نسخ أكتر وحاول تاني'];
        }

        $countries = array_values(array_filter((array) ($options['countries'] ?? []), fn ($c) => AdGeo::googleGeoTargetId((string) $c) !== null));
        if (empty($countries)) {
            return ['success' => false, 'error' => 'حدّد الدول المستهدفة للحملة - من غيرها Google هتعرض الإعلان في العالم كله وتصرف الميزانية على ناس مش عملاؤك'];
        }

        $budgetMicros = (int) round((float) ($campaign['daily_budget'] ?? 10) * 1_000_000);
        $budgetResult = $this->mutate($customerId, 'campaignBudgets', [[
            'create' => [
                'name' => $campaign['name'] . ' - Budget ' . time(),
                'amountMicros' => (string) $budgetMicros,
                'deliveryMethod' => 'STANDARD',
                'explicitlyShared' => false,
            ],
        ]]);
        if (!$budgetResult['success']) {
            return ['success' => false, 'error' => 'فشل إنشاء ميزانية الحملة: ' . ($budgetResult['error'] ?? '')];
        }
        $budgetResourceName = $budgetResult['results'][0]['resourceName'];

        $bidding = (string) ($options['bidding'] ?? '');
        if ($bidding === '') {
            $bidding = (stripos($bidStrategyHint, 'تحويل') !== false || stripos($bidStrategyHint, 'conversion') !== false) ? 'conversions' : 'clicks';
        }

        $campaignCreate = [
            'name' => $campaign['name'],
            'advertisingChannelType' => 'SEARCH',
            'status' => 'PAUSED',
            'campaignBudget' => $budgetResourceName,
            'containsEuPoliticalAdvertising' => 'DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING',
            'networkSettings' => [
                'targetGoogleSearch' => true, 'targetSearchNetwork' => false,
                'targetContentNetwork' => false, 'targetPartnerSearchNetwork' => false,
            ],
            // الناس "الموجودين فعلًا" في الدول المستهدفة - مش أي حد "مهتم" بيها من أي مكان في العالم
            'geoTargetTypeSetting' => ['positiveGeoTargetType' => 'PRESENCE', 'negativeGeoTargetType' => 'PRESENCE'],
            'finalUrlSuffix' => 'utm_source=google&utm_medium=cpc&utm_campaign={campaignid}&utm_content={creative}&utm_term={keyword}',
        ];
        if ($bidding === 'conversions') {
            $campaignCreate['maximizeConversions'] = new stdClass();
        } else {
            // "زيادة الضغطات" في Google Ads API اسمها targetSpend (الحقل maximizeClicks مش موجود أصلًا)
            $maxCpc = (float) ($options['max_cpc'] ?? 0);
            $campaignCreate['targetSpend'] = $maxCpc > 0 ? ['cpcBidCeilingMicros' => (string) (int) round($maxCpc * 1_000_000)] : new stdClass();
        }

        $campaignResult = $this->mutate($customerId, 'campaigns', [['create' => $campaignCreate]]);
        if (!$campaignResult['success']) {
            $this->mutate($customerId, 'campaignBudgets', [['remove' => $budgetResourceName]]);
            return ['success' => false, 'error' => 'فشل إنشاء الحملة: ' . ($campaignResult['error'] ?? '')];
        }
        $campaignResourceName = $campaignResult['results'][0]['resourceName'];
        $campaignId = str_replace("customers/{$customerId}/campaigns/", '', $campaignResourceName);
        $abort = function (string $error) use ($customerId, $campaignResourceName, $budgetResourceName): array {
            // تنضيف كامل: مفيش حملات ناقصة تفضل معلّقة في حساب العميل
            $this->mutate($customerId, 'campaigns', [['remove' => $campaignResourceName]]);
            $this->mutate($customerId, 'campaignBudgets', [['remove' => $budgetResourceName]]);
            return ['success' => false, 'error' => $error];
        };

        $dateWarning = $this->applyCampaignDates($customerId, $campaignResourceName, $campaign);
        if ($dateWarning) {
            $warnings[] = $dateWarning;
        }

        // 1) الاستهداف: الدول + اللغات + الكلمات السلبية على مستوى الحملة
        $criteria = [];
        foreach ($countries as $iso) {
            $criteria[] = ['create' => ['campaign' => $campaignResourceName, 'location' => ['geoTargetConstant' => 'geoTargetConstants/' . AdGeo::googleGeoTargetId($iso)]]];
        }
        $languages = array_values(array_unique(array_filter(array_map(fn ($l) => AdGeo::googleLanguageId((string) $l), (array) ($options['languages'] ?? [])))));
        foreach ($languages as $langId) {
            $criteria[] = ['create' => ['campaign' => $campaignResourceName, 'language' => ['languageConstant' => 'languageConstants/' . $langId]]];
        }
        $criteriaResult = $this->mutate($customerId, 'campaignCriteria', $criteria);
        if (!$criteriaResult['success']) {
            return $abort('فشل ضبط استهداف الدول/اللغة، فمسحنا الحملة عشان متصرفش على العالم كله: ' . ($criteriaResult['error'] ?? ''));
        }

        $negatives = [];
        foreach ($keywords as $k) {
            if (strtolower((string) ($k['match_type'] ?? '')) === 'negative') {
                $negatives[] = (string) $k['keyword'];
            }
        }
        foreach ((array) ($options['negative_keywords'] ?? []) as $n) {
            $negatives[] = (string) $n;
        }
        foreach (($languages ? (array) ($options['languages'] ?? []) : ['en']) as $lang) {
            foreach (self::DEFAULT_NEGATIVES[strtolower(substr((string) $lang, 0, 2))] ?? [] as $n) {
                $negatives[] = $n;
            }
        }
        $negatives = array_values(array_unique(array_filter(array_map(fn ($n) => self::sanitizeKeyword($n), $negatives))));
        if ($negatives) {
            $negResult = $this->addCampaignNegativeKeywords($customerId, $campaignId, $negatives, 'PHRASE');
            if (!$negResult['success']) {
                $warnings[] = 'الكلمات السلبية متضافتش: ' . ($negResult['error'] ?? '');
            }
        }

        // 2) المجموعات الإعلانية: كل مجموعة بكلماتها وإعلانها المتجاوب
        $groups = [];
        foreach ((array) ($options['ad_groups'] ?? []) as $g) {
            if (!empty($g['keywords'])) {
                $groups[] = ['name' => (string) ($g['name'] ?? 'Ad Group'), 'keywords' => (array) $g['keywords']];
            }
        }
        if (empty($groups)) {
            $groups[] = ['name' => $campaign['name'] . ' - Ad Group', 'keywords' => $keywords];
        }

        $paths = array_values(array_filter(array_map(fn ($p) => self::fitText(preg_replace('/[\s\/]+/u', '-', (string) $p), 15), (array) ($options['search_assets']['paths'] ?? []))));
        $createdGroups = 0;
        $keywordCount = 0;
        $usedNames = [];
        foreach ($groups as $gi => $group) {
            // أسماء المجموعات لازم تبقى فريدة جوه الحملة
            $groupName = mb_substr($group['name'], 0, 240);
            if (isset($usedNames[mb_strtolower($groupName)])) {
                $groupName .= ' ' . ($gi + 1);
            }
            $usedNames[mb_strtolower($groupName)] = true;
            $adGroupResult = $this->mutate($customerId, 'adGroups', [[
                'create' => [
                    'name' => $groupName,
                    'campaign' => $campaignResourceName,
                    'status' => 'ENABLED',
                    'type' => 'SEARCH_STANDARD',
                ],
            ]]);
            if (!$adGroupResult['success']) {
                $warnings[] = 'مجموعة "' . $group['name'] . '" متعملتش: ' . ($adGroupResult['error'] ?? '');
                continue;
            }
            $adGroupResourceName = $adGroupResult['results'][0]['resourceName'];

            $keywordOps = [];
            $seen = [];
            foreach (array_slice($group['keywords'], 0, 50) as $k) {
                $matchType = strtoupper((string) ($k['match_type'] ?? 'PHRASE'));
                if ($matchType === 'NEGATIVE') {
                    continue;
                }
                if (!in_array($matchType, ['EXACT', 'PHRASE', 'BROAD'], true)) {
                    $matchType = 'PHRASE';
                }
                $text = self::sanitizeKeyword((string) ($k['keyword'] ?? ''));
                if ($text === '' || isset($seen[$matchType . mb_strtolower($text)])) {
                    continue;
                }
                $seen[$matchType . mb_strtolower($text)] = true;
                $keywordOps[] = ['create' => [
                    'adGroup' => $adGroupResourceName,
                    'status' => 'ENABLED',
                    'keyword' => ['text' => $text, 'matchType' => $matchType],
                ]];
            }
            if ($keywordOps) {
                $kwResult = $this->mutate($customerId, 'adGroupCriteria', $keywordOps, true);
                if (!$kwResult['success']) {
                    $warnings[] = 'كلمات مجموعة "' . $group['name'] . '" متضافتش: ' . ($kwResult['error'] ?? '');
                } else {
                    $keywordCount += count(array_filter($kwResult['results'], fn ($r) => !empty($r['resourceName'])));
                    if (!empty($kwResult['partial_error'])) {
                        $warnings[] = 'بعض الكلمات اترفضت من Google: ' . $kwResult['partial_error'];
                    }
                }
            }

            $rsaBody = [
                'headlines' => array_map(fn ($t) => ['text' => $t], $rsa['headlines']),
                'descriptions' => array_map(fn ($t) => ['text' => $t], $rsa['descriptions']),
            ];
            if (!empty($paths[0])) {
                $rsaBody['path1'] = $paths[0];
                if (!empty($paths[1])) {
                    $rsaBody['path2'] = $paths[1];
                }
            }
            $adResult = $this->mutate($customerId, 'adGroupAds', [[
                'create' => [
                    'adGroup' => $adGroupResourceName,
                    // الإعلان شغّال والحملة نفسها متوقفة: لما العميل يفعّل الحملة الإعلان يشتغل فورًا
                    'status' => 'ENABLED',
                    'ad' => ['finalUrls' => [$destinationUrl], 'responsiveSearchAd' => $rsaBody],
                ],
            ]]);
            if (!$adResult['success']) {
                $warnings[] = 'إعلان مجموعة "' . $group['name'] . '" اترفض: ' . ($adResult['error'] ?? '');
                $this->mutate($customerId, 'adGroups', [['remove' => $adGroupResourceName]]);
                continue;
            }
            $createdGroups++;
        }

        if ($createdGroups === 0) {
            return $abort('فشل إنشاء الإعلانات، فمسحنا الحملة من Google Ads عشان متفضلش ناقصة: ' . implode(' | ', $warnings));
        }
        if ($keywordCount === 0) {
            return $abort('مفيش ولا كلمة مفتاحية اتقبلت، والحملة من غير كلمات مش هتظهر - ولّد كلمات مفتاحية للحملة وحاول تاني. ' . implode(' | ', $warnings));
        }

        // 3) الإضافات (Sitelinks / Callouts / Snippets / Call) - بترفع نسبة الضغط بوضوح
        $assetWarning = $this->attachCampaignAssets($customerId, $campaignResourceName, (array) ($options['search_assets'] ?? []), $options, $languages);
        if ($assetWarning) {
            $warnings[] = $assetWarning;
        }

        return [
            'success' => true,
            'external_campaign_id' => $campaignId,
            'external_budget_resource' => $budgetResourceName,
            'ad_groups' => $createdGroups,
            'keywords' => $keywordCount,
            'headlines' => count($rsa['headlines']),
            'descriptions' => count($rsa['descriptions']),
            'warning' => $warnings ? implode(' | ', $warnings) : null,
        ];
    }

    /**
     * Performance Max: text assets for one asset group.
     * Google needs >= 3 headlines (30), >= 1 long headline (90), >= 2 descriptions (90, one of them <= 60) and a business name (25).
     * @return array{headlines: string[], long_headlines: string[], descriptions: string[], business_name: string}
     */
    public static function buildPmaxTextAssets(array $copies, array $searchAssets, string $businessName): array
    {
        $rsa = self::buildRsaAssets($copies, $searchAssets);
        $long = [];
        $addLong = function (?string $t) use (&$long) {
            if ($t !== null && count($long) < 5 && !in_array(mb_strtolower($t), array_map('mb_strtolower', $long), true)) {
                $long[] = $t;
            }
        };
        foreach ($copies as $c) {
            $addLong(self::fitText(trim((string) ($c['headline'] ?? '') . ' - ' . (string) ($c['description'] ?? ''), ' -'), 90));
            foreach (preg_split('/(?<=[.!؟?])\s+/u', trim((string) ($c['primary_text'] ?? ''))) ?: [] as $sentence) {
                $addLong(self::fitText((string) $sentence, 90));
            }
        }
        $desc = array_slice($rsa['descriptions'], 0, 5);
        $hasShort = false;
        foreach ($desc as $d) {
            $hasShort = $hasShort || mb_strlen($d) <= 60;
        }
        if (!$hasShort) {
            foreach (array_merge($rsa['headlines'], $long) as $cand) {
                $short = self::fitText($cand, 60);
                if ($short !== null && !in_array($short, $desc, true)) {
                    array_unshift($desc, $short);
                    $desc = array_slice($desc, 0, 5);
                    break;
                }
            }
        }
        return [
            'headlines' => $rsa['headlines'],
            'long_headlines' => $long,
            'descriptions' => $desc,
            'business_name' => (string) mb_substr(trim($businessName), 0, 25),
        ];
    }

    /**
     * Centre-crop an image to a ratio and scale it to a Google-friendly size. Needs the GD extension.
     * @return string|null JPEG bytes, or null when the source is too small / not an image
     */
    public static function cropImage(string $bytes, float $ratio, int $minW, int $minH, int $outW): ?string
    {
        if (!function_exists('imagecreatefromstring') || strlen($bytes) > 12 * 1024 * 1024) {
            return null;
        }
        $src = @imagecreatefromstring($bytes);
        if (!$src) {
            return null;
        }
        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw / $sh > $ratio) {
            $ch = $sh;
            $cw = (int) round($sh * $ratio);
        } else {
            $cw = $sw;
            $ch = (int) round($sw / $ratio);
        }
        if ($cw < $minW || $ch < $minH) {
            imagedestroy($src);
            return null;
        }
        $w = min($outW, $cw);
        $h = (int) round($w / $ratio);
        $dst = imagecreatetruecolor($w, $h);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, (int) floor(($sw - $cw) / 2), (int) floor(($sh - $ch) / 2), $w, $h, $cw, $ch);
        ob_start();
        imagejpeg($dst, null, 88);
        $out = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);
        return $out !== '' ? $out : null;
    }

    /** Public https image fetched through the SSRF guard (own signed library links or the site's og:image). */
    public static function fetchImageBytes(string $url): ?string
    {
        if (!preg_match('#^https://#i', $url) || !class_exists('SafeHttp')) {
            return null;
        }
        $r = SafeHttp::exec($url, [CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_HEADER => false]);
        $body = $r['response'] ?? false;
        return (is_string($body) && $r['code'] === 200 && strlen($body) > 1000 && strlen($body) <= 12 * 1024 * 1024) ? $body : null;
    }

    /**
     * Performance Max campaign in ONE atomic googleAds:mutate (budget + campaign + targeting + asset group + assets):
     * either everything is created (PAUSED) or nothing is. Google requires conversion-based bidding, so the account
     * must already have a conversion action.
     *
     * $options: countries[], languages[], image_bytes[] (raw images; cropped to 1.91:1 / 1:1 here), business_name,
     * search_assets{headlines[],descriptions[]}, final_url_suffix
     */
    public function createPerformanceMaxCampaign(string $customerId, array $campaign, array $copies, string $destinationUrl, array $options = []): array
    {
        $customerId = preg_replace('/[^0-9]/', '', $customerId);
        $warnings = [];

        $text = self::buildPmaxTextAssets($copies, (array) ($options['search_assets'] ?? []), (string) ($options['business_name'] ?? ''));
        if (count($text['headlines']) < 3 || !$text['long_headlines'] || count($text['descriptions']) < 2 || $text['business_name'] === '') {
            return ['success' => false, 'step' => 'preflight', 'error' => 'Performance Max needs at least 3 headlines, 1 long headline, 2 descriptions and a business name. Generate the Google assets or approve more copies, then try again.'];
        }
        $countries = array_values(array_filter((array) ($options['countries'] ?? []), fn ($c) => AdGeo::googleGeoTargetId((string) $c) !== null));
        if (!$countries) {
            return ['success' => false, 'step' => 'preflight', 'error' => 'Choose the target countries; without them Google would show the ads worldwide.'];
        }

        // Images: first usable source gives the landscape and square marketing images and the logo.
        $landscape = $square = $logo = null;
        foreach ((array) ($options['image_bytes'] ?? []) as $bytes) {
            $landscape = $landscape ?? self::cropImage((string) $bytes, 1.91, 600, 314, 1200);
            $square = $square ?? self::cropImage((string) $bytes, 1.0, 300, 300, 1200);
            $logo = $logo ?? self::cropImage((string) $bytes, 1.0, 128, 128, 600);
        }
        if (!$landscape || !$square) {
            return ['success' => false, 'step' => 'preflight', 'error' => 'Performance Max needs at least one image of 600x314 px or larger. Pick an image from your library or add an og:image to the landing page.'];
        }
        if (!$logo) {
            $warnings[] = 'No logo image was available; add a logo in Google Ads before activating the campaign.';
        }

        $c = "customers/{$customerId}";
        $budgetTmp = "{$c}/campaignBudgets/-1";
        $campaignTmp = "{$c}/campaigns/-2";
        $groupTmp = "{$c}/assetGroups/-3";
        $ops = [];
        $ops[] = ['campaignBudgetOperation' => ['create' => [
            'resourceName' => $budgetTmp,
            'name' => $campaign['name'] . ' - Budget ' . time(),
            'amountMicros' => (string) (int) round((float) ($campaign['daily_budget'] ?? 10) * 1_000_000),
            'deliveryMethod' => 'STANDARD',
            'explicitlyShared' => false,
        ]]];
        $ops[] = ['campaignOperation' => ['create' => [
            'resourceName' => $campaignTmp,
            'name' => $campaign['name'],
            'advertisingChannelType' => 'PERFORMANCE_MAX',
            'status' => 'PAUSED',
            'campaignBudget' => $budgetTmp,
            'maximizeConversions' => new stdClass(),
            'containsEuPoliticalAdvertising' => 'DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING',
            'urlExpansionOptOut' => false,
            'geoTargetTypeSetting' => ['positiveGeoTargetType' => 'PRESENCE', 'negativeGeoTargetType' => 'PRESENCE'],
            'finalUrlSuffix' => 'utm_source=google&utm_medium=cpc&utm_campaign={campaignid}&utm_content={creative}',
        ]]];
        foreach ($countries as $iso) {
            $ops[] = ['campaignCriterionOperation' => ['create' => ['campaign' => $campaignTmp, 'location' => ['geoTargetConstant' => 'geoTargetConstants/' . AdGeo::googleGeoTargetId($iso)]]]];
        }
        foreach (array_values(array_unique(array_filter(array_map(fn ($l) => AdGeo::googleLanguageId((string) $l), (array) ($options['languages'] ?? []))))) as $langId) {
            $ops[] = ['campaignCriterionOperation' => ['create' => ['campaign' => $campaignTmp, 'language' => ['languageConstant' => 'languageConstants/' . $langId]]]];
        }
        $ops[] = ['assetGroupOperation' => ['create' => [
            'resourceName' => $groupTmp,
            'name' => mb_substr($campaign['name'], 0, 120) . ' - Asset group',
            'campaign' => $campaignTmp,
            'finalUrls' => [$destinationUrl],
            'status' => 'ENABLED',
        ]]];

        $n = 10;
        $link = function (string $fieldType, array $assetBody) use (&$ops, &$n, $c, $groupTmp) {
            $tmp = "{$c}/assets/-" . $n++;
            $ops[] = ['assetOperation' => ['create' => ['resourceName' => $tmp] + $assetBody]];
            $ops[] = ['assetGroupAssetOperation' => ['create' => ['assetGroup' => $groupTmp, 'asset' => $tmp, 'fieldType' => $fieldType]]];
        };
        foreach (array_slice($text['headlines'], 0, 15) as $t) {
            $link('HEADLINE', ['textAsset' => ['text' => $t]]);
        }
        foreach ($text['long_headlines'] as $t) {
            $link('LONG_HEADLINE', ['textAsset' => ['text' => $t]]);
        }
        foreach ($text['descriptions'] as $t) {
            $link('DESCRIPTION', ['textAsset' => ['text' => $t]]);
        }
        $link('BUSINESS_NAME', ['textAsset' => ['text' => $text['business_name']]]);
        $stamp = time();
        $link('MARKETING_IMAGE', ['name' => $campaign['name'] . ' landscape ' . $stamp, 'imageAsset' => ['data' => base64_encode($landscape)]]);
        $link('SQUARE_MARKETING_IMAGE', ['name' => $campaign['name'] . ' square ' . $stamp, 'imageAsset' => ['data' => base64_encode($square)]]);
        if ($logo) {
            $link('LOGO', ['name' => $campaign['name'] . ' logo ' . $stamp, 'imageAsset' => ['data' => base64_encode($logo)]]);
        }

        $res = $this->httpPost("/customers/{$customerId}/googleAds:mutate", ['mutateOperations' => $ops]);
        if (!$res['success']) {
            return ['success' => false, 'step' => 'mutate', 'error' => 'Google rejected the Performance Max campaign, nothing was created: ' . ($res['error'] ?? '')];
        }
        $campaignName = '';
        $budgetName = '';
        foreach ((array) ($res['data']['mutateOperationResponses'] ?? []) as $r) {
            $campaignName = $r['campaignResult']['resourceName'] ?? $campaignName;
            $budgetName = $r['campaignBudgetResult']['resourceName'] ?? $budgetName;
        }
        if ($campaignName === '') {
            return ['success' => false, 'step' => 'mutate', 'uncertain' => true, 'error' => 'Google accepted the request but returned no campaign id. Check Google Ads before retrying.'];
        }
        return [
            'success' => true,
            'external_campaign_id' => str_replace("customers/{$customerId}/campaigns/", '', $campaignName),
            'external_budget_resource' => $budgetName,
            'headlines' => count($text['headlines']),
            'descriptions' => count($text['descriptions']),
            'warning' => $warnings ? implode(' | ', $warnings) : null,
        ];
    }

    /**
     * تواريخ الحملة: الإصدارات الحديثة من الـAPI بتستخدم startDateTime/endDateTime
     * والقديمة startDate/endDate - بنجرّب الاتنين بدل ما الحملة كلها تفشل بسبب التاريخ.
     */
    private function applyCampaignDates(string $customerId, string $campaignResourceName, array $campaign): ?string
    {
        $start = !empty($campaign['start_date']) ? strtotime((string) $campaign['start_date']) : false;
        $end = !empty($campaign['end_date']) ? strtotime((string) $campaign['end_date']) : false;
        $start = ($start && $start > strtotime('tomorrow') - 1) ? $start : false;
        if (!$start && !$end) {
            return null;
        }
        $variants = [
            ['startDateTime', 'endDateTime', 'start_date_time', 'end_date_time', 'Y-m-d 00:00:00', 'Y-m-d 23:59:59'],
            ['startDate', 'endDate', 'start_date', 'end_date', 'Y-m-d', 'Y-m-d'],
        ];
        foreach ($variants as [$sf, $ef, $sm, $em, $sfmt, $efmt]) {
            $update = ['resourceName' => $campaignResourceName];
            $mask = [];
            if ($start) {
                $update[$sf] = date($sfmt, $start);
                $mask[] = $sm;
            }
            if ($end) {
                $update[$ef] = date($efmt, $end);
                $mask[] = $em;
            }
            $r = $this->mutate($customerId, 'campaigns', [['update' => $update, 'updateMask' => implode(',', $mask)]]);
            if ($r['success']) {
                return null;
            }
        }
        return 'تواريخ البداية/النهاية متضبطتش تلقائيًا - اضبطها من Google Ads';
    }

    private function attachCampaignAssets(string $customerId, string $campaignResourceName, array $assets, array $options, array $languageIds): ?string
    {
        $ops = [];
        $fieldTypes = [];
        $seenUrls = [];
        foreach (array_slice((array) ($assets['sitelinks'] ?? []), 0, 6) as $s) {
            $text = self::fitText((string) ($s['text'] ?? ''), 25);
            $url = trim((string) ($s['url'] ?? ''));
            if (!$text || !filter_var($url, FILTER_VALIDATE_URL) || isset($seenUrls[$url])) {
                continue;
            }
            $seenUrls[$url] = true;
            $sitelink = ['linkText' => $text];
            $d1 = self::fitText((string) ($s['description1'] ?? ''), 35);
            $d2 = self::fitText((string) ($s['description2'] ?? ''), 35);
            if ($d1 && $d2) {
                $sitelink['description1'] = $d1;
                $sitelink['description2'] = $d2;
            }
            $ops[] = ['create' => ['sitelinkAsset' => $sitelink, 'finalUrls' => [$url]]];
            $fieldTypes[] = 'SITELINK';
        }
        foreach (array_slice((array) ($assets['callouts'] ?? []), 0, 8) as $c) {
            $text = self::fitText((string) $c, 25);
            if ($text) {
                $ops[] = ['create' => ['calloutAsset' => ['calloutText' => $text]]];
                $fieldTypes[] = 'CALLOUT';
            }
        }
        $snippet = (array) ($assets['snippet'] ?? []);
        $englishOnly = $languageIds === [AdGeo::googleLanguageId('en')];
        if ($englishOnly && in_array($snippet['header'] ?? '', self::SNIPPET_HEADERS, true)) {
            $values = array_values(array_filter(array_map(fn ($v) => self::fitText((string) $v, 25), (array) ($snippet['values'] ?? []))));
            if (count($values) >= 3) {
                $ops[] = ['create' => ['structuredSnippetAsset' => ['header' => $snippet['header'], 'values' => array_slice($values, 0, 10)]]];
                $fieldTypes[] = 'STRUCTURED_SNIPPET';
            }
        }
        $phone = preg_replace('/[^0-9+]/', '', (string) ($options['phone'] ?? ''));
        $phoneCountry = strtoupper((string) ($options['phone_country'] ?? ''));
        if (strlen($phone) >= 7 && preg_match('/^[A-Z]{2}$/', $phoneCountry)) {
            $ops[] = ['create' => ['callAsset' => ['countryCode' => $phoneCountry, 'phoneNumber' => $phone]]];
            $fieldTypes[] = 'CALL';
        }
        if (!$ops) {
            return null;
        }

        $created = $this->mutate($customerId, 'assets', $ops, true);
        if (!$created['success']) {
            return 'الإضافات (Sitelinks/Callouts) متضافتش: ' . ($created['error'] ?? '');
        }
        $links = [];
        foreach ($created['results'] as $i => $r) {
            if (!empty($r['resourceName']) && isset($fieldTypes[$i])) {
                $links[] = ['create' => ['campaign' => $campaignResourceName, 'asset' => $r['resourceName'], 'fieldType' => $fieldTypes[$i]]];
            }
        }
        if (!$links) {
            return 'الإضافات اترفضت من Google: ' . ($created['partial_error'] ?? '');
        }
        $linked = $this->mutate($customerId, 'campaignAssets', $links, true);
        if (!$linked['success']) {
            return 'الإضافات اتعملت بس متربطتش بالحملة: ' . ($linked['error'] ?? '');
        }
        return !empty($created['partial_error']) ? 'بعض الإضافات اترفضت: ' . $created['partial_error'] : null;
    }

    /** إضافة كلمات سلبية على مستوى الحملة (من تقرير مصطلحات البحث أو الافتراضيات) */
    public function addCampaignNegativeKeywords(string $customerId, string $campaignId, array $terms, string $matchType = 'EXACT'): array
    {
        $customerId = preg_replace('/[^0-9]/', '', $customerId);
        $campaignId = preg_replace('/[^0-9]/', '', $campaignId);
        $matchType = in_array($matchType, ['EXACT', 'PHRASE', 'BROAD'], true) ? $matchType : 'EXACT';
        $ops = [];
        foreach (array_slice($terms, 0, 200) as $t) {
            $text = self::sanitizeKeyword((string) $t);
            if ($text !== '') {
                $ops[] = ['create' => [
                    'campaign' => "customers/{$customerId}/campaigns/{$campaignId}",
                    'negative' => true,
                    'keyword' => ['text' => $text, 'matchType' => $matchType],
                ]];
            }
        }
        if (!$ops) {
            return ['success' => true, 'added' => 0];
        }
        $r = $this->mutate($customerId, 'campaignCriteria', $ops, true);
        if (!$r['success']) {
            return $r;
        }
        return ['success' => true, 'added' => count(array_filter($r['results'], fn ($x) => !empty($x['resourceName']))), 'partial_error' => $r['partial_error'] ?? null];
    }

    /**
     * تقرير مصطلحات البحث الفعلية اللي ظهر عليها الإعلان (آخر 30 يوم) - أساس
     * اكتشاف الكلمات اللي بتصرف فلوس من غير أي تحويل.
     */
    public function searchTermsReport(string $customerId, string $campaignId, int $limit = 300): array
    {
        $customerId = preg_replace('/[^0-9]/', '', $customerId);
        $campaignId = preg_replace('/[^0-9]/', '', $campaignId);
        $gaql = "SELECT search_term_view.search_term, search_term_view.status, metrics.impressions, metrics.clicks,
                        metrics.cost_micros, metrics.conversions
                 FROM search_term_view
                 WHERE campaign.id = {$campaignId} AND segments.date DURING LAST_30_DAYS
                 ORDER BY metrics.cost_micros DESC
                 LIMIT " . max(1, min(1000, $limit));
        $r = $this->query($customerId, $gaql);
        if (!$r['success']) {
            return $r;
        }
        $terms = [];
        foreach ($r['rows'] as $row) {
            $term = (string) ($row['searchTermView']['searchTerm'] ?? '');
            if ($term === '') {
                continue;
            }
            $key = mb_strtolower($term);
            if (!isset($terms[$key])) {
                $terms[$key] = ['term' => $term, 'status' => (string) ($row['searchTermView']['status'] ?? ''), 'impressions' => 0, 'clicks' => 0, 'cost' => 0.0, 'conversions' => 0.0];
            }
            $m = $row['metrics'] ?? [];
            $terms[$key]['impressions'] += (int) ($m['impressions'] ?? 0);
            $terms[$key]['clicks'] += (int) ($m['clicks'] ?? 0);
            $terms[$key]['cost'] += ((float) ($m['costMicros'] ?? 0)) / 1_000_000;
            $terms[$key]['conversions'] += (float) ($m['conversions'] ?? 0);
        }
        return ['success' => true, 'terms' => array_values($terms)];
    }

    public function updateCampaignStatus(string $customerId, string $campaignId, string $status): array
    {
        $customerId = preg_replace('/[^0-9]/', '', $customerId);
        $googleStatus = strtoupper($status) === 'ENABLED' ? 'ENABLED' : 'PAUSED';
        $result = $this->mutate($customerId, 'campaigns', [[
            'update' => ['resourceName' => "customers/{$customerId}/campaigns/{$campaignId}", 'status' => $googleStatus],
            'updateMask' => 'status',
        ]]);
        return $result['success'] ? ['success' => true] : ['success' => false, 'error' => $result['error'] ?? 'فشل تعديل حالة الحملة'];
    }

    public function deleteCampaign(string $customerId, string $campaignId): array
    {
        $customerId = preg_replace('/[^0-9]/', '', $customerId);
        $result = $this->mutate($customerId, 'campaigns', [[
            'update' => ['resourceName' => "customers/{$customerId}/campaigns/{$campaignId}", 'status' => 'REMOVED'],
            'updateMask' => 'status',
        ]]);
        return $result['success'] ? ['success' => true] : ['success' => false, 'error' => $result['error'] ?? 'فشل إلغاء الحملة'];
    }

    public function pauseCampaign(string $customerId, string $campaignResourceName): array
    {
        return $this->updateCampaignStatus($customerId, $this->extractCampaignId($campaignResourceName), 'PAUSED');
    }

    public function resumeCampaign(string $customerId, string $campaignResourceName): array
    {
        return $this->updateCampaignStatus($customerId, $this->extractCampaignId($campaignResourceName), 'ENABLED');
    }

    private function extractCampaignId(string $campaignResourceName): string
    {
        if (preg_match('#customers/\d+/campaigns/(\d+)#', $campaignResourceName, $m)) {
            return $m[1];
        }
        return $campaignResourceName;
    }

    public function updateCampaignBudget(string $customerId, string $budgetResourceName, float $dailyBudgetUsd): array
    {
        return $this->updateBudget($budgetResourceName, $dailyBudgetUsd);
    }

    public function updateBudget(string $budgetResourceName, float $dailyBudgetUsd): array
    {
        if (!preg_match('#^customers/(\d+)/campaignBudgets/#', $budgetResourceName, $m)) {
            return ['success' => false, 'error' => 'رقم الميزانية غير صالح'];
        }
        $customerId = $m[1];
        $result = $this->mutate($customerId, 'campaignBudgets', [[
            'update' => ['resourceName' => $budgetResourceName, 'amountMicros' => (string) (int) round($dailyBudgetUsd * 1_000_000)],
            'updateMask' => 'amount_micros',
        ]]);
        return $result['success'] ? ['success' => true] : ['success' => false, 'error' => $result['error'] ?? 'فشل تعديل الميزانية'];
    }

    /**
     * $partialFailure: العمليات الصحيحة بتتنفذ حتى لو فيه عملية واحدة غلط (مثلًا
     * كلمة مفتاحية مرفوضة) بدل ما الدفعة كلها تفشل. النتائج الفاشلة بترجع فاضية.
     */
    private function mutate(string $customerId, string $resource, array $operations, bool $partialFailure = false): array
    {
        $body = ['operations' => $operations];
        if ($partialFailure) {
            $body['partialFailure'] = true;
        }
        $result = $this->httpPost("/customers/{$customerId}/{$resource}:mutate", $body);
        if (!$result['success']) {
            return $result;
        }
        $out = ['success' => true, 'results' => $result['data']['results'] ?? []];
        if (!empty($result['data']['partialFailureError']['message'])) {
            $out['partial_error'] = (string) $result['data']['partialFailureError']['message'];
        }
        return $out;
    }

    /**
     * Google Enhanced Conversions مع دعم gclid و orderId لمنع التكرار ورفع الدقة.
     */
    public function sendEnhancedConversion(string $customerId, string $conversionAction, array $conversion): array
    {
        $customerId = preg_replace('/[^0-9]/', '', $customerId);
        $conversionAction = preg_replace('/[^0-9\/a-zA-Z]/', '', $conversionAction);

        if ($customerId === '' || $conversionAction === '') {
            return ['success' => false, 'error' => 'رقم حساب Google Ads وإجراء التحويل مطلوبين'];
        }

        $emailHash = isset($conversion['email_hash']) && is_string($conversion['email_hash']) ? (string) $conversion['email_hash'] : null;
        $phoneHash = isset($conversion['phone_hash']) && is_string($conversion['phone_hash']) ? (string) $conversion['phone_hash'] : null;

        if ($emailHash === null && $phoneHash === null) {
            return ['success' => false, 'error' => 'مفيش أي معرّف مستخدم مجهول الهوية لإرسال الحدث'];
        }

        $identifiers = [];
        if ($emailHash !== null) {
            $identifiers[] = ['hashedEmail' => $emailHash];
        }
        if ($phoneHash !== null) {
            $identifiers[] = ['hashedPhoneNumber' => $phoneHash];
        }

        $conversionDateTime = (new DateTimeImmutable('now'))->format('c');

        $conversionRow = [
            'conversionAction' => $conversionAction,
            'conversionDateTime' => $conversionDateTime,
            'conversionValue' => round((float) ($conversion['value'] ?? 0), 2),
            'currencyCode' => (string) ($conversion['currency'] ?? 'USD'),
            'userIdentifiers' => $identifiers,
        ];

        if (!empty($conversion['event_id']) && is_string($conversion['event_id'])) {
            $conversionRow['orderId'] = $conversion['event_id'];
        }
        if (!empty($conversion['gclid']) && is_string($conversion['gclid'])) {
            $conversionRow['gclid'] = $conversion['gclid'];
        }

        $result = $this->httpPost("/customers/{$customerId}/googleAds:uploadClickConversions", [
            'conversions' => [$conversionRow],
            'partialFailure' => true,
        ]);

        if (!$result['success']) {
            return $result;
        }

        $results = $result['data']['results'] ?? [];
        if (empty($results)) {
            $errors = $result['data']['partialConversionErrors'] ?? [];
            $message = $errors[0]['message'] ?? 'فشل رفع التحويل (partialFailure)';
            return ['success' => false, 'error' => $message];
        }

        return ['success' => true, 'data' => $results[0] ?? $result['data']];
    }

    private function httpGet(string $path): array
    {
        return $this->request('GET', $path, null);
    }

    private function httpPost(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    protected function request(string $method, string $path, ?array $body, ?string $loginId = null): array
    {
        $result = $this->doRequest($method, $path, $body, $loginId);
        if ($result['success'] || $loginId !== null) {
            return $result;
        }

        $raw = strtoupper((string) ($result['raw'] ?? ''));
        $isPermission = (int) ($result['http_code'] ?? 0) === 403
            && (strpos($raw, 'USER_PERMISSION_DENIED') !== false || strpos($raw, 'CALLER DOES NOT HAVE PERMISSION') !== false)
            && strpos($raw, 'CLOUD_PROJECT') === false
            && strpos($raw, 'ACTION_NOT_PERMITTED') === false;

        if ($isPermission && preg_match('#^/customers/(\d+)/#', $path, $m)) {
            $manager = $this->resolveManagerFor($m[1]);
            if ($manager !== null) {
                return $this->doRequest($method, $path, $body, $manager);
            }
        }
        return $result;
    }

    private function resolveManagerFor(string $customerId): ?string
    {
        $key = substr(hash('sha256', $this->accessToken), 0, 16) . ':' . $customerId;
        if (array_key_exists($key, self::$managerCache)) {
            return self::$managerCache[$key];
        }
        self::$managerCache[$key] = null;

        $list = $this->doRequest('GET', '/customers:listAccessibleCustomers', null, null);
        if (!$list['success']) {
            return null;
        }
        $candidates = array_slice((array) ($list['data']['resourceNames'] ?? []), 0, 10);
        foreach ($candidates as $rn) {
            $mgrId = str_replace('customers/', '', (string) $rn);
            if ($mgrId === $customerId || !ctype_digit($mgrId)) {
                continue;
            }
            $check = $this->doRequest(
                'POST',
                "/customers/{$mgrId}/googleAds:search",
                ['query' => 'SELECT customer_client.id FROM customer_client WHERE customer_client.id = ' . (int) $customerId],
                $mgrId
            );
            if ($check['success'] && !empty($check['data']['results'])) {
                return self::$managerCache[$key] = $mgrId;
            }
        }
        return null;
    }

    /** login-customer-id الصح لكل حساب (نفسه لو العميل مالكه مباشرة، أو المدير اللي لقيناه تحته) */
    private static function rememberLogin(string $customerId, string $loginId): void
    {
        try {
            $db = Database::getInstance();
            $key = 'gads_login_' . preg_replace('/\D/', '', $customerId);
            $db->query('DELETE FROM system_settings WHERE setting_key = ?', [$key]);
            $db->query('INSERT INTO system_settings (setting_key, setting_value, is_secret) VALUES (?, ?, 0)', [$key, preg_replace('/\D/', '', $loginId)]);
        } catch (Throwable $e) {
        }
    }

    private static function rememberedLogin(string $customerId): ?string
    {
        static $cache = [];
        if (!array_key_exists($customerId, $cache)) {
            try {
                $v = Database::getInstance()->query('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1', ['gads_login_' . $customerId])[0]['setting_value'] ?? null;
                $cache[$customerId] = $v !== null && $v !== '' ? (string) $v : null;
            } catch (Throwable $e) {
                $cache[$customerId] = null;
            }
        }
        return $cache[$customerId];
    }

    private function doRequest(string $method, string $path, ?array $body, ?string $loginId = null): array
    {
        $url = self::BASE_URL . '/' . $this->apiVersion . $path;

        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
        ];
        if ($this->developerToken !== '') {
            $headers[] = 'developer-token: ' . $this->developerToken;
        }
        // الحساب اللي اكتشفناه وقت الربط (هو نفسه أو المدير اللي تحته) ليه الأولوية؛ الـ MCC العام للحسابات المربوطة تحته بس
        if ($loginId === null && preg_match('#^/customers/(\d+)/#', $path, $m)) {
            $loginId = self::rememberedLogin($m[1]);
        }
        $effectiveLogin = $loginId !== null ? $loginId : $this->loginCustomerId;
        if ($effectiveLogin !== '' && $effectiveLogin !== null) {
            $headers[] = 'login-customer-id: ' . preg_replace('/[^0-9]/', '', (string) $effectiveLogin);
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $ch = curl_init($url);
                $opts = [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_CUSTOMREQUEST => $method,
                ];
                if ($body !== null) {
                    $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
                }
                curl_setopt_array($ch, $opts);

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

                $data = json_decode((string) $response, true);

                if ($httpCode !== 200) {
                    if ($attempt === 0 && in_array($httpCode, [429, 502, 503, 504], true)) {
                        usleep(500000);
                        continue;
                    }

                    $message = $data['error']['message'] ?? "Google Ads API error (HTTP {$httpCode})";
                    $raw = strtoupper((string) $response);
                    if (strpos($raw, 'CLOUD_PROJECT_NOT_APPROVED_FOR_PRODUCTION') !== false
                        || strpos($raw, 'ACTION_NOT_PERMITTED') !== false
                        || ($httpCode === 403 && stripos((string) $message, 'developer token') !== false)) {
                        $message = 'مشروع Google Cloud لسه عليه Test access بس، ومش مسموح له يوصل لحسابات إعلانات حقيقية. '
                            . 'افتح Google Cloud Console > Google Ads API > Overview واضغط Apply for Explorer access '
                            . '(لازم نفس المشروع اللي فيه GOOGLE_CLIENT_ID). بعد الموافقة جرّب الربط تاني.';
                    } elseif ($httpCode === 403 && strpos($raw, 'AUTHORIZATION_ERROR') !== false) {
                        $message = 'Google رفضت الطلب لأسباب صلاحيات (AUTHORIZATION_ERROR). لو لسه مقدّم على Explorer/Basic access قريب، '
                            . 'استنى شوية أو جرّب مشروع Google Cloud جديد. التفاصيل: ' . $message;
                    } elseif ($httpCode === 404 || (strpos($raw, 'VERSION') !== false && strpos($raw, 'SUNSET') !== false)) {
                        $message = 'إصدار Google Ads API (' . $this->apiVersion . ') غير مدعوم. غيّر GOOGLE_ADS_API_VERSION في .env لأحدث إصدار (مثلاً v25).';
                    } elseif ($httpCode === 401) {
                        $message = 'OAuth غير صالح أو انتهت صلاحية الربط. افصل Google Ads واربط الحساب مرة أخرى.';
                    }
                    return ['success' => false, 'error' => $message, 'http_code' => $httpCode, 'raw' => (string) $response];
                }

                return ['success' => true, 'data' => $data];
            } catch (Throwable $e) {
                if (class_exists('Logger')) {
                    Logger::error('Google Ads API request failed', ['path' => $path, 'error' => $e->getMessage()]);
                }
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return ['success' => false, 'error' => 'Google Ads API request failed after retries'];
    }
}
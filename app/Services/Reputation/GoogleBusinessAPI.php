<?php

/**
 * Tourfecto - Google Business API Integration
 * تكامل مع Google Business Profile API
 * @version 2.0.0
 *
 * تصحيح جذري (2026-07-13): النسخة القديمة كانت بتاخد accessToken/accountId/
 * locationId من متغيرات .env ثابتة - يعني حساب Google Business واحد بس
 * للموقع كله. ده غلط تمامًا لمنتج SaaS كل عميل فيه لازم يربط حسابه هو.
 * دلوقتي الكلاس بياخد التوكن ومعرفات الحساب/الموقع كـ arguments في
 * الـ constructor (جايين من صف platform_connections الخاص بكل عميل)،
 * مش من إعدادات عامة.
 *
 * ملاحظة: sendReply() في النسخة القديمة كانت بترجع "success" فورًا من غير
 * ما تعمل أي طلب فعلي لـ Google - ده كان هيخلي الواجهة تقول "تم الرد"
 * والرد الحقيقي مبعتش. اتصلحت هنا عشان تنفّذ الطلب فعليًا.
 */
class GoogleBusinessAPI
{
    private const REVIEWS_BASE_URL = 'https://mybusiness.googleapis.com/v4';
    private const ACCOUNT_MGMT_BASE_URL = 'https://mybusinessaccountmanagement.googleapis.com/v1';
    private const BUSINESS_INFO_BASE_URL = 'https://mybusinessbusinessinformation.googleapis.com/v1';
    /** @since 2026-08-09 (GBP Module Upgrade) - Business Profile Performance API الجديد، بديل الـ Insights API القديم المُهجَّر رسميًا */
    private const PERFORMANCE_BASE_URL = 'https://businessprofileperformance.googleapis.com/v1';

    /**
     * DailyMetric enums المدعومة فعليًا في Business Profile Performance API
     * (لا تخترع Metric غير موجودة هنا - القائمة دي من توثيق Google الرسمي فقط).
     * @see https://developers.google.com/my-business/reference/performance/rest/v1/DailyMetric
     */
    public const SUPPORTED_METRICS = [
        'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
        'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
        'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
        'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
        'BUSINESS_CONVERSATIONS',
        'BUSINESS_DIRECTION_REQUESTS',
        'CALL_CLICKS',
        'WEBSITE_CLICKS',
    ];

    private string $accessToken;
    private ?string $accountId;
    private ?string $locationId;
    private int $timeout = 30;

    /**
     * @param string $accessToken توكن OAuth حقيقي خاص بحساب هذا العميل (مش من .env).
     * اختياري (فاضي افتراضيًا) عشان يفضل متوافق مع ReputationManager اللي
     * بيعمل `new GoogleBusinessAPI()` من غير توكن وقت الإنشاء، وبعدين أي
     * استدعاء فعلي للـ API هيرجع خطأ مصادقة واضح بدل ما يفشل الكلاس نفسه.
     * @param string|null $accountId
     * @param string|null $locationId
     */
    public function __construct(string $accessToken = '', ?string $accountId = null, ?string $locationId = null)
    {
        $this->accessToken = $accessToken;
        $this->accountId = $accountId;
        $this->locationId = $locationId;
    }

    /**
     * قائمة حسابات Google Business المتاحة لهذا التوكن. مطلوبة أول مرة
     * بعد الموافقة عشان نعرف نعرض للعميل يختار حسابه/فرعه لو عنده أكتر
     * من واحد.
     */
    public function listAccounts(): array
    {
        $response = $this->makeRequest('GET', self::ACCOUNT_MGMT_BASE_URL, '/accounts');
        if (!$response['success']) {
            return $response;
        }

        $accounts = array_map(function ($acc) {
            return [
                'id' => $this->extractId($acc['name'] ?? ''),
                'name' => $acc['accountName'] ?? $acc['name'] ?? '',
                'type' => $acc['type'] ?? null,
            ];
        }, $response['data']['accounts'] ?? []);

        return ['success' => true, 'accounts' => $accounts];
    }

    /**
     * قائمة الفروع/المواقع (locations) تحت حساب معيّن.
     */
    public function listLocations(string $accountId): array
    {
        $response = $this->makeRequest(
            'GET',
            self::BUSINESS_INFO_BASE_URL,
            "/accounts/{$accountId}/locations",
            ['readMask' => 'name,title,storefrontAddress']
        );
        if (!$response['success']) {
            return $response;
        }

        $locations = array_map(function ($loc) {
            return [
                'id' => $this->extractId($loc['name'] ?? ''),
                'name' => $loc['title'] ?? '',
                'address' => $loc['storefrontAddress']['addressLines'][0] ?? null,
            ];
        }, $response['data']['locations'] ?? []);

        return ['success' => true, 'locations' => $locations];
    }

    /**
     * جلب المراجعات
     * @param array $params - معاملات الطلب
     * @return array
     */
    public function getReviews(array $params = []): array
    {
        try {
            $accountId = $params['account_id'] ?? $this->accountId;
            $locationId = $params['location_id'] ?? $this->locationId;
            if (!$accountId || !$locationId) {
                return ['success' => false, 'error' => 'Account ID و Location ID مطلوبين'];
            }

            $endpoint = "/accounts/{$accountId}/locations/{$locationId}/reviews";

            $query = array_filter([
                'pageSize' => $params['limit'] ?? 20,
                'pageToken' => $params['page_token'] ?? null,
            ], fn ($v) => $v !== null);

            $response = $this->makeRequest('GET', self::REVIEWS_BASE_URL, $endpoint, $query);

            if (!$response['success']) {
                return $response;
            }

            $reviews = $this->parseReviews($response['data']);

            return [
                'success' => true,
                'reviews' => $reviews,
                'total' => $response['data']['totalReviewCount'] ?? 0,
                'next_page_token' => $response['data']['nextPageToken'] ?? null,
                'source' => 'google_business'
            ];

        } catch (Exception $e) {
            Logger::error('Google Business Get Reviews Error', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * إرسال رد فعلي على مراجعة عن طريق Google API (PUT reply).
     * @param string $reviewId
     * @param string $reply
     * @return array
     */
    public function sendReply(string $reviewId, string $reply): array
    {
        try {
            if (!$this->accountId || !$this->locationId) {
                return ['success' => false, 'error' => 'Account ID و Location ID مطلوبين'];
            }

            $endpoint = "/accounts/{$this->accountId}/locations/{$this->locationId}/reviews/{$reviewId}/reply";

            $response = $this->makeRequest('PUT', self::REVIEWS_BASE_URL, $endpoint, [], ['comment' => $reply]);

            if (!$response['success']) {
                return $response;
            }

            return [
                'success' => true,
                'review_id' => $reviewId,
                'reply_sent' => true,
                'message' => 'Reply sent successfully'
            ];

        } catch (Exception $e) {
            Logger::error('Google Business Send Reply Error', [
                'review_id' => $reviewId,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * جلب معلومات الموقع
     * @param string|null $locationId
     * @return array
     */
    public function getLocation(?string $locationId = null): array
    {
        try {
            $locationId = $locationId ?? $this->locationId;
            if (!$locationId) {
                return ['success' => false, 'error' => 'Location ID مطلوب'];
            }

            // تصحيح (GBP Module Upgrade - Round 5, 2026-08-10): تأكدنا من
            // توثيق Google الرسمي (My Business Business Information API
            // change log) إن locations.get/patch بقى resource name مباشر
            // "locations/{location_id}" من غير accounts/{account}/ - المسار
            // القديم اتغيّر من زمان. accounts/{account}/locations لسه
            // صحيح بس لعملية LIST (شوف listLocations() تحت).
            $endpoint = "/locations/{$locationId}";
            // وسّعنا readMask عشان تدعم Profile Management/Completeness
            // Score - كل حقل هنا مدعوم فعليًا من Business Information API
            // الرسمي، مفيش حقل مخترع.
            $response = $this->makeRequest('GET', self::BUSINESS_INFO_BASE_URL, $endpoint, [
                'readMask' => 'name,title,phoneNumbers,websiteUri,storefrontAddress,profile,categories,'
                    . 'regularHours,openInfo,metadata,labels,storeCode,latlng',
            ]);

            if (!$response['success']) {
                return $response;
            }

            $data = $response['data'];

            return [
                'success' => true,
                'location' => [
                    'id' => $data['name'] ?? null,
                    'name' => $data['title'] ?? null,
                    'address' => $data['storefrontAddress'] ?? null,
                    'phone' => $data['phoneNumbers']['primaryPhone'] ?? null,
                    'website' => $data['websiteUri'] ?? null,
                    'description' => $data['profile']['description'] ?? null,
                    'primary_category' => $data['categories']['primaryCategory']['displayName'] ?? null,
                    'additional_categories' => array_map(
                        fn ($c) => $c['displayName'] ?? '',
                        $data['categories']['additionalCategories'] ?? []
                    ),
                    'regular_hours' => $data['regularHours']['periods'] ?? null,
                    'store_code' => $data['storeCode'] ?? null,
                    'is_published' => $data['metadata']['hasVoiceOfMerchant'] ?? null,
                    'can_delete' => $data['metadata']['canDelete'] ?? null,
                    'maps_uri' => $data['metadata']['mapsUri'] ?? null,
                    'place_id' => $data['metadata']['placeId'] ?? null,
                    'new_review_uri' => $data['metadata']['newReviewUri'] ?? null,
                    'latitude' => $data['latlng']['latitude'] ?? null,
                    'longitude' => $data['latlng']['longitude'] ?? null,
                    // Phase 9 (Google Business Agent - Consolidated Module): مطلوبة
                    // لـ GbpProfileScoreService::calculateCompletenessScore() - إضافي
                    // بالكامل، متلمستش latitude/longitude الموجودين فوق.
                    'has_coordinates' => isset($data['latlng']),
                ]
            ];

        } catch (Exception $e) {
            Logger::error('Google Business Get Location Error', [
                'location_id' => $locationId,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * تعديل حقول بروفايل Google Business Profile الفعلية (Business Information API).
     * بيدعم بس الحقول اللي الـ API الرسمي فعليًا بيسمح بتعديلها عن طريق patch.
     * @param string|null $locationId
     * @param array $fields ['description'=>?, 'phone'=>?, 'website'=>?] - كل مفتاح اختياري
     * @return array ['success'=>bool, 'error'=>?string]
     * @since 2026-08-09 (GBP Module Upgrade)
     */
    public function updateLocation(?string $locationId, array $fields): array
    {
        $locationId = $locationId ?? $this->locationId;
        if (!$locationId) {
            return ['success' => false, 'error' => 'Location ID مطلوب'];
        }

        $body = [];
        $updateMask = [];

        if (array_key_exists('description', $fields)) {
            $body['profile'] = ['description' => (string) $fields['description']];
            $updateMask[] = 'profile';
        }
        if (array_key_exists('phone', $fields)) {
            $body['phoneNumbers'] = ['primaryPhone' => (string) $fields['phone']];
            $updateMask[] = 'phoneNumbers';
        }
        if (array_key_exists('website', $fields)) {
            $body['websiteUri'] = (string) $fields['website'];
            $updateMask[] = 'websiteUri';
        }
        if (array_key_exists('regular_hours', $fields) && is_array($fields['regular_hours'])) {
            $body['regularHours'] = ['periods' => $fields['regular_hours']];
            $updateMask[] = 'regularHours';
        }

        if (empty($updateMask)) {
            return ['success' => false, 'error' => 'لا يوجد أي حقل مدعوم للتعديل'];
        }

        // تصحيح (Round 5): نفس تصحيح getLocation() - locations/{id} مباشرة من غير accounts/
        $endpoint = "/locations/{$locationId}";
        $response = $this->makeRequest(
            'PATCH',
            self::BUSINESS_INFO_BASE_URL,
            $endpoint,
            ['updateMask' => implode(',', $updateMask)],
            $body
        );

        if (!$response['success']) {
            return $response;
        }

        return ['success' => true, 'location' => $response['data']];
    }

    /**
     * قائمة الوسائط (الصور) المرتبطة بالـ location. Media API الرسمي
     * (accounts.locations.media) - مش مخترع.
     * تصحيح (Round 5، 2026-08-10): Media API فعليًا لسه تحت My Business
     * API v4 القديم (mybusiness.googleapis.com/v4)، مش تحت Business
     * Information API v1 - تأكدنا من توثيق Google الرسمي (upload-photos
     * guide) قبل التصحيح. المسار accounts/{account}/locations/{location}/media
     * نفسه كان صحيح، الدومين بس كان غلط.
     * @since 2026-08-09 (GBP Module Upgrade)
     */
    public function listMedia(?string $locationId = null): array
    {
        $locationId = $locationId ?? $this->locationId;
        if (!$locationId || !$this->accountId) {
            return ['success' => false, 'error' => 'Account ID و Location ID مطلوبين'];
        }

        $endpoint = "/accounts/{$this->accountId}/locations/{$locationId}/media";
        $response = $this->makeRequest('GET', self::REVIEWS_BASE_URL, $endpoint, ['pageSize' => 100]);

        if (!$response['success']) {
            return $response;
        }

        $items = array_map(function ($m) {
            return [
                'id' => $this->extractId($m['name'] ?? ''),
                'name' => $m['name'] ?? null,
                'category' => $m['category'] ?? null,
                'format' => $m['mediaFormat'] ?? null,
                'source_url' => $m['sourceUrl'] ?? null,
                'google_url' => $m['googleUrl'] ?? null,
                'thumbnail_url' => $m['thumbnailUrl'] ?? null,
                'create_time' => $m['createTime'] ?? null,
            ];
        }, $response['data']['mediaItems'] ?? []);

        return ['success' => true, 'media' => $items];
    }

    /**
     * رفع صورة عن طريق sourceUrl عام (الطريقة الوحيدة المدعومة رسميًا من
     * Media API بدون رفع بايتات مباشرة عبر multipart، اللي مش مدعوم في
     * REST endpoint ده). لازم الصورة تكون متاحة على رابط https عام أولًا
     * (بنرفعها لتخزين المشروع الحالي ثم نبعت رابطها لجوجل).
     * @param string $category واحدة من: COVER, PROFILE, EXTERIOR, INTERIOR, PRODUCT, AT_WORK, FOOD_AND_DRINK, MENU, COMMON_AREA, ROOMS, TEAMS, ADDITIONAL
     * @since 2026-08-09 (GBP Module Upgrade)
     */
    public function insertMedia(?string $locationId, string $sourceUrl, string $category = 'ADDITIONAL'): array
    {
        $locationId = $locationId ?? $this->locationId;
        if (!$locationId || !$this->accountId) {
            return ['success' => false, 'error' => 'Account ID و Location ID مطلوبين'];
        }

        $endpoint = "/accounts/{$this->accountId}/locations/{$locationId}/media";
        $response = $this->makeRequest('POST', self::REVIEWS_BASE_URL, $endpoint, [], [
            'mediaFormat' => 'PHOTO',
            'locationAssociation' => ['category' => $category],
            'sourceUrl' => $sourceUrl,
        ]);

        if (!$response['success']) {
            return $response;
        }

        return [
            'success' => true,
            'media' => [
                'id' => $this->extractId($response['data']['name'] ?? ''),
                'name' => $response['data']['name'] ?? null,
                'google_url' => $response['data']['googleUrl'] ?? null,
                'thumbnail_url' => $response['data']['thumbnailUrl'] ?? null,
            ],
        ];
    }

    /**
     * حذف صورة. Media API الرسمي بيدعم delete على media resource name كامل
     * (accounts/{a}/locations/{l}/media/{m}).
     * @since 2026-08-09 (GBP Module Upgrade)
     */
    public function deleteMedia(string $mediaResourceName): array
    {
        // مسموح نستقبل الاسم الكامل أو المعرف بس؛ لو معرف بس نبنيه بالكامل.
        if (strpos($mediaResourceName, 'accounts/') !== 0) {
            if (!$this->accountId || !$this->locationId) {
                return ['success' => false, 'error' => 'Account ID و Location ID مطلوبين'];
            }
            $mediaResourceName = "accounts/{$this->accountId}/locations/{$this->locationId}/media/{$mediaResourceName}";
        }

        $endpoint = '/' . $mediaResourceName;
        $response = $this->makeRequest('DELETE', self::REVIEWS_BASE_URL, $endpoint);

        if (!$response['success']) {
            return $response;
        }

        return ['success' => true];
    }

    /**
     * Attributes (بند 4/5 بالسبيك) - Google API الرسمي (Business
     * Information API v1) - 3 عمليات منفصلة ومؤكدة من توثيق Google
     * الرسمي (developers.google.com/my-business/reference/businessinformation):
     *
     * 1. listAvailableAttributes() → GET /v1/attributes?parent=locations/{id}
     *    (attributes.list): بيرجع الـ Attribute IDs الصحيحة فعليًا
     *    المتاحة لتصنيف نشاط الـ location ده بالذات - مفيش تخمين خالص.
     * 2. getAttributes() → GET /v1/locations/{id}/attributes: القيم
     *    المضبوطة حاليًا.
     * 3. updateAttributes() → PATCH /v1/locations/{id}/attributes: تعديل
     *    القيم.
     *
     * ⚠️ ملحوظة أمانة (Round 7 - Production Finalization): وثّقنا الـ
     * request/response shape دي من توثيق Google الرسمي المتاح للقراءة،
     * لكن معندناش حساب Google حقيقي نجرب بيه فعليًا وقت كتابة الكود ده.
     * LIVE GOOGLE TEST REQUIRED قبل الاعتماد عليها 100% في بيئة إنتاج.
     * @since 2026-08-10 (Round 5), محدّثة 2026-08-14 (Round 7)
     */
    public function listAvailableAttributes(?string $locationId = null): array
    {
        $locationId = $locationId ?? $this->locationId;
        if (!$locationId) {
            return ['success' => false, 'error' => 'Location ID مطلوب'];
        }

        // الـ endpoint ده top-level (مش تحت /locations/) - لما parent
        // مضبوط، مش لازم categoryName/regionCode/languageCode/showAll.
        $response = $this->makeRequest('GET', self::BUSINESS_INFO_BASE_URL, '/attributes', [
            'parent' => "locations/{$locationId}",
            'pageSize' => 200,
        ]);

        if (!$response['success']) {
            return $response;
        }

        $items = array_map(function ($meta) {
            $options = array_map(fn ($vm) => [
                'value' => $vm['value'] ?? '',
                'display_name' => $vm['displayName'] ?? ($vm['value'] ?? ''),
            ], $meta['valueMetadata'] ?? []);

            return [
                'attribute_id' => $meta['attributeId'] ?? '',
                'display_name' => $meta['displayName'] ?? ($meta['attributeId'] ?? ''),
                'group_display_name' => $meta['groupDisplayName'] ?? null,
                'value_type' => $meta['valueType'] ?? 'UNKNOWN',
                'repeatable' => (bool) ($meta['repeatable'] ?? false),
                'deprecated' => (bool) ($meta['deprecated'] ?? false),
                'options' => $options, // للـ ENUM/REPEATED_ENUM بس - فاضية لغير كده
            ];
        }, $response['data']['attributeMetadata'] ?? []);

        return ['success' => true, 'available_attributes' => array_values(array_filter($items, fn ($i) => !$i['deprecated']))];
    }

    public function getAttributes(?string $locationId = null): array
    {
        $locationId = $locationId ?? $this->locationId;
        if (!$locationId) {
            return ['success' => false, 'error' => 'Location ID مطلوب'];
        }

        $endpoint = "/locations/{$locationId}/attributes";
        $response = $this->makeRequest('GET', self::BUSINESS_INFO_BASE_URL, $endpoint);

        if (!$response['success']) {
            return $response;
        }

        $items = array_map(function ($attr) {
            $valueType = $attr['valueType'] ?? 'UNKNOWN';
            $current = null;

            if ($valueType === 'BOOL') {
                $rawValues = $attr['values'] ?? [];
                $current = isset($rawValues[0]) ? (bool) $rawValues[0] : null;
            } elseif ($valueType === 'REPEATED_ENUM') {
                $current = ['set' => $attr['repeatedEnumValue']['setValues'] ?? [], 'unset' => $attr['repeatedEnumValue']['unsetValues'] ?? []];
            } elseif ($valueType === 'URL') {
                $current = array_map(fn ($u) => $u['uri'] ?? '', $attr['uriValues'] ?? []);
            }

            return [
                'attribute_id' => $attr['name'] ?? '', // الحقل اسمه "name" في الـ response الرسمي، مش attributeId
                'value_type' => $valueType,
                'current_value' => $current,
            ];
        }, $response['data']['attributes'] ?? []);

        return ['success' => true, 'attributes' => $items];
    }

    /**
     * @param array $changes ['attribute_id' => ['type'=>'BOOL','value'=>bool]]
     *                        أو ['type'=>'REPEATED_ENUM','set'=>[...],'unset'=>[...]]
     *                        أو ['type'=>'URL','values'=>['https://...']]
     */
    public function updateAttributes(?string $locationId, array $changes): array
    {
        $locationId = $locationId ?? $this->locationId;
        if (!$locationId) {
            return ['success' => false, 'error' => 'Location ID مطلوب'];
        }
        if (empty($changes)) {
            return ['success' => false, 'error' => 'لا يوجد Attributes للتحديث'];
        }

        $attributes = [];
        foreach ($changes as $attributeId => $change) {
            $type = $change['type'] ?? 'BOOL';
            $entry = ['name' => $attributeId]; // "name" الحقل الصحيح لتحديد الـ attribute، مش attributeId

            if ($type === 'BOOL') {
                $entry['values'] = [(bool) ($change['value'] ?? false)];
            } elseif ($type === 'REPEATED_ENUM') {
                $entry['repeatedEnumValue'] = [
                    'setValues' => $change['set'] ?? [],
                    'unsetValues' => $change['unset'] ?? [],
                ];
            } elseif ($type === 'URL') {
                $entry['uriValues'] = array_map(fn ($u) => ['uri' => $u], $change['values'] ?? []);
            } else {
                continue; // نوع غير مدعوم - نتجاهله بدل ما نبعت طلب هيترفض من جوجل
            }

            $attributes[] = $entry;
        }

        if (empty($attributes)) {
            return ['success' => false, 'error' => 'Unsupported by Current API: كل الـ Attributes المطلوبة من نوع غير مدعوم'];
        }

        $endpoint = "/locations/{$locationId}/attributes";
        $body = ['name' => "locations/{$locationId}/attributes", 'attributes' => $attributes];

        $response = $this->makeRequest(
            'PATCH',
            self::BUSINESS_INFO_BASE_URL,
            $endpoint,
            ['attributeMask' => implode(',', array_keys($changes))],
            $body
        );

        if (!$response['success']) {

            return $response;
        }

        return ['success' => true];
    }

    /**
     * جلب مقاييس أداء حقيقية من Business Profile Performance API الجديد
     * (locations.fetchMultiDailyMetricsTimeSeries) - البديل الرسمي لـ
     * Insights API القديم المُهجَّر بالكامل. لا بيانات وهمية أبدًا: أي
     * metric مش موجود في SUPPORTED_METRICS بيترفض قبل حتى ما نبعت الطلب.
     * @param string[] $metrics عناصر من SUPPORTED_METRICS فقط
     * @param string $startDate Y-m-d
     * @param string $endDate Y-m-d
     * @since 2026-08-09 (GBP Module Upgrade)
     */
    public function fetchDailyMetrics(?string $locationId, array $metrics, string $startDate, string $endDate): array
    {
        $locationId = $locationId ?? $this->locationId;
        if (!$locationId) {
            return ['success' => false, 'error' => 'Location ID مطلوب'];
        }

        $metrics = array_values(array_intersect($metrics, self::SUPPORTED_METRICS));
        if (empty($metrics)) {
            return ['success' => false, 'error' => 'Unsupported by Current API: كل الـ metrics المطلوبة غير مدعومة'];
        }

        $start = date_create($startDate);
        $end = date_create($endDate);
        if (!$start || !$end) {
            return ['success' => false, 'error' => 'تواريخ غير صحيحة'];
        }

        $query = ['dailyMetrics' => $metrics];
        // http_build_query هيحول array لـ dailyMetrics[]=..، لكن Google
        // محتاجها dailyMetrics=A&dailyMetrics=B، فبنبنيها يدويًا في makeRequest.
        $query['dailyRange.start_date.year'] = (int) $start->format('Y');
        $query['dailyRange.start_date.month'] = (int) $start->format('n');
        $query['dailyRange.start_date.day'] = (int) $start->format('j');
        $query['dailyRange.end_date.year'] = (int) $end->format('Y');
        $query['dailyRange.end_date.month'] = (int) $end->format('n');
        $query['dailyRange.end_date.day'] = (int) $end->format('j');

        $endpoint = "/locations/{$locationId}:fetchMultiDailyMetricsTimeSeries";
        $response = $this->makeRequest('GET', self::PERFORMANCE_BASE_URL, $endpoint, $query);

        if (!$response['success']) {
            return $response;
        }

        $series = [];
        foreach ($response['data']['multiDailyMetricTimeSeries'] ?? [] as $group) {
            foreach ($group['dailyMetricTimeSeries'] ?? [] as $entry) {
                $metric = $entry['dailyMetric'] ?? 'UNKNOWN';
                $points = [];
                foreach ($entry['timeSeries']['datedValues'] ?? [] as $dv) {
                    $d = $dv['date'] ?? [];
                    if (empty($d)) {
                        continue;
                    }
                    $date = sprintf('%04d-%02d-%02d', $d['year'] ?? 0, $d['month'] ?? 1, $d['day'] ?? 1);
                    $points[] = ['date' => $date, 'value' => isset($dv['value']) ? (int) $dv['value'] : 0];
                }
                $series[$metric] = $points;
            }
        }

        return ['success' => true, 'metrics' => $series];
    }

    /**
     * نشر منشور (Post) على Google Business Profile.
     * بيستخدم Local Posts API الرسمي - نفس المنشورات اللي بتظهر تحت
     * "Updates" في صفحة نشاطك على خرائط جوجل/البحث.
     * @param string $summary نص المنشور
     * @param string $languageCode كود اللغة (ar, en, ...)
     * @param string|null $ctaUrl رابط اختياري لزرار "Call to Action" (لو موجود بيبقى نوعه LEARN_MORE)
     * @return array ['success'=>bool, 'post_id'=>?string, 'error'=>?string]
     */
    public function publishPost(string $summary, string $languageCode = 'ar', ?string $ctaUrl = null, array $extra = []): array
    {
        try {
            if (!$this->accountId || !$this->locationId) {
                return ['success' => false, 'error' => 'Account ID و Location ID مطلوبين'];
            }

            $endpoint = "/accounts/{$this->accountId}/locations/{$this->locationId}/localPosts";

            $type = (string) ($extra['type'] ?? 'update');
            $topic = ['offer' => 'OFFER', 'event' => 'EVENT'][$type] ?? 'STANDARD';
            $data = [
                'languageCode' => $languageCode,
                'summary' => $summary,
                'topicType' => $topic,
            ];

            // عرض/فعالية: Google محتاج عنوان + مدة
            if ($topic !== 'STANDARD' && !empty($extra['title']) && !empty($extra['start']) && !empty($extra['end'])) {
                $dt = static function (string $v): array {
                    $t = strtotime($v);
                    return [
                        'date' => ['year' => (int) date('Y', $t), 'month' => (int) date('n', $t), 'day' => (int) date('j', $t)],
                        'time' => ['hours' => (int) date('G', $t), 'minutes' => (int) date('i', $t), 'seconds' => 0, 'nanos' => 0],
                    ];
                };
                $s = $dt((string) $extra['start']);
                $e = $dt((string) $extra['end']);
                $data['event'] = [
                    'title' => mb_substr((string) $extra['title'], 0, 58),
                    'schedule' => ['startDate' => $s['date'], 'startTime' => $s['time'], 'endDate' => $e['date'], 'endTime' => $e['time']],
                ];
            }
            if ($topic === 'OFFER') {
                $offer = array_filter([
                    'couponCode' => $extra['coupon_code'] ?? null,
                    'termsConditions' => $extra['terms'] ?? null,
                    'redeemOnlineUrl' => $extra['cta_url'] ?? $ctaUrl,
                ]);
                if ($offer) {
                    $data['offer'] = $offer;
                }
            } else {
                // زرار: نوعه من المنشور (احجز/اطلب/اتصل...)، والافتراضي "اعرف أكتر" على موقعك
                $action = strtoupper((string) ($extra['cta_type'] ?? '')) ?: 'LEARN_MORE';
                $url = (string) ($extra['cta_url'] ?? '') ?: (string) $ctaUrl;
                if ($action === 'CALL') {
                    $data['callToAction'] = ['actionType' => 'CALL'];
                } elseif ($url !== '') {
                    $data['callToAction'] = ['actionType' => $action, 'url' => $url];
                }
            }

            // صورة المنشور (اختياري): رابط عام https - Google بتسحبها بنفسها
            if (!empty($extra['media_url']) && preg_match('#^https://#i', (string) $extra['media_url'])) {
                $data['media'] = [['mediaFormat' => 'PHOTO', 'sourceUrl' => (string) $extra['media_url']]];
            }

            $response = $this->makeRequest('POST', self::REVIEWS_BASE_URL, $endpoint, [], $data);

            if (!$response['success']) {
                return $response;
            }

            return [
                'success' => true,
                'post_id' => $this->extractId($response['data']['name'] ?? ''),
                'post_url' => $response['data']['searchUrl'] ?? null,
            ];

        } catch (Exception $e) {
            Logger::error('Google Business Publish Post Error', [
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * إرسال طلب إلى API. بياخد الـ base URL كـ argument دلوقتي عشان
     * endpoints جوجل الحديثة متقسّمة على أكتر من دومين (Account Management/
     * Business Information/Reviews القديم).
     */
    /**
     * تصنيف أخطاء Google لرسالة عربية مفهومة للمستخدم + كود ثابت للـ
     * Frontend (بدل ما نعرض نص Google الخام أو أي Stack Trace/Secrets).
     * Round 8 (2026-08-14 - Phase AI): وحّدنا أسماء الأكواد مع القائمة
     * القياسية المطلوبة (AUTH_REQUIRED/TOKEN_EXPIRED/PERMISSION_DENIED/
     * NOT_FOUND/INVALID_ARGUMENT/RATE_LIMITED/GOOGLE_UNAVAILABLE/
     * NETWORK_ERROR/QUOTA_EXCEEDED/NOT_SUPPORTED/INTERNAL_ERROR) - تأكدنا
     * الأول (grep) إن error_code مش متطابق عليه (matched) في أي مكان
     * تاني في الكود، فالتغيير ده آمن ومفيش API Contract اتكسر.
     * @since 2026-08-09 (GBP Module Upgrade - Round 3), محدّثة Round 8
     */
    private static function classifyError(int $httpCode, string $rawMessage, ?string $googleStatus): array
    {
        $lower = strtolower($rawMessage);

        if (strpos($lower, 'token has expired') !== false || strpos($lower, 'invalid_grant') !== false) {
            return ['code' => 'TOKEN_EXPIRED', 'message' => 'انتهت صلاحية الجلسة - يحتاج إعادة ربط (Reconnect)'];
        }
        if ($httpCode === 401 || strpos($lower, 'invalid authentication') !== false || strpos($lower, 'invalid credentials') !== false) {
            return ['code' => 'AUTH_REQUIRED', 'message' => 'بيانات الاعتماد غير صحيحة أو منتهية - يحتاج إعادة ربط (Reconnect)'];
        }
        // تصحيح: الترتيب كان بيخلي 3 أسباب مختلفة تمامًا تطلع كلها "صلاحيات غير كافية".
        if ($httpCode === 403 && (strpos($lower, 'service_disabled') !== false || strpos($lower, 'has not been used') !== false || strpos($lower, 'api not enabled') !== false || strpos($lower, 'is disabled') !== false)) {
            return ['code' => 'NOT_SUPPORTED', 'message' => 'واجهات Google Business Profile مش مفعّلة على مشروع Google Cloud بتاع المنصة (My Business Account Management API + Business Information API) - لازم تتفعّل من Google Cloud Console'];
        }
        if (strpos($lower, 'scope_insufficient') !== false || strpos($lower, 'insufficient authentication scopes') !== false) {
            return ['code' => 'SCOPE_MISSING', 'message' => 'Google ما اداش صلاحية إدارة النشاط التجاري. اربط تاني وعلّم على صلاحية "See, edit, create and delete your Google business listings" في شاشة Google'];
        }
        if ($httpCode === 403 && (strpos($lower, 'permission') !== false || strpos($lower, 'insufficient') !== false)) {
            return ['code' => 'PERMISSION_DENIED', 'message' => 'Google رفض الطلب (Permission denied). غالبًا مشروع Google Cloud بتاع المنصة لسه ما اتوافقش عليه لاستخدام Business Profile API، أو حساب Google ده مش مالك/مدير على الملف التجاري'];
        }
        if ($httpCode === 429 && (strpos($lower, 'mybusiness') !== false || strpos($lower, 'per minute') !== false) && strpos($lower, "of service") !== false) {
            return ['code' => 'QUOTA_EXCEEDED', 'message' => 'حصة Business Profile API على مشروع Google Cloud بتاع المنصة = صفر. ده معناه إن طلب الوصول للـ API لسه ما اتوافقش عليه من Google'];
        }
        if ($httpCode === 429 && strpos($lower, 'quota') !== false) {
            return ['code' => 'QUOTA_EXCEEDED', 'message' => 'تم تجاوز الحد المسموح به من Google لهذا اليوم - حاول لاحقًا'];
        }
        if ($httpCode === 429 || strpos($lower, 'rate limit') !== false) {
            return ['code' => 'RATE_LIMITED', 'message' => 'طلبات كتيرة في وقت قصير - انتظر شوية وحاول تاني'];
        }
        if ($httpCode === 404 || strpos($lower, 'not found') !== false) {
            return ['code' => 'NOT_FOUND', 'message' => 'الموقع/العنصر غير موجود على Google أو تم حذفه'];
        }
        if ($httpCode === 400 || strpos($lower, 'invalid argument') !== false) {
            return ['code' => 'INVALID_ARGUMENT', 'message' => 'بيانات الطلب غير صحيحة'];
        }
        if ($httpCode >= 500) {
            return ['code' => 'GOOGLE_UNAVAILABLE', 'message' => 'خطأ مؤقت من خوادم Google - حاول لاحقًا'];
        }

        return ['code' => 'INTERNAL_ERROR', 'message' => 'حدث خطأ من Google Business Profile API'];
    }

    private function makeRequest(string $method, string $baseUrl, string $endpoint, array $query = [], array $data = []): array
    {
        $url = $baseUrl . $endpoint;

        if (!empty($query)) {
            // تصحيح (GBP Module Upgrade 2026-08-09): http_build_query() العادية
            // بتحول array لصيغة key[0]=A&key[1]=B، لكن Google محتاج صيغة
            // key=A&key=B (زي dailyMetrics في Performance API) - بنبنيها يدويًا.
            $parts = [];
            foreach ($query as $key => $value) {
                if (is_array($value)) {
                    foreach ($value as $v) {
                        $parts[] = rawurlencode($key) . '=' . rawurlencode((string) $v);
                    }
                } else {
                    $parts[] = rawurlencode($key) . '=' . rawurlencode((string) $value);
                }
            }
            $url .= '?' . implode('&', $parts);
        }

        $headers = ['Accept: application/json'];
        if ($this->accessToken) {
            $headers[] = 'Authorization: Bearer ' . $this->accessToken;
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            // Round 8 (2026-08-14 - GBP Professional Finalization / Phase B):
            // Connect timeout منفصل عن Total timeout - كانت مضبوطة بس
            // CURLOPT_TIMEOUT، فطلب معلّق على DNS/TCP handshake كان ممكن
            // ياخد لحد 30 ثانية كاملة قبل ما يفشل.
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Tourfecto/1.0'
        ];

        if (!empty($data)) {
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_UNICODE);
        }

        // Round 8 (Phase B): Retry policy حقيقي - بس على العمليات الآمنة
        // للإعادة (GET/PATCH/DELETE - Idempotent فعليًا في Business
        // Information API). POST (زي insertMedia/publishPost) بيتعمله
        // محاولة واحدة بس هنا - إعادة POST تلقائيًا ممكن تعمل نسخة
        // مكررة فعلية على حساب العميل الحقيقي على Google (صورة أو
        // منشور اتنين بدل واحد). أي إعادة محاولة لـ POST لازم تتم على
        // مستوى الـ Job نفسه (Queue retry)، اللي أصلاً محمي بـ
        // Idempotency guard (شوف PublishGbpPostJob).
        $isRetryable = in_array($method, ['GET', 'PATCH', 'DELETE'], true);
        $maxAttempts = $isRetryable ? 3 : 1;
        $retryableHttpCodes = [429, 500, 502, 503, 504];

        $response = null;
        $httpCode = 0;
        $curlError = '';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, $options);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $isNetworkError = $curlError !== '';
            $isRetryableHttp = in_array($httpCode, $retryableHttpCodes, true);

            if (!$isNetworkError && !$isRetryableHttp) {
                break; // نجح أو فشل بخطأ نهائي (400/401/403/404...) - منعادش المحاولة
            }
            if ($attempt >= $maxAttempts) {
                break; // خلصت المحاولات المسموحة
            }

            // Exponential backoff: 500ms, 1s, (لو احتجنا محاولة تالتة كانت هتبقى 2s) - محدود بحد أقصى معقول
            $backoffMs = min(2000, 500 * (2 ** ($attempt - 1)));
            usleep($backoffMs * 1000);

            if (class_exists('Logger')) {
                Logger::info('GBP API retry', ['method' => $method, 'attempt' => $attempt + 1, 'http_code' => $httpCode, 'network_error' => $isNetworkError]);
            }
        }

        if ($curlError) {
            return ['success' => false, 'error' => 'تعذر الاتصال بخوادم Google - تحقق من الشبكة وحاول تاني', 'error_code' => 'NETWORK_ERROR'];
        }

        // Round 8 (Phase B): تحقق فعلي من نجاح json_decode قبل ما نستخدم
        // النتيجة - كان ممكن Google يرجع HTML (صفحة خطأ من Load Balancer
        // مثلاً) في حالات نادرة، وكان $decoded['error']['message'] هيرجع
        // null بصمت من غير ما نعرف إن الـ response أصلاً مش JSON صالح.
        $decoded = json_decode((string) $response, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE && $httpCode >= 200 && $httpCode < 300) {
            return ['success' => false, 'error' => 'رد غير متوقع من Google (مش JSON صالح)', 'error_code' => 'GOOGLE_UNAVAILABLE'];
        }
        $decoded = $decoded ?? [];


        if ($httpCode < 200 || $httpCode >= 300) {
            $errorMessage = $decoded['error']['message'] ?? 'Unknown error';
            $googleStatus = $decoded['error']['status'] ?? null;
            // سبب Google الدقيق (SERVICE_DISABLED / ACCESS_TOKEN_SCOPE_INSUFFICIENT / RATE_LIMIT_EXCEEDED ...)
            $googleReason = '';
            foreach ((array) ($decoded['error']['details'] ?? []) as $d) {
                if (!empty($d['reason'])) { $googleReason = (string) $d['reason']; break; }
            }
            $classified = self::classifyError($httpCode, $errorMessage . ' ' . $googleReason, $googleStatus);
            if (class_exists('Logger')) {
                Logger::warning('GBP API error', ['http' => $httpCode, 'status' => $googleStatus, 'reason' => $googleReason, 'message' => mb_substr($errorMessage, 0, 300)]);
            }
            return [
                'success' => false,
                'error' => $classified['message'],
                'error_code' => $classified['code'],
                'http_code' => $httpCode,
                'google_status' => $googleStatus,
                'google_reason' => $googleReason,
                // نص Google الخام (من غير توكنات) - بيساعد في التشخيص من شاشة الخطأ
                'google_message' => mb_substr(preg_replace('/ya29\.[\w\-\.]+/', '***', $errorMessage), 0, 300),
            ];
        }

        return ['success' => true, 'data' => $decoded, 'http_code' => $httpCode];
    }

    /**
     * تحليل بيانات المراجعات
     */
    private function parseReviews(array $data): array
    {
        $reviews = [];

        $items = $data['reviews'] ?? [];
        foreach ($items as $item) {
            $reviews[] = [
                'id' => $this->extractId($item['name'] ?? ''),
                'rating' => $this->starRatingToNumber($item['starRating'] ?? null),
                'text' => $item['comment'] ?? '',
                'language' => 'en',
                'date' => $item['createTime'] ?? null,
                'reviewer' => [
                    'name' => $item['reviewer']['displayName'] ?? 'Guest',
                    // صورة صاحب التقييم (Google بيبعتها مع كل تقييم) - كانت بتضيع
                    'photo' => $item['reviewer']['profilePhotoUrl'] ?? null,
                    'anonymous' => !empty($item['reviewer']['isAnonymous']),
                ],
                'response' => $item['reviewReply']['comment'] ?? null,
                'response_time' => $item['reviewReply']['updateTime'] ?? null,
            ];
        }

        return $reviews;
    }

    /** Google بترجع التقييم كنص (ONE..FIVE) مش رقم */
    private function starRatingToNumber($starRating): int
    {
        $map = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];
        return $map[$starRating] ?? 0;
    }

    private function extractId(string $name): string
    {
        $parts = explode('/', $name);
        return end($parts);
    }
}

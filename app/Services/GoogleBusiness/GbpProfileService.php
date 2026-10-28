<?php

/**
 * Tourfecto - GBP Profile Management Service
 * عرض وتعديل بيانات بروفايل Google Business Profile الحقيقية + Score
 * اكتمال البروفايل المبني على البيانات الموجودة فعليًا - مفيش قيم وهمية.
 * @version 1.0.0
 * @since 2026-08-09 (GBP Module Upgrade)
 */
class GbpProfileService
{
    /** @var GbpSyncService */
    private $sync;
    /** @var GoogleReviewSyncService */
    private $reviewSync;

    public function __construct()
    {
        $this->sync = new GbpSyncService();
        $this->reviewSync = new GoogleReviewSyncService();
    }

    /**
     * Attributes (بند 4/5 بالسبيك) - Round 7 (2026-08-14): بدل ما نعرض
     * BOOL بس، بنستخدم دلوقتي attributes.list الرسمي (metadata حقيقية
     * لتصنيف نشاط الـ location بالذات) عشان نعرف الـ Attribute IDs
     * الصحيحة ونوعها (BOOL/REPEATED_ENUM/URL) وخياراتها الحقيقية - من
     * غير أي تخمين. لو Google مرجعتش صفة معينة لتصنيف النشاط ده، هي مش
     * هتظهر أصلاً هنا (بدل ما نعرض "Not available" لكل صفة ممكنة - عرض
     * بس اللي فعلاً متاح أدق وأوضح للمستخدم).
     */
    public function getAttributes(int $websiteId, int $userId): array
    {
        $connection = $this->sync->findConnection($websiteId, $userId);
        if (!$connection) {
            return ['success' => false, 'error' => 'اربط حساب Google Business الأول'];
        }

        try {
            $accessToken = $this->reviewSync->getValidAccessToken($connection);
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'تعذر جلب الـ Attributes - يحتاج إعادة ربط (Reconnect): ' . $e->getMessage()];
        }

        $api = new GoogleBusinessAPI(
            $accessToken,
            $connection->getAttribute('external_account_id'),
            $connection->getAttribute('external_location_id')
        );

        // 1) الـ Attributes المتاحة فعليًا لتصنيف نشاط الـ location ده (Metadata حقيقية من Google)
        $availableResult = $api->listAvailableAttributes();
        if (!$availableResult['success']) {
            return $availableResult;
        }

        // 2) القيم المضبوطة حاليًا (لو موجودة)
        $currentResult = $api->getAttributes();
        $currentById = [];
        if ($currentResult['success']) {
            foreach ($currentResult['attributes'] as $attr) {
                $currentById[$attr['attribute_id']] = $attr['current_value'];
            }
        }

        $labels = self::attributeLabels();
        $merged = array_map(function ($meta) use ($currentById, $labels) {
            $id = $meta['attribute_id'];
            return [
                'attribute_id' => $id,
                'label' => $labels[$id] ?? $meta['display_name'],
                'group' => $meta['group_display_name'],
                'value_type' => $meta['value_type'],
                'repeatable' => $meta['repeatable'],
                'options' => $meta['options'], // خيارات ENUM حقيقية من Google، فاضية للـ BOOL/URL
                'current_value' => $currentById[$id] ?? null,
            ];
        }, $availableResult['available_attributes']);

        return ['success' => true, 'attributes' => $merged];
    }

    /**
     * @param array $changes ['attribute_id' => ['type'=>'BOOL','value'=>bool]]
     *                        أو ['type'=>'REPEATED_ENUM','set'=>[...],'unset'=>[...]]
     *                        أو ['type'=>'URL','values'=>['https://...']]
     */
    public function updateAttributes(int $websiteId, int $userId, array $changes): array
    {
        if (empty($changes)) {
            return ['success' => false, 'error' => 'لا يوجد تغييرات'];
        }

        $connection = $this->sync->findConnection($websiteId, $userId);
        if (!$connection) {
            return ['success' => false, 'error' => 'اربط حساب Google Business الأول'];
        }

        try {
            $accessToken = $this->reviewSync->getValidAccessToken($connection);
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'تعذر تحديث الـ Attributes - يحتاج إعادة ربط (Reconnect): ' . $e->getMessage()];
        }

        $api = new GoogleBusinessAPI(
            $accessToken,
            $connection->getAttribute('external_account_id'),
            $connection->getAttribute('external_location_id')
        );

        // Round 7: نتحقق إن كل attribute_id المطلوب تعديله فعلاً موجود في
        // القايمة المتاحة لتصنيف النشاط ده قبل ما نبعت لجوجل - بدل ما
        // نسيب جوجل ترفض الطلب كامل بسبب صفة واحدة مش متاحة.
        $availableResult = $api->listAvailableAttributes();
        if ($availableResult['success']) {
            $availableIds = array_column($availableResult['available_attributes'], 'attribute_id');
            $notAvailable = array_diff(array_keys($changes), $availableIds);
            if (!empty($notAvailable)) {
                return ['success' => false, 'error' => 'Not available for this business category: ' . implode('، ', $notAvailable)];
            }
        }

        $result = $api->updateAttributes(null, $changes);
        if ($result['success']) {
            event('ProfileUpdated', ['website_id' => $websiteId, 'user_id' => $userId, 'type' => 'attributes', 'fields' => array_keys($changes)]);
            GbpAuditLogger::log('attributes_update', $websiteId, $userId, 'success', ['attribute_ids' => array_keys($changes)]);
        }

        return $result;
    }

    /** تسميات عربية معروفة لأشهر Attribute IDs الموثقة من Google - أي ID غير موجود هنا بيتعرض بمعرفه الخام */
    private static function attributeLabels(): array
    {
        return [
            'has_wifi' => 'يوجد واي فاي',
            'wheelchair_accessible_entrance' => 'مدخل لذوي الاحتياجات',
            'wheelchair_accessible_parking' => 'باركينج لذوي الاحتياجات',
            'wheelchair_accessible_restroom' => 'حمام لذوي الاحتياجات',
            'wheelchair_accessible_seating' => 'أماكن جلوس لذوي الاحتياجات',
            'delivery' => 'توصيل',
            'dine_in' => 'تناول الطعام بالمكان',
            'takeout' => 'تيك أواي',
            'outdoor_seating' => 'جلسة خارجية',
            'restroom' => 'يوجد حمام',
            'serves_beer' => 'يقدم بيرة',
            'serves_wine' => 'يقدم نبيذ',
            'accepts_credit_cards' => 'يقبل بطاقات ائتمان',
            'free_wifi' => 'واي فاي مجاني',
            'good_for_children' => 'مناسب للأطفال',
            'reservable' => 'يمكن الحجز',
        ];
    }

    /** يجيب أحدث بروفايل حقيقي من Google + Completeness Score محسوب من نفس البيانات */
    public function getProfile(int $websiteId, int $userId): array
    {
        $connection = $this->sync->findConnection($websiteId, $userId);
        if (!$connection) {
            return ['success' => false, 'error' => 'اربط حساب Google Business الأول'];
        }

        try {
            $accessToken = $this->reviewSync->getValidAccessToken($connection);
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'تعذر الوصول للبروفايل - يحتاج إعادة ربط (Reconnect): ' . $e->getMessage()];
        }

        $api = new GoogleBusinessAPI(
            $accessToken,
            $connection->getAttribute('external_account_id'),
            $connection->getAttribute('external_location_id')
        );

        $result = $api->getLocation();
        if (!$result['success']) {
            return $result;
        }

        // Location Dashboard (بند 2 بالسبيك): Google Rating/Review Count من
        // جدول reviews الموجود فعلاً (بدل ما نطلبهم من Google تاني -
        // القيم دي بتتزامن أصلاً عن طريق GoogleReviewSyncService).
        $ratingStats = $this->getRatingStats($websiteId, $userId);

        return [
            'success' => true,
            'profile' => array_merge($result['location'], $ratingStats),
            'completeness' => $this->computeCompleteness($result['location']),
        ];
    }

    /** متوسط التقييم وعدد المراجعات الفعلي من قاعدة بياناتنا (مُزامَنة من Google) */
    private function getRatingStats(int $websiteId, int $userId): array
    {
        try {
            $db = Database::getInstance();
            $rows = $db->query(
                "SELECT AVG(rating) AS avg_rating, COUNT(*) AS review_count
                 FROM reviews WHERE website_id = ? AND user_id = ? AND source_platform = 'google_business'",
                [$websiteId, $userId]
            );
            $row = $rows[0] ?? [];
            return [
                'google_rating' => $row['avg_rating'] !== null ? round((float) $row['avg_rating'], 1) : null,
                'review_count' => (int) ($row['review_count'] ?? 0),
            ];
        } catch (Throwable $e) {
            return ['google_rating' => null, 'review_count' => 0];
        }
    }

    /** تعديل حقول مسموح بتعديلها فعليًا عبر Business Information API فقط */
    public function updateProfile(int $websiteId, int $userId, array $fields): array
    {
        $connection = $this->sync->findConnection($websiteId, $userId);
        if (!$connection) {
            return ['success' => false, 'error' => 'اربط حساب Google Business الأول'];
        }

        $allowed = array_intersect_key($fields, array_flip(['description', 'phone', 'website', 'regular_hours']));
        if (empty($allowed)) {
            return ['success' => false, 'error' => 'لا يوجد حقل مدعوم للتعديل'];
        }

        // Validation بسيطة قبل الإرسال
        if (isset($allowed['description']) && mb_strlen((string) $allowed['description']) > 750) {
            return ['success' => false, 'error' => 'وصف النشاط يجب ألا يتجاوز 750 حرفًا (حد Google الرسمي)'];
        }
        if (isset($allowed['website']) && $allowed['website'] !== '' && !filter_var($allowed['website'], FILTER_VALIDATE_URL)) {
            return ['success' => false, 'error' => 'رابط الموقع غير صحيح'];
        }
        if (isset($allowed['phone']) && $allowed['phone'] !== '' && !preg_match('/^\+?[0-9\s\-()]{6,20}$/', $allowed['phone'])) {
            return ['success' => false, 'error' => 'رقم الهاتف غير صحيح'];
        }

        try {
            $accessToken = $this->reviewSync->getValidAccessToken($connection);
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'تعذر تحديث البروفايل - يحتاج إعادة ربط (Reconnect): ' . $e->getMessage()];
        }

        $api = new GoogleBusinessAPI(
            $accessToken,
            $connection->getAttribute('external_account_id'),
            $connection->getAttribute('external_location_id')
        );

        $result = $api->updateLocation(null, $allowed);
        if ($result['success']) {
            event('ProfileUpdated', ['website_id' => $websiteId, 'user_id' => $userId, 'fields' => array_keys($allowed)]);
            GbpAuditLogger::log('profile_update', $websiteId, $userId, 'success', ['fields' => array_keys($allowed)]);
        }

        return $result;
    }

    /**
     * Score اكتمال حقيقي مبني فقط على البيانات المرجعة فعليًا من Google -
     * أي حقل مش موجود في الرد بيتحسب "Unknown / Not Available" ومش
     * بيتحسب ضد النشاط (عشان بعض الحقول مش كل الحسابات بترجعها).
     */
    private function computeCompleteness(array $location): array
    {
        $checks = [
            'name' => !empty($location['name']),
            'description' => !empty($location['description']),
            'phone' => !empty($location['phone']),
            'website' => !empty($location['website']),
            'primary_category' => !empty($location['primary_category']),
            'regular_hours' => !empty($location['regular_hours']),
            'address' => !empty($location['address']),
        ];

        $known = array_filter($checks, fn ($v) => $v !== null);
        $complete = array_filter($known);
        $score = count($known) > 0 ? (int) round((count($complete) / count($known)) * 100) : 0;

        return [
            'score' => $score,
            'checks' => $checks,
            'missing' => array_keys(array_filter($checks, fn ($v) => $v === false)),
        ];
    }
}

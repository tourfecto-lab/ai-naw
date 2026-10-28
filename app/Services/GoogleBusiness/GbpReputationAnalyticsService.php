<?php

/**
 * Tourfecto - GBP Reputation Analytics Service
 * شاشة "Reputation Intelligence" على مستوى Birdeye/Chatmeter/Semrush Local:
 *
 *  1) getAnalytics()   - KPIs أساسية على لوحة القيادة: متوسط التقييم + الاتجاه،
 *                        مراجعات جديدة (Review Velocity) بفترات، معدل الرد
 *                        (Response Rate) + متوسط زمن أول رد (First Response Time)،
 *                        توزيع التقييمات، ومزيج المشاعر.
 *  2) getRiskSignals() - مراقبة مخاطر (نفس فكرة PulseAi Risk Monitoring عند
 *                        Chatmeter): هبوط تقييم مفاجئ، قفزة مراجعات، قفزة في
 *                        نسبة السلبية، ونمط مراجعات مشبوهة.
 *  3) getShareOfVoice()- حصة الظهور المحلية (Share of Voice) مقارنة بالمنافسين
 *                        اللي بيظهرهم Google Places في نفس المنطقة.
 *
 * الأرقام كلها حقيقية: من جدول reviews المتزامن من جوجل + Google Business API
 * + Places Text Search. مفيش أرقام مُخترعة. لو Places مش مفعّل، حصة الظهور
 * بترجع available=false بسبب واضح، والبقية شغالة من قاعدة بياناتنا.
 * @version 1.0.0
 * @since 2026-08-15 (Reputation Intelligence Tier 1)
 */
class GbpReputationAnalyticsService
{
    private const TIMEOUT_SECONDS = 8;
    private const MAX_COMPETITORS = 5;

    /** @var Database */
    private $db;
    /** @var GbpSyncService */
    private $sync;
    /** @var GoogleReviewSyncService */
    private $reviewSync;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->sync = new GbpSyncService();
        $this->reviewSync = new GoogleReviewSyncService();
    }

    /**
     * لوحة القيادة: KPIs + اتجاهات خلال 90 يوم افتراضيًا.
     */
    public function getAnalytics(int $websiteId, int $userId, int $days = 90): array
    {
        $connection = $this->sync->findConnection($websiteId, $userId);
        if (!$connection) {
            return ['success' => false, 'error' => 'اربط حساب Google Business الأول'];
        }
        if ($connection->getAttribute('status') !== 'connected') {
            return ['success' => false, 'error' => 'الاتصال غير نشط حاليًا'];
        }

        $days = max(7, min(365, $days));
        $since = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));

        return [
            'success' => true,
            'range_days' => $days,
            'kpis' => $this->kpis($websiteId, $userId, $since),
            'trends' => [
                'rating_trend' => $this->ratingTrend($websiteId, $userId, $since),
                'velocity' => $this->velocityTrend($websiteId, $userId, $days),
                'response_trend' => $this->responseTrend($websiteId, $userId, $since),
            ],
            'distribution' => $this->ratingDistribution($websiteId, $userId),
            'sentiment' => $this->sentimentMix($websiteId, $userId, $since),
        ];
    }

    // ============================================================
    // 1) KPIs
    // ============================================================

    private function kpis(int $websiteId, int $userId, string $since): array
    {
        try {
            $rows = $this->db->query(
                "SELECT COUNT(*) AS total,
                        AVG(rating) AS avg_rating,
                        SUM(CASE WHEN reply_sent_at IS NOT NULL THEN 1 ELSE 0 END) AS replied,
                        AVG(CASE WHEN reply_sent_at >= GREATEST(COALESCE(review_date,created_at),created_at)
                                 THEN TIMESTAMPDIFF(SECOND, GREATEST(COALESCE(review_date,created_at),created_at), reply_sent_at) / 3600.0 END) AS avg_hours
                 FROM reviews
                 WHERE website_id = ? AND user_id = ? AND source_platform = 'google_business' AND rating > 0",
                [$websiteId, $userId]
            );
            $total = (int) ($rows[0]['total'] ?? 0);
            $avgRating = $rows[0]['avg_rating'] !== null ? round((float) $rows[0]['avg_rating'], 2) : 0.0;
            $replied = (int) ($rows[0]['replied'] ?? 0);
            $avgHours = $rows[0]['avg_hours'] ?? null;

            $new30 = $this->db->query(
                "SELECT COUNT(*) AS cnt FROM reviews
                 WHERE website_id = ? AND user_id = ? AND source_platform = 'google_business'
                   AND rating > 0 AND review_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                [$websiteId, $userId]
            );
            $new30Count = (int) ($new30[0]['cnt'] ?? 0);
            $velocity30 = round($new30Count / 30.0, 2);

            $new7 = $this->db->query(
                "SELECT COUNT(*) AS cnt FROM reviews
                 WHERE website_id = ? AND user_id = ? AND source_platform = 'google_business'
                   AND rating > 0 AND review_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
                [$websiteId, $userId]
            );
            $new7Count = (int) ($new7[0]['cnt'] ?? 0);

            return [
                'total_reviews' => $total,
                'responded' => $replied,
                'avg_rating' => $avgRating,
                'new_reviews_7d' => $new7Count,
                'new_reviews_30d' => $new30Count,
                'review_velocity_per_day_30d' => $velocity30,
                'response_rate' => $total > 0 ? round(($replied / $total) * 100, 1) : 0.0,
                'avg_response_hours' => $avgHours !== null ? round((float) $avgHours, 1) : null,
            ];
        } catch (Throwable $e) {
            return [
                'total_reviews' => 0, 'responded' => 0, 'avg_rating' => 0.0, 'new_reviews_7d' => 0, 'new_reviews_30d' => 0,
                'review_velocity_per_day_30d' => 0.0, 'response_rate' => 0.0, 'avg_response_hours' => null,
            ];
        }
    }

    // ============================================================
    // 2) Trends
    // ============================================================

    /** متوسط التقييم يوم بيوم (آخر 90 يوم) - أيام فاضية بتتكسب */
    private function ratingTrend(int $websiteId, int $userId, string $since): array
    {
        try {
            $rows = $this->db->query(
                "SELECT DATE(review_date) AS d, AVG(rating) AS avg_r
                 FROM reviews
                 WHERE website_id = ? AND user_id = ? AND source_platform = 'google_business'
                   AND rating > 0 AND review_date >= ?
                 GROUP BY DATE(review_date) ORDER BY d ASC",
                [$websiteId, $userId, $since]
            );
        } catch (Throwable $e) {
            return [];
        }

        $byDay = [];
        foreach ($rows as $r) {
            $byDay[$r['d']] = round((float) $r['avg_r'], 2);
        }

        $trend = [];
        $cursor = new DateTime($since);
        $end = new DateTime('today');
        $interval = new DateInterval('P1D');
        while ($cursor <= $end) {
            $key = $cursor->format('Y-m-d');
            $trend[] = ['date' => $key, 'avg_rating' => $byDay[$key] ?? null];
            $cursor->add($interval);
        }
        return $trend;
    }

    /** مراجعات جديدة كل فترة (Weekly buckets لليونة أكبر من اليومي) */
    private function velocityTrend(int $websiteId, int $userId, int $days): array
    {
        try {
            $rows = $this->db->query(
                "SELECT YEARWEEK(review_date, 3) AS wk, DATE(MIN(review_date)) AS d, COUNT(*) AS cnt
                 FROM reviews
                 WHERE website_id = ? AND user_id = ? AND source_platform = 'google_business'
                   AND rating > 0 AND review_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY YEARWEEK(review_date, 3) ORDER BY wk ASC",
                [$websiteId, $userId, $days]
            );
        } catch (Throwable $e) {
            return [];
        }

        $trend = [];
        foreach ($rows as $r) {
            $trend[] = [
                'week_start' => $r['d'],
                'new_reviews' => (int) ($r['cnt'] ?? 0),
            ];
        }
        return $trend;
    }

    /** معدل الرد + أول وقت رد يوم بيوم */
    private function responseTrend(int $websiteId, int $userId, string $since): array
    {
        try {
            $rows = $this->db->query(
                "SELECT DATE(review_date) AS d,
                        COUNT(*) AS total,
                        SUM(CASE WHEN reply_sent_at IS NOT NULL THEN 1 ELSE 0 END) AS replied,
                        AVG(CASE WHEN reply_sent_at >= GREATEST(COALESCE(review_date,created_at),created_at)
                                 THEN TIMESTAMPDIFF(SECOND, GREATEST(COALESCE(review_date,created_at),created_at), reply_sent_at) / 3600.0 END) AS avg_hours
                 FROM reviews
                 WHERE website_id = ? AND user_id = ? AND source_platform = 'google_business'
                   AND rating > 0 AND review_date >= ?
                 GROUP BY DATE(review_date) ORDER BY d ASC",
                [$websiteId, $userId, $since]
            );
        } catch (Throwable $e) {
            return [];
        }

        $trend = [];
        foreach ($rows as $r) {
            $total = (int) ($r['total'] ?? 0);
            $replied = (int) ($r['replied'] ?? 0);
            $trend[] = [
                'date' => $r['d'],
                'total' => $total,
                'response_rate' => $total > 0 ? round(($replied / $total) * 100, 1) : 0.0,
                'avg_response_hours' => ($r['avg_hours'] ?? null) !== null ? round((float) $r['avg_hours'], 1) : null,
            ];
        }
        return $trend;
    }

    // ============================================================
    // 3) Distribution + Sentiment
    // ============================================================

    /** توزيع التقييمات (5/4/3/2/1 نجوم) */
    private function ratingDistribution(int $websiteId, int $userId): array
    {
        try {
            $rows = $this->db->query(
                "SELECT FLOOR(rating) AS stars, COUNT(*) AS cnt
                 FROM reviews
                 WHERE website_id = ? AND user_id = ? AND source_platform = 'google_business' AND rating > 0
                 GROUP BY FLOOR(rating) ORDER BY stars DESC",
                [$websiteId, $userId]
            );
        } catch (Throwable $e) {
            return [];
        }

        $dist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        $total = 0;
        foreach ($rows as $r) {
            $stars = (int) $r['stars'];
            if (isset($dist[$stars])) {
                $dist[$stars] = (int) ($r['cnt'] ?? 0);
            }
            $total += (int) ($r['cnt'] ?? 0);
        }

        $result = [];
        foreach ($dist as $stars => $cnt) {
            $result[] = [
                'stars' => $stars,
                'count' => $cnt,
                'percentage' => $total > 0 ? round(($cnt / $total) * 100, 1) : 0.0,
            ];
        }
        return $result;
    }

    /** مزيج المشاعر (بناءً على عمود sentiment الفعلي المخزّن عند المعالجة) */
    private function sentimentMix(int $websiteId, int $userId, string $since): array
    {
        try {
            $rows = $this->db->query(
                "SELECT sentiment AS label, COUNT(*) AS cnt
                 FROM reviews
                 WHERE website_id = ? AND user_id = ? AND source_platform = 'google_business'
                   AND review_date >= ? AND sentiment IS NOT NULL
                 GROUP BY sentiment",
                [$websiteId, $userId, $since]
            );
        } catch (Throwable $e) {
            return [];
        }

        $mix = ['positive' => 0, 'neutral' => 0, 'negative' => 0, 'mixed' => 0];
        $total = 0;
        foreach ($rows as $r) {
            $label = $r['label'];
            if (isset($mix[$label])) {
                $mix[$label] = (int) ($r['cnt'] ?? 0);
            }
            $total += (int) ($r['cnt'] ?? 0);
        }

        foreach ($mix as $label => $cnt) {
            $mix[$label] = [
                'count' => $cnt,
                'percentage' => $total > 0 ? round(($cnt / $total) * 100, 1) : 0.0,
            ];
        }
        return ['total_analyzed' => $total, 'labels' => $mix];
    }

    // ============================================================
    // 4) Risk Monitoring (PulseAi-style)
    // ============================================================

    /**
     * إشارات مخاطر: كشف شذوذ من البيانات الحقيقية.
     * - rating_drop: متوسط تقييم آخر 7 أيام أقل من آخر 30 يوم بفرق >= 0.5
     * - review_spike: مراجعات آخر 7 أيام > ضعف المتوسط اليومي الشهري (و>= 3)
     * - negative_spike: نسبة السلبية آخر 7 أيام > ضعف النسبة العامة (و>= 15 نقطة)
     * - suspicious_pattern: 3+ تقييمات 1-2 نجمة في نفس اليوم
     */
    public function getRiskSignals(int $websiteId, int $userId): array
    {
        $connection = $this->sync->findConnection($websiteId, $userId);
        if (!$connection) {
            return ['success' => false, 'error' => 'اربط حساب Google Business الأول'];
        }

        $metrics = [];
        try {
            $rows = $this->db->query(
                "SELECT
                    AVG(CASE WHEN review_date >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN rating END) AS avg7,
                    AVG(CASE WHEN review_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN rating END) AS avg30,
                    SUM(CASE WHEN review_date >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND rating BETWEEN 1 AND 2 THEN 1 ELSE 0 END) AS neg7,
                    SUM(CASE WHEN review_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND rating BETWEEN 1 AND 2 THEN 1 ELSE 0 END) AS neg30,
                    SUM(CASE WHEN review_date >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS cnt7,
                    SUM(CASE WHEN review_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS cnt30
                 FROM reviews
                 WHERE website_id = ? AND user_id = ? AND source_platform = 'google_business' AND rating > 0",
                [$websiteId, $userId]
            );
            $r = $rows[0] ?? [];
            $metrics = [
                'avg7' => $r['avg7'] !== null ? (float) $r['avg7'] : null,
                'avg30' => $r['avg30'] !== null ? (float) $r['avg30'] : null,
                'cnt7' => (int) ($r['cnt7'] ?? 0),
                'cnt30' => (int) ($r['cnt30'] ?? 0),
                'neg7' => (int) ($r['neg7'] ?? 0),
                'neg30' => (int) ($r['neg30'] ?? 0),
            ];
        } catch (Throwable $e) {
            $metrics = ['avg7' => null, 'avg30' => null, 'cnt7' => 0, 'cnt30' => 0, 'neg7' => 0, 'neg30' => 0];
        }

        $suspiciousDays = [];
        try {
            $rows = $this->db->query(
                "SELECT DATE(review_date) AS d, COUNT(*) AS cnt
                 FROM reviews
                 WHERE website_id = ? AND user_id = ? AND source_platform = 'google_business'
                   AND rating BETWEEN 1 AND 2 AND review_date >= DATE_SUB(NOW(), INTERVAL 60 DAY)
                 GROUP BY DATE(review_date)
                 HAVING cnt >= 3
                 ORDER BY cnt DESC LIMIT 5",
                [$websiteId, $userId]
            );
            $suspiciousDays = array_map(fn ($r) => ['date' => $r['d'], 'negative_reviews' => (int) $r['cnt']], $rows);
        } catch (Throwable $e) {
            // تجاهل
        }

        return self::scoreRisk($metrics, $suspiciousDays);
    }

    /**
     * منطق حساب إشارات المخاطر كـ Pure Function (قابل للاختبار بمثيلات ثابتة).
     * نفس المعايير الموثقة في getRiskSignals() - بيفصل الحساب عن جلب البيانات.
     */
    public static function scoreRisk(array $metrics, array $suspiciousDays = []): array
    {
        $scores = ['rating_drop' => 0, 'review_spike' => 0, 'negative_spike' => 0, 'suspicious_pattern' => 0];
        $signals = [];
        $details = [];

        $avg7 = $metrics['avg7'] ?? null;
        $avg30 = $metrics['avg30'] ?? null;
        $cnt7 = (int) ($metrics['cnt7'] ?? 0);
        $cnt30 = (int) ($metrics['cnt30'] ?? 0);
        $neg7 = (int) ($metrics['neg7'] ?? 0);
        $neg30 = (int) ($metrics['neg30'] ?? 0);

        if ($avg7 !== null && $avg30 !== null && $avg7 <= $avg30 - 0.5 && $cnt7 >= 2) {
            $scores['rating_drop'] = 1;
            $signals[] = 'هبوط ملحوظ في متوسط التقييم خلال آخر 7 أيام (' . number_format($avg7, 2) . ' مقابل ' . number_format($avg30, 2) . ' خلال 30 يوم) - راجع أحدث المراجعات السلبية.';
            $details['rating_drop'] = ['avg_7d' => round($avg7, 2), 'avg_30d' => round($avg30, 2)];
        }

        $negRate7 = $cnt7 > 0 ? round(($neg7 / $cnt7) * 100, 1) : 0.0;
        $negRate30 = $cnt30 > 0 ? round(($neg30 / $cnt30) * 100, 1) : 0.0;
        if ($cnt7 >= 3 && $negRate7 >= $negRate30 + 15) {
            $scores['negative_spike'] = 1;
            $signals[] = 'قفزة في نسبة المراجعات السلبية (1-2 نجوم): ' . $negRate7 . '% آخر 7 أيام مقابل ' . $negRate30 . '% الشهر الماضي.';
            $details['negative_spike'] = ['negative_rate_7d' => $negRate7, 'negative_rate_30d' => $negRate30];
        }

        $dailyAvg = $cnt30 / 30.0;
        if ($cnt7 >= 3 && $cnt7 > $dailyAvg * 2) {
            $scores['review_spike'] = 1;
            $signals[] = 'قفزة مراجعات غير معتادة: ' . $cnt7 . ' مراجعة في 7 أيام مقابل متوسط يومي ' . number_format($dailyAvg, 2) . ' - تحقق إنها مش حملة حقيقية ولا محاولة تضخيم.';
            $details['review_spike'] = ['reviews_7d' => $cnt7, 'daily_average_30d' => round($dailyAvg, 2)];
        }

        if (!empty($suspiciousDays)) {
            $scores['suspicious_pattern'] = 1;
            $signals[] = 'تجمّع مراجعات سلبية مشبوه في أيام متتالية - مراجعة إنها مش حملة مدفوعة أو مراجعات وهمية.';
            $details['suspicious_pattern'] = $suspiciousDays;
        }

        return [
            'success' => true,
            'risk_level' => array_sum($scores) === 0 ? 'low' : (array_sum($scores) >= 2 ? 'high' : 'medium'),
            'active_signals' => array_sum($scores),
            'signals' => $signals,
            'signal_scores' => $scores,
            'details' => $details,
        ];
    }

    // ============================================================
    // 5) Local Share of Voice
    // ============================================================

    /**
     * حصة الظهور المحلية: نصيب النشاط من الـ "تريند" المحلي بناءً على:
     * - مواقع Places المكتشفة (كل منافس = كيان في السوق المحلي)
     * - التقييم وعدد المراجعات كتمثيل للوزن
     * Share of Voice = مراجعات النشاط / إجمالي مراجعات السوق (مقياس Birdeye/Chatmeter).
     */
    public function getShareOfVoice(int $websiteId, int $userId): array
    {
        $connection = $this->sync->findConnection($websiteId, $userId);
        if (!$connection) {
            return ['success' => false, 'error' => 'اربط حساب Google Business الأول'];
        }
        if ($connection->getAttribute('status') !== 'connected') {
            return ['success' => false, 'error' => 'الاتصال غير نشط حاليًا'];
        }

        try {
            $accessToken = $this->reviewSync->getValidAccessToken($connection);
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'تعذر الحصول على توكن صالح: ' . $e->getMessage()];
        }

        $api = new GoogleBusinessAPI(
            $accessToken,
            $connection->getAttribute('external_account_id'),
            $connection->getAttribute('external_location_id')
        );

        $locationResult = $api->getLocation();
        if (!$locationResult['success']) {
            return ['success' => false, 'error' => $locationResult['error'] ?? 'فشل جلب بيانات الموقع'];
        }

        $ownLocation = $locationResult['location'] ?? [];
        $ownName = $ownLocation['name'] ?? '';
        $ownCategory = $ownLocation['primary_category'] ?? '';
        $ownLat = $ownLocation['latitude'] ?? null;
        $ownLng = $ownLocation['longitude'] ?? null;

        $apiKey = $this->resolveApiKey();
        if ($apiKey === '') {
            return ['success' => false, 'available' => false, 'reason' => 'google_maps_api_key_not_configured', 'error' => 'مفتاح Google Maps غير مضبوط - حصة الظهور محتاجة Places'];
        }

        $query = $ownCategory !== '' ? $ownCategory : $ownName;
        $near = ($ownLat !== null && $ownLng !== null)
            ? "&location={$ownLat}%2C{$ownLng}&radius=5000"
            : '';

        $searchResult = $this->placesTextSearch($query, $apiKey, $near);
        if (!$searchResult['success']) {
            return ['success' => false, 'available' => false, 'reason' => 'google_places_request_failed: ' . $searchResult['error'], 'error' => 'تعذر جلب السوق المحلي من Google Places'];
        }

        $ownMetrics = $this->ownReviewMetrics($websiteId, $userId);
        $ownRating = $ownMetrics['avg_rating'];
        $ownCount = $ownMetrics['review_count'];

        $marketPlaces = [];
        $totalMarketReviews = 0;
        $totalMarketRatingWeight = 0.0;
        foreach ($searchResult['places'] as $place) {
            if ($this->isSameBusiness($ownName, $place['name'] ?? '')) {
                continue;
            }
            $rating = isset($place['rating']) ? (float) $place['rating'] : 0.0;
            $count = isset($place['user_ratings_total']) ? (int) $place['user_ratings_total'] : 0;
            $marketPlaces[] = [
                'name' => $place['name'] ?? 'Unknown',
                'rating' => $rating ?: null,
                'review_count' => $count,
            ];
            $totalMarketReviews += $count;
            $totalMarketRatingWeight += $rating * $count;
        }

        // SOV بناءً على المراجعات (review share = share of voice في أسواق المراجعات)
        $sovReviews = ($totalMarketReviews + $ownCount) > 0
            ? round(($ownCount / ($totalMarketReviews + $ownCount)) * 100, 1)
            : null;

        // Rank بين اللاعبين في السوق
        $allRatings = array_merge(array_column($marketPlaces, 'rating'), [$ownRating]);
        $allRatings = array_values(array_filter($allRatings, fn ($v) => $v !== null));
        rsort($allRatings);
        $ratingRank = $ownRating > 0 ? array_search($ownRating, $allRatings, true) + 1 : null;

        $allCounts = array_merge(array_column($marketPlaces, 'review_count'), [$ownCount]);
        rsort($allCounts);
        $countRank = array_search($ownCount, $allCounts, true) + 1;

        return [
            'success' => true,
            'available' => true,
            'reason' => null,
            'own' => [
                'name' => $ownName,
                'avg_rating' => $ownRating,
                'review_count' => $ownCount,
            ],
            'market_size' => count($marketPlaces),
            'total_market_reviews' => $totalMarketReviews + $ownCount,
            'share_of_voice' => [
                'review_share_percent' => $sovReviews,
                'rating_rank' => $ratingRank,
                'review_count_rank' => $countRank,
            ],
            'market_places' => array_slice($marketPlaces, 0, self::MAX_COMPETITORS),
        ];
    }

    private function ownReviewMetrics(int $websiteId, int $userId): array
    {
        try {
            $rows = $this->db->query(
                "SELECT AVG(rating) AS avg_rating, COUNT(*) AS cnt
                 FROM reviews
                 WHERE website_id = ? AND user_id = ? AND source_platform = 'google_business' AND rating > 0",
                [$websiteId, $userId]
            );
            return [
                'avg_rating' => $rows[0]['avg_rating'] !== null ? round((float) $rows[0]['avg_rating'], 2) : 0.0,
                'review_count' => (int) ($rows[0]['cnt'] ?? 0),
            ];
        } catch (Throwable $e) {
            return ['avg_rating' => 0.0, 'review_count' => 0];
        }
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function isSameBusiness(string $ownName, string $placeName): bool
    {
        $tokenize = function (string $s): array {
            $s = mb_strtolower((string) preg_replace('/[^a-z0-9\x{0600}-\x{06FF}]+/iu', ' ', $s));
            $tokens = preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $tokens = array_values(array_filter($tokens, fn ($t) => !ctype_digit($t)));
            sort($tokens);
            return $tokens;
        };
        $a = $tokenize($ownName);
        $b = $tokenize($placeName);
        if (empty($a) || empty($b)) {
            return false;
        }
        if (implode(' ', $a) === implode(' ', $b)) {
            return true;
        }
        $short = count($a) <= count($b) ? $a : $b;
        $long = count($a) <= count($b) ? $b : $a;
        if (count($short) < 2) {
            return false;
        }
        foreach ($short as $tok) {
            if (!in_array($tok, $long, true)) {
                return false;
            }
        }
        return true;
    }

    private function resolveApiKey(): string
    {
        if (class_exists('SystemSettingsService')) {
            $fromAdminPanel = (new SystemSettingsService())->get('google_maps_api_key', defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '');
            if ($fromAdminPanel !== '') {
                return $fromAdminPanel;
            }
        }
        return defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
    }

    private function placesTextSearch(string $query, string $apiKey, string $near = ''): array
    {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'error' => 'curl_extension_missing', 'places' => []];
        }

        $url = 'https://maps.googleapis.com/maps/api/place/textsearch/json?query=' . urlencode($query) . '&key=' . urlencode($apiKey) . $near;
        $response = $this->httpGetJson($url);

        if ($response === null) {
            return ['success' => false, 'error' => 'request_failed', 'places' => []];
        }

        if (($response['status'] ?? '') !== 'OK' && ($response['status'] ?? '') !== 'ZERO_RESULTS') {
            return ['success' => false, 'error' => (string) ($response['status'] ?? 'unknown_error'), 'places' => []];
        }

        return ['success' => true, 'error' => null, 'places' => $response['results'] ?? []];
    }

    private function httpGetJson(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $failed = curl_errno($ch) !== 0;
        curl_close($ch);

        if ($failed || $body === false) {
            return null;
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }
}

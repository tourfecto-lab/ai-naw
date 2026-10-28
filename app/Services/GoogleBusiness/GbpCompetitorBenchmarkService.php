<?php

/**
 * Tourfecto - GBP Competitor Benchmark Service
 * مقارنة النشاط التجاري مع المنافسين القريبين فعليًا على Google Maps
 * (نفس فكرة Competitive Intelligence في Chatmeter / Birdeye / Semrush Local).
 * البيانات الحقيقية من جوجل بس: Places Text Search + مكان النشاط من
 * GoogleBusinessAPI::getLocation() + أرقام المراجعات/الردود من قاعدة
 * بياناتنا. مفيش أرقام مُخترعة أبدًا - لو Places غير مفعّلة أو مفيش
 * مفتاح، بيرجع available=false بسبب واضح.
 *
 * مفتاح Places بيتجاب بنفس أسلوب GooglePlacesDiscoverySource:
 * SystemSettingsService أولاً، واحتياطيًا GOOGLE_MAPS_API_KEY من .env.
 * @version 1.0.0
 * @since 2026-08-15 (Competitive Benchmark - قائم على التحليل التنافسي)
 */
class GbpCompetitorBenchmarkService
{
    private const MAX_COMPETITORS = 5;
    private const TIMEOUT_SECONDS = 8;

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
     * @return array{
     *   success:bool, available?:bool, reason?:string|null,
     *   own?:array, competitors?:array, scorecard?:array, response_kpis?:array
     * }
     */
    public function benchmark(int $websiteId, int $userId): array
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
            return ['success' => false, 'available' => false, 'reason' => 'google_maps_api_key_not_configured', 'error' => 'مفتاح Google Maps غير مضبوط - تفعيل المقارنة التنافسية محتاج مفتاح Places'];
        }

        $query = $ownCategory !== '' ? $ownCategory : $ownName;
        $near = ($ownLat !== null && $ownLng !== null)
            ? "&location={$ownLat}%2C{$ownLng}&radius=5000"
            : '';

        $searchResult = $this->placesTextSearch($query, $apiKey, $near);
        if (!$searchResult['success']) {
            return ['success' => false, 'available' => false, 'reason' => 'google_places_request_failed: ' . $searchResult['error'], 'error' => 'تعذر البحث عن المنافسين على Google Places'];
        }

        $ownMetrics = $this->computeOwnMetrics($websiteId, $userId);

        $competitors = [];
        foreach (array_slice($searchResult['places'], 0, self::MAX_COMPETITORS) as $place) {
            $competitors[] = [
                'name' => $place['name'] ?? 'Unknown',
                'address' => $place['formatted_address'] ?? null,
                'rating' => isset($place['rating']) ? (float) $place['rating'] : null,
                'review_count' => isset($place['user_ratings_total']) ? (int) $place['user_ratings_total'] : 0,
                'is_self' => $this->isSameBusiness($ownName, $place['name'] ?? ''),
            ];
        }

        $competitors = array_filter($competitors, fn ($c) => !$c['is_self']);
        $competitors = array_values($competitors);

        return [
            'success' => true,
            'available' => true,
            'reason' => null,
            'own' => [
                'name' => $ownName,
                'category' => $ownCategory,
                'avg_rating' => $ownMetrics['avg_rating'],
                'review_count' => $ownMetrics['review_count'],
                'review_count_30d' => $ownMetrics['review_count_30d'],
            ],
            'competitors' => $competitors,
            'scorecard' => $this->buildScorecard($ownMetrics, $competitors),
            'response_kpis' => $ownMetrics['response_kpis'],
        ];
    }

    /** متريكات النشاط نفسها من قاعدة بياناتنا (أرقام حقيقية متزامنة من جوجل) */
    private function computeOwnMetrics(int $websiteId, int $userId): array
    {
        $avg = 0.0;
        $count = 0;
        $count30d = 0;

        try {
            $rows = $this->db->query(
                "SELECT AVG(rating) AS avg_rating, COUNT(*) AS cnt,
                        SUM(CASE WHEN review_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS cnt_30d
                 FROM reviews
                 WHERE website_id = ? AND user_id = ? AND source_platform = 'google_business' AND rating > 0",
                [$websiteId, $userId]
            );
            $avg = $rows[0]['avg_rating'] !== null ? round((float) $rows[0]['avg_rating'], 2) : 0.0;
            $count = (int) ($rows[0]['cnt'] ?? 0);
            $count30d = (int) ($rows[0]['cnt_30d'] ?? 0);
        } catch (Throwable $e) {
            // جدول reviews مش موجود؟ نرجّع أصفار بدل ما نوقع الطلب كله
        }

        return [
            'avg_rating' => $avg,
            'review_count' => $count,
            'review_count_30d' => $count30d,
            'response_kpis' => $this->computeResponseKpis($websiteId, $userId),
        ];
    }

    /**
     * معدل الرد % + متوسط زمن الرد بالساعات - نفس مؤشرات Chatmeter/Birdeye
     * اللي بيسموها Response Rate و First Response Time.
     */
    private function computeResponseKpis(int $websiteId, int $userId): array
    {
        try {
            $rows = $this->db->query(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN reply_sent_at IS NOT NULL THEN 1 ELSE 0 END) AS replied,
                        AVG(CASE WHEN reply_sent_at IS NOT NULL AND review_date IS NOT NULL
                                 THEN TIMESTAMPDIFF(HOUR, review_date, reply_sent_at) END) AS avg_hours
                 FROM reviews
                 WHERE website_id = ? AND user_id = ? AND source_platform = 'google_business'",
                [$websiteId, $userId]
            );

            $total = (int) ($rows[0]['total'] ?? 0);
            $replied = (int) ($rows[0]['replied'] ?? 0);
            $avgHours = $rows[0]['avg_hours'] ?? null;

            return [
                'total_reviews' => $total,
                'responded' => $replied,
                'response_rate' => $total > 0 ? round(($replied / $total) * 100, 1) : 0.0,
                'avg_response_hours' => $avgHours !== null ? round((float) $avgHours, 1) : null,
            ];
        } catch (Throwable $e) {
            return ['total_reviews' => 0, 'responded' => 0, 'response_rate' => 0.0, 'avg_response_hours' => null];
        }
    }

    /**
     * Scorecard تنافسي بسيط: ترتيب النشاط حسب التقييم وحسب عدد المراجعات
     * وسط المنافسين المكتشفين + الفجوة مع أقوى منافس.
     */
    private function buildScorecard(array $own, array $competitors): array
    {
        if (empty($competitors)) {
            return [
                'rating_rank' => 1,
                'review_count_rank' => 1,
                'rating_gap_vs_leader' => 0.0,
                'rating_gap_vs_average' => 0.0,
            ];
        }

        $rated = array_values(array_filter($competitors, fn ($c) => $c['rating'] !== null));
        $own['name'] = (string) ($own['name'] ?? '');
        $byRating = array_merge($rated, [['rating' => $own['avg_rating'], 'name' => $own['name']]]);
        usort($byRating, fn ($a, $b) => $b['rating'] <=> $a['rating']);
        $ratingRank = 0;
        foreach ($byRating as $i => $c) {
            if (($c['name'] ?? '') === $own['name']) {
                $ratingRank = $i + 1;
                break;
            }
        }

        $byCount = array_merge($competitors, [['review_count' => $own['review_count'], 'name' => $own['name']]]);
        usort($byCount, fn ($a, $b) => ($b['review_count'] ?? 0) <=> ($a['review_count'] ?? 0));
        $countRank = 0;
        foreach ($byCount as $i => $c) {
            if (($c['name'] ?? '') === $own['name']) {
                $countRank = $i + 1;
                break;
            }
        }

        $competitorAvgRating = $rated ? array_sum(array_column($rated, 'rating')) / count($rated) : 0.0;
        $leaderRating = $byRating[0]['rating'] ?? 0.0;

        return [
            'rating_rank' => $ratingRank ?: count($byRating),
            'review_count_rank' => $countRank ?: count($byCount),
            'rating_gap_vs_leader' => round($leaderRating - $own['avg_rating'], 2),
            'rating_gap_vs_average' => round($own['avg_rating'] - $competitorAvgRating, 2),
        ];
    }

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

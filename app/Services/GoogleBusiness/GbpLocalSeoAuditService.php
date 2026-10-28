<?php

/**
 * Tourfecto - GBP Local SEO Audit Service
 * تدقيق حضور النشاط في البحث المحلي (نفس فكرة Local SEO Audit في Semrush
 * Local / Birdeye / Chatmeter). كل البيانات حقيقية: Profile من
 * GoogleBusinessAPI::getLocation() + مرئية على Google Places (نفس أسلوب
 * GbpCompetitorBenchmarkService) + مؤشرات الرد/التقييم من قاعدة بياناتنا
 * المتزامنة من جوجل. مفيش أرقام مخترعة أبدًا - لو Places مش مفعّلة
 * بيرجع available=false بسبب واضح والباقي (Profile + مؤشراتنا) لسه
 * بيشتغل.
 *
 * النتيجة score حتمي (deterministic) 0-100: نفس المدخلات = نفس النتيجة،
 * مقسّمة على 4 محاور (Profile / NAP / Reputation / Visibility) مع
 * توصيات مرتبة حسب الأولوية.
 * @version 1.0.0
 * @since 2026-08-15 (Reputation Intelligence Tier 3)
 */
class GbpLocalSeoAuditService
{
    private const TIMEOUT_SECONDS = 8;
    private const MIN_PHOTOS = 3;
    private const RESPONSE_RATE_TARGET = 80;

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
     *   success:bool, available?:bool, reason?:string|null, error?:string,
     *   score?:int, sections?:array, recommendations?:array,
     *   profile_completeness?:array, visibility?:array|null, reputation?:array|null
     * }
     */
    public function audit(int $websiteId, int $userId): array
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

        $location = $locationResult['location'] ?? [];

        $apiKey = $this->resolveApiKey();
        $visibility = null;
        if ($apiKey !== '') {
            $visibility = $this->fetchVisibility($location, $apiKey);
        }

        $reputation = $this->fetchReputation($websiteId, $userId);
        $profile = (new GbpProfileScoreService())->calculateCompletenessScore($location);

        $sections = [
            'profile' => $this->scoreProfile($profile),
            'nap' => $this->scoreNap($location, $visibility),
            'reputation' => self::scoreReputation($reputation),
            'visibility' => $visibility !== null ? self::scoreVisibility($visibility) : null,
        ];

        $total = 0;
        $weights = 0;
        foreach ($sections as $section) {
            if ($section !== null) {
                $total += $section['score'] * $section['weight'];
                $weights += $section['weight'];
            }
        }
        $score = $weights > 0 ? (int) round($total / $weights) : 0;

        return [
            'success' => true,
            'available' => $visibility !== null,
            'reason' => $visibility === null ? 'google_maps_api_key_not_configured' : null,
            'score' => $score,
            'sections' => $sections,
            'recommendations' => $this->buildRecommendations($profile, $visibility, $reputation),
            'citation_consistency' => ['review_url'=>'/local-seo','note'=>'مقارنة اسم وهاتف وعنوان وموقع الشركة مع الموقع وأدلة العميل متاحة في فحص البحث المحلي؛ البيانات غير الظاهرة ليست تعارضًا مؤكدًا.'],
            'profile_completeness' => $profile,
            'visibility' => $visibility,
            'reputation' => $reputation,
        ];
    }

    // ============================================================
    // 1) Profile (وزن 40) - نعيد استخدام GbpProfileScoreService الحتمي
    // ============================================================
    private function scoreProfile(array $profile): array
    {
        return [
                'weight' => 40,
                'score' => (int) round(($profile['score'] / max(1, $profile['max_score'])) * 100),
                'label' => 'اكتمال الملف التجاري',
                'checks' => [
                    'missing' => $profile['missing'],
                    'complete' => $profile['complete'],
                ],
            ];
    }

    // ============================================================
    // 2) NAP consistency (وزن 15) - تطابق الاسم/العنوان/الهاتف/الموقع
    //    بين Profile الرسمي واللي Places بتعرضه فعليًا للناس
    // ============================================================
    private function scoreNap(array $location, ?array $visibility): array
    {
        $checks = [
            'website_match' => ['label' => 'الموقع الإلكتروني', 'ok' => $this->sameish($location['website'] ?? '', $visibility['website'] ?? '')],
            'phone_match' => ['label' => 'رقم الهاتف', 'ok' => $this->sameish($this->digits($location['phone'] ?? ''), $this->digits($visibility['phone'] ?? ''))],
            'address_present' => ['label' => 'العنوان', 'ok' => !empty($location['address'])],
            'has_coordinates' => ['label' => 'موقع دقيق على الخريطة', 'ok' => !empty($location['latitude']) && !empty($location['longitude'])],
        ];

        if ($visibility === null) {
            $checks['website_match']['ok'] = !empty($location['website']);
            $checks['phone_match']['ok'] = !empty($location['phone']);
        }

        $okCount = 0;
        $totalChecks = count($checks);
        $failed = [];
        foreach ($checks as $key => $check) {
            if ($check['ok']) {
                $okCount++;
            } else {
                $failed[] = $check['label'];
            }
        }

        return [
            'weight' => 15,
            'score' => $totalChecks > 0 ? (int) round(($okCount / $totalChecks) * 100) : 0,
            'label' => 'اتساق NAP',
            'checks' => [
                'passed' => array_values(array_map(fn ($c) => $c['label'], array_filter($checks, fn ($c) => $c['ok']))),
                'failed' => $failed,
            ],
        ];
    }

    // ============================================================
    // 3) Reputation (وزن 25) - مؤشرات حقيقية من قاعدة بياناتنا
    // ============================================================
    /**
     * Score محور السمعة (وزن 25). Pure Function - نفس المدخلات = نفس النتيجة.
     * @param array $reputation مخرجات fetchReputation()
     * @return array{weight:int, score:int, label:string, checks:array}
     */
    public static function scoreReputation(array $reputation): array
    {
        $checks = [
            'response_rate' => [
                'label' => 'معدل الرد على المراجعات',
                'score' => (int) min(100, $reputation['response_rate'] ?? 0),
                'target' => self::RESPONSE_RATE_TARGET,
            ],
            'review_velocity' => [
                'label' => 'سرعة تدفق المراجعات (آخر 30 يوم)',
                'score' => ($reputation['review_count_30d'] ?? 0) >= 5 ? 100 : (($reputation['review_count_30d'] ?? 0) >= 1 ? 60 : 20),
                'target' => 5,
            ],
            'unreplied_negative' => [
                'label' => 'مراجعات سلبية بدون رد',
                'score' => ($reputation['unreplied_negative'] ?? 0) === 0 ? 100 : 40,
                'target' => 0,
            ],
        ];

        $weighted = 0;
        $n = 0;
        foreach ($checks as $check) {
            $weighted += $check['score'];
            $n++;
        }
        $sectionScore = $n > 0 ? (int) round($weighted / $n) : 0;

        return [
            'weight' => 25,
            'score' => $sectionScore,
            'label' => 'مؤشرات السمعة والرد',
            'checks' => $checks,
        ];
    }

    /**
     * Score محور الظهور على Places (وزن 20). Pure Function.
     * @param array $visibility مخرجات fetchVisibility()
     * @return array{weight:int, score:int, label:string, checks:array}
     */
    public static function scoreVisibility(array $visibility): array
    {
        $checks = [
            'found_on_places' => ['label' => 'ظهورك على Google Places', 'ok' => (bool) ($visibility['found'] ?? false)],
            'photo_count' => ['label' => 'عدد الصور', 'ok' => ($visibility['photo_count'] ?? 0) >= self::MIN_PHOTOS, 'value' => $visibility['photo_count'] ?? 0],
            'live_rating' => ['label' => 'التقييم الظاهر للناس', 'ok' => ($visibility['rating'] ?? 0) >= 4.0, 'value' => $visibility['rating'] ?? null],
            'live_reviews' => ['label' => 'عدد التقييمات على Places', 'ok' => ($visibility['review_count'] ?? 0) >= 10, 'value' => $visibility['review_count'] ?? 0],
        ];

        $okCount = 0;
        $failed = [];
        foreach ($checks as $key => $check) {
            if ($check['ok']) {
                $okCount++;
            } else {
                $failed[] = $key === 'found_on_places' ? $check['label'] : $check['label'] . ' (الموجود: ' . ($check['value'] ?? '—') . ')';
            }
        }

        return [
            'weight' => 20,
            'score' => $okCount > 0 ? (int) round(($okCount / count($checks)) * 100) : 0,
            'label' => 'الظهور على Google Places',
            'checks' => [
                'passed' => $okCount,
                'failed' => $failed,
            ],
        ];
    }

    // ============================================================
    // 4) Visibility على Google Places (وزن 20) - البيانات الحية من جوجل
    // ============================================================


    // ============================================================
    // 5) التوصيات المجمّعة مرتبة حسب الأولوية (بتبني على بيانات حقيقية)
    // ============================================================
    private function buildRecommendations(array $profile, ?array $visibility, array $reputation): array
    {
        $recommendations = [];

        foreach (($profile['missing'] ?? []) as $item) {
            $recommendations[] = [
                'priority' => $item['weight'] >= 15 ? 'high' : ($item['weight'] >= 10 ? 'medium' : 'low'),
                'category' => 'profile',
                'title' => 'أضف: ' . ($item['label'] ?? ''),
                'detail' => $item['recommendation'] ?? '',
            ];
        }

        if ($visibility !== null) {
            if (($visibility['photo_count'] ?? 0) < self::MIN_PHOTOS) {
                $recommendations[] = [
                    'priority' => 'medium',
                    'category' => 'visibility',
                    'title' => 'زود عدد الصور',
                    'detail' => 'عندك ' . $visibility['photo_count'] . ' صور بس - الأعمال السياحية المحلية اللي بتعرض صور حقيقية بتجذب نقرات أكتر بكتير.',
                ];
            }
            if (($visibility['rating'] ?? 0) > 0 && ($visibility['rating'] ?? 0) < 4.0) {
                $recommendations[] = [
                    'priority' => 'high',
                    'category' => 'visibility',
                    'title' => 'التقييم الظاهر أقل من 4',
                    'detail' => 'تقييمك الحالي على Places: ' . number_format((float) $visibility['rating'], 1) . ' - ركز على جمع مراجعات إيجابية جديدة ورد على السلبية بسرعة.',
                ];
            }
        }

        if (($reputation['response_rate'] ?? 0) < self::RESPONSE_RATE_TARGET) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'reputation',
                'title' => 'رد على كل المراجعات',
                'detail' => 'معدل الرد الحالي ' . $reputation['response_rate'] . '% - من المعروف إن الرد على 100% من المراجعات بيحسّن الظهور المحلي. فعّل قواعد الرد التلقائي من Reputation Intelligence.',
            ];
        }
        if (($reputation['unreplied_negative'] ?? 0) > 0) {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'reputation',
                'title' => 'عندك ' . $reputation['unreplied_negative'] . ' مراجعة سلبية بدون رد',
                'detail' => 'الرد على المراجعات السلبية بسرعة وباحترافية بيقلل أثرها على تقييمك العام وبيورّي إنك مهتم برأي عملائك.',
            ];
        }

        $order = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($recommendations, fn ($a, $b) => $order[$a['priority']] <=> $order[$b['priority']]);

        return $recommendations;
    }

    // ============================================================
    // جلب مؤشرات السمعة من قاعدة بياناتنا (أرقام متزامنة فعلًا من جوجل)
    // ============================================================
    private function fetchReputation(int $websiteId, int $userId): array
    {
        try {
            $rows = $this->db->query(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN reply_sent_at IS NOT NULL THEN 1 ELSE 0 END) AS replied,
                        SUM(CASE WHEN review_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS cnt_30d,
                        SUM(CASE WHEN rating <= 2 AND reply_sent_at IS NULL THEN 1 ELSE 0 END) AS unreplied_neg
                 FROM reviews
                 WHERE website_id = ? AND user_id = ? AND source_platform = 'google_business'",
                [$websiteId, $userId]
            );

            $total = (int) ($rows[0]['total'] ?? 0);
            $replied = (int) ($rows[0]['replied'] ?? 0);

            return [
                'total_reviews' => $total,
                'responded' => $replied,
                'response_rate' => $total > 0 ? round(($replied / $total) * 100, 1) : 0.0,
                'review_count_30d' => (int) ($rows[0]['cnt_30d'] ?? 0),
                'unreplied_negative' => (int) ($rows[0]['unreplied_neg'] ?? 0),
            ];
        } catch (Throwable $e) {
            return [
                'total_reviews' => 0,
                'responded' => 0,
                'response_rate' => 0.0,
                'review_count_30d' => 0,
                'unreplied_negative' => 0,
            ];
        }
    }

    // ============================================================
    // جلب بيانات الظهور الحية من Google Places (Place Details بالـ name
    // المستخرج من mapsUri الموجود في Profile الرسمي، واحتياطيًا Text Search)
    // ============================================================
    private function fetchVisibility(array $location, string $apiKey): array
    {
        $placeId = $this->extractPlaceId($location['maps_uri'] ?? '');

        if ($placeId !== '') {
            $details = $this->placeDetails($placeId, $apiKey);
            if ($details !== null) {
                return $this->normalizeVisibility($details, true);
            }
        }

        $query = ($location['name'] ?? '') . ($location['address'] !== null ? ' ' . $this->addressToString($location['address']) : '');
        if ($query === '') {
            return $this->emptyVisibility();
        }

        $search = $this->placesTextSearch($query, $apiKey);
        if (!$search['success'] || empty($search['places'])) {
            return $this->emptyVisibility();
        }

        return $this->normalizeVisibility($search['places'][0], true);
    }

    private function normalizeVisibility(array $place, bool $found): array
    {
        return [
            'found' => $found,
            'name' => $place['name'] ?? null,
            'address' => $place['formatted_address'] ?? null,
            'website' => $place['website'] ?? null,
            'phone' => $place['formatted_phone_number'] ?? $place['international_phone_number'] ?? null,
            'rating' => isset($place['rating']) ? (float) $place['rating'] : null,
            'review_count' => isset($place['user_ratings_total']) ? (int) $place['user_ratings_total'] : 0,
            'photo_count' => isset($place['photos']) && is_array($place['photos']) ? count($place['photos']) : 0,
        ];
    }

    private function emptyVisibility(): array
    {
        return [
            'found' => false,
            'name' => null,
            'address' => null,
            'website' => null,
            'phone' => null,
            'rating' => null,
            'review_count' => 0,
            'photo_count' => 0,
        ];
    }

    private function extractPlaceId(string $mapsUri): string
    {
        if ($mapsUri === '') {
            return '';
        }
        if (preg_match('/[?&]place_id=([^&]+)/', $mapsUri, $m)) {
            return urldecode($m[1]);
        }
        if (preg_match('#/maps/(?:preview/)?place/[^/]+/([0-9a-zA-Z_-]+)#', $mapsUri, $m)) {
            return $m[1];
        }
        return '';
    }

    private function placeDetails(string $placeId, string $apiKey): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $url = 'https://maps.googleapis.com/maps/api/place/details/json?place_id='
            . urlencode($placeId)
            . '&fields=name,formatted_address,formatted_phone_number,international_phone_number,website,rating,user_ratings_total,photos'
            . '&key=' . urlencode($apiKey);

        $response = $this->httpGetJson($url);
        if (!is_array($response) || ($response['status'] ?? '') !== 'OK') {
            return null;
        }
        return $response['result'] ?? null;
    }

    private function placesTextSearch(string $query, string $apiKey): array
    {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'error' => 'curl_extension_missing', 'places' => []];
        }

        $url = 'https://maps.googleapis.com/maps/api/place/textsearch/json?query=' . urlencode($query) . '&key=' . urlencode($apiKey);
        $response = $this->httpGetJson($url);

        if (!is_array($response)) {
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

    // ============================================================
    // أدوات مساعدة (كلها pure functions - قابلة للاختبار الوحدي)
    // ============================================================

    /** مقارنة تقريبية: تشابه النصوص بعد التطبيع (تجاهل حالات/فراغات/علامات/www) */
    public static function sameish(string $a, string $b): bool
    {
        $norm = function (string $s): string {
            $s = trim($s);
            if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $s)) {
                $s = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $s);
            }
            $s = (string) preg_replace('#^/+#', '', $s);
            $s = (string) preg_replace('#^www\.#i', '', $s);
            return mb_strtolower((string) preg_replace('/[^a-z0-9\x{0600}-\x{06FF}]+/iu', ' ', $s));
        };
        $na = trim($norm($a));
        $nb = trim($norm($b));
        if ($na === '' || $nb === '') {
            return false;
        }
        return $na === $nb;
    }

    /** استخراج الأرقام فقط (للمقارنة بين رقمين بنفس الرقم بس صيغ مختلفة) */
    public static function digits(string $value): string
    {
        return (string) preg_replace('/\D+/', '', $value);
    }

    public static function addressToString(array $address): string
    {
        $parts = [];
        foreach (['addressLines', 'locality', 'regionCode', 'postalCode', 'country'] as $key) {
            $val = $address[$key] ?? null;
            if (is_array($val)) {
                $parts = array_merge($parts, $val);
            } elseif (is_string($val) && $val !== '') {
                $parts[] = $val;
            }
        }
        return implode(' ', $parts);
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
}

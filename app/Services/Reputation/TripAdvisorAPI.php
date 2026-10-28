<?php

/**
 * Tourfecto - TripAdvisor API Integration
 * تكامل مع TripAdvisor Content API
 * @version 2.0.0
 *
 * تصحيح 2026-07-13:
 *  1) baseUrl كان غلط تمامًا (api.tripadvisor.com/v1 - دومين مش موجود
 *     أصلاً بالشكل ده)، فكل طلب كان هيفشل من الأساس. الصحيح حسب توثيق
 *     TripAdvisor الحالي: api.content.tripadvisor.com/api/v1
 *  2) sendReply() كانت بترجع "success" فورًا من غير أي طلب فعلي. الحقيقة:
 *     TripAdvisor Content API للعرض فقط (read-only) - مفيش endpoint
 *     للرد على مراجعة برمجيًا خالص. اتصلحت عشان ترجع خطأ واضح بدل كذب.
 *
 * ملاحظات مهمة عن حدود الـ API ده (النسخة العامة/self-serve):
 *  - بيرجع بس أحدث 5 مراجعات لكل موقع (مش سجل كامل).
 *  - مفتاح واحد بيغطي كل المواقع (مش OAuth لكل عميل زي جوجل).
 *  - لازم Attribution (شعار TripAdvisor + صورة التقييم) وقت عرض بياناتهم -
 *    راجع Display Requirements بتاعتهم.
 *  - الحد المجاني: 5000 طلب/شهر، وبعد كده pay-as-you-go.
 */
class TripAdvisorAPI
{
    /**
     * @var string $apiKey - مفتاح API (مشترك لكل المستخدمين، مش OAuth لكل عميل)
     */
    private $apiKey;

    /**
     * @var string $baseUrl - رابط API الأساسي (Content API الحديث)
     */
    private $baseUrl = 'https://api.content.tripadvisor.com/api/v1';

    /**
     * @var int $timeout - مهلة الطلب
     */
    private $timeout = 30;

    public function __construct()
    {
        $this->apiKey = (getenv('TRIPADVISOR_API_KEY') ?: (isset($_ENV['TRIPADVISOR_API_KEY']) ? (string) $_ENV['TRIPADVISOR_API_KEY'] : false)) ?: '';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * البحث عن موقع بالاسم عشان نلاقي location_id بتاعه (مطلوب مرة واحدة
     * وقت الربط، بعدين بنخزن الـ ID ونستخدمه مباشرة).
     * @param string $query اسم الشركة أو جزء من العنوان
     * @param string $category hotels|attractions|restaurants|geos
     */
    public function searchLocations(string $query, string $category = ''): array
    {
        $params = ['searchQuery' => $query];
        if ($category) {
            $params['category'] = $category;
        }

        $response = $this->makeRequest('GET', '/location/search', $params);
        if (!$response['success']) {
            return $response;
        }

        $results = array_map(function ($item) {
            return [
                'location_id' => $item['location_id'] ?? null,
                'name' => $item['name'] ?? '',
                'address' => $item['address_obj']['address_string'] ?? '',
            ];
        }, $response['data']['data'] ?? []);

        return ['success' => true, 'locations' => $results];
    }

    /**
     * جلب المراجعات (أحدث 5 بس، حد الـ API نفسه)
     * @param array $params - معاملات الطلب
     * @return array
     */
    public function getReviews(array $params = []): array
    {
        try {
            $locationId = $params['location_id'] ?? null;
            if (!$locationId) {
                return ['success' => false, 'error' => 'Location ID is required'];
            }

            $endpoint = "/location/{$locationId}/reviews";
            $query = [];
            if (isset($params['language'])) {
                $query['language'] = $params['language'];
            }

            $response = $this->makeRequest('GET', $endpoint, $query);

            if (!$response['success']) {
                return $response;
            }

            $reviews = $this->parseReviews($response['data']);

            return [
                'success' => true,
                'reviews' => $reviews,
                'source' => 'tripadvisor',
                'note' => 'TripAdvisor Content API بترجع أحدث 5 مراجعات بس، مش السجل الكامل',
            ];

        } catch (Exception $e) {
            Logger::error('TripAdvisor Get Reviews Error', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * تصحيح: TripAdvisor Content API للعرض فقط - مفيش أي endpoint حقيقي
     * للرد على مراجعة برمجيًا. النسخة القديمة كانت بترجع "نجاح" وهمي.
     * الحل العملي: نديله رد مُولّد بالذكاء الاصطناعي، والعميل ينسخه
     * ويحطه بنفسه على صفحة المراجعة في TripAdvisor مباشرة.
     */
    public function sendReply(string $reviewId, string $reply): array
    {
        return [
            'success' => false,
            'error' => 'TripAdvisor مبيوفّرش رد برمجي على المراجعات. انسخ الرد المقترح وحطه يدويًا من صفحة المراجعة على TripAdvisor.',
            'manual_action_required' => true,
        ];
    }

    private function makeRequest(string $method, string $endpoint, array $query = [], array $data = []): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'TRIPADVISOR_API_KEY غير مظبوط في إعدادات السيرفر'];
        }

        $query['key'] = $this->apiKey;
        $url = $this->baseUrl . $endpoint . '?' . http_build_query($query);

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Tourfecto/1.0'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'cURL Error: ' . $curlError];
        }

        $decoded = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $errorMessage = $decoded['error']['message'] ?? 'Unknown error';
            return [
                'success' => false,
                'error' => "TripAdvisor API Error ({$httpCode}): {$errorMessage}",
                'http_code' => $httpCode
            ];
        }

        return ['success' => true, 'data' => $decoded, 'http_code' => $httpCode];
    }

    /**
     * تحليل بيانات المراجعات
     * ملحوظة: أسماء الحقول دي أفضل تخمين متاح من توثيق TripAdvisor العام -
     * يُفضّل التأكد منها بطلب تجريبي حقيقي بعد ما تاخد مفتاح API فعلي،
     * لأن TripAdvisor مبتنشرش JSON schema كامل رسمي في التوثيق العام.
     */
    private function parseReviews(array $data): array
    {
        $reviews = [];

        $items = $data['data'] ?? [];
        foreach ($items as $item) {
            $reviews[] = [
                'id' => (string) ($item['id'] ?? ''),
                'rating' => $item['rating'] ?? 0,
                'title' => $item['title'] ?? '',
                'text' => $item['text'] ?? '',
                'language' => $item['lang'] ?? 'en',
                'date' => $item['published_date'] ?? null,
                'reviewer' => [
                    'name' => $item['user']['username'] ?? 'مسافر TripAdvisor'
                ],
                'url' => $item['url'] ?? null,
            ];
        }

        return $reviews;
    }
}

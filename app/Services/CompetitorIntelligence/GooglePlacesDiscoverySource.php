<?php

/**
 * Tourfecto - Competitor Intelligence: Google Places Discovery Source
 * @version 1.0.0
 *
 * مصدر اكتشاف خارجي حقيقي يستخدم Google Places API (Text Search +
 * Place Details) عشان يلاقي أنشطة تجارية حقيقية بنفس القطاع/الدولة -
 * بيانات فعلية من دليل جوجل العام، مش مُخترعة.
 *
 * يعيد استخدام مفتاح Google Maps/Places - نفس المفتاح المُستخدم في
 * خريطة GBP (GoogleBusinessContentController)، وبقى دلوقتي قابل
 * للتعديل من لوحة تحكم الأدمن (Super Admin > الإعدادات > Google Maps /
 * Places) بدل ما يتحدد من .env بس - بنفس أسلوب GeminiClient بالظبط:
 * SystemSettingsService أولاً، ولو مش متسجّل فيها بيرجع لقيمة .env
 * الاحتياطية. لو المفتاح غير موجود أو الطلب فشل (مثلاً Places API مش
 * مفعّلة على نفس المشروع)، يرجّع available=false بسبب واضح - لا يخترع
 * نتائج بديلة أبدًا.
 */
class GooglePlacesDiscoverySource implements CompetitorDiscoverySourceInterface
{
    private const MAX_RESULTS = 6;
    private const TIMEOUT_SECONDS = 8;

    public function discover(array $context): array
    {
        $apiKey = $this->resolveApiKey();
        if ($apiKey === '') {
            return ['available' => false, 'reason' => 'google_maps_api_key_not_configured', 'candidates' => []];
        }

        $industry = trim((string) ($context['industry'] ?? ''));
        $country = trim((string) ($context['country'] ?? ''));

        if ($industry === '') {
            return ['available' => false, 'reason' => 'missing_industry_for_search_query', 'candidates' => []];
        }

        $query = $industry . ($country !== '' ? " in {$country}" : '');
        $searchResult = $this->textSearch($query, $apiKey);

        if (!$searchResult['success']) {
            return ['available' => false, 'reason' => 'google_places_request_failed: ' . $searchResult['error'], 'candidates' => []];
        }

        $candidates = [];
        foreach (array_slice($searchResult['places'], 0, self::MAX_RESULTS) as $place) {
            $details = $this->placeDetails($place['place_id'] ?? '', $apiKey);
            $candidates[] = [
                'name' => $place['name'] ?? 'Unknown',
                'website' => $details['website'] ?? null,
                'industry' => $industry,
                'country' => $country ?: null,
                'category' => 'potential', // من دليل عام - يحتاج مراجعة المستخدم قبل اعتباره منافس مباشر
                'confidence' => !empty($details['website']) ? 'medium' : 'low',
            ];
        }

        return ['available' => true, 'reason' => null, 'candidates' => $candidates];
    }

    public function sourceName(): string
    {
        return 'google_places';
    }

    /**
     * الأولوية لمفتاح لوحة الأدمن (system_settings.google_maps_api_key)،
     * واحتياطيًا GOOGLE_MAPS_API_KEY من .env لو الأدمن لسه ما ضبطش
     * الإعداد من اللوحة.
     */
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

    private function textSearch(string $query, string $apiKey): array
    {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'error' => 'curl_extension_missing', 'places' => []];
        }

        $url = 'https://maps.googleapis.com/maps/api/place/textsearch/json?query=' . urlencode($query) . '&key=' . urlencode($apiKey);
        $response = $this->httpGetJson($url);

        if ($response === null) {
            return ['success' => false, 'error' => 'request_failed', 'places' => []];
        }

        if (($response['status'] ?? '') !== 'OK' && ($response['status'] ?? '') !== 'ZERO_RESULTS') {
            return ['success' => false, 'error' => (string) ($response['status'] ?? 'unknown_error'), 'places' => []];
        }

        return ['success' => true, 'error' => null, 'places' => $response['results'] ?? []];
    }

    private function placeDetails(string $placeId, string $apiKey): array
    {
        if ($placeId === '') {
            return [];
        }
        $url = 'https://maps.googleapis.com/maps/api/place/details/json?place_id=' . urlencode($placeId) . '&fields=website,name&key=' . urlencode($apiKey);
        $response = $this->httpGetJson($url);

        if ($response === null || ($response['status'] ?? '') !== 'OK') {
            return [];
        }

        return ['website' => $response['result']['website'] ?? null];
    }

    private function httpGetJson(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $error = curl_errno($ch) !== 0;
        curl_close($ch);

        if ($error || $body === false) {
            return null;
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }
}

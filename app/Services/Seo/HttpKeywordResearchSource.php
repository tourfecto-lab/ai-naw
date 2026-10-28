<?php

/**
 * Tourfecto - SEO: HTTP Keyword Research Source (G4)
 * @version 1.0.0
 *
 * مزوّد Keyword Intelligence قابل للتكوين عبر متغيرات البيئة:
 *   KEYWORD_RESEARCH_API_URL  - نقطة API (POST)
 *   KEYWORD_RESEARCH_API_KEY  - مفتاح الوصول
 *
 * يرسل الكلمات للـ API (JSON: {"keywords": [...]}) ويتوقع ردًا من الشكل:
 *   { "data": { "<keyword>": { "search_volume": 1200, "difficulty": 35 } } }
 *
 * لو الإعداد ناقص → available=false بسبب واضح (لا اختلاق).
 * **لم يُختبَر** (يحتاج مزوّد Keyword Data حقيقي) - مُوثّق في
 * COMPETITIVE_ANALYSIS_SeoAutoSeo.md.
 */
class HttpKeywordResearchSource implements KeywordResearchSourceInterface
{
    private ?string $apiUrl;
    private ?string $apiKey;

    public function __construct()
    {
        $url = (getenv('KEYWORD_RESEARCH_API_URL') ?: (isset($_ENV['KEYWORD_RESEARCH_API_URL']) ? (string) $_ENV['KEYWORD_RESEARCH_API_URL'] : false));
        $key = (getenv('KEYWORD_RESEARCH_API_KEY') ?: (isset($_ENV['KEYWORD_RESEARCH_API_KEY']) ? (string) $_ENV['KEYWORD_RESEARCH_API_KEY'] : false));
        $this->apiUrl = is_string($url) && $url !== '' ? $url : null;
        $this->apiKey = is_string($key) && $key !== '' ? $key : null;
    }

    public function isConfigured(): bool
    {
        return $this->apiUrl !== null && $this->apiKey !== null;
    }

    public function sourceName(): string
    {
        return 'http_keyword_research';
    }

    public function getKeywordData(array $keywords): array
    {
        $keywords = array_values(array_filter(array_map('trim', $keywords), static fn ($k) => $k !== ''));
        if (empty($keywords)) {
            return ['available' => $this->isConfigured(), 'reason' => null, 'data' => []];
        }
        if (!$this->isConfigured()) {
            return [
                'available' => false,
                'reason' => 'no_keyword_research_source_configured',
                'data' => [],
            ];
        }

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['keywords' => $keywords]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['available' => false, 'configured' => true, 'reason' => 'keyword_source_request_failed', 'data' => [], 'error' => $error ?: 'network error'];
        }
        if ($code < 200 || $code >= 300) {
            return ['available' => false, 'configured' => true, 'reason' => 'keyword_source_request_failed', 'data' => [], 'error' => "HTTP {$code}"];
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
            return ['available' => false, 'configured' => true, 'reason' => 'keyword_source_request_failed', 'data' => [], 'error' => 'رد API غير صالح'];
        }

        $normalized = [];
        foreach ($decoded['data'] as $kw => $meta) {
            if (!is_array($meta)) {
                continue;
            }
            $normalized[(string) $kw] = [
                'search_volume' => isset($meta['search_volume']) ? max(0, (int) $meta['search_volume']) : null,
                'difficulty' => isset($meta['difficulty']) ? max(0, min(100, (int) $meta['difficulty'])) : null,
            ];
        }

        return ['available' => true, 'reason' => null, 'data' => $normalized];
    }
}

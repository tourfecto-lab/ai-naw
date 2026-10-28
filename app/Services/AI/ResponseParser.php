<?php
require_once __DIR__ . '/SeoReportNormalizer.php';

/**
 * Tourfecto - Response Parser
 * معالجة وتحليل استجابات الـ AI
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class ResponseParser
{
    /**
     * @var bool $fixJSON - محاولة إصلاح JSON التالف
     */
    private $fixJSON = true;

    /**
     * Parse analysis response
     * @param string $response
     * @param string $targetUrl
     * @param array $competitorUrls
     * @param string $language
     * @return array
     */
    public function parseAnalysisResponse(
        string $response,
        string $targetUrl,
        array $competitorUrls,
        string $language
    ): array {
        // استخراج JSON من النص
        $jsonData = $this->extractJSON($response);

        if ($jsonData === null) {
            // محاولة إصلاح JSON
            if ($this->fixJSON) {
                $jsonData = $this->fixMalformedJSON($response);
            }
        }

        if ($jsonData === null) {
            // تخزين النص الخام إذا فشل التحليل
            return [
                'raw_response' => $response,
                'parsed' => false,
                'error' => 'Failed to parse AI response as JSON.',
                'metadata' => [
                    'target_url' => $targetUrl,
                    'competitor_urls' => $competitorUrls,
                    'target_language' => $language,
                    'analysis_date' => date('Y-m-d H:i:s'),
                    'analysis_version' => '1.0.0'
                ]
            ];
        }

        // التأكد من وجود جميع الحقول المطلوبة
        $jsonData = $this->ensureRequiredFields($jsonData);

        // إضافة البيانات الوصفية
        $jsonData['metadata'] = [
            'target_url' => $targetUrl,
            'competitor_urls' => $competitorUrls,
            'target_language' => $language,
            'analysis_date' => date('Y-m-d H:i:s'),
            'analysis_version' => '1.0.0'
        ];

        return $jsonData;
    }

    /**
     * Parse sentiment response
     * @param string $response
     * @return array
     */
    public function parseSentimentResponse(string $response): array
    {
        $jsonData = $this->extractJSON($response);

        if ($jsonData === null) {
            // محاولة استخراج المشاعر من النص العادي
            return $this->extractSentimentFromText($response);
        }

        return [
            'label' => $jsonData['label'] ?? 'neutral',
            'score' => (float) ($jsonData['score'] ?? 0.5),
            'confidence' => (float) ($jsonData['confidence'] ?? 0.7)
        ];
    }

    /**
     * استخراج JSON من النص
     * @param string $text
     * @return array|null
     */
    private function extractJSON(string $text): ?array
    {
        // محاولة استخراج JSON من النص
        $jsonPattern = '/\{[\s\S]*\}/';
        preg_match($jsonPattern, $text, $matches);

        if (empty($matches)) {
            // محاولة استخراج JSON من كتل الكود
            $codePattern = '/```(?:json)?\s*([\s\S]*?)\s*```/';
            preg_match($codePattern, $text, $codeMatches);

            if (!empty($codeMatches)) {
                $jsonString = trim($codeMatches[1]);
            } else {
                return null;
            }
        } else {
            $jsonString = $matches[0];
        }

        // محاولة فك JSON
        $data = json_decode($jsonString, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // محاولة إصلاح JSON
            if ($this->fixJSON) {
                $jsonString = $this->cleanJSONString($jsonString);
                $data = json_decode($jsonString, true);
            }

            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }
        }

        return $data;
    }

    /**
     * إصلاح JSON التالف
     * @param string $text
     * @return array|null
     */
    private function fixMalformedJSON(string $text): ?array
    {
        // محاولة إزالة الأجزاء غير الصالحة
        $text = $this->cleanJSONString($text);

        // محاولة العثور على JSON صالح
        $depth = 0;
        $start = -1;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($char === '{') {
                if ($depth === 0) {
                    $start = $i;
                }
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0 && $start !== -1) {
                    $jsonString = substr($text, $start, $i - $start + 1);
                    $data = json_decode($jsonString, true);

                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $data;
                    }
                }
            }
        }

        return null;
    }

    /**
     * تنظيف نص JSON
     * @param string $json
     * @return string
     */
    private function cleanJSONString(string $json): string
    {
        // إزالة الأحرف غير المرغوب فيها
        $json = preg_replace('/[\x00-\x1F\x7F]/u', '', $json);

        // إزالة التعليقات
        $json = preg_replace('/\/\/.*$/m', '', $json);
        $json = preg_replace('/\/\*.*?\*\//s', '', $json);

        // إصلاح الفواصل الزائدة
        $json = preg_replace('/,\s*}/', '}', $json);
        $json = preg_replace('/,\s*]/', ']', $json);

        // إضافة علامات اقتباس للمفاتيح بدونها
        $json = preg_replace('/([{,]\s*)([a-zA-Z_][a-zA-Z0-9_]*)\s*:/', '$1"$2":', $json);

        // إصلاح القيم المنطقية
        $json = preg_replace('/:\s*true\b/', ':true', $json);
        $json = preg_replace('/:\s*false\b/', ':false', $json);
        $json = preg_replace('/:\s*null\b/', ':null', $json);

        return $json;
    }

    /**
     * التأكد من وجود جميع الحقول المطلوبة
     * @param array $data
     * @return array
     */
    private function ensureRequiredFields(array $data): array
    {
        $defaults = [
            'seo' => [
                'keywords' => [],
                'title_suggestions' => [],
                'meta_suggestions' => [],
                'content_gaps' => []
            ],
            'aeo' => [
                'direct_answers' => [],
                'trust_signals' => [],
                'positioning_strategy' => ''
            ],
            'geo' => [
                'faq_schema' => [],
                'questions_generated' => [],
                'map_integration' => [],
                'improvement_suggestions' => ''
            ],
            'score' => 0
        ];

        foreach ($defaults as $key => $default) {
            if (!isset($data[$key])) {
                $data[$key] = $default;
            } elseif (is_array($data[$key]) && is_array($default)) {
                $data[$key] = array_merge($default, $data[$key]);
            }
        }

        // الذكاء الاصطناعي بيسمّي الحقول بأسماء تانية (target_keywords ...)
        return SeoReportNormalizer::normalize($data);
    }

    /**
     * استخراج المشاعر من النص العادي
     * @param string $text
     * @return array
     */
    private function extractSentimentFromText(string $text): array
    {
        $positiveWords = ['positive', 'good', 'great', 'excellent', 'amazing', 'رائع', 'ممتاز', 'جيد'];
        $negativeWords = ['negative', 'bad', 'terrible', 'awful', 'سيئ', 'رديء', 'مخيب'];

        $textLower = strtolower($text);
        $positiveCount = 0;
        $negativeCount = 0;

        foreach ($positiveWords as $word) {
            if (strpos($textLower, strtolower($word)) !== false) {
                $positiveCount++;
            }
        }

        foreach ($negativeWords as $word) {
            if (strpos($textLower, strtolower($word)) !== false) {
                $negativeCount++;
            }
        }

        $label = 'neutral';
        $score = 0.5;
        $confidence = 0.5;

        if ($positiveCount > $negativeCount) {
            $label = 'positive';
            $score = 0.7 + (($positiveCount - $negativeCount) / 20);
            $confidence = 0.6;
        } elseif ($negativeCount > $positiveCount) {
            $label = 'negative';
            $score = 0.3 - (($negativeCount - $positiveCount) / 20);
            $confidence = 0.6;
        }

        return [
            'label' => $label,
            'score' => max(0, min(1, $score)),
            'confidence' => $confidence
        ];
    }

    /**
     * استخراج الكلمات المفتاحية من النص
     * @param string $text
     * @param int $limit
     * @return array
     */
    public function extractKeywords(string $text, int $limit = 20): array
    {
        // تنظيف النص
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s\x{0600}-\x{06FF}]/u', ' ', $text);

        // تقسيم إلى كلمات
        $words = preg_split('/\s+/', $text);
        $words = array_filter($words, function ($word) {
            return strlen($word) > 2;
        });

        // حساب التكرارات
        $freq = array_count_values($words);
        arsort($freq);

        // استخراج الكلمات الأكثر تكراراً
        $keywords = array_slice(array_keys($freq), 0, $limit);

        return $keywords;
    }

    /**
     * استخراج العناوين المقترحة من النص
     * @param string $text
     * @param int $limit
     * @return array
     */
    public function extractTitleSuggestions(string $text, int $limit = 5): array
    {
        $titles = [];

        // البحث عن جمل تبدو كعناوين
        $sentences = preg_split('/[.!?]+/', $text);

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (strlen($sentence) > 10 && strlen($sentence) < 100) {
                $titles[] = $sentence;
            }
        }

        return array_slice($titles, 0, $limit);
    }
}

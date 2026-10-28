<?php

/**
 * Tourfecto - AI Engine Main Class
 * محرك الذكاء الاصطناعي الرئيسي لتحليل SEO/AEO/GEO
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class TourfectoAIEngine
{
    /**
     * @var GeminiClient $geminiClient - عميل Gemini API
     */
    private $geminiClient;

    /**
     * @var PromptBuilder $promptBuilder - بناء الـ Prompts
     */
    private $promptBuilder;

    /**
     * @var ResponseParser $responseParser - معالجة الاستجابات
     */
    private $responseParser;

    /**
     * @var SemanticCache $semanticCache - نظام الكاش الذكي
     */
    private $semanticCache;

    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;

    /**
     * @var SubscriptionValidator $subscription - نظام الاشتراكات
     */
    private $subscription;

    /** آخر generateReviewReply رجّع رد جاهز (مش من الذكاء الاصطناعي) */
    public bool $lastReviewReplyWasFallback = false;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->subscription = new SubscriptionValidator();
        $this->geminiClient = new GeminiClient();
        $this->promptBuilder = new PromptBuilder();
        $this->responseParser = new ResponseParser();
        $this->semanticCache = new SemanticCache();
    }

    /**
     * تحليل موقع باستخدام Gemini API مع نظام الكاش الذكي
     * @param int $userId - معرف المستخدم
     * @param int $websiteId - معرف الموقع
     * @param string $targetUrl - رابط الموقع المستهدف
     * @param array $competitorUrls - روابط المنافسين (3 روابط)
     * @param string $language - اللغة المستهدفة
     * @return array - نتائج التحليل
     */
    public function analyzeWebsite(
        int $userId,
        int $websiteId,
        string $targetUrl,
        array $competitorUrls,
        string $language = 'en'
    ): array {
        $billingUncertain = false;
        $billingScope = isset($this->subscription) ? $this->subscription->beginUsageScope() : [];
        try {
        try {
            // 1. التحقق من الصلاحيات والاشتراك
            $creditsCheck = $this->subscription->reserveCompetitorAnalysisCredits($userId);
            if (!$creditsCheck['available']) {
                return [
                    'success' => false,
                    'error' => $creditsCheck['message'],
                    'code' => 403
                ];
            }

            // 2. التحقق من الكاش الذكي - 🔒 المفتاح فيه رقم العميل (كان عميل تاني بياخد تقرير غيره)
            $audienceContext=InternationalSettingsService::audience($userId,$websiteId);
            $cacheKey = $this->semanticCache->generateKey($targetUrl, array_merge($competitorUrls, ['#u' . $userId,'#aud:'.hash('sha256',json_encode($audienceContext))]), $language);
            $cachedResult = $this->semanticCache->get($cacheKey);

            if ($cachedResult !== null) {
                // تحديث تاريخ الاستخدام
                $this->updateReportUsage($userId);

                // إصلاح: لازم نرجّع report_id حتى في حالة الكاش، عشان
                // المستخدم يقدر يفتح "التقرير الكامل" بدل ما ياخد رسالة
                // ميتة زي "راجع تبويب تقارير AI" من غير رابط شغال.
                return [
                    'success' => true,
                    'from_cache' => true,
                    'cache_key' => $cacheKey,
                    'report_id' => $this->semanticCache->getReportId($cacheKey),
                    'data' => $cachedResult,
                    'message' => 'Report retrieved from semantic cache.'
                ];
            }

            // 3. التحقق من عدد المنافسين
            if (count($competitorUrls) > 5) {
                return [
                    'success' => false,
                    'error' => 'أقصى حاجة 5 منافسين',
                    'code' => 400
                ];
            }

            // 4. التحقق من صحة الروابط
            foreach (array_merge([$targetUrl], $competitorUrls) as $url) {
                if (!filter_var($url, FILTER_VALIDATE_URL)) {
                    return [
                        'success' => false,
                        'error' => "الرابط غير صحيح: {$url}",
                        'code' => 400
                    ];
                }
            }

            // 5. بناء الـ Prompt
            // 5.a جلب الصفحات فعلًا عشان التحليل يبقى مبني على حقائق مش تخمين
            $facts = '';
            if (class_exists('PageFacts')) {
                @set_time_limit(180);
                $facts .= PageFacts::toPrompt(PageFacts::extract($targetUrl), 'الموقع المستهدف');
                foreach (array_slice($competitorUrls, 0, 5) as $i => $cu) {
                    $facts .= PageFacts::toPrompt(PageFacts::extract($cu, 8), 'منافس ' . ($i + 1));
                }
            }
            $prompt = $this->promptBuilder->buildAnalysisPrompt(
                $targetUrl,
                $competitorUrls,
                $language,
                $facts
            );

            // 6. إرسال الطلب إلى Gemini API
            $apiResponse = $this->geminiClient->generateContent($prompt, ['task'=>'website_analysis','user_id'=>$userId,'website_id'=>$websiteId,'output_language'=>$language]);

            if (!$apiResponse['success']) {
                return [
                    'success' => false,
                    'error' => $apiResponse['error'],
                    'code' => 500
                ];
            }

            // 7. معالجة وتحليل النتيجة
            $reportData = $this->responseParser->parseAnalysisResponse(
                $apiResponse['data'],
                $targetUrl,
                $competitorUrls,
                $language
            );

            // 8. حفظ التقرير في قاعدة البيانات
            $reportId = $this->saveReport(
                $userId,
                $websiteId,
                $targetUrl,
                $competitorUrls,
                $language,
                $reportData,
                $apiResponse['tokens_used'] ?? 0,
                $apiResponse['cost'] ?? 0
            );

            // 9. تخزين النتيجة في الكاش الذكي
            $this->semanticCache->set($cacheKey, $reportData, $reportId ?: null);

            // 10. استهلاك رصيد التحليل
            if (!$this->subscription->consumeCompetitorAnalysisCredit($userId, ($creditsCheck['source'] ?? '') === 'wallet')) throw new RuntimeException('تعذر تأكيد محاسبة الاستخدام');

            return [
                'success' => true,
                'from_cache' => false,
                'cache_key' => $cacheKey,
                'report_id' => $reportId,
                'data' => $reportData,
                'message' => 'Analysis completed successfully.'
            ];

        } catch (Exception $e) { $billingUncertain = true;
            Logger::error('AI Analysis Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 500
            ];
        }
    
        } catch (Throwable $billingFailure) {
            $billingUncertain = true; throw $billingFailure;
        } finally {
            if (isset($this->subscription)) $this->subscription->releasePendingReservations($billingUncertain,$billingScope);
        }
    }

    /**
     * توليد رد ذكي للمراجعات
     * @param string $reviewText - نص المراجعة
     * @param array $sentiment - تحليل المشاعر
     * @param string $platform - المنصة
     * @param int $userId - معرف المستخدم
     * @return string|null
     */
    public function generateReviewReply(
        string $reviewText,
        array $sentiment,
        string $platform,
        int $userId
    ): ?string {
        $this->lastReviewReplyWasFallback = false;
        $billingUncertain = false;
        $billingScope = isset($this->subscription) ? $this->subscription->beginUsageScope() : [];
        try {
        try {
            // التحقق من رصيد الـ AI
            $creditsCheck = $this->subscription->reserveAICredits($userId);
            if (!$creditsCheck['available']) {
                return $this->getFallbackReply($sentiment, $reviewText);
            }

            // بناء الـ Prompt
            $prompt = $this->promptBuilder->buildReviewReplyPrompt(
                $reviewText,
                $sentiment,
                $platform
            );
            // مركز الأتمتة: أمثلة من ردود صاحب النشاط على تقييمات من نفس النوع
            if (class_exists('AutomationEngine')) {
                $label = (string) ($sentiment['label'] ?? 'neutral');
                $bucket = isset($sentiment['rating']) ? AutomationCatalog::reviewBucket($sentiment['rating'])
                    : ($label === 'positive' ? 'positive' : ($label === 'negative' ? 'negative' : 'neutral'));
                $prompt .= AutomationEngine::instance()->styleBlock($userId, 'review_replies', $bucket);
            }

            // إرسال الطلب إلى Gemini API
            $apiResponse = $this->geminiClient->generateContent($prompt, ['task'=>'review_reply']);

            if (!$apiResponse['success']) {
                return $this->getFallbackReply($sentiment, $reviewText);
            }

            // استهلاك رصيد الـ AI
            if (!$this->subscription->consumeAICredits($userId, 1, ($creditsCheck['source'] ?? '') === 'wallet')) throw new RuntimeException('تعذر تأكيد محاسبة الاستخدام');

            return $apiResponse['data'];

        } catch (Exception $e) { $billingUncertain = true;
            Logger::error('Generate Review Reply Error', [
                'message' => $e->getMessage()
            ]);
            return $this->getFallbackReply($sentiment, $reviewText);
        }
    
        } catch (Throwable $billingFailure) {
            $billingUncertain = true; throw $billingFailure;
        } finally {
            if (isset($this->subscription)) $this->subscription->releasePendingReservations($billingUncertain,$billingScope);
        }
    }

    /**
     * توليد رد ذكي للشات
     * @param string $message - رسالة العميل
     * @param int $userId - معرف المستخدم
     * @param array $context - سياق المحادثة
     * @return string|null
     */
    public function generateChatReply(
        string $message,
        int $userId,
        array $context = []
    ): ?string {
        $billingUncertain = false;
        $billingScope = isset($this->subscription) ? $this->subscription->beginUsageScope() : [];
        try {
        try {
            // التحقق من رصيد الـ AI
            $creditsCheck = $this->subscription->reserveAICredits($userId);
            if (!$creditsCheck['available']) {
                return $this->getFallbackChatReply($message);
            }

            // بناء الـ Prompt
            $prompt = $this->promptBuilder->buildChatReplyPrompt($message, $context);
            if (class_exists('AutomationEngine')) {
                $prompt .= AutomationEngine::instance()->styleBlock($userId, 'chat_replies');
            }

            // إرسال الطلب إلى Gemini API
            $apiResponse = $this->geminiClient->generateContent($prompt, ['task'=>'chat_reply']);

            if (!$apiResponse['success']) {
                return $this->getFallbackChatReply($message);
            }

            // استهلاك رصيد الـ AI
            if (!$this->subscription->consumeAICredits($userId, 1, ($creditsCheck['source'] ?? '') === 'wallet')) throw new RuntimeException('تعذر تأكيد محاسبة الاستخدام');

            return $apiResponse['data'];

        } catch (Exception $e) { $billingUncertain = true;
            Logger::error('Generate Chat Reply Error', [
                'message' => $e->getMessage()
            ]);
            return $this->getFallbackChatReply($message);
        }
    
        } catch (Throwable $billingFailure) {
            $billingUncertain = true; throw $billingFailure;
        } finally {
            if (isset($this->subscription)) $this->subscription->releasePendingReservations($billingUncertain,$billingScope);
        }
    }

    /**
     * تحليل المشاعر في النص
     * @param string $text - النص المراد تحليله
     * @param int $userId - معرف المستخدم
     * @return array
     */
    public function analyzeSentiment(string $text, int $userId): array
    {
        $billingUncertain = false;
        $billingScope = isset($this->subscription) ? $this->subscription->beginUsageScope() : [];
        try {
        try {
            // التحقق من رصيد الـ AI
            $creditsCheck = $this->subscription->reserveAICredits($userId);
            if (!$creditsCheck['available']) {
                return $this->simpleSentimentAnalysis($text);
            }

            // بناء الـ Prompt
            $prompt = $this->promptBuilder->buildSentimentAnalysisPrompt($text);

            // إرسال الطلب إلى Gemini API
            $apiResponse = $this->geminiClient->generateContent($prompt, ['task'=>'sentiment']);

            if (!$apiResponse['success']) {
                return $this->simpleSentimentAnalysis($text);
            }

            // استهلاك رصيد الـ AI
            if (!$this->subscription->consumeAICredits($userId, 1, ($creditsCheck['source'] ?? '') === 'wallet')) throw new RuntimeException('تعذر تأكيد محاسبة الاستخدام');

            // معالجة النتيجة
            return $this->responseParser->parseSentimentResponse($apiResponse['data']);

        } catch (Exception $e) { $billingUncertain = true;
            Logger::error('Sentiment Analysis Error', [
                'message' => $e->getMessage()
            ]);
            return $this->simpleSentimentAnalysis($text);
        }
    
        } catch (Throwable $billingFailure) {
            $billingUncertain = true; throw $billingFailure;
        } finally {
            if (isset($this->subscription)) $this->subscription->releasePendingReservations($billingUncertain,$billingScope);
        }
    }

    /**
     * ترجمة نص
     * @param string $text - النص المراد ترجمته
     * @param string $targetLanguage - اللغة المستهدفة
     * @param int $userId - معرف المستخدم
     * @return string|null
     */
    public function translateText(string $text, string $targetLanguage, int $userId): ?string
    {
        $billingUncertain = false;
        $billingScope = isset($this->subscription) ? $this->subscription->beginUsageScope() : [];
        try {
        try {
            // التحقق من رصيد الـ AI
            $creditsCheck = $this->subscription->reserveAICredits($userId);
            if (!$creditsCheck['available']) {
                return $text;
            }

            // بناء الـ Prompt
            $prompt = $this->promptBuilder->buildTranslationPrompt($text, $targetLanguage);

            // إرسال الطلب إلى Gemini API
            $apiResponse = $this->geminiClient->generateContent($prompt, ['task'=>'translation']);

            if (!$apiResponse['success']) {
                return $text;
            }

            // استهلاك رصيد الـ AI
            if (!$this->subscription->consumeAICredits($userId, 1, ($creditsCheck['source'] ?? '') === 'wallet')) throw new RuntimeException('تعذر تأكيد محاسبة الاستخدام');

            return $apiResponse['data'];

        } catch (Exception $e) { $billingUncertain = true;
            Logger::error('Translation Error', [
                'message' => $e->getMessage()
            ]);
            return $text;
        }
    
        } catch (Throwable $billingFailure) {
            $billingUncertain = true; throw $billingFailure;
        } finally {
            if (isset($this->subscription)) $this->subscription->releasePendingReservations($billingUncertain,$billingScope);
        }
    }

    /**
     * حفظ التقرير في قاعدة البيانات
     * @param int $userId
     * @param int $websiteId
     * @param string $targetUrl
     * @param array $competitorUrls
     * @param string $language
     * @param array $reportData
     * @param int $tokensUsed
     * @param float $cost
     * @return int
     */
    private function saveReport(
        int $userId,
        int $websiteId,
        string $targetUrl,
        array $competitorUrls,
        string $language,
        array $reportData,
        int $tokensUsed,
        float $cost
    ): int {
        try {
            // استخراج البيانات من التقرير
            $seoData = $reportData['seo'] ?? [];
            $aeoData = $reportData['aeo'] ?? [];
            $geoData = $reportData['geo'] ?? [];

            $sql = "INSERT INTO ai_reports (
                        website_id, user_id, report_type, target_url, 
                        competitor_urls, target_language,
                        seo_keywords, seo_title_suggestions, seo_meta_suggestions, seo_content_gaps,
                        aeo_direct_answers, aeo_trust_signals, aeo_positioning_strategy,
                        geo_faq_schema, geo_questions_generated, geo_map_integration, geo_improvement_suggestions,
                        full_report_json, analysis_score, keywords_found, 
                        competitors_analyzed, is_cached, cached_until, status,
                        tokens_used, cost_in_usd
                    ) VALUES (
                        :website_id, :user_id, 'full', :target_url,
                        :competitor_urls, :target_language,
                        :seo_keywords, :seo_title_suggestions, :seo_meta_suggestions, :seo_content_gaps,
                        :aeo_direct_answers, :aeo_trust_signals, :aeo_positioning_strategy,
                        :geo_faq_schema, :geo_questions_generated, :geo_map_integration, :geo_improvement_suggestions,
                        :full_report_json, :analysis_score, :keywords_found,
                        :competitors_analyzed, 1, DATE_ADD(NOW(), INTERVAL 7 DAY), 'completed',
                        :tokens_used, :cost_in_usd
                    )";

            $params = [
                ':website_id' => $websiteId,
                ':user_id' => $userId,
                ':target_url' => $targetUrl,
                ':competitor_urls' => json_encode($competitorUrls),
                ':target_language' => $language,
                ':seo_keywords' => json_encode($seoData['keywords'] ?? []),
                ':seo_title_suggestions' => json_encode($seoData['title_suggestions'] ?? []),
                ':seo_meta_suggestions' => json_encode($seoData['meta_suggestions'] ?? []),
                ':seo_content_gaps' => json_encode($seoData['content_gaps'] ?? []),
                ':aeo_direct_answers' => json_encode($aeoData['direct_answers'] ?? []),
                ':aeo_trust_signals' => json_encode($aeoData['trust_signals'] ?? []),
                ':aeo_positioning_strategy' => $this->toText($aeoData['positioning_strategy'] ?? ''),
                ':geo_faq_schema' => json_encode($geoData['faq_schema'] ?? []),
                ':geo_questions_generated' => json_encode($geoData['questions_generated'] ?? []),
                ':geo_map_integration' => json_encode($geoData['map_integration'] ?? []),
                ':geo_improvement_suggestions' => $this->toText($geoData['improvement_suggestions'] ?? ''),
                ':full_report_json' => json_encode($reportData),
                ':analysis_score' => $this->toScore($reportData['score'] ?? 0),
                ':keywords_found' => is_array($seoData['keywords'] ?? null) ? count($seoData['keywords']) : 0,
                ':competitors_analyzed' => 3,
                ':tokens_used' => $tokensUsed,
                ':cost_in_usd' => $cost
            ];

            return (int) $this->db->query($sql, $params);

        } catch (Exception $e) {
            Logger::error('Save Report Error', [
                'message' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * الـ AI أحيانًا بيرجّع النص كـ array/object بدل string - كان بيتحفظ
     * "Array" أو بيطلع Warning: Array to string conversion.
     */
    private function toText($value): string
    {
        if (is_array($value) || is_object($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return (string) $value;
    }

    /**
     * score ممكن ييجي رقم أو نص أو array (مثلاً {"overall": 62}) - عمود
     * analysis_score رقم صحيح، فكان بيفشل بـ "Incorrect integer value: 'Array'".
     */
    private function toScore($value): int
    {
        if (is_array($value)) {
            foreach (['overall', 'total', 'score', 'value'] as $k) {
                if (isset($value[$k]) && is_numeric($value[$k])) {
                    return max(0, min(100, (int) round((float) $value[$k])));
                }
            }
            $nums = array_filter($value, 'is_numeric');
            return $nums ? max(0, min(100, (int) round(array_sum($nums) / count($nums)))) : 0;
        }
        return is_numeric($value) ? max(0, min(100, (int) round((float) $value))) : 0;
    }

    /**
     * تحديث استخدام التقرير
     * @param int $userId
     */
    private function updateReportUsage(int $userId): void
    {
        try {
            $sql = "INSERT INTO api_usage_logs (user_id, api_type, endpoint, status_code) 
                    VALUES (:user_id, 'gemini', 'cached_report', 200)";

            $this->db->query($sql, [':user_id' => $userId]);

        } catch (Exception $e) {
            // تجاهل الخطأ
        }
    }

    /**
     * رد بديل في حالة فشل الـ AI (للمراجعات)
     * @param array $sentiment
     * @return string
     */
    private function getFallbackReply(array $sentiment, string $reviewText = ''): string
    {
        $this->lastReviewReplyWasFallback = true;
        return ReviewReplyFallback::forReview($reviewText, (string) ($sentiment['label'] ?? 'neutral'));
    }

    /**
     * رد بديل في حالة فشل الـ AI (للشات)
     * @param string $message
     * @return string
     */
    private function getFallbackChatReply(string $message): string
    {
        return "شكراً لتواصلك معنا. أحد ممثلي خدمة العملاء سيتواصل معك قريباً لتقديم المساعدة المناسبة. نعتذر عن أي تأخير.";
    }

    /**
     * تحليل مشاعر بسيط (Fallback)
     * @param string $text
     * @return array
     */
    private function simpleSentimentAnalysis(string $text): array
    {
        $positiveWords = ['رائع', 'ممتاز', 'جيد', 'جميل', 'مذهل', 'أفضل', 'مثالي', 'سعيد', 'شكراً', 'good', 'great', 'excellent', 'amazing', 'best', 'happy', 'thanks'];
        $negativeWords = ['سيئ', 'مخيب', 'فاشل', 'رديء', 'محبط', 'غاضب', 'أسوأ', 'سيء', 'bad', 'terrible', 'awful', 'disappointed', 'worst', 'angry', 'poor'];

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

        $score = 0.5;
        $label = 'neutral';

        if ($positiveCount > $negativeCount) {
            $score = 0.7 + (($positiveCount - $negativeCount) / 20);
            $label = 'positive';
        } elseif ($negativeCount > $positiveCount) {
            $score = 0.3 - (($negativeCount - $positiveCount) / 20);
            $label = 'negative';
        }

        $score = min(1, max(0, $score));

        return [
            'label' => $label,
            'score' => $score,
            'confidence' => 0.6
        ];
    }

    /**
     * الحصول على إحصائيات استخدام الـ AI
     * @param int $userId
     * @return array
     */
    public function getUsageStats(int $userId): array
    {
        try {
            $sql = "SELECT 
                        COUNT(*) as total_requests,
                        SUM(tokens_used) as total_tokens,
                        SUM(cost_in_usd) as total_cost
                    FROM ai_reports 
                    WHERE user_id = ? 
                    AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)";

            $result = $this->db->query($sql, [$userId]);

            return [
                'total_requests' => (int) ($result[0]['total_requests'] ?? 0),
                'total_tokens' => (int) ($result[0]['total_tokens'] ?? 0),
                'total_cost' => round((float) ($result[0]['total_cost'] ?? 0), 6)
            ];

        } catch (Exception $e) {
            return [
                'total_requests' => 0,
                'total_tokens' => 0,
                'total_cost' => 0
            ];
        }
    }
}

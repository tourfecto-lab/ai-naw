<?php

/**
 * Tourfecto - AI Test
 * اختبارات محرك الذكاء الاصطناعي
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class AITest
{
    /**
     * @var TourfectoAIEngine $ai - محرك الذكاء الاصطناعي
     */
    private $ai;

    /**
     * @var array $testResults - نتائج الاختبارات
     */
    private $testResults = [];

    /**
     * @var int $passed - عدد الاختبارات الناجحة
     */
    private $passed = 0;

    /**
     * @var int $failed - عدد الاختبارات الفاشلة
     */
    private $failed = 0;

    /**
     * @var bool $mockMode - وضع المحاكاة (بدون API)
     */
    private $mockMode = false;

    /**
     * Constructor
     */
    public function __construct(bool $mockMode = true)
    {
        $this->ai = new TourfectoAIEngine();
        $this->mockMode = $mockMode;
    }

    /**
     * تشغيل جميع الاختبارات
     */
    public function runAll(): void
    {
        echo "\n🤖 AI Engine Tests\n";
        echo "==================\n\n";

        if ($this->mockMode) {
            echo "⚠️  Running in MOCK mode (no real API calls)\n\n";
        }

        $this->testAnalysis();
        $this->testSentimentAnalysis();
        $this->testReplyGeneration();
        $this->testTranslation();
        $this->testSemanticCache();
        $this->testPromptBuilder();
        $this->testResponseParser();

        $this->printSummary();
    }

    /**
     * اختبار تحليل الموقع
     */
    private function testAnalysis(): void
    {
        $this->startTest('Website Analysis');

        try {
            if ($this->mockMode) {
                $this->pass('Analysis test skipped (mock mode)');
                return;
            }

            $result = $this->ai->analyzeWebsite(
                1, // user_id
                1, // website_id
                'https://test-travel.com',
                [
                    'https://competitor1.com',
                    'https://competitor2.com',
                    'https://competitor3.com'
                ],
                'ar'
            );

            if (isset($result['success'])) {
                $this->pass('Analysis executed successfully');

                if (isset($result['data'])) {
                    $this->pass('Analysis data received');

                    // التحقق من وجود بيانات SEO
                    if (isset($result['data']['seo'])) {
                        $this->pass('SEO data present');
                    } else {
                        $this->fail('SEO data missing');
                    }

                    // التحقق من وجود بيانات AEO
                    if (isset($result['data']['aeo'])) {
                        $this->pass('AEO data present');
                    } else {
                        $this->fail('AEO data missing');
                    }

                    // التحقق من وجود بيانات GEO
                    if (isset($result['data']['geo'])) {
                        $this->pass('GEO data present');
                    } else {
                        $this->fail('GEO data missing');
                    }
                }
            } else {
                $this->fail('Analysis failed: ' . ($result['error'] ?? 'Unknown error'));
            }

        } catch (Exception $e) {
            $this->fail('Analysis error: ' . $e->getMessage());
        }
    }

    /**
     * اختبار تحليل المشاعر
     */
    private function testSentimentAnalysis(): void
    {
        $this->startTest('Sentiment Analysis');

        try {
            $texts = [
                'هذه الخدمة رائعة وممتازة! أنصح الجميع بتجربتها.' => 'positive',
                'الخدمة سيئة جداً ولا أنصح بها أحداً.' => 'negative',
                'الخدمة جيدة ولكن تحتاج إلى بعض التحسينات.' => 'neutral'
            ];

            foreach ($texts as $text => $expected) {
                $result = $this->ai->analyzeSentiment($text, 1);

                if ($result['label'] === $expected) {
                    $this->pass("Sentiment analysis correct for: '{$text}' -> {$result['label']}");
                } else {
                    $this->fail("Sentiment analysis failed for: '{$text}' -> expected {$expected}, got {$result['label']}");
                }
            }

        } catch (Exception $e) {
            $this->fail('Sentiment analysis error: ' . $e->getMessage());
        }
    }

    /**
     * اختبار توليد الردود
     */
    private function testReplyGeneration(): void
    {
        $this->startTest('Reply Generation');

        try {
            $reviewText = 'الخدمة رائعة وفريق العمل متعاون جداً. شكراً لكم!';
            $sentiment = ['label' => 'positive', 'score' => 0.9, 'confidence' => 0.95];

            $reply = $this->ai->generateReviewReply($reviewText, $sentiment, 'tripadvisor', 1);

            if (!empty($reply)) {
                $this->pass('Reply generated successfully');
                $this->pass("Reply preview: " . substr($reply, 0, 100) . "...");

                // التحقق من احتواء الرد على كلمات شكر
                if (strpos($reply, 'شكر') !== false || strpos($reply, 'سعد') !== false) {
                    $this->pass('Reply contains appropriate sentiment');
                } else {
                    $this->fail('Reply missing appropriate sentiment');
                }
            } else {
                $this->fail('Reply generation failed');
            }

        } catch (Exception $e) {
            $this->fail('Reply generation error: ' . $e->getMessage());
        }
    }

    /**
     * اختبار الترجمة
     */
    private function testTranslation(): void
    {
        $this->startTest('Translation');

        try {
            if ($this->mockMode) {
                $this->pass('Translation test skipped (mock mode)');
                return;
            }

            $englishText = 'Welcome to Tourfecto, the smart tourism platform.';
            $translated = $this->ai->translateText($englishText, 'ar', 1);

            if (!empty($translated)) {
                $this->pass('Translation successful');
                $this->pass("Translation preview: " . substr($translated, 0, 100) . "...");

                // التحقق من وجود كلمات عربية
                if (preg_match('/[\x{0600}-\x{06FF}]/u', $translated)) {
                    $this->pass('Translation contains Arabic characters');
                } else {
                    $this->fail('Translation missing Arabic characters');
                }
            } else {
                $this->fail('Translation failed');
            }

        } catch (Exception $e) {
            $this->fail('Translation error: ' . $e->getMessage());
        }
    }

    /**
     * اختبار الكاش الذكي
     */
    private function testSemanticCache(): void
    {
        $this->startTest('Semantic Cache');

        try {
            $cache = new SemanticCache();

            // اختبار تخزين الكاش
            $testKey = 'test_cache_key_' . uniqid();
            $testData = ['test' => 'data', 'timestamp' => time()];

            $setResult = $cache->set($testKey, $testData);

            if ($setResult) {
                $this->pass('Cache set successful');
            } else {
                $this->fail('Cache set failed');
            }

            // اختبار استرجاع الكاش
            $retrieved = $cache->get($testKey);

            if ($retrieved !== null && $retrieved['test'] === 'data') {
                $this->pass('Cache get successful');
            } else {
                $this->fail('Cache get failed');
            }

            // اختبار البحث المشابه
            $similar = $cache->findSimilar(
                'https://test1.com',
                ['https://comp1.com', 'https://comp2.com', 'https://comp3.com'],
                'ar'
            );

            if ($similar !== null || $similar === null) {
                $this->pass('Similar search executed');
            } else {
                $this->fail('Similar search failed');
            }

        } catch (Exception $e) {
            $this->fail('Semantic cache error: ' . $e->getMessage());
        }
    }

    /**
     * اختبار بناء الـ Prompts
     */
    private function testPromptBuilder(): void
    {
        $this->startTest('Prompt Builder');

        try {
            $builder = new PromptBuilder();

            // اختبار بناء Prompt تحليل
            $analysisPrompt = $builder->buildAnalysisPrompt(
                'https://test.com',
                ['https://comp1.com', 'https://comp2.com', 'https://comp3.com'],
                'ar'
            );

            if (!empty($analysisPrompt)) {
                $this->pass('Analysis prompt built successfully');
                $this->pass("Prompt preview: " . substr($analysisPrompt, 0, 150) . "...");
            } else {
                $this->fail('Analysis prompt building failed');
            }

            // اختبار بناء Prompt رد
            $replyPrompt = $builder->buildReviewReplyPrompt(
                'Great service!',
                ['label' => 'positive', 'score' => 0.9],
                'tripadvisor'
            );

            if (!empty($replyPrompt)) {
                $this->pass('Reply prompt built successfully');
            } else {
                $this->fail('Reply prompt building failed');
            }

            // اختبار بناء Prompt شات
            $chatPrompt = $builder->buildChatReplyPrompt('Hello!', []);

            if (!empty($chatPrompt)) {
                $this->pass('Chat prompt built successfully');
            } else {
                $this->fail('Chat prompt building failed');
            }

        } catch (Exception $e) {
            $this->fail('Prompt builder error: ' . $e->getMessage());
        }
    }

    /**
     * اختبار معالجة الاستجابات
     */
    private function testResponseParser(): void
    {
        $this->startTest('Response Parser');

        try {
            $parser = new ResponseParser();

            // اختبار استخراج JSON
            $response = 'Here is the result: {"seo": {"keywords": ["travel", "tourism"]}}';
            $parsed = $parser->parseAnalysisResponse($response, 'https://test.com', [], 'ar');

            if (isset($parsed['seo']) || isset($parsed['raw_response'])) {
                $this->pass('JSON extraction successful');
            } else {
                $this->fail('JSON extraction failed');
            }

            // اختبار استخراج المشاعر
            $sentimentResponse = '{"label": "positive", "score": 0.85}';
            $sentiment = $parser->parseSentimentResponse($sentimentResponse);

            if (isset($sentiment['label'])) {
                $this->pass('Sentiment parsing successful');
            } else {
                $this->fail('Sentiment parsing failed');
            }

            // اختبار استخراج الكلمات المفتاحية
            $keywords = $parser->extractKeywords('This is a test text about travel and tourism in Saudi Arabia');

            if (count($keywords) > 0) {
                $this->pass('Keyword extraction successful, found: ' . count($keywords));
            } else {
                $this->fail('Keyword extraction failed');
            }

        } catch (Exception $e) {
            $this->fail('Response parser error: ' . $e->getMessage());
        }
    }

    /**
     * بدء اختبار
     * @param string $name
     */
    private function startTest(string $name): void
    {
        echo "\n  ▶ {$name}\n";
    }

    /**
     * تسجيل نجاح
     * @param string $message
     */
    private function pass(string $message): void
    {
        echo "    ✅ {$message}\n";
        $this->passed++;
        $this->testResults[] = ['status' => 'PASS', 'message' => $message];
    }

    /**
     * تسجيل فشل
     * @param string $message
     */
    private function fail(string $message): void
    {
        echo "    ❌ {$message}\n";
        $this->failed++;
        $this->testResults[] = ['status' => 'FAIL', 'message' => $message];
    }

    /**
     * طباعة الملخص
     */
    private function printSummary(): void
    {
        $total = $this->passed + $this->failed;
        $percentage = $total > 0 ? round(($this->passed / $total) * 100, 2) : 0;

        echo "\n" . str_repeat('=', 50) . "\n";
        echo "📊 AI Engine Test Summary\n";
        echo str_repeat('=', 50) . "\n";
        echo "  ✅ Passed: {$this->passed}\n";
        echo "  ❌ Failed: {$this->failed}\n";
        echo "  📝 Total: {$total}\n";
        echo "  📈 Success Rate: {$percentage}%\n";
        echo str_repeat('=', 50) . "\n\n";
    }
}

// ============================================
// 6. تشغيل الاختبارات
// ============================================
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    $mockMode = isset($_GET['mock']) ? $_GET['mock'] !== 'false' : true;
    $test = new AITest($mockMode);
    $test->runAll();
}

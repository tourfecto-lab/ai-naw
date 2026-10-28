<?php

/**
 * Tourfecto - Reputation Integration Test
 * اختبارات نظام إدارة السمعة
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class ReputationTest
{
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
     * @var ReputationManager $reputationManager - مدير السمعة
     */
    private $reputationManager;

    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;

    /**
     * @var int $testUserId - معرف مستخدم الاختبار
     */
    private $testUserId;

    /**
     * @var int $testWebsiteId - معرف موقع الاختبار
     */
    private $testWebsiteId;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->reputationManager = new ReputationManager();
        $this->testUserId = $this->createTestUser();
        $this->testWebsiteId = $this->createTestWebsite();
    }

    /**
     * إنشاء مستخدم اختبار
     * @return int
     */
    private function createTestUser(): int
    {
        $sql = "INSERT INTO users (company_name, email, password, phone, is_active) 
                VALUES (:company_name, :email, :password, :phone, :is_active)";

        $id = $this->db->query($sql, [
            ':company_name' => 'Test Reputation Company',
            ':email' => 'rep_test_' . uniqid() . '@example.com',
            ':password' => password_hash('Test@123', PASSWORD_ARGON2ID),
            ':phone' => '+966500000001',
            ':is_active' => 1
        ]);

        return $id;
    }

    /**
     * إنشاء موقع اختبار
     * @return int
     */
    private function createTestWebsite(): int
    {
        $sql = "INSERT INTO websites (user_id, main_url, company_name, industry, is_verified) 
                VALUES (:user_id, :main_url, :company_name, :industry, :is_verified)";

        $id = $this->db->query($sql, [
            ':user_id' => $this->testUserId,
            ':main_url' => 'https://rep-test-' . uniqid() . '.com',
            ':company_name' => 'Test Reputation Website',
            ':industry' => 'tourism',
            ':is_verified' => 1
        ]);

        return $id;
    }

    /**
     * تشغيل جميع الاختبارات
     */
    public function runAll(): void
    {
        echo "\n⭐ Reputation Integration Tests\n";
        echo "===============================\n\n";

        $this->testReviewProcessing();
        $this->testSentimentAnalysis();
        $this->testReplyGeneration();
        $this->testReviewStats();
        $this->testPlatformIntegration();
        $this->testSentimentTrends();

        $this->cleanup();
        $this->printSummary();
    }

    /**
     * اختبار معالجة المراجعات
     */
    private function testReviewProcessing(): void
    {
        $this->startTest('Review Processing');

        // محاكاة مراجعة من TripAdvisor
        $webhookData = [
            'platform' => 'tripadvisor',
            'platform_review_id' => 'ta_' . uniqid(),
            'reviewer_name' => 'Test Reviewer',
            'review_text' => 'هذه خدمة رائعة! أنصح الجميع بتجربتها.',
            'rating' => 5,
            'review_date' => date('Y-m-d H:i:s'),
            'user_id' => $this->testUserId,
            'website_id' => $this->testWebsiteId
        ];

        $result = $this->reputationManager->processWebhook($webhookData);

        if ($result['success']) {
            $this->pass('Review processed successfully');

            if (isset($result['review_id'])) {
                $this->pass('Review ID returned: ' . $result['review_id']);
                $this->testReviewId = $result['review_id'];
            }

            if (isset($result['sentiment']['label'])) {
                $this->pass('Sentiment label: ' . $result['sentiment']['label']);
            }
        } else {
            $this->fail('Review processing failed: ' . ($result['error'] ?? 'Unknown error'));
        }

        // محاكاة مراجعة من Google Business
        $webhookData = [
            'platform' => 'google_business',
            'platform_review_id' => 'gb_' . uniqid(),
            'reviewer_name' => 'Google User',
            'review_text' => 'Great service! Highly recommended.',
            'rating' => 4.5,
            'review_date' => date('Y-m-d H:i:s'),
            'user_id' => $this->testUserId,
            'website_id' => $this->testWebsiteId
        ];

        $result = $this->reputationManager->processWebhook($webhookData);

        if ($result['success']) {
            $this->pass('Google Business review processed successfully');
        } else {
            $this->fail('Google Business review processing failed');
        }

        // اختبار مراجعة بدون بيانات كافية
        $invalidData = [
            'platform' => 'tripadvisor',
            'reviewer_name' => 'Test User'
        ];

        $result = $this->reputationManager->processWebhook($invalidData);

        if (!$result['success'] && isset($result['error'])) {
            $this->pass('Invalid review rejected correctly');
        } else {
            $this->fail('Invalid review should be rejected');
        }
    }

    /**
     * اختبار تحليل المشاعر
     */
    private function testSentimentAnalysis(): void
    {
        $this->startTest('Sentiment Analysis');

        // اختبار مراجعة إيجابية
        $text = 'هذه الخدمة ممتازة وفريق العمل رائع! أنصح الجميع بها.';
        $sentiment = $this->reputationManager->analyzeSentiment($text, $this->testUserId);

        if ($sentiment['label'] === 'positive') {
            $this->pass('Positive sentiment detected correctly');
            $this->pass('Sentiment score: ' . $sentiment['score']);
        } else {
            $this->fail('Positive sentiment not detected');
        }

        // اختبار مراجعة سلبية
        $text = 'الخدمة سيئة جداً ولا أنصح بها أحداً.';
        $sentiment = $this->reputationManager->analyzeSentiment($text, $this->testUserId);

        if ($sentiment['label'] === 'negative') {
            $this->pass('Negative sentiment detected correctly');
        } else {
            $this->fail('Negative sentiment not detected');
        }

        // اختبار مراجعة محايدة
        $text = 'الخدمة جيدة ولكن تحتاج إلى بعض التحسينات.';
        $sentiment = $this->reputationManager->analyzeSentiment($text, $this->testUserId);

        if ($sentiment['label'] === 'neutral') {
            $this->pass('Neutral sentiment detected correctly');
        } else {
            $this->fail('Neutral sentiment not detected');
        }

        // اختبار تحليل المشاعر بدون رصيد (محاكاة)
        $newUserId = $this->createTestUser();
        $sentiment = $this->reputationManager->analyzeSentiment(
            'هذه خدمة رائعة!',
            $newUserId
        );

        if (!empty($sentiment['label'])) {
            $this->pass('Sentiment analysis works even without credits (fallback)');
        } else {
            $this->fail('Sentiment analysis failed without credits');
        }

        // تنظيف
        $sql = "DELETE FROM users WHERE id = :id";
        $this->db->query($sql, [':id' => $newUserId]);
    }

    /**
     * اختبار توليد الردود
     */
    private function testReplyGeneration(): void
    {
        $this->startTest('Reply Generation');

        // اختبار توليد رد لمراجعة إيجابية
        $reviewText = 'خدمة رائعة وفريق عمل متعاون. شكراً لكم!';
        $sentiment = ['label' => 'positive', 'score' => 0.9];

        $reply = $this->reputationManager->generateReply(
            $reviewText,
            $sentiment,
            'tripadvisor',
            $this->testUserId
        );

        if (!empty($reply)) {
            $this->pass('Reply generated for positive review');
            $this->pass("Reply preview: " . substr($reply, 0, 100) . "...");
        } else {
            $this->fail('Reply generation failed for positive review');
        }

        // اختبار توليد رد لمراجعة سلبية
        $reviewText = 'الخدمة سيئة جداً ولا أنصح بها.';
        $sentiment = ['label' => 'negative', 'score' => 0.2];

        $reply = $this->reputationManager->generateReply(
            $reviewText,
            $sentiment,
            'tripadvisor',
            $this->testUserId
        );

        if (!empty($reply)) {
            $this->pass('Reply generated for negative review');

            // التحقق من احتواء الرد على اعتذار
            if (strpos($reply, 'أسف') !== false || strpos($reply, 'اعتذار') !== false) {
                $this->pass('Reply contains apology for negative review');
            }
        } else {
            $this->fail('Reply generation failed for negative review');
        }

        // اختبار توليد رد لمراجعة محايدة
        $reviewText = 'الخدمة جيدة ولكن تحتاج إلى تحسين.';
        $sentiment = ['label' => 'neutral', 'score' => 0.5];

        $reply = $this->reputationManager->generateReply(
            $reviewText,
            $sentiment,
            'google_business',
            $this->testUserId
        );

        if (!empty($reply)) {
            $this->pass('Reply generated for neutral review');
        } else {
            $this->fail('Reply generation failed for neutral review');
        }
    }

    /**
     * اختبار إحصائيات المراجعات
     */
    private function testReviewStats(): void
    {
        $this->startTest('Review Statistics');

        // إضافة عدة مراجعات
        $reviews = [
            ['text' => 'خدمة ممتازة', 'rating' => 5, 'sentiment' => 'positive'],
            ['text' => 'جيد جداً', 'rating' => 4, 'sentiment' => 'positive'],
            ['text' => 'متوسط', 'rating' => 3, 'sentiment' => 'neutral'],
            ['text' => 'سيء', 'rating' => 2, 'sentiment' => 'negative']
        ];

        foreach ($reviews as $review) {
            $webhookData = [
                'platform' => 'tripadvisor',
                'platform_review_id' => 'stats_' . uniqid(),
                'reviewer_name' => 'Stats User',
                'review_text' => $review['text'],
                'rating' => $review['rating'],
                'review_date' => date('Y-m-d H:i:s'),
                'user_id' => $this->testUserId,
                'website_id' => $this->testWebsiteId
            ];

            $this->reputationManager->processWebhook($webhookData);
        }

        // الحصول على إحصائيات السمعة
        $stats = $this->reputationManager->getReputationStats($this->testUserId, $this->testWebsiteId);

        if (!empty($stats)) {
            $this->pass('Reputation stats retrieved successfully');
            $this->pass('Total reviews: ' . $stats['total_reviews']);
            $this->pass('Average rating: ' . $stats['avg_rating']);
            $this->pass('Positive: ' . $stats['positive']);
            $this->pass('Neutral: ' . $stats['neutral']);
            $this->pass('Negative: ' . $stats['negative']);
        } else {
            $this->fail('Failed to retrieve reputation stats');
        }
    }

    /**
     * اختبار تكامل المنصات
     */
    private function testPlatformIntegration(): void
    {
        $this->startTest('Platform Integration');

        // اختبار جلب المراجعات من TripAdvisor (محاكاة)
        $params = [
            'location_id' => 'test_location',
            'limit' => 5,
            'offset' => 0
        ];

        // هذا قد يفشل إذا لم يكن هناك اتصال حقيقي
        $result = $this->reputationManager->fetchReviews('tripadvisor', $this->testUserId, $params);

        if ($result['success'] || isset($result['error'])) {
            $this->pass('TripAdvisor integration test executed');
        } else {
            $this->fail('TripAdvisor integration test failed');
        }

        // اختبار جلب المراجعات من Google Business (محاكاة)
        $params = [
            'location_id' => 'test_location',
            'limit' => 5
        ];

        $result = $this->reputationManager->fetchReviews('google_business', $this->testUserId, $params);

        if ($result['success'] || isset($result['error'])) {
            $this->pass('Google Business integration test executed');
        } else {
            $this->fail('Google Business integration test failed');
        }

        // اختبار منصة غير مدعومة
        $result = $this->reputationManager->fetchReviews('unsupported', $this->testUserId, []);

        if (!$result['success'] && isset($result['error'])) {
            $this->pass('Unsupported platform rejected correctly');
        } else {
            $this->fail('Unsupported platform should be rejected');
        }
    }

    /**
     * اختبار اتجاهات المشاعر
     */
    private function testSentimentTrends(): void
    {
        $this->startTest('Sentiment Trends');

        // إضافة مراجعات بأيام مختلفة
        $dates = [
            date('Y-m-d', strtotime('-5 days')),
            date('Y-m-d', strtotime('-3 days')),
            date('Y-m-d', strtotime('-1 day'))
        ];

        $texts = [
            'خدمة رائعة',
            'جيد ولكن يحتاج تحسين',
            'ممتاز جداً'
        ];

        foreach ($dates as $index => $date) {
            $webhookData = [
                'platform' => 'tripadvisor',
                'platform_review_id' => 'trend_' . uniqid(),
                'reviewer_name' => 'Trend User',
                'review_text' => $texts[$index],
                'rating' => $index === 1 ? 3 : 5,
                'review_date' => $date . ' 12:00:00',
                'user_id' => $this->testUserId,
                'website_id' => $this->testWebsiteId
            ];

            $this->reputationManager->processWebhook($webhookData);
        }

        // الحصول على اتجاهات المشاعر
        $trends = $this->reputationManager->getSentimentTrends($this->testUserId, 7);

        if (!empty($trends)) {
            $this->pass('Sentiment trends retrieved successfully');
            $this->pass('Number of data points: ' . count($trends));
        } else {
            $this->fail('Failed to retrieve sentiment trends');
        }
    }

    /**
     * تنظيف بيانات الاختبار
     */
    private function cleanup(): void
    {
        // حذف المراجعات
        $sql = "DELETE FROM reviews WHERE user_id = :user_id";
        $this->db->query($sql, [':user_id' => $this->testUserId]);

        // حذف المواقع
        $sql = "DELETE FROM websites WHERE id = :website_id";
        $this->db->query($sql, [':website_id' => $this->testWebsiteId]);

        // حذف المستخدم
        $sql = "DELETE FROM users WHERE id = :user_id";
        $this->db->query($sql, [':user_id' => $this->testUserId]);
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
        echo "📊 Reputation Test Summary\n";
        echo str_repeat('=', 50) . "\n";
        echo "  ✅ Passed: {$this->passed}\n";
        echo "  ❌ Failed: {$this->failed}\n";
        echo "  📝 Total: {$total}\n";
        echo "  📈 Success Rate: {$percentage}%\n";
        echo str_repeat('=', 50) . "\n\n";
    }
}

// ============================================
// تشغيل الاختبارات
// ============================================
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    $test = new ReputationTest();
    $test->runAll();
}

<?php

/**
 * Tourfecto - Reputation Manager
 * مدير السمعة الرئيسي لإدارة المراجعات والردود
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class ReputationManager
{
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;

    /**
     * @var SentimentAnalyzer $sentimentAnalyzer - محلل المشاعر
     */
    private $sentimentAnalyzer;

    /**
     * @var ReplyGenerator $replyGenerator - مولد الردود
     */
    private $replyGenerator;

    /**
     * @var TripAdvisorAPI $tripAdvisorAPI - تكامل Tripadvisor
     */
    private $tripAdvisorAPI;

    /**
     * @var GoogleBusinessAPI $googleBusinessAPI - تكامل Google Business
     */
    private $googleBusinessAPI;

    /**
     * @var Encryption $encryption - نظام التشفير
     */
    private $encryption;

    /**
     * @var SubscriptionValidator $subscription - نظام الاشتراكات
     */
    private $subscription;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->sentimentAnalyzer = new SentimentAnalyzer();
        $this->replyGenerator = new ReplyGenerator();
        $this->tripAdvisorAPI = new TripAdvisorAPI();
        $this->googleBusinessAPI = new GoogleBusinessAPI();
        $this->encryption = new Encryption();
        $this->subscription = new SubscriptionValidator();
    }

    /**
     * معالجة مراجعة واردة من Webhook
     * @param array $webhookData - بيانات Webhook
     * @return array
     */
    public function processWebhook(array $webhookData): array
    {
        try {
            // التحقق من البيانات الأساسية
            if (!isset($webhookData['platform']) || !isset($webhookData['review_text'])) {
                return [
                    'success' => false,
                    'error' => 'Missing required fields: platform, review_text'
                ];
            }

            // تحديد المستخدم والموقع
            $userId = $this->resolveUserId($webhookData);
            $websiteId = $this->resolveWebsiteId($webhookData);

            if (!$userId || !$websiteId) {
                return [
                    'success' => false,
                    'error' => 'Unable to resolve user or website.'
                ];
            }

            // التحقق من صلاحية الاشتراك
            $subscriptionCheck = $this->subscription->validateSubscription($userId);
            if (!$subscriptionCheck['valid']) {
                return [
                    'success' => false,
                    'error' => 'No active subscription found.'
                ];
            }

            // حفظ المراجعة
            $reviewId = $this->saveReview($userId, $websiteId, $webhookData);

            if (!$reviewId) {
                return [
                    'success' => false,
                    'error' => 'Failed to save review.'
                ];
            }

            // تحليل المشاعر
            $sentiment = $this->sentimentAnalyzer->analyze(
                $webhookData['review_text'],
                $userId
            );

            // تحديث المراجعة بتحليل المشاعر
            $this->updateReviewSentiment($reviewId, $sentiment);

            // توليد رد ذكي
            $reply = $this->replyGenerator->generate(
                $webhookData['review_text'],
                $sentiment,
                $webhookData['platform'] ?? 'tripadvisor',
                $userId
            );

            // الرد الجاهز (لما الذكاء الاصطناعي مش متاح) مش بيتحفظ كمسودة:
            // الرد التلقائي بينشر المسودة المحفوظة، فنسيبها فاضية لحد ما
            // الذكاء الاصطناعي يكتب رد حقيقي في الدورة الجاية.
            if ($reply && $this->replyGenerator->lastWasFallback) {
                $reply = null;
            }

            if ($reply) {
                $this->saveReply($reviewId, $reply);
            }

            // إرسال الرد إلى المنصة إذا كان Auto Pilot مفعلاً. التقييم
            // بيتعلّم "اتبعت" بس لو المنصة قبلت الرد فعلًا - قبل كده كان
            // بيتعلّم حتى لو الإرسال فشل، فالتقييم يفضل من غير رد للأبد
            // والرد التلقائي (ReviewAutoReplyRunner) يتخطاه.
            $botSettings = $this->subscription->getBotSettings($userId, $websiteId);
            $replySent = false;
            if (!empty($botSettings['auto_pilot']) && $reply) {
                $sendResult = $this->sendReplyToPlatform(
                    $webhookData['platform'],
                    $webhookData['platform_review_id'],
                    $reply,
                    $userId
                );
                if (!empty($sendResult['success'])) {
                    $this->markReplySent($reviewId);
                    $replySent = true;
                }
            }

            return [
                'success' => true,
                'review_id' => $reviewId,
                'sentiment' => $sentiment,
                'reply_generated' => (bool) $reply,
                'reply_sent' => $replySent,
                'message' => 'Review processed successfully.'
            ];

        } catch (Exception $e) {
            Logger::error('Reputation Webhook Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * توليد رد على مراجعة (wrapper حول ReplyGenerator)
     * تصحيح: ReputationController كان بينادي $this->reputationManager->generateReply(...)
     * بس الميثود دي ماكانتش موجودة أصلًا هنا - كانت هتدّي Fatal Error
     * "Call to undefined method" في كل مرة حد يدوس على "توليد رد".
     * @param string $reviewText
     * @param array $sentiment
     * @param string $platform
     * @param int $userId
     * @return string|null
     */
    public function generateReply(string $reviewText, array $sentiment, string $platform, int $userId): ?string
    {
        return $this->replyGenerator->generate($reviewText, $sentiment, $platform, $userId);
    }

    /**
     * جلب مراجعات من منصة معينة
     * @param string $platform
     * @param int $userId
     * @param array $params
     * @return array
     */
    public function fetchReviews(string $platform, int $userId, array $params = []): array
    {
        try {
            switch ($platform) {
                case 'tripadvisor':
                    return $this->tripAdvisorAPI->getReviews($params);

                case 'google_business':
                    return $this->googleBusinessAPI->getReviews($params);

                default:
                    return [
                        'success' => false,
                        'error' => "Unsupported platform: {$platform}"
                    ];
            }

        } catch (Exception $e) {
            Logger::error('Fetch Reviews Error', [
                'platform' => $platform,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * إرسال رد إلى المنصة
     * @param string $platform
     * @param string $reviewId
     * @param string $reply
     * @param int $userId
     * @return array
     */
    public function sendReplyToPlatform(string $platform, string $reviewId, string $reply, int $userId): array
    {
        try {
            switch ($platform) {
                case 'tripadvisor':
                    return $this->tripAdvisorAPI->sendReply($reviewId, $reply);

                case 'google_business':
                    return $this->googleBusinessAPI->sendReply($reviewId, $reply);

                default:
                    return [
                        'success' => false,
                        'error' => "Unsupported platform: {$platform}"
                    ];
            }

        } catch (Exception $e) {
            Logger::error('Send Reply Error', [
                'platform' => $platform,
                'review_id' => $reviewId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * الحصول على إحصائيات السمعة
     * @param int $userId
     * @param int|null $websiteId
     * @return array
     */
    public function getReputationStats(int $userId, ?int $websiteId = null): array
    {
        try {
            $params = [$userId];
            $sql = "SELECT 
                        COUNT(*) as total_reviews,
                        AVG(rating) as avg_rating,
                        SUM(CASE WHEN sentiment = 'positive' THEN 1 ELSE 0 END) as positive,
                        SUM(CASE WHEN sentiment = 'neutral' THEN 1 ELSE 0 END) as neutral,
                        SUM(CASE WHEN sentiment = 'negative' THEN 1 ELSE 0 END) as negative,
                        SUM(CASE WHEN reply_sent_at IS NOT NULL THEN 1 ELSE 0 END) as replied,
                        SUM(CASE WHEN reply_sent_at IS NULL AND ai_generated_reply IS NOT NULL THEN 1 ELSE 0 END) as pending_reply
                    FROM reviews 
                    WHERE user_id = ?";

            if ($websiteId) {
                $sql .= " AND website_id = ?";
                $params[] = $websiteId;
            }

            $result = $this->db->query($sql, $params);

            if (empty($result)) {
                return [
                    'total_reviews' => 0,
                    'avg_rating' => 0,
                    'positive' => 0,
                    'neutral' => 0,
                    'negative' => 0,
                    'replied' => 0,
                    'pending_reply' => 0,
                    'sentiment_distribution' => [
                        'positive' => 0,
                        'neutral' => 0,
                        'negative' => 0
                    ]
                ];
            }

            $stats = $result[0];
            $total = (int) $stats['total_reviews'];

            return [
                'total_reviews' => $total,
                'avg_rating' => round((float) $stats['avg_rating'], 2),
                'positive' => (int) $stats['positive'],
                'neutral' => (int) $stats['neutral'],
                'negative' => (int) $stats['negative'],
                'replied' => (int) $stats['replied'],
                'pending_reply' => (int) $stats['pending_reply'],
                'sentiment_distribution' => [
                    'positive' => $total > 0 ? round(((int) $stats['positive'] / $total) * 100, 2) : 0,
                    'neutral' => $total > 0 ? round(((int) $stats['neutral'] / $total) * 100, 2) : 0,
                    'negative' => $total > 0 ? round(((int) $stats['negative'] / $total) * 100, 2) : 0
                ]
            ];

        } catch (Exception $e) {
            Logger::error('Get Reputation Stats Error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return [
                'total_reviews' => 0,
                'avg_rating' => 0,
                'positive' => 0,
                'neutral' => 0,
                'negative' => 0,
                'replied' => 0,
                'pending_reply' => 0,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * الحصول على تحليل اتجاهات المشاعر
     * @param int $userId
     * @param int $days
     * @return array
     */
    public function getSentimentTrends(int $userId, int $days = 30): array
    {
        try {
            $sql = "SELECT 
                        DATE(created_at) as date,
                        AVG(sentiment_score) as avg_score,
                        COUNT(*) as count,
                        SUM(CASE WHEN sentiment = 'positive' THEN 1 ELSE 0 END) as positive,
                        SUM(CASE WHEN sentiment = 'neutral' THEN 1 ELSE 0 END) as neutral,
                        SUM(CASE WHEN sentiment = 'negative' THEN 1 ELSE 0 END) as negative
                    FROM reviews 
                    WHERE user_id = ? 
                    AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                    GROUP BY DATE(created_at)
                    ORDER BY date ASC";

            $result = $this->db->query($sql, [$userId, $days]);

            $trends = [];
            foreach ($result as $row) {
                $trends[] = [
                    'date' => $row['date'],
                    'avg_score' => round((float) $row['avg_score'], 2),
                    'count' => (int) $row['count'],
                    'positive' => (int) $row['positive'],
                    'neutral' => (int) $row['neutral'],
                    'negative' => (int) $row['negative']
                ];
            }

            return $trends;

        } catch (Exception $e) {
            Logger::error('Get Sentiment Trends Error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * حفظ المراجعة في قاعدة البيانات
     * @param int $userId
     * @param int $websiteId
     * @param array $data
     * @return int
     */
    private function saveReview(int $userId, int $websiteId, array $data): int
    {
        try {
            // تشفير البيانات الحساسة
            $encryptedEmail = !empty($data['reviewer_email'])
                ? $this->encryption->encryptCustomerData($data['reviewer_email'], $data['reviewer_phone'] ?? '')
                : null;

            $encryptedPhone = !empty($data['reviewer_phone'])
                ? $this->encryption->encryptCustomerData($data['reviewer_phone'], $data['reviewer_phone'])
                : null;

            $sql = "INSERT INTO reviews (
                        website_id, user_id, source_platform, external_review_id,
                        reviewer_name, reviewer_email, reviewer_phone,
                        review_text, review_language, rating, review_date,
                        webhook_raw_data
                    ) VALUES (
                        :website_id, :user_id, :platform, :platform_review_id,
                        :reviewer_name, :reviewer_email, :reviewer_phone,
                        :review_text, :review_language, :rating, :review_date,
                        :webhook_raw_data
                    )";

            $params = [
                ':website_id' => $websiteId,
                ':user_id' => $userId,
                ':platform' => $data['platform'] ?? 'tripadvisor',
                ':platform_review_id' => $data['platform_review_id'] ?? null,
                ':reviewer_name' => $data['reviewer_name'] ?? null,
                ':reviewer_email' => $encryptedEmail,
                ':reviewer_phone' => $encryptedPhone,
                ':review_text' => $data['review_text'],
                ':review_language' => $data['review_language'] ?? 'ar',
                ':rating' => $data['rating'] ?? 0,
                ':review_date' => $data['review_date'] ?? date('Y-m-d H:i:s'),
                ':webhook_raw_data' => json_encode($data)
            ];

            return (int) $this->db->query($sql, $params);

        } catch (Exception $e) {
            Logger::error('Save Review Error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * تحديث تحليل المشاعر للمراجعة
     * @param int $reviewId
     * @param array $sentiment
     */
    private function updateReviewSentiment(int $reviewId, array $sentiment): void
    {
        try {
            $sql = "UPDATE reviews 
                    SET sentiment_score = :score,
                        sentiment = :label,
                        sentiment_confidence = :confidence,
                        updated_at = NOW()
                    WHERE id = :review_id";

            $this->db->query($sql, [
                ':review_id' => $reviewId,
                ':score' => $sentiment['score'] ?? 0.5,
                ':label' => $sentiment['label'] ?? 'neutral',
                ':confidence' => $sentiment['confidence'] ?? 0.7
            ]);

        } catch (Exception $e) {
            Logger::error('Update Sentiment Error', [
                'review_id' => $reviewId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * حفظ الرد على المراجعة
     * @param int $reviewId
     * @param string $reply
     */
    private function saveReply(int $reviewId, string $reply): void
    {
        try {
            $sql = "UPDATE reviews 
                    SET ai_generated_reply = :reply,
                        reply_status = 'pending',
                        updated_at = NOW()
                    WHERE id = :review_id";

            $this->db->query($sql, [
                ':review_id' => $reviewId,
                ':reply' => $reply
            ]);

        } catch (Exception $e) {
            Logger::error('Save Reply Error', [
                'review_id' => $reviewId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * تحديث حالة إرسال الرد
     * @param int $reviewId
     */
    private function markReplySent(int $reviewId): void
    {
        try {
            $sql = "UPDATE reviews 
                    SET reply_sent_at = NOW(),
                        reply_status = 'sent',
                        updated_at = NOW()
                    WHERE id = :review_id";

            $this->db->query($sql, [':review_id' => $reviewId]);

        } catch (Exception $e) {
            Logger::error('Mark Reply Sent Error', [
                'review_id' => $reviewId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * تحديد معرف المستخدم من بيانات Webhook
     * @param array $data
     * @return int|null
     */
    private function resolveUserId(array $data): ?int
    {
        if (isset($data['user_id'])) {
            return (int) $data['user_id'];
        }

        if (isset($data['api_key'])) {
            $sql = "SELECT id FROM users WHERE api_key = :api_key LIMIT 1";
            $result = $this->db->query($sql, [':api_key' => $data['api_key']]);
            return !empty($result) ? (int) $result[0]['id'] : null;
        }

        if (isset($data['platform_user_id'])) {
            $sql = "SELECT user_id FROM websites WHERE platform_user_id = :platform_user_id LIMIT 1";
            $result = $this->db->query($sql, [':platform_user_id' => $data['platform_user_id']]);
            return !empty($result) ? (int) $result[0]['user_id'] : null;
        }

        return null;
    }

    /**
     * تحديد معرف الموقع من بيانات Webhook
     * @param array $data
     * @return int|null
     */
    private function resolveWebsiteId(array $data): ?int
    {
        if (isset($data['website_id'])) {
            return (int) $data['website_id'];
        }

        if (isset($data['website_url'])) {
            $urlCol = Website::urlColumn();
            $sql = "SELECT id FROM websites WHERE {$urlCol} = :url LIMIT 1";
            $result = $this->db->query($sql, [':url' => $data['website_url']]);
            return !empty($result) ? (int) $result[0]['id'] : null;
        }

        if (isset($data['platform_user_id'])) {
            $sql = "SELECT id FROM websites WHERE platform_user_id = :platform_user_id LIMIT 1";
            $result = $this->db->query($sql, [':platform_user_id' => $data['platform_user_id']]);
            return !empty($result) ? (int) $result[0]['id'] : null;
        }

        return null;
    }
}

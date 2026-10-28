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

            $rating = (int) ($webhookData['rating'] ?? 0);
            $text = trim((string) $webhookData['review_text']);
            $existingReply = trim((string) ($webhookData['existing_reply'] ?? ''));
            // تقييم قديم (اتسحب في أول مزامنة) أو صاحب الشركة رد عليه على جوجل بالفعل:
            // مابنولّدش له رد ولا بنبعته (جوجل بيستبدل الرد القديم بالجديد) ولا بنبعت تنبيه - ومابنصرفش ذكاء صناعي عليه
            $historical = !empty($webhookData['historical']);
            $sentiment = ($historical || $text === '') ? self::ratingSentiment($rating) : self::alignSentiment($this->sentimentAnalyzer->analyze($text, $userId), $rating);
            $this->updateReviewSentiment($reviewId, $sentiment);
            if ($existingReply !== '' || $historical) {
                if ($existingReply !== '') $this->markExistingReply((int) $reviewId, $existingReply, (string) ($webhookData['existing_reply_at'] ?? ''));
                return ['success' => true, 'review_id' => $reviewId, 'sentiment' => $sentiment, 'reply_generated' => false, 'reply_sent' => false, 'message' => 'Imported'];
            }

            // توليد رد ذكي
            $reply = $this->replyGenerator->generate(
                $webhookData['review_text'],
                $sentiment,
                $webhookData['platform'] ?? 'tripadvisor',
                $userId,
                $webhookData + ['id' => (int) $reviewId, 'website_id' => (int) $websiteId]
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

            // تقييم سلبي جديد ← تنبيه فوري لصاحب الحساب (إشعار + إيميل) فيه الرد المقترح - فشله مايوقفش حفظ التقييم
            if (class_exists('NegativeReviewAlert')) {
                try {
                    NegativeReviewAlert::maybeSend($userId, $websiteId, (int) $reviewId, $webhookData, $sentiment, null, null, (string) ($reply ?? ''));
                } catch (Throwable $e) {
                    Logger::warning('Negative review alert failed', ['review_id' => $reviewId]);
                }
            }

            // إرسال الرد إلى المنصة إذا كان Auto Pilot مفعلاً. التقييم
            // بيتعلّم "اتبعت" بس لو المنصة قبلت الرد فعلًا - قبل كده كان
            // بيتعلّم حتى لو الإرسال فشل، فالتقييم يفضل من غير رد للأبد
            // والرد التلقائي (ReviewAutoReplyRunner) يتخطاه.
            // النشر على المنصة بقى مكانه واحد: محرك «ردود التقييمات» في مركز الأتمتة (كل 10 دقايق)، اللي بيحترم
            // تشغيل/إيقاف الرد التلقائي ووضع كل نوع تقييم والتقييمات الحساسة. قبل كده كان فيه نشر فوري هنا بمفتاح
            // تاني (الطيار الآلي) فكان ممكن يرد حتى والعميل قافل الرد التلقائي.
            $replySent = false;

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
    public function generateReply(string $reviewText, array $sentiment, string $platform, int $userId, array $review = []): ?string
    {
        return $this->replyGenerator->generate($reviewText, $sentiment, $platform, $userId, $review);
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

    /** أي صيغة تاريخ (RFC3339 / timestamp) ← Y-m-d H:i:s بتوقيت السيرفر؛ الفاضي أو الغلط ← دلوقتي */
    public static function normalizeDate($value): string
    {
        $ts = is_numeric($value) ? (int) $value : (is_string($value) && trim($value) !== '' ? strtotime($value) : false);
        return date('Y-m-d H:i:s', $ts ?: time());
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
                // Google بيبعت التاريخ RFC3339 (2026-10-20T10:15:30.123Z) وMySQL الصارم بيرفضه فالتقييم مايتحفظش
                ':review_date' => self::normalizeDate($data['review_date'] ?? null),
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
    /** المشاعر من النجوم (للتقييم من غير نص أو القديم) */
    public static function ratingSentiment(int $rating): array
    {
        $label = $rating >= 4 ? 'positive' : ($rating > 0 && $rating <= 2 ? 'negative' : 'neutral');
        return ['label' => $label, 'score' => $rating > 0 ? round($rating / 5, 2) : 0.5, 'confidence' => 0.6];
    }

    /** النجوم بتحسم الحالات المحايدة: 5 نجوم «محايد» كانت بتظهر غلط للعميل */
    public static function alignSentiment(array $sentiment, int $rating): array
    {
        $label = (string) ($sentiment['label'] ?? 'neutral');
        if ($label === 'neutral' || $label === '' || $label === 'unknown') {
            if ($rating >= 4) $sentiment['label'] = 'positive';
            elseif ($rating > 0 && $rating <= 2) $sentiment['label'] = 'negative';
        }
        if (!in_array($sentiment['label'] ?? '', ['positive', 'negative', 'neutral', 'mixed'], true)) $sentiment['label'] = self::ratingSentiment($rating)['label'];
        return $sentiment;
    }

    /** رد موجود فعلًا على المنصة (صاحب الشركة رد من جوجل نفسه) */
    public function markExistingReply(int $reviewId, string $reply, string $at = ''): void
    {
        $ts = $at !== '' ? strtotime($at) : false;
        $this->db->query("UPDATE reviews SET ai_generated_reply = ?, is_ai_generated = 0, reply_status = 'sent', reply_sent = 1, reply_sent_at = ?, updated_at = NOW() WHERE id = ?",
            [mb_substr($reply, 0, 4000), date('Y-m-d H:i:s', $ts ?: time()), $reviewId]);
    }

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

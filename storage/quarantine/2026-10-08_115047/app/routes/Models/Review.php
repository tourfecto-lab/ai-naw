<?php
/**
 * Tourfecto - Review Model
 * نموذج المراجعات مع تحليل المشاعر
 * @version 1.1.0 - مُصحَّح (2026-07-15) ليطابق الجدول الحقيقي على السيرفر
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 *
 * ملاحظة تصحيح مهمة: أسامي الأعمدة هنا كانت مبنية على ملف migration
 * قديم (platform, platform_review_id, sentiment_label, auto_reply_generated,
 * reply_sent كـ TINYINT) لكن الجدول الفعلي على السيرفر مختلف:
 *   platform             -> source_platform
 *   platform_review_id   -> external_review_id
 *   sentiment_label      -> sentiment (وفيها كمان قيمة 'mixed')
 *   auto_reply_generated -> ai_generated_reply
 *   reply_sent (0/1)     -> غير موجود؛ الحالة الحقيقية reply_sent_at
 *                           (تاريخ/وقت، NULL = لسه مبعوتش) + عمود
 *                           reply_status (enum جاهز أصلاً على السيرفر:
 *                           pending/approved/rejected/sent/auto_...)
 */

class Review extends Model {
    /**
     * @var string $table - اسم الجدول
     */
    protected $table = 'reviews';

    /**
     * @var array $fillable - الحقول القابلة للتعبئة
     */
    protected $fillable = [
        'website_id',
        'user_id',
        'source_platform',
        'external_review_id',
        'reviewer_name',
        'reviewer_email',
        'reviewer_phone',
        'review_text',
        'review_language',
        'rating',
        'review_date',
        'sentiment_score',
        'sentiment',
        'sentiment_confidence',
        'ai_generated_reply',
        'reply_status',
        'reply_sent_at',
        'reply_approved_by',
        'is_ai_generated',
        'keywords_injected',
        'webhook_payload',
        'ip_address',
        'user_agent'
    ];

    /**
     * @var Encryption $encryption - نظام التشفير
     */
    private $encryption;

    public function __construct(array $attributes = []) {
        parent::__construct($attributes);
        $this->encryption = new Encryption();
    }

    public function save() {
        if (!empty($this->attributes['reviewer_email'])) {
            $this->attributes['reviewer_email'] = $this->encryption->encryptCustomerData(
                $this->attributes['reviewer_email'],
                $this->attributes['reviewer_phone'] ?? ''
            );
        }

        if (!empty($this->attributes['reviewer_phone'])) {
            $this->attributes['reviewer_phone'] = $this->encryption->encryptCustomerData(
                $this->attributes['reviewer_phone'],
                $this->attributes['reviewer_phone']
            );
        }

        return parent::save();
    }

    public function find($id): ?self {
        $review = parent::find($id);

        if ($review) {
            $review->decryptSensitiveData();
        }

        return $review;
    }

    private function decryptSensitiveData(): void {
        if (!empty($this->attributes['reviewer_email'])) {
            $this->attributes['reviewer_email'] = $this->encryption->decryptCustomerData(
                $this->attributes['reviewer_email'],
                $this->attributes['reviewer_phone'] ?? ''
            );
        }

        if (!empty($this->attributes['reviewer_phone'])) {
            $this->attributes['reviewer_phone'] = $this->encryption->decryptCustomerData(
                $this->attributes['reviewer_phone'],
                $this->attributes['reviewer_phone']
            );
        }
    }

    public function getWebsite(): ?Website {
        $sql = "SELECT * FROM websites WHERE id = ? LIMIT 1";
        $result = $this->db->query($sql, [$this->attributes['website_id']]);

        if (empty($result)) {
            return null;
        }

        return new Website($result[0]);
    }

    public function getUser(): ?User {
        $sql = "SELECT * FROM users WHERE id = ? LIMIT 1";
        $result = $this->db->query($sql, [$this->attributes['user_id']]);

        if (empty($result)) {
            return null;
        }

        return new User($result[0]);
    }

    public function updateSentiment(string $sentiment, float $score, float $confidence): bool {
        $this->attributes['sentiment'] = $sentiment;
        $this->attributes['sentiment_score'] = $score;
        $this->attributes['sentiment_confidence'] = $confidence;

        return $this->save() !== false;
    }

    public function updateAutoReply(string $reply): bool {
        $this->attributes['ai_generated_reply'] = $reply;
        $this->attributes['reply_status'] = 'pending';

        return $this->save() !== false;
    }

    public function markReplySent(): bool {
        $this->attributes['reply_sent_at'] = date('Y-m-d H:i:s');
        $this->attributes['reply_status'] = 'sent';

        return $this->save() !== false;
    }

    public static function getSentimentStats(int $websiteId): array {
        $db = Database::getInstance();

        $sql = "SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN sentiment = 'positive' THEN 1 ELSE 0 END) as positive,
                    SUM(CASE WHEN sentiment = 'neutral' THEN 1 ELSE 0 END) as neutral,
                    SUM(CASE WHEN sentiment = 'negative' THEN 1 ELSE 0 END) as negative,
                    SUM(CASE WHEN sentiment = 'mixed' THEN 1 ELSE 0 END) as mixed,
                    AVG(rating) as avg_rating,
                    AVG(sentiment_score) as avg_sentiment
                FROM reviews
                WHERE website_id = ?";

        $result = $db->query($sql, [$websiteId]);

        if (empty($result)) {
            return [
                'total' => 0, 'positive' => 0, 'neutral' => 0, 'negative' => 0,
                'mixed' => 0, 'avg_rating' => 0, 'avg_sentiment' => 0
            ];
        }

        return [
            'total' => (int) ($result[0]['total'] ?? 0),
            'positive' => (int) ($result[0]['positive'] ?? 0),
            'negative' => (int) ($result[0]['negative'] ?? 0),
            'neutral' => (int) ($result[0]['neutral'] ?? 0),
            'mixed' => (int) ($result[0]['mixed'] ?? 0),
            'avg_rating' => round((float) ($result[0]['avg_rating'] ?? 0), 2),
            'avg_sentiment' => round((float) ($result[0]['avg_sentiment'] ?? 0), 2)
        ];
    }

    public static function getPlatformStats(int $websiteId): array {
        $db = Database::getInstance();

        $sql = "SELECT
                    source_platform as platform,
                    COUNT(*) as count,
                    AVG(rating) as avg_rating
                FROM reviews
                WHERE website_id = ?
                GROUP BY source_platform";

        return $db->query($sql, [$websiteId]);
    }
}

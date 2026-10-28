<?php

/**
 * Tourfecto - الرد التلقائي على تقييمات Google بعد الربط
 *
 * بيشتغل من cron/run_automations.php. لكل موقع مربوط بـ Google Business:
 *  1) بيلاقي التقييمات اللي لسه مالهاش رد (آخر 30 يوم، 20 تقييم كحد أقصى
 *     في الدورة عشان أول ربط مايبعتش مئات الردود مرة واحدة).
 *  2) بيكتب رد بأسلوب العميل (أمثلة من ردوده وتعديلاته في التعليمات).
 *  3) بيسأل محرك الأتمتة: ينشر دلوقتي، ولا يستنى موافقة، ولا ماعملش حاجة.
 *
 * قواعد الرد اللي العميل عملها بنفسه (GbpReplyRuleService) ليها الأولوية:
 * التقييمات اللي عليها قاعدة متطابقة بتمشي على القاعدة.
 *
 * @version 1.0.0
 */
class ReviewAutoReplyRunner
{
    public const BACKLOG_DAYS = 30;
    public const PER_SITE_LIMIT = 20;

    /** @var Database */
    private $db;
    /** @var AutomationEngine */
    private $engine;
    /** @var callable|null للاختبارات بس: fn(array $review, string $text): array - بدل الإرسال الفعلي لـ Google */
    public static $transport = null;

    /** @var callable|null للاختبارات: fn(array $review, int $userId): ?string */
    private $draftFactory;

    public function __construct(?Database $db = null, ?AutomationEngine $engine = null, ?callable $draftFactory = null)
    {
        $this->db = $db ?: Database::getInstance();
        $this->engine = $engine ?: AutomationEngine::instance();
        $this->draftFactory = $draftFactory;
    }

    /** كل المواقع المربوطة */
    public function runAll(): array
    {
        $summary = ['sites' => 0, 'sent' => 0, 'pending' => 0, 'skipped' => 0, 'failed' => 0];
        $sites = $this->db->query(
            "SELECT DISTINCT website_id, user_id FROM platform_connections
              WHERE platform = 'google_business' AND status = 'connected'"
        );
        foreach ($sites as $s) {
            $summary['sites']++;
            $r = $this->runForWebsite((int) $s['user_id'], (int) $s['website_id']);
            foreach (['sent', 'pending', 'skipped', 'failed'] as $k) {
                $summary[$k] += $r[$k];
            }
        }
        return $summary;
    }

    public function runForWebsite(int $userId, int $websiteId): array
    {
        $out = ['sent' => 0, 'pending' => 0, 'skipped' => 0, 'failed' => 0];

        // العميل مختار مواقع تانية للخدمة دي
        if (!$this->engine->inScope($userId, 'review_replies', $websiteId)) {
            return $out;
        }

        // لو كل الفئات يدوي، مفيش داعي نصرف رصيد ذكاء اصطناعي على مسودات
        $anyActive = false;
        foreach (array_keys(AutomationCatalog::get('review_replies')['buckets']) as $b) {
            if ($this->engine->effectiveMode($userId, 'review_replies', $b) !== 'manual') {
                $anyActive = true;
            }
        }
        if (!$anyActive) {
            return $out;
        }

        $reviews = $this->db->query(
            "SELECT r.* FROM reviews r
              WHERE r.website_id = ? AND r.user_id = ?
                AND r.source_platform = 'google_business'
                AND r.reply_sent_at IS NULL
                AND (r.reply_status IS NULL OR r.reply_status NOT IN ('sent','auto_sent','rejected'))
                AND COALESCE(r.review_date, r.created_at) >= NOW() - INTERVAL " . self::BACKLOG_DAYS . " DAY
                AND NOT EXISTS (
                    SELECT 1 FROM automation_decisions d
                     WHERE d.feature_key = 'review_replies' AND d.subject_type = 'review'
                       AND d.subject_id = CAST(r.id AS CHAR)
                       AND d.status IN ('pending','auto_executed','rejected','executed','cancelled','expired')
                )
              ORDER BY COALESCE(r.review_date, r.created_at) DESC
              LIMIT " . self::PER_SITE_LIMIT,
            [$websiteId, $userId]
        );

        $hasRules = $this->hasActiveRules($websiteId, $userId);

        foreach ($reviews as $review) {
            $reviewId = (int) $review['id'];

            if ($hasRules && $this->ruleMatches($review, $websiteId, $userId)) {
                $out['skipped']++; // قاعدة العميل هي اللي هتتعامل معاه
                continue;
            }

            $bucket = AutomationCatalog::reviewBucket($review['rating'] ?? 0);
            $existing = $this->engine->findBySubject('review_replies', 'review', (string) $reviewId);

            if (!$existing && $this->engine->effectiveMode($userId, 'review_replies', $bucket) === 'manual') {
                $out['skipped']++;
                continue;
            }

            $draft = (string) ($review['ai_generated_reply'] ?? '');
            if (!$existing && $draft === '') {
                $draft = (string) ($this->makeDraft($review, $userId) ?? '');
                if ($draft === '') {
                    $out['failed']++;
                    continue;
                }
                $this->db->exec(
                    "UPDATE reviews SET ai_generated_reply = ?, is_ai_generated = 1, reply_status = 'pending' WHERE id = ?",
                    [$draft, $reviewId]
                );
            }

            $gate = $this->engine->gate($userId, $websiteId, 'review_replies', $bucket, 'review', (string) $reviewId, [
                'title' => self::reviewTitle($review),
                'context' => (string) ($review['review_text'] ?? ''),
                'draft' => $draft,
                'payload' => ['rating' => (float) ($review['rating'] ?? 0), 'reviewer' => $review['reviewer_name'] ?? null],
            ]);

            if ($gate['action'] === 'execute') {
                $res = self::sendReply($reviewId, $userId, (string) ($gate['final_text'] ?? $draft), null, $this->db);
                $this->engine->complete($gate['decision_id'] ?? null, !empty($res['success']), $res['error'] ?? null);
                $res['success'] ? $out['sent']++ : $out['failed']++;
            } elseif ($gate['action'] === 'wait') {
                $out['pending']++;
            } else {
                $out['skipped']++;
            }
        }

        return $out;
    }

    /**
     * إرسال رد فعلي لـ Google + تحديث حالة التقييم + خصم رصيد الردود.
     * مشتركة بين الأوتوماتيك والموافقة من مركز الأتمتة.
     */
    public static function sendReply(int $reviewId, int $userId, string $text, ?int $approvedBy = null, ?Database $db = null): array
    {
        $db = $db ?: Database::getInstance();
        $rows = $db->query('SELECT * FROM reviews WHERE id = ? AND user_id = ? LIMIT 1', [$reviewId, $userId]);
        if (empty($rows)) {
            return ['success' => false, 'error' => 'التقييم مش موجود'];
        }
        $review = $rows[0];
        if (!empty($review['reply_sent_at'])) {
            return ['success' => true, 'note' => 'already_replied'];
        }
        $externalId = (string) ($review['external_review_id'] ?? '');
        if ($externalId === '') {
            return ['success' => false, 'error' => 'التقييم مالوش رقم على Google'];
        }

        // رصيد الردود في الباقة (أو المحفظة)
        $validator = class_exists('SubscriptionValidator') ? new SubscriptionValidator() : null;
        $credits = $validator ? $validator->checkReviewCredits($userId, 1) : ['available' => true, 'source' => 'subscription'];
        if (empty($credits['available'])) {
            return ['success' => false, 'error' => 'رصيد الردود في الباقة خلص'];
        }

        if (self::$transport !== null) {
            $send = call_user_func(self::$transport, $review, $text);
        } else try {
            $connection = (new GbpSyncService())->findConnection((int) $review['website_id'], $userId);
            if (!$connection) {
                return ['success' => false, 'error' => 'Google Business مش مربوط'];
            }
            $accessToken = (new GoogleReviewSyncService())->getValidAccessToken($connection);
            $api = new GoogleBusinessAPI(
                $accessToken,
                $connection->getAttribute('external_account_id'),
                $connection->getAttribute('external_location_id')
            );
            $send = $api->sendReply($externalId, $text);
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'تعذر الاتصال بـ Google: ' . $e->getMessage()];
        }
        if (empty($send['success'])) {
            return ['success' => false, 'error' => $send['error'] ?? 'Google رفض الرد'];
        }

        $db->exec(
            "UPDATE reviews SET ai_generated_reply = ?, reply_sent = 1, reply_sent_at = NOW(),
                    reply_status = ?, reply_approved_by = ?, updated_at = NOW() WHERE id = ?",
            [$text, $approvedBy ? 'sent' : 'auto_sent', $approvedBy, $reviewId]
        );
        if ($validator) {
            try {
                $validator->consumeReviewCredits($userId, 1, ($credits['source'] ?? '') === 'wallet');
            } catch (Throwable $e) {
            }
        }
        if (class_exists('GbpAuditLogger')) {
            try {
                GbpAuditLogger::log('automation_reply', (int) $review['website_id'], $userId, 'success', ['review_id' => $reviewId, 'approved_by' => $approvedBy]);
            } catch (Throwable $e) {
            }
        }
        return ['success' => true];
    }

    private function makeDraft(array $review, int $userId): ?string
    {
        if ($this->draftFactory) {
            return call_user_func($this->draftFactory, $review, $userId);
        }
        try {
            $label = (string) ($review['sentiment'] ?? '');
            if ($label === '') {
                $label = AutomationCatalog::reviewBucket($review['rating'] ?? 0);
            }
            return (new ReplyGenerator())->generate(
                (string) ($review['review_text'] ?? ''),
                ['label' => $label, 'score' => (float) ($review['sentiment_score'] ?? 0.5), 'rating' => (float) ($review['rating'] ?? 0)],
                'google_business',
                $userId
            );
        } catch (Throwable $e) {
            return null;
        }
    }

    private function hasActiveRules(int $websiteId, int $userId): bool
    {
        try {
            $r = $this->db->query('SELECT COUNT(*) AS c FROM gbp_reply_rules WHERE website_id = ? AND user_id = ? AND enabled = 1', [$websiteId, $userId]);
            return (int) ($r[0]['c'] ?? 0) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function ruleMatches(array $review, int $websiteId, int $userId): bool
    {
        try {
            $svc = new GbpReplyRuleService();
            $list = $svc->listRules($websiteId, $userId);
            if (empty($list['rules'])) {
                return false;
            }
            return GbpReplyRuleService::pickRule($list['rules'], (float) ($review['rating'] ?? 0), (string) ($review['sentiment'] ?? 'neutral')) !== null;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function reviewTitle(array $review): string
    {
        $stars = (int) round((float) ($review['rating'] ?? 0));
        $name = trim((string) ($review['reviewer_name'] ?? ''));
        return str_repeat('★', max(0, min(5, $stars))) . ($name !== '' ? ' - ' . $name : '');
    }
}

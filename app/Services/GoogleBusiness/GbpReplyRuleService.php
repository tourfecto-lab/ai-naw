<?php

/**
 * Tourfecto - GBP Automated Reply Rules Service
 * قواعد الرد التلقائي على مراجعات Google Business (نفس فكرة BirdAI عند
 * Birdeye / Automation Rules عند Podium):
 *
 *  - القاعدة بتشتغل على شرط واحد: مدى تقييم (rating_min..rating_max) أو
 *    مشاعر (sentiment_label) - من البيانات الحقيقية المخزّنة عند المعالجة.
 *  - الإجراء: auto_reply (رد AI أو custom)، notify، أو الاتنين مع بعض.
 *  - القواعد بتتحرك في الأولوية (priority) - الأصغر رقمًا بيشتغل الأول.
 *  - مفيش رد مبعوت أبدًا من غير:
 *      1) قاعدة مفعّلة مطابقة،
 *      2) رد فعلي متولّد (AI أو custom)،
 *      3) وجود توكن صالح + connection شغالة (بنفس نمط باقي الموديول).
 *    لو أي خطوة فشلت، بنسجّل فشل صامت وبنكمّل من غير ما نوقع المزامنة.
 *
 * جدول جديد: gbp_reply_rules (2026_08_15_000055_create_gbp_reply_rules.sql)
 * @version 1.0.0
 * @since 2026-08-15 (Reputation Intelligence Tier 2)
 */
class GbpReplyRuleService
{
    /** @var Database */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ============================================================
    // CRUD
    // ============================================================

    /** القواعد المفعّلة لموقع (مرتبة بالأولوية) - للعرض والتقييم */
    public function listRules(int $websiteId, int $userId): array
    {
        try {
            $rows = $this->db->query(
                "SELECT * FROM gbp_reply_rules
                 WHERE website_id = ? AND user_id = ?
                 ORDER BY priority ASC, id ASC",
                [$websiteId, $userId]
            );
            return ['success' => true, 'rules' => $rows];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'تعذر جلب القواعد'];
        }
    }

    public function createRule(int $websiteId, int $userId, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return ['success' => false, 'error' => 'اسم القاعدة مطلوب'];
        }

        $triggerType = in_array($data['trigger_type'] ?? '', ['rating_range', 'sentiment'], true) ? $data['trigger_type'] : 'rating_range';
        $action = in_array($data['action'] ?? '', ['auto_reply', 'notify', 'auto_reply_and_notify'], true) ? $data['action'] : 'auto_reply';
        $replyMode = in_array($data['reply_mode'] ?? '', ['ai', 'custom'], true) ? $data['reply_mode'] : 'ai';

        $ratingMin = null;
        $ratingMax = null;
        if ($triggerType === 'rating_range') {
            $ratingMin = isset($data['rating_min']) && $data['rating_min'] !== '' ? (float) $data['rating_min'] : 0.0;
            $ratingMax = isset($data['rating_max']) && $data['rating_max'] !== '' ? (float) $data['rating_max'] : 5.0;
            if ($ratingMin < 1 || $ratingMax > 5 || $ratingMin > $ratingMax) {
                return ['success' => false, 'error' => 'مدى التقييم غير صالح (1-5)'];
            }
        }

        $sentimentLabel = null;
        if ($triggerType === 'sentiment') {
            $sentimentLabel = in_array($data['sentiment_label'] ?? '', ['positive', 'neutral', 'negative', 'mixed'], true) ? $data['sentiment_label'] : 'positive';
        }

        $customReply = $replyMode === 'custom' ? trim((string) ($data['custom_reply'] ?? '')) : null;
        if ($replyMode === 'custom' && $customReply === '') {
            return ['success' => false, 'error' => 'نص الرد المخصص مطلوب لـ custom reply'];
        }

        $priority = max(0, (int) ($data['priority'] ?? 100));

        try {
            // Database::query() بيرجّع lastInsertId مع INSERT (مفيش دالة lastInsertId()
            // عليه - كانت بترمي خطأ بعد ما القاعدة اتحفظت، فالعميل يشوف "تعذر" ويكررها)
            $id = $this->db->query(
                "INSERT INTO gbp_reply_rules
                    (website_id, user_id, name, trigger_type, rating_min, rating_max,
                     sentiment_label, action, reply_mode, custom_reply, priority, enabled)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $websiteId, $userId, $name, $triggerType,
                    $ratingMin, $ratingMax, $sentimentLabel,
                    $action, $replyMode, $customReply, $priority, 1,
                ]
            );
            return ['success' => true, 'id' => (int) $id];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'تعذر إنشاء القاعدة'];
        }
    }

    public function updateRule(int $ruleId, int $userId, array $data): array
    {
        $existing = $this->findRule($ruleId, $userId);
        if (!$existing) {
            return ['success' => false, 'error' => 'القاعدة غير موجودة'];
        }

        $name = isset($data['name']) ? trim((string) $data['name']) : $existing['name'];
        if ($name === '') {
            return ['success' => false, 'error' => 'اسم القاعدة مطلوب'];
        }

        $triggerType = isset($data['trigger_type']) && in_array($data['trigger_type'], ['rating_range', 'sentiment'], true) ? $data['trigger_type'] : $existing['trigger_type'];
        $action = isset($data['action']) && in_array($data['action'], ['auto_reply', 'notify', 'auto_reply_and_notify'], true) ? $data['action'] : $existing['action'];
        $replyMode = isset($data['reply_mode']) && in_array($data['reply_mode'], ['ai', 'custom'], true) ? $data['reply_mode'] : $existing['reply_mode'];
        $enabled = isset($data['enabled']) ? ((int) $data['enabled'] === 1 ? 1 : 0) : (int) $existing['enabled'];

        $ratingMin = $existing['rating_min'];
        $ratingMax = $existing['rating_max'];
        if ($triggerType === 'rating_range') {
            $ratingMin = isset($data['rating_min']) && $data['rating_min'] !== '' ? (float) $data['rating_min'] : (float) $ratingMin;
            $ratingMax = isset($data['rating_max']) && $data['rating_max'] !== '' ? (float) $data['rating_max'] : (float) $ratingMax;
            if ($ratingMin < 1 || $ratingMax > 5 || $ratingMin > $ratingMax) {
                return ['success' => false, 'error' => 'مدى التقييم غير صالح (1-5)'];
            }
        } else {
            $ratingMin = null;
            $ratingMax = null;
        }

        $sentimentLabel = $existing['sentiment_label'];
        if ($triggerType === 'sentiment') {
            $sentimentLabel = isset($data['sentiment_label']) && in_array($data['sentiment_label'], ['positive', 'neutral', 'negative', 'mixed'], true) ? $data['sentiment_label'] : 'positive';
        } else {
            $sentimentLabel = null;
        }

        $customReply = $replyMode === 'custom' ? trim((string) ($data['custom_reply'] ?? $existing['custom_reply'])) : null;
        if ($replyMode === 'custom' && $customReply === '') {
            return ['success' => false, 'error' => 'نص الرد المخصص مطلوب لـ custom reply'];
        }

        $priority = isset($data['priority']) ? max(0, (int) $data['priority']) : (int) $existing['priority'];

        try {
            $this->db->query(
                "UPDATE gbp_reply_rules SET
                    name = ?, trigger_type = ?, rating_min = ?, rating_max = ?,
                    sentiment_label = ?, action = ?, reply_mode = ?, custom_reply = ?,
                    priority = ?, enabled = ?, updated_at = NOW()
                 WHERE id = ? AND user_id = ?",
                [
                    $name, $triggerType, $ratingMin, $ratingMax,
                    $sentimentLabel, $action, $replyMode, $customReply,
                    $priority, $enabled, $ruleId, $userId,
                ]
            );
            return ['success' => true];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'تعذر تحديث القاعدة'];
        }
    }

    public function deleteRule(int $ruleId, int $userId): array
    {
        try {
            $this->db->query("DELETE FROM gbp_reply_rules WHERE id = ? AND user_id = ?", [$ruleId, $userId]);
            return ['success' => true];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'تعذر حذف القاعدة'];
        }
    }

    private function findRule(int $ruleId, int $userId): ?array
    {
        try {
            $rows = $this->db->query(
                "SELECT * FROM gbp_reply_rules WHERE id = ? AND user_id = ? LIMIT 1",
                [$ruleId, $userId]
            );
            return $rows[0] ?? null;
        } catch (Throwable $e) {
            return null;
        }
    }

    // ============================================================
    // Matching (Pure Function قابلة للاختبار)
    // ============================================================

    /**
     * هل قاعدة معينة بتطابق مراجعة (تقييم + مشاعر)؟
     * @param array $rule  صف gbp_reply_rules
     * @param float $rating التقييم الحقيقي (0 لو غير معروف)
     * @param string $sentiment قيمة sentiment المخزّنة (positive/neutral/negative/mixed)
     */
    public static function ruleMatches(array $rule, float $rating, string $sentiment): bool
    {
        if ((int) ($rule['enabled'] ?? 1) !== 1) {
            return false;
        }

        $trigger = $rule['trigger_type'] ?? 'rating_range';
        if ($trigger === 'sentiment') {
            $expected = $rule['sentiment_label'] ?? '';
            return $expected !== '' && $sentiment === $expected;
        }

        if ($rating <= 0) {
            return false;
        } // تقييم غير معروف = مش مطابق (أرقام حقيقية بس)
        $min = $rule['rating_min'] !== null && $rule['rating_min'] !== '' ? (float) $rule['rating_min'] : 0.0;
        $max = $rule['rating_max'] !== null && $rule['rating_max'] !== '' ? (float) $rule['rating_max'] : 5.0;
        return $rating >= $min && $rating <= $max;
    }

    /**
     * اختيار أول قاعدة مطابقة للمراجعة حسب الأولوية.
     * @param array $rules
     * @param float $rating
     * @param string $sentiment
     * @return array|null
     */
    public static function pickRule(array $rules, float $rating, string $sentiment): ?array
    {
        // دفاعي: نرتّب بالأولوية (الأصغر أولًا) حتى لو الـ rules جوّت الترتيب،
        // عشان الاختيار يبقى مستقرًا على نفس المعيار مهما كان المصدر.
        usort($rules, function ($a, $b) {
            $pa = (int) ($a['priority'] ?? 100);
            $pb = (int) ($b['priority'] ?? 100);
            if ($pa === $pb) {
                return (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
            }
            return $pa <=> $pb;
        });

        foreach ($rules as $rule) {
            if (self::ruleMatches($rule, $rating, $sentiment)) {
                return $rule;
            }
        }
        return null;
    }

    // ============================================================
    // Execution
    // ============================================================

    /**
     * تنفيذ القاعدة على مراجعة حقيقية:
     *  - auto_reply: توليد رد (AI أو custom) + إرساله عبر Google + تحديث
     *    حالة الرد في قاعدة البيانات.
     *  - notify: إشعار للمستخدم.
     * المراجعة اللي اتوردت فعلاً لازم يكون ليها id صالح في جدول reviews.
     */
    public function applyRulesToReview(int $reviewId): array
    {
        $review = $this->fetchReview($reviewId);
        if (!$review) {
            return ['success' => false, 'error' => 'review_not_found'];
        }

        $websiteId = (int) $review['website_id'];
        $userId = (int) $review['user_id'];

        // ممنوع إعادة الرد على مراجعة اتوردت بالفعل
        if (!empty($review['reply_sent_at'])) {
            return ['success' => false, 'error' => 'already_replied', 'review_id' => $reviewId];
        }

        $list = $this->listRules($websiteId, $userId);
        if (!$list['success'] || empty($list['rules'])) {
            return ['success' => false, 'error' => 'no_rules', 'review_id' => $reviewId];
        }

        $rating = (float) ($review['rating'] ?? 0);
        $sentiment = (string) ($review['sentiment'] ?? 'neutral');
        $rule = self::pickRule($list['rules'], $rating, $sentiment);
        if (!$rule) {
            return ['success' => false, 'error' => 'no_matching_rule', 'review_id' => $reviewId];
        }

        $action = $rule['action'] ?? 'auto_reply';
        $result = [
            'success' => true,
            'rule_id' => (int) $rule['id'],
            'rule_name' => $rule['name'],
            'action' => $action,
            'review_id' => $reviewId,
        ];

        if ($action === 'auto_reply' || $action === 'auto_reply_and_notify') {
            $replyResult = $this->executeAutoReply($review, $rule, $websiteId, $userId);
            $result['reply'] = $replyResult;
            $result['reply_sent'] = $replyResult['sent'] ?? false;
        }

        if ($action === 'notify' || $action === 'auto_reply_and_notify') {
            $this->sendNotification($userId, $websiteId, $review, $rule);
            $result['notified'] = true;
        }

        if (class_exists('GbpAuditLogger')) {
            GbpAuditLogger::log(
                'auto_reply_rule',
                $websiteId,
                $userId,
                $result['reply_sent'] ? 'success' : 'failed',
                [
                    'rule_id' => (int) $rule['id'],
                    'action' => $action,
                    'review_id' => $reviewId,
                ]
            );
        }

        return $result;
    }

    private function executeAutoReply(array $review, array $rule, int $websiteId, int $userId): array
    {
        // 1) توليد نص الرد (custom أو AI - ولو في رد AI متولّد بالفعل في
        //    عملية processWebhook ومحفوظ pending، نعيد استخدامه بدل ما
        //    ندفع تكلفة توليد تاني)
        if (($rule['reply_mode'] ?? 'ai') === 'custom' && !empty($rule['custom_reply'])) {
            $reply = $rule['custom_reply'];
            $aiGenerated = false;
        } else {
            $existing = (string) ($review['ai_generated_reply'] ?? '');
            if ($existing !== '') {
                $reply = $existing;
                $aiGenerated = true;
            } else {
                $generated = $this->generateAiReply($review, $userId);
                if ($generated === null || $generated === '') {
                    return ['sent' => false, 'error' => 'ai_reply_generation_failed'];
                }
                $reply = $generated;
                $aiGenerated = true;
            }
        }

        // 2) جلب اتصال + توكن صالح (نفس نمط باقي الموديول)
        try {
            // findConnection() موجودة في GbpSyncService مش GoogleReviewSyncService
            $sync = new GoogleReviewSyncService();
            $connection = (new GbpSyncService())->findConnection($websiteId, $userId);
            if (!$connection) {
                return ['sent' => false, 'error' => 'not_connected'];
            }
            $accessToken = $sync->getValidAccessToken($connection);
        } catch (Throwable $e) {
            return ['sent' => false, 'error' => 'token_error'];
        }

        $api = new GoogleBusinessAPI(
            $accessToken,
            $connection->getAttribute('external_account_id'),
            $connection->getAttribute('external_location_id')
        );

        $reviewId = (string) ($review['external_review_id'] ?? '');
        if ($reviewId === '') {
            return ['sent' => false, 'error' => 'missing_external_review_id'];
        }

        $sendResult = $api->sendReply($reviewId, $reply);
        if (!($sendResult['success'] ?? false)) {
            return ['sent' => false, 'error' => $sendResult['error'] ?? 'send_failed'];
        }

        // 3) تحديث حالة الرد في قاعدة البيانات (نفس الأعمدة الفعلية)
        $this->markReplied((int) $review['id'], $reply, $aiGenerated);

        return ['sent' => true, 'reply' => $reply, 'ai_generated' => $aiGenerated];
    }

    private function generateAiReply(array $review, int $userId): ?string
    {
        try {
            $generator = new ReplyGenerator();
            return $generator->generate(
                (string) ($review['review_text'] ?? ''),
                ['label' => (string) ($review['sentiment'] ?? 'neutral'), 'score' => (float) ($review['sentiment_score'] ?? 0.5), 'rating' => (float) ($review['rating'] ?? 0)],
                'google_business',
                $userId,
                $review
            );
        } catch (Throwable $e) {
            return null;
        }
    }

    private function markReplied(int $reviewId, string $reply, bool $aiGenerated): void
    {
        try {
            $this->db->query(
                "UPDATE reviews SET
                    ai_generated_reply = ?, is_ai_generated = ?, reply_sent_at = NOW(),
                    reply_status = 'sent', updated_at = NOW()
                 WHERE id = ?",
                [$reply, $aiGenerated ? 1 : 0, $reviewId]
            );
        } catch (Throwable $e) {
            // فشل صامت - الرد اتسند فعلًا على Google، بس تحديث الحالة المحلي اتحشر
        }
    }

    private function sendNotification(int $userId, int $websiteId, array $review, array $rule): void
    {
        if (!class_exists('Notification')) {
            return;
        }
        $reviewer = $review['reviewer_name'] ?? 'عميل';
        $stars = (int) ($review['rating'] ?? 0);
        $ruleName = $rule['name'] ?? '';
        $title = 'قاعدة الرد التلقائي شغالة: ' . $ruleName;
        $body = "مراجعة جديدة ({$stars} نجوم) من {$reviewer} تطابق قاعدة «{$ruleName}» على موقعك.";
        Notification::notify($userId, 'gbp_auto_reply_rule', $title, $body, '/reputation/reviews');
    }

    private function fetchReview(int $reviewId): ?array
    {
        try {
            $rows = $this->db->query(
                "SELECT * FROM reviews WHERE id = ? LIMIT 1",
                [$reviewId]
            );
            return $rows[0] ?? null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

<?php

/**
 * تنفيذ العقل جوه الخدمات نفسها (B2+).
 *
 * القرار بيتحول لشغل حقيقي في الخدمة اللي بتنفّذه، بس بخطوتين: معاينة (هيحصل إيه وبيكلّف قد إيه)،
 * وبعدين تنفيذ بموافقة صريحة من صاحب الشركة. مفيش حاجة بتتنشر أو تتبعت لعميل من هنا:
 * النتيجة بتتحط في طابور الموافقة الموجود في الخدمة، وصاحب الشركة هو اللي بينشر.
 *
 * أول تنفيذ: ردود التقييمات السلبية. بيكتب مسودات بنفس كاتب الردود (ReplyGenerator) اللي بتستخدمه
 * صفحة التقييمات والأتمتة، وبيحفظها كـ reply_status = 'pending' زي الأتمتة بالظبط.
 */
class BrainActions
{
    /** أقصى عدد مسودات في المرة الواحدة (كل مسودة = طلب أو اتنين ذكاء اصطناعي) */
    public const MAX_DRAFTS = 10;
    public const AI_CALLS_PER_DRAFT = 2;
    /** كل طلب بيكتب دفعة صغيرة بس، عشان الطلب مايعدّيش مهلة السيرفر؛ الصفحة بتكمّل الدفعات لحد MAX_DRAFTS */
    public const BATCH = 3;
    private const DAYS = 30;

    /** القرار ← التنفيذ اللي جوه الخدمة */
    private const ACTIONS = ['reply_negative_reviews' => 'review_drafts'];

    private Database $db;
    /** @var callable|null للاختبارات: fn(array $review, int $uid): ?string */
    private $drafter;

    public function __construct(?Database $db = null, ?callable $drafter = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->drafter = $drafter;
    }

    public static function forDecision(string $key): ?string
    {
        return self::ACTIONS[$key] ?? null;
    }

    /** هيحصل إيه لو صاحب الشركة وافق: عدد اللي هيتكتب، واللي جاهز أصلًا، وأقصى استهلاك ذكاء اصطناعي */
    public function preview(int $uid, string $action): array
    {
        if ($action !== 'review_drafts') throw new InvalidArgumentException('Unknown action');
        $ready = (int) ($this->db->query("SELECT COUNT(*) AS c FROM reviews WHERE user_id = ? AND rating <= 3 AND reply_sent_at IS NULL
            AND created_at > DATE_SUB(NOW(), INTERVAL " . self::DAYS . " DAY) AND reply_status = 'pending' AND ai_generated_reply IS NOT NULL AND ai_generated_reply <> ''", [$uid])[0]['c'] ?? 0);
        $missing = (int) ($this->db->query("SELECT COUNT(*) AS c FROM reviews WHERE user_id = ? AND rating <= 3 AND reply_sent_at IS NULL
            AND created_at > DATE_SUB(NOW(), INTERVAL " . self::DAYS . " DAY) AND (ai_generated_reply IS NULL OR ai_generated_reply = '')", [$uid])[0]['c'] ?? 0);
        $will = min(self::MAX_DRAFTS, $missing);
        return ['action' => $action, 'without_draft' => $missing, 'ready_drafts' => $ready, 'will_draft' => $will,
            'ai_calls_max' => $will * self::AI_CALLS_PER_DRAFT, 'publishes' => false, 'review_url' => '/reputation/reviews'];
    }

    /** يكتب دفعة مسودات ويحطها في طابور الموافقة. مابينشرش حاجة. */
    public function run(int $uid, string $action, int $limit = self::BATCH): array
    {
        $limit = max(1, min(self::MAX_DRAFTS, $limit));
        if ($action !== 'review_drafts') throw new InvalidArgumentException('Unknown action');
        $lock = 'tourfecto:brain-action:' . $uid;
        if ((int) ($this->db->query('SELECT GET_LOCK(?, 0) AS l', [$lock])[0]['l'] ?? 0) !== 1) throw new RuntimeException('busy');
        try {
            $rows = $this->db->query("SELECT * FROM reviews WHERE user_id = ? AND rating <= 3 AND reply_sent_at IS NULL
                AND created_at > DATE_SUB(NOW(), INTERVAL " . self::DAYS . " DAY) AND (ai_generated_reply IS NULL OR ai_generated_reply = '')
                ORDER BY rating ASC, created_at DESC LIMIT " . $limit, [$uid]) ?: [];
            $drafted = 0;
            $skipped = 0;
            foreach ($rows as $r) {
                $text = $this->draft($r, $uid);
                if ($text === null || trim($text) === '') { $skipped++; continue; }
                $this->db->query("UPDATE reviews SET ai_generated_reply = ?, is_ai_generated = 1, reply_status = 'pending'
                    WHERE id = ? AND user_id = ? AND reply_sent_at IS NULL", [$text, (int) $r['id'], $uid]);
                $drafted++;
            }
            return ['drafted' => $drafted, 'skipped' => $skipped] + $this->preview($uid, $action);
        } finally {
            $this->db->query('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    private function draft(array $r, int $uid): ?string
    {
        if ($this->drafter) return call_user_func($this->drafter, $r, $uid);
        $rating = (float) ($r['rating'] ?? 0);
        $label = (string) ($r['sentiment'] ?? '') ?: ($rating <= 2 ? 'negative' : 'neutral');
        $gen = new ReplyGenerator();
        $text = $gen->generate((string) ($r['review_text'] ?? ''), ['label' => $label, 'rating' => $rating, 'score' => (float) ($r['sentiment_score'] ?? 0.3)],
            (string) ($r['source_platform'] ?? 'google_business'), $uid, $r);
        // رد جاهز من قالب (الذكاء الاصطناعي مش متاح أو الرصيد خلص) مابيتحطش كمسودة باسم الشركة، زي الأتمتة
        return $gen->lastWasFallback ? null : $text;
    }
}

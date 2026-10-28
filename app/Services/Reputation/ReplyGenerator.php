<?php

/**
 * Tourfecto - Reply Generator
 * توليد ردود ذكية على المراجعات
 * @version 2.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 *
 * 2.0: الرد بيتكتب بقواعد خبير سمعة (ReviewReplyExpert): لغة صاحب التقييم، اسمه، عدد النجوم،
 * اسم الشركة وتوقيعها، وتفاصيل من كلامه. وبعد الكتابة مراجعة آلية (طول، كليشيهات، لغة، قواعد المنصة،
 * تكرار بدايات الردود اللي فاتت)، ولو فيه مشكلة بيتعاد مرة واحدة ونختار الأحسن.
 */

class ReplyGenerator
{
    /**
     * @var TourfectoAIEngine $aiEngine - محرك الذكاء الاصطناعي
     */
    private $aiEngine;

    /** آخر generate() رجّع رد جاهز مش مكتوب بالذكاء الاصطناعي */
    public bool $lastWasFallback = false;

    /** مشاكل آخر رد بعد المراجعة الآلية (فاضية = سليم) */
    public array $lastIssues = [];

    /** للاختبار: بدل محرك الذكاء الاصطناعي وقاعدة البيانات */
    public $writer = null;
    public $contextLoader = null;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->aiEngine = class_exists('TourfectoAIEngine') ? new TourfectoAIEngine() : null;
    }

    /**
     * توليد رد على مراجعة
     * @param string $reviewText - نص المراجعة
     * @param array $sentiment - تحليل المشاعر (ممكن فيه rating)
     * @param string $platform - المنصة
     * @param int $userId - معرف المستخدم
     * @param array $review - صف التقييم لو موجود (rating, reviewer_name, website_id, review_language, id)
     * @return string|null
     */
    public function generate(
        string $reviewText,
        array $sentiment,
        string $platform,
        int $userId,
        array $review = []
    ): ?string {
        $this->lastWasFallback = false;
        $this->lastIssues = [];
        try {
            $r = [
                'review_text' => $reviewText,
                'rating' => (float) ($review['rating'] ?? $sentiment['rating'] ?? 0),
                'reviewer_name' => (string) ($review['reviewer_name'] ?? ''),
                'review_language' => (string) ($review['review_language'] ?? ''),
                'platform' => $platform !== '' ? $platform : (string) ($review['source_platform'] ?? ''),
                'sentiment' => (string) ($sentiment['label'] ?? ''),
            ];
            [$business, $recent, $style] = $this->context($userId, (int) ($review['website_id'] ?? 0), (int) ($review['id'] ?? 0), $r);

            $best = null;
            $bestIssues = null;
            $issues = [];
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $prompt = ReviewReplyExpert::prompt($r, $business, $recent, $style, $issues);
                $raw = $this->write($prompt, $sentiment, $reviewText, $userId);
                if ($raw === null || $raw === '') {
                    break;
                }
                if ($this->lastWasFallback) {
                    return $raw; // الذكاء الاصطناعي مش متاح: الرد الجاهز زي ما هو
                }
                $reply = ReviewReplyExpert::clean($raw);
                $issues = ReviewReplyExpert::check($reply, $r, $recent);
                if ($bestIssues === null || count($issues) < count($bestIssues)) {
                    $best = $reply;
                    $bestIssues = $issues;
                }
                if (!$issues) {
                    break;
                }
            }
            if ($best !== null) {
                $this->lastIssues = $bestIssues ?? [];
                if ($this->lastIssues && class_exists('Logger')) {
                    Logger::info('Review reply kept with minor issues', ['issues' => $this->lastIssues]);
                }
                return $best;
            }

            // استخدام القوالب كـ Fallback
            return $this->generateFromTemplate($reviewText, $sentiment, $platform);

        } catch (Exception $e) {
            Logger::error('Generate Reply Error', [
                'error' => $e->getMessage()
            ]);
            return $this->generateFromTemplate($reviewText, $sentiment, $platform);
        }
    }

    private function write(string $prompt, array $sentiment, string $reviewText, int $userId): ?string
    {
        if ($this->writer) {
            return call_user_func($this->writer, $prompt);
        }
        if (!$this->aiEngine) {
            return null;
        }
        $out = $this->aiEngine->generateReviewReplyFromPrompt($prompt, $sentiment, $reviewText, $userId);
        if (!empty($this->aiEngine->lastReviewReplyWasFallback)) {
            $this->lastWasFallback = true;
        }
        return $out;
    }

    /**
     * اللي الخبير بيعرفه عن الشركة: اسمها ومدينتها وإيميل التواصل واسم اللي بيوقّع،
     * وآخر ردود اتكتبت (عشان مانكررش البدايات)، وأسلوب صاحب النشاط لو المنصة اتعلمته.
     * @return array{0:array,1:array,2:string}
     */
    private function context(int $userId, int $websiteId, int $reviewId, array $r): array
    {
        if ($this->contextLoader) {
            return call_user_func($this->contextLoader, $userId, $websiteId, $r);
        }
        $business = ['name' => '', 'signer' => '', 'city' => '', 'email' => ''];
        $recent = [];
        $style = '';
        try {
            $db = Database::getInstance();
            $u = $db->query('SELECT company_name, first_name, display_name FROM users WHERE id = ? LIMIT 1', [$userId])[0] ?? [];
            $biz = [];
            try {
                $biz = $db->query('SELECT trade_name, legal_name, city, business_email FROM businesses WHERE owner_user_id = ? ORDER BY id LIMIT 1', [$userId])[0] ?? [];
            } catch (Throwable $e) {
            }
            $site = $websiteId > 0 ? ($db->query('SELECT company_name FROM websites WHERE id = ? AND user_id = ? LIMIT 1', [$websiteId, $userId])[0] ?? []) : [];
            $business['name'] = trim((string) ($biz['trade_name'] ?? '')) ?: trim((string) ($site['company_name'] ?? '')) ?: trim((string) ($biz['legal_name'] ?? '')) ?: trim((string) ($u['company_name'] ?? ''));
            $business['city'] = trim((string) ($biz['city'] ?? ''));
            $business['email'] = trim((string) ($biz['business_email'] ?? ''));
            $signer = trim((string) ($u['first_name'] ?? '')) ?: trim((string) ($u['display_name'] ?? ''));
            // اسم المستخدم لو هو نفس اسم الشركة أو إيميل مايبقاش توقيع
            $business['signer'] = $signer !== '' && strcasecmp($signer, $business['name']) !== 0 && !str_contains($signer, '@') ? explode(' ', $signer)[0] : '';
            $rows = $db->query("SELECT ai_generated_reply FROM reviews WHERE user_id = ? AND ai_generated_reply IS NOT NULL AND ai_generated_reply <> '' AND id <> ? ORDER BY id DESC LIMIT 8", [$userId, $reviewId]);
            $recent = array_values(array_filter(array_map(static fn ($x) => (string) ($x['ai_generated_reply'] ?? ''), $rows ?: [])));
        } catch (Throwable $e) {
        }
        if (class_exists('AutomationEngine') && class_exists('AutomationCatalog')) {
            try {
                $bucket = $r['rating'] > 0 ? AutomationCatalog::reviewBucket($r['rating']) : (($r['sentiment'] ?? '') === 'positive' ? 'positive' : (($r['sentiment'] ?? '') === 'negative' ? 'negative' : 'neutral'));
                $style = AutomationEngine::instance()->styleBlock($userId, 'review_replies', $bucket);
            } catch (Throwable $e) {
            }
        }
        return [$business, $recent, $style];
    }

    /**
     * توليد رد من قالب
     * @param string $reviewText
     * @param array $sentiment
     * @param string $platform
     * @return string
     */
    private function generateFromTemplate(string $reviewText, array $sentiment, string $platform): string
    {
        $this->lastWasFallback = true;
        return ReviewReplyFallback::forReview($reviewText, (string) ($sentiment['label'] ?? 'neutral'));
    }
}

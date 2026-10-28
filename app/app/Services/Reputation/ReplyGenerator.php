<?php

/**
 * Tourfecto - Reply Generator
 * توليد ردود ذكية على المراجعات
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class ReplyGenerator
{
    /**
     * @var TourfectoAIEngine $aiEngine - محرك الذكاء الاصطناعي
     */
    private $aiEngine;

    /** آخر generate() رجّع رد جاهز مش مكتوب بالذكاء الاصطناعي */
    public bool $lastWasFallback = false;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->aiEngine = new TourfectoAIEngine();
    }

    /**
     * توليد رد على مراجعة
     * @param string $reviewText - نص المراجعة
     * @param array $sentiment - تحليل المشاعر
     * @param string $platform - المنصة
     * @param int $userId - معرف المستخدم
     * @return string|null
     */
    public function generate(
        string $reviewText,
        array $sentiment,
        string $platform,
        int $userId
    ): ?string {
        $this->lastWasFallback = false;
        try {
            // استخدام AI لتوليد رد
            $aiReply = $this->aiEngine->generateReviewReply(
                $reviewText,
                $sentiment,
                $platform,
                $userId
            );

            if ($aiReply && !empty($this->aiEngine->lastReviewReplyWasFallback)) {
                $this->lastWasFallback = true;
                return $aiReply;
            }

            if ($aiReply) {
                // لو المنصة اتعلمت أسلوب العميل، مانزودش جمل ختام جاهزة على ردّه
                $hasStyle = class_exists('AutomationEngine')
                    && AutomationEngine::instance()->styleExamples($userId, 'review_replies', null, 1) !== [];
                return $hasStyle ? trim(preg_replace('/\s+/u', ' ', $aiReply)) : $this->postProcessReply($aiReply, $sentiment);
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

    /**
     * معالجة الرد بعد التوليد
     * @param string $reply
     * @param array $sentiment
     * @return string
     */
    private function postProcessReply(string $reply, array $sentiment): string
    {
        // كان بيزود جملة ختام عربية عشوائية حتى على الردود الإنجليزي -
        // دلوقتي بس بنظبط المسافات وعلامة النهاية.
        $reply = trim(preg_replace('/\s+/u', ' ', $reply));

        if ($reply !== '' && !preg_match('/[.!?…؟!]$/u', $reply)) {
            $reply .= '.';
        }

        return $reply;
    }
}

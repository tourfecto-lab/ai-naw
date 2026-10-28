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

    /**
     * @var array $templates - قوالب الردود
     */
    private $templates = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->aiEngine = new TourfectoAIEngine();
        $this->loadTemplates();
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
        try {
            // استخدام AI لتوليد رد
            $aiReply = $this->aiEngine->generateReviewReply(
                $reviewText,
                $sentiment,
                $platform,
                $userId
            );

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
        $template = $this->selectTemplate($sentiment['label'], $platform);

        $placeholders = [
            '{reviewer_name}' => '',
            '{platform}' => $platform,
            '{sentiment}' => $sentiment['label'],
            '{rating}' => '5'
        ];

        $reply = str_replace(
            array_keys($placeholders),
            array_values($placeholders),
            $template
        );

        return $reply;
    }

    /**
     * معالجة الرد بعد التوليد
     * @param string $reply
     * @param array $sentiment
     * @return string
     */
    private function postProcessReply(string $reply, array $sentiment): string
    {
        $reply = preg_replace('/\s+/', ' ', $reply);

        if (!preg_match('/[.!?…]$/', $reply)) {
            $reply .= '.';
        }

        $keywords = $this->getKeywords($sentiment['label']);
        if (!empty($keywords)) {
            $reply .= ' ' . $keywords[array_rand($keywords)];
        }

        return $reply;
    }

    /**
     * الحصول على كلمات مفتاحية حسب المشاعر
     * @param string $sentiment
     * @return array
     */
    private function getKeywords(string $sentiment): array
    {
        $keywords = [
            'positive' => [
                'نتمنى رؤيتك مرة أخرى',
                'شكراً على ثقتكم بنا',
                'سعداء بخدمتكم',
                'نتطلع لخدمتكم مجدداً'
            ],
            'negative' => [
                'نأسف لأي إزعاج',
                'نعمل على تحسين خدماتنا',
                'نتعهد بتقديم الأفضل',
                'نسعى دائماً للتميز'
            ],
            'neutral' => [
                'نقدر تقييمكم',
                'شكراً لتواصلكم',
                'نحن بخدمتكم دائماً',
                'نتطلع لخدمتكم'
            ]
        ];

        return $keywords[$sentiment] ?? $keywords['neutral'];
    }

    /**
     * اختيار قالب مناسب
     * @param string $sentiment
     * @param string $platform
     * @return string
     */
    private function selectTemplate(string $sentiment, string $platform): string
    {
        $templates = $this->templates[$platform] ?? $this->templates['default'];
        return $templates[$sentiment] ?? $templates['neutral'] ?? $templates['default'];
    }

    /**
     * تحميل القوالب
     */
    private function loadTemplates(): void
    {
        $this->templates = [
            'default' => [
                'positive' => "شكراً جزيلاً لك على تقييمك الإيجابي. يسعدنا جداً أنك استمتعت بتجربتك معنا. نتطلع دائماً لتقديم الأفضل لعملائنا الكرام. نتمنى رؤيتك مرة أخرى قريباً!",
                'neutral' => "شكراً لتقييمك لخدماتنا. نضع آراء عملائنا في أولوياتنا ونسعى دائماً لتحسين تجربتكم. نتمنى لكم يوماً سعيداً.",
                'negative' => "نشكرك على مشاركة تجربتك معنا. نأسف جداً لأي إزعاج حدث لك. نعتز بآراء عملائنا ونعمل باستمرار على تحسين خدماتنا. يرجى التواصل معنا على رقم الدعم لإعطائنا فرصة لتعويضك عن هذه التجربة."
            ],
            'tripadvisor' => [
                'positive' => "شكراً لتقييمك الإيجابي على TripAdvisor! سعداء جداً أنك استمتعت بتجربتك. نأمل أن نراك مرة أخرى قريباً!",
                'neutral' => "نشكرك على تقييمك على TripAdvisor. نأخذ آراء عملائنا بجدية ونعمل دائماً على تحسين خدماتنا.",
                'negative' => "نأسف جداً لتجربتك غير المرضية على TripAdvisor. نود أن نعرف المزيد عن تجربتك لتحسين خدماتنا. يرجى التواصل مع فريق الدعم لدينا."
            ],
            'google_business' => [
                'positive' => "شكراً لتقييمك الإيجابي على Google Business! سعداء بثقتك بنا ونتطلع لخدمتك مجدداً.",
                'neutral' => "نشكرك على تقييمك على Google Business. آراؤكم مهمة لنا وتساعدنا في التحسين المستمر.",
                'negative' => "نأسف جداً لتجربتك غير المرضية على Google Business. نريد أن نعرف المزيد عن تجربتك لتحسين خدماتنا."
            ]
        ];
    }
}

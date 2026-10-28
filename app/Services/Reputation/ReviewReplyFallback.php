<?php

/**
 * Tourfecto - Review Reply Fallback
 *
 * لغة التقييم + ردود جاهزة بلغة صاحب التقييم لما الذكاء الاصطناعي
 * ميكونش متاح. السياح بيكتبوا غالبًا بالإنجليزي، فالرد العربي على تقييم
 * إنجليزي شكله مش احترافي.
 *
 * الردود دي للمسودات اليدوية بس: الرد التلقائي (ReviewAutoReplyRunner)
 * مش بينشر رد جاهز، بيستنى لحد ما الذكاء الاصطناعي يكتب رد حقيقي.
 *
 * منطق خالص بدون DB.
 */
class ReviewReplyFallback
{
    private const TEXTS = [
        'ar' => [
            'positive' => 'شكراً جزيلاً على تقييمك الجميل! سعداء جداً إن تجربتك معنا كانت مميزة، ونتمنى نشوفك تاني قريب.',
            'neutral' => 'شكراً على تقييمك ووقتك. ملاحظاتك بتساعدنا نحسّن تجربتنا باستمرار، ونتمنى نقدملك تجربة أفضل في زيارتك الجاية.',
            'negative' => 'شكراً إنك شاركتنا تجربتك، ونعتذر إنها ماكانتش على المستوى المتوقع. يهمنا جداً نعرف التفاصيل ونصلّح الموقف، من فضلك تواصل معانا مباشرة.',
        ],
        'en' => [
            'positive' => 'Thank you so much for your wonderful review! We are delighted you enjoyed your experience with us and hope to welcome you again soon.',
            'neutral' => 'Thank you for taking the time to share your feedback. It helps us keep improving, and we hope to offer you an even better experience next time.',
            'negative' => 'Thank you for sharing your experience, and we are sorry it did not meet your expectations. We would like to learn more and make this right, so please contact us directly.',
        ],
    ];

    /** لغة الرد: عربي لو التقييم فيه حروف عربية، غير كده إنجليزي */
    public static function language(string $reviewText): string
    {
        return preg_match('/\p{Arabic}/u', $reviewText) ? 'ar' : 'en';
    }

    public static function text(string $label, string $language): string
    {
        $texts = self::TEXTS[$language] ?? self::TEXTS['en'];
        return $texts[$label] ?? $texts['neutral'];
    }

    public static function forReview(string $reviewText, string $label): string
    {
        return self::text($label, self::language($reviewText));
    }
}

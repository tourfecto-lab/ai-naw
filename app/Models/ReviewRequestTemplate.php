<?php

/**
 * Tourfecto - Review Request Template Model
 * قوالب رسائل جاهزة قابلة للتخصيص لكل موقع (Friendly/Professional/
 * Short/Thank You/Custom).
 * @version 1.0.0
 */
class ReviewRequestTemplate extends Model
{
    protected $table = 'review_request_templates';

    protected $fillable = [
        'website_id', 'name', 'preset_type', 'message_template', 'email_subject', 'is_default', 'created_at',
    ];

    /**
     * القوالب الافتراضية الجاهزة (نص ثابت في الكود، مش بيانات مستخدم) -
     * بتتزرع تلقائيًا لأي موقع أول مرة يفتح فيها صفحة القوالب من غير
     * ما يكون عنده قوالب أصلاً.
     */
    public static function defaultPresets(): array
    {
        return [
            [
                'name' => 'ودود', 'preset_type' => 'friendly',
                'message_template' => 'أهلاً {name}! 🌟 شكرًا إنك اخترتنا. لو عجبتك تجربتك معانا، هيسعدنا جدًا لو قيّمتنا هنا: {review_link}',
                'email_subject' => 'شاركنا رأيك في تجربتك معنا 🌟',
            ],
            [
                'name' => 'رسمي', 'preset_type' => 'professional',
                'message_template' => 'عزيزي {name}، نشكركم على ثقتكم بنا. نقدّر تقييمكم لتجربتكم معنا عبر الرابط التالي: {review_link}',
                'email_subject' => 'نرجو تقييم تجربتكم معنا',
            ],
            [
                'name' => 'مختصر', 'preset_type' => 'short',
                'message_template' => '{name}، لو عندك دقيقة، قيّمنا هنا: {review_link} 🙏',
                'email_subject' => 'رأيك يهمنا',
            ],
            [
                'name' => 'شكر', 'preset_type' => 'thank_you',
                'message_template' => 'شكرًا جزيلًا {name} على وقتك معنا! لو حابب تشاركنا رأيك عشان نستمر في التحسين: {review_link}',
                'email_subject' => 'شكرًا لك 🙏',
            ],
        ];
    }
}

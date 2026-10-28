<?php

/**
 * Tourfecto - Review Request Settings Model
 * @version 1.0.0
 */
class ReviewRequestSettings extends Model
{
    protected $table = 'review_request_settings';

    protected $fillable = [
        'website_id', 'is_enabled', 'default_delay_hours', 'message_template', 'email_subject',
        'reminder_enabled', 'reminder_after_hours', 'reminder_template', 'default_review_link',
        'google_review_link', 'tripadvisor_review_link', 'auto_from_crm_won', 'auto_from_bookings',
    ];

    /** الإعدادات الافتراضية لموقع لسه معملش إعدادات خاصة بيه */
    public static function defaults(int $websiteId): array
    {
        return [
            'website_id' => $websiteId,
            'is_enabled' => 1,
            'default_delay_hours' => 4,
            'message_template' => 'أهلاً {name}! 🌟 شكرًا إنك اخترتنا. لو عجبتك تجربتك معانا، هيسعدنا جدًا لو قيّمتنا هنا: {review_link}',
            'email_subject' => 'شاركنا رأيك في تجربتك معنا',
            'reminder_enabled' => 1,
            'reminder_after_hours' => 48,
            'reminder_template' => 'أهلاً {name}، تذكير بسيط لو حابب تشاركنا رأيك (بياخد أقل من دقيقة 🙏): {review_link}',
            'default_review_link' => '',
            'google_review_link' => '',
            'tripadvisor_review_link' => '',
            'auto_from_crm_won' => 0,
            'auto_from_bookings' => 0,
        ];
    }
}

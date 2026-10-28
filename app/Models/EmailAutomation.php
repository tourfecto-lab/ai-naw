<?php

/**
 * Tourfecto - Email Automation Model (سير عمل أتمتة تسويق البريد)
 * @version 1.0.0
 */
class EmailAutomation extends Model
{
    protected $table = 'email_automations';
    protected $fillable = [
        'user_id', 'name', 'description', 'trigger_type', 'trigger_value',
        'entry_audience_ids', 'exit_audience_ids', 'status',
    ];

    public const TRIGGER_SUBSCRIBED = 'subscribed';
    public const TRIGGER_TAG_ADDED = 'tag_added';
    public const TRIGGER_CAMPAIGN_OPENED = 'campaign_opened';
    public const TRIGGER_CAMPAIGN_CLICKED = 'campaign_clicked';
    public const TRIGGER_DATE_AFTER = 'date_after';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';

    public static function triggers(): array
    {
        return [
            self::TRIGGER_SUBSCRIBED => 'عند الاشتراك في قائمة',
            self::TRIGGER_TAG_ADDED => 'عند إضافة وسم',
            self::TRIGGER_CAMPAIGN_OPENED => 'عند فتح حملة',
            self::TRIGGER_CAMPAIGN_CLICKED => 'عند النقر في حملة',
            self::TRIGGER_DATE_AFTER => 'بعد مدة من الاشتراك',
        ] + (class_exists('EmailPlatformBridge') ? EmailPlatformBridge::triggers() : []);
    }
}

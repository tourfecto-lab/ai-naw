<?php

/**
 * Tourfecto - Email Automation Step Model (خطوة في سير عمل أتمتة البريد)
 * @version 1.0.0
 */
class EmailAutomationStep extends Model
{
    protected $table = 'email_automation_steps';
    protected $fillable = [
        'automation_id', 'position', 'step_type', 'step_value',
    ];

    public const STEP_WAIT = 'wait';
    public const STEP_SEND_EMAIL = 'send_email';
    public const STEP_ADD_TAG = 'add_tag';
    public const STEP_REMOVE_TAG = 'remove_tag';
    public const STEP_ADD_TO_LIST = 'add_to_list';
    public const STEP_REMOVE_FROM_LIST = 'remove_from_list';
    public const STEP_END = 'end';
    public const STEP_CONDITION = 'condition'; // if/else: step_value {check,tag|list_id,else_position}
    public const STEP_GOAL = 'goal';           // لو تحقق الشرط: المشترك حقق الهدف وتنتهي مشاركته

    public static function types(): array
    {
        return [
            self::STEP_WAIT => 'انتظار',
            self::STEP_SEND_EMAIL => 'إرسال بريد',
            self::STEP_ADD_TAG => 'إضافة وسم',
            self::STEP_REMOVE_TAG => 'إزالة وسم',
            self::STEP_ADD_TO_LIST => 'إضافة إلى قائمة',
            self::STEP_REMOVE_FROM_LIST => 'إزالة من قائمة',
            self::STEP_END => 'نهاية',
            self::STEP_CONDITION => 'شرط (لو/وإلا)',
            self::STEP_GOAL => 'هدف (خروج عند التحقق)',
        ] + (class_exists('EmailPlatformBridge') ? EmailPlatformBridge::stepTypes() : []);
    }
}

<?php

/**
 * Tourfecto - Email Suppression Model (قائمة ممنوعين/ارتدادات)
 * @version 1.0.0
 */
class EmailSuppression extends Model
{
    protected $table = 'email_suppressions';
    protected $fillable = ['user_id', 'email', 'type', 'reason', 'source'];

    public const TYPE_BOUNCE = 'bounce';
    public const TYPE_COMPLAINT = 'complaint';
    public const TYPE_SPAM = 'spam';
    public const TYPE_MANUAL = 'manual';

    public const VALID_TYPES = [
        self::TYPE_BOUNCE, self::TYPE_COMPLAINT, self::TYPE_SPAM, self::TYPE_MANUAL,
    ];
}

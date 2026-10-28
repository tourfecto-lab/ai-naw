<?php

/**
 * Tourfecto - Email Transactional Log Model (سجل رسالة معاملات مرسلة)
 * @version 1.0.0
 */
class EmailTransactionalLog extends Model
{
    protected $table = 'email_transactional_logs';
    protected $fillable = [
        'user_id', 'template_id', 'to_email', 'to_name', 'subject', 'status',
        'error', 'open_token', 'click_token', 'opened_at', 'clicked_at',
        'open_count', 'click_count',
    ];

    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
}

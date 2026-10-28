<?php

/**
 * Tourfecto - Email Transactional Template Model (قالب رسالة معاملات)
 * @version 1.0.0
 */
class EmailTransactionalTemplate extends Model
{
    protected $table = 'email_transactional_templates';
    protected $fillable = [
        'user_id', 'name', 'slug', 'subject', 'html_body', 'is_active',
    ];

    public const STATUS_ACTIVE = 1;
    public const STATUS_INACTIVE = 0;
}

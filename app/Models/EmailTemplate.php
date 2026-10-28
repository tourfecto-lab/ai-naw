<?php

/**
 * Tourfecto - Email Template Model (قوالب حملات تسويق البريد)
 * @version 1.0.0
 */
class EmailTemplate extends Model
{
    protected $table = 'email_templates';
    protected $fillable = [
        'user_id', 'name', 'subject', 'html_body', 'category', 'blocks', 'share_token', 'is_system'
    ];
}

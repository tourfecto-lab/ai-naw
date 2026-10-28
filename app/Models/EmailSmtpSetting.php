<?php

/**
 * Tourfecto - Email SMTP Settings Model (إعدادات SMTP لكل مستخدم)
 * @version 1.0.0
 */
class EmailSmtpSetting extends Model
{
    protected $table = 'email_smtp_settings';
    protected $fillable = [
        'user_id', 'host', 'port', 'username', 'password', 'encryption',
        'from_email', 'from_name', 'is_active', 'last_test_at',
    ];
}

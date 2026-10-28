<?php

/**
 * Tourfecto - Email Subscriber Custom Value Model (قيم الحقول المخصصة)
 * @version 1.0.0
 */
class EmailSubscriberCustomValue extends Model
{
    protected $table = 'email_subscriber_custom_values';
    protected $fillable = ['subscriber_id', 'field_id', 'value'];
}

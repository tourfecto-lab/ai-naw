<?php

/**
 * Tourfecto - Contact Submission Model
 * رسائل نموذج التواصل من صفحة /help/contact
 * @version 1.0.0
 */
class ContactSubmission extends Model
{
    protected $table = 'contact_submissions';

    protected $fillable = [
        'name',
        'email',
        'message',
        'user_id',
        'status',
        'ip_address',
    ];
}

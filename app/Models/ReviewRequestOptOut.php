<?php

/**
 * Tourfecto - Review Request Opt-Out Model
 * قائمة "عدم التواصل" الخاصة بحملة طلب المراجعات - مستقلة عن حالة
 * الطلب الفردي (opted_out)، عشان تمنع أي طلب جديد لنفس الضيف مستقبلاً
 * (يدوي أو تلقائي من CRM) طالما موجود هنا.
 * @version 1.0.0
 */
class ReviewRequestOptOut extends Model
{
    protected $table = 'review_request_opt_outs';

    protected $fillable = [
        'website_id', 'guest_phone', 'guest_email', 'reason', 'source_request_id', 'created_at',
    ];
}

<?php

/**
 * Tourfecto - Billing Profile Model
 * بيانات الفوترة الرسمية الاختيارية (اسم قانوني، عنوان، رقم ضريبي).
 * @version 1.0.0
 */
class BillingProfile extends Model
{
    protected $table = 'billing_profiles';

    protected $fillable = [
        'user_id', 'legal_name', 'billing_email', 'address_line1',
        'address_line2', 'city', 'country', 'tax_id',
    ];

    public static function forUser(int $userId): ?self
    {
        $rows = (new self())->where(['user_id' => $userId], [], 1);
        return $rows[0] ?? null;
    }
}

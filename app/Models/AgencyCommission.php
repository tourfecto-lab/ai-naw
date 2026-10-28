<?php

/**
 * Tourfecto - Agency Commission Model (White-Label)
 * عمولة الوكالة من حجز مؤكد لأحد عملائها. تُنشأ تلقائيًا عند تأكيد
 * الحجز (BookingEngine)، ويدفعها الوكيل/الأدمن يدويًا عبر
 * AgencyController::markCommissionPaid.
 * @version 1.0.0
 */
class AgencyCommission extends Model
{
    protected $table = 'agency_commissions';
    protected $fillable = [
        'agency_id', 'agency_client_id', 'booking_id',
        'commission_amount', 'status',
    ];
}

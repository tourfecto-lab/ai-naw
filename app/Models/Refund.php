<?php

/**
 * Tourfecto - Refund Model
 * @version 1.0.0
 */
class Refund extends Model
{
    protected $table = 'refunds';

    protected $fillable = [
        'payment_transaction_id', 'user_id', 'amount', 'currency', 'reason',
        'status', 'gateway_refund_reference', 'created_by_admin_id',
    ];
}

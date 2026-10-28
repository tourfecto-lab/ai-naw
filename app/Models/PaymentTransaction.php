<?php

/**
 * Tourfecto - Payment Transaction Model
 * السجل الموحّد لكل محاولات الدفع (أي طريقة، أي بوابة).
 * @version 1.0.0
 */
class PaymentTransaction extends Model
{
    protected $table = 'payment_transactions';

    protected $fillable = [
        'internal_transaction_id', 'user_id', 'amount', 'currency', 'payment_method',
        'gateway', 'gateway_transaction_id', 'status', 'reference',
        'booking_id', 'related_wallet_transaction_id', 'metadata', 'idempotency_key',
    ];
}

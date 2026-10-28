<?php
/**
 * Tourfecto - Wallet Transaction Model
 * @version 1.0.0
 */
class WalletTransaction extends Model {
    protected $table = 'wallet_transactions';

    protected $fillable = [
        'user_id', 'type', 'amount', 'currency', 'status', 'payment_method',
        'reference_note', 'admin_note', 'related_subscription_plan', 'approved_by', 'approved_at',
        'idempotency_key',
    ];
}

<?php

/** Tourfecto - Wallet Recharge Card Model @version 1.0.0 */
class WalletRechargeCard extends Model
{
    protected $table = 'wallet_recharge_cards';
    protected $fillable = ['code', 'value', 'status', 'batch_label', 'used_by_user_id', 'used_at', 'created_by_admin_id'];
}

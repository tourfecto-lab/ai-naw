<?php

/**
 * Tourfecto - Password Reset Token Model
 * @version 1.0.0
 */
class PasswordResetToken extends Model
{
    protected $table = 'password_reset_tokens';

    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
        'used_at',
    ];
}

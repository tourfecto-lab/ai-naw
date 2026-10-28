<?php
/**
 * Tourfecto - Platform Connection Model
 * اتصال OAuth لعميل معيّن بمنصة مراجعات معيّنة (Google Business وغيرها مستقبلاً)
 * @version 1.0.0
 */
class PlatformConnection extends Model {
    protected $table = 'platform_connections';

    protected $fillable = [
        'website_id',
        'user_id',
        'platform',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'external_account_id',
        'external_location_id',
        'external_location_name',
        'status',
        'last_error',
        'last_synced_at',
    ];

    public function isTokenExpired(): bool {
        $expiresAt = $this->getAttribute('token_expires_at');
        if (!$expiresAt) {
            return true;
        }
        // نعتبره منتهي قبل 5 دقايق من الموعد الفعلي كهامش أمان
        return strtotime($expiresAt) <= (time() + 300);
    }
}
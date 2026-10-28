<?php

/**
 * Tourfecto - Business Location Model
 * @version 1.0.0
 */
class BusinessLocation extends Model
{
    protected $table = 'business_locations';

    protected $fillable = [
        'business_id',
        'name',
        'country_code',
        'city',
        'address',
        'postal_code',
        'latitude',
        'longitude',
        'phone',
        'email',
        'is_primary',
        'opening_hours',
    ];

    /**
     * فك تشفير opening_hours من JSON مخزّن.
     * @return array
     */
    public function getOpeningHours(): array
    {
        $raw = $this->getAttribute('opening_hours');
        if (empty($raw)) {
            return [];
        }
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}

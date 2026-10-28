<?php

/**
 * Tourfecto - Business Target Market Model
 * صف واحد بس لكل Business (1:1) - Business Control Center Phase 5
 * @version 1.0.0
 */
class BusinessTargetMarket extends Model
{
    protected $table = 'business_target_markets';

    protected $fillable = [
        'business_id',
        'target_countries',
        'target_cities',
        'target_languages',
        'customer_type',
        'customer_segments',
    ];

    public static function allowedCustomerTypes(): array
    {
        return ['b2b', 'b2c', 'both'];
    }

    public function getTargetCountries(): array
    {
        return $this->decodeJsonArray('target_countries');
    }
    public function getTargetCities(): array
    {
        return $this->decodeJsonArray('target_cities');
    }
    public function getTargetLanguages(): array
    {
        return $this->decodeJsonArray('target_languages');
    }
    public function getCustomerSegments(): array
    {
        return $this->decodeJsonArray('customer_segments');
    }

    private function decodeJsonArray(string $field): array
    {
        $raw = $this->getAttribute($field);
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

<?php

/**
 * Tourfecto - Business Service Model
 * الخدمات اللي بتقدمها الشركة (Egypt Tours, Nile Cruises...) - Business Control Center Phase 4
 * @version 1.0.0
 */
class BusinessService extends Model
{
    protected $table = 'business_services';

    protected $fillable = [
        'business_id',
        'name',
        'slug',
        'description',
        'category',
        'active',
        'target_markets',
        'target_languages',
    ];

    /**
     * قائمة تصنيفات مقترحة بس - مش قيد صارم (category عمود VARCHAR حر في
     * الداتابيز). الهدف: قائمة اقتراحات جاهزة للواجهة، مع السماح بإدخال
     * تصنيف مخصص لو الشركة عندها خدمة مش في القائمة دي - نفس فلسفة
     * business_type لكن أكثر مرونة لأن فئات الخدمات السياحية أوسع بكتير.
     * @return string[]
     */
    public static function suggestedCategories(): array
    {
        return [
            'egypt_tours', 'nile_cruises', 'safari', 'honeymoon', 'religious_tourism',
            'beach_holidays', 'airport_transfers', 'hotels', 'flights', 'custom_tours',
            'day_trips', 'adventure_tourism', 'cultural_tours', 'diving_snorkeling',
            'desert_safari', 'group_tours', 'luxury_travel', 'other',
        ];
    }

    public function getTargetMarkets(): array
    {
        return $this->decodeJsonArray('target_markets');
    }

    public function getTargetLanguages(): array
    {
        return $this->decodeJsonArray('target_languages');
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

<?php

/**
 * Tourfecto - Business Brand Settings Model
 * صف واحد بس لكل Business (1:1) - Business Control Center Phase 7
 * @version 1.0.0
 *
 * ملاحظة: logo_url موجود في Business Model (Phase 2)، وbrand_voice/
 * preferred_tone موجودين في BusinessAiContext (Phase 6) - مش مكررين
 * هنا عمدًا. الموديل ده بيحتوي بس الحقول الجديدة فعليًا في Phase 7.
 */
class BusinessBrandSettings extends Model
{
    protected $table = 'business_brand_settings';

    protected $fillable = [
        'business_id',
        'favicon_url',
        'brand_colors',
        'font_preference',
        'writing_style',
        'preferred_terminology',
        'prohibited_terminology',
    ];

    /**
     * فك تشفير brand_colors - القيم المتوقعة: primary/secondary/accent.
     * لو أي مفتاح ناقص، بيرجع فاضي مش قيمة افتراضية وهمية (زي أسود
     * افتراضي) - الواجهة هي اللي تقرر الـFallback البصري، مش الباك إند.
     * @return array{primary?: string, secondary?: string, accent?: string}
     */
    public function getBrandColors(): array
    {
        $raw = $this->getAttribute('brand_colors');
        if (empty($raw)) {
            return [];
        }
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function getPreferredTerminology(): array
    {
        return $this->decodeJsonArray('preferred_terminology');
    }
    public function getProhibitedTerminology(): array
    {
        return $this->decodeJsonArray('prohibited_terminology');
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

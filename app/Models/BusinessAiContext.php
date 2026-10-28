<?php

/**
 * Tourfecto - Business AI Context Model
 * صف واحد بس لكل Business (1:1) - Business Control Center Phase 6
 * @version 1.0.0
 */
class BusinessAiContext extends Model
{
    protected $table = 'business_ai_context';

    /**
     * قائمة Brand Voice المعتمدة (Phase 7 - Brand Settings). العمود
     * brand_voice نفسه اتعمل في Phase 6، بس القائمة الرسمية دي جزء من
     * Phase 7 - مش عمود جديد، توثيق وValidation لعمود موجود بالفعل،
     * عشان نتجنب تكرار "brand voice" في جدولين مختلفين.
     * 'custom' معناها الوصف الفعلي موجود في
     * business_brand_settings.writing_style بدل قيمة ثابتة هنا.
     * @return string[]
     */
    public static function allowedBrandVoicePresets(): array
    {
        return ['professional', 'friendly', 'luxury', 'adventure', 'family', 'corporate', 'custom'];
    }

    protected $fillable = [
        'business_id',
        'business_summary',
        'brand_description',
        'target_audience',
        'unique_selling_points',
        'brand_voice',
        'preferred_tone',
        'forbidden_claims',
        'preferred_keywords',
        'business_goals',
        'seo_goals',
        'content_goals',
        'competitors',
        'important_notes',
    ];

    public function getUniqueSellingPoints(): array
    {
        return $this->decodeJsonArray('unique_selling_points');
    }
    public function getForbiddenClaims(): array
    {
        return $this->decodeJsonArray('forbidden_claims');
    }
    public function getPreferredKeywords(): array
    {
        return $this->decodeJsonArray('preferred_keywords');
    }
    public function getBusinessGoals(): array
    {
        return $this->decodeJsonArray('business_goals');
    }
    public function getSeoGoals(): array
    {
        return $this->decodeJsonArray('seo_goals');
    }
    public function getContentGoals(): array
    {
        return $this->decodeJsonArray('content_goals');
    }
    public function getCompetitors(): array
    {
        return $this->decodeJsonArray('competitors');
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

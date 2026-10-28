<?php

/** Tourfecto - Website Template Model (مكتبة تصميمات منشئ المواقع) @version 2.0.0 */
class WebsiteTemplate extends Model
{
    protected $table = 'website_templates';
    protected $fillable = [
        'niche_key', 'name_ar', 'name_en', 'description_ar', 'layout_key',
        'preview_image', 'allowed_theme_colors', 'is_premium', 'is_active', 'sort_order',
    ];

    /** كل التصميمات المتاحة لمجال معين، مرتبة (المجاني الأول) */
    public function forNiche(string $nicheKey): array
    {
        return $this->where(['niche_key' => $nicheKey, 'is_active' => 1], ['is_premium' => 'ASC', 'sort_order' => 'ASC']);
    }

    /** كل المجالات المتاحة حاليًا مع تصميم واحد على الأقل نشط - لخطوة الاختيار في الشات */
    public function activeNiches(): array
    {
        $rows = $this->where(['is_active' => 1], ['sort_order' => 'ASC']);
        $niches = [];
        foreach ($rows as $row) {
            $key = (string) $row->getAttribute('niche_key');
            if (!isset($niches[$key])) {
                $niches[$key] = $key;
            }
        }
        return array_values($niches);
    }

    public function allowedColors(): array
    {
        $raw = $this->getAttribute('allowed_theme_colors');
        $decoded = $raw ? json_decode((string) $raw, true) : null;
        return is_array($decoded) && !empty($decoded) ? $decoded : ['gold', 'blue', 'green', 'red', 'purple'];
    }
}

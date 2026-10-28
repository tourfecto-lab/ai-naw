<?php

/**
 * Tourfecto - Ad Creative Variant Model (بند 1)
 * تنويع (A/B/C) لأصل إعلاني مع أداءه الخام الحقيقي (ظهور/نقرات/إنفاق/
 * تحويلات/إيرادات). CTR/CPC تُحسب عند القراءة - لا تُخزَّن.
 * عزل التينانت عبر user_id.
 * @version 1.0.0
 */
class AdCreativeVariant extends Model
{
    protected $table = 'ad_creative_variants';
    protected $fillable = [
        'user_id', 'creative_id', 'variant_label', 'headline',
        'primary_text', 'media_url', 'impressions', 'clicks', 'spend',
        'conversions', 'revenue', 'is_control', 'recorded_on',
    ];
}

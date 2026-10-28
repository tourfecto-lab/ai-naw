<?php

/**
 * Tourfecto - Ad A/B Test Variant Model (بند 2)
 * ذراع تجربة A/B (تنويع أصل إعلاني) مع وزنه النسبي لتوزيع الحركة.
 * عزل التينانت عبر user_id.
 * @version 1.0.0
 */
class AdAbTestVariant extends Model
{
    protected $table = 'ad_ab_test_variants';
    protected $fillable = [
        'user_id', 'ab_test_id', 'creative_variant_id', 'weight_pct', 'is_control',
    ];
}

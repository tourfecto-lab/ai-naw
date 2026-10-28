<?php

/**
 * Tourfecto - Ad A/B Test Model (بند 2)
 * تجربة A/B على تنويعات الأصول الإعلانية. عزل التينانت عبر user_id.
 * @version 1.0.0
 */
class AdAbTest extends Model
{
    protected $table = 'ad_ab_tests';
    protected $fillable = [
        'user_id', 'campaign_id', 'creative_id', 'name',
        'status', 'winning_variant_id', 'started_at', 'ended_at',
    ];
}

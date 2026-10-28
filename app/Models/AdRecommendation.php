<?php

/**
 * Tourfecto - Ad Recommendation Model (بند 5)
 * توصية "الخطوة التالية" لحملة إعلانية محفوظة كسجل (audit trail) —
 * اقتراح فقط ولا يُنفَّذ تلقائيًا. عزل التينانت عبر user_id.
 * @version 1.0.0
 */
class AdRecommendation extends Model
{
    protected $table = 'ad_recommendations';
    protected $fillable = [
        'user_id', 'campaign_id', 'action', 'basis', 'confidence',
        'reason', 'signals', 'status', 'recommendation_date',
    ];
}

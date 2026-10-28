<?php
/** Tourfecto - Ad Budget Recommendation Model @version 1.0.0 */
class AdBudgetRecommendation extends Model {
    protected $table = 'ad_budget_recommendations';
    protected $fillable = ['campaign_id', 'recommended_daily_budget', 'bid_strategy', 'reasoning', 'confidence_score', 'applied'];
}

<?php
/** Tourfecto - Competitor Recommendation Model @version 1.0.0 */
class CompetitorRecommendation extends Model {
    protected $table = 'competitor_recommendations';
    protected $fillable = ['competitor_id', 'website_id', 'recommendation', 'priority', 'status'];
}

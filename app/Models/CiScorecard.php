<?php

/**
 * Tourfecto - Competitor Intelligence: Scorecard Model
 * @version 1.0.0
 */
class CiScorecard extends Model
{
    protected $table = 'ci_scorecards';
    protected $fillable = [
        'competitor_id', 'period_start', 'period_end', 'visibility_score',
        'content_activity_score', 'offer_activity_score', 'customer_signals_score',
        'product_coverage_score', 'market_presence_score', 'basis', 'raw_metrics',
    ];
}

<?php

/** Tourfecto - SEO Strategy Plan Model (Phase 14) @version 1.0.0 */
class SeoStrategyPlan extends Model
{
    protected $table = 'seo_strategy_plans';
    protected $fillable = ['user_id', 'website_id', 'summary', 'based_on_seo_score'];
}

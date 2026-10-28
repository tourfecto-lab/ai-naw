<?php

/**
 * Tourfecto - Ad Campaign Model (إدارة الإعلانات)
 * @version 1.0.0
 */
class AdCampaign extends Model
{
    protected $table = 'ad_campaigns';
    protected $fillable = [
        'user_id', 'website_id', 'platform_connection_id', 'platform', 'name',
        'objective', 'product_or_service', 'target_audience_brief',
        'target_countries_json', 'landing_page_url', 'landing_page_last_analysis',
        'landing_page_analyzed_at', 'daily_budget', 'budget_total', 'currency',
        'status', 'deleted_at', 'external_campaign_id', 'external_adset_id',
        'external_budget_resource', 'external_budget_resource_name',
        'impressions', 'clicks', 'spend', 'start_date', 'end_date',
        'ai_generated', 'published_at', 'auto_optimize', 'started_at', 'ended_at'
    ];
}

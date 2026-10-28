<?php
/**
 * Tourfecto - Ad Campaign Model (إدارة الإعلانات)
 * @version 1.0.0
 */
class AdCampaign extends Model {
    protected $table = 'ad_campaigns';
    protected $fillable = [
        'user_id', 'website_id', 'platform_connection_id', 'name',
        'objective', 'daily_budget', 'currency', 'status',
        'external_campaign_id', 'impressions', 'clicks', 'spend',
        'started_at', 'ended_at'
    ];
}

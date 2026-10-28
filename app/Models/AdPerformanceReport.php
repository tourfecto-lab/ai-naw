<?php

/** Tourfecto - Ad Performance Report Model @version 1.0.0 */
class AdPerformanceReport extends Model
{
    protected $table = 'ad_performance_reports';
    protected $fillable = ['campaign_id', 'date_start', 'date_end', 'impressions', 'clicks', 'conversions', 'spend', 'revenue', 'ctr', 'cpc', 'roas'];
}

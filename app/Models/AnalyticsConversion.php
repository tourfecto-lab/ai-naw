<?php

/** Tourfecto - Analytics Conversion Model @version 1.0.0 */
class AnalyticsConversion extends Model
{
    protected $table = 'analytics_conversions';
    protected $fillable = ['website_id', 'date', 'goal_name', 'conversions', 'revenue'];
}

<?php

/** Tourfecto - Analytics Traffic Model @version 1.0.0 */
class AnalyticsTraffic extends Model
{
    protected $table = 'analytics_traffic';
    protected $fillable = ['website_id', 'date', 'sessions', 'users', 'pageviews', 'bounce_rate', 'avg_session_duration_seconds', 'source'];
}

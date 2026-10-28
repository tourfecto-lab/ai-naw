<?php

/**
 * Tourfecto - Competitor Intelligence: User Preferences Model
 * @version 1.0.0
 */
class CiUserPreference extends Model
{
    protected $table = 'ci_user_preferences';
    protected $fillable = [
        'user_id', 'default_monitoring_frequency', 'default_alert_min_severity', 'default_alert_channels',
        'webhook_url', 'slack_webhook_url', 'weekly_digest_enabled',
    ];
}

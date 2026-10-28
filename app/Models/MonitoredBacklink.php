<?php

/** Tourfecto - Monitored Backlink Model (Item 2a) @version 1.0.0 */
class MonitoredBacklink extends Model
{
    protected $table = 'monitored_backlinks';
    protected $fillable = [
        'user_id', 'website_id', 'prospect_id', 'link_url', 'domain', 'status',
        'last_checked_at', 'last_seen_live_at', 'check_count',
        'last_http_status', 'last_error',
    ];
}

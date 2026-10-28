<?php

/**
 * Tourfecto - Competitor Intelligence: Watchlist Item Model
 * @version 1.0.0
 */
class CiWatchlistItem extends Model
{
    protected $table = 'ci_watchlist';
    protected $fillable = [
        'user_id', 'competitor_id', 'priority', 'alert_min_severity',
        'alert_channels', 'keyword_filters', 'is_paused',
    ];
}

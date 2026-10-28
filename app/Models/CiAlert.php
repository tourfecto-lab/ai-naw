<?php

/**
 * Tourfecto - Competitor Intelligence: Alert Model
 * @version 1.0.0
 */
class CiAlert extends Model
{
    protected $table = 'ci_alerts';
    protected $fillable = [
        'user_id', 'competitor_id', 'change_id', 'type', 'severity', 'title',
        'message', 'channel', 'is_read', 'sent_at',
    ];
}

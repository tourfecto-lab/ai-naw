<?php

/**
 * Tourfecto - Competitor Intelligence: Detected Change Model
 * @version 1.0.0
 */
class CiChange extends Model
{
    protected $table = 'ci_changes';
    protected $fillable = [
        'competitor_id', 'user_id', 'page_type', 'change_type', 'severity',
        'previous_value', 'new_value', 'source_url', 'confidence',
        'snapshot_before_id', 'snapshot_after_id', 'detected_at',
    ];
}

<?php

/**
 * Tourfecto - Competitor Intelligence: Report Model
 * @version 1.0.0
 */
class CiReport extends Model
{
    protected $table = 'ci_reports';
    protected $fillable = [
        'user_id', 'website_id', 'competitor_id', 'type', 'title',
        'period_start', 'period_end', 'content_json', 'generated_by',
    ];
}

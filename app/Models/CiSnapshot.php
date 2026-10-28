<?php

/**
 * Tourfecto - Competitor Intelligence: Page Snapshot Model
 * @version 1.0.0
 */
class CiSnapshot extends Model
{
    protected $table = 'ci_snapshots';
    protected $fillable = [
        'competitor_id', 'page_type', 'url', 'http_status', 'content_hash',
        'title', 'meta_description', 'normalized_excerpt', 'structured_data_hash', 'tech_signals',
        'fetch_status', 'fetch_error', 'captured_at',
    ];
}

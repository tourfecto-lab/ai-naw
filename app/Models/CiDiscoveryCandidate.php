<?php

/**
 * Tourfecto - Competitor Intelligence: Discovery Candidate Model
 * @version 1.0.0
 */
class CiDiscoveryCandidate extends Model
{
    protected $table = 'ci_discovery_candidates';
    protected $fillable = [
        'user_id', 'website_id', 'competitor_name', 'website', 'industry',
        'country', 'market_segment', 'source', 'category', 'confidence',
        'status', 'discovered_at',
    ];
}

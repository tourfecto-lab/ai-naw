<?php

/**
 * Tourfecto - Competitor Intelligence: AI/Rules Insight Model
 * @version 1.0.0
 */
class CiInsight extends Model
{
    protected $table = 'ci_insights';
    protected $fillable = [
        'user_id', 'website_id', 'competitor_id', 'type', 'title', 'description',
        'evidence', 'confidence', 'threat_level', 'recommended_action', 'status',
        'generated_by',
    ];
}

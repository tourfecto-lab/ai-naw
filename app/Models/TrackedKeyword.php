<?php

/** Tourfecto - Tracked Keyword Model @version 1.1.0 */
class TrackedKeyword extends Model
{
    protected $table = 'tracked_keywords';
    protected $fillable = [
        'market_id', 'impressions', 'gsc_position', 'gsc_checked_at',
        'user_id', 'website_id', 'keyword', 'current_position', 'search_volume', 'difficulty', 'last_checked_at',
        // Phase 6 (Keyword Intelligence) - إضافي، الحقول القديمة فوق متلمستش
        'search_intent', 'commercial_intent', 'opportunity_score', 'target_page', 'priority', 'enriched_at',
    ];
}

<?php
/** Tourfecto - Tracked Keyword Model @version 1.0.0 */
class TrackedKeyword extends Model {
    protected $table = 'tracked_keywords';
    protected $fillable = ['user_id', 'website_id', 'keyword', 'current_position', 'search_volume', 'difficulty', 'last_checked_at'];
}

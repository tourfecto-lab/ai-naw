<?php

/**
 * Tourfecto - Social Post Model
 * منشور سوشيال ميديا (المحتوى نفسه، منفصل عن أهداف النشر لكل منصة)
 * @version 1.0.0
 */
class SocialPost extends Model
{
    protected $table = 'social_posts';
    protected $fillable = [
        'user_id', 'website_id', 'content', 'media_item_id',
        'hashtags', 'first_comment', 'status', 'post_format', 'utm_campaign',
        'track_links', 'approval_state', 'requested_targets_json', 'created_by', 'reviewed_by', 'review_note'
    ];
}

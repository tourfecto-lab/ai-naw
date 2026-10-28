<?php

/** Tourfecto - Google Business Profile Scheduled Post Model @version 1.0.0 */
class GbpScheduledPost extends Model
{
    protected $table = 'gbp_scheduled_posts';
    protected $fillable = [
        'gbp_content_id', 'platform_connection_id', 'queue_job_id', 'scheduled_at', 'published_at',
        'google_post_id', 'status', 'attempts', 'error_message'
    ];
}

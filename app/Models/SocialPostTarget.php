<?php

/**
 * Tourfecto - Social Post Target Model
 * هدف نشر واحد (منصة + موعد) تابع لمنشور social_posts
 * @version 1.0.0
 */
class SocialPostTarget extends Model
{
    protected $table = 'social_post_targets';
    protected $fillable = [
        'social_post_id', 'platform_connection_id', 'scheduled_at',
        'published_at', 'external_post_id', 'status', 'last_error',
        'provider_ref', 'poll_attempts', 'error_code', 'error_category', 'fbtrace_id',
        'retry_count', 'next_retry_at', 'publish_attempt_at', 'reconciled_at', 'publish_ms'
    ];
}

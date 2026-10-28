<?php

/**
 * Tourfecto - Media Item Model (Creative Studio)
 * عنصر وسائط مولّد بالذكاء الاصطناعي (صورة/فيديو قصير)
 * @version 1.0.0
 */
class MediaItem extends Model
{
    protected $table = 'media_items';
    protected $fillable = [
        'publish_version','folder_id','is_favorite','deleted_at','provider_cost','request_key','generation_meta','generation_stage','usage_charged',
        'user_id', 'type', 'prompt', 'aspect_ratio', 'duration_seconds',
        'file_path', 'thumbnail_path', 'width', 'height', 'status',
        'error_message', 'job_id', 'provider_ref', 'poll_attempts',
        'brand_profile_id', 'final_prompt', 'provider', 'rating', 'feedback_note', 'headline'
    ];
}

<?php
/**
 * Tourfecto - Video Script Model (Creative Studio)
 * @version 1.0.0
 */
class VideoScript extends Model {
    protected $table = 'video_scripts';
    protected $fillable = [
        'user_id', 'topic', 'platform', 'duration_seconds',
        'script_text', 'scenes', 'status'
    ];
}

<?php
/**
 * Tourfecto - Media Item Model (Creative Studio)
 * عنصر وسائط مولّد بالذكاء الاصطناعي (صورة/فيديو قصير)
 * @version 1.0.0
 */
class MediaItem extends Model {
    protected $table = 'media_items';
    protected $fillable = [
        'user_id', 'type', 'prompt', 'file_path', 'thumbnail_path',
        'width', 'height', 'status', 'error_message', 'job_id'
    ];
}

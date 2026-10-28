<?php
/** Tourfecto - Google Business Profile Content Model @version 1.0.0 */
class GbpContent extends Model {
    protected $table = 'gbp_content';
    protected $fillable = ['user_id', 'website_id', 'type', 'prompt', 'generated_text', 'media_item_id', 'status'];
}

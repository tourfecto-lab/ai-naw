<?php

/** Tourfecto - Google Business Profile Content Model @version 1.0.0 */
class GbpContent extends Model
{
    protected $table = 'gbp_content';
    protected $fillable = ['user_id', 'website_id', 'type', 'prompt', 'generated_text', 'media_item_id', 'status',
        'post_title', 'event_start', 'event_end', 'coupon_code', 'offer_terms', 'cta_type', 'cta_url'];
}

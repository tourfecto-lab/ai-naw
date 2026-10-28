<?php

/**
 * Tourfecto - SEO: Rank Tracking History Model (G7)
 * @version 1.0.0
 *
 * قياس ترتيب الكلمات المفتاحية (SERP) لموقع العميل عبر الزمن.
 * `position` = ترتيب الظهور (1-100) أو NULL لو خارج أول 100 نتيجة.
 */
class SeoRankTrackingHistory extends Model
{
    protected $table = 'seo_rank_tracking_history';
    protected $fillable = [
        'website_id', 'user_id', 'keyword', 'position', 'url', 'source', 'checked_at',
    ];
}

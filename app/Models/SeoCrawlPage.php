<?php

/**
 * Tourfecto - SEO: Crawl Page Model (G1)
 * @version 1.0.0
 *
 * نتيجة فحص on-page لصفحة واحدة من الزحف المتعدد للموقع.
 * `status_code` = كود HTTP الفعلي؛ `depth` = العمق من الصفحة الرئيسية.
 */
class SeoCrawlPage extends Model
{
    protected $table = 'seo_crawl_pages';
    protected $fillable = [
        'website_id', 'user_id', 'crawl_id', 'url', 'status_code', 'depth',
        'title', 'title_length', 'has_meta_description', 'h1_count', 'h1_text',
        'word_count', 'http_time_ms', 'fetch_error', 'checked_at',
    ];
}

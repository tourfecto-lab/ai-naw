<?php

/**
 * Tourfecto - Ad Creative Model (بند 1: إدارة الأصول الإعلانية)
 * أصل إعلاني (نص/صورة/فيديو) يخص حملة إعلانية. عزل التينانت عبر user_id.
 * @version 1.0.0
 */
class AdCreative extends Model
{
    protected $table = 'ad_creatives';
    protected $fillable = [
        'user_id', 'campaign_id', 'name', 'creative_type',
        'headline', 'primary_text', 'media_url', 'status',
    ];
}

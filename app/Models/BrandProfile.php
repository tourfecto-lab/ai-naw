<?php

/** Tourfecto - هوية علامة العميل (Brand Studio) */
class BrandProfile extends Model
{
    protected $table = 'brand_profiles';
    protected $fillable = ['user_id', 'website_id', 'source_url', 'brand_name', 'logo_path', 'palette_json', 'profile_json', 'status'];
}

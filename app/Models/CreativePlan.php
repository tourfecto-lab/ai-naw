<?php

/** Tourfecto - خطة محتوى إبداعي (Brand Studio) */
class CreativePlan extends Model
{
    protected $table = 'creative_plans';
    protected $fillable = ['user_id', 'brand_profile_id', 'title', 'plan_json'];
}

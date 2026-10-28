<?php
/** Tourfecto - Ad Audience Model @version 1.0.0 */
class AdAudience extends Model {
    protected $table = 'ad_audiences';
    protected $fillable = ['campaign_id', 'name', 'age_min', 'age_max', 'genders', 'locations_json', 'interests_json', 'estimated_reach', 'ai_generated'];
}

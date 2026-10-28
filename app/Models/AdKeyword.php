<?php

/** Tourfecto - Ad Keyword Model @version 1.0.0 */
class AdKeyword extends Model
{
    protected $table = 'ad_keywords';
    protected $fillable = ['campaign_id', 'ad_group_id', 'keyword', 'match_type', 'ai_relevance_score', 'estimated_search_volume', 'estimated_cpc', 'ai_generated'];
}

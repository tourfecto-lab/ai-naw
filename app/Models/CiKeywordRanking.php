<?php

/**
 * Tourfecto - Competitor Intelligence: Keyword Ranking Model (G1)
 * @version 1.0.0
 *
 * سجل قياسات ترتيب الكلمات المفتاحية (SERP) لمنافس عبر الزمن.
 * `position` = ترتيب الظهور (1-100) أو NULL لو خارج أول 100 نتيجة.
 */
class CiKeywordRanking extends Model
{
    protected $table = 'ci_keyword_rankings';
    protected $fillable = [
        'competitor_id', 'keyword', 'position', 'url', 'source', 'checked_at',
    ];
}

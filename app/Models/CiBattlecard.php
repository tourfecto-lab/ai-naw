<?php

/**
 * Tourfecto - Competitor Intelligence: Battlecard Model (G6)
 * @version 1.0.0
 *
 * بطاقة معركة منافس لإعداد فريق المبيعات - تولد من بيانات مراقبة
 * حقيقية (scorecard / insights / تغييرات / أسعار) ولا تُختلق أبدًا.
 * أعمدة JSON تُخزَّن كنصوص JSON (تُبنى وتُفسَّر في BattlecardService).
 */
class CiBattlecard extends Model
{
    protected $table = 'ci_battlecards';
    protected $fillable = [
        'user_id', 'competitor_id', 'title', 'positioning_summary',
        'strengths', 'weaknesses', 'price_position', 'content_position',
        'recommended_actions', 'evidence', 'generated_at',
    ];
}

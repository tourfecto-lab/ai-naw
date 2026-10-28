<?php
/**
 * Tourfecto - Revenue AI Assistant Query Log Model
 * @version 1.0.0
 */
class RevaiAiQuery extends Model {
    protected $table = 'revai_ai_queries';
    protected $fillable = [
        'user_id', 'question', 'matched_intent', 'answer_summary',
        'confidence', 'had_enough_data',
    ];
}

<?php
/**
 * Tourfecto - Revenue AI Insight Model
 * جدول موحّد لـ Opportunities/Risks/Anomalies/Next-Best-Actions.
 * @version 1.0.0
 */
class RevaiInsight extends Model {
    protected $table = 'revai_insights';
    protected $fillable = [
        'user_id', 'type', 'category', 'title', 'finding', 'evidence',
        'reasoning_summary', 'confidence', 'severity', 'estimated_impact',
        'affected_area', 'recommended_action', 'subject_type', 'subject_id',
        'status', 'detected_at',
    ];
}

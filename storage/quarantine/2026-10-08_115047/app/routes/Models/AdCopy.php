<?php
/** Tourfecto - Ad Copy Model @version 1.0.0 */
class AdCopy extends Model {
    protected $table = 'ad_copies';
    protected $fillable = ['campaign_id', 'headline', 'description', 'primary_text', 'call_to_action', 'variant_label', 'ai_generated', 'status', 'performance_score'];
}

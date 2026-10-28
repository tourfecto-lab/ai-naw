<?php
/** Tourfecto - Ad Optimization Log Model @version 1.0.0 */
class AdOptimizationLog extends Model {
    protected $table = 'ad_optimization_logs';
    protected $fillable = ['campaign_id', 'action_type', 'description', 'ai_confidence', 'applied_automatically'];
}

<?php

/**
 * Tourfecto - Ad Optimization Log Model
 * سجل قرارات تحسين الحملات (مُوسَّع) - يشمل قرارات الـAutopilot القابلة
 * للتراجع (قبل/بعد + rollback) وسجل الإيقاف/الاستئناف اليدوي.
 * @version 1.1.0
 */
class AdOptimizationLog extends Model
{
    protected $table = 'ad_optimization_logs';
    protected $fillable = [
        'campaign_id', 'user_id', 'action_type', 'mode', 'description',
        'before_value', 'after_value', 'ai_confidence', 'applied_automatically',
        'external_result', 'can_rollback', 'rolled_back_at', 'rollback_of_log_id',
    ];

    /** أحدث سجلات التحسين لمستخدم (أو لكل حملة بعينها لو campaign_id متحدد) */
    public static function forUser(int $userId, int $limit = 50, ?int $campaignId = null): array
    {
        $conditions = ['user_id' => $userId];
        if ($campaignId !== null) {
            $conditions['campaign_id'] = $campaignId;
        }
        return (new self())->where($conditions, ['created_at' => 'DESC'], $limit);
    }
}

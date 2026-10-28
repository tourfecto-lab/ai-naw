<?php

/**
 * Tourfecto - Ad Pending Action Model
 * قرارات Autopilot المعلّقة بانتظار موافقة العميل (Approval Mode) أو اللي
 * اتجاوزت الـGuardrails وبقت محتاجة موافقة يدوية قبل التنفيذ الفعلي.
 * @version 1.0.0
 */
class AdPendingAction extends Model
{
    protected $table = 'ad_pending_actions';
    protected $fillable = [
        'user_id', 'campaign_id', 'action_type',
        'before_value', 'after_value', 'reasoning', 'confidence_level',
        'blocked_reason', 'status', 'decided_by_user_id', 'decided_at', 'executed_log_id',
    ];

    /** كل القرارات المعلّقة (لم تُحسم بعد) لمستخدم - الأحدث أولًا */
    public static function pendingForUser(int $userId, int $limit = 50): array
    {
        return (new self())->where(['user_id' => $userId, 'status' => 'pending'], ['created_at' => 'DESC'], $limit);
    }
}

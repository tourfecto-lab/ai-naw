<?php

/**
 * Tourfecto - AI Chat Platform
 * إعدادات المتابعة التلقائية القابلة للتعديل لكل شركة (بند 7).
 * @version 1.0.0
 */
class AiFollowupRule extends Model
{
    protected $table = 'ai_followup_rules';
    protected $fillable = ['website_id', 'is_enabled', 'steps', 'max_followups', 'stop_conditions'];

    /**
     * @param int $websiteId
     * @return AiFollowupRule|null
     */
    public function forWebsite(int $websiteId): ?self
    {
        $result = $this->where(['website_id' => $websiteId], [], 1);
        return $result[0] ?? null;
    }
}

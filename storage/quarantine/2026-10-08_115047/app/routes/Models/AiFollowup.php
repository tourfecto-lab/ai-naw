<?php
/**
 * Tourfecto - AI Chat Platform
 * سجلّ رسائل المتابعة المجدولة/المرسلة (بند 7).
 * @version 1.0.0
 */
class AiFollowup extends Model {
    protected $table = 'ai_followups';
    protected $fillable = [
        'website_id', 'conversation_id', 'lead_id', 'followup_number',
        'scheduled_at', 'sent_at', 'status', 'template_used', 'stop_reason',
    ];

    /**
     * المتابعات المستحقة الآن (تُستخدم في مهمة Cron - المرحلة القادمة).
     * @return array
     */
    public function dueNow(): array {
        $db = Database::getInstance();
        $rows = $db->query(
            "SELECT * FROM ai_followups WHERE status = 'pending' AND scheduled_at <= NOW() ORDER BY scheduled_at ASC LIMIT 200"
        );
        return array_map(function ($row) { return new AiFollowup($row); }, $rows);
    }
}

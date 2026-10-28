<?php

/**
 * Tourfecto - AI Chat Platform
 * سجلّ استخدام وتكلفة كل طلب AI (بند 21).
 * @version 1.0.0
 */
class AiUsageLog extends Model
{
    protected $table = 'ai_usage_logs';
    protected $fillable = [
        'website_id', 'user_id', 'conversation_id', 'provider', 'model',
        'feature', 'tokens_input', 'tokens_output', 'tokens_total',
        'estimated_cost_usd', 'status', 'duration_ms', 'error_message',
    ];

    /**
     * إحصائيات استخدام مبسّطة لموقع معيّن (تُستخدم في AI Analytics - بند 18).
     * @param int $websiteId
     * @param string|null $sinceDate 'Y-m-d'
     * @return array
     */
    public static function statsFor(int $websiteId, ?string $sinceDate = null): array
    {
        $db = Database::getInstance();
        $sql = "SELECT
                    COUNT(*) as total_requests,
                    SUM(tokens_total) as total_tokens,
                    SUM(estimated_cost_usd) as total_cost_usd,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_requests,
                    provider
                FROM ai_usage_logs
                WHERE website_id = ?";
        $params = [$websiteId];

        if ($sinceDate) {
            $sql .= " AND created_at >= ?";
            $params[] = $sinceDate;
        }

        $sql .= " GROUP BY provider";

        return $db->query($sql, $params);
    }
}

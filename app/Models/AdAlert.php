<?php

/**
 * Tourfecto - Ad Alert Model
 * تنبيه استباقي مولّد من AdAlertService حسب قواعد المستخدم. بيمثل إنذارًا
 * حقيقيًا مبنيًا على بيانات مُزامنة فعلية - ليس صفًا مختلقًا.
 * @version 1.0.0
 */
class AdAlert extends Model
{
    protected $table = 'ad_alerts';
    protected $fillable = [
        'user_id', 'campaign_id', 'rule_type', 'severity',
        'title', 'body', 'is_read', 'is_dismissed', 'alert_date',
    ];

    /** أحدث التنبيهات غير المقرؤة وغير المستبعدة لمستخدم */
    public static function recentForUser(int $userId, int $limit = 50, bool $onlyUnread = false): array
    {
        $conditions = ['user_id' => $userId, 'is_dismissed' => 0];
        if ($onlyUnread) {
            $conditions['is_read'] = 0;
        }
        return (new self())->where($conditions, ['created_at' => 'DESC'], $limit);
    }

    public static function unreadCount(int $userId): int
    {
        $db = Database::getInstance();
        $rows = $db->query(
            "SELECT COUNT(*) AS c FROM ad_alerts WHERE user_id = ? AND is_read = 0 AND is_dismissed = 0",
            [$userId]
        );
        return !empty($rows) ? (int) $rows[0]['c'] : 0;
    }

    public static function markAllReadForUser(int $userId): bool
    {
        $db = Database::getInstance();
        return $db->exec(
            "UPDATE ad_alerts SET is_read = 1 WHERE user_id = ? AND is_read = 0 AND is_dismissed = 0",
            [$userId]
        );
    }

    /** هل فيه تنبيه مولّد بالفعل لنفس القاعدة/الحملة اليوم؟ */
    public static function existsToday(int $userId, int $campaignId, string $ruleType): bool
    {
        $db = Database::getInstance();
        $rows = $db->query(
            "SELECT id FROM ad_alerts WHERE user_id = ? AND campaign_id = ? AND rule_type = ? AND alert_date = CURDATE() LIMIT 1",
            [$userId, $campaignId, $ruleType]
        );
        return !empty($rows);
    }
}

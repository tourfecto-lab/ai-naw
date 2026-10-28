<?php

/**
 * Tourfecto - Usage Tracker
 * تتبع استخدام الـ Credits والـ API
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class UsageTracker
{
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;

    /**
     * @var array $usageTypes - أنواع الاستخدام
     */
    private $usageTypes = [
        'ai' => 'ai_credits',
        'chat' => 'chat_credits',
        'review' => 'review_credits',
        'competitor_analysis' => 'competitor_analysis'
    ];

    /**
     * @var Cache $cache - نظام الكاش
     */
    private $cache;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->cache = new Cache();
    }

    /**
     * تسجيل استخدام
     * @param int $userId
     * @param string $type
     * @param int $amount
     * @param array $metadata
     * @return bool
     */
    public function logUsage(int $userId, string $type, int $amount = 1, array $metadata = []): bool
    {
        try {
            // تسجيل في جدول الاستخدام اليومي
            $sql = "INSERT INTO daily_usage_logs 
                    (user_id, usage_type, amount, metadata, usage_date, created_at) 
                    VALUES 
                    (:user_id, :type, :amount, :metadata, CURDATE(), NOW())
                    ON DUPLICATE KEY UPDATE amount = amount + :amount_add";

            // الجدول عليه UNIQUE (user_id, usage_type, usage_date)، فتاني استخدام
            // في نفس اليوم كان بيفشل بـ Duplicate entry والاستهلاك مايتحسبش.
            // دلوقتي بنزوّد الكمية على نفس صف اليوم بدل ما نضيف صف جديد.
            $this->db->query($sql, [
                ':user_id' => $userId,
                ':type' => $type,
                ':amount' => $amount,
                ':metadata' => json_encode($metadata) ?: '[]',
                ':amount_add' => $amount
            ]);

            // تحديث الكاش
            $cacheKey = "usage_{$userId}_{$type}_" . date('Y-m-d');
            $this->cache->delete($cacheKey);

            return true;

        } catch (Exception $e) {
            Logger::error('Log Usage Error', [
                'user_id' => $userId,
                'type' => $type,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * الحصول على استخدام اليوم
     * @param int $userId
     * @param string|null $type
     * @return array
     */
    public function getDailyUsage(int $userId, ?string $type = null): array
    {
        try {
            $cacheKey = "usage_{$userId}_" . ($type ?? 'all') . '_' . date('Y-m-d');
            $cached = $this->cache->get($cacheKey);

            if ($cached !== null) {
                return $cached;
            }

            $sql = "SELECT 
                        usage_type,
                        SUM(amount) as total_usage,
                        COUNT(*) as total_entries
                    FROM daily_usage_logs 
                    WHERE user_id = :user_id 
                    AND usage_date = CURDATE()";

            $params = [':user_id' => $userId];

            if ($type) {
                $sql .= " AND usage_type = :type";
                $params[':type'] = $type;
            }

            $sql .= " GROUP BY usage_type";

            $result = $this->db->query($sql, $params);

            $usage = [];
            foreach ($result as $row) {
                $usage[$row['usage_type']] = [
                    'total' => (int) $row['total_usage'],
                    'entries' => (int) $row['total_entries']
                ];
            }

            // تخزين في الكاش
            $this->cache->set($cacheKey, $usage, 3600); // 1 ساعة

            return $usage;

        } catch (Exception $e) {
            Logger::error('Get Daily Usage Error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * الحصول على استخدام الشهر
     * @param int $userId
     * @param string|null $type
     * @return array
     */
    public function getMonthlyUsage(int $userId, ?string $type = null): array
    {
        try {
            require_once __DIR__.'/SubscriptionPeriod.php';
            $sub=Subscription::activeSubscriptionRow($userId);
            $anchor=$sub ? SubscriptionPeriod::quotaStart((string)$sub['current_period_start']) : date('Y-m-01 00:00:00');
            // Logs aggregate by day; exact entitlement counters remain in subscriptions.
            $fromDate=substr($anchor,0,10);
            $sql = "SELECT 
                        usage_type,
                        SUM(amount) as total_usage,
                        COUNT(*) as total_entries,
                        COUNT(DISTINCT usage_date) as active_days
                    FROM daily_usage_logs 
                    WHERE user_id = :user_id 
                    AND usage_date >= :period_start AND usage_date <= CURDATE()";

            $params = [':user_id' => $userId, ':period_start'=>$fromDate];

            if ($type) {
                $sql .= " AND usage_type = :type";
                $params[':type'] = $type;
            }

            $sql .= " GROUP BY usage_type";

            $result = $this->db->query($sql, $params);

            $usage = [];
            foreach ($result as $row) {
                $usage[$row['usage_type']] = [
                    'total' => (int) $row['total_usage'],
                    'entries' => (int) $row['total_entries'],
                    'active_days' => (int) $row['active_days']
                ];
            }

            return $usage;

        } catch (Exception $e) {
            Logger::error('Get Monthly Usage Error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * الحصول على إحصائيات الاستخدام
     * @param int $userId
     * @param int $days
     * @return array
     */
    public function getUsageStats(int $userId, int $days = 30): array
    {
        try {
            $sql = "SELECT 
                        usage_type,
                        DATE(usage_date) as date,
                        SUM(amount) as daily_total
                    FROM daily_usage_logs 
                    WHERE user_id = :user_id 
                    AND usage_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                    GROUP BY usage_type, DATE(usage_date)
                    ORDER BY date ASC";

            $result = $this->db->query($sql, [
                ':user_id' => $userId,
                ':days' => $days
            ]);

            $stats = [];
            foreach ($result as $row) {
                $type = $row['usage_type'];
                if (!isset($stats[$type])) {
                    $stats[$type] = [];
                }
                $stats[$type][] = [
                    'date' => $row['date'],
                    'amount' => (int) $row['daily_total']
                ];
            }

            return $stats;

        } catch (Exception $e) {
            Logger::error('Get Usage Stats Error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * التحقق من حد الاستخدام اليومي
     * @param int $userId
     * @param string $type
     * @param int $limit
     * @return bool
     */
    public function checkDailyLimit(int $userId, string $type, int $limit): bool
    {
        $usage = $this->getDailyUsage($userId, $type);
        $current = $usage[$type]['total'] ?? 0;

        return $current < $limit;
    }

    /**
     * الحصول على نسبة الاستخدام
     * @param int $userId
     * @param string $type
     * @param int $total
     * @return float
     */
    public function getUsagePercentage(int $userId, string $type, int $total): float
    {
        $usage = $this->getMonthlyUsage($userId, $type);
        $used = $usage[$type]['total'] ?? 0;

        if ($total <= 0) {
            return 0;
        }

        return round(($used / $total) * 100, 2);
    }

    /**
     * الحصول على أعلى أنواع الاستخدام
     * @param int $userId
     * @param int $limit
     * @return array
     */
    public function getTopUsageTypes(int $userId, int $limit = 5): array
    {
        try {
            $sql = "SELECT 
                        usage_type,
                        SUM(amount) as total_usage
                    FROM daily_usage_logs 
                    WHERE user_id = :user_id 
                    AND usage_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    GROUP BY usage_type
                    ORDER BY total_usage DESC
                    LIMIT :limit";

            $result = $this->db->query($sql, [
                ':user_id' => $userId,
                ':limit' => $limit
            ]);

            return $result;

        } catch (Exception $e) {
            Logger::error('Get Top Usage Types Error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * تنظيف سجلات الاستخدام القديمة
     * @param int $days
     * @return int
     */
    public function cleanOldLogs(int $days = 90): int
    {
        try {
            $sql = "DELETE FROM daily_usage_logs 
                    WHERE usage_date < DATE_SUB(CURDATE(), INTERVAL :days DAY)";

            return (int) $this->db->query($sql, [':days' => $days]);

        } catch (Exception $e) {
            Logger::error('Clean Old Logs Error', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
}

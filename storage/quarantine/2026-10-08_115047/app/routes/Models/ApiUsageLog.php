<?php
/**
 * Tourfecto - API Usage Log Model
 * نموذج سجل استخدام الـ API مع تتبع التكلفة
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class ApiUsageLog extends Model {
    /**
     * @var string $table - اسم الجدول
     */
    protected $table = 'api_usage_logs';
    
    /**
     * @var array $fillable - الحقول القابلة للتعبئة
     */
    protected $fillable = [
        'user_id',
        'api_type',
        'endpoint',
        'request_data',
        'response_data',
        'status_code',
        'tokens_used',
        'cost_in_usd',
        'duration_ms',
        'ip_address'
    ];
    
    /**
     * @var array $casts - تحويل البيانات
     */
    protected $casts = [
        'request_data' => 'array',
        'response_data' => 'array'
    ];
    
    /**
     * Constructor - معالجة تحويل JSON
     */
    public function __construct(array $attributes = []) {
        parent::__construct($attributes);
        
        // تحويل JSON إلى مصفوفات
        foreach ($this->casts as $field => $type) {
            if (isset($this->attributes[$field]) && is_string($this->attributes[$field])) {
                $this->attributes[$field] = json_decode($this->attributes[$field], true);
            }
        }
    }
    
    /**
     * تسجيل استخدام API
     * @param array $data
     * @return int|bool
     */
    public static function log(array $data) {
        // تحويل المصفوفات إلى JSON
        foreach (['request_data', 'response_data'] as $field) {
            if (isset($data[$field]) && is_array($data[$field])) {
                $data[$field] = json_encode($data[$field]);
            }
        }
        
        $log = new static($data);
        return $log->save();
    }
    
    /**
     * الحصول على المستخدم
     * @return User|null
     */
    public function getUser(): ?User {
        $sql = "SELECT * FROM users WHERE id = ? LIMIT 1";
        $result = $this->db->query($sql, [$this->attributes['user_id']]);
        
        if (empty($result)) {
            return null;
        }
        
        return new User($result[0]);
    }
    
    /**
     * الحصول على إحصائيات الاستخدام
     * @param int $userId
     * @param int $days
     * @return array
     */
    public static function getUsageStats(int $userId, int $days = 30): array {
        $db = Database::getInstance();
        
        $sql = "SELECT 
                    api_type,
                    COUNT(*) as total_requests,
                    SUM(tokens_used) as total_tokens,
                    SUM(cost_in_usd) as total_cost,
                    AVG(duration_ms) as avg_duration,
                    MAX(duration_ms) as max_duration,
                    SUM(CASE WHEN status_code >= 200 AND status_code < 300 THEN 1 ELSE 0 END) as success_count,
                    SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as error_count
                FROM api_usage_logs 
                WHERE user_id = ? 
                AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY api_type";
        
        $result = $db->query($sql, [$userId, $days]);
        
        $stats = [];
        foreach ($result as $row) {
            $stats[$row['api_type']] = [
                'total_requests' => (int) $row['total_requests'],
                'total_tokens' => (int) $row['total_tokens'],
                'total_cost' => round((float) $row['total_cost'], 6),
                'avg_duration' => round((float) $row['avg_duration'], 2),
                'max_duration' => (float) $row['max_duration'],
                'success_rate' => $row['total_requests'] > 0 
                    ? round(($row['success_count'] / $row['total_requests']) * 100, 2)
                    : 0,
                'error_count' => (int) $row['error_count']
            ];
        }
        
        return $stats;
    }
    
    /**
     * الحصول على استهلاك اليوم
     * @param int $userId
     * @return array
     */
    public static function getTodayUsage(int $userId): array {
        $db = Database::getInstance();
        
        $sql = "SELECT 
                    SUM(cost_in_usd) as total_cost,
                    COUNT(*) as total_requests
                FROM api_usage_logs 
                WHERE user_id = ? 
                AND DATE(created_at) = CURDATE()";
        
        $result = $db->query($sql, [$userId]);
        
        return [
            'total_cost' => round((float) ($result[0]['total_cost'] ?? 0), 6),
            'total_requests' => (int) ($result[0]['total_requests'] ?? 0)
        ];
    }
    
    /**
     * الحصول على استهلاك الشهر
     * @param int $userId
     * @return array
     */
    public static function getMonthlyUsage(int $userId): array {
        $db = Database::getInstance();
        
        $sql = "SELECT 
                    SUM(cost_in_usd) as total_cost,
                    COUNT(*) as total_requests,
                    SUM(tokens_used) as total_tokens
                FROM api_usage_logs 
                WHERE user_id = ? 
                AND MONTH(created_at) = MONTH(CURRENT_DATE())
                AND YEAR(created_at) = YEAR(CURRENT_DATE())";
        
        $result = $db->query($sql, [$userId]);
        
        return [
            'total_cost' => round((float) ($result[0]['total_cost'] ?? 0), 6),
            'total_requests' => (int) ($result[0]['total_requests'] ?? 0),
            'total_tokens' => (int) ($result[0]['total_tokens'] ?? 0)
        ];
    }
    
    /**
     * الحصول على سجل الاستخدام حسب النوع
     * @param int $userId
     * @param string $apiType
     * @param int $limit
     * @return array
     */
    public static function getLogsByType(int $userId, string $apiType, int $limit = 100): array {
        $db = Database::getInstance();
        
        $sql = "SELECT * FROM api_usage_logs 
                WHERE user_id = ? 
                AND api_type = ? 
                ORDER BY created_at DESC 
                LIMIT ?";
        
        $result = $db->query($sql, [$userId, $apiType, $limit]);
        
        return array_map(function($data) {
            return new static($data);
        }, $result);
    }
    
    /**
     * تنظيف السجلات القديمة
     * @param int $days
     * @return int
     */
    public static function cleanOldLogs(int $days = 90): int {
        $db = Database::getInstance();
        
        $sql = "DELETE FROM api_usage_logs 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";
        
        return $db->query($sql, [$days]);
    }
}
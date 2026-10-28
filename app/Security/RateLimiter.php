<?php
/**
 * Tourfecto - Rate Limiter
 * نظام تحديد معدل الطلبات للحماية من الهجمات
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class RateLimiter {
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;
    
    /**
     * @var Cache $cache - نظام الكاش
     */
    private $cache;
    
    /**
     * @var array $limits - حدود المعدلات
     */
    private $limits = [];
    
    /**
     * @var array $blockedIPs - عناوين IP المحظورة
     */
    private $blockedIPs = [];
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->db = Database::getInstance();
        $this->cache = new Cache();
        $this->loadLimits();
        $this->loadBlockedIPs();
    }
    
    /**
     * التحقق من معدل الطلبات
     * @param string $identifier
     * @param string $type
     * @param int $maxRequests
     * @param int $timeWindow
     * @return bool
     */
    public function check(
        string $identifier,
        string $type = 'default',
        int $maxRequests = 100,
        int $timeWindow = 60
    ): bool {
        // التحقق من الحظر
        if ($this->isBlocked($identifier)) {
            return false;
        }
        
        $key = $this->getKey($identifier, $type);
        $current = $this->getCurrentUsage($key, $timeWindow);
        
        if ($current >= $maxRequests) {
            $this->blockIdentifier($identifier, 'Rate limit exceeded');
            return false;
        }
        
        $this->incrementUsage($key, $timeWindow);
        
        return true;
    }
    
    /**
     * التحقق من معدل الطلبات مع إرجاع تفاصيل
     * @param string $identifier
     * @param string $type
     * @param int $maxRequests
     * @param int $timeWindow
     * @return array
     */
    public function checkWithDetails(
        string $identifier,
        string $type = 'default',
        int $maxRequests = 100,
        int $timeWindow = 60
    ): array {
        $key = $this->getKey($identifier, $type);
        $current = $this->getCurrentUsage($key, $timeWindow);
        $remaining = max(0, $maxRequests - $current);
        
        // مستخدم مسجّل دخول (user_N): مفيش حظر ساعة كاملة - كان زرار واحد (إعادة إرسال التأكيد 5 مرات)
        // بيقفل الحساب كله ساعة، وكل محاولة بتمدّها ساعة كمان. بنرفض الطلب ده بس لحد ما الدقيقة تخلص.
        $soft = strpos($identifier, 'user_') === 0;
        $blocked = !$soft && $this->isBlocked($identifier);
        $allowed = $current < $maxRequests && !$blocked;
        
        if (!$allowed) {
            if (!$soft && !$blocked) {
                $this->blockIdentifier($identifier, 'Rate limit exceeded');
            }
        } else {
            $this->incrementUsage($key, $timeWindow);
        }
        
        return [
            'allowed' => $allowed,
            'current' => $current,
            'max' => $maxRequests,
            'remaining' => $remaining,
            'reset_in' => $this->getResetTime($timeWindow),
            'is_blocked' => $this->isBlocked($identifier)
        ];
    }
    
    /**
     * التحقق من API Key
     * @param string $apiKey
     * @param int $maxRequests
     * @param int $timeWindow
     * @return bool
     */
    public function checkApiKey(string $apiKey, int $maxRequests = 1000, int $timeWindow = 3600): bool {
        return $this->check($apiKey, 'api', $maxRequests, $timeWindow);
    }
    
    /**
     * التحقق من IP
     * @param string $ip
     * @param int $maxRequests
     * @param int $timeWindow
     * @return bool
     */
    public function checkIP(string $ip, int $maxRequests = 100, int $timeWindow = 60): bool {
        return $this->check($ip, 'ip', $maxRequests, $timeWindow);
    }
    
    /**
     * التحقق من المستخدم
     * @param int $userId
     * @param int $maxRequests
     * @param int $timeWindow
     * @return bool
     */
    public function checkUser(int $userId, int $maxRequests = 500, int $timeWindow = 3600): bool {
        return $this->check((string) $userId, 'user', $maxRequests, $timeWindow);
    }
    
    /**
     * حظر معرف
     * @param string $identifier
     * @param string $reason
     * @param int $duration
     * @return bool
     */
    public function blockIdentifier(string $identifier, string $reason = '', int $duration = 3600): bool {
        try {
            $sql = "INSERT INTO rate_limit_blocks 
                    (identifier, reason, expires_at, created_at) 
                    VALUES 
                    (:identifier, :reason, DATE_ADD(NOW(), INTERVAL :duration SECOND), NOW())";
            
            $result = $this->db->query($sql, [
                ':identifier' => $identifier,
                ':reason' => $reason,
                ':duration' => $duration
            ]);
            
            if ($result) {
                $this->cache->set("blocked_{$identifier}", true, $duration);
                $this->blockedIPs[] = $identifier;
                
                Logger::warning('Identifier Blocked', [
                    'identifier' => $identifier,
                    'reason' => $reason,
                    'duration' => $duration
                ]);
            }
            
            return $result !== false;
            
        } catch (Exception $e) {
            Logger::error('Block Identifier Error', [
                'identifier' => $identifier,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * إلغاء حظر معرف
     * @param string $identifier
     * @return bool
     */
    public function unblockIdentifier(string $identifier): bool {
        try {
            $sql = "DELETE FROM rate_limit_blocks 
                    WHERE identifier = :identifier 
                    OR expires_at < NOW()";
            
            $result = $this->db->query($sql, [':identifier' => $identifier]);
            
            if ($result) {
                $this->cache->delete("blocked_{$identifier}");
                
                // تحديث قائمة المحظورين
                $this->blockedIPs = array_diff($this->blockedIPs, [$identifier]);
            }
            
            return $result !== false;
            
        } catch (Exception $e) {
            Logger::error('Unblock Identifier Error', [
                'identifier' => $identifier,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * التحقق من الحظر
     * @param string $identifier
     * @return bool
     */
    public function isBlocked(string $identifier): bool {
        // التحقق من الكاش أولاً
        $cached = $this->cache->get("blocked_{$identifier}");
        if ($cached !== null) {
            return $cached;
        }
        
        // التحقق من قاعدة البيانات
        $sql = "SELECT id FROM rate_limit_blocks 
                WHERE identifier = :identifier 
                AND expires_at > NOW() 
                LIMIT 1";
        
        $result = $this->db->query($sql, [':identifier' => $identifier]);
        $isBlocked = !empty($result);
        
        // تخزين في الكاش
        $expires = $isBlocked ? 300 : 60; // 5 دقائق إذا كان محظوراً، دقيقة واحدة إذا لم يكن
        $this->cache->set("blocked_{$identifier}", $isBlocked, $expires);
        
        return $isBlocked;
    }
    
    /**
     * الحصول على مفتاح الكاش
     * @param string $identifier
     * @param string $type
     * @return string
     */
    private function getKey(string $identifier, string $type): string {
        return "rate_limit_{$type}_{$identifier}";
    }
    
    /**
     * الحصول على الاستخدام الحالي
     * @param string $key
     * @param int $timeWindow
     * @return int
     */
    private function getCurrentUsage(string $key, int $timeWindow): int {
        $data = $this->cache->get($key);
        
        if ($data === null) {
            return 0;
        }
        
        // تنظيف البيانات القديمة
        $this->cleanOldEntries($data, $timeWindow);
        
        return count($data);
    }
    
    /**
     * زيادة عداد الاستخدام
     * @param string $key
     * @param int $timeWindow
     */
    private function incrementUsage(string $key, int $timeWindow): void {
        $data = $this->cache->get($key) ?? [];
        
        // إضافة الطلب الحالي
        $data[] = time();
        
        // تنظيف البيانات القديمة
        $this->cleanOldEntries($data, $timeWindow);
        
        // تخزين في الكاش
        $this->cache->set($key, $data, $timeWindow);
    }
    
    /**
     * تنظيف الإدخالات القديمة
     * @param array &$data
     * @param int $timeWindow
     */
    private function cleanOldEntries(array &$data, int $timeWindow): void {
        $threshold = time() - $timeWindow;
        $data = array_filter($data, function($timestamp) use ($threshold) {
            return $timestamp > $threshold;
        });
    }
    
    /**
     * الحصول على وقت إعادة الضبط
     * @param int $timeWindow
     * @return int
     */
    private function getResetTime(int $timeWindow): int {
        return $timeWindow - (time() % $timeWindow);
    }
    
    /**
     * تحميل حدود المعدلات
     */
    private function loadLimits(): void {
        $this->limits = [
            'default' => [
                'max' => 100,
                'window' => 60 // ثانية
            ],
            'api' => [
                'max' => 1000,
                'window' => 3600 // ساعة
            ],
            'ip' => [
                'max' => 100,
                'window' => 60 // ثانية
            ],
            'user' => [
                'max' => 500,
                'window' => 3600 // ساعة
            ],
            'auth' => [
                'max' => 5,
                'window' => 300 // 5 دقائق
            ],
            'webhook' => [
                'max' => 10,
                'window' => 60 // ثانية
            ]
        ];
    }
    
    /**
     * تحميل عناوين IP المحظورة
     */
    private function loadBlockedIPs(): void {
        $sql = "SELECT identifier FROM rate_limit_blocks WHERE expires_at > NOW()";
        $result = $this->db->query($sql);
        
        $this->blockedIPs = array_column($result, 'identifier');
    }
    
    /**
     * تنظيف الحظر المنتهي
     * @return int
     */
    public function cleanExpiredBlocks(): int {
        $sql = "DELETE FROM rate_limit_blocks WHERE expires_at < NOW()";
        return (int) $this->db->query($sql);
    }
    
    /**
     * الحصول على إحصائيات الحظر
     * @return array
     */
    public function getBlockStats(): array {
        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN expires_at > NOW() THEN 1 ELSE 0 END) as active,
                    MAX(expires_at) as latest_expiry
                FROM rate_limit_blocks";
        
        $result = $this->db->query($sql);
        
        if (empty($result)) {
            return [
                'total' => 0,
                'active' => 0,
                'latest_expiry' => null
            ];
        }
        
        return [
            'total' => (int) $result[0]['total'],
            'active' => (int) $result[0]['active'],
            'latest_expiry' => $result[0]['latest_expiry']
        ];
    }
}
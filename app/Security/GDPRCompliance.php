<?php
/**
 * Tourfecto - GDPR Compliance
 * نظام الامتثال للائحة العامة لحماية البيانات (GDPR)
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class GDPRCompliance {
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;
    
    /**
     * @var Encryption $encryption - نظام التشفير
     */
    private $encryption;
    
    /**
     * @var array $sensitiveFields - الحقول الحساسة
     */
    private $sensitiveFields = [
        'email', 'phone', 'address', 'passport_number', 
        'national_id', 'credit_card', 'cvv', 'ssn'
    ];
    
    /**
     * @var array $consentRecords - سجلات الموافقات
     */
    private $consentRecords = [];
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->db = Database::getInstance();
        $this->encryption = new Encryption();
    }
    
    /**
     * تسجيل موافقة المستخدم
     * @param int $userId
     * @param string $consentType
     * @param array $data
     * @return bool
     */
    public function recordConsent(int $userId, string $consentType, array $data = []): bool {
        try {
            $sql = "INSERT INTO gdpr_consents 
                    (user_id, consent_type, consent_data, ip_address, user_agent, created_at) 
                    VALUES 
                    (:user_id, :consent_type, :consent_data, :ip_address, :user_agent, NOW())";
            
            $result = $this->db->query($sql, [
                ':user_id' => $userId,
                ':consent_type' => $consentType,
                ':consent_data' => json_encode($data),
                ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
            
            return $result !== false;
            
        } catch (Exception $e) {
            Logger::error('Record Consent Error', [
                'user_id' => $userId,
                'consent_type' => $consentType,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * التحقق من وجود موافقة
     * @param int $userId
     * @param string $consentType
     * @return bool
     */
    public function hasConsent(int $userId, string $consentType): bool {
        try {
            $sql = "SELECT id FROM gdpr_consents 
                    WHERE user_id = :user_id 
                    AND consent_type = :consent_type 
                    AND revoked_at IS NULL 
                    ORDER BY created_at DESC LIMIT 1";
            
            $result = $this->db->query($sql, [
                ':user_id' => $userId,
                ':consent_type' => $consentType
            ]);
            
            return !empty($result);
            
        } catch (Exception $e) {
            Logger::error('Check Consent Error', [
                'user_id' => $userId,
                'consent_type' => $consentType,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * سحب الموافقة
     * @param int $userId
     * @param string $consentType
     * @return bool
     */
    public function revokeConsent(int $userId, string $consentType): bool {
        try {
            $sql = "UPDATE gdpr_consents 
                    SET revoked_at = NOW() 
                    WHERE user_id = :user_id 
                    AND consent_type = :consent_type 
                    AND revoked_at IS NULL";
            
            $result = $this->db->query($sql, [
                ':user_id' => $userId,
                ':consent_type' => $consentType
            ]);
            
            return $result !== false;
            
        } catch (Exception $e) {
            Logger::error('Revoke Consent Error', [
                'user_id' => $userId,
                'consent_type' => $consentType,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * طلب حذف البيانات (Right to be Forgotten)
     * @param int $userId
     * @return array
     */
    public function requestDataDeletion(int $userId): array {
        try {
            // تسجيل طلب الحذف
            $sql = "INSERT INTO gdpr_deletion_requests 
                    (user_id, request_date, status, created_at) 
                    VALUES 
                    (:user_id, NOW(), 'pending', NOW())";
            
            $requestId = $this->db->query($sql, [':user_id' => $userId]);
            
            if (!$requestId) {
                return [
                    'success' => false,
                    'error' => 'Failed to create deletion request'
                ];
            }
            
            // إرسال إشعار للمسؤول
            $this->notifyAdmin('Data Deletion Request', [
                'user_id' => $userId,
                'request_id' => $requestId
            ]);
            
            return [
                'success' => true,
                'request_id' => $requestId,
                'message' => 'Deletion request submitted successfully. You will be notified when completed.'
            ];
            
        } catch (Exception $e) {
            Logger::error('Request Data Deletion Error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * تنفيذ حذف البيانات
     * @param int $userId
     * @return bool
     */
    public function executeDataDeletion(int $userId): bool {
        try {
            $this->db->beginTransaction();
            
            // 1. حذف أو تعمية البيانات الشخصية
            $tables = [
                'users' => ['email', 'phone', 'company_name'],
                'chat_messages' => ['customer_name', 'customer_phone', 'customer_email'],
                'reviews' => ['reviewer_name', 'reviewer_email', 'reviewer_phone']
            ];
            
            foreach ($tables as $table => $fields) {
                $updates = [];
                foreach ($fields as $field) {
                    $updates[] = "`{$field}` = 'DELETED_' || id";
                }
                $sql = "UPDATE {$table} SET " . implode(', ', $updates) . " WHERE user_id = :user_id";
                $this->db->query($sql, [':user_id' => $userId]);
            }
            
            // 2. تحديث حالة طلب الحذف
            $sql = "UPDATE gdpr_deletion_requests 
                    SET status = 'completed', 
                        completed_date = NOW() 
                    WHERE user_id = :user_id 
                    AND status = 'pending'";
            
            $this->db->query($sql, [':user_id' => $userId]);
            
            // 3. تسجيل الخروج القسري
            $this->forceLogout($userId);
            
            $this->db->commit();
            
            Logger::info('Data Deletion Executed', ['user_id' => $userId]);
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollback();
            Logger::error('Execute Data Deletion Error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * تصدير بيانات المستخدم (Right to Data Portability)
     * @param int $userId
     * @param string $format
     * @return array
     */
    public function exportUserData(int $userId, string $format = 'json'): array {
        try {
            $data = [
                'user' => $this->getUserData($userId),
                'subscriptions' => $this->getUserSubscriptions($userId),
                'websites' => $this->getUserWebsites($userId),
                'reviews' => $this->getUserReviews($userId),
                'chat_messages' => $this->getUserChatMessages($userId),
                'ai_reports' => $this->getUserAIReports($userId),
                'export_date' => date('Y-m-d H:i:s'),
                'export_format' => $format
            ];
            
            // تسجيل طلب التصدير
            $sql = "INSERT INTO gdpr_export_requests 
                    (user_id, format, status, created_at) 
                    VALUES 
                    (:user_id, :format, 'completed', NOW())";
            
            $this->db->query($sql, [
                ':user_id' => $userId,
                ':format' => $format
            ]);
            
            return [
                'success' => true,
                'data' => $data,
                'format' => $format,
                'filename' => "gdpr_export_{$userId}_" . date('Y-m-d') . ".{$format}"
            ];
            
        } catch (Exception $e) {
            Logger::error('Export User Data Error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * الحصول على بيانات المستخدم
     * @param int $userId
     * @return array
     */
    private function getUserData(int $userId): array {
        $sql = "SELECT id, company_name, email, phone, country, language, 
                       timezone, is_active, email_verified, created_at, updated_at 
                FROM users WHERE id = :user_id LIMIT 1";
        
        $result = $this->db->query($sql, [':user_id' => $userId]);
        return $result[0] ?? [];
    }
    
    /**
     * الحصول على اشتراكات المستخدم
     * @param int $userId
     * @return array
     */
    private function getUserSubscriptions(int $userId): array {
        $sql = "SELECT * FROM subscriptions WHERE user_id = :user_id ORDER BY created_at DESC";
        return $this->db->query($sql, [':user_id' => $userId]);
    }
    
    /**
     * الحصول على مواقع المستخدم
     * @param int $userId
     * @return array
     */
    private function getUserWebsites(int $userId): array {
        $sql = "SELECT * FROM websites WHERE user_id = :user_id ORDER BY created_at DESC";
        return $this->db->query($sql, [':user_id' => $userId]);
    }
    
    /**
     * الحصول على مراجعات المستخدم
     * @param int $userId
     * @return array
     */
    private function getUserReviews(int $userId): array {
        $sql = "SELECT id, platform, rating, sentiment_label, review_text, 
                       auto_reply_generated, created_at 
                FROM reviews WHERE user_id = :user_id ORDER BY created_at DESC";
        return $this->db->query($sql, [':user_id' => $userId]);
    }
    
    /**
     * الحصول على رسائل الشات
     * @param int $userId
     * @return array
     */
    private function getUserChatMessages(int $userId): array {
        $sql = "SELECT id, platform, message_direction, message_text, 
                       ai_reply_generated, bot_status, created_at 
                FROM chat_messages WHERE user_id = :user_id ORDER BY created_at DESC";
        return $this->db->query($sql, [':user_id' => $userId]);
    }
    
    /**
     * الحصول على تقارير AI
     * @param int $userId
     * @return array
     */
    private function getUserAIReports(int $userId): array {
        $sql = "SELECT id, report_type, target_url, analysis_score, 
                       keywords_found, status, created_at 
                FROM ai_reports WHERE user_id = :user_id ORDER BY created_at DESC";
        return $this->db->query($sql, [':user_id' => $userId]);
    }
    
    /**
     * تسجيل الخروج القسري
     * @param int $userId
     */
    private function forceLogout(int $userId): void {
        $sql = "UPDATE users SET remember_token = NULL, api_token = NULL WHERE id = :user_id";
        $this->db->query($sql, [':user_id' => $userId]);
        
        $sql = "DELETE FROM sessions WHERE user_id = :user_id";
        $this->db->query($sql, [':user_id' => $userId]);
    }
    
    /**
     * إرسال إشعار للمسؤول
     * @param string $subject
     * @param array $data
     */
    private function notifyAdmin(string $subject, array $data): void {
        // تنفيذ إرسال الإشعار
        Logger::info('GDPR Admin Notification', [
            'subject' => $subject,
            'data' => $data
        ]);
    }
    
    /**
     * التحقق من صلاحية البيانات
     * @param int $userId
     * @param string $dataType
     * @return bool
     */
    public function validateDataAccess(int $userId, string $dataType): bool {
        // التحقق من وجود موافقة صريحة
        if (!$this->hasConsent($userId, 'data_processing')) {
            return false;
        }
        
        // التحقق من وجود موافقة للنوع المحدد
        $consentType = "data_access_{$dataType}";
        return $this->hasConsent($userId, $consentType);
    }
}
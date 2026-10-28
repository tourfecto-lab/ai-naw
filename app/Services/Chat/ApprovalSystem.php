<?php

/**
 * Tourfecto - Approval System
 * نظام إدارة الموافقات على ردود البوت (Human-in-the-Loop)
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class ApprovalSystem
{
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;

    /**
     * @var NotificationService $notificationService - خدمة الإشعارات
     */
    private $notificationService;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->notificationService = new NotificationService();
    }

    /**
     * إضافة طلب موافقة جديد
     * @param int $messageId - معرف الرسالة
     * @param int $userId - معرف المستخدم
     * @return bool
     */
    public function addPendingApproval(int $messageId, int $userId): bool
    {
        try {
            // التحقق من عدم وجود طلب مسبق
            $sql = "SELECT id FROM chat_messages 
                    WHERE id = :message_id 
                    AND bot_status = 'pending_approval'";

            $result = $this->db->query($sql, [':message_id' => $messageId]);

            if (!empty($result)) {
                return true;
            }

            // تحديث حالة الرسالة
            $sql = "UPDATE chat_messages 
                    SET bot_status = 'pending_approval',
                        updated_at = NOW()
                    WHERE id = :message_id";

            $this->db->query($sql, [':message_id' => $messageId]);

            // إرسال إشعار للمستخدم
            $this->notificationService->sendApprovalNotification($userId, $messageId);

            return true;

        } catch (Exception $e) {
            Logger::error('Add Pending Approval Error', [
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * الموافقة على رد
     * @param int $messageId - معرف الرسالة
     * @param int $userId - معرف المستخدم الموافق
     * @return array
     */
    public function approve(int $messageId, int $userId): array
    {
        return (new ApprovedReplyDelivery())->approve($messageId,$userId);
    }

    /**
     * رفض رد
     * @param int $messageId - معرف الرسالة
     * @param int $userId - معرف المستخدم الرافض
     * @param string $reason - سبب الرفض
     * @return array
     */
    public function reject(int $messageId, int $userId, string $reason = ''): array
    {
        $lock='approved-reply:'.$userId.':'.$messageId;
        if(!ExternalExecution::lock($lock))return ['success'=>false,'error'=>'Reply approval is busy'];
        try {
            $sql = "SELECT * FROM chat_messages 
                    WHERE id = :message_id 
                    AND bot_status = 'pending_approval' AND user_id = :user_id";

            $message = $this->db->query($sql, [':message_id' => $messageId, ':user_id' => $userId]);

            if (empty($message)) {
                return [
                    'success' => false,
                    'error' => 'Message not found or not pending approval.'
                ];
            }

            $sql = "UPDATE chat_messages 
                    SET bot_status = 'rejected',
                        approved_by_user_id = :user_id,
                        approved_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :message_id AND user_id = :owner_id AND bot_status = 'pending_approval'";

            $this->db->query($sql, [
                ':message_id' => $messageId,
                ':user_id' => $userId,
                ':owner_id' => $userId
            ]);

            // تسجيل سبب الرفض
            if ($reason) {
                $sql = "INSERT INTO chat_approval_logs 
                        (message_id, user_id, action, reason, created_at) 
                        VALUES 
                        (:message_id, :user_id, 'rejected', :reason, NOW())";

                $this->db->query($sql, [
                    ':message_id' => $messageId,
                    ':user_id' => $userId,
                    ':reason' => $reason
                ]);
            }

            Logger::info('Message Rejected', [
                'message_id' => $messageId,
                'user_id' => $userId,
                'reason' => $reason
            ]);

            return [
                'success' => true,
                'message_id' => $messageId,
                'status' => 'rejected',
                'message' => 'Message rejected.'
            ];

        } catch (Exception $e) {
            Logger::error('Reject Error', [
                'message_id' => $messageId,
                'error' => 'Could not reject the reply. Try again.'
            ]);

            return [
                'success' => false,
                'error' => 'Could not reject the reply. Try again.'
            ];
        } finally { ExternalExecution::unlock($lock); }
    }

    /**
     * تحديث حالة الرسالة إلى مرسلة
     * @param int $messageId
     */
    public function markAsSent(int $messageId): void
    {
        try {
            $sql = "UPDATE chat_messages 
                    SET bot_status = 'sent',
                        sent_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :message_id";

            $this->db->query($sql, [':message_id' => $messageId]);

        } catch (Exception $e) {
            Logger::error('Mark As Sent Error', [
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * الحصول على قائمة طلبات الموافقة المعلقة
     * @param int $userId - معرف المستخدم
     * @param int $limit - عدد النتائج
     * @return array
     */
    public function getPendingApprovals(int $userId, int $limit = 50): array
    {
        try {
            $urlCol = Website::urlColumn();
            $sql = "SELECT 
                        cm.*,
                        u.company_name,
                        w.{$urlCol} AS main_url
                    FROM chat_messages cm
                    JOIN users u ON cm.user_id = u.id
                    JOIN websites w ON cm.website_id = w.id
                    WHERE cm.user_id = :user_id
                    AND cm.bot_status = 'pending_approval'
                    AND cm.message_direction = 'incoming'
                    ORDER BY cm.created_at ASC
                    LIMIT :limit";

            $results = $this->db->query($sql, [
                ':user_id' => $userId,
                ':limit' => $limit
            ]);

            // فك تشفير البيانات الحساسة
            $encryption = new Encryption();
            foreach ($results as &$row) {
                if (!empty($row['encrypted_phone'])) {
                    $row['customer_phone'] = $encryption->decryptCustomerData(
                        $row['encrypted_phone'],
                        $row['customer_phone'] ?? ''
                    );
                }
                unset($row['encrypted_phone']);
                unset($row['encrypted_email']);
            }

            return $results;

        } catch (Exception $e) {
            Logger::error('Get Pending Approvals Error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * الحصول على عدد طلبات الموافقة المعلقة
     * @param int $userId
     * @return int
     */
    public function getPendingCount(int $userId): int
    {
        try {
            $sql = "SELECT COUNT(*) as count 
                    FROM chat_messages 
                    WHERE user_id = :user_id 
                    AND bot_status = 'pending_approval'
                    AND message_direction = 'incoming'";

            $result = $this->db->query($sql, [':user_id' => $userId]);

            return (int) ($result[0]['count'] ?? 0);

        } catch (Exception $e) {
            Logger::error('Get Pending Count Error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
}

/**
 * Class NotificationService - خدمة الإشعارات (داخل نفس الملف)
 */
class NotificationService
{
    /**
     * @var Database $db
     */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * إرسال إشعار موافقة
     * @param int $userId
     * @param int $messageId
     */
    public function sendApprovalNotification(int $userId, int $messageId): void
    {
        try {
            // تصحيح: كان بس بيسجّل في app.log ومفيش أي إشعار حقيقي بيوصل
            // للعميل - يعني جرس الإشعارات في الداشبورد فاضي دايمًا حتى لو
            // فيه رسايل واتساب مستنية موافقته.
            if (class_exists('Notification')) {
                Notification::notify(
                    $userId,
                    'chat_pending_approval',
                    'A new WhatsApp reply needs your approval',
                    'A customer sent a message. Review the suggested reply before sending.',
                    '/chat/pending'
                );
            }

            Logger::info('Approval Notification Sent', [
                'user_id' => $userId,
                'message_id' => $messageId
            ]);

        } catch (Exception $e) {
            // تجاهل
        }
    }
}

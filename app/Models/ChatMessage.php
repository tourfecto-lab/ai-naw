<?php

/**
 * Tourfecto - Chat Message Model
 * نموذج رسائل الشات مع نظام الموافقات
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class ChatMessage extends Model
{
    /**
     * @var string $table - اسم الجدول
     */
    protected $table = 'chat_messages';

    /**
     * @var array $fillable - الحقول القابلة للتعبئة
     */
    protected $fillable = [
        'website_id',
        'conversation_id',
        'user_id',
        'session_id',
        'platform',
        'platform_message_id',
        'customer_name',
        'customer_phone',
        'customer_email',
        'encrypted_phone',
        'encrypted_email',
        'message_direction',
        'message_text',
        'message_language',
        'ai_reply_generated',
        'ai_reply_language',
        'ai_confidence_score',
        'bot_status',
        'is_auto_pilot',
        'approved_by_user_id',
        'approved_at',
        'sent_at',
        'webhook_raw_data',
        'ip_address',
        'user_agent'
    ];

    /**
     * @var Encryption $encryption - نظام التشفير
     */
    private $encryption;

    /**
     * Constructor
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->encryption = new Encryption();
    }

    /**
     * تشفير البيانات الحساسة قبل الحفظ
     * @return int|bool
     */
    public function save()
    {
        // تشفير رقم الهاتف إذا لم يكن مشفراً بالفعل
        if (!empty($this->attributes['customer_phone']) && empty($this->attributes['encrypted_phone'])) {
            $this->attributes['encrypted_phone'] = $this->encryption->encryptCustomerData(
                $this->attributes['customer_phone'],
                $this->attributes['customer_phone']
            );
        }

        if (!empty($this->attributes['customer_email']) && empty($this->attributes['encrypted_email'])) {
            $this->attributes['encrypted_email'] = $this->encryption->encryptCustomerData(
                $this->attributes['customer_email'],
                $this->attributes['customer_phone'] ?? ''
            );
        }

        return parent::save();
    }

    /**
     * فك تشفير البيانات الحساسة عند القراءة
     * @param mixed $id
     * @return ChatMessage|null
     */
    public function find($id): ?self
    {
        $message = parent::find($id);

        if ($message) {
            $message->decryptSensitiveData();
        }

        return $message;
    }

    /**
     * فك تشفير البيانات الحساسة
     */
    private function decryptSensitiveData(): void
    {
        // فك تشفير رقم الهاتف من الحقل المشفر
        if (!empty($this->attributes['encrypted_phone'])) {
            $this->attributes['customer_phone'] = $this->encryption->decryptCustomerData(
                $this->attributes['encrypted_phone'],
                $this->attributes['customer_phone'] ?? ''
            );
        }

        // فك تشفير البريد الإلكتروني من الحقل المشفر
        if (!empty($this->attributes['encrypted_email'])) {
            $this->attributes['customer_email'] = $this->encryption->decryptCustomerData(
                $this->attributes['encrypted_email'],
                $this->attributes['customer_phone'] ?? ''
            );
        }
    }

    /**
     * الحصول على الموقع
     * @return Website|null
     */
    public function getWebsite(): ?Website
    {
        $sql = "SELECT * FROM websites WHERE id = ? LIMIT 1";
        $result = $this->db->query($sql, [$this->attributes['website_id']]);

        if (empty($result)) {
            return null;
        }

        return new Website($result[0]);
    }

    /**
     * الحصول على المستخدم
     * @return User|null
     */
    public function getUser(): ?User
    {
        $sql = "SELECT * FROM users WHERE id = ? LIMIT 1";
        $result = $this->db->query($sql, [$this->attributes['user_id']]);

        if (empty($result)) {
            return null;
        }

        return new User($result[0]);
    }

    /**
     * الحصول على المستخدم المعتمد
     * @return User|null
     */
    public function getApprovedBy(): ?User
    {
        if (empty($this->attributes['approved_by_user_id'])) {
            return null;
        }

        $sql = "SELECT * FROM users WHERE id = ? LIMIT 1";
        $result = $this->db->query($sql, [$this->attributes['approved_by_user_id']]);

        if (empty($result)) {
            return null;
        }

        return new User($result[0]);
    }

    /**
     * الموافقة على الرسالة
     * @param int $userId
     * @return bool
     */
    public function approve(int $userId): bool
    {
        if ($this->attributes['bot_status'] !== 'pending_approval') {
            return false;
        }

        $this->attributes['bot_status'] = 'approved';
        $this->attributes['approved_by_user_id'] = $userId;
        $this->attributes['approved_at'] = date('Y-m-d H:i:s');

        return $this->save() !== false;
    }

    /**
     * رفض الرسالة
     * @param int $userId
     * @return bool
     */
    public function reject(int $userId): bool
    {
        if ($this->attributes['bot_status'] !== 'pending_approval') {
            return false;
        }

        $this->attributes['bot_status'] = 'rejected';
        $this->attributes['approved_by_user_id'] = $userId;
        $this->attributes['approved_at'] = date('Y-m-d H:i:s');

        return $this->save() !== false;
    }

    /**
     * تحديث حالة الإرسال
     * @return bool
     */
    public function markAsSent(): bool
    {
        $this->attributes['bot_status'] = 'sent';
        $this->attributes['sent_at'] = date('Y-m-d H:i:s');

        return $this->save() !== false;
    }

    /**
     * تحديث حالة الفشل
     * @return bool
     */
    public function markAsFailed(): bool
    {
        $this->attributes['bot_status'] = 'failed';

        return $this->save() !== false;
    }

    /**
     * الحصول على رسائل في انتظار الموافقة
     * @param int $userId
     * @param int $limit
     * @return array
     */
    public static function getPendingApprovals(int $userId, int $limit = 50): array
    {
        $db = Database::getInstance();

        $sql = "SELECT * FROM chat_messages 
                WHERE user_id = ? 
                AND bot_status = 'pending_approval'
                AND message_direction = 'incoming'
                ORDER BY created_at ASC 
                LIMIT ?";

        $result = $db->query($sql, [$userId, $limit]);

        return array_map(function ($data) {
            $message = new static($data);
            $message->decryptSensitiveData();
            return $message;
        }, $result);
    }

    /**
     * الحصول على عدد الرسائل غير المقروءة
     * @param int $userId
     * @return int
     */
    public static function getUnreadCount(int $userId): int
    {
        $db = Database::getInstance();

        $sql = "SELECT COUNT(*) as count FROM chat_messages 
                WHERE user_id = ? 
                AND bot_status = 'pending_approval'
                AND message_direction = 'incoming'";

        $result = $db->query($sql, [$userId]);
        return (int) ($result[0]['count'] ?? 0);
    }
}

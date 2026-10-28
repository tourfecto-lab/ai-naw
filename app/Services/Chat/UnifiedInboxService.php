<?php

/**
 * Tourfecto - AI Chat Platform
 * Unified Inbox Service (بند 1): يدير دورة حياة "المحادثة الموحدة" فوق
 * جدول chat_messages الموجود، بغضّ النظر عن القناة (WhatsApp/Website/
 * Messenger/Instagram/Email).
 *
 * هذا الكلاس هو نقطة الدخول الوحيدة لإنشاء/تحديث سجلات ai_conversations؛
 * لا يجب على أي كود آخر أن يكتب في هذا الجدول مباشرة.
 *
 * @version 1.0.0
 */

class UnifiedInboxService
{
    /** الوسوم الجاهزة القياسية (بند 11) - غير قابلة للحذف، فوقها Custom Tags */
    public const STANDARD_TAGS = [
        'HOT_LEAD', 'NEW_INQUIRY', 'PRICE_REQUEST', 'COMPLAINT',
        'FOLLOW_UP', 'BOOKING_INTENT', 'VIP', 'HUMAN_REQUIRED',
    ];

    /** @var AiChatConversation */
    private $conversationModel;

    /** @var Database */
    private $db;

    public function __construct()
    {
        $this->conversationModel = new AiChatConversation();
        $this->db = Database::getInstance();
    }

    /**
     * مفتاح ثابت لتوحيد هوية العميل (يُستخدم في ai_conversations.customer_key
     * و ai_customer_memory.customer_key) - نفس العميل بنفس الرقم/الإيميل على
     * نفس الموقع يحصل على نفس المفتاح حتى لو القناة اختلفت لاحقًا.
     * @param int $websiteId
     * @param string|null $phone
     * @param string|null $email
     * @return string
     */
    public function buildCustomerKey(int $websiteId, ?string $phone, ?string $email): string
    {
        $identifier = '';
        if (!empty($phone)) {
            $identifier = 'phone:' . preg_replace('/\D+/', '', $phone);
        } elseif (!empty($email)) {
            $identifier = 'email:' . strtolower(trim($email));
        } else {
            $identifier = 'anon:' . uniqid('', true);
        }
        return hash('sha256', $websiteId . '|' . $identifier);
    }

    /**
     * إيجاد محادثة موحدة موجودة، أو إنشاء واحدة جديدة، لعميل على قناة معيّنة.
     * يُستدعى عند استقبال أي رسالة واردة من أي قناة.
     *
     * @param int $websiteId
     * @param int $userId
     * @param string $channel website_chat|whatsapp|messenger|instagram|email
     * @param string $channelThreadId رقم الهاتف أو معرف الـthread الخارجي
     * @param array $customerInfo ['name','phone','email']
     * @return AiChatConversation
     */
    public function findOrCreateConversation(
        int $websiteId,
        int $userId,
        string $channel,
        string $channelThreadId,
        array $customerInfo = []
    ): AiChatConversation {
        $existing = $this->conversationModel->findByChannelThread($websiteId, $channel, $channelThreadId);
        if ($existing) {
            return $existing;
        }

        $customerKey = $this->buildCustomerKey($websiteId, $customerInfo['phone'] ?? null, $customerInfo['email'] ?? null);

        $conversation = new AiChatConversation();
        $conversation->fill([
            'website_id' => $websiteId,
            'user_id' => $userId,
            'channel' => $channel,
            'channel_thread_id' => $channelThreadId,
            'customer_name' => $customerInfo['name'] ?? null,
            'customer_phone' => $customerInfo['phone'] ?? null,
            'customer_email' => $customerInfo['email'] ?? null,
            'customer_key' => $customerKey,
            'status' => 'open',
            'ai_status' => 'ai',
            'lead_status' => 'new_inquiry',
            'priority' => 'normal',
            'tags' => json_encode(['NEW_INQUIRY']),
            'unread_count' => 0,
        ]);
        $conversation->save();

        try {
            Notification::notify(
                $userId,
                'ai_chat_new_conversation',
                'New AI Chat conversation',
                'New ' . $channel . ' conversation from ' . ($customerInfo['name'] ?: ($customerInfo['phone'] ?? 'a customer')),
                '/ai-chat/conversations/' . $conversation->getAttribute('id')
            );
        } catch (Exception $e) {
            Logger::warning('UnifiedInboxService: notification failed', ['error' => $e->getMessage()]);
        }

        return $conversation;
    }

    /**
     * ربط رسالة موجودة في chat_messages بالمحادثة الموحدة، وتحديث مؤشرات
     * وقت آخر رسالة (يُستخدم لاحقًا في Follow-up Automation - بند 7).
     * @param int $messageId
     * @param int $conversationId
     * @param bool $isCustomerMessage
     */
    public function linkMessage(int $messageId, int $conversationId, bool $isCustomerMessage): void
    {
        try {
            $this->db->query(
                "UPDATE chat_messages SET conversation_id = ? WHERE id = ?",
                [$conversationId, $messageId]
            );

            $updates = ['last_message_at' => date('Y-m-d H:i:s')];
            if ($isCustomerMessage) {
                $updates['last_customer_message_at'] = date('Y-m-d H:i:s');
                $updates['unread_count'] = $this->incrementUnread($conversationId);
            }

            $this->updateConversation($conversationId, $updates);
        } catch (Exception $e) {
            Logger::warning('UnifiedInboxService: failed to link message', [
                'message_id' => $messageId, 'conversation_id' => $conversationId, 'error' => $e->getMessage(),
            ]);
        }
    }

    private function incrementUnread(int $conversationId): int
    {
        $row = $this->db->query("SELECT unread_count FROM ai_conversations WHERE id = ?", [$conversationId]);
        $current = !empty($row) ? (int) $row[0]['unread_count'] : 0;
        return $current + 1;
    }

    /**
     * تحديث حقول عامة على المحادثة (استخدام داخلي/من AIConversationEngine).
     * @param int $conversationId
     * @param array $fields
     * @return bool
     */
    public function updateConversation(int $conversationId, array $fields): bool
    {
        $conversation = $this->conversationModel->find($conversationId);
        if (!$conversation) {
            return false;
        }
        $conversation->fill($fields);
        return $conversation->save() !== false;
    }

    /**
     * إضافة Tags لمحادثة (بدون تكرار)، مع دمج الجديد بالموجود.
     * @param int $conversationId
     * @param array $newTags
     * @return bool
     */
    public function addTags(int $conversationId, array $newTags): bool
    {
        $conversation = $this->conversationModel->find($conversationId);
        if (!$conversation) {
            return false;
        }
        $existing = json_decode((string) $conversation->getAttribute('tags'), true) ?: [];
        $merged = array_values(array_unique(array_merge($existing, $newTags)));
        $conversation->fill(['tags' => json_encode($merged)]);
        $result = $conversation->save() !== false;

        $newlyAdded = array_diff($newTags, $existing);
        foreach (array_intersect($newlyAdded, ['COMPLAINT', 'HOT_LEAD']) as $notifyTag) {
            try {
                Notification::notify(
                    (int) $conversation->getAttribute('user_id'),
                    $notifyTag === 'COMPLAINT' ? 'ai_chat_complaint' : 'ai_chat_hot_lead',
                    $notifyTag === 'COMPLAINT' ? 'Customer complaint detected' : 'Hot lead detected',
                    'Conversation #' . $conversationId,
                    '/ai-chat/conversations/' . $conversationId
                );
            } catch (Exception $e) {
                Logger::warning('UnifiedInboxService: tag notification failed', ['error' => $e->getMessage()]);
            }
        }

        return $result;
    }

    /**
     * تحويل المحادثة إلى موظف (Human Handoff - بند 8). بمجرد التحويل يجب
     * أن يتوقف الـAI عن الرد تلقائيًا (يتحقق منها AIConversationEngine
     * والـChatManager قبل توليد أي رد جديد).
     * @param int $conversationId
     * @param string $reason
     * @param int|null $assignToAgentId
     * @return bool
     */
    public function handoffToHuman(int $conversationId, string $reason, ?int $assignToAgentId = null): bool
    {
        $fields = [
            'ai_status' => 'human',
            'handoff_reason' => $reason,
            'handoff_at' => date('Y-m-d H:i:s'),
        ];
        if ($assignToAgentId) {
            $fields['assigned_agent_id'] = $assignToAgentId;
        }
        $this->addTags($conversationId, ['HUMAN_REQUIRED']);
        $result = $this->updateConversation($conversationId, $fields);

        try {
            $conversation = $this->conversationModel->find($conversationId);
            if ($conversation) {
                $type = $reason === 'ai_provider_failure' ? 'ai_chat_ai_failure' : 'ai_chat_human_handoff';
                $title = $reason === 'ai_provider_failure' ? 'AI Chat provider failure' : 'Conversation needs a human agent';
                Notification::notify(
                    (int) $conversation->getAttribute('user_id'),
                    $type,
                    $title,
                    'Reason: ' . $reason,
                    '/ai-chat/conversations/' . $conversationId
                );
            }
        } catch (Exception $e) {
            Logger::warning('UnifiedInboxService: handoff notification failed', ['error' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * إعادة تفعيل الـAI بعد أن كان محوَّلاً لموظف.
     * @param int $conversationId
     * @return bool
     */
    public function resumeAI(int $conversationId): bool
    {
        return $this->updateConversation($conversationId, [
            'ai_status' => 'ai', 'handoff_reason' => null, 'handoff_at' => null,
        ]);
    }

    /**
     * فحص Stop Conditions قبل أي محاولة رد أو متابعة تلقائية (بند 7 و8):
     * العميل طلب عدم التواصل، أو تم التحويل لموظف، أو الحالة مغلقة.
     * @param int $conversationId
     * @return bool true لو الرد الآلي يجب أن يتوقف
     */
    public function shouldStopAutomation(int $conversationId): bool
    {
        $conversation = $this->conversationModel->find($conversationId);
        if (!$conversation) {
            return true;
        }
        return (bool) $conversation->getAttribute('do_not_contact')
            || $conversation->getAttribute('ai_status') !== 'ai'
            || $conversation->getAttribute('status') === 'closed';
    }

    /**
     * تسجيل طلب العميل عدم التواصل (Stop Condition دائم).
     * @param int $conversationId
     * @return bool
     */
    public function markDoNotContact(int $conversationId): bool
    {
        return $this->updateConversation($conversationId, ['do_not_contact' => 1, 'status' => 'closed']);
    }

    /**
     * البحث والفلترة داخل Unified Inbox (بند 15 و16).
     * @param int $websiteId
     * @param array $filters ['status','ai_status','lead_status','channel','priority','tag','search','assigned_agent_id']
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function search(int $websiteId, array $filters = [], int $limit = 30, int $offset = 0): array
    {
        $where = ['website_id = ?'];
        $params = [$websiteId];

        foreach (['status', 'ai_status', 'lead_status', 'channel', 'priority', 'assigned_agent_id'] as $key) {
            if (!empty($filters[$key])) {
                $where[] = "{$key} = ?";
                $params[] = $filters[$key];
            }
        }

        if (!empty($filters['tag'])) {
            $where[] = "JSON_CONTAINS(tags, JSON_QUOTE(?))";
            $params[] = $filters['tag'];
        }

        if (!empty($filters['search'])) {
            $where[] = "(customer_name LIKE ? OR customer_phone LIKE ? OR customer_email LIKE ? OR ai_summary LIKE ?)";
            $term = '%' . $filters['search'] . '%';
            array_push($params, $term, $term, $term, $term);
        }

        if (!empty($filters['unread_only'])) {
            $where[] = "unread_count > 0";
        }

        $sql = "SELECT * FROM ai_conversations WHERE " . implode(' AND ', $where)
            . " ORDER BY last_message_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $rows = $this->db->query($sql, $params);
        return array_map(function ($row) {
            return new AiChatConversation($row);
        }, $rows);
    }
}

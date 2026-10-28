<?php
/**
 * Tourfecto - AI Chat Platform
 * محادثة موحدة (Unified Inbox) - بند 1.
 *
 * ملاحظة تسمية مهمة: الاسم AiChatConversation (وليس AiConversation) عمدًا،
 * لأن app/Models/AiConversation.php موجود بالفعل ويشير لجدول مختلف تمامًا
 * (ai_assistant_conversations - المساعد الداخلي لأدمن المنصة). استخدام
 * نفس الاسم كان سيسبب تصادم أسماء كلاسات (Fatal Error) عبر classmap.
 *
 * @version 1.0.0
 */
class AiChatConversation extends Model {
    protected $table = 'ai_conversations';
    protected $fillable = [
        'website_id', 'user_id', 'channel', 'channel_thread_id',
        'customer_name', 'customer_phone', 'customer_email',
        'encrypted_phone', 'encrypted_email', 'customer_key',
        'status', 'ai_status', 'assigned_agent_id', 'handoff_reason',
        'handoff_at', 'lead_status', 'priority', 'tags', 'ai_summary',
        'ai_confidence_score', 'language', 'unread_count',
        'last_message_at', 'last_customer_message_at', 'do_not_contact',
    ];

    /**
     * Unified Inbox: كل المحادثات لموقع معيّن مع فلاتر اختيارية (بند 16).
     * @param int $websiteId
     * @param array $filters ['status','ai_status','lead_status','channel','priority']
     * @return array
     */
    public function forWebsite(int $websiteId, array $filters = []): array {
        $conditions = ['website_id' => $websiteId];
        foreach (['status', 'ai_status', 'lead_status', 'channel', 'priority', 'assigned_agent_id'] as $key) {
            if (!empty($filters[$key])) {
                $conditions[$key] = $filters[$key];
            }
        }
        return $this->where($conditions, ['last_message_at' => 'DESC']);
    }

    /**
     * إيجاد أو تجهيز محادثة موحدة لعميل معيّن على قناة معيّنة (يُستخدم عند
     * استقبال رسالة جديدة من أي قناة). لا ينشئ سجلاً بنفسه - فقط بحث.
     * @param int $websiteId
     * @param string $channel
     * @param string $channelThreadId
     * @return AiChatConversation|null
     */
    public function findByChannelThread(int $websiteId, string $channel, string $channelThreadId): ?self {
        $result = $this->where([
            'website_id' => $websiteId,
            'channel' => $channel,
            'channel_thread_id' => $channelThreadId,
        ], [], 1);

        return $result[0] ?? null;
    }
}

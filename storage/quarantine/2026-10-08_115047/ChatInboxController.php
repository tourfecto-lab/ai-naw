<?php

/**
 * Tourfecto - AI Chat Platform
 * Unified Inbox Controller (بند 1، 8، 15، 16): واجهة API للوحة الإدارة
 * لعرض كل المحادثات من كل القنوات في مكان واحد، والبحث/الفلترة، وتحويل
 * المحادثة لموظف والعكس، وإرسال رد يدوي من الموظف.
 *
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class ChatInboxController extends Controller
{
    /** @var UnifiedInboxService */
    private $inbox;

    /** @var AiChatConversation */
    private $conversationModel;

    /** @var AiReplySuggestionsService */
    private $replySuggestions;

    public function __construct()
    {
        parent::__construct();
        $this->inbox = new UnifiedInboxService();
        $this->conversationModel = new AiChatConversation();
        $this->replySuggestions = new AiReplySuggestionsService();
    }

    /**
     * قائمة المحادثات مع فلاتر وبحث (بند 15، 16).
     * GET /api/ai-chat/websites/{id}/conversations
     * Query: status, ai_status, lead_status, channel, priority, tag, search, page
     */
    public function index(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }

        $filters = [
            'status' => $this->get('status'),
            'ai_status' => $this->get('ai_status'),
            'lead_status' => $this->get('lead_status'),
            'channel' => $this->get('channel'),
            'priority' => $this->get('priority'),
            'tag' => $this->get('tag'),
            'search' => $this->get('search'),
            'assigned_agent_id' => $this->get('assigned_agent_id'),
            'unread_only' => $this->get('unread_only'),
        ];
        $filters = array_filter($filters, function ($v) {
            return $v !== null && $v !== '';
        });

        $page = max(1, (int) $this->get('page', 1));
        $limit = 30;
        $offset = ($page - 1) * $limit;

        $conversations = $this->inbox->search((int) $website->getAttribute('id'), $filters, $limit, $offset);

        return $this->success([
            'conversations' => array_map([$this, 'serializeConversation'], $conversations),
            'page' => $page,
        ]);
    }

    /**
     * تفاصيل محادثة واحدة: الرسائل + الملخص + الوسوم + حالة الـLead.
     * GET /api/ai-chat/websites/{id}/conversations/{conversationId}
     */
    public function show(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }

        $conversation = $this->authorizedConversation((int) ($params['conversationId'] ?? 0), (int) $website->getAttribute('id'));
        if (!$conversation) {
            return $this->error('Conversation not found', 404);
        }

        $db = Database::getInstance();
        $messages = $db->query(
            "SELECT id, message_direction, message_text, ai_reply_generated, ai_confidence_score,
                    bot_status, is_auto_pilot, sent_at, created_at
             FROM chat_messages WHERE conversation_id = ? ORDER BY created_at ASC LIMIT 200",
            [$conversation->getAttribute('id')]
        );

        // إعادة تفعيل عدّاد غير المقروء عند فتح الموظف للمحادثة
        $this->inbox->updateConversation((int) $conversation->getAttribute('id'), ['unread_count' => 0]);

        return $this->success([
            'conversation' => $this->serializeConversation($conversation, true),
            'messages' => $messages,
        ]);
    }

    /**
     * إرسال رد يدوي من موظف داخل محادثة (لا يمر على AI - رد بشري مباشر).
     * POST /api/ai-chat/websites/{id}/conversations/{conversationId}/reply
     * Body: message
     */
    public function reply(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }

        $conversation = $this->authorizedConversation((int) ($params['conversationId'] ?? 0), (int) $website->getAttribute('id'));
        if (!$conversation) {
            return $this->error('Conversation not found', 404);
        }

        $message = trim((string) $this->get('message', ''));
        if ($message === '') {
            return $this->error('message is required', 422);
        }

        $channel = (string) $conversation->getAttribute('channel');
        $recipient = $channel === 'email'
            ? $conversation->getAttribute('customer_email')
            : $conversation->getAttribute('customer_phone');

        $sent = false;
        if ($recipient) {
            $chatManager = new ChatManager();
            $sent = $chatManager->sendMessageForWebsite((int) $website->getAttribute('id'), $recipient, $message, $channel);
        }

        $db = Database::getInstance();
        $agentMessageId = $db->query(
            "INSERT INTO chat_messages (website_id, conversation_id, user_id, session_id, platform,
                customer_name, customer_phone, customer_email, message_direction, message_text,
                bot_status, is_auto_pilot, sent_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'outgoing', ?, 'sent', 0, NOW(), NOW())",
            [
                $website->getAttribute('id'),
                $conversation->getAttribute('id'),
                $this->user['id'],
                'agent_' . $conversation->getAttribute('id'),
                $channel,
                $conversation->getAttribute('customer_name'),
                $conversation->getAttribute('customer_phone'),
                $conversation->getAttribute('customer_email'),
                $message,
            ]
        );

        $this->inbox->updateConversation((int) $conversation->getAttribute('id'), [
            'last_message_at' => date('Y-m-d H:i:s'),
        ]);

        // مركز الأتمتة: رد الموظف اليدوي بيبقى مثال لأسلوب الردود الآلية
        if (class_exists('AutomationEngine')) {
            try {
                $last = $db->query(
                    "SELECT message_text FROM chat_messages WHERE conversation_id = ? AND message_direction <> 'outgoing' ORDER BY id DESC LIMIT 1",
                    [(int) $conversation->getAttribute('id')]
                );
                AutomationEngine::instance()->recordManualAction(
                    (int) $this->user['id'], 'chat_replies', 'default', 'chat_manual', (string) $agentMessageId,
                    $message, (string) ($last[0]['message_text'] ?? '')
                );
            } catch (Throwable $e) {
            }
        }

        return $this->success(['sent' => $sent], $sent ? 'Reply sent' : 'Reply saved but delivery to channel failed');
    }

    /**
     * تحويل المحادثة لموظف يدويًا (بند 8).
     * POST /api/ai-chat/websites/{id}/conversations/{conversationId}/handoff
     * Body: reason (اختياري)
     */
    public function handoff(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }

        $conversation = $this->authorizedConversation((int) ($params['conversationId'] ?? 0), (int) $website->getAttribute('id'));
        if (!$conversation) {
            return $this->error('Conversation not found', 404);
        }

        $reason = (string) $this->get('reason', 'manual_handoff');
        $this->inbox->handoffToHuman((int) $conversation->getAttribute('id'), $reason, (int) $this->user['id']);

        return $this->success([], 'Conversation handed off to human agent');
    }

    /**
     * إعادة تفعيل الـAI بعد التحويل اليدوي.
     * POST /api/ai-chat/websites/{id}/conversations/{conversationId}/resume-ai
     */
    public function resumeAI(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }

        $conversation = $this->authorizedConversation((int) ($params['conversationId'] ?? 0), (int) $website->getAttribute('id'));
        if (!$conversation) {
            return $this->error('Conversation not found', 404);
        }

        $this->inbox->resumeAI((int) $conversation->getAttribute('id'));

        return $this->success([], 'AI resumed for this conversation');
    }

    /**
     * تحديث حالة/أولوية/تعيين/وسوم المحادثة يدويًا.
     * PUT /api/ai-chat/websites/{id}/conversations/{conversationId}
     * Body: status, priority, assigned_agent_id, tags (array), do_not_contact
     */
    public function update(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }

        $conversation = $this->authorizedConversation((int) ($params['conversationId'] ?? 0), (int) $website->getAttribute('id'));
        if (!$conversation) {
            return $this->error('Conversation not found', 404);
        }

        $updatable = ['status', 'priority', 'assigned_agent_id', 'do_not_contact'];
        $fields = array_intersect_key($this->all(), array_flip($updatable));

        if (($tags = $this->get('tags')) !== null && is_array($tags)) {
            $fields['tags'] = json_encode(array_values(array_unique($tags)));
        }

        if (empty($fields)) {
            return $this->error('No valid fields to update', 422);
        }

        $this->inbox->updateConversation((int) $conversation->getAttribute('id'), $fields);

        // Learning Loop: عند إغلاق/حل المحادثة نسجّل نتيجتها (هل حلها الـAI
        // أم أحيلت لموظف؟) لتحسين معدلات الحل مستقبلًا (Zendesk/Fin).
        if (in_array((string) ($fields['status'] ?? ''), ['resolved', 'closed'], true)) {
            try {
                (new LearningLoopService())->recordResolutionForClosedConversation((int) $conversation->getAttribute('id'));
            } catch (Exception $e) {
                Logger::warning('ChatInboxController: resolution recording failed', ['error' => $e->getMessage()]);
            }
        }

        return $this->success([], 'Conversation updated');
    }

    /**
     * اقتراحات ردود جاهزة للموظف (بند 12) - لا يُرسَل أي شيء تلقائيًا.
     * GET /api/ai-chat/websites/{id}/conversations/{conversationId}/reply-suggestions
     */
    public function suggestReplies(array $params = []): array
    {
        if (!$this->authenticated) {
            return $this->error('Unauthorized', 401);
        }

        $website = $this->authorizedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('Website not found', 404);
        }

        $conversation = $this->authorizedConversation((int) ($params['conversationId'] ?? 0), (int) $website->getAttribute('id'));
        if (!$conversation) {
            return $this->error('Conversation not found', 404);
        }

        $result = $this->replySuggestions->suggestFor(
            (int) $website->getAttribute('id'),
            (int) $this->user['id'],
            (int) $conversation->getAttribute('id')
        );

        if ($result['error']) {
            return $this->error($result['error'], 422);
        }

        return $this->success(['suggestions' => $result['suggestions']]);
    }

    /**
     * @param int $websiteId
     * @return Website|null
     */
    private function authorizedWebsite(int $websiteId): ?Website
    {
        if ($websiteId <= 0) {
            return null;
        }
        $website = (new Website())->find($websiteId);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->user['id']) {
            return null;
        }
        return $website;
    }

    /**
     * @param int $conversationId
     * @param int $websiteId
     * @return AiChatConversation|null
     */
    private function authorizedConversation(int $conversationId, int $websiteId): ?AiChatConversation
    {
        if ($conversationId <= 0) {
            return null;
        }
        $conversation = $this->conversationModel->find($conversationId);
        if (!$conversation || (int) $conversation->getAttribute('website_id') !== $websiteId) {
            return null;
        }
        return $conversation;
    }

    /**
     * @param AiChatConversation $conversation
     * @param bool $detailed
     * @return array
     */
    private function serializeConversation(AiChatConversation $conversation, bool $detailed = false): array
    {
        $data = [
            'id' => $conversation->getAttribute('id'),
            'channel' => $conversation->getAttribute('channel'),
            'customer_name' => $conversation->getAttribute('customer_name'),
            'customer_phone' => $conversation->getAttribute('customer_phone'),
            'customer_email' => $conversation->getAttribute('customer_email'),
            'status' => $conversation->getAttribute('status'),
            'ai_status' => $conversation->getAttribute('ai_status'),
            'lead_status' => $conversation->getAttribute('lead_status'),
            'priority' => $conversation->getAttribute('priority'),
            'tags' => json_decode((string) $conversation->getAttribute('tags'), true) ?: [],
            'unread_count' => (int) $conversation->getAttribute('unread_count'),
            'assigned_agent_id' => $conversation->getAttribute('assigned_agent_id'),
            'last_message_at' => $conversation->getAttribute('last_message_at'),
        ];

        if ($detailed) {
            $data['ai_summary'] = $conversation->getAttribute('ai_summary');
            $data['ai_confidence_score'] = $conversation->getAttribute('ai_confidence_score');
            $data['handoff_reason'] = $conversation->getAttribute('handoff_reason');
            $data['handoff_at'] = $conversation->getAttribute('handoff_at');
            $data['language'] = $conversation->getAttribute('language');
            $data['do_not_contact'] = (bool) $conversation->getAttribute('do_not_contact');
            $data['created_at'] = $conversation->getAttribute('created_at');
            $data['next_recommended_action'] = $conversation->getAttribute('next_recommended_action');
        }

        return $data;
    }
}

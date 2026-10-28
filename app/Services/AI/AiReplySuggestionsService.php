<?php

/**
 * Tourfecto - AI Chat Platform
 * AI Reply Suggestions (بند 12): حتى بعد تحويل المحادثة لموظف، الـAI
 * يقترح للموظف حتى 3 ردود جاهزة مبنية على قاعدة المعرفة وسياق المحادثة
 * - الموظف يختار أو يعدّل أو يرفض، لا يُرسَل أي شيء تلقائيًا من هنا.
 *
 * @version 1.0.0
 */

class AiReplySuggestionsService
{
    public const HISTORY_LIMIT = 10;

    /** @var KnowledgeBaseService */
    private $knowledgeBase;

    /** @var AIProviderManager */
    private $providerManager;

    /** @var AiChatConversation */
    private $conversationModel;

    /** @var Database */
    private $db;

    public function __construct()
    {
        $this->knowledgeBase = new KnowledgeBaseService();
        $this->providerManager = new AIProviderManager();
        $this->conversationModel = new AiChatConversation();
        $this->db = Database::getInstance();
    }

    /**
     * @param int $websiteId
     * @param int $userId
     * @param int $conversationId
     * @return array ['suggestions' => string[], 'error' => string|null]
     */
    public function suggestFor(int $websiteId, int $userId, int $conversationId): array
    {
        $conversation = $this->conversationModel->find($conversationId);
        if (!$conversation || (int) $conversation->getAttribute('website_id') !== $websiteId) {
            return ['suggestions' => [], 'error' => 'Conversation not found'];
        }

        $language = $conversation->getAttribute('language') ?: 'en';
        $knowledgeContext = $this->knowledgeBase->buildContextForPrompt($websiteId, $language);
        $brandVoice = $this->knowledgeBase->getBrandVoice($websiteId);
        $history = $this->loadHistory($conversationId);

        if (empty($history)) {
            return ['suggestions' => [], 'error' => 'No conversation history to base suggestions on'];
        }

        $systemPrompt = $this->buildSystemPrompt($knowledgeContext, $brandVoice, $language);

        $result = $this->providerManager->generateReply($systemPrompt, $history, [
            'website_id' => $websiteId,
            'user_id' => $userId,
            'conversation_id' => $conversationId,
            'feature' => 'reply_suggestions',
            'temperature' => 0.6,
        ]);

        if (empty($result['success'])) {
            return ['suggestions' => [], 'error' => $result['error'] ?? 'AI provider failed'];
        }

        return ['suggestions' => $this->parseSuggestions((string) $result['content']), 'error' => null];
    }

    private function buildSystemPrompt(string $knowledgeContext, array $brandVoice, string $language): string
    {
        $toneInstruction = "Tone: {$brandVoice['tone']}.";
        if (!empty($brandVoice['custom_instructions'])) {
            $toneInstruction .= " Additional company instructions: " . $brandVoice['custom_instructions'];
        }

        return <<<PROMPT
You are helping a human customer support agent at a travel/tourism company reply to a customer. You do NOT reply directly - you suggest options for the agent to pick from, edit, or ignore.

{$toneInstruction}
Reply language: {$language}

{$knowledgeContext}

### RULES ###
- Never invent prices, services, or policies not in the knowledge base above.
- Suggest exactly 3 short, distinct reply options the agent could send, ranging from a quick acknowledgment to a more complete answer.
- Keep each suggestion concise and ready to send as-is.

### OUTPUT FORMAT ###
Respond with ONLY a valid JSON object, no markdown fences:
{"suggestions": ["option 1", "option 2", "option 3"]}
PROMPT;
    }

    /**
     * @param string $rawContent
     * @return string[]
     */
    private function parseSuggestions(string $rawContent): array
    {
        $cleaned = trim($rawContent);
        $cleaned = preg_replace('/^```json\s*|\s*```$/i', '', $cleaned);
        $cleaned = preg_replace('/^```\s*|\s*```$/', '', $cleaned);

        $decoded = json_decode($cleaned, true);
        if (is_array($decoded) && !empty($decoded['suggestions']) && is_array($decoded['suggestions'])) {
            return array_slice(array_values(array_filter(array_map('strval', $decoded['suggestions']))), 0, 3);
        }

        Logger::warning('AiReplySuggestionsService: failed to parse AI JSON output');
        return [];
    }

    /**
     * @param int $conversationId
     * @return array
     */
    private function loadHistory(int $conversationId): array
    {
        $rows = $this->db->query(
            "SELECT message_direction, message_text, ai_reply_generated FROM chat_messages
             WHERE conversation_id = ? ORDER BY created_at DESC LIMIT ?",
            [$conversationId, self::HISTORY_LIMIT]
        );

        $messages = [];
        foreach (array_reverse($rows) as $row) {
            if ($row['message_direction'] === 'incoming' && !empty($row['message_text'])) {
                $messages[] = ['role' => 'user', 'content' => $row['message_text']];
            } elseif (!empty($row['message_text']) && $row['message_direction'] === 'outgoing') {
                $messages[] = ['role' => 'assistant', 'content' => $row['message_text']];
            } elseif (!empty($row['ai_reply_generated'])) {
                $messages[] = ['role' => 'assistant', 'content' => $row['ai_reply_generated']];
            }
        }
        return $messages;
    }
}

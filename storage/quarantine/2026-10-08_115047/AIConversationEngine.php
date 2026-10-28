<?php

/**
 * Tourfecto - AI Chat Platform
 * AI Conversation Engine (بند 2، 3، 8، 9، 10، 11): المحرك الحقيقي الذي
 * يفهم رسالة العميل داخل سياقها الكامل (قاعدة معرفة الشركة + ذاكرة
 * العميل + تاريخ المحادثة + Brand Voice) ويولّد قرارًا كاملاً وليس مجرد
 * نص رد: هل نرد أم نحوّل لموظف؟ بأي ثقة؟ ما ملخص المحادثة المحدَّث؟ ما
 * الوسوم المناسبة؟ ما الحقائق الجديدة عن العميل التي يجب تذكّرها؟
 *
 * هذا الكلاس منفصل تمامًا عن TourfectoAIEngine الموجود (الذي تعتمد عليه
 * وحدات أخرى مثل Reputation/SEO ولا يجب المساس به). لا يُستدعى مباشرة من
 * الـWebhook Controllers؛ AutoReplyEngine هو من يستدعيه (بند 30: لا Refactor
 * شامل، تكامل إضافي فقط).
 *
 * @version 1.0.0
 */

class AIConversationEngine
{
    /** أقل درجة ثقة مقبولة لإرسال رد تلقائيًا دون تدخل بشري (بند 9) */
    public const MIN_CONFIDENCE_TO_AUTO_REPLY = 0.5;

    /** أقصى عدد رسائل سابقة تُحمَّل كسياق للمحادثة */
    public const HISTORY_LIMIT = 12;

    /** حقول الذاكرة المسموح استخلاصها تلقائيًا (بند 3: لا تخزين حساس بدون ضرورة) */
    public const ALLOWED_MEMORY_KEYS = [
        'name', 'country', 'trip_type', 'travelers_count', 'travel_date',
        'budget', 'interests', 'requested_services',
    ];

    /** @var KnowledgeBaseService */
    private $knowledgeBase;

    /** @var AIProviderManager */
    private $providerManager;

    /** @var AiCustomerMemory */
    private $memoryModel;

    /** @var UnifiedInboxService */
    private $inbox;

    /** @var AiChatConversation */
    private $conversationModel;

    /** @var LeadScoringService */
    private $leadScoring;

    /** @var Database */
    private $db;

    public function __construct()
    {
        $this->knowledgeBase = new KnowledgeBaseService();
        $this->providerManager = new AIProviderManager();
        $this->memoryModel = new AiCustomerMemory();
        $this->inbox = new UnifiedInboxService();
        $this->conversationModel = new AiChatConversation();
        $this->leadScoring = new LeadScoringService();
        $this->db = Database::getInstance();
    }

    /**
     * معالجة رسالة عميل واردة ضمن محادثة موحدة، وتوليد قرار كامل.
     *
     * @param int $websiteId
     * @param int $userId
     * @param int $conversationId
     * @param string $customerMessage
     * @return array [
     *   'reply' => string|null,     // null يعني: لا يوجد رد آلي، انتظر موظف
     *   'handoff' => bool,
     *   'handoff_reason' => string|null,
     *   'confidence' => float,
     *   'next_action' => string|null, // الإجراء المقترح التالي (تحسين تنافسي)
     *   'error' => string|null,
     * ]
     */
    public function handleIncomingMessage(int $websiteId, int $userId, int $conversationId, string $customerMessage): array
    {
        $conversation = $this->conversationModel->find($conversationId);
        if (!$conversation) {
            return $this->result(null, false, null, 0, null, 'Conversation not found');
        }

        // بند 8: لو المحادثة اتحوّلت لموظف بالفعل، أو تم طلب عدم التواصل،
        // الـAI يجب أن يتوقف تمامًا عن الرد.
        if ($this->inbox->shouldStopAutomation($conversationId)) {
            return $this->result(null, false, null, 0, null, null);
        }

        $language = $this->detectLanguage($customerMessage) ?: ($conversation->getAttribute('language') ?: 'en');
        $customerKey = (string) $conversation->getAttribute('customer_key');

        $knowledgeContext = $this->knowledgeBase->buildContextForPrompt($websiteId, $language, $customerMessage, 12);
        $brandVoice = $this->knowledgeBase->getBrandVoice($websiteId);
        $memory = $this->memoryModel->memoryFor($websiteId, $customerKey);
        $history = $this->loadHistory($conversationId);

        $systemPrompt = $this->buildSystemPrompt($knowledgeContext, $brandVoice, $memory, $language);
        // مركز الأتمتة: أمثلة من ردود صاحب النشاط وتعديلاته عشان الرد يطلع بأسلوبه
        if (class_exists('AutomationEngine')) {
            $systemPrompt .= AutomationEngine::instance()->styleBlock($userId, 'chat_replies');
        }
        $messages = $history;
        $messages[] = ['role' => 'user', 'content' => $customerMessage];

        $aiResult = $this->providerManager->generateReply($systemPrompt, $messages, [
            'website_id' => $websiteId,
            'user_id' => $userId,
            'conversation_id' => $conversationId,
            'feature' => 'chat_reply',
            'temperature' => 0.5,
        ]);

        // بند 24: فشل الـAI لا يجب أن يُسقط المحادثة - نحوّل لموظف بدل الانهيار.
        if (empty($aiResult['success'])) {
            $this->inbox->handoffToHuman($conversationId, 'ai_provider_failure');
            Logger::error('AIConversationEngine: provider failure, handed off to human', [
                'conversation_id' => $conversationId, 'error' => $aiResult['error'] ?? null,
            ]);
            return $this->result(null, true, 'ai_provider_failure', 0, null, $aiResult['error'] ?? 'AI provider failed');
        }

        $decision = $this->parseDecision((string) $aiResult['content']);

        // بند 9: ثقة منخفضة = لا اختراع، إما توضيح أو تحويل لموظف.
        $lowConfidence = $decision['confidence'] < self::MIN_CONFIDENCE_TO_AUTO_REPLY;
        $needsHuman = $decision['needs_human'] || $lowConfidence;

        $this->applySideEffects($conversationId, $websiteId, $customerKey, $decision, $language, $memory);

        if ($needsHuman) {
            $reason = $decision['handoff_reason'] ?: ($lowConfidence ? 'low_ai_confidence' : 'ai_requested_handoff');
            $this->inbox->handoffToHuman($conversationId, $reason);

            // Learning Loop: لو التحويل بسبب نقص معرفة (الـAI لم يجد الإجابة)،
            // سجّل سؤال العميل كفجوة معرفة يُقترح إضافتها لقاعدة المعرفة لاحقًا.
            try {
                $learningLoop = new LearningLoopService();
                if (in_array($reason, LearningLoopService::KNOWLEDGE_GAP_HANDOFF_REASONS, true)) {
                    $learningLoop->recordKnowledgeGap(
                        $websiteId,
                        $conversationId,
                        $customerMessage,
                        $language,
                        $reason
                    );
                }
            } catch (Exception $e) {
                // فشل تسجيل الفجوة لا يوقف رد الـAI إطلاقًا
                Logger::warning('AIConversationEngine: knowledge gap recording failed', ['error' => $e->getMessage()]);
            }

            // لو الـAI مع ذلك ولّد رد توضيحي (مثال: "سأتأكد وأعود إليك")، نسمح
            // بإرساله قبل التحويل - أفضل من صمت مفاجئ للعميل.
            $replyBeforeHandoff = !empty($decision['reply']) ? $decision['reply'] : null;
            return $this->result($replyBeforeHandoff, true, $reason, $decision['confidence'], $decision['next_action'], null);
        }

        return $this->result($decision['reply'] ?: null, false, null, $decision['confidence'], $decision['next_action'], null);
    }

    /**
     * @param string $knowledgeContext
     * @param array $brandVoice
     * @param array $memory
     * @param string $language
     * @return string
     */
    private function buildSystemPrompt(string $knowledgeContext, array $brandVoice, array $memory, string $language): string
    {
        $toneInstruction = "Tone: {$brandVoice['tone']}.";
        if (!empty($brandVoice['custom_instructions'])) {
            $toneInstruction .= " Additional company instructions: " . $brandVoice['custom_instructions'];
        }

        $memoryText = 'None known yet.';
        if (!empty($memory)) {
            $lines = [];
            foreach ($memory as $key => $value) {
                $lines[] = "- {$key}: {$value}";
            }
            $memoryText = implode("\n", $lines);
        }

        return <<<PROMPT
You are the AI customer communication assistant for a travel/tourism company on the Tourfecto platform. You chat directly with customers on behalf of the company.

{$toneInstruction}

Reply in this language unless the customer clearly switches language: {$language}

{$knowledgeContext}

### WHAT YOU KNOW ABOUT THIS CUSTOMER SO FAR ###
{$memoryText}

### STRICT RULES ###
1. NEVER invent prices, services, tours, destinations, or policies that are not in the COMPANY KNOWLEDGE BASE above.
2. If you don't have enough information to answer accurately, say so honestly and offer to connect the customer with a team member - do not guess.
3. Act as a helpful sales-minded assistant: try to understand what the customer needs (destination, dates, number of travelers, budget) and move the conversation toward a booking, but never pressure or invent discounts.
4. Detect if the customer wants a human agent, has a complaint, mentions payment issues, or asks something entirely outside the knowledge base - in these cases you must request human handoff.
5. Detect if the customer says something like "don't contact me" / "stop messaging me" and reflect that clearly in your JSON output so automation can stop.
6. Always pick ONE single most useful next step that brings the conversation closer to a confirmed booking, based on what is still missing: ask for the destination, travel dates, number of travelers, budget, or contact details before quoting or booking. Do not ask for information the customer already provided.

### OUTPUT FORMAT ###
Respond with ONLY a single valid JSON object (no markdown fences, no extra text) with this exact shape:
{
  "reply": "the message to send to the customer, in their language, following the tone above",
  "language": "detected customer language code, e.g. ar or en",
  "confidence": 0.0 to 1.0 (how confident you are this reply is accurate and fully grounded in the knowledge base),
  "needs_human": true or false,
  "handoff_reason": "one of: customer_requested_human, complaint, payment_issue, sensitive_request, outside_knowledge_base, high_value_lead, or null",
  "summary": "one short paragraph summarizing the customer's request and status so far, or null if nothing new",
  "tags": ["subset of: HOT_LEAD, NEW_INQUIRY, PRICE_REQUEST, COMPLAINT, FOLLOW_UP, BOOKING_INTENT, VIP, HUMAN_REQUIRED"],
  "lead_status": "one of: new_inquiry, qualifying, qualified, hot_lead, converted, lost, none",
  "next_action": "the single most useful next step for the company: one of ask_destination, ask_dates, ask_travelers, ask_budget, ask_contact_details, send_quote, schedule_booking, handoff_to_human, follow_up, or null if the conversation is complete",
  "memory": {"only include new facts you learned, keys from: name, country, trip_type, travelers_count, travel_date, budget, interests, requested_services"}
}
PROMPT;
    }

    /**
     * تحليل استجابة الـAI (JSON) بأمان مع قيم افتراضية آمنة لو فشل التحليل.
     * @param string $rawContent
     * @return array
     */
    private function parseDecision(string $rawContent): array
    {
        $cleaned = trim($rawContent);
        // بعض المزودين قد يضيف ```json ... ``` رغم التعليمات - نزيلها احتياطًا
        $cleaned = preg_replace('/^```json\s*|\s*```$/i', '', $cleaned);
        $cleaned = preg_replace('/^```\s*|\s*```$/', '', $cleaned);

        $decoded = json_decode($cleaned, true);

        if (!is_array($decoded) || !isset($decoded['reply'])) {
            // فشل تحليل الـJSON - نعتبر النص كله ردًا خامًا بثقة متوسطة
            // بدل رفض الرد بالكامل (بند 24: عدم الانهيار عند الخطأ).
            Logger::warning('AIConversationEngine: failed to parse AI JSON output, using raw text fallback');
            return [
                'reply' => $cleaned !== '' ? $cleaned : null,
                'language' => null,
                'confidence' => 0.5,
                'needs_human' => false,
                'handoff_reason' => null,
                'summary' => null,
                'tags' => [],
                'lead_status' => null,
                'next_action' => null,
                'memory' => [],
            ];
        }

        return [
            'reply' => is_string($decoded['reply'] ?? null) ? $decoded['reply'] : null,
            'language' => $decoded['language'] ?? null,
            'confidence' => is_numeric($decoded['confidence'] ?? null) ? max(0.0, min(1.0, (float) $decoded['confidence'])) : 0.5,
            'needs_human' => (bool) ($decoded['needs_human'] ?? false),
            'handoff_reason' => $decoded['handoff_reason'] ?? null,
            'summary' => is_string($decoded['summary'] ?? null) ? $decoded['summary'] : null,
            'tags' => is_array($decoded['tags'] ?? null) ? $decoded['tags'] : [],
            'lead_status' => $decoded['lead_status'] ?? null,
            'next_action' => is_string($decoded['next_action'] ?? null) ? $decoded['next_action'] : null,
            'memory' => is_array($decoded['memory'] ?? null) ? $decoded['memory'] : [],
        ];
    }

    /**
     * تطبيق الآثار الجانبية للقرار: تحديث المحادثة، الوسوم، الذاكرة (بند 3، 10، 11)
     * وبناء/تحديث ملف Lead (بند 5، 6).
     * @param int $conversationId
     * @param int $websiteId
     * @param string $customerKey
     * @param array $decision
     * @param string $fallbackLanguage
     * @param array $priorMemory ذاكرة العميل المعروفة قبل هذه الرسالة
     */
    private function applySideEffects(int $conversationId, int $websiteId, string $customerKey, array $decision, string $fallbackLanguage, array $priorMemory = []): void
    {
        $updates = [
            'ai_confidence_score' => $decision['confidence'],
            'language' => $decision['language'] ?: $fallbackLanguage,
        ];

        if (!empty($decision['summary'])) {
            $updates['ai_summary'] = $decision['summary'];
        }
        if (!empty($decision['lead_status'])) {
            $updates['lead_status'] = $decision['lead_status'];
        }
        if (!empty($decision['handoff_reason']) && stripos((string) $decision['handoff_reason'], 'not_contact') !== false) {
            $updates['do_not_contact'] = 1;
        }
        if (!empty($decision['next_action'])) {
            $updates['next_recommended_action'] = $decision['next_action'];
        }

        $this->inbox->updateConversation($conversationId, $updates);

        if (!empty($decision['tags'])) {
            $validTags = array_intersect($decision['tags'], UnifiedInboxService::STANDARD_TAGS);
            if (!empty($validTags)) {
                $this->inbox->addTags($conversationId, $validTags);
            }
        }

        $mergedMemory = $priorMemory;
        foreach ($decision['memory'] as $key => $value) {
            if (!in_array($key, self::ALLOWED_MEMORY_KEYS, true) || $value === null || $value === '') {
                continue;
            }
            $this->memoryModel->remember($websiteId, $customerKey, (string) $key, (string) $value, $conversationId);
            $mergedMemory[$key] = $value;
        }

        // بند 5-6: AI Sales Agent - بناء/تحديث ملف Lead تلقائيًا لو المحادثة
        // وصلت لحالة تستحق ذلك. لا يُنشئ Lead لكل رسالة عابرة (تحكمه
        // LeadScoringService::LEAD_WORTHY_STATUSES داخليًا).
        try {
            $refreshedConversation = $this->conversationModel->find($conversationId);
            if ($refreshedConversation) {
                $this->leadScoring->upsertFromConversation(
                    $refreshedConversation,
                    $mergedMemory,
                    $decision['summary'] ?: null,
                    null
                );
            }
        } catch (Exception $e) {
            Logger::warning('AIConversationEngine: lead scoring failed', [
                'conversation_id' => $conversationId, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * تحميل آخر رسائل المحادثة كسياق (role/content) للـAI.
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
            } elseif (!empty($row['ai_reply_generated'])) {
                $messages[] = ['role' => 'assistant', 'content' => $row['ai_reply_generated']];
            }
        }
        return $messages;
    }

    /**
     * كشف لغة مبسّط (عربي/غير عربي) يكفي لاختيار سياق قاعدة المعرفة
     * ولغة الرد الافتراضية قبل أن يحدد الـAI نفسه اللغة بدقة أكبر (بند 14).
     * @param string $text
     * @return string|null
     */
    private function detectLanguage(string $text): ?string
    {
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $text)) {
            return 'ar';
        }
        if (preg_match('/[a-zA-Z]/', $text)) {
            return 'en';
        }
        return null;
    }

    private function result(?string $reply, bool $handoff, ?string $handoffReason, float $confidence, ?string $nextAction, ?string $error): array
    {
        return [
            'reply' => $reply,
            'handoff' => $handoff,
            'handoff_reason' => $handoffReason,
            'confidence' => $confidence,
            'next_action' => $nextAction,
            'error' => $error,
        ];
    }
}

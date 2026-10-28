<?php

/**
 * Tourfecto - AI Chat Platform
 * Learning Loop Service (Learning Loop - مستوحى من Resolution Learning Loop
 * في Zendesk وIntercom Fin Flywheel):
 *
 * الهدف: إغلاق حلقة التعلم حتى يتحسن الـAI تلقائيًا مع الوقت:
 *   1) تسجيل نتيجة كل محادثة (هل الـAI حلّها فعلاً؟ أم أحيلت لموظف؟) في
 *      ai_resolution_events - أساس حساب "AI Resolution Rate" الحقيقي.
 *   2) اكتشاف فجوات المعرفة (Knowledge Gaps): أسئلة العملاء التي لم
 *      يستطع الـAI الإجابة عنها فتحوّل لموظف، تُجمَّع حسب السؤال وتُقترح
 *      لصاحب الشركة لإضافتها لقاعدة المعرفة (Flywheel).
 *   3) مؤشرات التعلم المجمّعة لوحة "Learning Insights" في الـAnalytics.
 *
 * لغة محايدة: لا يفترض أي لغة معينة - يشتغل على كل اللغات (الدولية).
 *
 * @version 1.0.0
 */

class LearningLoopService
{
    /** أسباب التحويل المرتبطة بفجوة معرفة (الـAI لم يجد الإجابة) */
    public const KNOWLEDGE_GAP_HANDOFF_REASONS = [
        'outside_knowledge_base',
        'low_ai_confidence',
        'ai_requested_handoff',
    ];

    /** @var Database */
    private $db;

    /** @var AiChatConversation */
    private $conversationModel;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->conversationModel = new AiChatConversation();
    }

    /**
     * تسجيل نتيجة محادثة في ai_resolution_events (نقطة واحدة لكل محادثة).
     *
     * @param int $conversationId
     * @param string $outcome ai_resolved|human_resolved|abandoned|reopened
     * @param string|null $handoffReason سبب التحويل لو outcome=human_resolved
     * @return bool
     */
    public function recordResolution(int $conversationId, string $outcome, ?string $handoffReason = null): bool
    {
        $valid = ['ai_resolved', 'human_resolved', 'abandoned', 'reopened'];
        if (!in_array($outcome, $valid, true)) {
            return false;
        }

        $conversation = $this->conversationModel->find($conversationId);
        if (!$conversation) {
            return false;
        }

        try {
            $this->db->query(
                "INSERT INTO ai_resolution_events
                 (website_id, conversation_id, channel, language, outcome, handoff_reason, ai_confidence_score, resolved_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    (int) $conversation->getAttribute('website_id'),
                    $conversationId,
                    $conversation->getAttribute('channel'),
                    $conversation->getAttribute('language'),
                    $outcome,
                    $handoffReason,
                    $conversation->getAttribute('ai_confidence_score'),
                ]
            );
            return true;
        } catch (Exception $e) {
            Logger::warning('LearningLoopService: recordResolution failed', ['conversation_id' => $conversationId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * تحديد النتيجة تلقائيًا عند إغلاق/حل محادثة: لو تم تحويل لموظف أبدًا
     * تعتبر human_resolved، وإلا ai_resolved.
     * @param int $conversationId
     * @return bool
     */
    public function recordResolutionForClosedConversation(int $conversationId): bool
    {
        $conversation = $this->conversationModel->find($conversationId);
        if (!$conversation) {
            return false;
        }

        $outcome = $conversation->getAttribute('handoff_at') !== null ? 'human_resolved' : 'ai_resolved';
        return $this->recordResolution($conversationId, $outcome, $conversation->getAttribute('handoff_reason'));
    }

    /**
     * تسجيل/تحديث فجوة معرفة لسؤال لم يستطع الـAI الإجابة عنه.
     * - نفس المحادثة لا تُسجَّل إلا مرة واحدة (UNIQUE website_id+conversation_id).
     * - نفس السؤال (بعد التسوية النصية) من محادثات مختلفة يزيد occurrence_count.
     *
     * @param int $websiteId
     * @param int $conversationId
     * @param string $question نص سؤال العميل
     * @param string|null $language
     * @param string|null $handoffReason
     * @return bool
     */
    public function recordKnowledgeGap(int $websiteId, int $conversationId, string $question, ?string $language = null, ?string $handoffReason = null): bool
    {
        $question = trim($question);
        if ($question === '' || $conversationId <= 0) {
            return false;
        }
        $normalized = $this->normalizeQuestion($question);

        try {
            // وجود فجوة لهذه المحادثة بالفعل؟ لو نعم تجاهل (منع التكرار)
            $existing = $this->db->query(
                "SELECT id FROM ai_knowledge_gaps WHERE website_id = ? AND conversation_id = ? LIMIT 1",
                [$websiteId, $conversationId]
            );
            if (!empty($existing)) {
                return false;
            }

            // نفس السؤال من محادثة أخرى؟ لو نعم زد occurrence_count
            $sameQuestion = $this->db->query(
                "SELECT id, occurrence_count FROM ai_knowledge_gaps
                 WHERE website_id = ? AND normalized_question = ? ORDER BY id ASC LIMIT 1",
                [$websiteId, $normalized]
            );
            if (!empty($sameQuestion)) {
                $this->db->query(
                    "UPDATE ai_knowledge_gaps SET occurrence_count = occurrence_count + 1, last_seen_at = NOW() WHERE id = ?",
                    [(int) $sameQuestion[0]['id']]
                );
                return true;
            }

            $this->db->query(
                "INSERT INTO ai_knowledge_gaps
                 (website_id, conversation_id, question, normalized_question, language, handoff_reason, occurrence_count)
                 VALUES (?, ?, ?, ?, ?, ?, 1)",
                [$websiteId, $conversationId, $question, $normalized, $language, $handoffReason]
            );
            return true;
        } catch (Exception $e) {
            Logger::warning('LearningLoopService: recordKnowledgeGap failed', ['conversation_id' => $conversationId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * مسح المحادثات المحوّلة لموظف لأسباب معرفية خلال فترة، واستخراج آخر
     * رسالة عميل قبل التحويل، وتسجيلها كفجوة معرفة (Flywheel). آمن للفشل:
     * أي خطأ يُسجَّل فقط ولا يكسر الدعوة.
     *
     * @param int $websiteId
     * @param string|null $sinceDate 'Y-m-d'
     * @param int $limit
     * @return int عدد الفجوات الجديدة المسجّلة
     */
    public function scanKnowledgeGaps(int $websiteId, ?string $sinceDate = null, int $limit = 50): int
    {
        $sinceDate = $sinceDate ?: date('Y-m-d', strtotime('-30 days'));
        $reasons = implode(',', array_map(function ($r) {
            return "'" . addslashes($r) . "'";
        }, self::KNOWLEDGE_GAP_HANDOFF_REASONS));

        try {
            $rows = $this->db->query(
                "SELECT id, handoff_reason, language, handoff_at
                 FROM ai_conversations
                 WHERE website_id = ? AND created_at >= ?
                   AND handoff_at IS NOT NULL
                   AND handoff_reason IN ({$reasons})
                 ORDER BY handoff_at DESC
                 LIMIT " . (int) $limit,
                [$websiteId, $sinceDate]
            );
        } catch (Exception $e) {
            Logger::warning('LearningLoopService: scanKnowledgeGaps select failed', ['error' => $e->getMessage()]);
            return 0;
        }

        $recorded = 0;
        foreach ($rows as $row) {
            $lastCustomerMessage = $this->lastCustomerMessageBefore((int) $row['id'], $row['handoff_at']);
            if ($lastCustomerMessage === null) {
                continue;
            }
            if ($this->recordKnowledgeGap(
                $websiteId,
                (int) $row['id'],
                $lastCustomerMessage,
                $row['language'],
                $row['handoff_reason']
            )) {
                $recorded++;
            }
        }
        return $recorded;
    }

    /**
     * آخر رسالة عميل (incoming) قبل لحظة التحويل لموظف.
     * @param int $conversationId
     * @param string|null $handoffAt
     * @return string|null
     */
    private function lastCustomerMessageBefore(int $conversationId, ?string $handoffAt): ?string
    {
        try {
            $rows = $this->db->query(
                "SELECT message_text FROM chat_messages
                 WHERE conversation_id = ? AND message_direction = 'incoming' AND message_text IS NOT NULL AND message_text != ''
                 AND (created_at <= ?)
                 ORDER BY created_at DESC LIMIT 1",
                [$conversationId, $handoffAt ?? date('Y-m-d H:i:s')]
            );
            return !empty($rows) ? trim((string) $rows[0]['message_text']) : null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * تسوية نص السؤال للتجميع (لغة محايدة):
     * حروف صغيرة، إزالة علامات الترقيم والتشكيل العربي، ضغط المسافات.
     * @param string $question
     * @return string
     */
    private function normalizeQuestion(string $question): string
    {
        $text = mb_strtolower(trim($question));
        $text = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $text); // تشكيل عربي + تطويل
        $text = preg_replace('/[\p{P}\p{S}]/u', ' ', $text); // ترقيم ورموز
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    /**
     * لوحة مؤشرات التعلم المجمّعة (Learning Insights) لموقع معيّن:
     * معدلات الحل + الفجوات + أسباب التحويل. تُستخدم في الـAnalytics.
     *
     * @param int $websiteId
     * @param string|null $sinceDate 'Y-m-d'
     * @return array
     */
    public function getLearningInsights(int $websiteId, ?string $sinceDate = null): array
    {
        $sinceDate = $sinceDate ?: date('Y-m-d', strtotime('-30 days'));

        return [
            'resolution_events' => $this->resolutionBreakdown($websiteId, $sinceDate),
            'ai_resolution_rate_percent' => $this->aiResolutionRate($websiteId, $sinceDate),
            'escalation_reasons' => $this->escalationReasons($websiteId, $sinceDate),
            'knowledge_gaps' => $this->topKnowledgeGaps($websiteId, $sinceDate),
        ];
    }

    private function resolutionBreakdown(int $websiteId, string $sinceDate): array
    {
        try {
            $rows = $this->db->query(
                "SELECT outcome, COUNT(*) AS c FROM ai_resolution_events
                 WHERE website_id = ? AND created_at >= ? GROUP BY outcome",
                [$websiteId, $sinceDate]
            );
        } catch (Exception $e) {
            Logger::warning('LearningLoopService: resolutionBreakdown failed', ['error' => $e->getMessage()]);
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $result[$row['outcome']] = (int) $row['c'];
        }
        return $result;
    }

    /**
     * AI Resolution Rate: نسبة المحادثات التي حلها الـAI بالكامل
     * (outcome=ai_resolved) من كل الأحداث المسجّلة خلال الفترة.
     */
    private function aiResolutionRate(int $websiteId, string $sinceDate): float
    {
        $breakdown = $this->resolutionBreakdown($websiteId, $sinceDate);
        $total = array_sum($breakdown);
        if ($total <= 0) {
            return 0.0;
        }
        $aiResolved = $breakdown['ai_resolved'] ?? 0;
        return round(($aiResolved / $total) * 100, 1);
    }

    private function escalationReasons(int $websiteId, string $sinceDate, int $limit = 10): array
    {
        try {
            $rows = $this->db->query(
                "SELECT handoff_reason, COUNT(*) AS c FROM ai_resolution_events
                 WHERE website_id = ? AND created_at >= ? AND handoff_reason IS NOT NULL AND handoff_reason != ''
                 GROUP BY handoff_reason ORDER BY c DESC LIMIT ?",
                [$websiteId, $sinceDate, $limit]
            );
        } catch (Exception $e) {
            Logger::warning('LearningLoopService: escalationReasons failed', ['error' => $e->getMessage()]);
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $result[$row['handoff_reason']] = (int) $row['c'];
        }
        return $result;
    }

    /**
     * أهم فجوات المعرفة (بالأعلى تكرارًا) خلال فترة، للأعلى اعتبارًا.
     * @param int $websiteId
     * @param string $sinceDate
     * @param int $limit
     * @return array
     */
    public function topKnowledgeGaps(int $websiteId, string $sinceDate, int $limit = 10): array
    {
        try {
            $rows = $this->db->query(
                "SELECT id, question, normalized_question, language, handoff_reason, occurrence_count, status, last_seen_at
                 FROM ai_knowledge_gaps
                 WHERE website_id = ? AND created_at >= ?
                 ORDER BY occurrence_count DESC, last_seen_at DESC
                 LIMIT ?",
                [$websiteId, $sinceDate, $limit]
            );
            return $rows;
        } catch (Exception $e) {
            Logger::warning('LearningLoopService: topKnowledgeGaps failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * تحديث حالة فجوة معرفة (acknowledged|added_to_kb|dismissed).
     * @param int $gapId
     * @param int $websiteId
     * @param string $status
     * @return bool
     */
    public function updateGapStatus(int $gapId, int $websiteId, string $status): bool
    {
        $valid = ['acknowledged', 'added_to_kb', 'dismissed'];
        if (!in_array($status, $valid, true)) {
            return false;
        }
        try {
            $affected = $this->db->query(
                "UPDATE ai_knowledge_gaps SET status = ? WHERE id = ? AND website_id = ?",
                [$status, $gapId, $websiteId]
            );
            return (int) $affected > 0;
        } catch (Exception $e) {
            Logger::warning('LearningLoopService: updateGapStatus failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}

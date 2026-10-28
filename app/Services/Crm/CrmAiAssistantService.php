<?php

/**
 * Tourfecto - CRM AI Sales Assistant Service (بند 9)
 * @version 1.0.0
 *
 * التصميم: توجيه نية (Intent Routing) بسيط ومبني على كلمات مفتاحية لتغطية
 * الأسئلة الخمسة الواردة حرفيًا في الطلب الأصلي + سؤال عام احتياطي. كل
 * نية تُنفّذ استعلام SQL حقيقي على بيانات الحساب فقط (بند 9: "الـAI يعتمد
 * على بيانات CRM الحقيقية فقط")، ثم GeminiClient الموحّد (نفس العميل
 * المستخدم في MarketingAssistantService - بند 33: لا تنشئ أنظمة مكررة)
 * يُستخدم فقط لصياغة الإجابة بلغة طبيعية من البيانات المُسترجَعة فعليًا -
 * وليس لاختلاق أي معلومة. البرومبت يُلزم النموذج صراحة بعدم إضافة أي شيء
 * خارج البيانات المرفقة. لو فشل استدعاء الـAI (لا مفتاح/انقطاع)، تُعرض
 * البيانات الخام منسّقة بدلًا من فشل كامل للطلب.
 */
class CrmAiAssistantService
{
    private $db;
    private $ai;
    private $nbaService;

    public function __construct(?GeminiClient $ai = null)
    {
        $this->db = Database::getInstance();
        $this->ai = $ai ?? new GeminiClient();
        $this->nbaService = new CrmNextBestActionService();
    }

    public function ask(int $userId, string $question): array
    {
        $intent = $this->detectIntent($question);
        $data = $this->fetchDataForIntent($userId, $intent, $question);

        $answer = $this->composeAnswer($question, $intent, $data);

        $interaction = new AIAssistantInteraction([
            'user_id' => $userId,
            'type' => 'crm_assistant',
            'title' => mb_substr($question, 0, 100),
            'input_payload' => json_encode(['question' => $question, 'intent' => $intent], JSON_UNESCAPED_UNICODE),
            'output' => $answer,
        ]);
        $interaction->save();

        ActivityLog::record('crm', 'assistant.asked', [
            'user_id' => $userId, 'subject_type' => 'ai_assistant_interactions',
            'subject_id' => (int) $interaction->getAttribute('id'), 'meta' => ['intent' => $intent],
        ]);

        return ['intent' => $intent, 'data' => $data, 'answer' => $answer];
    }

    private function detectIntent(string $q): string
    {
        $q = mb_strtolower($q);
        $has = fn (array $words) => array_reduce($words, fn ($carry, $w) => $carry || mb_strpos($q, $w) !== false, false);

        if ($has(['أهم', 'أكلمهم', 'اتصل بيهم', 'النهارده', 'اليوم', 'today', 'priority'])) {
            return 'top_priority_today';
        }
        if ($has(['قربت تقفل', 'قريبة الإغلاق', 'هتتحول', 'closing soon', 'قربوا'])) {
            return 'leads_closing_soon';
        }
        if ($has(['متأخرة', 'متأخر', 'overdue', 'خطر', 'at risk', 'at-risk'])) {
            return 'deals_overdue';
        }
        if ($has(['ما اتعملوش follow', 'من غير متابعة', 'بدون متابعة', 'no follow', 'follow-up', 'followup'])) {
            return 'leads_without_followup';
        }
        if ($has(['أفضل خطوة', 'خطوة تالية', 'next step', 'next best action'])) {
            return 'next_best_action_generic';
        }
        return 'general_overview';
    }

    private function fetchDataForIntent(int $userId, string $intent, string $question): array
    {
        switch ($intent) {
            case 'top_priority_today':
                return $this->db->query(
                    "SELECT l.id, l.status, l.score, l.priority, l.score_reason, c.name AS contact_name, c.phone AS contact_phone
                     FROM crm_leads l JOIN crm_contacts c ON c.id = l.contact_id
                     WHERE c.user_id = ? AND l.status NOT IN ('converted', 'disqualified')
                       AND (l.next_follow_up_at IS NULL OR l.next_follow_up_at <= NOW())
                     ORDER BY l.score DESC, l.next_follow_up_at ASC
                     LIMIT 10",
                    [$userId]
                );

            case 'leads_closing_soon':
                return $this->db->query(
                    "SELECT l.id, l.status, l.score, c.name AS contact_name
                     FROM crm_leads l JOIN crm_contacts c ON c.id = l.contact_id
                     WHERE c.user_id = ? AND l.status = 'qualified'
                     ORDER BY l.score DESC LIMIT 10",
                    [$userId]
                );

            case 'deals_overdue':
                return (new CrmDealService())->atRiskDeals($userId);

            case 'leads_without_followup':
                return $this->db->query(
                    "SELECT l.id, l.status, c.name AS contact_name
                     FROM crm_leads l JOIN crm_contacts c ON c.id = l.contact_id
                     WHERE c.user_id = ? AND l.status NOT IN ('converted', 'disqualified') AND l.next_follow_up_at IS NULL
                     ORDER BY l.created_at ASC LIMIT 20",
                    [$userId]
                );

            case 'next_best_action_generic':
                // لو السؤال بيحدد رقم Lead صراحة (مثال: "Lead #42")
                if (preg_match('/#?(\d{1,10})/', $question, $m)) {
                    try {
                        return ['single_lead' => $this->nbaService->forLead((int) $m[1])];
                    } catch (Exception $e) {
                        return ['error' => $e->getMessage()];
                    }
                }
                return ['note' => 'حدّد رقم الـLead في السؤال (مثال: "أفضل خطوة للـLead #42") للحصول على اقتراح دقيق'];

            default:
                return (new CrmDashboardService())->stats($userId);
        }
    }

    private function composeAnswer(string $question, string $intent, array $data): string
    {
        if (empty($data)) {
            return 'مفيش بيانات كافية للإجابة على السؤال ده حاليًا (Not enough data).';
        }

        $factsJson = json_encode($data, JSON_UNESCAPED_UNICODE);
        $prompt = "أنت مساعد مبيعات داخل نظام CRM. جاوب على سؤال المستخدم بالعربية المصرية "
            . "بإيجاز واحترافية، بالاعتماد حصريًا على البيانات الحقيقية التالية (JSON) ولا تضف أي "
            . "معلومة أو رقم غير موجود فيها إطلاقًا. لو البيانات فارغة أو غير كافية قل ذلك صراحة.\n\n"
            . "سؤال المستخدم: \"{$question}\"\n\nالبيانات الحقيقية:\n{$factsJson}\n\n"
            . "الإجابة (بدون تكرار الـJSON، فقط ملخص طبيعي مفيد):";

        $response = $this->ai->generateContent($prompt, ['task'=>'crm_assistant', 'maxOutputTokens' => 512]);
        if (!empty($response['success']) && !empty($response['data'])) {
            return trim((string) $response['data']);
        }

        // Fallback: لو فشل استدعاء الـAI، نعرض البيانات الخام بدل فشل كامل للطلب
        return "تعذّر توليد إجابة نصية من الذكاء الاصطناعي حاليًا، لكن هذه البيانات الحقيقية المطابقة لسؤالك:\n"
            . $factsJson;
    }
}

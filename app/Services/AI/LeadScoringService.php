<?php

/**
 * Tourfecto - AI Chat Platform
 * Lead Scoring Service (بند 5، 6): يبني ويحدّث ملف Lead غني تلقائيًا من
 * إشارات المحادثة (الوسوم، حالة الـLead، ذاكرة العميل)، ويحسب Lead Score
 * بناءً على اكتمال البيانات + قوة النية - بدون اختلاق خصومات أو أسعار
 * (هذا الكلاس لا يولّد أي نص للعميل، فقط يبني/يحدّث سجل داخلي للفريق).
 *
 * يُستدعى من AIConversationEngine بعد كل رسالة عميل ذات إشارة Lead
 * حقيقية (لا يُنشئ Lead لكل "مرحبا" عابرة).
 *
 * @version 1.0.0
 */

class LeadScoringService
{
    /** حالات lead_status التي تستحق إنشاء/تحديث ملف Lead */
    public const LEAD_WORTHY_STATUSES = ['new_inquiry', 'qualifying', 'qualified', 'hot_lead', 'converted'];

    /** الحقول المستخدمة في حساب اكتمال البيانات (كل حقل = نقاط ثابتة) */
    public const PROFILE_FIELDS_WEIGHT = [
        'destination' => 15,
        'travel_date' => 15,
        'budget' => 20,
        'travelers_count' => 10,
        'interest' => 10,
        'phone' => 15,
        'email' => 5,
    ];

    /** @var AiLead */
    private $leadModel;

    public function __construct()
    {
        $this->leadModel = new AiLead();
    }

    /**
     * إنشاء أو تحديث ملف Lead لمحادثة معيّنة بناءً على أحدث حالة معروفة.
     *
     * @param AiChatConversation $conversation
     * @param array $memory ذاكرة العميل الحالية (مفتاح => قيمة)
     * @param string|null $aiSummary
     * @param string|null $nextRecommendedAction
     * @return AiLead|null
     */
    public function upsertFromConversation(AiChatConversation $conversation, array $memory, ?string $aiSummary = null, ?string $nextRecommendedAction = null): ?AiLead
    {
        $leadStatus = $conversation->getAttribute('lead_status');
        if (!in_array($leadStatus, self::LEAD_WORTHY_STATUSES, true)) {
            return null;
        }

        $conversationId = (int) $conversation->getAttribute('id');
        $existing = $this->leadModel->where(['conversation_id' => $conversationId], [], 1);
        $isNewLead = empty($existing);
        $lead = $existing[0] ?? new AiLead();

        $intentScore = $this->calculateIntentScore($leadStatus, $conversation, $memory);
        $profileCompletenessScore = $this->calculateProfileCompleteness($conversation, $memory);
        $leadScore = (int) round(($intentScore * 0.6) + ($profileCompletenessScore * 0.4));

        $lead->fill([
            'website_id' => $conversation->getAttribute('website_id'),
            'conversation_id' => $conversationId,
            'name' => $conversation->getAttribute('customer_name'),
            'phone' => $conversation->getAttribute('customer_phone'),
            'email' => $conversation->getAttribute('customer_email'),
            'source' => 'ai_chat',
            'channel' => $conversation->getAttribute('channel'),
            'interest' => $memory['requested_services'] ?? $memory['trip_type'] ?? null,
            'destination' => $memory['country'] ?? null,
            'travel_date' => $memory['travel_date'] ?? null,
            'budget' => $memory['budget'] ?? null,
            'travelers_count' => isset($memory['travelers_count']) ? (int) $memory['travelers_count'] : null,
            'intent_score' => $intentScore,
            'lead_score' => $leadScore,
            'status' => $this->mapLeadStatusToPipelineStatus($leadStatus, $lead->getAttribute('status')),
            'ai_summary' => $aiSummary ?: $lead->getAttribute('ai_summary'),
            'next_recommended_action' => $nextRecommendedAction ?: $this->suggestNextAction($leadStatus, $memory),
            'last_interaction_at' => date('Y-m-d H:i:s'),
        ]);

        if ($lead->save() === false) {
            return null;
        }

        if ($isNewLead) {
            try {
                Notification::notify(
                    (int) $conversation->getAttribute('user_id'),
                    'ai_chat_new_lead',
                    'New lead from AI Chat',
                    ($lead->getAttribute('name') ?: 'A customer') . ' - lead score: ' . $leadScore,
                    '/ai-chat/leads/' . $lead->getAttribute('id')
                );
            } catch (Exception $e) {
                Logger::warning('LeadScoringService: new lead notification failed', ['error' => $e->getMessage()]);
            }
        }

        return $lead;
    }

    /**
     * درجة نية الشراء (0-100) بناءً على حالة الـLead والوسوم المكتشفة.
     * @param string $leadStatus
     * @param AiChatConversation $conversation
     * @param array $memory
     * @return int
     */
    private function calculateIntentScore(string $leadStatus, AiChatConversation $conversation, array $memory): int
    {
        $base = [
            'new_inquiry' => 20,
            'qualifying' => 40,
            'qualified' => 65,
            'hot_lead' => 85,
            'converted' => 100,
        ][$leadStatus] ?? 20;

        $tags = json_decode((string) $conversation->getAttribute('tags'), true) ?: [];
        if (in_array('HOT_LEAD', $tags, true)) {
            $base += 10;
        }
        if (in_array('BOOKING_INTENT', $tags, true)) {
            $base += 10;
        }
        if (!empty($memory['travel_date'])) {
            $base += 5;
        }

        return max(0, min(100, $base));
    }

    /**
     * درجة اكتمال بيانات العميل (0-100) - كل معلومة معروفة تزيد الدرجة.
     * @param AiChatConversation $conversation
     * @param array $memory
     * @return int
     */
    private function calculateProfileCompleteness(AiChatConversation $conversation, array $memory): int
    {
        $score = 0;
        $score += !empty($memory['country']) ? self::PROFILE_FIELDS_WEIGHT['destination'] : 0;
        $score += !empty($memory['travel_date']) ? self::PROFILE_FIELDS_WEIGHT['travel_date'] : 0;
        $score += !empty($memory['budget']) ? self::PROFILE_FIELDS_WEIGHT['budget'] : 0;
        $score += !empty($memory['travelers_count']) ? self::PROFILE_FIELDS_WEIGHT['travelers_count'] : 0;
        $score += (!empty($memory['requested_services']) || !empty($memory['trip_type'])) ? self::PROFILE_FIELDS_WEIGHT['interest'] : 0;
        $score += !empty($conversation->getAttribute('customer_phone')) ? self::PROFILE_FIELDS_WEIGHT['phone'] : 0;
        $score += !empty($conversation->getAttribute('customer_email')) ? self::PROFILE_FIELDS_WEIGHT['email'] : 0;

        return max(0, min(100, $score));
    }

    /**
     * ترجمة lead_status (حالة المحادثة) إلى status خط أنابيب المبيعات
     * (pipeline) الخاص بجدول ai_leads، مع عدم التراجع لحالة أضعف لو
     * الفريق حرّك الـLead يدويًا لحالة متقدمة بالفعل (won/proposal_sent).
     * @param string $leadStatus
     * @param string|null $currentPipelineStatus
     * @return string
     */
    private function mapLeadStatusToPipelineStatus(string $leadStatus, ?string $currentPipelineStatus): string
    {
        if (in_array($currentPipelineStatus, ['won', 'lost', 'proposal_sent'], true)) {
            return $currentPipelineStatus; // لا تتراجع تلقائيًا عن قرار الفريق اليدوي
        }
        if ($leadStatus === 'converted') {
            return 'won';
        }
        if (in_array($leadStatus, ['qualified', 'hot_lead'], true)) {
            return 'qualified';
        }
        return $currentPipelineStatus === 'contacted' ? 'contacted' : 'new';
    }

    /**
     * اقتراح الخطوة التالية للفريق - نص إرشادي عام وليس محتوى مُرسَل للعميل.
     * @param string $leadStatus
     * @param array $memory
     * @return string
     */
    private function suggestNextAction(string $leadStatus, array $memory): string
    {
        if ($leadStatus === 'hot_lead') {
            return 'Contact customer directly - high intent detected, close before they cool off.';
        }
        if (empty($memory['budget'])) {
            return 'Ask the customer about their budget to refine the offer.';
        }
        if (empty($memory['travel_date'])) {
            return 'Confirm the customer travel dates to check availability.';
        }
        return 'Prepare and send a tailored itinerary/quote based on known preferences.';
    }
}

<?php

/**
 * Tourfecto - AI Chat Platform
 * إدارة ملفات Leads التي يبنيها AI Sales Agent تلقائيًا من المحادثات
 * (بند 5، 6).
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class AiLeadController extends Controller
{
    /** @var AiLead */
    private $leadModel;

    public function __construct()
    {
        parent::__construct();
        $this->leadModel = new AiLead();
    }

    /**
     * قائمة الـLeads لموقع معيّن، مع فلترة اختيارية بالحالة.
     * GET /api/ai-chat/websites/{id}/leads
     * Query: status
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

        $filters = [];
        if ($status = $this->get('status')) {
            $filters['status'] = $status;
        }
        if ($conversationId = $this->get('conversation_id')) {
            $filters['conversation_id'] = (int) $conversationId;
        }

        $leads = $this->leadModel->forWebsite((int) $website->getAttribute('id'), $filters);

        return $this->success([
            'leads' => array_map([$this, 'serialize'], $leads),
        ]);
    }

    /**
     * تفاصيل Lead واحد.
     * GET /api/ai-chat/websites/{id}/leads/{leadId}
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

        $lead = $this->authorizedLead((int) ($params['leadId'] ?? 0), (int) $website->getAttribute('id'));
        if (!$lead) {
            return $this->error('Lead not found', 404);
        }

        return $this->success(['lead' => $this->serialize($lead, true)]);
    }

    /**
     * تحديث Lead يدويًا من الفريق (تغيير الحالة، تعيين موظف...). القرار
     * اليدوي هنا لا يُستبدَل تلقائيًا بعد ذلك من LeadScoringService
     * للحالات المتقدمة (won/lost/proposal_sent) - راجع
     * LeadScoringService::mapLeadStatusToPipelineStatus().
     * PUT /api/ai-chat/websites/{id}/leads/{leadId}
     * Body: status, assigned_agent_id
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

        $lead = $this->authorizedLead((int) ($params['leadId'] ?? 0), (int) $website->getAttribute('id'));
        if (!$lead) {
            return $this->error('Lead not found', 404);
        }

        $updatable = ['status', 'assigned_agent_id'];
        $fields = array_intersect_key($this->all(), array_flip($updatable));

        if (empty($fields)) {
            return $this->error('No valid fields to update', 422);
        }

        $lead->fill($fields);
        if ($lead->save() === false) {
            return $this->error('Failed to update lead', 500);
        }

        return $this->success([], 'Lead updated');
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
     * @param int $leadId
     * @param int $websiteId
     * @return AiLead|null
     */
    private function authorizedLead(int $leadId, int $websiteId): ?AiLead
    {
        if ($leadId <= 0) {
            return null;
        }
        $lead = $this->leadModel->find($leadId);
        if (!$lead || (int) $lead->getAttribute('website_id') !== $websiteId) {
            return null;
        }
        return $lead;
    }

    /**
     * @param AiLead $lead
     * @param bool $detailed
     * @return array
     */
    private function serialize(AiLead $lead, bool $detailed = false): array
    {
        $data = [
            'id' => $lead->getAttribute('id'),
            'conversation_id' => $lead->getAttribute('conversation_id'),
            'name' => $lead->getAttribute('name'),
            'phone' => $lead->getAttribute('phone'),
            'channel' => $lead->getAttribute('channel'),
            'interest' => $lead->getAttribute('interest'),
            'destination' => $lead->getAttribute('destination'),
            'intent_score' => $lead->getAttribute('intent_score'),
            'lead_score' => $lead->getAttribute('lead_score'),
            'status' => $lead->getAttribute('status'),
            'last_interaction_at' => $lead->getAttribute('last_interaction_at'),
        ];

        if ($detailed) {
            $data['email'] = $lead->getAttribute('email');
            $data['travel_date'] = $lead->getAttribute('travel_date');
            $data['budget'] = $lead->getAttribute('budget');
            $data['travelers_count'] = $lead->getAttribute('travelers_count');
            $data['ai_summary'] = $lead->getAttribute('ai_summary');
            $data['next_recommended_action'] = $lead->getAttribute('next_recommended_action');
            $data['assigned_agent_id'] = $lead->getAttribute('assigned_agent_id');
            $data['created_at'] = $lead->getAttribute('created_at');
        }

        return $data;
    }
}

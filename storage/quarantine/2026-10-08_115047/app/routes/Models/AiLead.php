<?php
/**
 * Tourfecto - AI Chat Platform
 * ملف Lead الغني الذي يبنيه الـAI Sales Agent من المحادثة (بند 5-6).
 * منفصل عمدًا عن WebsiteLead (نماذج التواصل من الموقع المنشور).
 * @version 1.0.0
 */
class AiLead extends Model {
    protected $table = 'ai_leads';
    protected $fillable = [
        'website_id', 'conversation_id', 'name', 'phone', 'email',
        'encrypted_phone', 'encrypted_email', 'source', 'channel',
        'interest', 'destination', 'travel_date', 'budget',
        'travelers_count', 'intent_score', 'lead_score', 'status',
        'ai_summary', 'next_recommended_action', 'assigned_agent_id',
        'last_interaction_at',
    ];

    /**
     * @param int $websiteId
     * @param array $filters ['status', 'conversation_id']
     * @return array
     */
    public function forWebsite(int $websiteId, array $filters = []): array {
        $conditions = ['website_id' => $websiteId];
        if (!empty($filters['status'])) {
            $conditions['status'] = $filters['status'];
        }
        if (!empty($filters['conversation_id'])) {
            $conditions['conversation_id'] = (int) $filters['conversation_id'];
        }
        return $this->where($conditions, ['lead_score' => 'DESC', 'last_interaction_at' => 'DESC']);
    }
}

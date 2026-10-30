<?php

/**
 * Tourfecto - AI Voice & Sales Agent: call history API. Tenant from the session; transcripts need the view_recordings capability
 * because they can contain personal data.
 */
class VoiceCallsController extends Controller
{
    private function guard(string $cap): array
    {
        if (!$this->isAuthenticated()) {
            return ['error' => $this->error('Unauthorized', 401)];
        }
        $userId = (int) $this->user['id'];
        $tenantId = VoiceAccessService::tenantFor($userId);
        if (!VoiceAccessService::featureEnabled($tenantId)) {
            return ['error' => $this->error('AI Voice Agent is not enabled for this plan', 403)];
        }
        if (!VoiceAccessService::can($userId, $cap)) {
            return ['error' => $this->error('Forbidden', 403)];
        }
        return ['user' => $userId, 'tenant' => $tenantId];
    }

    /** GET /api/voice-agent/calls?limit=50 */
    public function index(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_VIEW);
        if (isset($g['error'])) {
            return $g['error'];
        }
        $limit = max(1, min(100, (int) $this->get('limit', 50)));
        $rows = Database::getInstance()->query(
            'SELECT id, number_id, agent_id, direction, from_e164, to_e164, mode, status, language, identity_verified, turns, outcome, handoff_reason, ai_cost_usd, started_at, ended_at, duration_s
             FROM voice_calls WHERE tenant_user_id = ? ORDER BY id DESC LIMIT ' . $limit,
            [$g['tenant']]
        ) ?: [];
        return $this->success(['calls' => $rows]);
    }

    /** GET /api/voice-agent/calls/{id} */
    public function show(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_VIEW_RECORDINGS);
        if (isset($g['error'])) {
            return $g['error'];
        }
        $store = new VoiceDataStore();
        $call = $store->getCall($g['tenant'], (int) ($params['id'] ?? 0));
        if (!$call) {
            return $this->error('Call not found', 404);
        }
        $turns = array_values(array_filter($store->recentTurns($g['tenant'], (int) $call['id'], 300), static fn ($t) => $t['role'] !== 'tool'));
        return $this->success(['call' => $call, 'turns' => $turns]);
    }
}

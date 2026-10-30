<?php

/**
 * Tourfecto - AI Voice & Sales Agent controller (Phase 1: status, settings, agent profiles).
 *
 * Tenant is always derived server-side from the session user (VoiceAccessService::tenantFor); no endpoint
 * accepts a tenant id from the client. Module is gated by the `voice_agent` plan feature (off by default).
 * No telephony provider is contacted in this phase.
 */
class VoiceAgentController extends Controller
{
    private VoiceSettingsService $settings;

    public function __construct()
    {
        parent::__construct();
        $this->settings = new VoiceSettingsService();
    }

    /** Resolve [userId, tenantId] after auth + feature + capability checks, or return an error response. */
    private function guard(string $capability, bool $write = false): array
    {
        if (!$this->isAuthenticated()) {
            return ['error' => $this->error('Unauthorized', 401)];
        }
        if ($write && ($csrf = $this->verifyCsrf())) {
            return ['error' => $csrf];
        }
        $userId = (int) $this->user['id'];
        $tenantId = VoiceAccessService::tenantFor($userId);
        if (!VoiceAccessService::featureEnabled($tenantId)) {
            return ['error' => $this->error('AI Voice Agent is not enabled for this plan', 403)];
        }
        if (!VoiceAccessService::can($userId, $capability)) {
            return ['error' => $this->error('Forbidden', 403)];
        }
        return ['user' => $userId, 'tenant' => $tenantId];
    }

    /** GET /api/voice-agent/status */
    public function status(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $userId = (int) $this->user['id'];
        $tenantId = VoiceAccessService::tenantFor($userId);
        $enabled = VoiceAccessService::featureEnabled($tenantId);
        $role = VoiceAccessService::roleFor($userId);
        return $this->success([
            'feature_enabled' => $enabled,
            'role' => $role,
            'capabilities' => $enabled ? VoiceAccessService::capabilitiesFor($role) : [],
            'phase' => 1,
            'telephony_connected' => false,
        ]);
    }

    /** GET /api/voice-agent/settings */
    public function getSettings(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_VIEW);
        if (isset($g['error'])) {
            return $g['error'];
        }
        return $this->success(['settings' => $this->settings->get($g['tenant'])]);
    }

    /** POST /api/voice-agent/settings */
    public function saveSettings(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_MANAGE_SETTINGS, true);
        if (isset($g['error'])) {
            return $g['error'];
        }
        try {
            $saved = $this->settings->save($g['tenant'], $this->all());
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        VoiceAuditService::record($g['tenant'], $g['user'], 'settings.update', 'settings', $g['tenant']);
        return $this->success(['settings' => $saved], 'Saved');
    }

    /** GET /api/voice-agent/agents */
    public function listAgents(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_VIEW);
        if (isset($g['error'])) {
            return $g['error'];
        }
        return $this->success(['agents' => $this->settings->listAgents($g['tenant'])]);
    }

    /** POST /api/voice-agent/agents */
    public function createAgent(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_MANAGE_AGENTS, true);
        if (isset($g['error'])) {
            return $g['error'];
        }
        try {
            $id = $this->settings->createAgent($g['tenant'], $g['user'], $this->all());
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        VoiceAuditService::record($g['tenant'], $g['user'], 'agent.create', 'agent', $id);
        return $this->success(['id' => $id], 'Created', 201);
    }

    /** POST /api/voice-agent/agents/{id} */
    public function updateAgent(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_MANAGE_AGENTS, true);
        if (isset($g['error'])) {
            return $g['error'];
        }
        $id = (int) ($params['id'] ?? 0);
        try {
            $ok = $this->settings->updateAgent($g['tenant'], $id, $this->all());
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        if (!$ok) {
            return $this->error('Agent not found', 404);
        }
        VoiceAuditService::record($g['tenant'], $g['user'], 'agent.update', 'agent', $id);
        return $this->success(['id' => $id], 'Saved');
    }
}

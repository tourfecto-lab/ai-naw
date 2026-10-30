<?php

/** Tourfecto - AI Voice & Sales Agent: AI VOICE ANALYTICS API (Phase 8). Tenant from the session only. */
class VoiceAnalyticsController extends Controller
{
    private function guard(string $cap, bool $write = false): array
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
        if (!VoiceAccessService::can($userId, $cap)) {
            return ['error' => $this->error('Forbidden', 403)];
        }
        return ['user' => $userId, 'tenant' => $tenantId];
    }

    /** GET /api/voice-agent/analytics?from=YYYY-MM-DD&to=YYYY-MM-DD (default: last 30 days, at most one year) */
    public function overview(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_VIEW);
        if (isset($g['error'])) {
            return $g['error'];
        }
        try {
            return $this->success((new VoiceAnalyticsService())->overview($g['tenant'], $this->get('from') ?: null, $this->get('to') ?: null));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** GET /api/voice-agent/analytics/reviews - what the AI Call Supervisor found (needs the recordings capability: it quotes calls) */
    public function reviews(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_VIEW_RECORDINGS);
        return isset($g['error']) ? $g['error'] : $this->success(['reviews' => (new VoiceAnalyticsService())->reviews($g['tenant'], (int) $this->get('limit', 50))]);
    }

    /** POST /api/voice-agent/numbers/{id}/attribution {utm_link_id: N|null} - tie a dedicated number to one of the company's own ad links */
    public function setAttribution(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_MANAGE_TELEPHONY, true);
        if (isset($g['error'])) {
            return $g['error'];
        }
        $link = $this->get('utm_link_id');
        try {
            return $this->success((new VoiceNumberService())->setAttribution($g['tenant'], $g['user'], (int) ($params['id'] ?? 0), $link === null || $link === '' ? null : (int) $link));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}

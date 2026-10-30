<?php

/** Tourfecto - AI Voice & Sales Agent: BILLING, USAGE AND PRIVACY API (Phase 9). Tenant from the session only. */
class VoiceBillingController extends Controller
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

    /** GET /api/voice-agent/billing?month=YYYY-MM - usage statement with the budget state */
    public function statement(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_MANAGE_SETTINGS);
        if (isset($g['error'])) {
            return $g['error'];
        }
        try {
            $usage = new VoiceUsageService();
            $settings = (new VoiceSettingsService())->get($g['tenant']);
            return $this->success(['statement' => (new VoiceBillingService($usage))->statement($g['tenant'], $this->get('month') ?: null), 'budget' => (new VoiceBudgetService($usage))->state($g['tenant'], $settings)]);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** POST /api/voice-agent/privacy/erase {phone} - removes transcripts, call details and consent records of one number (the do-not-call entry stays) */
    public function erase(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_MANAGE_SETTINGS, true);
        if (isset($g['error'])) {
            return $g['error'];
        }
        try {
            return $this->success(['erased' => (new VoiceRetentionService())->erasePhone($g['tenant'], (string) $this->post('phone', ''))]);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}

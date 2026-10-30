<?php

/**
 * Tourfecto - AI Voice & Sales Agent: outbound jobs, consent and do-not-call API (Phase 5). Tenant from the session only.
 * Creating a job needs place_calls; lifting a do-not-call entry needs the account admin (manage_settings).
 */
class VoiceOutboundController extends Controller
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

    private function run(array $g, callable $fn): array
    {
        try {
            return $this->success($fn($g['tenant'], $g['user']));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** GET /api/voice-agent/outbound/purposes - what may be called for, and on what basis */
    public function purposes(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_VIEW);
        if (isset($g['error'])) {
            return $g['error'];
        }
        $out = [];
        foreach (VoiceCompliance::PURPOSES as $p => [$cat, $basis]) {
            $out[] = ['purpose' => $p, 'category' => $cat, 'basis' => $basis];
        }
        return $this->success(['purposes' => $out, 'rules' => ['inquiry_max_days' => VoiceCompliance::INQUIRY_MAX_DAYS, 'callback_max_days' => VoiceCompliance::CALLBACK_MAX_DAYS, 'max_per_day' => VoiceCompliance::MAX_PER_DAY, 'max_per_week' => VoiceCompliance::MAX_PER_WEEK]]);
    }

    /** GET /api/voice-agent/outbound/jobs */
    public function jobs(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_VIEW);
        return isset($g['error']) ? $g['error'] : $this->success(['jobs' => (new VoiceOutboundService())->listJobs($g['tenant'])]);
    }

    /** POST /api/voice-agent/outbound/jobs */
    public function createJob(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_PLACE_CALLS, true);
        if (isset($g['error'])) {
            return $g['error'];
        }
        return $this->run($g, fn ($t, $u) => (new VoiceOutboundService())->enqueue($t, $u, $this->all()));
    }

    /** POST /api/voice-agent/outbound/jobs/{id}/cancel */
    public function cancelJob(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_PLACE_CALLS, true);
        if (isset($g['error'])) {
            return $g['error'];
        }
        return $this->run($g, fn ($t, $u) => ['cancelled' => (new VoiceOutboundService())->cancel($t, $u, (int) ($params['id'] ?? 0))]);
    }

    /** POST /api/voice-agent/consents {phone, source, evidence, contact_id?} */
    public function grantConsent(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_PLACE_CALLS, true);
        if (isset($g['error'])) {
            return $g['error'];
        }
        return $this->run($g, function ($t, $u) {
            (new VoiceConsentService())->grantMarketing($t, $u, (string) $this->get('phone', ''), (string) $this->get('source', ''), (string) $this->get('evidence', ''), $this->get('contact_id') ? (int) $this->get('contact_id') : null);
            return ['granted' => true];
        });
    }

    /** POST /api/voice-agent/consents/withdraw {phone} */
    public function withdrawConsent(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_PLACE_CALLS, true);
        if (isset($g['error'])) {
            return $g['error'];
        }
        return $this->run($g, function ($t, $u) {
            (new VoiceConsentService())->withdraw($t, $u, (string) $this->get('phone', ''));
            return ['withdrawn' => true];
        });
    }

    /** GET /api/voice-agent/dnc */
    public function dnc(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_VIEW);
        return isset($g['error']) ? $g['error'] : $this->success(['dnc' => (new VoiceConsentService())->listDnc($g['tenant'])]);
    }

    /** POST /api/voice-agent/dnc {phone, reason?} */
    public function addDnc(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_PLACE_CALLS, true);
        if (isset($g['error'])) {
            return $g['error'];
        }
        return $this->run($g, function ($t, $u) {
            (new VoiceConsentService())->addDnc($t, $u, (string) $this->get('phone', ''), 'manual', $this->get('reason') ? (string) $this->get('reason') : null);
            return ['added' => true];
        });
    }

    /** POST /api/voice-agent/dnc/remove {phone} - admin only; platform-wide entries can never be removed here */
    public function removeDnc(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_MANAGE_SETTINGS, true);
        if (isset($g['error'])) {
            return $g['error'];
        }
        return $this->run($g, function ($t, $u) {
            (new VoiceConsentService())->removeDnc($t, $u, (string) $this->get('phone', ''));
            return ['removed' => true];
        });
    }

    /** GET /api/voice-agent/outbound/campaigns */
    public function campaigns(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_VIEW);
        return isset($g['error']) ? $g['error'] : $this->success(['campaigns' => (new VoiceCampaignService())->list($g['tenant'])]);
    }

    /** POST /api/voice-agent/outbound/campaigns {name, purpose, number_id, agent_id, max_attempts?, min_gap_hours?, tz?, approve_live?} */
    public function createCampaign(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_PLACE_CALLS, true);
        if (isset($g['error'])) {
            return $g['error'];
        }
        return $this->run($g, fn ($t, $u) => ['campaign_id' => (new VoiceCampaignService())->create($t, $u, $this->all())]);
    }

    /** POST /api/voice-agent/outbound/campaigns/{id}/recipients {recipients:[{to, contact_id?, country?, tz?, context?}]} */
    public function addRecipients(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_PLACE_CALLS, true);
        if (isset($g['error'])) {
            return $g['error'];
        }
        return $this->run($g, fn ($t, $u) => (new VoiceCampaignService())->addRecipients($t, $u, (int) ($params['id'] ?? 0), (array) $this->get('recipients', [])));
    }

    /** POST /api/voice-agent/outbound/campaigns/{id}/status {action: pause|resume|cancel} */
    public function campaignStatus(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_PLACE_CALLS, true);
        if (isset($g['error'])) {
            return $g['error'];
        }
        return $this->run($g, fn ($t, $u) => (new VoiceCampaignService())->setStatus($t, $u, (int) ($params['id'] ?? 0), (string) $this->get('action', '')));
    }

    /** GET /api/voice-agent/outbound/campaigns/{id} */
    public function campaignStats(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_VIEW);
        if (isset($g['error'])) {
            return $g['error'];
        }
        return $this->run($g, fn ($t, $u) => (new VoiceCampaignService())->stats($t, (int) ($params['id'] ?? 0)));
    }
}

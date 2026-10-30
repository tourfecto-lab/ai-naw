<?php

/** Tourfecto - AI Voice & Sales Agent: assistant routing, handoff staff and business-hours status (Phase 7). Tenant from the session only. */
class VoiceAssistantController extends Controller
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

    /** GET /api/voice-agent/staff */
    public function staff(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_VIEW);
        return isset($g['error']) ? $g['error'] : $this->success(['staff' => (new VoiceStaffService())->list($g['tenant']), 'topics' => VoiceStaffRouting::TOPICS]);
    }

    /** POST /api/voice-agent/staff {name, phone, topics?, days?, from_time?, to_time?, priority?, active?} */
    public function createStaff(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_MANAGE_AGENTS, true);
        return isset($g['error']) ? $g['error'] : $this->run($g, fn ($t, $u) => ['id' => (new VoiceStaffService())->create($t, $u, $this->all())]);
    }

    /** POST /api/voice-agent/staff/{id} */
    public function updateStaff(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_MANAGE_AGENTS, true);
        if (isset($g['error'])) {
            return $g['error'];
        }
        return $this->run($g, function ($t, $u) use ($params) {
            if (!(new VoiceStaffService())->update($t, $u, (int) ($params['id'] ?? 0), $this->all())) {
                throw new InvalidArgumentException('Person not found');
            }
            return ['saved' => true];
        });
    }

    /** POST /api/voice-agent/staff/{id}/delete */
    public function deleteStaff(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_MANAGE_AGENTS, true);
        return isset($g['error']) ? $g['error'] : $this->run($g, fn ($t, $u) => ['deleted' => (new VoiceStaffService())->delete($t, $u, (int) ($params['id'] ?? 0))]);
    }

    /** POST /api/voice-agent/numbers/{id}/routing {rules:[{when:{...}, agent_id}]} */
    public function setRouting(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_MANAGE_AGENTS, true);
        return isset($g['error']) ? $g['error'] : $this->run($g, fn ($t, $u) => (new VoiceNumberService())->setRouting($t, $u, (int) ($params['id'] ?? 0), (array) $this->get('rules', [])));
    }

    /** GET /api/voice-agent/hours - is the office open now, from the company's knowledge base hours, and who is on shift */
    public function hours(array $params = []): array
    {
        $g = $this->guard(VoiceAccessService::CAP_VIEW);
        if (isset($g['error'])) {
            return $g['error'];
        }
        $settings = (new VoiceSettingsService())->get($g['tenant']);
        $rows = (new VoiceDataStore())->businessHoursRows($g['tenant']);
        $now = time();
        return $this->success([
            'configured' => (bool) BusinessHoursService::fromEntries($rows), 'open_now' => VoiceHours::isOpen($rows, $settings['timezone'] ?? null, $now),
            'timezone' => $settings['timezone'] ?? null, 'after_hours_mode' => $settings['after_hours_mode'] ?? 'ai',
            'staff_on_shift' => array_map(static fn ($s) => ['id' => (int) $s['id'], 'name' => $s['name']], VoiceStaffRouting::available((new VoiceStaffService())->forRouting($g['tenant']), $now, $settings['timezone'] ?? null, null)),
        ]);
    }
}

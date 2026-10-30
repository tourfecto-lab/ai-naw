<?php

/**
 * Tourfecto - AI Voice Agent: platform administration API (Phase 2). Routes are admin-only (AuthMiddleware + AdminMiddleware) and the
 * controller checks the role again. Provider keys are saved in admin > Integrations (twilio_voice / telnyx_voice). Platform switches, per-company
 * control, the emergency stop and the platform do-not-call list are managed here; turning on anything that spends money or contacts people needs
 * the typed confirmation phrase, and every change is written to the audit trail.
 */
class VoiceAdminController extends Controller
{
    private function admin(): ?array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return in_array((string) ($this->user['role'] ?? ''), ['admin', 'super_admin'], true) ? null : $this->error('Forbidden', 403);
    }

    /** GET /api/admin/voice/providers */
    public function providers(array $params = []): array
    {
        return $this->admin() ?? $this->success(['providers' => (new VoiceAdminReports())->providers(), 'live_operations_enabled' => (new SystemSettingsService())->get('voice_live_operations_enabled', '0') === '1', 'own_provider_accounts_allowed' => VoiceProviderService::ownAccountsAllowed()]);
    }

    /** POST /api/admin/voice/providers/{provider}/test - read-only check of Tourfecto's own account */
    public function test(array $params = []): array
    {
        if ($e = $this->admin()) {
            return $e;
        }
        if ($csrf = $this->verifyCsrf()) {
            return $csrf;
        }
        $provider = strtolower((string) ($params['provider'] ?? ''));
        if (!VoiceProviderRegistry::has($provider)) {
            return $this->error('Unknown provider', 404);
        }
        $key = array_search($provider, VoicePlatformCredentials::INTEGRATIONS, true);
        $r = VoicePlatformCredentials::adminTest((string) $key, [VoicePlatformCredentials::class, 'read']);
        IntegrationsController::recordCheck((string) $key, $r['ok'], $r['message']);
        return $r['ok'] ? $this->success($r, $r['message']) : $this->error($r['message'], 422);
    }

    /** GET /api/admin/voice/providers/{provider}/countries */
    public function countries(array $params = []): array
    {
        if ($e = $this->admin()) {
            return $e;
        }
        return $this->catalogue($params, static fn (VoiceProviderAdapter $a) => ['countries' => $a->listCountries()]);
    }

    /** GET /api/admin/voice/providers/{provider}/numbers?country=US&type=local - what a company would be offered, with the provider's real prices */
    public function numbers(array $params = []): array
    {
        if ($e = $this->admin()) {
            return $e;
        }
        $iso = (string) $this->get('country', '');
        $type = (string) $this->get('type', 'local');
        return $this->catalogue($params, static fn (VoiceProviderAdapter $a) => ['numbers' => $a->searchNumbers($iso, $type), 'usage_rates' => $a->usageRates($iso, $type)]);
    }

    private function catalogue(array $params, callable $fn): array
    {
        $provider = strtolower((string) ($params['provider'] ?? ''));
        $creds = VoiceProviderRegistry::has($provider) ? VoicePlatformCredentials::forProvider($provider) : null;
        if (!$creds) {
            return $this->error('Provider is not configured in Integrations', 422);
        }
        try {
            return $this->success($fn(VoiceProviderRegistry::make($provider, $creds)));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (VoiceProviderException $e) {
            return $this->error('Provider request failed (HTTP ' . $e->httpCode . ')', 502);
        }
    }

    /** GET /api/admin/voice/errors */
    public function errors(array $params = []): array
    {
        return $this->admin() ?? $this->success(['errors' => (new VoiceAdminReports())->errors((int) $this->get('limit', 100))]);
    }

    /** GET /api/admin/voice/costs?month=YYYY-MM */
    public function costs(array $params = []): array
    {
        if ($e = $this->admin()) {
            return $e;
        }
        try {
            return $this->success((new VoiceAdminReports())->costs((string) $this->get('month', date('Y-m'))));
        } catch (InvalidArgumentException $ex) {
            return $this->error($ex->getMessage(), 422);
        }
    }

    /** GET /api/admin/voice/analytics - platform-wide totals (the same numbers the AI CEO sees) */
    public function analytics(array $params = []): array
    {
        return $this->admin() ?? $this->success(['summary' => VoicePlatformLoad::summary((int) $this->get('days', 30))]);
    }

    /** GET /api/admin/voice/margins?month=YYYY-MM */
    public function margins(array $params = []): array
    {
        if ($e = $this->admin()) {
            return $e;
        }
        try {
            return $this->success((new VoiceAdminReports())->margins((string) $this->get('month', gmdate('Y-m'))));
        } catch (InvalidArgumentException $ex) {
            return $this->error($ex->getMessage(), 422);
        }
    }

    /** GET /api/admin/voice/quality?days=7 */
    public function quality(array $params = []): array
    {
        return $this->admin() ?? $this->success((new VoiceAdminReports())->quality((int) $this->get('days', 7)));
    }

    /** GET /api/admin/voice/settings */
    public function getSettings(array $params = []): array
    {
        if ($e = $this->admin()) {
            return $e;
        }
        $defs = [];
        foreach (VoicePlatformSettings::DEFINITIONS as $k => [$type, $default, $label, $danger]) {
            $defs[] = ['key' => $k, 'type' => $type, 'default' => $default, 'label' => $label, 'danger' => $danger];
        }
        return $this->success(['values' => VoicePlatformSettings::current(), 'definitions' => $defs, 'confirm_phrase' => VoicePlatformSettings::CONFIRM_PHRASE]);
    }

    /** POST /api/admin/voice/settings {key: value..., confirm?} */
    public function saveSettings(array $params = []): array
    {
        if ($e = $this->admin()) {
            return $e;
        }
        if ($csrf = $this->verifyCsrf()) {
            return $csrf;
        }
        try {
            $clean = VoicePlatformSettings::sanitize($this->all());
            $current = VoicePlatformSettings::current();
            $danger = VoicePlatformSettings::dangerousChanges($clean, $current);
            if ($danger && (string) $this->get('confirm', '') !== VoicePlatformSettings::CONFIRM_PHRASE) {
                return $this->error('Type "' . VoicePlatformSettings::CONFIRM_PHRASE . '" to confirm: ' . implode(', ', $danger), 422);
            }
            $settings = new SystemSettingsService();
            foreach ($clean as $k => $v) {
                if ((string) ($current[$k] ?? '') !== $v) {
                    $settings->set($k, $v);
                    VoiceAuditService::record(0, (int) $this->user['id'], 'platform.setting_changed', 'setting', $k, ['from' => $current[$k] ?? null, 'to' => $v]);
                }
            }
            return $this->success(['values' => VoicePlatformSettings::current()], 'Saved');
        } catch (InvalidArgumentException $ex) {
            return $this->error($ex->getMessage(), 422);
        }
    }

    /** POST /api/admin/voice/emergency-stop - turns live operations off platform-wide and cancels everything waiting to be dialled */
    public function emergencyStop(array $params = []): array
    {
        if ($e = $this->admin()) {
            return $e;
        }
        if ($csrf = $this->verifyCsrf()) {
            return $csrf;
        }
        (new SystemSettingsService())->set('voice_live_operations_enabled', '0');
        $n = (new VoiceAdminReports())->cancelQueuedJobs(null, 'emergency_stop');
        VoiceAuditService::record(0, (int) $this->user['id'], 'platform.emergency_stop', 'platform', null, ['cancelled_jobs' => $n]);
        return $this->success(['live_operations_enabled' => false, 'cancelled_jobs' => $n], 'Live operations are off and queued calls were cancelled');
    }

    /** GET /api/admin/voice/companies */
    public function companies(array $params = []): array
    {
        return $this->admin() ?? $this->success(['companies' => (new VoiceAdminReports())->companies()]);
    }

    /** POST /api/admin/voice/companies/{id}/control {enabled?, sandbox_mode?, outbound_enabled?, monthly_budget_usd?, budget_policy?, stop?} */
    public function controlCompany(array $params = []): array
    {
        if ($e = $this->admin()) {
            return $e;
        }
        if ($csrf = $this->verifyCsrf()) {
            return $csrf;
        }
        $tenant = (int) ($params['id'] ?? 0);
        if ($tenant < 1 || !Database::getInstance()->query('SELECT id FROM users WHERE id = ? LIMIT 1', [$tenant])) {
            return $this->error('Company not found', 404);
        }
        try {
            $in = array_intersect_key($this->all(), array_flip(['enabled', 'sandbox_mode', 'outbound_enabled', 'monthly_budget_usd', 'budget_policy']));
            if (!empty($this->get('stop'))) { // company kill switch
                $in = ['enabled' => 0, 'outbound_enabled' => 0];
            }
            $svc = new VoiceSettingsService();
            $before = $svc->get($tenant);
            if (isset($in['sandbox_mode']) && (int) $in['sandbox_mode'] === 0 && (int) ($before['sandbox_mode'] ?? 1) === 1 && (string) $this->get('confirm', '') !== VoicePlatformSettings::CONFIRM_PHRASE) {
                return $this->error('Type "' . VoicePlatformSettings::CONFIRM_PHRASE . '" to take this company out of test mode', 422);
            }
            $after = $svc->save($tenant, $in);
            $cancelled = 0;
            if (empty($after['enabled']) || empty($after['outbound_enabled'])) {
                $cancelled = (new VoiceAdminReports())->cancelQueuedJobs($tenant, 'admin_stop');
            }
            VoiceAuditService::record($tenant, (int) $this->user['id'], 'admin.company_control', 'company', $tenant, ['changed' => array_keys($in), 'cancelled_jobs' => $cancelled]);
            return $this->success(['settings' => $after, 'cancelled_jobs' => $cancelled], 'Saved');
        } catch (InvalidArgumentException $ex) {
            return $this->error($ex->getMessage(), 422);
        }
    }

    /** GET /api/admin/voice/dnc - the platform-wide do-not-call list (blocks every company) */
    public function dnc(array $params = []): array
    {
        return $this->admin() ?? $this->success(['entries' => (new VoiceConsentService())->listDnc(0)]);
    }

    /** POST /api/admin/voice/dnc {phone, reason?} */
    public function addDnc(array $params = []): array
    {
        if ($e = $this->admin()) {
            return $e;
        }
        if ($csrf = $this->verifyCsrf()) {
            return $csrf;
        }
        try {
            (new VoiceConsentService())->addDnc(0, (int) $this->user['id'], (string) $this->get('phone', ''), 'platform', (string) $this->get('reason', '') ?: null);
            return $this->success([], 'Added');
        } catch (InvalidArgumentException $ex) {
            return $this->error($ex->getMessage(), 422);
        }
    }

    /** POST /api/admin/voice/dnc/remove {phone} */
    public function removeDnc(array $params = []): array
    {
        if ($e = $this->admin()) {
            return $e;
        }
        if ($csrf = $this->verifyCsrf()) {
            return $csrf;
        }
        (new VoiceConsentService())->removeDnc(0, (int) $this->user['id'], (string) $this->get('phone', ''));
        return $this->success([], 'Removed');
    }

    /** GET /api/admin/voice/access?q=email-or-company - find accounts and see whether they have the voice feature */
    public function accessSearch(array $params = []): array
    {
        return $this->admin() ?? $this->success(['users' => (new VoiceFeatureAccess())->search((string) $this->get('q', ''))]);
    }

    /** POST /api/admin/voice/access/{id} {enabled: 1|0|"plan", max_numbers?} - grant, revoke, or hand the decision back to the plan */
    public function accessSet(array $params = []): array
    {
        if ($e = $this->admin()) {
            return $e;
        }
        if ($csrf = $this->verifyCsrf()) {
            return $csrf;
        }
        $id = (int) ($params['id'] ?? 0);
        try {
            $raw = $this->get('enabled');
            $enabled = $raw === 'plan' ? null : in_array($raw, [1, '1', true, 'true'], true);
            $max = $this->get('max_numbers') !== null && $this->get('max_numbers') !== '' ? (int) $this->get('max_numbers') : null;
            (new VoiceFeatureAccess())->set($id, $enabled, $max, (int) $this->user['id']);
            VoiceAuditService::record($id, (int) $this->user['id'], 'admin.voice_access_' . ($enabled === null ? 'reset' : ($enabled ? 'granted' : 'revoked')), 'user', $id, ['max_numbers' => $max]);
            return $this->success([], $enabled === null ? 'The plan decides again' : ($enabled ? 'Voice agent enabled for this account' : 'Voice agent disabled for this account'));
        } catch (InvalidArgumentException $ex) {
            return $this->error($ex->getMessage(), 422);
        }
    }
}

<?php

/**
 * Tourfecto - AI Voice Agent: platform administration API (Phase 2). Routes are admin-only (AuthMiddleware + AdminMiddleware) and the
 * controller checks the role again. Read-only on purpose: provider keys are saved in admin > Integrations (twilio_voice / telnyx_voice),
 * and the live-operations switch is not changeable from here.
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
}

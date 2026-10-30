<?php

/**
 * Tourfecto - AI Voice & Sales Agent: telephony provider management API (Phase 2).
 * Tenant comes from the session only. Needs the manage_telephony capability (account admin).
 * Credentials are write-only: no endpoint returns them.
 */
class VoiceTelephonyController extends Controller
{
    private VoiceProviderService $providers;

    public function __construct()
    {
        parent::__construct();
        $this->providers = new VoiceProviderService();
    }

    private function guard(bool $write = false, string $cap = VoiceAccessService::CAP_MANAGE_TELEPHONY): array
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

    private function providerParam(array $params): ?string
    {
        $p = strtolower((string) ($params['provider'] ?? $this->get('provider', '')));
        return VoiceProviderRegistry::has($p) ? $p : null;
    }

    /** GET /api/voice-agent/providers */
    public function providers(array $params = []): array
    {
        $g = $this->guard();
        if (isset($g['error'])) {
            return $g['error'];
        }
        return $this->success(['providers' => $this->providers->listProviders($g['tenant']), 'errors_24h' => VoiceProviderErrorLog::summary($g['tenant'])]);
    }

    /** POST /api/voice-agent/providers/{provider}/credentials */
    public function saveCredentials(array $params = []): array
    {
        $g = $this->guard(true);
        if (isset($g['error'])) {
            return $g['error'];
        }
        $p = $this->providerParam($params);
        if (!$p) {
            return $this->error('Unknown provider', 404);
        }
        try {
            $this->providers->saveCredentials($g['tenant'], $p, $this->all());
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        VoiceAuditService::record($g['tenant'], $g['user'], 'provider.credentials_saved', 'provider', $p);
        return $this->success([], 'Saved');
    }

    /** POST /api/voice-agent/providers/{provider}/remove */
    public function removeCredentials(array $params = []): array
    {
        $g = $this->guard(true);
        if (isset($g['error'])) {
            return $g['error'];
        }
        $p = $this->providerParam($params);
        if (!$p) {
            return $this->error('Unknown provider', 404);
        }
        $this->providers->removeCredentials($g['tenant'], $p);
        VoiceAuditService::record($g['tenant'], $g['user'], 'provider.credentials_removed', 'provider', $p);
        return $this->success([], 'Removed');
    }

    /** POST /api/voice-agent/providers/{provider}/test */
    public function test(array $params = []): array
    {
        $g = $this->guard(true);
        if (isset($g['error'])) {
            return $g['error'];
        }
        $p = $this->providerParam($params);
        if (!$p) {
            return $this->error('Unknown provider', 404);
        }
        try {
            return $this->success(['result' => $this->providers->test($g['tenant'], $p)]);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** GET /api/voice-agent/providers/{provider}/countries */
    public function countries(array $params = []): array
    {
        $g = $this->guard();
        if (isset($g['error'])) {
            return $g['error'];
        }
        $p = $this->providerParam($params);
        if (!$p) {
            return $this->error('Unknown provider', 404);
        }
        try {
            return $this->success(['countries' => $this->providers->countries($g['tenant'], $p)]);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (VoiceProviderException $e) {
            return $this->error('Provider request failed', 502);
        }
    }

    /** GET /api/voice-agent/providers/{provider}/numbers?country=US&type=local&contains=555 */
    public function searchNumbers(array $params = []): array
    {
        $g = $this->guard();
        if (isset($g['error'])) {
            return $g['error'];
        }
        $p = $this->providerParam($params);
        if (!$p) {
            return $this->error('Unknown provider', 404);
        }
        try {
            $n = $this->providers->search($g['tenant'], $p, (string) $this->get('country', ''), (string) $this->get('type', 'local'), ['contains' => (string) $this->get('contains', '')]);
            return $this->success(['numbers' => $n, 'note' => 'Prices come from the provider and may change before purchase.']);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (VoiceProviderException $e) {
            return $this->error('Provider request failed', 502);
        }
    }

    /** GET /api/voice-agent/provider-errors */
    public function errors(array $params = []): array
    {
        $g = $this->guard();
        if (isset($g['error'])) {
            return $g['error'];
        }
        return $this->success(['errors' => VoiceProviderErrorLog::recent($g['tenant'])]);
    }

    /** GET /api/voice-agent/costs */
    public function costs(array $params = []): array
    {
        $g = $this->guard(false, VoiceAccessService::CAP_VIEW);
        if (isset($g['error'])) {
            return $g['error'];
        }
        $s = (new VoiceSettingsService())->get($g['tenant']);
        $usage = new VoiceUsageService();
        $b = (new VoiceBudgetService($usage))->state($g['tenant'], $s);
        $m = $usage->month($g['tenant']);
        return $this->success(['costs' => ['month_usd' => $m['price_usd'], 'by_component' => array_map(static fn ($c) => ['usd' => $c['price_usd'], 'quantity' => $c['quantity']], $m['by_component']), 'budget_usd' => $b['budget_usd'], 'percent' => $b['percent'], 'state' => $b['state'], 'over_budget' => $b['blocked'], 'policy' => $s['budget_policy'] ?? 'block']]);
    }
}

<?php

/**
 * Tourfecto - AI Voice & Sales Agent: phone number management API (Phase 3).
 * Tenant from the session only. Buying needs two explicit steps: quote (live price) then approve (exact amount).
 */
class VoiceNumberController extends Controller
{
    private VoiceNumberService $numbers;

    public function __construct()
    {
        parent::__construct();
        $this->numbers = new VoiceNumberService();
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

    /** Runs $fn, mapping customer-facing errors to responses. */
    private function run(array $g, callable $fn): array
    {
        try {
            return $this->success($fn($g['tenant'], $g['user']));
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (VoiceProviderException $e) {
            return $this->error('Provider request failed', 502);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 500);
        }
    }

    private function flag(string $k): bool
    {
        return filter_var($this->get($k, false), FILTER_VALIDATE_BOOLEAN);
    }

    /** GET /api/voice-agent/numbers */
    public function index(array $params = []): array
    {
        $g = $this->guard(false, VoiceAccessService::CAP_VIEW);
        return isset($g['error']) ? $g['error'] : $this->success(['numbers' => $this->numbers->list($g['tenant'])]);
    }

    /** GET /api/voice-agent/markets */
    public function markets(array $params = []): array
    {
        $g = $this->guard(false, VoiceAccessService::CAP_VIEW);
        return isset($g['error']) ? $g['error'] : $this->success(['markets' => VoiceMarkets::targets(), 'types' => VoiceNumberRules::TYPES, 'methods' => VoiceNumberRules::METHODS]);
    }

    /** POST /api/voice-agent/numbers/quote  {provider, number, country, type} */
    public function quote(array $params = []): array
    {
        $g = $this->guard(true);
        if (isset($g['error'])) {
            return $g['error'];
        }
        return $this->run($g, fn ($t, $u) => ['quote' => $this->numbers->quote($t, $u, strtolower((string) $this->get('provider', '')), (string) $this->get('number', ''), (string) $this->get('country', ''), (string) $this->get('type', 'local'))]);
    }

    /** POST /api/voice-agent/numbers/orders/{id}/approve  {accept_total, requirements_ack, address_sid?, bundle_sid?} */
    public function approve(array $params = []): array
    {
        $g = $this->guard(true);
        if (isset($g['error'])) {
            return $g['error'];
        }
        return $this->run($g, fn ($t, $u) => $this->numbers->approve($t, $u, (int) ($params['id'] ?? 0), $this->get('accept_total'), $this->flag('requirements_ack'), ['address_sid' => $this->get('address_sid'), 'bundle_sid' => $this->get('bundle_sid')]));
    }

    /** POST /api/voice-agent/numbers/connect  {number, method, provider?, country?} */
    public function connect(array $params = []): array
    {
        $g = $this->guard(true);
        if (isset($g['error'])) {
            return $g['error'];
        }
        $p = $this->get('provider');
        return $this->run($g, fn ($t, $u) => $this->numbers->connect($t, $u, (string) $this->get('number', ''), (string) $this->get('method', ''), $p ? strtolower((string) $p) : null, $this->get('country') ? (string) $this->get('country') : null));
    }

    /** POST /api/voice-agent/numbers/{id}/verify/start  {approve_call} */
    public function verifyStart(array $params = []): array
    {
        $g = $this->guard(true);
        if (isset($g['error'])) {
            return $g['error'];
        }
        return $this->run($g, fn ($t, $u) => $this->numbers->startVerification($t, $u, (int) ($params['id'] ?? 0), $this->flag('approve_call')));
    }

    /** POST /api/voice-agent/numbers/{id}/verify/confirm  {code} */
    public function verifyConfirm(array $params = []): array
    {
        $g = $this->guard(true);
        if (isset($g['error'])) {
            return $g['error'];
        }
        return $this->run($g, fn ($t, $u) => $this->numbers->confirmVerification($t, $u, (int) ($params['id'] ?? 0), (string) $this->get('code', '')));
    }

    /** POST /api/voice-agent/numbers/{id}/test */
    public function test(array $params = []): array
    {
        $g = $this->guard(true);
        if (isset($g['error'])) {
            return $g['error'];
        }
        return $this->run($g, fn ($t, $u) => $this->numbers->testLink($t, (int) ($params['id'] ?? 0)));
    }

    /** POST /api/voice-agent/numbers/{id}/assign  {agent_id?, forward_to?} */
    public function assign(array $params = []): array
    {
        $g = $this->guard(true, VoiceAccessService::CAP_MANAGE_AGENTS);
        if (isset($g['error'])) {
            return $g['error'];
        }
        $a = $this->get('agent_id');
        return $this->run($g, fn ($t, $u) => $this->numbers->assign($t, (int) ($params['id'] ?? 0), $a === null || $a === '' ? null : (int) $a, $this->get('forward_to') !== null ? (string) $this->get('forward_to') : null));
    }

    /** POST /api/voice-agent/numbers/{id}/release  {approve_release} */
    public function release(array $params = []): array
    {
        $g = $this->guard(true);
        if (isset($g['error'])) {
            return $g['error'];
        }
        return $this->run($g, fn ($t, $u) => $this->numbers->release($t, $u, (int) ($params['id'] ?? 0), $this->flag('approve_release')));
    }
}

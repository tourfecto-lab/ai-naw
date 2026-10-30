<?php

/**
 * Tourfecto - AI Voice Agent: inbound calls (provider webhooks -> answer, converse, transfer, recover missed calls).
 * Every handler first verifies the provider signature with the tenant's own credentials, then resolves the dialled number
 * INSIDE that tenant, so a call can never reach another company's agent or data. Each returns [httpStatus, contentType, body].
 */
class VoiceInboundService
{
    public const MAX_SECONDS = 600;

    private VoiceProviderService $providers;
    private VoiceDataStore $store;
    private VoiceSettingsService $settings;
    private string $baseUrl;
    /** @var callable|null */
    private $llm;

    public function __construct(?VoiceProviderService $providers = null, ?VoiceDataStore $store = null, ?string $baseUrl = null, ?callable $llm = null)
    {
        $this->providers = $providers ?? new VoiceProviderService();
        $this->store = $store ?? new VoiceDataStore();
        $this->settings = new VoiceSettingsService();
        $this->baseUrl = rtrim($baseUrl ?? (defined('APP_URL') ? APP_URL : ''), '/');
        $this->llm = $llm;
    }

    public static function webhookUrls(string $baseUrl, string $provider, int $tenantId): array
    {
        $b = rtrim($baseUrl, '/') . '/webhooks/voice/' . $provider . '/' . $tenantId;
        return ['inbound' => $b . '/inbound', 'status' => $b . '/status', 'turn' => $b . '/turn'];
    }

    private function authenticate(string $provider, int $tenantId, string $url, array $headers, string $raw, array $params): ?VoiceProviderAdapter
    {
        if (!VoiceProviderRegistry::has($provider) || $tenantId < 1) {
            return null;
        }
        try {
            $a = $this->providers->adapter($tenantId, $provider);
        } catch (Throwable $e) {
            return null;
        }
        return $a->verifyWebhook($url, $headers, $raw, $params) ? $a : null;
    }

    private static function xml(VoiceProviderAdapter $a, array $o): array
    {
        $r = $a->turnInstructions($o);
        return [200, $r['content_type'], $r['body']];
    }

    private static function plain(int $code, string $body): array
    {
        return [$code, 'text/plain', $body];
    }

    private function humanTarget(array $number, array $settings): ?string
    {
        foreach ([$number['forward_to'] ?? null, $settings['human_handoff_phone'] ?? null] as $t) {
            if (is_string($t) && $t !== '') {
                return $t;
            }
        }
        return null;
    }

    /** First webhook of a call: decide whether the AI may answer, and answer. */
    public function handleInbound(string $provider, int $tenantId, string $url, array $headers, string $raw, array $params): array
    {
        $a = $this->authenticate($provider, $tenantId, $url, $headers, $raw, $params);
        if (!$a) {
            return self::plain(403, 'Invalid signature');
        }
        $ev = $a->parseStatusEvent($headers, $raw, $params);
        if (!$ev || $ev['direction'] !== 'inbound') {
            return self::plain(200, 'ignored');
        }
        try {
            $to = VoiceNumberRules::normalizeE164($ev['to']);
        } catch (InvalidArgumentException $e) {
            return self::plain(200, 'ignored');
        }
        $rows = Database::getInstance()->query('SELECT * FROM voice_numbers WHERE tenant_user_id = ? AND e164 = ? LIMIT 1', [$tenantId, $to]);
        if (!$rows) {
            return self::plain(200, 'unknown number');
        }
        $number = $rows[0];
        $settings = $this->settings->get($tenantId);
        $human = $this->humanTarget($number, $settings);
        $agent = $number['agent_id'] ? (Database::getInstance()->query('SELECT * FROM voice_agents WHERE id = ? AND tenant_user_id = ? LIMIT 1', [(int) $number['agent_id'], $tenantId])[0] ?? null) : null;
        $lang = VoiceLanguages::normalize($agent['language'] ?? $settings['default_language'] ?? 'en');

        $awaiting = $number['status'] === 'awaiting_link_test' && $number['verification_mode'] === 'live';
        $usable = VoiceNumberRules::liveUsable($number) || $awaiting;
        $budget = isset($settings['monthly_budget_usd']) && $settings['monthly_budget_usd'] !== null ? (float) $settings['monthly_budget_usd'] : null;
        $reason = null;
        if (!$usable) {
            $reason = 'number_not_live';
        } elseif (empty($settings['enabled'])) {
            $reason = 'module_disabled';
        } elseif (!$agent || $agent['status'] !== 'active') {
            $reason = 'no_active_agent';
        } elseif (VoiceCostTracker::monthSummary($tenantId, $budget)['over_budget']) {
            $reason = 'budget_reached';
        }
        if ($awaiting) {
            (new VoiceNumberService($this->providers))->recordInboundArrival($to);
        }
        if ($reason !== null) {
            $this->store->createCall($tenantId, $number, $agent['id'] ?? null, $provider, $ev['call_id'], $ev['from'], $to, 'fallback', $lang, null);
            $call = $this->store->findBySid($tenantId, $provider, $ev['call_id']);
            if ($call) {
                $this->store->updateCall($tenantId, (int) $call['id'], ['status' => 'missed', 'outcome' => 'unanswered_' . $reason]);
            }
            if ($human && $a->capabilities()['turn_mode']) {
                return self::xml($a, ['say' => VoiceLanguages::handoffPhrase($lang), 'voice' => VoiceLanguages::defaultVoice($lang), 'transfer_to' => $human]);
            }
            (new VoiceMissedCallService($this->store))->record($tenantId, $ev['from'], $reason);
            return $a->capabilities()['turn_mode']
                ? self::xml($a, ['say' => VoiceLanguages::callbackPhrase($lang), 'voice' => VoiceLanguages::defaultVoice($lang), 'hangup' => true])
                : self::plain(200, 'unavailable');
        }

        $platform = new SystemSettingsService();
        $streamUrl = trim($platform->get('voice_stream_url', ''));
        $secret = (string) $platform->get('voice_bridge_secret', '');
        $stream = $streamUrl !== '' && $secret !== '' && !empty($a->capabilities()['streaming']) && preg_match('#^wss://#i', $streamUrl);
        $mode = $stream ? 'stream' : (!empty($a->capabilities()['turn_mode']) ? 'turn' : 'fallback');
        $ctx = $this->store->customerContext($tenantId, $ev['from']);
        $callId = $this->store->createCall($tenantId, $number, (int) $agent['id'], $provider, $ev['call_id'], $ev['from'], $to, $mode, $lang, $ctx['contact_id']);

        $greeting = VoicePromptBuilder::greeting($this->store->businessName($tenantId), $agent['name'] ?? null, $lang, !empty($settings['recording_enabled']));
        if ($mode === 'stream') {
            $r = $a->inboundInstructions(['say' => $greeting, 'stream_url' => $streamUrl . (strpos($streamUrl, '?') === false ? '?' : '&') . 't=' . rawurlencode(VoiceBridgeAuth::makeToken($callId, $tenantId, $secret))]);
            return [200, $r['content_type'], $r['body']];
        }
        if ($mode === 'turn') {
            return self::xml($a, [
                'say' => $greeting, 'voice' => VoiceLanguages::safeVoice($agent['voice'] ?? null, $lang), 'language' => VoiceLanguages::speechLocale($lang),
                'gather_url' => $this->turnUrl($provider, $tenantId, $callId, 1),
            ]);
        }
        // provider cannot converse yet: get the caller to a person instead of dead air
        (new VoiceMissedCallService($this->store))->record($tenantId, $ev['from'], 'provider_without_turn_mode');
        $r = $a->inboundInstructions($human ? ['forward_to' => $human] : ['say' => VoiceLanguages::callbackPhrase($lang)]);
        return [200, $r['content_type'], $r['body']];
    }

    private function turnUrl(string $provider, int $tenantId, int $callId, int $n): string
    {
        return self::webhookUrls($this->baseUrl, $provider, $tenantId)['turn'] . '?call=' . $callId . '&n=' . $n;
    }

    /** One caller utterance (speech-gather result). */
    public function handleTurn(string $provider, int $tenantId, string $url, array $headers, string $raw, array $params, int $callId, int $n): array
    {
        $a = $this->authenticate($provider, $tenantId, $url, $headers, $raw, $params);
        if (!$a) {
            return self::plain(403, 'Invalid signature');
        }
        $call = $this->store->getCall($tenantId, $callId);
        $sid = $a->parseStatusEvent($headers, $raw, $params)['call_id'] ?? '';
        if (!$call || $call['provider'] !== $provider || $call['call_sid'] !== $sid || $call['status'] !== 'in_progress' || $call['mode'] !== 'turn' || $n < 1) {
            return self::plain(200, 'ignored');
        }
        $lang = VoiceLanguages::normalize($call['language'] ?? 'en');
        $number = Database::getInstance()->query('SELECT * FROM voice_numbers WHERE id = ? AND tenant_user_id = ? LIMIT 1', [(int) $call['number_id'], $tenantId])[0] ?? [];
        $agent = Database::getInstance()->query('SELECT * FROM voice_agents WHERE id = ? AND tenant_user_id = ? LIMIT 1', [(int) $call['agent_id'], $tenantId])[0] ?? [];
        $settings = $this->settings->get($tenantId);
        $human = $this->humanTarget($number, $settings);
        $voice = VoiceLanguages::safeVoice($agent['voice'] ?? null, $lang);
        $next = fn (string $say, string $l) => self::xml($a, ['say' => $say, 'voice' => VoiceLanguages::safeVoice($agent['voice'] ?? null, $l), 'language' => VoiceLanguages::speechLocale($l), 'gather_url' => $this->turnUrl($provider, $tenantId, $callId, $n + 1)]);

        if ($n <= (int) $call['turns']) { // provider retry: repeat the stored answer, no second AI call
            foreach (array_reverse($this->store->recentTurns($tenantId, $callId, 200)) as $t) {
                if ($t['seq'] === $n * 100 + 90 && $t['role'] === 'agent') {
                    return $next($t['text'], $lang);
                }
            }
            return self::plain(200, 'ignored');
        }

        $speech = trim((string) ($params['SpeechResult'] ?? ''));
        if ($speech === '') {
            $silent = (int) $call['silent_turns'] + 1;
            $this->store->updateCall($tenantId, $callId, ['silent_turns' => $silent]);
            if ($silent >= 2) {
                $this->store->updateCall($tenantId, $callId, ['outcome' => 'no_response']);
                return self::xml($a, ['say' => VoiceLanguages::goodbyePhrase($lang), 'voice' => $voice, 'hangup' => true]);
            }
            return self::xml($a, ['say' => VoiceLanguages::repromptPhrase($lang), 'voice' => $voice, 'language' => VoiceLanguages::speechLocale($lang), 'gather_url' => $this->turnUrl($provider, $tenantId, $callId, $n)]);
        }

        if (time() - (int) strtotime($call['started_at']) > self::MAX_SECONDS) {
            return $this->wrapUp($a, $call, $lang, $voice, $human, 'max_duration');
        }
        $out = (new VoiceAgentBrain($this->store, $this->llm))->respond($call, $agent, $speech, $n, $human !== null);
        $l = $out['language'];
        if ($out['transfer'] !== null && $human) {
            $this->store->updateCall($tenantId, $callId, ['status' => 'transferred', 'handoff_reason' => mb_substr($out['transfer'], 0, 200), 'outcome' => 'transferred']);
            return self::xml($a, ['say' => $out['say'], 'voice' => VoiceLanguages::safeVoice($agent['voice'] ?? null, $l), 'transfer_to' => $human]);
        }
        if ($out['end']) {
            $this->store->updateCall($tenantId, $callId, ['outcome' => $out['outcome'] ?? 'resolved']);
            if (($out['outcome'] ?? '') === 'callback_requested') {
                (new VoiceMissedCallService($this->store))->record($tenantId, (string) $call['from_e164'], 'callback_requested');
            }
            return self::xml($a, ['say' => $out['say'], 'voice' => VoiceLanguages::safeVoice($agent['voice'] ?? null, $l), 'hangup' => true]);
        }
        return $next($out['say'], $l);
    }

    private function wrapUp(VoiceProviderAdapter $a, array $call, string $lang, string $voice, ?string $human, string $why): array
    {
        $tenantId = (int) $call['tenant_user_id'];
        if ($human) {
            $this->store->updateCall($tenantId, (int) $call['id'], ['status' => 'transferred', 'handoff_reason' => $why, 'outcome' => 'transferred']);
            return self::xml($a, ['say' => VoiceLanguages::handoffPhrase($lang), 'voice' => $voice, 'transfer_to' => $human]);
        }
        $this->store->updateCall($tenantId, (int) $call['id'], ['outcome' => 'callback_requested']);
        (new VoiceMissedCallService($this->store))->record($tenantId, (string) $call['from_e164'], $why);
        return self::xml($a, ['say' => VoiceLanguages::callbackPhrase($lang), 'voice' => $voice, 'hangup' => true]);
    }

    /** Provider status callback for an inbound call: close the call record, recover calls hung up before the AI said anything. */
    public function onStatus(int $tenantId, string $provider, array $ev): void
    {
        $call = $this->store->findBySid($tenantId, $provider, (string) $ev['call_id']);
        if (!$call || !in_array($ev['status'], ['completed', 'busy', 'failed', 'no-answer', 'canceled'], true)) {
            return;
        }
        $fields = ['ended_at' => date('Y-m-d H:i:s'), 'duration_s' => $ev['duration_s']];
        $earlyHangup = (int) $call['turns'] === 0 && $call['status'] === 'in_progress' && ($ev['status'] !== 'completed' || (int) ($ev['duration_s'] ?? 0) < 8);
        if ($earlyHangup) {
            $fields['status'] = 'missed';
            $fields['outcome'] = 'hung_up_early';
            (new VoiceMissedCallService($this->store))->record($tenantId, (string) $call['from_e164'], 'caller_hung_up');
        } elseif ($call['status'] === 'in_progress') {
            $fields['status'] = in_array($ev['status'], ['failed', 'canceled'], true) ? 'failed' : 'completed';
        }
        $this->store->updateCall($tenantId, (int) $call['id'], $fields);
    }
}

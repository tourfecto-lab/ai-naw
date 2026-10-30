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
        return ['inbound' => $b . '/inbound', 'status' => $b . '/status', 'turn' => $b . '/turn', 'outbound' => $b . '/outbound', 'whisper' => $b . '/whisper'];
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

    /** Who a handed-over call goes to: a staff member on shift who handles the topic, else the number's own line, else the company handoff phone. */
    private function handoffPhone(array $staff, array $number, array $settings, ?string $topic): ?string
    {
        $p = VoiceStaffRouting::pick($staff, time(), $settings['timezone'] ?? null, $topic);
        return $p ? (string) $p['phone'] : $this->humanTarget($number, $settings);
    }

    /** What the person hears (only they, before the caller is connected) when the AI hands a call over. */
    public function handleWhisper(string $provider, int $tenantId, string $url, array $headers, string $raw, array $params, int $callId): array
    {
        $a = $this->authenticate($provider, $tenantId, $url, $headers, $raw, $params);
        $call = $a ? $this->store->getCall($tenantId, $callId) : null;
        if (!$a || !$call) {
            return $a ? self::plain(200, 'ignored') : self::plain(403, 'Invalid signature');
        }
        $q = json_decode((string) ($call['qualification'] ?? ''), true) ?: [];
        $br = json_decode((string) ($call['booking_request'] ?? ''), true) ?: [];
        $lang = VoiceLanguages::normalize($call['language'] ?? 'en');
        $text = VoiceHandoff::whisperText($lang, $this->store->businessName($tenantId), ['reason' => (string) ($call['handoff_reason'] ?? ''), 'interest' => (string) ($q['interest'] ?? ''), 'booking' => $br]);
        $r = $a->turnInstructions(['say' => $text, 'voice' => VoiceLanguages::defaultVoice($lang)]); // say + hang up this leg's TwiML: Twilio then bridges the call
        $r['body'] = str_replace('<Hangup/>', '', $r['body']);
        return [200, $r['content_type'], $r['body']];
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
        $staff = (new VoiceStaffService())->forRouting($tenantId);
        $human = $this->handoffPhone($staff, $number, $settings, null);
        $ctx = $this->store->customerContext($tenantId, $ev['from']);
        $afterHours = !VoiceHours::isOpen($this->store->businessHoursRows($tenantId), $settings['timezone'] ?? null, time());
        $routed = VoiceRouting::choose(json_decode((string) ($number['routing_json'] ?? ''), true) ?: [], $number['agent_id'] ? (int) $number['agent_id'] : null, [
            'known_customer' => $ctx['known'], 'has_booking' => !empty($ctx['bookings']), 'after_hours' => $afterHours, 'caller_country' => VoiceMarkets::countryOf($ev['from']),
        ]);
        $agent = null;
        foreach (array_unique(array_filter([$routed, $number['agent_id'] ? (int) $number['agent_id'] : null])) as $candidate) { // a routed assistant that is not active falls back to the number's own
            $row = Database::getInstance()->query("SELECT * FROM voice_agents WHERE id = ? AND tenant_user_id = ? LIMIT 1", [(int) $candidate, $tenantId])[0] ?? null;
            if ($row && $row['status'] === 'active') {
                $agent = $row;
                break;
            }
            $agent = $agent ?? $row;
        }
        $lang = VoiceLanguages::normalize($agent['language'] ?? $settings['default_language'] ?? 'en');

        $awaiting = $number['status'] === 'awaiting_link_test' && $number['verification_mode'] === 'live';
        $usable = VoiceNumberRules::liveUsable($number) || $awaiting;
        $budget = isset($settings['monthly_budget_usd']) && $settings['monthly_budget_usd'] !== null ? (float) $settings['monthly_budget_usd'] : null;
        $reason = null;
        if (!$usable) {
            $reason = 'number_not_live';
        } elseif (!VoiceAccessService::featureEnabled($tenantId)) {
            $reason = 'subscription_inactive'; // plan ended or downgraded: callers still get a polite answer, never silence
        } elseif (empty($settings['enabled'])) {
            $reason = 'module_disabled';
        } elseif (!$agent || $agent['status'] !== 'active') {
            $reason = 'no_active_agent';
        } elseif (($why = (new VoiceBudgetService())->blockReason($tenantId, $settings)) !== null) {
            $reason = $why;
        } else {
            $platformCap = (int) (new SystemSettingsService())->get('voice_platform_max_concurrent', (string) VoiceCapacity::DEFAULT_PLATFORM_CAP);
            $cap = VoiceCapacity::decide($this->store->activeCalls($tenantId), (int) ($settings['max_concurrent_calls'] ?? 3), VoicePlatformLoad::activeCalls(), $platformCap);
            $reason = $cap['allowed'] ? null : 'at_capacity';
        }
        if ($awaiting) {
            (new VoiceNumberService($this->providers))->recordInboundArrival($tenantId, $to);
        }
        $afterMode = (string) ($settings['after_hours_mode'] ?? 'ai');
        if ($reason === null && $afterHours && $afterMode === 'forward' && $human && $a->capabilities()['turn_mode']) {
            $this->store->createCall($tenantId, $number, (int) $agent['id'], $provider, $ev['call_id'], $ev['from'], $to, 'fallback', $lang, $ctx['contact_id']);
            if ($c = $this->store->findBySid($tenantId, $provider, $ev['call_id'])) {
                $this->store->updateCall($tenantId, (int) $c['id'], ['status' => 'transferred', 'outcome' => 'transferred_after_hours', 'handoff_reason' => 'after_hours_forward', 'after_hours' => 1]);
            }
            return self::xml($a, ['say' => VoiceLanguages::handoffPhrase($lang), 'voice' => VoiceLanguages::defaultVoice($lang), 'transfer_to' => $human]);
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
        $callId = $this->store->createCall($tenantId, $number, (int) $agent['id'], $provider, $ev['call_id'], $ev['from'], $to, $mode, $lang, $ctx['contact_id']);
        if ($afterHours && $afterMode !== 'ai') {
            $this->store->updateCall($tenantId, $callId, ['after_hours' => 1]); // closed: take a message, never promise a person
        }
        if (!empty($number['utm_link_id'])) { // a dedicated tracking number: the only trustworthy ad attribution for a phone call
            $this->store->updateCall($tenantId, $callId, ['utm_link_id' => (int) $number['utm_link_id'], 'attribution_source' => $number['attribution_source'] ?? null]);
        }
        $rec = VoiceRecordingService::decide((string) $ev['from'], $settings); // one decision for both the notice and the recording
        $greeting = VoicePromptBuilder::greeting($this->store->businessName($tenantId), $agent['name'] ?? null, $lang, $rec['record'], $agent['opening_text'] ?? null);
        if ($rec['record'] && $mode !== 'fallback') {
            (new VoiceRecordingService($this->providers))->start($tenantId, $callId, $provider, (string) $ev['call_id'], $settings);
        }
        if ($afterHours && $afterMode !== 'ai') {
            $greeting .= ' ' . VoiceLanguages::afterHoursPhrase($lang);
        }
        if ($mode === 'stream') {
            // Twilio ignores a query string on the stream URL, so the per-call token travels as a stream parameter
            $r = $a->inboundInstructions(['say' => $greeting, 'stream_url' => $streamUrl, 'stream_params' => ['token' => VoiceBridgeAuth::makeToken($callId, $tenantId, $secret)]]);
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
        $staff = (new VoiceStaffService())->forRouting($tenantId);
        $human = !empty($call['after_hours']) ? null : $this->handoffPhone($staff, $number, $settings, null);
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
        $customerNumber = VoiceAgentBrain::customerNumber($call);
        if (VoiceOptOut::detect($speech)) {
            $this->optOut($tenantId, $call, $customerNumber);
            return self::xml($a, ['say' => VoiceLanguages::optOutPhrase($lang), 'voice' => $voice, 'hangup' => true]);
        }
        if (!empty($call['job_id'])) {
            $job = Database::getInstance()->query('SELECT purpose, context_json FROM voice_outbound_jobs WHERE id = ? AND tenant_user_id = ? LIMIT 1', [(int) $call['job_id'], $tenantId])[0] ?? null;
            if ($job) {
                $call['outbound'] = ['purpose' => $job['purpose'], 'facts' => json_decode((string) $job['context_json'], true) ?: []];
            }
        }
        $t0 = microtime(true);
        $out = (new VoiceAgentBrain($this->store, $this->llm))->respond($call, $agent, $speech, $n, $human !== null);
        $this->store->updateCall($tenantId, $callId, ['latency_ms_add' => (int) round((microtime(true) - $t0) * 1000)]);
        $l = $out['language'];
        if (!empty($out['opt_out'])) {
            $this->optOut($tenantId, $call, $customerNumber);
            return self::xml($a, ['say' => $out['say'], 'voice' => VoiceLanguages::safeVoice($agent['voice'] ?? null, $l), 'hangup' => true]);
        }
        if ($out['transfer'] !== null && $human) {
            $target = $this->handoffPhone($staff, $number, $settings, $out['topic'] ?? null) ?? $human; // the person who handles this topic, if on shift
            $this->store->updateCall($tenantId, $callId, ['status' => 'transferred', 'handoff_reason' => mb_substr($out['transfer'], 0, 200), 'handoff_topic' => $out['topic'] ?? null, 'outcome' => 'transferred']);
            return self::xml($a, ['say' => $out['say'], 'voice' => VoiceLanguages::safeVoice($agent['voice'] ?? null, $l), 'transfer_to' => $target, 'whisper_url' => self::webhookUrls($this->baseUrl, $provider, $tenantId)['whisper'] . '?call=' . $callId]);
        }
        if ($out['end']) {
            $this->store->updateCall($tenantId, $callId, ['outcome' => $out['outcome'] ?? 'resolved']);
            if (($out['outcome'] ?? '') === 'callback_requested') {
                (new VoiceMissedCallService($this->store))->record($tenantId, $customerNumber, 'callback_requested');
            }
            return self::xml($a, ['say' => $out['say'], 'voice' => VoiceLanguages::safeVoice($agent['voice'] ?? null, $l), 'hangup' => true]);
        }
        return $next($out['say'], $l);
    }

    private function optOut(int $tenantId, array $call, string $customerNumber): void
    {
        try {
            (new VoiceConsentService())->optOut($tenantId, $customerNumber, (int) $call['id']);
        } catch (Throwable $e) {
            Logger::error('Voice opt-out failed', ['tenant' => $tenantId, 'error' => $e->getMessage()]);
        }
        $this->store->updateCall($tenantId, (int) $call['id'], ['outcome' => 'opted_out']);
    }

    private function wrapUp(VoiceProviderAdapter $a, array $call, string $lang, string $voice, ?string $human, string $why): array
    {
        $tenantId = (int) $call['tenant_user_id'];
        if ($human) {
            $this->store->updateCall($tenantId, (int) $call['id'], ['status' => 'transferred', 'handoff_reason' => $why, 'outcome' => 'transferred']);
            return self::xml($a, ['say' => VoiceLanguages::handoffPhrase($lang), 'voice' => $voice, 'transfer_to' => $human]);
        }
        $this->store->updateCall($tenantId, (int) $call['id'], ['outcome' => 'callback_requested']);
        (new VoiceMissedCallService($this->store))->record($tenantId, VoiceAgentBrain::customerNumber($call), $why);
        return self::xml($a, ['say' => VoiceLanguages::callbackPhrase($lang), 'voice' => $voice, 'hangup' => true]);
    }

    /** Provider status callback for an inbound call: close the call record, recover calls hung up before the AI said anything. */
    public function onStatus(int $tenantId, string $provider, array $ev): void
    {
        if ((new VoiceOutboundService($this->providers, null, $this->store, $this->baseUrl))->onStatusBySid($tenantId, $provider, $ev)) {
            return; // an outbound call: the job decides what happens next
        }
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
        VoicePostCallService::markPending($this->store, $tenantId, ($this->store->getCall($tenantId, (int) $call['id']) ?? $call));
    }
}

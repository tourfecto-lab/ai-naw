<?php

/**
 * Tourfecto - AI Voice Agent: one conversational turn. Calls the platform AI router, runs at most two lookup tools,
 * validates everything the model says, and stores the turn. The LLM is injected so tests never touch the network.
 * Returns what the telephony layer should do next.
 */
class VoiceAgentBrain
{
    public const MAX_TURNS = 25;
    public const MAX_LOOKUPS_PER_TURN = 2;

    private VoiceDataSource $data;
    /** @var callable(string,array):array */
    private $llm;

    public function __construct(VoiceDataSource $data, ?callable $llm = null)
    {
        $this->data = $data;
        $this->llm = $llm ?? static function (string $prompt, array $opts): array {
            return (new AIOrchestrator())->generateContent($prompt, $opts);
        };
    }

    /** The customer's number: the caller on inbound calls, the dialled party on outbound ones. */
    public static function customerNumber(array $call): string
    {
        return (string) (($call['direction'] ?? 'inbound') === 'outbound' ? ($call['to_e164'] ?? '') : ($call['from_e164'] ?? ''));
    }

    /**
     * @return array{say:string, language:string, end:bool, transfer:?string, outcome:?string, opt_out:bool}
     */
    public function respond(array $call, array $agent, string $utterance, int $seq, bool $canTransfer): array
    {
        $tenant = (int) $call['tenant_user_id'];
        $callId = (int) $call['id'];
        $lang = VoiceLanguages::normalize($call['language'] ?? 'en');
        // turn n owns the sequence range n*100..n*100+99: caller n*100, tools +1.., final agent reply +90
        $base = $seq * 100;
        $this->data->addTurn($tenant, $callId, $base, 'caller', mb_substr($utterance, 0, 600));

        $ctx = $this->data->customerContext($tenant, self::customerNumber($call));
        $business = $this->data->businessName($tenant);
        $parsed = null;
        $lookups = 0;
        $cost = 0.0;
        for ($i = 0; $i <= self::MAX_LOOKUPS_PER_TURN; $i++) {
            $call = $this->data->getCall($tenant, $callId) ?? $call;
            $prompt = VoicePromptBuilder::build($call, $ctx, $this->data->recentTurns($tenant, $callId, 14), $agent, $business, $canTransfer, date('Y-m-d'));
            $r = ($this->llm)($prompt, ['task' => 'voice_agent', 'user_id' => $tenant, 'responseMimeType' => 'application/json', 'temperature' => 0.3, 'maxOutputTokens' => 400]);
            $cost += (float) ($r['cost'] ?? 0);
            $parsed = !empty($r['success']) ? VoiceReplyParser::parse((string) ($r['data'] ?? '')) : null;
            if ($parsed === null) {
                break;
            }
            if ($parsed['language']) {
                $lang = $parsed['language'];
            }
            if (in_array($parsed['action']['type'], VoiceReplyParser::READ_ACTIONS, true) && $lookups < self::MAX_LOOKUPS_PER_TURN) {
                $lookups++;
                $this->data->addTurn($tenant, $callId, $base + $lookups, 'tool', json_encode($this->runLookup($tenant, $call, $parsed['action']), JSON_UNESCAPED_UNICODE), ['action' => $parsed['action']['type']]);
                continue;
            }
            break;
        }
        $this->data->updateCall($tenant, $callId, ['ai_cost_usd_add' => $cost, 'language' => $lang, 'turns' => $seq]);

        if ($parsed === null) {
            // Model unavailable or unusable: never improvise. Hand over, or promise a callback.
            $say = $canTransfer ? VoiceLanguages::handoffPhrase($lang) : VoiceLanguages::callbackPhrase($lang);
            $this->data->addTurn($tenant, $callId, $base + 90, 'agent', $say, ['fallback' => true]);
            return ['say' => $say, 'language' => $lang, 'end' => !$canTransfer, 'transfer' => $canTransfer ? 'ai_unavailable' : null, 'outcome' => $canTransfer ? null : 'callback_requested', 'opt_out' => false];
        }

        $a = $parsed['action'];
        $say = $parsed['say'];
        if ($say === '' || in_array($a['type'], VoiceReplyParser::READ_ACTIONS, true)) {
            $say = VoiceLanguages::fallbackPhrase($lang); // model still wanted a lookup after the limit
        }
        $bad = VoiceReplyParser::ungroundedPrices($say, $this->allowedPrices($tenant, $callId));
        if ($bad) {
            $say = VoiceLanguages::fallbackPhrase($lang);
        }
        $out = ['say' => $say, 'language' => $lang, 'end' => false, 'transfer' => null, 'outcome' => null, 'opt_out' => false];

        switch ($a['type']) {
            case 'opt_out':
                $out['opt_out'] = true;
                $out['end'] = true;
                $out['say'] = VoiceLanguages::optOutPhrase($lang);
                $out['outcome'] = 'opted_out';
                break;
            case 'record_survey':
            case 'qualify_lead':
            case 'request_booking':
                if (!$this->persist($tenant, $call, $a)) {
                    $out['say'] = VoiceLanguages::fallbackPhrase($lang);
                }
                break;
            case 'transfer_human':
                if ($canTransfer) {
                    $out['transfer'] = $a['reason'] !== '' ? $a['reason'] : 'requested';
                    $out['say'] = VoiceLanguages::handoffPhrase($lang);
                } else {
                    $out['say'] = VoiceLanguages::callbackPhrase($lang);
                    $out['end'] = true;
                    $out['outcome'] = 'callback_requested';
                }
                break;
            case 'end_call':
                $out['end'] = true;
                $out['outcome'] = $a['outcome'];
                break;
        }
        if ($seq >= self::MAX_TURNS && !$out['end'] && $out['transfer'] === null) {
            $out['say'] = $canTransfer ? VoiceLanguages::handoffPhrase($lang) : VoiceLanguages::callbackPhrase($lang);
            $out['transfer'] = $canTransfer ? 'max_turns' : null;
            $out['end'] = !$canTransfer;
            $out['outcome'] = $canTransfer ? null : 'callback_requested';
        }
        $this->data->addTurn($tenant, $callId, $base + 90, 'agent', $out['say'], ['action' => $a['type']]);
        return $out;
    }

    /** Lookup tools. Results are stored as tool turns so the next prompt (and the price guard) can see them. */
    private function runLookup(int $tenant, array $call, array $a): array
    {
        switch ($a['type']) {
            case 'search_trips':
                $trips = $this->data->searchTrips($tenant, $a['query']);
                return ['tool' => 'search_trips', 'results' => $trips, 'note' => $trips ? 'Prices are per person unless the description says otherwise.' : 'No matching trips.'];
            case 'check_availability':
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $a['date']) || $a['product_id'] < 1) {
                    return ['tool' => 'check_availability', 'error' => 'Need a trip id from search_trips and a valid date YYYY-MM-DD'];
                }
                return ['tool' => 'check_availability', 'result' => $this->data->tripAvailability($tenant, $a['product_id'], $a['date'], $a['guests'])];
            case 'verify_identity':
                if (empty($call['contact_id']) && !$this->data->customerContext($tenant, self::customerNumber($call))['known']) {
                    return ['tool' => 'verify_identity', 'verified' => false, 'note' => 'No customer record for this number.'];
                }
                if (VoiceIdentity::attemptsLeft((int) $call['verify_attempts']) < 1) {
                    return ['tool' => 'verify_identity', 'verified' => false, 'note' => 'No attempts left. Offer a human callback.'];
                }
                $ctx = $this->data->customerContext($tenant, self::customerNumber($call));
                $cid = (int) ($call['contact_id'] ?: $ctx['contact_id']);
                $ok = $cid > 0 && VoiceIdentity::matches($a['answer'], $this->data->identityFactors($tenant, $cid));
                $this->data->updateCall($tenant, (int) $call['id'], $ok ? ['identity_verified' => 1, 'contact_id' => $cid] : ['verify_attempts_add' => 1]);
                return ['tool' => 'verify_identity', 'verified' => $ok, 'note' => $ok ? 'Identity verified; existing bookings may now be discussed.' : 'Did not match.'];
        }
        return ['tool' => $a['type'], 'error' => 'unsupported'];
    }

    /** Every amount a tool returned in this call (unit and total prices). */
    private function allowedPrices(int $tenant, int $callId): array
    {
        $allowed = [];
        foreach ($this->data->recentTurns($tenant, $callId, 60) as $t) {
            if ($t['role'] !== 'tool') {
                continue;
            }
            $j = json_decode($t['text'], true);
            foreach (($j['results'] ?? []) as $r) {
                $allowed[] = (float) ($r['price'] ?? 0);
            }
            foreach (['unit_price', 'total_price'] as $k) {
                if (isset($j['result'][$k])) {
                    $allowed[] = (float) $j['result'][$k];
                }
            }
        }
        return $allowed;
    }

    /** Saves lead qualification / a booking request on the call. False = a booking request that could not be honoured. */
    private function persist(int $tenant, array $call, array $a): bool
    {
        if ($a['type'] === 'record_survey') {
            $this->mergeJson($tenant, $call, 'qualification', ['survey_score' => $a['score'], 'survey_comment' => $a['comment']]);
            return true;
        }
        if ($a['type'] === 'qualify_lead') {
            $this->mergeJson($tenant, $call, 'qualification', array_diff_key($a, ['type' => 1]));
            return true;
        }
        if ($a['type'] === 'request_booking') {
            if ($a['product_id'] < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $a['date'])) {
                return false;
            }
            $av = $this->data->tripAvailability($tenant, $a['product_id'], $a['date'], $a['guests']);
            if (empty($av['found']) || empty($av['available'])) {
                return false;
            }
            $this->mergeJson($tenant, $call, 'booking_request', array_diff_key($a, ['type' => 1]) + ['quoted_total' => $av['total_price'] ?? null, 'currency' => $av['currency'] ?? null]);
        }
        return true;
    }

    /**
     * Tool entry point for an external realtime bridge whose own model decides what to do. The same validation and tenant scoping apply.
     * @return array<string,mixed>
     */
    public function bridgeTool(array $call, array $rawAction): array
    {
        $parsed = VoiceReplyParser::parse(json_encode(['say' => '', 'action' => $rawAction]));
        $a = $parsed['action'] ?? ['type' => 'none'];
        $tenant = (int) $call['tenant_user_id'];
        if (in_array($a['type'], VoiceReplyParser::READ_ACTIONS, true)) {
            $r = $this->runLookup($tenant, $call, $a);
            $n = count($this->data->recentTurns($tenant, (int) $call['id'], 300));
            $this->data->addTurn($tenant, (int) $call['id'], 900000 + $n, 'tool', json_encode($r, JSON_UNESCAPED_UNICODE), ['action' => $a['type'], 'bridge' => true]);
            return $r;
        }
        if (in_array($a['type'], ['qualify_lead', 'request_booking'], true)) {
            return ['tool' => $a['type'], 'saved' => $this->persist($tenant, $call, $a)];
        }
        return ['tool' => $a['type'], 'error' => 'unsupported_for_bridge'];
    }

    private function mergeJson(int $tenant, array $call, string $field, array $new): void
    {
        $cur = json_decode((string) (($this->data->getCall($tenant, (int) $call['id'])[$field] ?? '') ?: '{}'), true) ?: [];
        foreach ($new as $k => $v) {
            if ($v !== '' && $v !== null) {
                $cur[$k] = $v;
            }
        }
        $this->data->updateCall($tenant, (int) $call['id'], [$field => json_encode($cur, JSON_UNESCAPED_UNICODE)]);
    }
}

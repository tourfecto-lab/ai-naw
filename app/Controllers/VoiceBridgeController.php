<?php

/**
 * Tourfecto - AI Voice Agent: API used by an external realtime audio bridge (streaming mode).
 * No session: every request carries the per-call token from the stream URL (X-Voice-Token) plus an HMAC of the body
 * signed with the platform bridge secret (X-Voice-Timestamp / X-Voice-Signature). Only the call named in the token is reachable.
 */
class VoiceBridgeController extends Controller
{
    private function deny(int $code, string $msg): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $msg]);
        exit;
    }

    /** @return array{call:array, body:array, store:VoiceDataStore} */
    private function authed(): array
    {
        $secret = (string) (new SystemSettingsService())->get('voice_bridge_secret', '');
        $raw = (string) file_get_contents('php://input');
        $ts = (int) ($_SERVER['HTTP_X_VOICE_TIMESTAMP'] ?? 0);
        if (!VoiceBridgeAuth::verifyRequest($raw, $ts, (string) ($_SERVER['HTTP_X_VOICE_SIGNATURE'] ?? ''), $secret)) {
            $this->deny(403, 'bad signature');
        }
        $tok = VoiceBridgeAuth::verifyToken((string) ($_SERVER['HTTP_X_VOICE_TOKEN'] ?? ''), $secret);
        if (!$tok) {
            $this->deny(403, 'bad token');
        }
        $store = new VoiceDataStore();
        $call = $store->getCall($tok['tenant_id'], $tok['call_id']);
        if (!$call || $call['status'] !== 'in_progress' || $call['mode'] !== 'stream') {
            $this->deny(404, 'call not active');
        }
        $body = json_decode($raw, true);
        return ['call' => $call, 'body' => is_array($body) ? $body : [], 'store' => $store];
    }

    private function out(array $data): array
    {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** POST /api/voice-bridge/context - what the bridge's model needs to start the conversation */
    public function context(array $params = []): array
    {
        $x = $this->authed();
        $call = $x['call'];
        $t = (int) $call['tenant_user_id'];
        $agent = Database::getInstance()->query('SELECT * FROM voice_agents WHERE id = ? AND tenant_user_id = ? LIMIT 1', [(int) $call['agent_id'], $t])[0] ?? [];
        $lang = VoiceLanguages::normalize($call['language'] ?? 'en');
        $ctx = $x['store']->customerContext($t, (string) $call['from_e164']);
        $number = Database::getInstance()->query('SELECT forward_to FROM voice_numbers WHERE id = ? AND tenant_user_id = ? LIMIT 1', [(int) $call['number_id'], $t])[0] ?? [];
        $settings = (new VoiceSettingsService())->get($t);
        $canTransfer = !empty($number['forward_to']) || !empty($settings['human_handoff_phone']);
        return $this->out([
            'call_id' => (int) $call['id'], 'language' => $lang, 'voice' => VoiceLanguages::safeVoice($agent['voice'] ?? null, $lang),
            'system_prompt' => VoicePromptBuilder::build($call, $ctx, [], $agent, $x['store']->businessName($t), $canTransfer, date('Y-m-d')),
            'transfer_to' => ($number['forward_to'] ?? null) ?: ($settings['human_handoff_phone'] ?? null),
        ]);
    }

    /** POST /api/voice-bridge/tool {action:{type:...}} - run a lookup or save qualification/booking request */
    public function tool(array $params = []): array
    {
        $x = $this->authed();
        $action = is_array($x['body']['action'] ?? null) ? $x['body']['action'] : [];
        return $this->out((new VoiceAgentBrain($x['store']))->bridgeTool($x['call'], $action));
    }

    /** POST /api/voice-bridge/end {outcome, turns:[{role,text}]} - close the call and keep the transcript */
    public function end(array $params = []): array
    {
        $x = $this->authed();
        $call = $x['call'];
        $t = (int) $call['tenant_user_id'];
        $n = 0;
        foreach (array_slice((array) ($x['body']['turns'] ?? []), 0, 200) as $turn) {
            $role = ($turn['role'] ?? '') === 'agent' ? 'agent' : 'caller';
            $x['store']->addTurn($t, (int) $call['id'], 100000 + $n++, $role, mb_substr((string) ($turn['text'] ?? ''), 0, 600), ['bridge' => true]);
        }
        $o = (string) ($x['body']['outcome'] ?? 'other');
        $x['store']->updateCall($t, (int) $call['id'], ['turns' => $n, 'outcome' => in_array($o, VoiceReplyParser::OUTCOMES, true) ? $o : 'other']);
        return $this->out(['turns_saved' => $n]);
    }
}

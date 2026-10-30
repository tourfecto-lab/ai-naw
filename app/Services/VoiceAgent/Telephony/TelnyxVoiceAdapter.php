<?php

/**
 * Tourfecto - AI Voice Agent: Telnyx adapter (v2 REST + Call Control).
 * Written against Telnyx's public API docs; exercised in tests with a fake transport only (no live account here).
 * Per-call cost is not looked up in this phase (cost_lookup=false): costs are recorded as estimates by VoiceCostTracker.
 */
class TelnyxVoiceAdapter extends AbstractVoiceProviderAdapter
{
    private const API = 'https://api.telnyx.com/v2';
    private const TYPES = ['local', 'toll_free', 'national', 'mobile'];

    public function key(): string
    {
        return 'telnyx';
    }

    public static function credentialFields(): array
    {
        return ['api_key', 'connection_id', 'public_key'];
    }

    public function capabilities(): array
    {
        return ['numbers_search' => true, 'numbers_purchase' => true, 'inbound' => true, 'outbound' => true, 'sip' => true,
            'transfer' => true, 'recording' => true, 'streaming' => true, 'porting' => false, 'cost_lookup' => false];
    }

    private function h(): array
    {
        return ['Authorization' => 'Bearer ' . $this->cred['api_key'], 'Content-Type' => 'application/json', 'Accept' => 'application/json'];
    }

    private function call(string $method, string $path, ?array $body = null): array
    {
        return $this->json($method, self::API . $path, $body === null ? null : json_encode($body), $this->h());
    }

    protected function errorOf(array $d, int $http): array
    {
        $e = $d['errors'][0] ?? [];
        return [(string) ($e['detail'] ?? $e['title'] ?? ('Telnyx HTTP ' . $http)), (string) ($e['code'] ?? '')];
    }

    public function testConnection(): array
    {
        $d = $this->call('GET', '/balance');
        $ok = isset($d['data']);
        return ['ok' => $ok, 'account' => '', 'detail' => $ok ? 'balance ' . ($d['data']['balance'] ?? '?') . ' ' . ($d['data']['currency'] ?? '') : 'unexpected response'];
    }

    public function listCountries(): array
    {
        $d = $this->call('GET', '/country_coverage');
        $out = [];
        foreach ((array) ($d['data'] ?? []) as $iso => $c) {
            $types = [];
            foreach (self::TYPES as $t) {
                if (!empty($c[$t]) || !empty($c[str_replace('_', '', $t)])) {
                    $types[] = $t;
                }
            }
            $out[] = ['iso' => strtoupper((string) ($c['code'] ?? $iso)), 'name' => (string) ($c['country'] ?? $c['name'] ?? $iso), 'types' => $types];
        }
        return $out;
    }

    public function searchNumbers(string $iso, string $type, array $filters = [], int $limit = 20): array
    {
        $iso = strtoupper(trim($iso));
        if (!preg_match('/^[A-Z]{2}$/', $iso) || !in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Invalid country or number type');
        }
        $q = ['filter[country_code]' => $iso, 'filter[phone_number_type]' => $type, 'filter[limit]' => max(1, min(50, $limit)), 'filter[features][]' => 'voice'];
        if (!empty($filters['contains'])) {
            $q['filter[phone_number][contains]'] = preg_replace('/[^0-9]/', '', (string) $filters['contains']);
        }
        $d = $this->call('GET', '/available_phone_numbers?' . http_build_query($q));
        $out = [];
        foreach ($d['data'] ?? [] as $n) {
            $features = array_column((array) ($n['features'] ?? []), 'name');
            $cost = (array) ($n['cost_information'] ?? []);
            $out[] = [
                'number' => (string) $n['phone_number'], 'country' => $iso, 'type' => $type,
                'monthly_cost' => isset($cost['monthly_cost']) ? (float) $cost['monthly_cost'] : null,
                'setup_cost' => isset($cost['upfront_cost']) ? (float) $cost['upfront_cost'] : 0.0,
                'currency' => (string) ($cost['currency'] ?? 'USD'),
                'voice' => in_array('voice', $features, true), 'sms' => in_array('sms', $features, true),
                'address_required' => 'unknown', // Telnyx publishes requirements per country/order, checked at order time (Phase 3)
                'provider' => 'telnyx',
            ];
        }
        return $out;
    }

    public function purchaseNumber(string $e164, array $opts = []): array
    {
        $order = ['phone_numbers' => [['phone_number' => self::e164($e164)]], 'connection_id' => (string) $this->cred['connection_id']];
        $d = $this->call('POST', '/number_orders', $order);
        $id = (string) ($d['data']['id'] ?? '');
        return ['number_id' => $id, 'number' => self::e164($e164), 'status' => (string) ($d['data']['status'] ?? 'pending')];
    }

    public function releaseNumber(string $numberId): bool
    {
        $this->call('DELETE', '/phone_numbers/' . rawurlencode($numberId));
        return true;
    }

    public function startCall(string $from, string $to, array $opts = []): array
    {
        $body = ['connection_id' => (string) $this->cred['connection_id'], 'from' => self::e164($from), 'to' => self::e164($to)];
        if (!empty($opts['status_callback'])) {
            $body['webhook_url'] = (string) $opts['status_callback'];
        }
        if (!empty($opts['record'])) {
            $body['record'] = 'record-from-answer';
        }
        if (!empty($opts['machine_detection'])) {
            $body['answering_machine_detection'] = 'detect';
        }
        $d = $this->call('POST', '/calls', $body);
        return ['call_id' => (string) ($d['data']['call_control_id'] ?? ''), 'status' => 'queued'];
    }

    public function transferCall(string $callId, string $toE164): array
    {
        $this->call('POST', '/calls/' . rawurlencode($callId) . '/actions/transfer', ['to' => self::e164($toE164)]);
        return ['call_id' => $callId, 'status' => 'transferring'];
    }

    public function hangupCall(string $callId): bool
    {
        $this->call('POST', '/calls/' . rawurlencode($callId) . '/actions/hangup', []);
        return true;
    }

    /** Telnyx is command driven: the webhook reply is empty and these actions are sent to the Call Control API. */
    public function inboundInstructions(array $opts): array
    {
        $cmds = [['action' => 'answer', 'body' => []]];
        if (!empty($opts['stream_url'])) {
            if (!preg_match('#^wss://#i', (string) $opts['stream_url'])) {
                throw new InvalidArgumentException('stream_url must be wss://');
            }
            $cmds[] = ['action' => 'streaming_start', 'body' => ['stream_url' => (string) $opts['stream_url']]];
        } elseif (!empty($opts['forward_to'])) {
            $cmds[] = ['action' => 'transfer', 'body' => ['to' => self::e164($opts['forward_to'])]];
        } elseif (!empty($opts['sip_uri'])) {
            $cmds[] = ['action' => 'transfer', 'body' => ['to' => (string) $opts['sip_uri']]];
        }
        if (!empty($opts['record'])) {
            $cmds[] = ['action' => 'record_start', 'body' => ['format' => 'mp3', 'channels' => 'dual']];
        }
        return ['content_type' => 'application/json', 'body' => json_encode(['commands' => $cmds])];
    }

    /** ed25519 over "{timestamp}|{rawBody}", 5 minute replay window. */
    public function verifyWebhook(string $url, array $headers, string $rawBody, array $params): bool
    {
        $sig = $headers['telnyx-signature-ed25519'] ?? '';
        $ts = $headers['telnyx-timestamp'] ?? '';
        if ($sig === '' || $ts === '' || !ctype_digit($ts) || abs(time() - (int) $ts) > 300 || !function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }
        $s = base64_decode($sig, true);
        $k = base64_decode((string) $this->cred['public_key'], true);
        if ($s === false || $k === false || strlen($s) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($k) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }
        return sodium_crypto_sign_verify_detached($s, $ts . '|' . $rawBody, $k);
    }

    public function parseStatusEvent(array $headers, string $rawBody, array $params): ?array
    {
        $j = json_decode($rawBody, true);
        $data = is_array($j) ? ($j['data'] ?? null) : null;
        if (!is_array($data) || empty($data['payload']['call_control_id'])) {
            return null;
        }
        $p = $data['payload'];
        $map = ['call.initiated' => 'initiated', 'call.answered' => 'in-progress', 'call.hangup' => 'completed', 'call.machine.detection.ended' => 'machine-detected'];
        $type = (string) ($data['event_type'] ?? '');
        $dur = null;
        if ($type === 'call.hangup' && !empty($p['start_time']) && !empty($p['end_time'])) {
            $dur = max(0, (int) (strtotime($p['end_time']) - strtotime($p['start_time'])));
        }
        return [
            'event_id' => (string) ($data['id'] ?? ''), 'call_id' => (string) $p['call_control_id'],
            'status' => $map[$type] ?? $type, 'direction' => (string) ($p['direction'] ?? 'inbound') === 'outgoing' ? 'outbound' : 'inbound',
            'from' => (string) ($p['from'] ?? ''), 'to' => (string) ($p['to'] ?? ''),
            'duration_s' => $dur, 'cost_usd' => null, 'cost_source' => null,
            'recording_url' => $type === 'call.recording.saved' ? (string) ($p['recording_urls']['mp3'] ?? '') : null,
        ];
    }
}

<?php

/**
 * Tourfecto - AI Voice Agent: Twilio adapter (REST 2010-04-01 + Pricing v1).
 * Written against Twilio's public API docs; exercised in tests with a fake transport only (no live account here).
 */
class TwilioVoiceAdapter extends AbstractVoiceProviderAdapter
{
    private const API = 'https://api.twilio.com/2010-04-01';
    private const PRICING = 'https://pricing.twilio.com/v1';
    private const TYPES = ['local' => 'Local', 'mobile' => 'Mobile', 'toll_free' => 'TollFree', 'national' => 'National'];

    public function key(): string
    {
        return 'twilio';
    }

    public static function credentialFields(): array
    {
        return ['account_sid', 'auth_token'];
    }

    public function capabilities(): array
    {
        return ['numbers_search' => true, 'numbers_purchase' => true, 'inbound' => true, 'outbound' => true, 'sip' => true,
            'transfer' => true, 'recording' => true, 'streaming' => true, 'porting' => false, 'cost_lookup' => true, 'inline_voice' => true, 'turn_mode' => true, 'subaccounts' => true];
    }

    private function auth(): array
    {
        return [(string) $this->cred['account_sid'], (string) $this->cred['auth_token']];
    }

    private function acct(): string
    {
        return self::API . '/Accounts/' . rawurlencode((string) $this->cred['account_sid']);
    }

    protected function errorOf(array $d, int $http): array
    {
        return [(string) ($d['message'] ?? ('Twilio HTTP ' . $http)), (string) ($d['code'] ?? '')];
    }

    public function testConnection(): array
    {
        $d = $this->json('GET', $this->acct() . '.json', null, [], $this->auth());
        $ok = ($d['status'] ?? '') === 'active';
        return ['ok' => $ok, 'account' => (string) ($d['friendly_name'] ?? ''), 'detail' => (string) ($d['status'] ?? 'unknown')];
    }

    public function listCountries(): array
    {
        $d = $this->json('GET', $this->acct() . '/AvailablePhoneNumbers.json?PageSize=300', null, [], $this->auth());
        $out = [];
        foreach ($d['countries'] ?? [] as $c) {
            $types = [];
            foreach (array_keys((array) ($c['subresource_uris'] ?? [])) as $k) {
                $t = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', (string) $k));
                if (isset(self::TYPES[$t])) {
                    $types[] = $t;
                }
            }
            $out[] = ['iso' => strtoupper((string) ($c['country_code'] ?? '')), 'name' => (string) ($c['country'] ?? ''), 'types' => $types];
        }
        return $out;
    }

    public function searchNumbers(string $iso, string $type, array $filters = [], int $limit = 20): array
    {
        $iso = strtoupper(trim($iso));
        if (!preg_match('/^[A-Z]{2}$/', $iso) || !isset(self::TYPES[$type])) {
            throw new InvalidArgumentException('Invalid country or number type');
        }
        $q = ['PageSize' => max(1, min(50, $limit)), 'VoiceEnabled' => 'true'];
        if (!empty($filters['contains'])) {
            $q['Contains'] = preg_replace('/[^0-9*]/', '', (string) $filters['contains']);
        }
        $d = $this->json('GET', $this->acct() . '/AvailablePhoneNumbers/' . $iso . '/' . self::TYPES[$type] . '.json?' . http_build_query($q), null, [], $this->auth());
        $price = $this->monthlyPrice($iso, $type);
        $out = [];
        foreach ($d['available_phone_numbers'] ?? [] as $n) {
            $out[] = [
                'number' => (string) $n['phone_number'], 'country' => $iso, 'type' => $type,
                'monthly_cost' => $price['monthly'], 'setup_cost' => 0.0, 'currency' => $price['currency'],
                'voice' => (bool) ($n['capabilities']['voice'] ?? false), 'sms' => (bool) ($n['capabilities']['SMS'] ?? $n['capabilities']['sms'] ?? false),
                'address_required' => (string) ($n['address_requirements'] ?? 'none'), 'provider' => 'twilio',
            ];
        }
        return $out;
    }

    /** @return array{monthly:?float, currency:string} monthly = null when Twilio does not publish a price for this type */
    private function monthlyPrice(string $iso, string $type): array
    {
        try {
            $d = $this->json('GET', self::PRICING . '/PhoneNumbers/Countries/' . $iso, null, [], $this->auth());
        } catch (VoiceProviderException $e) {
            return ['monthly' => null, 'currency' => 'USD'];
        }
        $want = $type === 'toll_free' ? 'toll free' : $type;
        foreach ($d['phone_number_prices'] ?? [] as $p) {
            if (strtolower((string) ($p['number_type'] ?? '')) === $want) {
                return ['monthly' => (float) ($p['current_price'] ?? $p['base_price'] ?? 0), 'currency' => (string) ($d['price_unit'] ?? 'USD')];
            }
        }
        return ['monthly' => null, 'currency' => (string) ($d['price_unit'] ?? 'USD')];
    }

    public function createSubaccount(string $label): array
    {
        $d = $this->json('POST', self::API . '/Accounts.json', ['FriendlyName' => mb_substr(trim($label), 0, 60)], [], $this->auth());
        if (empty($d['sid']) || empty($d['auth_token'])) {
            throw new VoiceProviderException('Twilio did not return sub-account credentials');
        }
        return ['account_id' => (string) $d['sid'], 'credentials' => ['account_sid' => (string) $d['sid'], 'auth_token' => (string) $d['auth_token']]];
    }

    public function listOwnedNumbers(): array
    {
        $d = $this->json('GET', $this->acct() . '/IncomingPhoneNumbers.json?PageSize=1000', null, [], $this->auth());
        $out = [];
        foreach ($d['incoming_phone_numbers'] ?? [] as $n) {
            $out[] = ['number' => (string) $n['phone_number'], 'number_id' => (string) $n['sid']];
        }
        return $out;
    }

    public function usageRates(string $iso, string $type): ?array
    {
        $iso = strtoupper($iso);
        if (!preg_match('/^[A-Z]{2}$/', $iso)) {
            return null;
        }
        try {
            $d = $this->json('GET', self::PRICING . '/Voice/Countries/' . $iso, null, [], $this->auth());
        } catch (VoiceProviderException $e) {
            return null;
        }
        $want = $type === 'toll_free' ? 'toll free' : $type;
        $in = null;
        foreach ($d['inbound_call_prices'] ?? [] as $p) {
            if (strtolower((string) ($p['number_type'] ?? '')) === $want) {
                $in = (float) ($p['current_price'] ?? $p['base_price'] ?? 0);
            }
        }
        $out = null;
        foreach ($d['outbound_prefix_prices'] ?? [] as $p) {
            $v = (float) ($p['current_price'] ?? $p['base_price'] ?? 0);
            $out = $out === null ? $v : max($out, $v); // worst case across prefixes: never under-quote
        }
        return ['inbound_per_min' => $in, 'outbound_per_min' => $out, 'currency' => (string) ($d['price_unit'] ?? 'USD')];
    }

    public function purchaseNumber(string $e164, array $opts = []): array
    {
        $body = ['PhoneNumber' => self::e164($e164)];
        foreach (['voice_url' => 'VoiceUrl', 'status_callback' => 'StatusCallback', 'address_sid' => 'AddressSid', 'bundle_sid' => 'BundleSid'] as $k => $p) {
            if (!empty($opts[$k])) {
                $body[$p] = (string) $opts[$k];
            }
        }
        $d = $this->json('POST', $this->acct() . '/IncomingPhoneNumbers.json', $body, [], $this->auth());
        return ['number_id' => (string) $d['sid'], 'number' => (string) $d['phone_number'], 'status' => 'active'];
    }

    public function releaseNumber(string $numberId): bool
    {
        $this->json('DELETE', $this->acct() . '/IncomingPhoneNumbers/' . rawurlencode($numberId) . '.json', null, [], $this->auth());
        return true;
    }

    public function startCall(string $from, string $to, array $opts = []): array
    {
        if (empty($opts['url']) && empty($opts['twiml'])) {
            throw new InvalidArgumentException('url (instructions webhook) or twiml is required');
        }
        $body = ['From' => self::e164($from), 'To' => self::e164($to)];
        $body += !empty($opts['twiml']) ? ['Twiml' => (string) $opts['twiml']] : ['Url' => (string) $opts['url']];
        if (!empty($opts['status_callback'])) {
            $body['StatusCallback'] = (string) $opts['status_callback'];
            $body['StatusCallbackEvent'] = 'initiated ringing answered completed';
        }
        if (!empty($opts['record'])) {
            $body['Record'] = 'true';
        }
        if (!empty($opts['machine_detection'])) {
            $body['MachineDetection'] = $opts['machine_detection'] === 'message_end' ? 'DetectMessageEnd' : 'Enable';
        }
        $d = $this->json('POST', $this->acct() . '/Calls.json', $body, [], $this->auth());
        return ['call_id' => (string) $d['sid'], 'status' => (string) ($d['status'] ?? 'queued')];
    }

    public function transferCall(string $callId, string $toE164): array
    {
        $twiml = '<Response><Dial>' . htmlspecialchars(self::e164($toE164), ENT_XML1) . '</Dial></Response>';
        $d = $this->json('POST', $this->acct() . '/Calls/' . rawurlencode($callId) . '.json', ['Twiml' => $twiml], [], $this->auth());
        return ['call_id' => (string) $d['sid'], 'status' => (string) ($d['status'] ?? 'in-progress')];
    }

    public function hangupCall(string $callId): bool
    {
        $this->json('POST', $this->acct() . '/Calls/' . rawurlencode($callId) . '.json', ['Status' => 'completed'], [], $this->auth());
        return true;
    }

    /** opts: stream_url (wss://), forward_to (E.164), sip_uri, say, record */
    public function inboundInstructions(array $opts): array
    {
        $x = static fn ($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES);
        $inner = '';
        if (!empty($opts['say'])) {
            $inner .= '<Say>' . $x($opts['say']) . '</Say>';
        }
        if (!empty($opts['stream_url'])) {
            if (!preg_match('#^wss://#i', (string) $opts['stream_url'])) {
                throw new InvalidArgumentException('stream_url must be wss://');
            }
            $params = '';
            foreach ((array) ($opts['stream_params'] ?? []) as $k => $v) {
                if (preg_match('/^[A-Za-z0-9_]{1,40}$/', (string) $k)) {
                    $params .= '<Parameter name="' . $x($k) . '" value="' . $x($v) . '"/>';
                }
            }
            $inner .= $params === ''
                ? '<Connect><Stream url="' . $x($opts['stream_url']) . '"/></Connect>'
                : '<Connect><Stream url="' . $x($opts['stream_url']) . '">' . $params . '</Stream></Connect>';
        } elseif (!empty($opts['forward_to'])) {
            $rec = !empty($opts['record']) ? ' record="record-from-answer"' : '';
            $inner .= '<Dial' . $rec . '>' . $x(self::e164($opts['forward_to'])) . '</Dial>';
        } elseif (!empty($opts['sip_uri'])) {
            $inner .= '<Dial><Sip>' . $x($opts['sip_uri']) . '</Sip></Dial>';
        }
        return ['content_type' => 'text/xml', 'body' => '<?xml version="1.0" encoding="UTF-8"?><Response>' . $inner . '</Response>'];
    }

    public function turnInstructions(array $o): array
    {
        $x = static fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES);
        $voice = preg_match('/^[A-Za-z0-9._\-]{2,60}$/', (string) ($o['voice'] ?? '')) ? $o['voice'] : 'Polly.Joanna';
        $say = trim((string) ($o['say'] ?? '')) === '' ? '' : '<Say voice="' . $x($voice) . '">' . $x($o['say']) . '</Say>';
        $inner = $say;
        if (!empty($o['gather_url'])) {
            if (!preg_match('#^https://#i', (string) $o['gather_url'])) {
                throw new InvalidArgumentException('gather_url must be https');
            }
            $loc = preg_match('/^[a-z]{2}-[A-Z]{2}$/', (string) ($o['language'] ?? '')) ? $o['language'] : 'en-US';
            $inner = '<Gather input="speech" action="' . $x($o['gather_url']) . '" method="POST" language="' . $x($loc) . '" speechTimeout="auto" timeout="6" actionOnEmptyResult="true">' . $say . '</Gather>';
        } elseif (!empty($o['transfer_to'])) {
            $inner = $say . '<Dial>' . $x(self::e164($o['transfer_to'])) . '</Dial>';
        } else {
            $inner = $say . '<Hangup/>';
        }
        return ['content_type' => 'text/xml', 'body' => '<?xml version="1.0" encoding="UTF-8"?><Response>' . $inner . '</Response>'];
    }

    public function verifyWebhook(string $url, array $headers, string $rawBody, array $params): bool
    {
        $sig = $headers['x-twilio-signature'] ?? '';
        if ($sig === '') {
            return false;
        }
        ksort($params);
        $data = $url;
        foreach ($params as $k => $v) {
            $data .= $k . (is_array($v) ? '' : $v);
        }
        return hash_equals(base64_encode(hash_hmac('sha1', $data, (string) $this->cred['auth_token'], true)), $sig);
    }

    public function parseStatusEvent(array $headers, string $rawBody, array $params): ?array
    {
        $sid = (string) ($params['CallSid'] ?? '');
        if ($sid === '') {
            return null;
        }
        $status = (string) ($params['CallStatus'] ?? '');
        return [
            'event_id' => $sid . ':' . $status . ':' . ($params['SequenceNumber'] ?? '0'),
            'call_id' => $sid, 'status' => $status,
            'direction' => str_starts_with((string) ($params['Direction'] ?? ''), 'outbound') ? 'outbound' : 'inbound',
            'from' => (string) ($params['From'] ?? ''), 'to' => (string) ($params['To'] ?? ''),
            'duration_s' => isset($params['CallDuration']) ? (int) $params['CallDuration'] : null,
            'cost_usd' => null, 'cost_source' => null,
            'recording_url' => isset($params['RecordingUrl']) ? (string) $params['RecordingUrl'] : null,
        ];
    }

    public function fetchCallCost(string $callId): ?array
    {
        $d = $this->json('GET', $this->acct() . '/Calls/' . rawurlencode($callId) . '.json', null, [], $this->auth());
        if (!isset($d['price']) || $d['price'] === null) {
            return null; // price is filled in by Twilio a little after the call ends
        }
        return ['cost_usd' => abs((float) $d['price']), 'currency' => (string) ($d['price_unit'] ?? 'USD')];
    }
}

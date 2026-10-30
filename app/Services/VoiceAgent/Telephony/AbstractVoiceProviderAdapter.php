<?php

abstract class AbstractVoiceProviderAdapter implements VoiceProviderAdapter
{
    protected array $cred;
    protected VoiceHttpClient $http;

    public function __construct(array $credentials, ?VoiceHttpClient $http = null)
    {
        foreach (static::credentialFields() as $f) {
            if (trim((string) ($credentials[$f] ?? '')) === '') {
                throw new InvalidArgumentException('Missing credential: ' . $f);
            }
        }
        $this->cred = $credentials;
        $this->http = $http ?? new CurlVoiceHttpClient();
    }

    /** Credentials that may be left out (the adapter then works without them). @return string[] */
    public static function optionalCredentialFields(): array
    {
        return [];
    }

    /** @return array decoded JSON body ([] for empty) */
    protected function json(string $method, string $url, $body = null, array $headers = [], ?array $auth = null): array
    {
        $r = $this->http->request($method, $url, $headers, $body, $auth);
        if ($r['body'] === '' && $r['code'] === 0) {
            throw new VoiceProviderException('Provider unreachable: ' . $r['error']);
        }
        $data = json_decode($r['body'], true);
        $data = is_array($data) ? $data : [];
        if ($r['code'] >= 400 || $r['code'] === 0) {
            [$msg, $code] = $this->errorOf($data, $r['code']);
            throw new VoiceProviderException($msg, $r['code'], $code);
        }
        return $data;
    }

    /** @return array{0:string,1:string} message, provider error code */
    abstract protected function errorOf(array $data, int $http): array;

    protected static function e164(string $n): string
    {
        $d = preg_replace('/[^0-9]/', '', $n);
        if ($d === '' || strlen($d) < 7 || strlen($d) > 15) {
            throw new InvalidArgumentException('Invalid phone number');
        }
        return '+' . $d;
    }

    /** @return array{account_id:string, credentials:array} credentials a tenant-scoped adapter can use */
    /** Start recording a call that is in progress. @return array{recording_id:string} */
    public function startRecording(string $callId): array
    {
        throw new VoiceProviderException('Recording is not supported by this provider yet');
    }

    /** @return array{content_type:string, body:string} the audio bytes (never a provider URL: customers must not receive provider links) */
    public function fetchRecording(string $recordingId): array
    {
        throw new VoiceProviderException('Recording is not supported by this provider yet');
    }

    public function deleteRecording(string $recordingId): bool
    {
        throw new VoiceProviderException('Recording is not supported by this provider yet');
    }

    public function createSubaccount(string $label): array
    {
        throw new LogicException('Sub-accounts are not supported by this provider');
    }

    public function turnInstructions(array $opts): array
    {
        throw new LogicException('Turn mode is not supported by this provider');
    }

    public function usageRates(string $iso, string $type): ?array
    {
        return null;
    }

    public function fetchCallCost(string $callId): ?array
    {
        return null;
    }
}

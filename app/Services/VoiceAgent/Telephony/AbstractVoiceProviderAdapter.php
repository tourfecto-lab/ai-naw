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

    public function usageRates(string $iso, string $type): ?array
    {
        return null;
    }

    public function fetchCallCost(string $callId): ?array
    {
        return null;
    }
}

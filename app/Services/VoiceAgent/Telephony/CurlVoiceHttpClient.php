<?php

/** Tourfecto - AI Voice Agent: real transport. Goes through SafeHttp so redirects and private IPs are checked. */
class CurlVoiceHttpClient implements VoiceHttpClient
{
    public function request(string $method, string $url, array $headers = [], $body = null, ?array $basicAuth = null, int $timeout = 20): array
    {
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        $opts = [CURLOPT_CUSTOMREQUEST => strtoupper($method), CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => $timeout];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = is_array($body) ? http_build_query($body) : (string) $body;
        }
        if ($basicAuth) {
            $opts[CURLOPT_USERPWD] = $basicAuth[0] . ':' . $basicAuth[1];
        }
        $r = SafeHttp::exec($url, $opts);
        return ['code' => (int) $r['code'], 'body' => is_string($r['response']) ? $r['response'] : '', 'error' => (string) $r['error']];
    }
}

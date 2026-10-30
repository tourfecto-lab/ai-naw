<?php

/** Fake telephony transport for AI Voice Agent tests: queue responses, inspect recorded calls. */

final class VoiceFakeHttp implements VoiceHttpClient
{
    public array $calls = [];
    public array $queue = [];

    public function push(int $code, $body): self
    {
        $this->queue[] = ['code' => $code, 'body' => is_string($body) ? $body : json_encode($body), 'error' => ''];
        return $this;
    }

    public function request(string $method, string $url, array $headers = [], $body = null, ?array $basicAuth = null, int $timeout = 20): array
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body', 'basicAuth');
        return array_shift($this->queue) ?? ['code' => 0, 'body' => '', 'error' => 'no response queued'];
    }
}

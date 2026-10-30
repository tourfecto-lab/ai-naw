<?php

/** Tourfecto - AI Voice Agent: HTTP transport used by telephony adapters (swapped for a fake in tests). */
interface VoiceHttpClient
{
    /**
     * @param array<string,string> $headers
     * @param array|string|null $body array = form-encoded, string = sent as-is
     * @param array{0:string,1:string}|null $basicAuth
     * @return array{code:int, body:string, error:string}
     */
    public function request(string $method, string $url, array $headers = [], $body = null, ?array $basicAuth = null, int $timeout = 20): array;
}

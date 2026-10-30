<?php

/**
 * Tourfecto - AI Voice Agent: authentication between Tourfecto and an external realtime audio bridge (streaming mode).
 * The bridge receives a short-lived per-call token inside the stream URL and signs each request to Tourfecto with a shared secret. Pure.
 */
class VoiceBridgeAuth
{
    public const TOKEN_TTL = 900;
    public const SKEW = 300;

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function makeToken(int $callId, int $tenantId, string $secret, ?int $now = null): string
    {
        $payload = self::b64($callId . '.' . $tenantId . '.' . (($now ?? time()) + self::TOKEN_TTL));
        return $payload . '.' . hash_hmac('sha256', $payload, $secret);
    }

    /** @return array{call_id:int, tenant_id:int}|null */
    public static function verifyToken(string $token, string $secret, ?int $now = null): ?array
    {
        if ($secret === '' || substr_count($token, '.') !== 1) {
            return null;
        }
        [$payload, $sig] = explode('.', $token);
        if (!hash_equals(hash_hmac('sha256', $payload, $secret), $sig)) {
            return null;
        }
        $parts = explode('.', (string) base64_decode(strtr($payload, '-_', '+/'), true));
        if (count($parts) !== 3 || (int) $parts[2] < ($now ?? time())) {
            return null;
        }
        return ['call_id' => (int) $parts[0], 'tenant_id' => (int) $parts[1]];
    }

    public static function signRequest(string $body, int $timestamp, string $secret): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    public static function verifyRequest(string $body, int $timestamp, string $signature, string $secret, ?int $now = null): bool
    {
        return $secret !== '' && abs(($now ?? time()) - $timestamp) <= self::SKEW && hash_equals(self::signRequest($body, $timestamp, $secret), $signature);
    }
}

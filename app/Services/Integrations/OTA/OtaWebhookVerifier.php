<?php
/** Generic HMAC verifier for providers that document a compatible signature scheme. Do not use unless docs match. */
final class OtaWebhookVerifier
{
    public static function verifyHmacSha256(string $rawBody, string $providedSignature, string $secret, string $prefix = ''): bool
    {
        if ($rawBody === '' || $providedSignature === '' || $secret === '') return false;
        $signature = trim($providedSignature);
        if ($prefix !== '' && strpos($signature, $prefix) === 0) $signature = substr($signature, strlen($prefix));
        $expected = hash_hmac('sha256', $rawBody, $secret);
        return strlen($signature) === strlen($expected) && hash_equals($expected, strtolower($signature));
    }
}

<?php

/**
 * Tourfecto - فك والتحقق من signed_request اللي Meta بتبعتها لـ Deauthorize / Data Deletion callbacks.
 * الشكل: base64url(HMAC-SHA256 signature) . base64url(JSON payload). التوقيع بيتحسب على الـ payload المشفّر نفسه بـ App Secret.
 * @version 1.0.0
 */
final class MetaSignedRequest
{
    /** @return array|null الـ payload (فيه user_id) لو التوقيع سليم، وإلا null */
    public static function parse(string $signedRequest, string $appSecret): ?array
    {
        if ($appSecret === '' || substr_count($signedRequest, '.') !== 1) {
            return null;
        }
        [$encodedSig, $encodedPayload] = explode('.', $signedRequest, 2);
        $sig = self::b64urlDecode($encodedSig);
        $json = self::b64urlDecode($encodedPayload);
        if ($sig === null || $json === null) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || strtoupper((string) ($data['algorithm'] ?? '')) !== 'HMAC-SHA256') {
            return null;
        }
        $expected = hash_hmac('sha256', $encodedPayload, $appSecret, true);
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        return $data;
    }

    private static function b64urlDecode(string $s): ?string
    {
        if ($s === '' || !preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $s)) {
            return null;
        }
        $pad = (4 - strlen($s) % 4) % 4;
        $out = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', $pad), true);
        return $out === false ? null : $out;
    }

    /** للاختبارات: بيبني signed_request صحيح */
    public static function build(array $payload, string $appSecret): string
    {
        $enc = static fn (string $b): string => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
        $payload += ['algorithm' => 'HMAC-SHA256'];
        $p = $enc(json_encode($payload));
        return $enc(hash_hmac('sha256', $p, $appSecret, true)) . '.' . $p;
    }
}

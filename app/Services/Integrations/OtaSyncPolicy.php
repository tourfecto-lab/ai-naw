<?php
/** Safe policy helpers for the OTA hub. This class deliberately does not perform network or database writes. */
final class OtaSyncPolicy
{
    private const WRITE_CAPABILITIES = ['booking_create','booking_update','booking_cancel','availability_write','rate_write','message_reply','review_reply','listing_write'];

    public static function assertCapability(array $connection, string $capability): void
    {
        if (!in_array($capability, self::WRITE_CAPABILITIES, true)) {
            throw new InvalidArgumentException('Unknown OTA capability');
        }
        if (($connection['status'] ?? '') !== 'connected' || empty($connection['verified_at'])) {
            throw new RuntimeException('OTA connection must be verified before writes are enabled');
        }
        $capabilities = $connection['capabilities'] ?? [];
        if (is_string($capabilities)) {
            $decoded = json_decode($capabilities, true);
            $capabilities = is_array($decoded) ? $decoded : [];
        }
        if (!in_array($capability, $capabilities, true)) {
            throw new RuntimeException('Provider capability is not enabled for this connection');
        }
    }

    /** Only allow HTTPS callbacks to avoid leaking provider events to insecure endpoints. */
    public static function validateCallbackUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Callback URL must be an HTTPS URL without embedded credentials');
        }
        return $url;
    }

    /** Stable webhook dedupe key; callers persist this with a unique index before processing. */
    public static function webhookEventKey(string $platform, string $accountId, string $eventId, string $eventType): string
    {
        foreach ([$platform, $accountId, $eventId, $eventType] as $value) {
            if (trim($value) === '' || strlen($value) > 255) throw new InvalidArgumentException('Invalid webhook identity');
        }
        return hash('sha256', strtolower(trim($platform)).'|'.trim($accountId).'|'.trim($eventId).'|'.trim($eventType));
    }
}

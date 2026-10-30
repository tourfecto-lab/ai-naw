<?php

/**
 * Tourfecto - AI Voice Agent: a secret that goes in the webhook URL you paste into a provider console. Used for providers whose XML (TeXML) callbacks
 * are not guaranteed to carry a verifiable signature. Derived from the platform encryption key, so it is never stored or shown to customers.
 * A request is accepted if EITHER the provider's signature verifies OR this token matches.
 */
class VoiceWebhookToken
{
    public static function make(string $provider): string
    {
        $key = defined('ENCRYPTION_KEY') ? (string) ENCRYPTION_KEY : '';
        if ($key === '') {
            return '';
        }
        $salt = self::salt();
        return substr(hash_hmac('sha256', 'voice-webhook:' . strtolower($provider) . ($salt === '' ? '' : ':' . $salt), $key), 0, 32);
    }

    private static function salt(): string
    {
        try {
            return (string) (new SystemSettingsService())->get('voice_webhook_salt', '');
        } catch (Throwable $e) {
            return '';
        }
    }

    /** New secret for every provider: the old URLs stop working at once. @return string the new salt */
    public static function rotate(): string
    {
        $salt = bin2hex(random_bytes(8));
        (new SystemSettingsService())->set('voice_webhook_salt', $salt);
        return $salt;
    }

    public static function valid(string $provider, string $given): bool
    {
        $t = self::make($provider);
        return $t !== '' && $given !== '' && hash_equals($t, $given);
    }

    /** The token carried by a request URL (?k=...), or ''. */
    public static function fromUrl(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        return isset($q['k']) && is_string($q['k']) ? $q['k'] : '';
    }
}

<?php

/**
 * Tourfecto - AI Voice Agent: the public address providers call for webhooks. Normally the site's APP_URL, but the platform owner can set
 * voice_public_base_url so voice webhooks reach the domain that actually runs this code, WITHOUT touching APP_URL (which also feeds OAuth redirect addresses).
 */
class VoicePublicUrl
{
    public static function base(): string
    {
        try {
            $v = trim((string) (new SystemSettingsService())->get('voice_public_base_url', ''));
        } catch (Throwable $e) {
            $v = '';
        }
        if ($v !== '' && self::valid($v)) {
            return rtrim($v, '/');
        }
        return rtrim(defined('APP_URL') ? (string) APP_URL : '', '/');
    }

    /** Only https origins: https://host or https://host:port, no path, no query. */
    public static function valid(string $url): bool
    {
        return (bool) preg_match('#^https://[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?(:\d{2,5})?/?$#', $url);
    }
}

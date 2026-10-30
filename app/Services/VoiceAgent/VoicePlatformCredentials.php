<?php

/**
 * Tourfecto - AI Voice Agent: Tourfecto's own provider accounts. Keys live in the platform's existing integration settings
 * (admin panel > Integrations, stored in system_settings and exposed through env()), never in a voice table and never in any
 * customer-facing response. This class only reads them and maps them to adapter credentials.
 */
class VoicePlatformCredentials
{
    /** adapter credential field => integration env key */
    public const MAP = [
        'twilio' => ['account_sid' => 'TWILIO_VOICE_ACCOUNT_SID', 'auth_token' => 'TWILIO_VOICE_AUTH_TOKEN'],
        'telnyx' => ['api_key' => 'TELNYX_API_KEY', 'connection_id' => 'TELNYX_CONNECTION_ID', 'public_key' => 'TELNYX_PUBLIC_KEY'],
    ];
    /** admin integration key => provider */
    public const INTEGRATIONS = ['twilio_voice' => 'twilio', 'telnyx_voice' => 'telnyx'];

    public static function read(string $envKey): string
    {
        $v = $_ENV[$envKey] ?? getenv($envKey);
        if (($v === false || $v === null || $v === '') && function_exists('env')) {
            $v = env($envKey);
        }
        return is_string($v) ? trim($v) : '';
    }

    /** @return string[] env keys of a provider */
    public static function envKeys(string $provider): array
    {
        return array_values(self::MAP[$provider] ?? []);
    }

    /** @param callable|null $get fn(string $envKey): string (tests / the admin panel's saved values) */
    public static function forProvider(string $provider, ?callable $get = null): ?array
    {
        $get = $get ?? [self::class, 'read'];
        $out = [];
        foreach (self::MAP[$provider] ?? [] as $field => $envKey) {
            $v = trim((string) $get($envKey));
            if ($v === '') {
                return null;
            }
            $out[$field] = $v;
        }
        return $out ?: null;
    }

    /** Names (never values) of the keys still missing. @return string[] */
    public static function missing(string $provider, ?callable $get = null): array
    {
        $get = $get ?? [self::class, 'read'];
        return array_values(array_filter(self::envKeys($provider), static fn ($k) => trim((string) $get($k)) === ''));
    }

    /** Read-only connectivity check for the admin Integrations page. @return array{ok:bool, message:string} */
    public static function adminTest(string $integrationKey, callable $get, ?VoiceHttpClient $http = null): array
    {
        $provider = self::INTEGRATIONS[$integrationKey] ?? null;
        $creds = $provider ? self::forProvider($provider, $get) : null;
        if (!$creds) {
            return ['ok' => false, 'message' => 'Missing keys: ' . implode(', ', $provider ? self::missing($provider, $get) : [$integrationKey])];
        }
        try {
            $r = VoiceProviderRegistry::make($provider, $creds, $http)->testConnection();
            return ['ok' => $r['ok'], 'message' => ucfirst($provider) . ($r['ok'] ? ' connection OK' : ' answered but the account is not usable') . ($r['detail'] !== '' ? ' (' . $r['detail'] . ')' : '')];
        } catch (VoiceProviderException $e) {
            return ['ok' => false, 'message' => ucfirst($provider) . ' rejected the request' . ($e->httpCode ? ' (HTTP ' . $e->httpCode . ')' : '') . ': ' . $e->getMessage()];
        }
    }
}

<?php

/** Tourfecto - AI Voice Agent: provider registry. Add a provider by implementing VoiceProviderAdapter and listing it here. */
class VoiceProviderRegistry
{
    private const PROVIDERS = ['twilio' => 'TwilioVoiceAdapter', 'telnyx' => 'TelnyxVoiceAdapter'];

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::PROVIDERS);
    }

    public static function has(string $key): bool
    {
        return isset(self::PROVIDERS[$key]);
    }

    /** @return class-string<VoiceProviderAdapter> */
    public static function classFor(string $key): string
    {
        if (!self::has($key)) {
            throw new InvalidArgumentException('Unknown provider');
        }
        return self::PROVIDERS[$key];
    }

    public static function make(string $key, array $credentials, ?VoiceHttpClient $http = null): VoiceProviderAdapter
    {
        $c = self::classFor($key);
        return new $c($credentials, $http);
    }
}

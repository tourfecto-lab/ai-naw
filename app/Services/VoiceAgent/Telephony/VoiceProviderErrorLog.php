<?php

/** Tourfecto - AI Voice Agent: provider error monitoring (per tenant, credentials never stored). */
class VoiceProviderErrorLog
{
    public static function record(int $tenantId, string $provider, string $operation, Throwable $e): void
    {
        try {
            Database::getInstance()->exec(
                'INSERT INTO voice_provider_errors (tenant_user_id, provider, operation, http_code, provider_code, message, created_at) VALUES (?,?,?,?,?,?,NOW())',
                [$tenantId, $provider, $operation, $e instanceof VoiceProviderException ? $e->httpCode : 0,
                    $e instanceof VoiceProviderException ? $e->providerCode : '', mb_substr($e->getMessage(), 0, 500)]
            );
        } catch (Throwable $ignored) {
            // monitoring must never break the request
        }
    }

    public static function recent(int $tenantId, int $limit = 50): array
    {
        return Database::getInstance()->query(
            'SELECT provider, operation, http_code, provider_code, message, created_at FROM voice_provider_errors WHERE tenant_user_id = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)),
            [$tenantId]
        ) ?: [];
    }

    /** @return list<array{provider:string, errors:int}> last 24h */
    public static function summary(int $tenantId): array
    {
        return Database::getInstance()->query(
            'SELECT provider, COUNT(*) AS errors FROM voice_provider_errors WHERE tenant_user_id = ? AND created_at >= NOW() - INTERVAL 1 DAY GROUP BY provider',
            [$tenantId]
        ) ?: [];
    }
}

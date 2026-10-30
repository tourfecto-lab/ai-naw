<?php

/**
 * Tourfecto - AI Voice & Sales Agent: tenant + role resolution.
 *
 * Reuses the CRM tenant model (account owner = tenant, team members act inside the owner's tenant) and the
 * CRM role names, so no second permission system is introduced. Every voice query must be scoped by the
 * tenant id returned from tenantFor(), never by a client-supplied id.
 * roleAllows() is pure (no DB) so it is unit-tested offline.
 */
class VoiceAccessService
{
    public const FEATURE_KEY = 'voice_agent';

    public const CAP_VIEW = 'view';
    public const CAP_MANAGE_AGENTS = 'manage_agents';
    public const CAP_MANAGE_SETTINGS = 'manage_settings';
    public const CAP_MANAGE_TELEPHONY = 'manage_telephony';
    public const CAP_PLACE_CALLS = 'place_calls';
    public const CAP_VIEW_RECORDINGS = 'view_recordings';

    private const MATRIX = [
        'admin'   => ['view', 'manage_agents', 'manage_settings', 'manage_telephony', 'place_calls', 'view_recordings'],
        'manager' => ['view', 'manage_agents', 'place_calls', 'view_recordings'],
        'sales'   => ['view', 'place_calls'],
        'support' => ['view'],
        'viewer'  => ['view'],
    ];

    public static function roleAllows(string $role, string $capability): bool
    {
        return in_array($capability, self::MATRIX[$role] ?? [], true);
    }

    /** @return string[] */
    public static function capabilitiesFor(string $role): array
    {
        return self::MATRIX[$role] ?? [];
    }

    /** Tenant (account owner id) the user works inside. */
    public static function tenantFor(int $userId): int
    {
        return (new CrmPermissionService())->resolveTenantId($userId);
    }

    public static function roleFor(int $userId): string
    {
        return (new CrmPermissionService())->roleFor($userId);
    }

    public static function can(int $userId, string $capability): bool
    {
        return self::roleAllows(self::roleFor($userId), $capability);
    }

    /** Module is sold through plan features / admin overrides; no existing plan gets it automatically. */
    public static function featureEnabled(int $tenantId): bool
    {
        return FeatureResolver::has($tenantId, self::FEATURE_KEY);
    }
}

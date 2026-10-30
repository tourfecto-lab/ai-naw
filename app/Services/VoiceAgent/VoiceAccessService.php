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
        $g = self::grant($tenantId);
        return $g !== null ? (bool) $g['enabled'] : FeatureResolver::has($tenantId, self::FEATURE_KEY); // an admin grant (or revoke) wins over the plan
    }

    /** @return array{enabled:int, max_numbers:?int}|null the admin's decision for this account, if any */
    public static function grant(int $tenantId): ?array
    {
        try {
            $r = Database::getInstance()->query('SELECT enabled, max_numbers FROM voice_feature_grants WHERE tenant_user_id = ? LIMIT 1', [$tenantId]);
        } catch (Throwable $e) {
            return null; // migration not applied yet: the plan decides
        }
        return $r ? ['enabled' => (int) $r[0]['enabled'], 'max_numbers' => $r[0]['max_numbers'] !== null ? (int) $r[0]['max_numbers'] : null] : null;
    }

    /** Phone numbers this account may hold: the admin's limit if set, else the plan's. */
    public static function maxNumbers(int $tenantId): int
    {
        $g = self::grant($tenantId);
        return $g !== null && $g['enabled'] && $g['max_numbers'] !== null ? $g['max_numbers'] : VoiceNumberRules::maxNumbers(FeatureResolver::value($tenantId, 'voice_max_numbers'));
    }
}

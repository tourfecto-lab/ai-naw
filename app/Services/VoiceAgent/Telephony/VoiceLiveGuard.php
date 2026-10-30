<?php

/**
 * Tourfecto - AI Voice Agent: decides whether a mutating telephony operation may hit the real provider.
 * Everything defaults to simulation. Going live needs ALL of: platform flag on, tenant module enabled,
 * tenant sandbox off, and (for operations that spend money or ring a person) an explicit approval for that action.
 */
class VoiceLiveGuard
{
    public const NEEDS_APPROVAL = ['purchase_number', 'release_number', 'start_call', 'transfer_call'];
    public const MUTATING = ['purchase_number', 'release_number', 'start_call', 'transfer_call', 'hangup_call', 'create_subaccount', 'start_recording'];

    /** @return array{live:bool, reason:string} */
    public static function decide(string $operation, array $tenantSettings, bool $platformLiveEnabled, bool $approved): array
    {
        if (!in_array($operation, self::MUTATING, true)) {
            return ['live' => true, 'reason' => 'read_only'];
        }
        if (!$platformLiveEnabled) {
            return ['live' => false, 'reason' => 'platform_live_disabled'];
        }
        if (empty($tenantSettings['enabled'])) {
            return ['live' => false, 'reason' => 'module_disabled'];
        }
        if (!isset($tenantSettings['sandbox_mode']) || (int) $tenantSettings['sandbox_mode'] !== 0) {
            return ['live' => false, 'reason' => 'sandbox_mode'];
        }
        if (in_array($operation, self::NEEDS_APPROVAL, true) && !$approved) {
            return ['live' => false, 'reason' => 'approval_required'];
        }
        return ['live' => true, 'reason' => 'approved'];
    }

    /** Deterministic fake result returned instead of calling the provider. */
    public static function simulate(string $operation, array $args): array
    {
        $id = 'SIM-' . substr(hash('sha256', $operation . json_encode($args)), 0, 12);
        switch ($operation) {
            case 'purchase_number':
                return ['number_id' => $id, 'number' => (string) ($args['number'] ?? ''), 'status' => 'simulated'];
            case 'start_call':
            case 'transfer_call':
                return ['call_id' => $id, 'status' => 'simulated'];
            case 'create_subaccount':
                return ['account_id' => $id, 'status' => 'simulated'];
            default:
                return ['ok' => true, 'status' => 'simulated'];
        }
    }
}

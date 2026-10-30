<?php

/**
 * Tourfecto - AI Voice Agent: per-call cost tracking. One row per (provider, call_id); later updates overwrite
 * (so an estimate is replaced by the provider's real price). cost_source says which one it is.
 */
class VoiceCostTracker
{
    /** Pure: estimate from billed seconds and a per-minute rate; providers bill per started minute here. */
    public static function estimate(int $durationSeconds, float $ratePerMinute): float
    {
        if ($durationSeconds <= 0 || $ratePerMinute <= 0) {
            return 0.0;
        }
        return round(ceil($durationSeconds / 60) * $ratePerMinute, 4);
    }

    public static function record(int $tenantId, string $provider, string $callId, string $direction, ?int $durationS, ?float $costUsd, string $costSource): void
    {
        if (!in_array($costSource, ['provider', 'estimated', 'none'], true)) {
            throw new InvalidArgumentException('Invalid cost source');
        }
        Database::getInstance()->exec(
            'INSERT INTO voice_call_costs (tenant_user_id, provider, call_id, direction, duration_s, cost_usd, cost_source, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,NOW(),NOW())
             ON DUPLICATE KEY UPDATE duration_s=COALESCE(VALUES(duration_s),duration_s), cost_usd=COALESCE(VALUES(cost_usd),cost_usd),
               cost_source=IF(VALUES(cost_usd) IS NULL, cost_source, VALUES(cost_source)), updated_at=NOW()',
            [$tenantId, $provider, $callId, $direction, $durationS, $costUsd, $costSource]
        );
    }

    /** @return array{month_usd:float, calls:int, budget_usd:?float, over_budget:bool} */
    public static function monthSummary(int $tenantId, ?float $budget): array
    {
        $r = Database::getInstance()->query(
            "SELECT COALESCE(SUM(cost_usd),0) AS c, COUNT(*) AS n FROM voice_call_costs WHERE tenant_user_id = ? AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')",
            [$tenantId]
        )[0] ?? ['c' => 0, 'n' => 0];
        $spent = round((float) $r['c'], 4);
        return ['month_usd' => $spent, 'calls' => (int) $r['n'], 'budget_usd' => $budget, 'over_budget' => $budget !== null && $spent >= $budget];
    }
}

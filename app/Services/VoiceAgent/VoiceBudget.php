<?php

/** Tourfecto - AI Voice Agent: monthly budget and daily limits. Pure. Spend here means what the COMPANY is charged (ledger price), not Tourfecto's cost. */
class VoiceBudget
{
    public const THRESHOLDS = [80, 100];

    /**
     * @param string[]|int[] $alerted thresholds already notified this month
     * @return array{state:string, percent:?float, blocked:bool, alerts:int[]} state: none (no budget) | ok | warning | exhausted
     */
    public static function evaluate(float $spent, ?float $budget, string $policy, array $alerted = []): array
    {
        if ($budget === null || $budget <= 0) {
            return ['state' => 'none', 'percent' => null, 'blocked' => false, 'alerts' => []];
        }
        $pct = round($spent / $budget * 100, 1);
        $state = $pct >= 100 ? 'exhausted' : ($pct >= 80 ? 'warning' : 'ok');
        $due = [];
        foreach (self::THRESHOLDS as $t) {
            if ($pct >= $t && !in_array($t, array_map('intval', $alerted), true)) {
                $due[] = $t;
            }
        }
        return ['state' => $state, 'percent' => $pct, 'blocked' => $state === 'exhausted' && $policy === 'block', 'alerts' => $due];
    }

    public static function dailyMinutesExceeded(int $secondsToday, int $capMinutes): bool
    {
        return $capMinutes > 0 && $secondsToday >= $capMinutes * 60;
    }
}

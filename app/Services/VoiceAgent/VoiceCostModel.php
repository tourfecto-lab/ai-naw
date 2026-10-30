<?php

/**
 * Tourfecto - AI Voice Agent: what one call cost, split by component, and what the company is charged for it. Pure.
 * Provider call charges are used when the provider reported them; otherwise an estimate from platform rates (voice_rates_json) and marked as such;
 * with no rate and no report the cost is left unknown (source "none") rather than invented.
 */
class VoiceCostModel
{
    public const RATE_KEYS = ['inbound_per_min', 'outbound_per_min', 'speech_per_min', 'storage_per_gb_month'];

    /** Parse the platform rates setting: only known keys, only sane non-negative numbers. */
    public static function rates(string $json): array
    {
        $j = json_decode($json, true);
        $out = [];
        foreach (self::RATE_KEYS as $k) {
            if (is_array($j) && isset($j[$k]) && is_numeric($j[$k]) && (float) $j[$k] >= 0 && (float) $j[$k] < 100) {
                $out[$k] = (float) $j[$k];
            }
        }
        return $out;
    }

    public static function billedMinutes(?int $seconds): int
    {
        return $seconds !== null && $seconds > 0 ? (int) ceil($seconds / 60) : 0;
    }

    /**
     * @param array{direction:string, duration_s:?int, mode:?string, ai_cost_usd:?float} $call
     * @param ?float $providerCostUsd what the provider reported for the call (null = not reported)
     * @return list<array{component:string, quantity:float, unit:string, cost_usd:float, price_usd:float, cost_source:string}>
     */
    public static function components(array $call, ?float $providerCostUsd, array $rates, float $markupPct): array
    {
        $rows = [];
        $minutes = self::billedMinutes($call['duration_s'] ?? null);
        $dir = ($call['direction'] ?? 'inbound') === 'outbound' ? 'outbound' : 'inbound';
        $rate = $rates[$dir . '_per_min'] ?? null;
        if ($providerCostUsd !== null) {
            $cost = $providerCostUsd;
            $src = 'provider';
        } elseif ($rate !== null && $minutes > 0) {
            $cost = round($minutes * $rate, 6);
            $src = 'estimated';
        } else {
            $cost = 0.0;
            $src = 'none';
        }
        if ($minutes > 0 || $providerCostUsd !== null) {
            $rows[] = ['component' => $dir . '_minutes', 'quantity' => (float) $minutes, 'unit' => 'minute', 'cost_usd' => $cost, 'price_usd' => VoicePricing::applyPrecise($cost, $markupPct) ?? 0.0, 'cost_source' => $src];
        }
        if (isset($rates['speech_per_min']) && ($call['mode'] ?? '') === 'turn' && $minutes > 0) { // speech recognition and voice synthesis in turn mode
            $c = round($minutes * $rates['speech_per_min'], 6);
            $rows[] = ['component' => 'speech', 'quantity' => (float) $minutes, 'unit' => 'minute', 'cost_usd' => $c, 'price_usd' => VoicePricing::applyPrecise($c, $markupPct) ?? 0.0, 'cost_source' => 'estimated'];
        }
        $ai = (float) ($call['ai_cost_usd'] ?? 0);
        if ($ai > 0) {
            $rows[] = ['component' => 'ai', 'quantity' => 1.0, 'unit' => 'call', 'cost_usd' => round($ai, 6), 'price_usd' => VoicePricing::applyPrecise($ai, $markupPct) ?? 0.0, 'cost_source' => 'provider'];
        }
        return $rows;
    }

    /** One day of a monthly number rental, for a given month length. @return array{cost_usd:float, price_usd:float} */
    public static function rentalDay(?float $providerMonthly, ?float $customerMonthly, int $daysInMonth): array
    {
        $d = max(28, min(31, $daysInMonth));
        return ['cost_usd' => round(((float) $providerMonthly) / $d, 6), 'price_usd' => round(((float) ($customerMonthly ?? $providerMonthly)) / $d, 6)];
    }
}

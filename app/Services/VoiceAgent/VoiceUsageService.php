<?php

/**
 * Tourfecto - AI Voice Agent: the usage ledger. One row per (tenant, ref, component), upserted so a provider's final price replaces an estimate.
 * "cost" is what Tourfecto pays; "price" is what the company is charged (cost plus markup). Budgets and invoices use price; margin reports use both.
 */
class VoiceUsageService
{
    private function db(): Database
    {
        return Database::getInstance();
    }

    public static function rates(): array
    {
        return VoiceCostModel::rates((new SystemSettingsService())->get('voice_rates_json', ''));
    }

    /** Called when a call closes (after the provider cost lookup). Safe to repeat. */
    public function recordCall(int $tenantId, int $callId, ?float $providerCostUsd): void
    {
        $call = $this->db()->query('SELECT direction, duration_s, mode, ai_cost_usd, COALESCE(ended_at, started_at) AS at FROM voice_calls WHERE id = ? AND tenant_user_id = ? LIMIT 1', [$callId, $tenantId])[0] ?? null;
        if (!$call) {
            return;
        }
        foreach (VoiceCostModel::components($call, $providerCostUsd, self::rates(), VoicePricing::markupPct()) as $c) {
            $this->upsert($tenantId, 'call:' . $callId, $c['component'], $c['quantity'], $c['unit'], $c['cost_usd'], $c['price_usd'], $c['cost_source'], (string) $call['at']);
        }
    }

    public function upsert(int $tenantId, string $ref, string $component, float $qty, string $unit, float $cost, float $price, string $source, string $at): void
    {
        $this->db()->exec(
            'INSERT INTO voice_usage_ledger (tenant_user_id, ref, component, quantity, unit, cost_usd, price_usd, cost_source, occurred_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE quantity=VALUES(quantity), cost_usd=VALUES(cost_usd), price_usd=VALUES(price_usd), cost_source=VALUES(cost_source), updated_at=NOW()',
            [$tenantId, $ref, $component, $qty, $unit, $cost, $price, $source, $at]
        );
    }

    /** One day of rental for every live number (idempotent per number and day). Returns rows written. */
    public function accrueRental(?string $day = null): int
    {
        $day = $day ?? gmdate('Y-m-d');
        $dim = (int) gmdate('t', strtotime($day));
        $n = 0;
        foreach ($this->db()->query("SELECT id, tenant_user_id, monthly_cost, provider_monthly_cost FROM voice_numbers WHERE status = 'active' AND monthly_cost IS NOT NULL") ?: [] as $r) {
            $d = VoiceCostModel::rentalDay($r['provider_monthly_cost'] !== null ? (float) $r['provider_monthly_cost'] : null, (float) $r['monthly_cost'], $dim);
            $this->upsert((int) $r['tenant_user_id'], 'number:' . $r['id'] . ':' . str_replace('-', '', $day), 'number_rental', 1, 'day', $d['cost_usd'], $d['price_usd'], 'provider', $day . ' 00:00:00');
            $n++;
        }
        return $n;
    }

    /** @return array{cost_usd:float, price_usd:float, by_component:array<string,array{cost_usd:float,price_usd:float,quantity:float}>} */
    public function month(int $tenantId, ?string $month = null): array
    {
        $month = $month ?? gmdate('Y-m');
        $out = ['cost_usd' => 0.0, 'price_usd' => 0.0, 'by_component' => []];
        foreach ($this->db()->query(
            "SELECT component, SUM(cost_usd) AS cost, SUM(price_usd) AS price, SUM(quantity) AS qty FROM voice_usage_ledger WHERE tenant_user_id = ? AND occurred_at >= ? AND occurred_at < DATE_ADD(?, INTERVAL 1 MONTH) GROUP BY component",
            [$tenantId, $month . '-01 00:00:00', $month . '-01 00:00:00']
        ) ?: [] as $r) {
            $out['by_component'][$r['component']] = ['cost_usd' => round((float) $r['cost'], 4), 'price_usd' => round((float) $r['price'], 4), 'quantity' => (float) $r['qty']];
            $out['cost_usd'] += (float) $r['cost'];
            $out['price_usd'] += (float) $r['price'];
        }
        $out['cost_usd'] = round($out['cost_usd'], 4);
        $out['price_usd'] = round($out['price_usd'], 4);
        return $out;
    }

    public function secondsToday(int $tenantId): int
    {
        return (int) ($this->db()->query('SELECT COALESCE(SUM(duration_s),0) AS s FROM voice_calls WHERE tenant_user_id = ? AND started_at >= CURDATE()', [$tenantId])[0]['s'] ?? 0);
    }
}

<?php

/**
 * Tourfecto - AI Voice Agent: platform-admin reporting ACROSS companies (provider health, errors, costs).
 * This is the only voice class that reads other companies' rows on purpose. It must only be called from admin-only routes
 * (VoiceAdminController); VoiceTenantIsolationTest enforces that. It never returns credentials, call content or customer data:
 * only counts, costs and error messages.
 */
class VoiceAdminReports
{
    private function db(): Database
    {
        return Database::getInstance();
    }

    /** Provider status for the admin panel: which platform keys are missing (names only) and how the companies use it. */
    public function providers(): array
    {
        $modes = [];
        foreach ($this->db()->query('SELECT provider, mode, COUNT(*) AS n FROM voice_providers GROUP BY provider, mode') ?: [] as $r) {
            $modes[$r['provider']][$r['mode']] = (int) $r['n'];
        }
        $numbers = [];
        foreach ($this->db()->query("SELECT provider, status, COUNT(*) AS n FROM voice_numbers WHERE provider IS NOT NULL GROUP BY provider, status") ?: [] as $r) {
            $numbers[$r['provider']][$r['status']] = (int) $r['n'];
        }
        $errors = [];
        foreach ($this->db()->query('SELECT provider, COUNT(*) AS n FROM voice_provider_errors WHERE created_at >= NOW() - INTERVAL 1 DAY GROUP BY provider') ?: [] as $r) {
            $errors[$r['provider']] = (int) $r['n'];
        }
        $out = [];
        foreach (VoiceProviderRegistry::keys() as $k) {
            $missing = VoicePlatformCredentials::missing($k);
            $out[] = [
                'provider' => $k, 'configured' => !$missing, 'missing_keys' => $missing,
                'capabilities' => VoiceProviderService::capabilitiesOf($k),
                'companies_by_mode' => $modes[$k] ?? [], 'numbers_by_status' => $numbers[$k] ?? [], 'errors_24h' => $errors[$k] ?? 0,
            ];
        }
        return $out;
    }

    /** Latest provider errors from every company, newest first. */
    public function errors(int $limit = 100): array
    {
        return $this->db()->query(
            'SELECT tenant_user_id, provider, operation, http_code, provider_code, message, created_at FROM voice_provider_errors ORDER BY id DESC LIMIT ' . max(1, min(500, $limit))
        ) ?: [];
    }

    /**
     * Usage cost per company for a month, split into the components the platform pays for.
     * @return array{month:string, companies:list<array<string,mixed>>, totals:array<string,float>}
     */
    public function costs(string $month): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new InvalidArgumentException('month must be YYYY-MM');
        }
        $from = $month . '-01 00:00:00';
        $to = date('Y-m-d H:i:s', strtotime($from . ' +1 month'));
        $rows = [];
        $add = static function (array $list, string $field) use (&$rows): void {
            foreach ($list as $r) {
                $rows[(int) $r['t']]['tenant_user_id'] = (int) $r['t'];
                $rows[(int) $r['t']][$field] = round((float) $r['v'], 4);
            }
        };
        $add($this->db()->query("SELECT tenant_user_id AS t, SUM(cost_usd) AS v FROM voice_call_costs WHERE direction = 'inbound' AND created_at >= ? AND created_at < ? GROUP BY tenant_user_id", [$from, $to]) ?: [], 'inbound_calls_usd');
        $add($this->db()->query("SELECT tenant_user_id AS t, SUM(cost_usd) AS v FROM voice_call_costs WHERE direction = 'outbound' AND created_at >= ? AND created_at < ? GROUP BY tenant_user_id", [$from, $to]) ?: [], 'outbound_calls_usd');
        $add($this->db()->query('SELECT tenant_user_id AS t, SUM(ai_cost_usd) AS v FROM voice_calls WHERE started_at >= ? AND started_at < ? GROUP BY tenant_user_id', [$from, $to]) ?: [], 'ai_usd');
        $add($this->db()->query("SELECT tenant_user_id AS t, SUM(monthly_cost) AS v FROM voice_numbers WHERE source = 'purchased' AND status IN ('active','pending') AND created_at < ? GROUP BY tenant_user_id", [$to]) ?: [], 'number_rental_usd');
        return self::summarise($month, array_values($rows));
    }

    /** Pure: fill the missing components with 0, add a total per company and overall. */
    public static function summarise(string $month, array $rows): array
    {
        $parts = ['inbound_calls_usd', 'outbound_calls_usd', 'ai_usd', 'number_rental_usd'];
        $totals = array_fill_keys(array_merge($parts, ['total_usd']), 0.0);
        $out = [];
        foreach ($rows as $r) {
            $row = ['tenant_user_id' => (int) $r['tenant_user_id']];
            $sum = 0.0;
            foreach ($parts as $p) {
                $row[$p] = round((float) ($r[$p] ?? 0), 4);
                $sum += $row[$p];
                $totals[$p] += $row[$p];
            }
            $row['total_usd'] = round($sum, 4);
            $totals['total_usd'] += $row['total_usd'];
            $out[] = $row;
        }
        usort($out, static fn ($a, $b) => $b['total_usd'] <=> $a['total_usd']);
        return ['month' => $month, 'companies' => $out, 'totals' => array_map(static fn ($v) => round($v, 4), $totals)];
    }

    /** Margin per company for a month: what Tourfecto paid vs what the company was charged. Pure maths in margin(). */
    public function margins(string $month): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new InvalidArgumentException('Invalid month');
        }
        $rows = $this->db()->query(
            "SELECT tenant_user_id, SUM(cost_usd) AS cost, SUM(price_usd) AS price, SUM(cost_source = 'none' AND component LIKE '%minutes') AS unknown_cost_rows
             FROM voice_usage_ledger WHERE occurred_at >= ? AND occurred_at < DATE_ADD(?, INTERVAL 1 MONTH) GROUP BY tenant_user_id ORDER BY price DESC LIMIT 200",
            [$month . '-01 00:00:00', $month . '-01 00:00:00']
        ) ?: [];
        $out = ['month' => $month, 'companies' => [], 'total' => self::margin(0, 0)];
        $cost = $price = 0.0;
        foreach ($rows as $r) {
            $out['companies'][] = ['company' => (int) $r['tenant_user_id'], 'unknown_cost_rows' => (int) $r['unknown_cost_rows']] + self::margin((float) $r['cost'], (float) $r['price']);
            $cost += (float) $r['cost'];
            $price += (float) $r['price'];
        }
        $out['total'] = self::margin($cost, $price);
        return $out;
    }

    public static function margin(float $cost, float $price): array
    {
        return ['cost_usd' => round($cost, 4), 'price_usd' => round($price, 4), 'margin_usd' => round($price - $cost, 4), 'margin_pct' => $price > 0 ? round(($price - $cost) / $price * 100, 1) : null];
    }

    /** Conversation quality across companies for the last N days: reply time, fallback share, provider error counts. Numbers only. */
    public function quality(int $days = 7): array
    {
        $days = max(1, min(90, $days));
        $c = $this->db()->query(
            "SELECT COUNT(*) AS calls, SUM(mode = 'fallback') AS fallback, SUM(latency_ms_total) AS lat, SUM(turns) AS turns FROM voice_calls WHERE started_at >= NOW() - INTERVAL $days DAY"
        )[0] ?? [];
        $e = (int) ($this->db()->query("SELECT COUNT(*) AS n FROM voice_provider_errors WHERE created_at >= NOW() - INTERVAL $days DAY")[0]['n'] ?? 0);
        $calls = (int) ($c['calls'] ?? 0);
        $turns = (int) ($c['turns'] ?? 0);
        return ['days' => $days, 'calls' => $calls, 'fallback_rate_pct' => $calls ? round((int) $c['fallback'] / $calls * 100, 1) : null,
            'avg_reply_ms' => $turns ? (int) round((int) $c['lat'] / $turns) : null, 'provider_errors' => $e];
    }
}

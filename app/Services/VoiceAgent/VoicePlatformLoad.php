<?php

/** Tourfecto - AI Voice Agent: platform-wide aggregates (calls in progress, 30-day totals for the AI CEO). Counts and sums only: nothing about any single company or caller. */
class VoicePlatformLoad
{
    public static function activeCalls(): int
    {
        return (int) (Database::getInstance()->query("SELECT COUNT(*) AS n FROM voice_calls WHERE status = 'in_progress' AND started_at >= NOW() - INTERVAL 15 MINUTE")[0]['n'] ?? 0);
    }

    /**
     * Platform-wide voice picture for the AI CEO (Tourfecto's own business): totals only, nothing about any single company or caller.
     * @return array<string,mixed>
     */
    public static function summary(int $days = 30): array
    {
        $d = max(1, min(90, $days));
        $c = Database::getInstance()->query("SELECT COUNT(*) AS calls, COUNT(DISTINCT tenant_user_id) AS companies, COALESCE(SUM(duration_s),0) AS secs, COALESCE(SUM(ai_cost_usd),0) AS ai, SUM(status = 'missed') AS missed FROM voice_calls WHERE started_at >= NOW() - INTERVAL $d DAY")[0] ?? [];
        $t = Database::getInstance()->query("SELECT COALESCE(SUM(cost_usd),0) AS usd FROM voice_call_costs WHERE created_at >= NOW() - INTERVAL $d DAY")[0] ?? [];
        $n = Database::getInstance()->query("SELECT COUNT(*) AS numbers, COALESCE(SUM(monthly_cost),0) AS rent FROM voice_numbers WHERE source = 'purchased' AND status IN ('active','pending')")[0] ?? [];
        $e = Database::getInstance()->query("SELECT COUNT(*) AS n FROM voice_provider_errors WHERE created_at >= NOW() - INTERVAL $d DAY")[0] ?? [];
        $calls = (int) ($c['calls'] ?? 0);
        return [
            'period_days' => $d, 'calls' => $calls, 'active_companies' => (int) ($c['companies'] ?? 0), 'minutes' => round(((int) ($c['secs'] ?? 0)) / 60, 1),
            'missed_rate' => VoiceAnalyticsMath::rate((int) ($c['missed'] ?? 0), $calls), 'provider_call_cost_usd' => round((float) ($t['usd'] ?? 0), 2), 'ai_cost_usd' => round((float) ($c['ai'] ?? 0), 2),
            'numbers_in_service' => (int) ($n['numbers'] ?? 0), 'monthly_number_rent_usd' => round((float) ($n['rent'] ?? 0), 2), 'provider_errors' => (int) ($e['n'] ?? 0),
        ];
    }
}

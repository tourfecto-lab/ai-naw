<?php

/** Tourfecto - AI Voice Agent: applies the monthly budget and daily minutes cap for one company, and sends the 80% / 100% alerts once a month. */
class VoiceBudgetService
{
    private VoiceUsageService $usage;

    public function __construct(?VoiceUsageService $usage = null)
    {
        $this->usage = $usage ?? new VoiceUsageService();
    }

    /** @return array{state:string, percent:?float, blocked:bool, alerts:int[], spent_usd:float, budget_usd:?float, reason:?string} */
    public function state(int $tenantId, array $settings): array
    {
        $budget = isset($settings['monthly_budget_usd']) && $settings['monthly_budget_usd'] !== null ? (float) $settings['monthly_budget_usd'] : null;
        $spent = $this->usage->month($tenantId)['price_usd'];
        $alerted = array_column(Database::getInstance()->query('SELECT threshold FROM voice_budget_alerts WHERE tenant_user_id = ? AND month = ?', [$tenantId, gmdate('Y-m')]) ?: [], 'threshold');
        $e = VoiceBudget::evaluate($spent, $budget, (string) ($settings['budget_policy'] ?? 'block'), $alerted);
        $reason = $e['blocked'] ? 'budget_reached' : null;
        if ($reason === null && VoiceBudget::dailyMinutesExceeded($this->usage->secondsToday($tenantId), (int) ($settings['daily_minutes_cap'] ?? 0))) {
            $reason = 'daily_minutes_reached';
        }
        return $e + ['spent_usd' => $spent, 'budget_usd' => $budget, 'reason' => $reason];
    }

    /** Reason a new AI call must not start, or null. Also fires any due alerts. */
    public function blockReason(int $tenantId, array $settings): ?string
    {
        $s = $this->state($tenantId, $settings);
        $this->alert($tenantId, $s);
        return $s['reason'];
    }

    public function alert(int $tenantId, array $state): void
    {
        foreach ($state['alerts'] as $t) {
            // the primary key makes the alert once-per-month even if two calls close at the same moment
            if (!Database::getInstance()->exec('INSERT IGNORE INTO voice_budget_alerts (tenant_user_id, month, threshold, created_at) VALUES (?,?,?,NOW())', [$tenantId, gmdate('Y-m'), $t])) {
                continue;
            }
            if (Database::getInstance()->getConnection()->query('SELECT ROW_COUNT()')->fetchColumn() < 1) {
                continue;
            }
            if (class_exists('Notification')) {
                $msg = $t >= 100 ? 'Your AI Voice Agent monthly budget is used up' . ($state['blocked'] ? '; new AI calls are paused.' : '.') : 'Your AI Voice Agent has used 80% of its monthly budget.';
                Notification::notify($tenantId, 'voice_budget', 'AI Voice Agent budget', $msg, '/dashboard/voice-agent#billing');
            }
        }
    }
}

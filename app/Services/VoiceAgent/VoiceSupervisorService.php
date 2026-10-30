<?php

/**
 * Tourfecto - AI Voice Agent: the AI Call Supervisor. Runs from cron over calls whose post-call actions are done. Free rules decide which calls
 * deserve a model review; each company has a daily review cap and a budget check; an unchanged transcript is never reviewed twice.
 */
class VoiceSupervisorService
{
    private VoiceDataStore $store;
    /** @var callable */
    private $llm;

    public function __construct(?VoiceDataStore $store = null, ?callable $llm = null)
    {
        $this->store = $store ?? new VoiceDataStore();
        $this->llm = $llm ?? static fn (string $prompt, array $opts): array => (new AIOrchestrator())->generateContent($prompt, $opts);
    }

    private function db(): Database
    {
        return Database::getInstance();
    }

    /** @return array{reviewed:int, by_model:int, skipped:int, alerts:int} */
    public function run(int $limit = 20): array
    {
        $sum = ['reviewed' => 0, 'by_model' => 0, 'skipped' => 0, 'alerts' => 0];
        $rows = $this->db()->query(
            "SELECT c.id, c.tenant_user_id FROM voice_calls c LEFT JOIN voice_call_reviews r ON r.call_id = c.id
             WHERE r.id IS NULL AND c.ended_at IS NOT NULL AND c.turns >= 2 AND c.post_call_status = 'done' AND c.started_at >= NOW() - INTERVAL 3 DAY ORDER BY c.id LIMIT " . max(1, min(100, $limit))
        ) ?: [];
        $tenants = [];
        foreach ($rows as $row) {
            $t = (int) $row['tenant_user_id'];
            $res = $this->review($t, (int) $row['id']);
            $sum['reviewed']++;
            $sum['by_model'] += $res === 'model' ? 1 : 0;
            $sum['skipped'] += $res === 'skipped' ? 1 : 0;
            $tenants[$t] = 1;
        }
        foreach (array_keys($tenants) as $t) {
            $sum['alerts'] += (new VoiceInsightsService())->push($t);
        }
        return $sum;
    }

    /** @return string 'model' | 'rules' | 'skipped' */
    public function review(int $tenantId, int $callId): string
    {
        $call = $this->store->getCall($tenantId, $callId);
        if (!$call) {
            return 'skipped';
        }
        $settings = (new VoiceSettingsService())->get($tenantId);
        $turns = $this->store->recentTurns($tenantId, $callId, 200);
        $hash = VoiceSupervisorRules::transcriptHash($turns);
        $flags = VoiceSupervisorRules::flags($call, $turns);
        $useModel = !empty($settings['supervisor_enabled']) && VoiceSupervisorRules::needsModel($flags, VoiceSupervisorRules::sampled($callId, (int) ($settings['supervisor_sample_pct'] ?? 20)));
        if ($useModel) {
            $today = (int) ($this->db()->query("SELECT COUNT(*) AS n FROM voice_call_reviews WHERE tenant_user_id = ? AND source = 'model' AND created_at >= CURDATE()", [$tenantId])[0]['n'] ?? 0);
            if ($today >= (int) ($settings['supervisor_daily_cap'] ?? 20) || (new VoiceBudgetService())->state($tenantId, $settings)['state'] === 'exhausted') {
                $useModel = false; // cap or budget reached: the free rules-only note still records the warning signs
            }
        }
        $review = ['quality' => null, 'issues' => $flags, 'loss_reason' => null, 'objections' => [], 'suggestions' => []];
        $source = 'rules';
        $cost = 0.0;
        if ($useModel) {
            $r = ($this->llm)(VoiceSupervisorRules::prompt($turns, $this->store->businessName($tenantId), $flags), ['task' => 'voice_supervisor', 'user_id' => $tenantId, 'responseMimeType' => 'application/json', 'temperature' => 0.1, 'maxOutputTokens' => 450]);
            $cost = (float) ($r['cost'] ?? 0);
            $parsed = !empty($r['success']) ? VoiceSupervisorRules::parseReview((string) ($r['data'] ?? '')) : null;
            if ($parsed) {
                $review = ['quality' => $parsed['quality'], 'issues' => array_values(array_unique(array_merge($flags, $parsed['issues']))), 'loss_reason' => $parsed['loss_reason'], 'objections' => $parsed['objections'], 'suggestions' => $parsed['suggestions']];
                $source = 'model';
            }
        }
        $this->db()->exec(
            'INSERT IGNORE INTO voice_call_reviews (tenant_user_id, call_id, source, quality_score, issues, loss_reason, objections, suggestions, transcript_hash, cost_usd, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())',
            [$tenantId, $callId, $source, $review['quality'], json_encode($review['issues']), $review['loss_reason'], json_encode($review['objections']), json_encode($review['suggestions'], JSON_UNESCAPED_UNICODE), $hash, round($cost, 4)]
        );
        if ($cost > 0) {
            $this->store->updateCall($tenantId, $callId, ['ai_cost_usd_add' => $cost]);
        }
        return $source === 'model' ? 'model' : ($flags || $useModel ? 'rules' : 'skipped');
    }
}

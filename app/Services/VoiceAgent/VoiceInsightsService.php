<?php

/**
 * Tourfecto - AI Voice Agent: what the voice channel tells the strategic brain. Feeds the existing AI CEO tables (ceo_risk_alerts,
 * ceo_growth_opportunities) and the CEO Advisor snapshot; it does not run any analysis of its own beyond counting reviews.
 */
class VoiceInsightsService
{
    public const MIN_REVIEWS = 5;

    private function db(): Database
    {
        return Database::getInstance();
    }

    /**
     * Pure: turn aggregated review counts into insights worth a CEO's attention. Needs a minimum number of reviews so one odd call never becomes a "trend".
     * @param array{reviews:int, objections:array<string,int>, loss_reasons:array<string,int>, issues:array<string,int>} $agg
     * @return list<array{kind:string, title:string, description:string, level:string}>
     */
    public static function insights(array $agg): array
    {
        $out = [];
        $n = (int) $agg['reviews'];
        if ($n < self::MIN_REVIEWS) {
            return $out;
        }
        $obj = VoiceAnalyticsMath::top($agg['objections'], 1)[0] ?? null;
        $callShare = $obj ? $obj['count'] / $n : 0.0; // share of REVIEWED CALLS that raised it (not of all objections)
        if ($obj && $callShare >= 0.3 && $obj['count'] >= 3) {
            $out[] = ['kind' => 'opportunity', 'title' => 'Voice: callers often raise "' . str_replace('_', ' ', $obj['key']) . '"', 'description' => sprintf('%d of %d reviewed calls (%d%%) hit this objection. Prepare a clear answer or offer for the assistant and the team.', $obj['count'], $n, round($callShare * 100)), 'level' => 'high'];
        }
        $loss = VoiceAnalyticsMath::top(array_diff_key($agg['loss_reasons'], ['unknown' => 1]), 1)[0] ?? null;
        if ($loss && $loss['count'] >= 3) {
            $out[] = ['kind' => 'opportunity', 'title' => 'Voice: top reason callers are lost is "' . str_replace('_', ' ', $loss['key']) . '"', 'description' => sprintf('%d reviewed calls ended without a booking mainly because of this.', $loss['count']), 'level' => 'medium'];
        }
        if (($agg['issues']['ungrounded_price'] ?? 0) >= 3) {
            $out[] = ['kind' => 'risk', 'title' => 'Voice: the assistant tried to quote prices it could not verify', 'description' => sprintf('%d calls in the period. The safety net replaced those answers, but check that your trips and prices are complete in the catalogue.', $agg['issues']['ungrounded_price']), 'level' => 'high'];
        }
        if (($agg['issues']['inaccurate_answer'] ?? 0) >= 2) {
            $out[] = ['kind' => 'risk', 'title' => 'Voice: inaccurate answers found in reviewed calls', 'description' => sprintf('%d reviewed calls contained an answer the supervisor judged inaccurate. Review them under Call History.', $agg['issues']['inaccurate_answer']), 'level' => 'high'];
        }
        return $out;
    }

    public function aggregate(int $tenantId, int $days = 14): array
    {
        $agg = ['reviews' => 0, 'objections' => [], 'loss_reasons' => [], 'issues' => [], 'quality' => []];
        foreach ($this->db()->query('SELECT quality_score, issues, loss_reason, objections FROM voice_call_reviews WHERE tenant_user_id = ? AND created_at >= NOW() - INTERVAL ' . max(1, min(90, $days)) . ' DAY LIMIT 5000', [$tenantId]) ?: [] as $r) {
            $agg['reviews']++;
            foreach ((json_decode((string) $r['issues'], true) ?: []) as $i) {
                $agg['issues'][$i] = ($agg['issues'][$i] ?? 0) + 1;
            }
            foreach ((json_decode((string) $r['objections'], true) ?: []) as $o) {
                $agg['objections'][$o] = ($agg['objections'][$o] ?? 0) + 1;
            }
            if (!empty($r['loss_reason'])) {
                $agg['loss_reasons'][$r['loss_reason']] = ($agg['loss_reasons'][$r['loss_reason']] ?? 0) + 1;
            }
            if ($r['quality_score'] !== null) {
                $agg['quality'][] = (int) $r['quality_score'];
            }
        }
        return $agg;
    }

    /** Pushes new insights into the AI CEO tables once per week per title (no duplicates). @return int inserted */
    public function push(int $tenantId): int
    {
        $n = 0;
        foreach (self::insights($this->aggregate($tenantId)) as $i) {
            $table = $i['kind'] === 'risk' ? 'ceo_risk_alerts' : 'ceo_growth_opportunities';
            $dup = $this->db()->query("SELECT id FROM $table WHERE user_id = ? AND title = ? AND created_at >= NOW() - INTERVAL 7 DAY LIMIT 1", [$tenantId, $i['title']]);
            if ($dup) {
                continue;
            }
            $ok = $i['kind'] === 'risk'
                ? $this->db()->exec("INSERT INTO ceo_risk_alerts (user_id, title, description, severity, source_module, is_resolved) VALUES (?,?,?,?, 'voice', 0)", [$tenantId, $i['title'], $i['description'], $i['level']])
                : $this->db()->exec("INSERT INTO ceo_growth_opportunities (user_id, title, description, estimated_impact, status) VALUES (?,?,?,?, 'new')", [$tenantId, $i['title'], $i['description'], $i['level']]);
            $n += $ok ? 1 : 0;
        }
        return $n;
    }

    /** Compact last-30-days picture for the CEO Advisor; null when the company has no voice activity. */
    public function snapshotFor(int $tenantId): ?array
    {
        $a = (new VoiceAnalyticsService())->overview($tenantId, null, null);
        if ($a['calls']['total'] === 0) {
            return null;
        }
        return [
            'period_days' => 30, 'calls' => $a['calls']['total'], 'missed' => $a['calls']['missed'], 'minutes' => $a['calls']['minutes'], 'bookings' => $a['bookings']['count'],
            'revenue_usd' => $a['roi']['revenue'], 'cost_usd' => $a['roi']['cost'], 'roi_pct' => $a['roi']['roi_pct'], 'satisfaction_avg' => $a['satisfaction']['average'],
            'assistant_quality_avg' => $a['assistant']['quality_avg'], 'top_objections' => array_column($a['objections'], 'key'), 'top_loss_reasons' => array_column($a['loss_reasons'], 'key'),
        ];
    }
}

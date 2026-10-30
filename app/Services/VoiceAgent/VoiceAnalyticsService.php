<?php

/** Tourfecto - AI Voice Agent: the numbers behind AI VOICE ANALYTICS for one company. Every query is tenant-scoped. */
class VoiceAnalyticsService
{
    private function db(): Database
    {
        return Database::getInstance();
    }

    public function overview(int $tenantId, ?string $from, ?string $to): array
    {
        [$f, $t] = VoiceAnalyticsMath::period($from, $to);
        $rng = [$tenantId, $f, $t];

        $calls = ['total' => 0, 'inbound' => 0, 'outbound' => 0, 'missed' => 0, 'transferred' => 0, 'seconds' => 0];
        $ai = 0.0;
        $lat = 0;
        $samples = 0;
        foreach ($this->db()->query('SELECT direction, status, COUNT(*) AS n, COALESCE(SUM(duration_s),0) AS secs, COALESCE(SUM(ai_cost_usd),0) AS ai, COALESCE(SUM(latency_ms_total),0) AS lat, COALESCE(SUM(latency_samples),0) AS samples FROM voice_calls WHERE tenant_user_id = ? AND started_at >= ? AND started_at < ? GROUP BY direction, status', $rng) ?: [] as $r) {
            $n = (int) $r['n'];
            $calls['total'] += $n;
            $calls[$r['direction']] += $n;
            $calls['missed'] += $r['status'] === 'missed' ? $n : 0;
            $calls['transferred'] += $r['status'] === 'transferred' ? $n : 0;
            $calls['seconds'] += (int) $r['secs'];
            $ai += (float) $r['ai'];
            $lat += (int) $r['lat'];
            $samples += (int) $r['samples'];
        }
        $outcomes = [];
        foreach ($this->db()->query("SELECT outcome, COUNT(*) AS n FROM voice_calls WHERE tenant_user_id = ? AND started_at >= ? AND started_at < ? AND outcome IS NOT NULL GROUP BY outcome", $rng) ?: [] as $r) {
            $outcomes[$r['outcome']] = (int) $r['n'];
        }
        $leads = (int) ($this->db()->query('SELECT COUNT(DISTINCT crm_lead_id) AS n FROM voice_calls WHERE tenant_user_id = ? AND started_at >= ? AND started_at < ? AND crm_lead_id IS NOT NULL', $rng)[0]['n'] ?? 0);

        // bookings made on calls, split by currency: amounts in different currencies are never added together
        $bk = ['count' => 0, 'confirmed' => 0, 'pending' => 0, 'by_currency' => []];
        $usdConfirmed = 0;
        foreach ($this->db()->query('SELECT b.status, b.currency, COUNT(*) AS n, COALESCE(SUM(b.total_amount),0) AS amt FROM voice_calls c JOIN bookings b ON b.id = c.booking_id AND b.user_id = c.tenant_user_id WHERE c.tenant_user_id = ? AND c.started_at >= ? AND c.started_at < ? GROUP BY b.status, b.currency', $rng) ?: [] as $r) {
            $bk['count'] += (int) $r['n'];
            $paid = in_array($r['status'], ['confirmed', 'completed'], true);
            $bk[$paid ? 'confirmed' : 'pending'] += $paid || $r['status'] === 'pending' ? (int) $r['n'] : 0;
            $cur = strtoupper((string) $r['currency']);
            $usdConfirmed += $paid && $cur === 'USD' ? (int) $r['n'] : 0;
            $bk['by_currency'][$cur] = $bk['by_currency'][$cur] ?? ['confirmed' => 0.0, 'pending' => 0.0];
            if ($paid) {
                $bk['by_currency'][$cur]['confirmed'] += (float) $r['amt'];
            } elseif ($r['status'] === 'pending') {
                $bk['by_currency'][$cur]['pending'] += (float) $r['amt'];
            }
        }

        // cost: provider call charges + AI + number rental in the period
        $tele = ['inbound' => 0.0, 'outbound' => 0.0];
        foreach ($this->db()->query('SELECT direction, COALESCE(SUM(cost_usd),0) AS c FROM voice_call_costs WHERE tenant_user_id = ? AND created_at >= ? AND created_at < ? GROUP BY direction', $rng) ?: [] as $r) {
            $tele[$r['direction']] = (float) $r['c'];
        }
        $rental = 0.0;
        foreach ($this->db()->query("SELECT monthly_cost, created_at FROM voice_numbers WHERE tenant_user_id = ? AND source = 'purchased' AND monthly_cost IS NOT NULL AND created_at < ?", [$tenantId, $t]) ?: [] as $r) {
            $rental += VoiceAnalyticsMath::proratedRental((float) $r['monthly_cost'], $r['created_at'], $f, $t);
        }
        $cost = round($tele['inbound'] + $tele['outbound'] + $ai + $rental, 4);
        $usdRevenue = $bk['by_currency']['USD']['confirmed'] ?? 0.0;

        // supervisor reviews and survey answers
        $quality = [];
        $issues = [];
        $loss = [];
        $obj = [];
        foreach ($this->db()->query('SELECT quality_score, issues, loss_reason, objections FROM voice_call_reviews WHERE tenant_user_id = ? AND created_at >= ? AND created_at < ? LIMIT 5000', $rng) ?: [] as $r) {
            $r['quality_score'] !== null && $quality[] = (int) $r['quality_score'];
            foreach ((json_decode((string) $r['issues'], true) ?: []) as $i) {
                $issues[$i] = ($issues[$i] ?? 0) + 1;
            }
            foreach ((json_decode((string) $r['objections'], true) ?: []) as $o) {
                $obj[$o] = ($obj[$o] ?? 0) + 1;
            }
            !empty($r['loss_reason']) && $loss[$r['loss_reason']] = ($loss[$r['loss_reason']] ?? 0) + 1;
        }
        $scores = [];
        foreach ($this->db()->query("SELECT qualification FROM voice_calls WHERE tenant_user_id = ? AND started_at >= ? AND started_at < ? AND qualification LIKE '%survey_score%' LIMIT 2000", $rng) ?: [] as $r) {
            $q = json_decode((string) $r['qualification'], true);
            isset($q['survey_score']) && $scores[] = (int) $q['survey_score'];
        }

        // most requested trips: booking requests and "send me details" requests
        $trips = [];
        foreach ($this->db()->query("SELECT booking_request, send_requests FROM voice_calls WHERE tenant_user_id = ? AND started_at >= ? AND started_at < ? AND (booking_request IS NOT NULL OR send_requests IS NOT NULL) LIMIT 3000", $rng) ?: [] as $r) {
            $ids = [];
            $b = json_decode((string) $r['booking_request'], true);
            !empty($b['product_id']) && $ids[(int) $b['product_id']] = 1;
            foreach ((json_decode((string) $r['send_requests'], true) ?: []) as $s) {
                !empty($s['product_id']) && $ids[(int) $s['product_id']] = 1;
            }
            foreach (array_keys($ids) as $id) {
                $trips[$id] = ($trips[$id] ?? 0) + 1;
            }
        }
        $topTrips = [];
        foreach (VoiceAnalyticsMath::top(array_combine(array_map('strval', array_keys($trips)), array_values($trips)) ?: [], 5) as $row) {
            $p = $this->db()->query('SELECT name FROM crm_products WHERE id = ? AND user_id = ? LIMIT 1', [(int) $row['key'], $tenantId]);
            $topTrips[] = ['product_id' => (int) $row['key'], 'name' => $p ? $p[0]['name'] : null, 'requests' => $row['count']];
        }

        // ad attribution: only calls on dedicated tracking numbers
        $attr = $this->db()->query("SELECT c.attribution_source AS source, c.utm_link_id, COUNT(*) AS calls, SUM(c.booking_id IS NOT NULL) AS bookings, COALESCE(SUM(CASE WHEN b.status IN ('confirmed','completed') THEN b.total_amount END),0) AS revenue, MAX(b.currency) AS currency
            FROM voice_calls c LEFT JOIN bookings b ON b.id = c.booking_id AND b.user_id = c.tenant_user_id
            WHERE c.tenant_user_id = ? AND c.started_at >= ? AND c.started_at < ? AND c.utm_link_id IS NOT NULL GROUP BY c.attribution_source, c.utm_link_id", $rng) ?: [];

        $answered = $calls['inbound'] - $this->missedInbound($rng);
        return [
            'period' => ['from' => $f, 'to' => $t],
            'calls' => $calls + ['minutes' => round($calls['seconds'] / 60, 1), 'avg_seconds' => $calls['total'] ? (int) round($calls['seconds'] / $calls['total']) : null, 'missed_rate' => VoiceAnalyticsMath::rate($calls['missed'], $calls['total']), 'inbound_answered' => max(0, $answered)],
            'outcomes' => $outcomes,
            'leads' => $leads,
            'bookings' => $bk + ['conversion' => VoiceAnalyticsMath::rate($bk['count'], $calls['total'])],
            'cost' => ['inbound_calls_usd' => round($tele['inbound'], 4), 'outbound_calls_usd' => round($tele['outbound'], 4), 'ai_usd' => round($ai, 4), 'number_rental_usd' => round($rental, 4), 'total_usd' => $cost],
            'roi' => VoiceAnalyticsMath::roi($usdRevenue, $cost, $usdConfirmed) + ['note' => 'Revenue counts confirmed or completed bookings in USD only; other currencies are listed under bookings.by_currency.'],
            'satisfaction' => VoiceAnalyticsMath::satisfaction($scores),
            'assistant' => ['reviews' => count($quality), 'quality_avg' => $quality ? round(array_sum($quality) / count($quality), 2) : null, 'issues' => VoiceAnalyticsMath::top($issues, 6), 'avg_reply_ms' => $samples ? (int) round($lat / $samples) : null],
            'objections' => VoiceAnalyticsMath::top($obj, 5),
            'loss_reasons' => VoiceAnalyticsMath::top($loss, 5),
            'top_trips' => $topTrips,
            'attribution' => array_map(static fn ($r) => ['source' => $r['source'], 'utm_link_id' => (int) $r['utm_link_id'], 'calls' => (int) $r['calls'], 'bookings' => (int) $r['bookings'], 'confirmed_revenue' => round((float) $r['revenue'], 2), 'currency' => $r['currency']], $attr),
        ];
    }

    private function missedInbound(array $rng): int
    {
        return (int) ($this->db()->query("SELECT COUNT(*) AS n FROM voice_calls WHERE tenant_user_id = ? AND started_at >= ? AND started_at < ? AND direction = 'inbound' AND status = 'missed'", $rng)[0]['n'] ?? 0);
    }

    public function reviews(int $tenantId, int $limit = 50): array
    {
        return $this->db()->query(
            'SELECT r.call_id, r.source, r.quality_score, r.issues, r.loss_reason, r.objections, r.suggestions, r.created_at, c.from_e164, c.direction, c.outcome
             FROM voice_call_reviews r JOIN voice_calls c ON c.id = r.call_id AND c.tenant_user_id = r.tenant_user_id
             WHERE r.tenant_user_id = ? AND (r.source = \'model\' OR r.issues <> \'[]\') ORDER BY r.id DESC LIMIT ' . max(1, min(200, $limit)),
            [$tenantId]
        ) ?: [];
    }
}

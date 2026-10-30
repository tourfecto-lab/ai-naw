<?php

/** Tourfecto - AI Voice Agent: database implementation of VoiceDataSource. Every query carries the tenant id. */
class VoiceDataStore implements VoiceDataSource
{
    private const UPDATABLE = ['language', 'identity_verified', 'contact_id', 'status', 'outcome', 'handoff_reason', 'qualification', 'booking_request', 'summary', 'turns', 'mode', 'silent_turns', 'ended_at', 'duration_s', 'answered_by', 'next_step', 'needs', 'send_requests', 'post_call_status', 'post_processed_at', 'crm_lead_id', 'booking_id', 'after_hours', 'handoff_topic', 'utm_link_id', 'attribution_source'];

    private function db(): Database
    {
        return Database::getInstance();
    }

    /** Last 9 digits: enough to match the same line across +20 / 0020 / 010 formats without colliding across countries in practice. */
    public static function phoneKey(string $phone): string
    {
        $d = preg_replace('/[^0-9]/', '', $phone);
        return substr($d, -9);
    }

    public function createCall(int $tenantId, array $number, ?int $agentId, string $provider, string $callSid, string $from, string $to, string $mode, string $language, ?int $contactId, string $direction = 'inbound', ?int $jobId = null): int
    {
        $this->db()->exec(
            'INSERT IGNORE INTO voice_calls (tenant_user_id, number_id, agent_id, provider, call_sid, direction, from_e164, to_e164, mode, language, contact_id, job_id, started_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())',
            [$tenantId, $number['id'] ?? null, $agentId, $provider, $callSid, $direction, $from, $to, $mode, $language, $contactId, $jobId]
        );
        $r = $this->db()->query('SELECT id FROM voice_calls WHERE provider = ? AND call_sid = ? AND tenant_user_id = ? LIMIT 1', [$provider, $callSid, $tenantId]);
        return $r ? (int) $r[0]['id'] : 0;
    }

    public function findBySid(int $tenantId, string $provider, string $callSid): ?array
    {
        $r = $this->db()->query('SELECT * FROM voice_calls WHERE tenant_user_id = ? AND provider = ? AND call_sid = ? LIMIT 1', [$tenantId, $provider, $callSid]);
        return $r ? $r[0] : null;
    }

    public function getCall(int $tenantId, int $callId): ?array
    {
        $r = $this->db()->query('SELECT * FROM voice_calls WHERE id = ? AND tenant_user_id = ? LIMIT 1', [$callId, $tenantId]);
        return $r ? $r[0] : null;
    }

    public function updateCall(int $tenantId, int $callId, array $fields): void
    {
        $set = [];
        $params = [];
        foreach ($fields as $k => $v) {
            if ($k === 'ai_cost_usd_add') {
                $set[] = 'ai_cost_usd = ai_cost_usd + ?';
                $params[] = round((float) $v, 6);
            } elseif ($k === 'latency_ms_add') {
                $set[] = 'latency_ms_total = latency_ms_total + ?, latency_samples = latency_samples + 1';
                $params[] = max(0, (int) $v);
            } elseif ($k === 'post_call_attempts_add') {
                $set[] = 'post_call_attempts = post_call_attempts + ?';
                $params[] = (int) $v;
            } elseif ($k === 'verify_attempts_add') {
                $set[] = 'verify_attempts = verify_attempts + ?';
                $params[] = (int) $v;
            } elseif (in_array($k, self::UPDATABLE, true)) {
                $set[] = "$k = ?";
                $params[] = $v;
            }
        }
        if ($set) {
            $params[] = $callId;
            $params[] = $tenantId;
            $this->db()->exec('UPDATE voice_calls SET ' . implode(', ', $set) . ' WHERE id = ? AND tenant_user_id = ?', $params);
        }
    }

    public function addTurn(int $tenantId, int $callId, int $seq, string $role, string $text, array $meta = []): void
    {
        $this->db()->exec(
            'INSERT IGNORE INTO voice_call_turns (call_id, tenant_user_id, seq, role, text, meta, created_at) VALUES (?,?,?,?,?,?,NOW())',
            [$callId, $tenantId, $seq, $role, $text, $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null]
        );
    }

    public function recentTurns(int $tenantId, int $callId, int $limit): array
    {
        $rows = $this->db()->query(
            'SELECT seq, role, text, meta FROM voice_call_turns WHERE call_id = ? AND tenant_user_id = ? ORDER BY seq DESC, id DESC LIMIT ' . max(1, min(300, $limit)),
            [$callId, $tenantId]
        ) ?: [];
        $out = [];
        foreach (array_reverse($rows) as $r) {
            $out[] = ['seq' => (int) $r['seq'], 'role' => $r['role'], 'text' => (string) $r['text'], 'meta' => json_decode((string) $r['meta'], true) ?: []];
        }
        return $out;
    }

    public function searchTrips(int $tenantId, string $query, int $limit = 4): array
    {
        $tokens = array_slice(array_filter(preg_split('/\s+/u', trim($query)) ?: [], static fn ($t) => mb_strlen($t) >= 2), 0, 5);
        $where = 'user_id = ? AND is_active = 1';
        $params = [$tenantId];
        if ($tokens) {
            $parts = [];
            foreach ($tokens as $t) {
                $like = '%' . addcslashes($t, '%_\\') . '%';
                $parts[] = '(name LIKE ? OR description LIKE ?)';
                array_push($params, $like, $like);
            }
            $where .= ' AND (' . implode(' OR ', $parts) . ')';
        }
        $rows = $this->db()->query("SELECT id, name, description, price, currency FROM crm_products WHERE $where ORDER BY id DESC LIMIT " . max(1, min(8, $limit)), $params) ?: [];
        return array_map(static fn ($r) => [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'description' => mb_substr(trim(strip_tags((string) $r['description'])), 0, 200),
            'price' => (float) $r['price'], 'currency' => (string) $r['currency'],
        ], $rows);
    }

    public function tripAvailability(int $tenantId, int $productId, string $date, int $guests): array
    {
        $p = $this->db()->query('SELECT id, name, price, currency FROM crm_products WHERE id = ? AND user_id = ? AND is_active = 1 LIMIT 1', [$productId, $tenantId]);
        if (!$p) {
            return ['found' => false, 'reason' => 'unknown_trip'];
        }
        if (!InventoryService::isValidDate($date)) {
            return ['found' => true, 'available' => false, 'reason' => 'invalid_date', 'name' => $p[0]['name']];
        }
        if ($date < date('Y-m-d')) {
            return ['found' => true, 'available' => false, 'reason' => 'past_date', 'name' => $p[0]['name']];
        }
        $a = (new InventoryService())->checkAvailability($productId, $date);
        $unit = $a['price_override'] ?? (float) $p[0]['price'];
        $enough = $a['available'] && $a['remaining'] >= max(1, $guests);
        return [
            'found' => true, 'name' => $p[0]['name'], 'available' => $enough, 'remaining' => (int) $a['remaining'],
            'unit_price' => $unit, 'total_price' => round($unit * max(1, $guests), 2), 'currency' => $p[0]['currency'],
            'reason' => $enough ? null : ($a['available'] ? 'not_enough_capacity' : ($a['reason'] ?? 'unavailable')),
        ];
    }

    public function customerContext(int $tenantId, string $fromE164): array
    {
        $none = ['known' => false, 'contact_id' => null, 'bookings' => [], 'previous' => []];
        $key = self::phoneKey($fromE164);
        if (strlen($key) < 7) {
            return $none;
        }
        $c = $this->db()->query(
            "SELECT id FROM crm_contacts WHERE user_id = ? AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'(',''),')',''),'+','') LIKE ? ORDER BY id LIMIT 2",
            [$tenantId, '%' . $key]
        ) ?: [];
        if (count($c) !== 1) {
            return $none; // no match, or ambiguous (shared line): never guess who is calling
        }
        $id = (int) $c[0]['id'];
        $b = $this->db()->query(
            'SELECT b.booking_reference, p.name AS trip, b.start_date, b.adults_count, b.children_count, b.total_amount, b.currency, b.status
             FROM bookings b LEFT JOIN crm_products p ON p.id = b.product_id WHERE b.user_id = ? AND b.customer_id = ? ORDER BY b.start_date DESC LIMIT 5',
            [$tenantId, $id]
        ) ?: [];
        $prev = $this->db()->query('SELECT summary FROM voice_calls WHERE tenant_user_id = ? AND contact_id = ? AND summary IS NOT NULL ORDER BY id DESC LIMIT 3', [$tenantId, $id]) ?: [];
        return ['known' => true, 'contact_id' => $id, 'bookings' => $b, 'previous' => array_column($prev, 'summary')];
    }

    public function identityFactors(int $tenantId, int $contactId): array
    {
        $c = $this->db()->query('SELECT email FROM crm_contacts WHERE id = ? AND user_id = ? LIMIT 1', [$contactId, $tenantId]);
        $refs = $this->db()->query('SELECT booking_reference FROM bookings WHERE user_id = ? AND customer_id = ?', [$tenantId, $contactId]) ?: [];
        return ['email' => $c ? $c[0]['email'] : null, 'refs' => array_column($refs, 'booking_reference')];
    }

    public function businessName(int $tenantId): string
    {
        $r = $this->db()->query('SELECT brand_name FROM websites WHERE user_id = ? AND brand_name IS NOT NULL AND brand_name <> \'\' ORDER BY id LIMIT 1', [$tenantId]);
        return $r ? mb_substr((string) $r[0]['brand_name'], 0, 80) : 'our company';
    }

    /** Recent missed call from this number (dedupe window in minutes). */
    public function hasRecentMissed(int $tenantId, string $from, int $minutes = 60): bool
    {
        return (bool) $this->db()->query("SELECT id FROM voice_calls WHERE tenant_user_id = ? AND from_e164 = ? AND status = 'missed' AND started_at >= NOW() - INTERVAL " . (int) $minutes . ' MINUTE LIMIT 1', [$tenantId, $from]);
    }

    /** Calls currently in progress (calls older than 15 minutes are treated as ended, so a lost status callback cannot block a company). */
    public function activeCalls(int $tenantId): int
    {
        return (int) ($this->db()->query("SELECT COUNT(*) AS n FROM voice_calls WHERE tenant_user_id = ? AND status = 'in_progress' AND started_at >= NOW() - INTERVAL 15 MINUTE", [$tenantId])[0]['n'] ?? 0);
    }

    private function knowledgeWebsite(int $tenantId): int
    {
        $r = $this->db()->query('SELECT knowledge_website_id FROM voice_agent_settings WHERE tenant_user_id = ? LIMIT 1', [$tenantId]);
        $id = (int) ($r[0]['knowledge_website_id'] ?? 0);
        return $id > 0 && $this->db()->query('SELECT id FROM websites WHERE id = ? AND user_id = ? LIMIT 1', [$id, $tenantId]) ? $id : 0;
    }

    public function knowledgeContext(int $tenantId, string $lang, string $query): string
    {
        $wid = $this->knowledgeWebsite($tenantId);
        if ($wid < 1) {
            return '';
        }
        try {
            return mb_substr((new KnowledgeBaseService())->buildContextForPrompt($wid, null, mb_substr($query, 0, 300), 8), 0, 3500);
        } catch (Throwable $e) {
            return ''; // a broken knowledge base must not stop the call
        }
    }

    /** Business-hours rows of the company's knowledge base. @return list<array> */
    public function businessHoursRows(int $tenantId): array
    {
        $wid = $this->knowledgeWebsite($tenantId);
        if ($wid < 1) {
            return [];
        }
        try {
            return array_values(array_filter((new AiKnowledgeBase())->activeFor($wid), static fn ($r) => ($r['section'] ?? '') === 'business_hours'));
        } catch (Throwable $e) {
            return [];
        }
    }
}

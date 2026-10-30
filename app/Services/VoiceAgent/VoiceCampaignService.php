<?php

/**
 * Tourfecto - AI Voice Agent: call lists (campaigns). A campaign fixes the purpose, number, assistant, attempt count and retry gap;
 * recipients are added in batches and every one goes through the same compliance checks as a single job, so a list can never
 * dial someone a single call could not. Pause/cancel take effect before the next dial.
 */
class VoiceCampaignService
{
    public const MAX_BATCH = 500;

    private VoiceOutboundService $jobs;

    public function __construct(?VoiceOutboundService $jobs = null)
    {
        $this->jobs = $jobs ?? new VoiceOutboundService();
    }

    private function db(): Database
    {
        return Database::getInstance();
    }

    /**
     * Pure: clean a pasted/imported list. One entry per person (same last 9 digits), at most MAX_BATCH.
     * @return array{valid:list<array>, rejected:list<array{input:string, reason:string}>}
     */
    public static function normalizeRecipients(array $rows): array
    {
        $valid = [];
        $rejected = [];
        $seen = [];
        foreach ($rows as $i => $r) {
            $raw = is_array($r) ? (string) ($r['to'] ?? $r['phone'] ?? '') : (string) $r;
            if (count($valid) >= self::MAX_BATCH) {
                $rejected[] = ['input' => $raw, 'reason' => 'batch_limit_' . self::MAX_BATCH];
                continue;
            }
            try {
                $e164 = VoiceNumberRules::normalizeE164($raw);
                $key = VoiceConsentService::key($e164);
            } catch (InvalidArgumentException $e) {
                $rejected[] = ['input' => mb_substr($raw, 0, 40), 'reason' => 'invalid_number'];
                continue;
            }
            if (isset($seen[$key])) {
                $rejected[] = ['input' => $e164, 'reason' => 'duplicate_in_list'];
                continue;
            }
            $seen[$key] = true;
            $valid[] = ['to' => $e164] + (is_array($r) ? array_intersect_key($r, ['contact_id' => 1, 'country' => 1, 'tz' => 1, 'context' => 1, 'scheduled_at' => 1]) : []);
        }
        return ['valid' => $valid, 'rejected' => $rejected];
    }

    /** Pure: roll the job rows of a campaign up into the numbers a manager reads. */
    public static function summarise(array $rows): array
    {
        $by = ['scheduled' => 0, 'dialing' => 0, 'completed' => 0, 'blocked' => 0, 'exhausted' => 0, 'cancelled' => 0];
        $results = [];
        $attempts = 0;
        foreach ($rows as $r) {
            $by[$r['status']] = ($by[$r['status']] ?? 0) + (int) $r['n'];
            if (!empty($r['last_result'])) {
                $results[$r['last_result']] = ($results[$r['last_result']] ?? 0) + (int) $r['n'];
            }
            $attempts += (int) $r['attempts'];
        }
        $total = array_sum($by);
        $answered = $results['answered'] ?? 0;
        $done = $by['completed'] + $by['exhausted'] + $by['blocked'];
        return ['total' => $total, 'by_status' => $by, 'by_result' => $results, 'attempts' => $attempts, 'answer_rate' => $done > 0 ? round($answered / $done, 3) : null];
    }

    public function create(int $tenantId, int $actorUserId, array $in): int
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Campaign name is required (max 120 chars)');
        }
        $purpose = (string) ($in['purpose'] ?? '');
        if (VoiceCompliance::category($purpose) === null) {
            throw new InvalidArgumentException('Unknown call purpose');
        }
        if (!$this->db()->query("SELECT id FROM voice_numbers WHERE id = ? AND tenant_user_id = ? AND status IN ('active','simulated') AND provider IS NOT NULL LIMIT 1", [(int) ($in['number_id'] ?? 0), $tenantId])) {
            throw new InvalidArgumentException('Choose one of your active provider numbers as caller ID');
        }
        if (!$this->db()->query("SELECT id FROM voice_agents WHERE id = ? AND tenant_user_id = ? AND status = 'active' LIMIT 1", [(int) ($in['agent_id'] ?? 0), $tenantId])) {
            throw new InvalidArgumentException('Choose an active assistant');
        }
        $tz = isset($in['tz']) && $in['tz'] !== '' ? (string) $in['tz'] : null;
        if ($tz !== null && !in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('Unknown time zone');
        }
        $gap = isset($in['min_gap_hours']) && $in['min_gap_hours'] !== '' ? max(1, min(48, (int) $in['min_gap_hours'])) : null;
        $this->db()->exec(
            "INSERT INTO voice_campaigns (tenant_user_id, name, purpose, number_id, agent_id, status, max_attempts, min_gap_hours, tz, approve_live, created_by_user_id, created_at, updated_at) VALUES (?,?,?,?,?,'active',?,?,?,?,?,NOW(),NOW())",
            [$tenantId, $name, $purpose, (int) $in['number_id'], (int) $in['agent_id'], max(1, min(5, (int) ($in['max_attempts'] ?? 3))), $gap, $tz, !empty($in['approve_live']) && filter_var($in['approve_live'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0, $actorUserId]
        );
        $id = (int) $this->db()->getConnection()->lastInsertId();
        VoiceAuditService::record($tenantId, $actorUserId, 'campaign.created', 'campaign', $id, ['purpose' => $purpose]);
        return $id;
    }

    private function campaign(int $tenantId, int $id): array
    {
        $r = $this->db()->query('SELECT * FROM voice_campaigns WHERE id = ? AND tenant_user_id = ? LIMIT 1', [$id, $tenantId]);
        if (!$r) {
            throw new InvalidArgumentException('Campaign not found');
        }
        return $r[0];
    }

    /** @return array{added:int, rejected:list<array>} each rejected entry says why (compliance reason or bad number) */
    public function addRecipients(int $tenantId, int $actorUserId, int $campaignId, array $rows): array
    {
        $c = $this->campaign($tenantId, $campaignId);
        if ($c['status'] === 'cancelled') {
            throw new InvalidArgumentException('This campaign was cancelled');
        }
        $clean = self::normalizeRecipients($rows);
        $rejected = $clean['rejected'];
        $added = 0;
        foreach ($clean['valid'] as $r) {
            try {
                $this->jobs->enqueue($tenantId, $actorUserId, $r + [
                    'purpose' => $c['purpose'], 'number_id' => (int) $c['number_id'], 'agent_id' => (int) $c['agent_id'], 'max_attempts' => (int) $c['max_attempts'],
                    'min_gap_hours' => $c['min_gap_hours'], 'tz' => $r['tz'] ?? $c['tz'], 'approve_live' => (bool) $c['approve_live'], 'campaign_id' => $campaignId,
                ]);
                $added++;
            } catch (InvalidArgumentException $e) {
                $rejected[] = ['input' => $r['to'], 'reason' => $e->getMessage()];
            }
        }
        VoiceAuditService::record($tenantId, $actorUserId, 'campaign.recipients_added', 'campaign', $campaignId, ['added' => $added, 'rejected' => count($rejected)]);
        return ['added' => $added, 'rejected' => $rejected];
    }

    public function setStatus(int $tenantId, int $actorUserId, int $campaignId, string $action): array
    {
        $this->campaign($tenantId, $campaignId);
        $map = ['pause' => 'paused', 'resume' => 'active', 'cancel' => 'cancelled'];
        if (!isset($map[$action])) {
            throw new InvalidArgumentException('Action must be pause, resume or cancel');
        }
        $st = $this->db()->getConnection()->prepare("UPDATE voice_campaigns SET status = ?, updated_at = NOW() WHERE id = ? AND tenant_user_id = ? AND status <> 'cancelled'");
        $st->execute([$map[$action], $campaignId, $tenantId]);
        if ($action === 'cancel') {
            $this->db()->exec("UPDATE voice_outbound_jobs SET status='cancelled', blocked_reason='campaign_cancelled', updated_at=NOW() WHERE campaign_id = ? AND tenant_user_id = ? AND status = 'scheduled'", [$campaignId, $tenantId]);
        }
        VoiceAuditService::record($tenantId, $actorUserId, 'campaign.' . $action, 'campaign', $campaignId);
        return ['status' => $map[$action]];
    }

    public function stats(int $tenantId, int $campaignId): array
    {
        $c = $this->campaign($tenantId, $campaignId);
        $rows = $this->db()->query('SELECT status, last_result, COUNT(*) AS n, SUM(attempts) AS attempts FROM voice_outbound_jobs WHERE campaign_id = ? AND tenant_user_id = ? GROUP BY status, last_result', [$campaignId, $tenantId]) ?: [];
        return ['campaign' => ['id' => (int) $c['id'], 'name' => $c['name'], 'purpose' => $c['purpose'], 'status' => $c['status']]] + self::summarise($rows);
    }

    public function list(int $tenantId): array
    {
        return $this->db()->query('SELECT id, name, purpose, number_id, agent_id, status, max_attempts, min_gap_hours, created_at FROM voice_campaigns WHERE tenant_user_id = ? ORDER BY id DESC LIMIT 100', [$tenantId]) ?: [];
    }
}

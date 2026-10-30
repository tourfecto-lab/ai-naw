<?php

/**
 * Tourfecto - AI Voice Agent: data retention and erasure. Transcripts and call personal data are removed after the company's retention period,
 * or on request for one phone number. The do-not-call list is NEVER erased (it has to outlive the data to keep protecting the person).
 */
class VoiceRetentionService
{
    private function db(): Database
    {
        return Database::getInstance();
    }

    /** Remove transcript text and personal fields of old calls; the call row stays for statistics and billing. @return int calls cleaned */
    public function purgeExpired(int $tenantId, array $settings): int
    {
        $days = max(1, (int) ($settings['transcript_retention_days'] ?? 90));
        $ids = array_column($this->db()->query("SELECT id FROM voice_calls WHERE tenant_user_id = ? AND started_at < NOW() - INTERVAL $days DAY AND (summary IS NOT NULL OR from_e164 IS NOT NULL) LIMIT 500", [$tenantId]) ?: [], 'id');
        foreach ($ids as $id) {
            $this->scrub($tenantId, (int) $id);
        }
        return count($ids);
    }

    /** Erase everything stored about one phone number for one company, except the do-not-call entry. @return array{calls:int, consents:int} */
    public function erasePhone(int $tenantId, string $phone): array
    {
        $key = VoiceDataStore::phoneKey($phone);
        if (strlen($key) < 7) {
            throw new InvalidArgumentException('Enter the full phone number');
        }
        $calls = $this->db()->query('SELECT id FROM voice_calls WHERE tenant_user_id = ? AND (RIGHT(REPLACE(from_e164,"+",""),9) = ? OR RIGHT(REPLACE(to_e164,"+",""),9) = ?)', [$tenantId, $key, $key]) ?: [];
        foreach ($calls as $c) {
            $this->scrub($tenantId, (int) $c['id']);
        }
        $this->db()->exec('DELETE FROM voice_consents WHERE tenant_user_id = ? AND phone_key = ?', [$tenantId, $key]);
        VoiceAuditService::record($tenantId, null, 'privacy.erase_phone', 'phone', substr($key, -4), ['calls' => count($calls)]);
        return ['calls' => count($calls), 'consents' => 1];
    }

    private function scrub(int $tenantId, int $callId): void
    {
        $this->db()->exec('DELETE FROM voice_call_turns WHERE tenant_user_id = ? AND call_id = ?', [$tenantId, $callId]);
        $this->db()->exec("UPDATE voice_calls SET from_e164 = NULL, to_e164 = NULL, summary = NULL, qualification = NULL, booking_request = NULL, needs = NULL, send_requests = NULL WHERE id = ? AND tenant_user_id = ?", [$callId, $tenantId]);
        $this->db()->exec("UPDATE voice_recordings SET retention_until = NOW() - INTERVAL 1 SECOND, updated_at = NOW() WHERE call_id = ? AND tenant_user_id = ? AND status <> 'deleted'", [$callId, $tenantId]);
    }
}

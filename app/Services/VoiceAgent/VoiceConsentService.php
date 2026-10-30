<?php

/**
 * Tourfecto - AI Voice Agent: marketing consent and do-not-call. Tenant-scoped, plus a platform-wide DNC (tenant 0).
 * Numbers are matched by their last 9 digits so +20 / 0020 / 010 spellings of the same line are one person.
 */
class VoiceConsentService
{
    public const SOURCES = ['web_form', 'booking_form', 'verbal_recorded', 'written', 'import_with_proof', 'manual_with_evidence'];

    private function db(): Database
    {
        return Database::getInstance();
    }

    public static function key(string $phone): string
    {
        $d = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($d) < 7) {
            throw new InvalidArgumentException('Invalid phone number');
        }
        return substr($d, -9);
    }

    public function grantMarketing(int $tenantId, int $actorUserId, string $e164, string $source, string $evidence, ?int $contactId = null): void
    {
        $e164 = VoiceNumberRules::normalizeE164($e164);
        if (!in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException('Unknown consent source');
        }
        $evidence = trim($evidence);
        if (mb_strlen($evidence) < 8) {
            throw new InvalidArgumentException('Describe how and when consent was obtained (form, recording or document reference)');
        }
        $key = self::key($e164);
        if ($this->isDnc($tenantId, $e164)) {
            throw new InvalidArgumentException('This number is on the do-not-call list. Remove it from the list first, and only if the person asked for that');
        }
        $this->db()->exec(
            "INSERT INTO voice_consents (tenant_user_id, phone_key, e164, contact_id, purpose, status, source, evidence, granted_by_user_id, granted_at, updated_at)
             VALUES (?,?,?,?, 'marketing','granted',?,?,?,NOW(),NOW())
             ON DUPLICATE KEY UPDATE status='granted', e164=VALUES(e164), source=VALUES(source), evidence=VALUES(evidence), granted_by_user_id=VALUES(granted_by_user_id), granted_at=NOW(), withdrawn_at=NULL, updated_at=NOW()",
            [$tenantId, $key, $e164, $contactId, $source, mb_substr($evidence, 0, 500), $actorUserId]
        );
        VoiceAuditService::record($tenantId, $actorUserId, 'consent.granted', 'consent', $key, ['source' => $source]);
    }

    public function withdraw(int $tenantId, ?int $actorUserId, string $e164): void
    {
        $key = self::key($e164);
        $this->db()->exec("UPDATE voice_consents SET status='withdrawn', withdrawn_at=NOW(), updated_at=NOW() WHERE tenant_user_id=? AND phone_key=?", [$tenantId, $key]);
        VoiceAuditService::record($tenantId, $actorUserId, 'consent.withdrawn', 'consent', $key);
    }

    public function hasMarketingConsent(int $tenantId, string $e164): bool
    {
        return (bool) $this->db()->query("SELECT id FROM voice_consents WHERE tenant_user_id=? AND phone_key=? AND purpose='marketing' AND status='granted' LIMIT 1", [$tenantId, self::key($e164)]);
    }

    public function isDnc(int $tenantId, string $e164): bool
    {
        return (bool) $this->db()->query('SELECT id FROM voice_dnc WHERE tenant_user_id IN (0, ?) AND phone_key=? LIMIT 1', [$tenantId, self::key($e164)]);
    }

    public function addDnc(int $tenantId, ?int $actorUserId, string $e164, string $source = 'manual', ?string $reason = null): void
    {
        $e164 = VoiceNumberRules::normalizeE164($e164);
        $key = self::key($e164);
        $this->db()->exec(
            'INSERT IGNORE INTO voice_dnc (tenant_user_id, phone_key, e164, reason, source, created_by_user_id, created_at) VALUES (?,?,?,?,?,?,NOW())',
            [$tenantId, $key, $e164, $reason !== null ? mb_substr($reason, 0, 120) : null, $source, $actorUserId]
        );
        $this->withdraw($tenantId, $actorUserId, $e164);
        $this->cancelPending($tenantId, $key, 'do_not_call_list');
        VoiceAuditService::record($tenantId, $actorUserId, 'dnc.added', 'dnc', $key, ['source' => $source]);
    }

    public function removeDnc(int $tenantId, int $actorUserId, string $e164): void
    {
        $key = self::key($e164);
        $this->db()->exec('DELETE FROM voice_dnc WHERE tenant_user_id=? AND phone_key=?', [$tenantId, $key]); // never removes platform-wide (tenant 0) entries
        VoiceAuditService::record($tenantId, $actorUserId, 'dnc.removed', 'dnc', $key);
    }

    public function listDnc(int $tenantId, int $limit = 200): array
    {
        return $this->db()->query('SELECT e164, reason, source, created_at FROM voice_dnc WHERE tenant_user_id=? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)), [$tenantId]) ?: [];
    }

    /** The person said stop: DNC, withdraw consent, cancel everything pending for that number. */
    public function optOut(int $tenantId, string $e164, ?int $callId = null): void
    {
        $this->addDnc($tenantId, null, $e164, 'opt_out_call', $callId ? 'opted out on call #' . $callId : 'opted out on a call');
    }

    private function cancelPending(int $tenantId, string $key, string $reason): void
    {
        $this->db()->exec("UPDATE voice_outbound_jobs SET status='cancelled', blocked_reason=?, updated_at=NOW() WHERE tenant_user_id=? AND phone_key=? AND status IN ('scheduled','dialing')", [$reason, $tenantId, $key]);
    }
}

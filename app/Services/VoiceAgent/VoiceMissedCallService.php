<?php

/**
 * Tourfecto - AI Voice Agent: missed call recovery. Creates (or reuses) the CRM contact and a follow-up task for the account owner.
 * One task per caller per hour, so a caller who keeps redialling does not flood the team.
 */
class VoiceMissedCallService
{
    private VoiceDataStore $store;

    public function __construct(?VoiceDataStore $store = null)
    {
        $this->store = $store ?? new VoiceDataStore();
    }

    public function record(int $tenantId, string $fromE164, string $reason): bool
    {
        if ($fromE164 === '' || $this->store->hasRecentMissed($tenantId, $fromE164)) {
            return false;
        }
        try {
            $ctx = $this->store->customerContext($tenantId, $fromE164);
            $contactId = $ctx['contact_id'];
            if (!$ctx['known']) {
                $key = VoiceDataStore::phoneKey($fromE164);
                $dup = Database::getInstance()->query(
                    "SELECT id FROM crm_contacts WHERE user_id = ? AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'(',''),')',''),'+','') LIKE ? LIMIT 1",
                    [$tenantId, '%' . $key]
                );
                $contactId = $dup ? (int) $dup[0]['id'] : (int) (new CrmContactService())->create($tenantId, ['name' => 'Caller ' . $fromE164, 'phone' => $fromE164, 'source' => 'voice_missed_call'])->getAttribute('id');
            }
            VoiceCrmCompat::createTask($tenantId, [
                'title' => 'Return missed call from ' . $fromE164,
                'description' => 'The AI voice assistant could not take this call (' . $reason . '). Call the customer back.',
                'related_type' => 'contact', 'related_id' => $contactId,
                'due_date' => date('Y-m-d', strtotime('+1 day')), 'priority' => 'high',
            ]);
            return true;
        } catch (Throwable $e) {
            Logger::error('VoiceMissedCallService failed', ['tenant' => $tenantId, 'error' => $e->getMessage()]);
            return false;
        }
    }
}

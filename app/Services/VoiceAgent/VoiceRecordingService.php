<?php

/**
 * Tourfecto - AI Voice Agent: call recording. Whether to record is decided ONCE by VoiceRecordingPolicy (company opted in AND the platform enabled the
 * caller's country), and the same decision drives both the spoken notice and the recording, so a call is never recorded without being announced.
 * Starting a recording is a guarded operation (simulated unless the platform and company are live). Audio is served only through an authenticated
 * endpoint of ours; provider URLs are never given to customers.
 */
class VoiceRecordingService
{
    private VoiceProviderService $providers;

    public function __construct(?VoiceProviderService $providers = null)
    {
        $this->providers = $providers ?? new VoiceProviderService();
    }

    public static function platformCountries(): array
    {
        return VoiceRecordingPolicy::countries((new SystemSettingsService())->get('voice_recording_countries', ''));
    }

    /** @return array{record:bool, reason:string} for a remote party's number */
    public static function decide(string $remoteE164, array $settings): array
    {
        return VoiceRecordingPolicy::decide(VoiceMarkets::countryOf($remoteE164), $settings, self::platformCountries());
    }

    /** Never throws into the call flow: a recording problem must not drop a customer's call. @return string started|simulated|skipped|failed */
    public function start(int $tenantId, int $callId, string $provider, string $providerCallSid, array $settings): string
    {
        $db = Database::getInstance();
        try {
            $res = $this->providers->guarded($tenantId, 0, $provider, 'start_recording', ['call' => $callId], true, fn (VoiceProviderAdapter $a) => $a->startRecording($providerCallSid));
            if (!empty($res['simulated'])) {
                return 'simulated';
            }
            $db->exec(
                "INSERT IGNORE INTO voice_recordings (tenant_user_id, call_id, provider, recording_sid, status, retention_until, created_at, updated_at) VALUES (?,?,?,?, 'requested', ?, NOW(), NOW())",
                [$tenantId, $callId, $provider, $res['recording_id'], VoiceRecordingPolicy::retentionUntil((int) ($settings['recording_retention_days'] ?? 30))]
            );
            return 'started';
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('VoiceRecordingService: start failed', ['error' => $e->getMessage()]);
            }
            return 'failed';
        }
    }

    /** @return array{content_type:string, body:string}|null null when there is no available recording for THIS company's call */
    public function audio(int $tenantId, int $userId, int $callId): ?array
    {
        $r = Database::getInstance()->query("SELECT provider, recording_sid FROM voice_recordings WHERE tenant_user_id = ? AND call_id = ? AND status IN ('requested','available') AND (retention_until IS NULL OR retention_until > NOW()) ORDER BY id DESC LIMIT 1", [$tenantId, $callId])[0] ?? null;
        if (!$r) {
            return null;
        }
        try {
            $audio = $this->providers->adapter($tenantId, (string) $r['provider'])->fetchRecording((string) $r['recording_sid']);
        } catch (VoiceProviderException $e) {
            VoiceProviderErrorLog::record($tenantId, (string) $r['provider'], 'fetch_recording', $e);
            return null;
        }
        VoiceAuditService::record($tenantId, $userId, 'recording.played', 'call', (string) $callId);
        return $audio;
    }

    /** Delete expired recordings at the provider and mark them deleted. @return int deleted */
    public function purgeExpired(int $tenantId): int
    {
        $n = 0;
        foreach (Database::getInstance()->query("SELECT id, provider, recording_sid FROM voice_recordings WHERE tenant_user_id = ? AND status IN ('requested','available') AND retention_until < NOW() LIMIT 200", [$tenantId]) ?: [] as $r) {
            try {
                if ($this->providers->adapter($tenantId, (string) $r['provider'])->deleteRecording((string) $r['recording_sid'])) {
                    Database::getInstance()->exec("UPDATE voice_recordings SET status='deleted', updated_at=NOW() WHERE id=? AND tenant_user_id=?", [$r['id'], $tenantId]);
                    $n++;
                }
            } catch (VoiceProviderException $e) {
                VoiceProviderErrorLog::record($tenantId, (string) $r['provider'], 'delete_recording', $e); // retried on the next run
            }
        }
        return $n;
    }
}

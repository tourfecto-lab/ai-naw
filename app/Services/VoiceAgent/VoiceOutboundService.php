<?php

/**
 * Tourfecto - AI Voice Agent: outbound calls (follow-ups, confirmations, reminders, surveys, authorised marketing).
 * A job is checked by VoiceCompliance when it is created and again right before it is dialled. Dialling is simulated unless
 * the platform flag, the tenant's live mode AND an explicit per-job approval are all present (VoiceLiveGuard).
 */
class VoiceOutboundService
{
    public const CONTEXT_KEYS = ['first_name' => 80, 'booking_ref' => 40, 'trip_name' => 120, 'trip_date' => 10, 'amount' => 20, 'currency' => 3, 'inquiry_at' => 10, 'callback_requested_at' => 10, 'survey_topic' => 120, 'offer_text' => 300];

    public const REASONS = [
        'unknown_purpose' => 'Unknown call purpose.',
        'do_not_call_list' => 'This number is on the do-not-call list.',
        'recipient_opted_out' => 'This person opted out of calls.',
        'no_booking_relationship' => 'No open booking was found for this number, so this call has no lawful basis.',
        'no_completed_booking' => 'No completed booking was found for this number.',
        'inquiry_too_old_or_unknown' => 'Follow-ups are only allowed within 30 days of the inquiry; provide inquiry_at.',
        'callback_request_too_old_or_unknown' => 'Callbacks are only allowed within 7 days of the request; provide callback_requested_at.',
        'marketing_not_enabled_for_country' => 'Marketing calls are not enabled for this country.',
        'no_marketing_consent' => 'No recorded marketing consent for this number.',
        'attempts_exhausted' => 'The maximum number of attempts was reached.',
        'timezone_unknown' => 'The recipient time zone is unknown; pass a valid tz.',
        'no_allowed_time_found' => 'No allowed calling time was found in the next week.',
    ];

    private VoiceProviderService $providers;
    private VoiceConsentService $consent;
    private VoiceDataStore $store;
    private string $baseUrl;

    public function __construct(?VoiceProviderService $providers = null, ?VoiceConsentService $consent = null, ?VoiceDataStore $store = null, ?string $baseUrl = null)
    {
        $this->providers = $providers ?? new VoiceProviderService();
        $this->consent = $consent ?? new VoiceConsentService();
        $this->store = $store ?? new VoiceDataStore();
        $this->baseUrl = rtrim($baseUrl ?? (defined('APP_URL') ? APP_URL : ''), '/');
    }

    private function db(): Database
    {
        return Database::getInstance();
    }

    /** Pure: keep only known context keys, trimmed and bounded; dates must be YYYY-MM-DD. */
    public static function sanitizeContext(array $in): array
    {
        $out = [];
        foreach (self::CONTEXT_KEYS as $k => $max) {
            if (!isset($in[$k]) || !is_scalar($in[$k])) {
                continue;
            }
            $v = mb_substr(trim(strip_tags((string) $in[$k])), 0, $max);
            if ($v === '' || (in_array($k, ['trip_date', 'inquiry_at', 'callback_requested_at'], true) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v))) {
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    private static function ageDays(?string $date, int $now): ?int
    {
        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        $t = strtotime($date . ' 00:00:00 UTC');
        return $t === false || $t > $now ? -1 : (int) floor(($now - $t) / 86400);
    }

    /** Facts VoiceCompliance needs, read fresh from the database. */
    public function facts(array $job, int $now): array
    {
        $t = (int) $job['tenant_user_id'];
        $key = (string) $job['phone_key'];
        $ctx = json_decode((string) ($job['context_json'] ?? ''), true) ?: [];
        $match = "(customer_id = ? OR RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(customer_phone,' ',''),'-',''),'+',''),'(',''),9) = ?)";
        $cid = (int) ($job['contact_id'] ?? 0);
        $has = (bool) $this->db()->query("SELECT id FROM bookings WHERE user_id = ? AND status IN ('pending','confirmed') AND $match LIMIT 1", [$t, $cid, $key]);
        $done = (bool) $this->db()->query("SELECT id FROM bookings WHERE user_id = ? AND status = 'completed' AND $match LIMIT 1", [$t, $cid, $key]);
        $last = $this->db()->query('SELECT MAX(created_at) AS m, SUM(created_at >= NOW() - INTERVAL 1 DAY) AS d, SUM(created_at >= NOW() - INTERVAL 7 DAY) AS w FROM voice_outbound_attempts WHERE tenant_user_id = ? AND phone_key = ? AND simulated = 0', [$t, $key])[0] ?? [];
        $enabled = array_filter(array_map('trim', explode(',', strtoupper((string) (new SystemSettingsService())->get('voice_marketing_countries', '')))));
        return [
            'dnc' => $this->consent->isDnc($t, (string) $job['to_e164']),
            'opted_out' => false, // an opt-out is stored as a DNC entry
            'marketing_consent' => $this->consent->hasMarketingConsent($t, (string) $job['to_e164']),
            'marketing_country_enabled' => in_array(strtoupper((string) $job['country']), $enabled, true),
            'has_booking' => $has,
            'completed_booking' => $done,
            'inquiry_age_days' => self::ageDays($ctx['inquiry_at'] ?? null, $now),
            'callback_age_days' => self::ageDays($ctx['callback_requested_at'] ?? null, $now),
            'calls_24h' => (int) ($last['d'] ?? 0),
            'calls_7d' => (int) ($last['w'] ?? 0),
            'last_attempt_ts' => !empty($last['m']) ? (int) strtotime($last['m']) : null,
        ];
    }

    /** @param array<string,mixed> $in purpose,to,number_id,agent_id,[contact_id,country,tz,scheduled_at,context,max_attempts,approve_live] */
    public function enqueue(int $tenantId, int $actorUserId, array $in, ?int $now = null): array
    {
        $now = $now ?? time();
        $purpose = (string) ($in['purpose'] ?? '');
        $category = VoiceCompliance::category($purpose);
        if ($category === null) {
            throw new InvalidArgumentException(self::REASONS['unknown_purpose']);
        }
        $to = VoiceNumberRules::normalizeE164((string) ($in['to'] ?? ''));
        $key = VoiceConsentService::key($to);
        $allowed = ((new VoiceSettingsService())->get($tenantId)['allowed_dial_countries'] ?? null);
        if (!VoiceFraudGuard::destinationAllowed($to, $allowed ? explode(',', (string) $allowed) : null)) {
            throw new InvalidArgumentException('Calls to this destination are not allowed for your account. Allowed countries can be changed in Settings.');
        }
        $number = $this->db()->query("SELECT * FROM voice_numbers WHERE id = ? AND tenant_user_id = ? AND status IN ('active','simulated') LIMIT 1", [(int) ($in['number_id'] ?? 0), $tenantId])[0] ?? null;
        if (!$number || empty($number['provider'])) {
            throw new InvalidArgumentException('Choose one of your active numbers that belongs to a provider account (caller ID)');
        }
        if (empty(VoiceProviderService::capabilitiesOf($number['provider'])['turn_mode'])) {
            throw new InvalidArgumentException('This number\'s provider cannot run AI conversations on outbound calls yet');
        }
        if (!$this->db()->query("SELECT id FROM voice_agents WHERE id = ? AND tenant_user_id = ? AND status = 'active' LIMIT 1", [(int) ($in['agent_id'] ?? 0), $tenantId])) {
            throw new InvalidArgumentException('Choose an active agent');
        }
        $contactId = isset($in['contact_id']) && $in['contact_id'] !== '' ? (int) $in['contact_id'] : null;
        if ($contactId !== null && !$this->db()->query('SELECT id FROM crm_contacts WHERE id = ? AND user_id = ? LIMIT 1', [$contactId, $tenantId])) {
            throw new InvalidArgumentException('Contact not found');
        }
        $iso = strtoupper(trim((string) ($in['country'] ?? ''))) ?: VoiceMarkets::countryOf($to);
        $tz = isset($in['tz']) && $in['tz'] !== '' ? (string) $in['tz'] : null;
        if ($tz !== null && !VoiceTimeWindows::zonesFor($iso, $tz)) {
            throw new InvalidArgumentException('Unknown time zone');
        }
        $ctx = self::sanitizeContext((array) ($in['context'] ?? []));
        $max = max(1, min(5, (int) ($in['max_attempts'] ?? 3)));
        $gapHours = isset($in['min_gap_hours']) && $in['min_gap_hours'] !== '' ? max(1, min(48, (int) $in['min_gap_hours'])) : null;
        $campaignId = isset($in['campaign_id']) ? (int) $in['campaign_id'] : null;
        $sourceRef = isset($in['source_ref']) && preg_match('/^[a-z0-9:_\-]{1,60}$/', (string) $in['source_ref']) ? (string) $in['source_ref'] : null;
        if ($sourceRef !== null && ($dup = $this->db()->query('SELECT id, status, next_attempt_at FROM voice_outbound_jobs WHERE tenant_user_id = ? AND source_ref = ? LIMIT 1', [$tenantId, $sourceRef]))) {
            return ['job_id' => (int) $dup[0]['id'], 'status' => $dup[0]['status'], 'next_attempt_at' => date('c', strtotime($dup[0]['next_attempt_at'])), 'waiting_reason' => null, 'existing' => true]; // one job per source, even if asked twice
        }
        $at = $now;
        if (!empty($in['scheduled_at'])) {
            $req = strtotime((string) $in['scheduled_at']);
            if ($req === false) {
                throw new InvalidArgumentException('Invalid scheduled_at');
            }
            $at = max($now, $req);
        }
        $job = ['tenant_user_id' => $tenantId, 'to_e164' => $to, 'phone_key' => $key, 'contact_id' => $contactId, 'country' => $iso, 'tz' => $tz, 'purpose' => $purpose, 'attempts' => 0, 'max_attempts' => $max, 'min_gap_hours' => $gapHours, 'context_json' => json_encode($ctx)];
        $d = VoiceCompliance::evaluate($job, $this->facts($job, $now), $at);
        if ($d['action'] === 'block') {
            throw new InvalidArgumentException(self::REASONS[$d['reason']] ?? $d['reason']);
        }
        $first = $d['action'] === 'wait' ? (int) $d['retry_at'] : $at;
        $this->db()->exec(
            "INSERT INTO voice_outbound_jobs (tenant_user_id, number_id, agent_id, contact_id, to_e164, phone_key, country, tz, purpose, category, context_json, status, max_attempts, min_gap_hours, campaign_id, source_ref, next_attempt_at, approved_live, created_by_user_id, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,'scheduled',?,?,?,?,?,?,?,NOW(),NOW())",
            [$tenantId, $number['id'], (int) $in['agent_id'], $contactId, $to, $key, $iso, $tz, $purpose, $category, json_encode($ctx), $max, $gapHours, $campaignId, $sourceRef, date('Y-m-d H:i:s', $first), !empty($in['approve_live']) && filter_var($in['approve_live'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0, $actorUserId]
        );
        $id = (int) $this->db()->getConnection()->lastInsertId();
        VoiceAuditService::record($tenantId, $actorUserId, 'outbound.queued', 'job', $id, ['purpose' => $purpose, 'basis_attested' => array_intersect_key($ctx, ['inquiry_at' => 1, 'callback_requested_at' => 1])]);
        return ['job_id' => $id, 'status' => 'scheduled', 'next_attempt_at' => date('c', $first), 'waiting_reason' => $d['action'] === 'wait' ? $d['reason'] : null];
    }

    public function cancel(int $tenantId, int $actorUserId, int $jobId): bool
    {
        $st = $this->db()->getConnection()->prepare("UPDATE voice_outbound_jobs SET status='cancelled', updated_at=NOW() WHERE id=? AND tenant_user_id=? AND status IN ('scheduled')");
        $st->execute([$jobId, $tenantId]);
        if ($st->rowCount() === 1) {
            VoiceAuditService::record($tenantId, $actorUserId, 'outbound.cancelled', 'job', $jobId);
            return true;
        }
        return false;
    }

    public function listJobs(int $tenantId, int $limit = 100): array
    {
        return $this->db()->query(
            'SELECT id, number_id, agent_id, contact_id, to_e164, country, tz, purpose, category, status, blocked_reason, attempts, max_attempts, next_attempt_at, last_attempt_at, last_result, approved_live, created_at
             FROM voice_outbound_jobs WHERE tenant_user_id = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)),
            [$tenantId]
        ) ?: [];
    }

    // ------------------------------------------------------------------ dialling (cron)

    /** @return array{processed:int, dialed:int, simulated:int, waiting:int, blocked:int} */
    public function runDue(int $limit = 10, ?int $now = null): array
    {
        $now = $now ?? time();
        $sum = ['processed' => 0, 'dialed' => 0, 'simulated' => 0, 'waiting' => 0, 'blocked' => 0];
        foreach ($this->db()->query("SELECT * FROM voice_outbound_jobs WHERE status = 'dialing' AND updated_at < NOW() - INTERVAL 30 MINUTE LIMIT 50") ?: [] as $stuck) {
            $this->applyResult($stuck, 'failed', $now);
        }
        $rows = $this->db()->query("SELECT * FROM voice_outbound_jobs WHERE status = 'scheduled' AND next_attempt_at <= NOW() ORDER BY next_attempt_at LIMIT " . max(1, min(50, $limit))) ?: [];
        foreach ($rows as $job) {
            $st = $this->db()->getConnection()->prepare("UPDATE voice_outbound_jobs SET status='dialing', updated_at=NOW() WHERE id=? AND tenant_user_id=? AND status='scheduled'");
            $st->execute([$job['id'], $job['tenant_user_id']]);
            if ($st->rowCount() !== 1) {
                continue; // another worker took it
            }
            $sum['processed']++;
            try {
                $r = $this->process($job, $now);
            } catch (Throwable $e) {
                Logger::error('Voice outbound job failed', ['job' => $job['id'], 'error' => $e->getMessage()]);
                $this->db()->exec("UPDATE voice_outbound_jobs SET status='scheduled', next_attempt_at = NOW() + INTERVAL 30 MINUTE, updated_at=NOW() WHERE id=? AND tenant_user_id=?", [$job['id'], $job['tenant_user_id']]);
                $r = 'waiting';
            }
            $sum[$r]++;
        }
        return $sum;
    }

    private function block(array $job, string $reason): string
    {
        $this->db()->exec("UPDATE voice_outbound_jobs SET status='blocked', blocked_reason=?, updated_at=NOW() WHERE id=? AND tenant_user_id=?", [$reason, $job['id'], $job['tenant_user_id']]);
        VoiceAuditService::record((int) $job['tenant_user_id'], null, 'outbound.blocked', 'job', $job['id'], ['reason' => $reason]);
        return 'blocked';
    }

    /** @return string one of dialed|simulated|waiting|blocked */
    private function process(array $job, int $now): string
    {
        $t = (int) $job['tenant_user_id'];
        $settings = (new VoiceSettingsService())->get($t);
        if (empty($settings['enabled']) || empty($settings['outbound_enabled'])) {
            return $this->block($job, 'outbound_disabled');
        }
        $number = $this->db()->query("SELECT * FROM voice_numbers WHERE id = ? AND tenant_user_id = ? AND status IN ('active','simulated') LIMIT 1", [$job['number_id'], $t])[0] ?? null;
        $agent = $this->db()->query("SELECT * FROM voice_agents WHERE id = ? AND tenant_user_id = ? AND status = 'active' LIMIT 1", [$job['agent_id'], $t])[0] ?? null;
        if (!$number || !$agent || empty($number['provider'])) {
            return $this->block($job, 'number_or_agent_unavailable');
        }
        if (($why = (new VoiceBudgetService())->blockReason($t, $settings)) !== null) {
            return $this->block($job, $why);
        }
        if (!empty($job['campaign_id'])) {
            $c = $this->db()->query('SELECT status FROM voice_campaigns WHERE id = ? AND tenant_user_id = ? LIMIT 1', [$job['campaign_id'], $t])[0]['status'] ?? 'cancelled';
            if ($c === 'cancelled') {
                $this->db()->exec("UPDATE voice_outbound_jobs SET status='cancelled', blocked_reason='campaign_cancelled', updated_at=NOW() WHERE id=? AND tenant_user_id=?", [$job['id'], $t]);
                return 'blocked';
            }
            if ($c === 'paused') { // wait without spending an attempt
                $this->db()->exec("UPDATE voice_outbound_jobs SET status='scheduled', next_attempt_at = NOW() + INTERVAL 1 HOUR, updated_at=NOW() WHERE id=? AND tenant_user_id=?", [$job['id'], $t]);
                return 'waiting';
            }
        }
        $recent = (int) ($this->db()->query('SELECT COUNT(*) AS n FROM voice_outbound_attempts WHERE tenant_user_id = ? AND simulated = 0 AND created_at >= NOW() - INTERVAL 10 MINUTE', [$t])[0]['n'] ?? 0);
        if (VoiceFraudGuard::dialRateExceeded($recent)) {
            $this->db()->exec("UPDATE voice_outbound_jobs SET status='scheduled', next_attempt_at = NOW() + INTERVAL 10 MINUTE, updated_at=NOW() WHERE id=? AND tenant_user_id=?", [$job['id'], $t]);
            return 'waiting'; // burst limit against runaway loops and toll fraud
        }
        $real24 = (int) ($this->db()->query('SELECT COUNT(*) AS n FROM voice_outbound_attempts WHERE tenant_user_id = ? AND simulated = 0 AND created_at >= NOW() - INTERVAL 1 DAY', [$t])[0]['n'] ?? 0);
        if (VoiceCapacity::dailyCapReached($real24, (int) ($settings['daily_outbound_cap'] ?? 100))) {
            $this->db()->exec("UPDATE voice_outbound_jobs SET status='scheduled', next_attempt_at = NOW() + INTERVAL 1 HOUR, updated_at=NOW() WHERE id=? AND tenant_user_id=?", [$job['id'], $t]);
            return 'waiting'; // the company's daily limit: try again later, nothing is lost
        }
        $d = VoiceCompliance::evaluate($job, $this->facts($job, $now), $now);
        if ($d['action'] === 'block') {
            return $this->block($job, $d['reason']);
        }
        if ($d['action'] === 'wait') {
            $this->db()->exec("UPDATE voice_outbound_jobs SET status='scheduled', next_attempt_at=?, updated_at=NOW() WHERE id=? AND tenant_user_id=?", [date('Y-m-d H:i:s', (int) $d['retry_at']), $job['id'], $t]);
            return 'waiting';
        }
        $approved = (bool) $job['approved_live'];
        if ($this->providers->wouldGoLive($t, 'start_call', $approved) && !VoiceNumberRules::liveUsable($number)) {
            return $this->block($job, 'from_number_not_live');
        }
        $urls = VoiceInboundService::webhookUrls($this->baseUrl, (string) $number['provider'], $t);
        try {
            $res = $this->providers->guarded(
                $t, (int) $job['created_by_user_id'], (string) $number['provider'], 'start_call', ['to' => $job['to_e164'], 'purpose' => 'outbound:' . $job['purpose']], $approved,
                fn (VoiceProviderAdapter $a) => $a->startCall($number['e164'], $job['to_e164'], [
                    'url' => $urls['outbound'] . '?job=' . (int) $job['id'], 'status_callback' => $urls['status'],
                    'machine_detection' => $job['category'] === 'marketing' ? true : 'message_end', 'record' => !empty($settings['recording_enabled']),
                ])
            );
        } catch (VoiceProviderException $e) {
            // the provider refused the call: it counts as an attempt, then the normal retry rules apply
            $this->db()->exec('INSERT INTO voice_outbound_attempts (tenant_user_id, job_id, phone_key, simulated, result, created_at) VALUES (?,?,?,0,\'failed\',NOW())', [$t, $job['id'], $job['phone_key']]);
            $this->db()->exec("UPDATE voice_outbound_jobs SET attempts=attempts+1, last_attempt_at=NOW(), updated_at=NOW() WHERE id=? AND tenant_user_id=?", [$job['id'], $t]);
            $this->applyResult($job, 'failed', $now);
            return 'waiting';
        }
        $simulated = !empty($res['simulated']);
        $this->db()->exec('INSERT INTO voice_outbound_attempts (tenant_user_id, job_id, phone_key, simulated, result, created_at) VALUES (?,?,?,?,?,NOW())', [$t, $job['id'], $job['phone_key'], $simulated ? 1 : 0, $simulated ? 'simulated' : 'dialing']);
        if ($simulated) {
            $this->db()->exec("UPDATE voice_outbound_jobs SET status='completed', attempts=attempts+1, last_attempt_at=NOW(), last_result='simulated', blocked_reason=?, updated_at=NOW() WHERE id=? AND tenant_user_id=?", [$res['reason'] ?? null, $job['id'], $t]);
            return 'simulated';
        }
        $this->db()->exec("UPDATE voice_outbound_jobs SET attempts=attempts+1, last_attempt_at=NOW(), last_call_sid=?, updated_at=NOW() WHERE id=? AND tenant_user_id=?", [$res['call_id'] ?? null, $job['id'], $t]);
        return 'dialed';
    }

    /** After an attempt: finish the job or schedule the retry (which the compliance engine re-checks when it comes due). */
    public function applyResult(array $job, string $result, ?int $now = null): void
    {
        $now = $now ?? time();
        $job = $this->db()->query('SELECT * FROM voice_outbound_jobs WHERE id = ? AND tenant_user_id = ? LIMIT 1', [$job['id'], $job['tenant_user_id']])[0] ?? $job;
        $delay = VoiceCompliance::retryDelay($result, (int) $job['attempts'], (string) $job['purpose'], (int) $job['max_attempts']);
        if ($delay !== null) {
            $this->db()->exec("UPDATE voice_outbound_jobs SET status='scheduled', last_result=?, next_attempt_at=?, updated_at=NOW() WHERE id=? AND tenant_user_id=?", [$result, date('Y-m-d H:i:s', $now + $delay), $job['id'], $job['tenant_user_id']]);
            return;
        }
        $final = in_array($result, ['answered', 'voicemail'], true) ? 'completed' : 'exhausted';
        $this->db()->exec('UPDATE voice_outbound_jobs SET status=?, last_result=?, updated_at=NOW() WHERE id=? AND tenant_user_id=?', [$final, $result, $job['id'], $job['tenant_user_id']]);
    }

    // ------------------------------------------------------------------ provider callbacks

    /** The provider fetched call instructions because someone picked up. */
    public function handleAnswer(string $provider, int $tenantId, string $url, array $headers, string $raw, array $params, int $jobId): array
    {
        try {
            $a = $this->providers->adapter($tenantId, $provider);
        } catch (Throwable $e) {
            return [404, 'text/plain', 'Not found'];
        }
        if (!$a->verifyWebhook($url, $headers, $raw, $params)) {
            return [403, 'text/plain', 'Invalid signature'];
        }
        $ev = $a->parseStatusEvent($headers, $raw, $params);
        $job = $this->db()->query('SELECT * FROM voice_outbound_jobs WHERE id = ? AND tenant_user_id = ? LIMIT 1', [$jobId, $tenantId])[0] ?? null;
        if (!$ev || !$job || $job['last_call_sid'] !== $ev['call_id'] || $job['status'] !== 'dialing') {
            return [200, 'text/plain', 'ignored'];
        }
        $number = $this->db()->query('SELECT * FROM voice_numbers WHERE id = ? AND tenant_user_id = ? LIMIT 1', [$job['number_id'], $tenantId])[0] ?? [];
        $agent = $this->db()->query('SELECT * FROM voice_agents WHERE id = ? AND tenant_user_id = ? LIMIT 1', [$job['agent_id'], $tenantId])[0] ?? [];
        $settings = (new VoiceSettingsService())->get($tenantId);
        $lang = VoiceLanguages::normalize($agent['language'] ?? $settings['default_language'] ?? 'en');
        $voice = VoiceLanguages::safeVoice($agent['voice'] ?? null, $lang);
        $business = $this->store->businessName($tenantId);
        $by = strtolower((string) ($params['AnsweredBy'] ?? 'unknown'));
        $callId = $this->store->createCall($tenantId, $number, (int) $job['agent_id'], $provider, $ev['call_id'], (string) $number['e164'], (string) $job['to_e164'], 'turn', $lang, $job['contact_id'] !== null ? (int) $job['contact_id'] : null, 'outbound', (int) $job['id']);
        $this->store->updateCall($tenantId, $callId, ['answered_by' => mb_substr($by, 0, 20)]);

        if (strpos($by, 'machine') === 0) {
            $this->applyResult($job, 'voicemail');
            $say = $job['category'] === 'marketing' ? '' : VoiceLanguages::voicemailPhrase($lang, $business);
            $r = $a->turnInstructions(['say' => $say, 'voice' => $voice, 'hangup' => true]);
            return [200, $r['content_type'], $r['body']];
        }
        if ($by === 'fax') {
            $this->applyResult($job, 'failed');
            $r = $a->turnInstructions(['say' => '', 'hangup' => true]);
            return [200, $r['content_type'], $r['body']];
        }
        $ctx = json_decode((string) $job['context_json'], true) ?: [];
        $this->db()->exec("UPDATE voice_outbound_jobs SET last_result='answered', updated_at=NOW() WHERE id=? AND tenant_user_id=?", [$job['id'], $tenantId]);
        $r = $a->turnInstructions([
            'say' => VoiceLanguages::outboundOpening($lang, (string) ($agent['name'] ?? 'the assistant'), $business, $ctx['first_name'] ?? null),
            'voice' => $voice, 'language' => VoiceLanguages::speechLocale($lang),
            'gather_url' => VoiceInboundService::webhookUrls($this->baseUrl, $provider, $tenantId)['turn'] . '?call=' . $callId . '&n=1',
        ]);
        return [200, $r['content_type'], $r['body']];
    }

    /** @return bool true when the call id belongs to an outbound job (the caller then skips inbound handling) */
    public function onStatusBySid(int $tenantId, string $provider, array $ev): bool
    {
        $job = $this->db()->query('SELECT * FROM voice_outbound_jobs WHERE tenant_user_id = ? AND last_call_sid = ? LIMIT 1', [$tenantId, (string) $ev['call_id']])[0] ?? null;
        if (!$job) {
            return false;
        }
        if (!in_array($ev['status'], ['completed', 'busy', 'failed', 'no-answer', 'canceled'], true)) {
            return true;
        }
        $call = $this->store->findBySid($tenantId, $provider, (string) $ev['call_id']);
        if ($call) {
            $this->store->updateCall($tenantId, (int) $call['id'], ['ended_at' => date('Y-m-d H:i:s'), 'duration_s' => $ev['duration_s'], 'status' => $call['status'] === 'in_progress' ? ($ev['status'] === 'failed' ? 'failed' : 'completed') : $call['status']]);
            VoicePostCallService::markPending($this->store, $tenantId, ($this->store->getCall($tenantId, (int) $call['id']) ?? $call));
        }
        if ($job['status'] === 'dialing') { // not already settled by voicemail handling
            $map = ['busy' => 'busy', 'no-answer' => 'no-answer', 'failed' => 'failed', 'canceled' => 'failed'];
            $this->applyResult($job, $job['last_result'] === 'answered' || ($call && (int) $call['turns'] > 0) ? 'answered' : ($map[$ev['status']] ?? 'answered'));
        }
        return true;
    }
}

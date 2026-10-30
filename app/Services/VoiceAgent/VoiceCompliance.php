<?php

/**
 * Tourfecto - AI Voice Agent: the outbound call decision engine. Pure and deterministic: given a job and the facts about the
 * recipient it answers dial / wait / block. It runs when a job is created AND again right before dialing.
 *
 * Principles: do-not-call and opt-outs always win; every purpose needs a lawful basis we can point to (an existing booking, a recent
 * inquiry, a requested callback, or recorded consent for marketing); marketing is off in every country until the platform owner
 * enables it after legal review; calls only ring inside the recipient's local-time window; and per-recipient frequency is capped.
 * These defaults are product guardrails, not legal advice.
 */
class VoiceCompliance
{
    /** purpose => [category, lawful basis] */
    public const PURPOSES = [
        'booking_confirmation' => ['transactional', 'existing_booking'],
        'trip_reminder' => ['transactional', 'existing_booking'],
        'payment_followup' => ['transactional', 'existing_booking'],
        'booking_followup' => ['transactional', 'existing_booking'],
        'lead_followup' => ['service', 'inquiry'],
        'inquiry_followup' => ['service', 'inquiry'],
        'callback' => ['service', 'callback_request'],
        'survey' => ['service', 'completed_booking'],
        'post_trip' => ['service', 'completed_booking'],
        'marketing' => ['marketing', 'consent'],
    ];

    public const INQUIRY_MAX_DAYS = 30;
    public const CALLBACK_MAX_DAYS = 7;
    public const MIN_GAP_SECONDS = 14400;   // 4 hours between attempts to the same person
    public const MAX_PER_DAY = 2;
    public const MAX_PER_WEEK = 5;

    /** Seconds between attempts to one person: the company's choice (1-48 h), never below one hour, default 4 h. */
    public static function gapSeconds($hours): int
    {
        return is_numeric($hours) && (int) $hours >= 1 ? min(48, (int) $hours) * 3600 : self::MIN_GAP_SECONDS;
    }

    public static function category(string $purpose): ?string
    {
        return self::PURPOSES[$purpose][0] ?? null;
    }

    /**
     * @param array{purpose:string, country:?string, tz:?string, attempts:int, max_attempts:int} $job
     * @param array{dnc?:bool, opted_out?:bool, marketing_consent?:bool, marketing_country_enabled?:bool, has_booking?:bool, completed_booking?:bool,
     *              inquiry_age_days?:?int, callback_age_days?:?int, calls_24h?:int, calls_7d?:int, last_attempt_ts?:?int} $facts
     * @return array{action:string, reason:string, retry_at:?int}
     */
    public static function evaluate(array $job, array $facts, int $now): array
    {
        $block = static fn (string $r) => ['action' => 'block', 'reason' => $r, 'retry_at' => null];
        $purpose = (string) $job['purpose'];
        if (!isset(self::PURPOSES[$purpose])) {
            return $block('unknown_purpose');
        }
        [$category, $basis] = self::PURPOSES[$purpose];

        if (!empty($facts['dnc'])) {
            return $block('do_not_call_list');
        }
        if (!empty($facts['opted_out'])) {
            return $block('recipient_opted_out');
        }
        switch ($basis) {
            case 'existing_booking':
                if (empty($facts['has_booking'])) {
                    return $block('no_booking_relationship');
                }
                break;
            case 'completed_booking':
                if (empty($facts['completed_booking'])) {
                    return $block('no_completed_booking');
                }
                break;
            case 'inquiry':
                if (!isset($facts['inquiry_age_days']) || $facts['inquiry_age_days'] < 0 || $facts['inquiry_age_days'] > self::INQUIRY_MAX_DAYS) {
                    return $block('inquiry_too_old_or_unknown');
                }
                break;
            case 'callback_request':
                if (!isset($facts['callback_age_days']) || $facts['callback_age_days'] < 0 || $facts['callback_age_days'] > self::CALLBACK_MAX_DAYS) {
                    return $block('callback_request_too_old_or_unknown');
                }
                break;
            case 'consent':
                if (empty($facts['marketing_country_enabled'])) {
                    return $block('marketing_not_enabled_for_country');
                }
                if (empty($facts['marketing_consent'])) {
                    return $block('no_marketing_consent');
                }
                break;
        }
        if ((int) $job['attempts'] >= (int) $job['max_attempts']) {
            return $block('attempts_exhausted');
        }
        $zones = VoiceTimeWindows::zonesFor($job['country'] ?? null, $job['tz'] ?? null);
        if (!$zones) {
            return $block('timezone_unknown');
        }

        // frequency limits wait instead of failing: the job is still valid, just not now
        $wait = null;
        $gap = self::gapSeconds($job['min_gap_hours'] ?? null);
        if (!empty($facts['last_attempt_ts']) && $now - (int) $facts['last_attempt_ts'] < $gap) {
            $wait = (int) $facts['last_attempt_ts'] + $gap;
        }
        if ((int) ($facts['calls_24h'] ?? 0) >= self::MAX_PER_DAY) {
            $wait = max((int) $wait, $now + 86400);
        }
        if ((int) ($facts['calls_7d'] ?? 0) >= self::MAX_PER_WEEK) {
            $wait = max((int) $wait, $now + 2 * 86400);
        }
        $from = max($now, (int) $wait);
        $next = VoiceTimeWindows::nextAllowed($from, $zones, $category === 'marketing' ? 'marketing' : 'other', $job['country'] ?? null);
        if ($next === null) {
            return $block('no_allowed_time_found');
        }
        if ($next > $now) {
            return ['action' => 'wait', 'reason' => $wait !== null && $wait > $now ? 'frequency_limit' : 'outside_calling_hours', 'retry_at' => $next];
        }
        return ['action' => 'dial', 'reason' => 'ok', 'retry_at' => null];
    }

    /**
     * Seconds until the next attempt after a result, or null for "done". Voicemail on marketing is never retried.
     */
    public static function retryDelay(string $result, int $attemptsDone, string $purpose, int $maxAttempts): ?int
    {
        if ($attemptsDone >= $maxAttempts) {
            return null;
        }
        $marketing = (self::PURPOSES[$purpose][0] ?? '') === 'marketing';
        switch ($result) {
            case 'busy':
            case 'failed':
                return 1800;
            case 'no-answer':
                return $marketing && $attemptsDone >= 2 ? null : 14400;
            case 'voicemail':
                return $marketing || $attemptsDone >= 2 ? null : 86400;
        }
        return null; // answered, simulated, canceled...
    }
}

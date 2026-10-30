<?php

/**
 * Tourfecto - AI Voice Agent: automatic follow-up calls created from bookings: abandoned (unpaid) booking recovery, trip reminders for tomorrow,
 * and confirmation of a booking that was just confirmed. Each kind is off until the company turns it on. Every call is only QUEUED here: the
 * normal outbound path (VoiceCompliance at queue time and again at dial time, DNC, calling hours, caps, live approvals) decides whether it rings.
 * One job per booking and purpose (source_ref), so running this every few minutes never duplicates a call.
 */
class VoiceAutoFollowUps
{
    public const KINDS = [
        'abandoned' => ['purpose' => 'booking_followup', 'flag' => 'auto_abandoned_recovery'],
        'reminder' => ['purpose' => 'trip_reminder', 'flag' => 'auto_trip_reminders'],
        'confirmation' => ['purpose' => 'booking_confirmation', 'flag' => 'auto_booking_confirmation'],
    ];
    public const PER_RUN = 50;

    private VoiceOutboundService $jobs;

    public function __construct(?VoiceOutboundService $jobs = null)
    {
        $this->jobs = $jobs ?? new VoiceOutboundService();
    }

    private function db(): Database
    {
        return Database::getInstance();
    }

    /** Only numbers that already carry their country code. A local-format number is never guessed into another country. */
    public static function phoneToE164(?string $raw): ?string
    {
        $s = preg_replace('/[\s\-().]/', '', (string) $raw);
        if (strpos($s, '00') === 0) {
            $s = '+' . substr($s, 2);
        }
        try {
            return strpos($s, '+') === 0 ? VoiceNumberRules::normalizeE164($s) : null;
        } catch (InvalidArgumentException $e) {
            return null;
        }
    }

    public static function sourceRef(int $bookingId, string $purpose): string
    {
        return 'booking:' . $bookingId . ':' . $purpose;
    }

    /** What the assistant may say about the booking on the call (amounts are only used after identity checks, see VoicePromptBuilder). */
    public static function contextFor(array $b): array
    {
        $first = trim((string) strtok(trim((string) ($b['customer_name'] ?? '')), ' '));
        return array_filter([
            'first_name' => $first, 'booking_ref' => (string) ($b['booking_reference'] ?? ''), 'trip_name' => (string) ($b['trip_name'] ?? ''),
            'trip_date' => (string) ($b['start_date'] ?? ''), 'amount' => isset($b['total_amount']) ? rtrim(rtrim(number_format((float) $b['total_amount'], 2, '.', ''), '0'), '.') : '', 'currency' => (string) ($b['currency'] ?? ''),
        ], static fn ($v) => $v !== '');
    }

    /** SQL per kind. Always limited to one company's bookings. */
    private static function where(string $kind): string
    {
        switch ($kind) {
            case 'abandoned':
                return "b.status = 'pending' AND b.created_at <= NOW() - INTERVAL 2 HOUR AND b.created_at >= NOW() - INTERVAL 48 HOUR AND b.start_date >= CURDATE()
                        AND NOT EXISTS (SELECT 1 FROM payment_transactions t WHERE t.booking_id = b.id AND t.status IN ('succeeded','processing'))";
            case 'reminder':
                return "b.status = 'confirmed' AND b.start_date = CURDATE() + INTERVAL 1 DAY";
            case 'confirmation':
                return "b.status = 'confirmed' AND b.updated_at >= NOW() - INTERVAL 6 HOUR AND b.start_date >= CURDATE()";
        }
        throw new InvalidArgumentException('Unknown kind');
    }

    /** @return list<array<string,mixed>> */
    public function candidates(int $tenantId, string $kind): array
    {
        return $this->db()->query(
            'SELECT b.id, b.booking_reference, b.customer_id, b.customer_name, b.customer_phone, b.start_date, b.total_amount, b.currency, p.name AS trip_name
             FROM bookings b LEFT JOIN crm_products p ON p.id = b.product_id
             WHERE b.user_id = ? AND b.customer_phone IS NOT NULL AND b.customer_phone <> \'\' AND ' . self::where($kind) . ' ORDER BY b.id DESC LIMIT ' . self::PER_RUN,
            [$tenantId]
        ) ?: [];
    }

    /** @return array{tenants:int, queued:int, skipped:array<string,int>} */
    public function run(): array
    {
        $sum = ['tenants' => 0, 'queued' => 0, 'skipped' => []];
        $tenants = $this->db()->query('SELECT tenant_user_id FROM voice_agent_settings WHERE enabled = 1 AND outbound_enabled = 1 AND (auto_abandoned_recovery = 1 OR auto_trip_reminders = 1 OR auto_booking_confirmation = 1)') ?: [];
        foreach ($tenants as $row) {
            $t = (int) $row['tenant_user_id'];
            $settings = (new VoiceSettingsService())->get($t);
            $sum['tenants']++;
            $num = $this->db()->query(
                "SELECT n.id AS number_id, n.agent_id FROM voice_numbers n JOIN voice_agents a ON a.id = n.agent_id AND a.tenant_user_id = n.tenant_user_id AND a.status = 'active'
                 WHERE n.tenant_user_id = ? AND n.status IN ('active','simulated') AND n.provider IS NOT NULL ORDER BY n.is_default DESC, n.id LIMIT 1",
                [$t]
            )[0] ?? null;
            if (!$num) {
                $sum['skipped']['no_number_with_active_agent'] = ($sum['skipped']['no_number_with_active_agent'] ?? 0) + 1;
                continue;
            }
            foreach (self::KINDS as $kind => $def) {
                if (empty($settings[$def['flag']])) {
                    continue;
                }
                foreach ($this->candidates($t, $kind) as $b) {
                    $to = self::phoneToE164($b['customer_phone']);
                    if ($to === null) {
                        $sum['skipped']['phone_without_country_code'] = ($sum['skipped']['phone_without_country_code'] ?? 0) + 1;
                        continue;
                    }
                    try {
                        $r = $this->jobs->enqueue($t, $t, [
                            'purpose' => $def['purpose'], 'to' => $to, 'number_id' => (int) $num['number_id'], 'agent_id' => (int) $num['agent_id'],
                            'contact_id' => $b['customer_id'] ?: null, 'context' => self::contextFor($b), 'source_ref' => self::sourceRef((int) $b['id'], $def['purpose']),
                            'approve_live' => (bool) $settings['auto_followups_live_approved'],
                        ]);
                        if (empty($r['existing'])) {
                            $sum['queued']++;
                        }
                    } catch (InvalidArgumentException $e) {
                        $k = mb_substr($e->getMessage(), 0, 60);
                        $sum['skipped'][$k] = ($sum['skipped'][$k] ?? 0) + 1;
                    }
                }
            }
        }
        return $sum;
    }
}

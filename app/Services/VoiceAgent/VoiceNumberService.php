<?php

/**
 * Tourfecto - AI Voice Agent: phone numbers (buy through Tourfecto, or connect an existing one). Tenant-scoped.
 * Buying: quote (live price + availability) -> customer approves the exact amount -> guarded purchase.
 * Connecting: ownership is proven by the customer's own provider account, or by a code delivered to the number.
 * Errors the customer should see are InvalidArgumentException; provider failures are VoiceProviderException.
 */
class VoiceNumberService
{
    private VoiceProviderService $providers;

    public function __construct(?VoiceProviderService $providers = null)
    {
        $this->providers = $providers ?? new VoiceProviderService();
    }

    private function db(): Database
    {
        return Database::getInstance();
    }

    private function row(int $tenantId, int $numberId): array
    {
        $r = $this->db()->query('SELECT * FROM voice_numbers WHERE id = ? AND tenant_user_id = ? LIMIT 1', [$numberId, $tenantId]);
        if (!$r) {
            throw new InvalidArgumentException('Number not found');
        }
        return $r[0];
    }

    private function assertRoom(int $tenantId): void
    {
        $max = VoiceNumberRules::maxNumbers(FeatureResolver::value($tenantId, 'voice_max_numbers'));
        $n = (int) ($this->db()->query('SELECT COUNT(*) AS n FROM voice_numbers WHERE tenant_user_id = ?', [$tenantId])[0]['n'] ?? 0);
        if ($n >= $max) {
            throw new InvalidArgumentException("Number limit reached ($max). Release a number or upgrade your plan.");
        }
    }

    /** Rejects a number this tenant already has, or one another account has proven/bought live. Unproven claims do not block anyone. */
    private function assertFree(int $tenantId, string $e164): void
    {
        if ($this->db()->query('SELECT id FROM voice_numbers WHERE e164 = ? AND (tenant_user_id = ? OR live_key = ?) LIMIT 1', [$e164, $tenantId, $e164])) {
            throw new InvalidArgumentException('This number is already registered');
        }
    }

    public function list(int $tenantId): array
    {
        $out = [];
        foreach ($this->db()->query('SELECT * FROM voice_numbers WHERE tenant_user_id = ? ORDER BY id DESC', [$tenantId]) ?: [] as $r) {
            $r['live_usable'] = VoiceNumberRules::liveUsable($r);
            unset($r['verification_hash']);
            $out[] = $r;
        }
        return $out;
    }

    /** Re-checks availability and price with the provider right now and stores a time-limited quote. */
    public function quote(int $tenantId, int $actorUserId, string $provider, string $e164, string $iso, string $type): array
    {
        $e164 = VoiceNumberRules::normalizeE164($e164);
        $iso = strtoupper(trim($iso));
        if (!in_array($type, VoiceNumberRules::TYPES, true) || !preg_match('/^[A-Z]{2}$/', $iso)) {
            throw new InvalidArgumentException('Invalid country or number type');
        }
        $this->assertFree($tenantId, $e164);
        $cc = VoiceMarkets::callingCode($iso);
        $national = $cc !== null && strpos(ltrim($e164, '+'), $cc) === 0 ? substr(ltrim($e164, '+'), strlen($cc)) : '';
        $found = null;
        foreach ($this->providers->search($tenantId, $provider, $iso, $type, ['contains' => $national]) as $n) {
            if ($n['number'] === $e164) {
                $found = $n;
                break;
            }
        }
        if (!$found) {
            throw new InvalidArgumentException('This number is no longer available from the provider');
        }
        if (empty($found['voice'])) {
            throw new InvalidArgumentException('This number does not support voice calls');
        }
        $monthly = $found['monthly_cost'];
        $first = VoiceNumberRules::firstCharge($monthly, $found['setup_cost'] ?? 0.0);
        if ($first === null) {
            throw new InvalidArgumentException('The provider did not publish a price for this number, so it cannot be bought here');
        }
        $usage = null;
        try {
            $usage = $this->providers->readAdapter($tenantId, $provider)->usageRates($iso, $type);
        } catch (VoiceProviderException $e) {
            VoiceProviderErrorLog::record($tenantId, $provider, 'usage_rates', $e);
        }
        $req = VoiceNumberRules::assessRequirements($iso, (string) ($found['address_required'] ?? 'unknown'));
        $this->db()->exec(
            "INSERT INTO voice_number_orders (tenant_user_id, provider, e164, country, number_type, monthly_cost, setup_cost, first_charge, currency, usage_json, requirement_level, needs_ack, status, quoted_by_user_id, quoted_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'quoted',?,NOW())",
            [$tenantId, $provider, $e164, $iso, $type, $monthly, $found['setup_cost'] ?? 0.0, $first, $found['currency'], $usage ? json_encode($usage) : null, $req['level'], $req['needs_ack'] ? 1 : 0, $actorUserId]
        );
        $id = (int) $this->db()->getConnection()->lastInsertId();
        VoiceAuditService::record($tenantId, $actorUserId, 'number.quoted', 'order', $id, ['number' => $e164]);
        return [
            'order_id' => $id, 'number' => $e164, 'country' => $iso, 'type' => $type, 'provider' => $provider,
            'monthly_cost' => $monthly, 'setup_cost' => (float) ($found['setup_cost'] ?? 0), 'first_charge' => $first, 'currency' => $found['currency'],
            'usage_rates' => $usage, 'usage_note' => $usage ? 'Per-minute usage is billed on top of the monthly fee.' : 'The provider API does not publish usage rates for this route; check the provider price list before approving.',
            'requirements' => $req, 'expires_in_seconds' => VoiceNumberRules::QUOTE_TTL_SECONDS,
        ];
    }

    /** Customer approval of an exact quote, then a guarded purchase (simulated unless every live condition holds). */
    public function approve(int $tenantId, int $actorUserId, int $orderId, $acceptedTotal, bool $requirementsAck, array $providerRefs = []): array
    {
        $o = $this->db()->query('SELECT * FROM voice_number_orders WHERE id = ? AND tenant_user_id = ? LIMIT 1', [$orderId, $tenantId]);
        if (!$o) {
            throw new InvalidArgumentException('Order not found');
        }
        $o = $o[0];
        if ($o['status'] !== 'quoted') {
            throw new InvalidArgumentException('This order was already processed');
        }
        if (VoiceNumberRules::quoteExpired((int) strtotime($o['quoted_at']))) {
            throw new InvalidArgumentException('The quote expired; request a new one');
        }
        if (!VoiceNumberRules::approvalMatches((float) $o['first_charge'], $acceptedTotal)) {
            throw new InvalidArgumentException('The approved amount does not match the quoted amount');
        }
        if ((int) $o['needs_ack'] === 1 && !$requirementsAck) {
            throw new InvalidArgumentException('Please confirm you have read the regulatory requirements for this number');
        }
        $this->assertRoom($tenantId);
        $this->assertFree($tenantId, $o['e164']);

        $st = $this->db()->getConnection()->prepare("UPDATE voice_number_orders SET status='approved', approved_by_user_id=?, approved_at=NOW() WHERE id=? AND tenant_user_id=? AND status='quoted'");
        $st->execute([$actorUserId, $orderId, $tenantId]);
        if ($st->rowCount() !== 1) {
            throw new InvalidArgumentException('This order was already processed');
        }

        $urls = VoiceInboundService::webhookUrls(defined('APP_URL') ? APP_URL : '', $o['provider'], $tenantId);
        $opts = ['voice_url' => $urls['inbound'], 'status_callback' => $urls['status']];
        foreach (['address_sid', 'bundle_sid'] as $k) {
            if (!empty($providerRefs[$k]) && preg_match('/^[A-Za-z0-9]{10,64}$/', (string) $providerRefs[$k])) {
                $opts[$k] = (string) $providerRefs[$k];
            }
        }
        try {
            $res = $this->providers->guarded($tenantId, $actorUserId, $o['provider'], 'purchase_number', ['number' => $o['e164']], true,
                fn (VoiceProviderAdapter $a) => $a->purchaseNumber($o['e164'], $opts));
        } catch (Throwable $e) {
            $this->db()->exec("UPDATE voice_number_orders SET status='failed', result_json=? WHERE id=? AND tenant_user_id=?", [json_encode(['error' => mb_substr($e->getMessage(), 0, 300)]), $orderId, $tenantId]);
            throw $e;
        }
        $simulated = !empty($res['simulated']);
        $status = $simulated ? 'simulated' : (($res['status'] ?? '') === 'active' ? 'active' : 'pending');
        $saved = $this->db()->exec(
            "INSERT INTO voice_numbers (tenant_user_id, e164, live_key, country, number_type, source, provider, provider_number_id, status, verification_mode, verified_at, monthly_cost, setup_cost, currency, created_at, updated_at)
             VALUES (?,?,?,?,?, 'purchased', ?,?,?,?, ?,?,?,?, NOW(), NOW())",
            [$tenantId, $o['e164'], $simulated ? null : $o['e164'], $o['country'], $o['number_type'], $o['provider'], $res['number_id'] ?? null, $status, $simulated ? 'none' : 'live', $simulated ? null : date('Y-m-d H:i:s'), $o['monthly_cost'], $o['setup_cost'], $o['currency']]
        );
        if (!$saved) {
            // The provider already bought it: never lose track of a paid number.
            VoiceAuditService::record($tenantId, $actorUserId, 'number.purchase_record_failed', 'order', $orderId, ['number' => $o['e164'], 'provider_number_id' => $res['number_id'] ?? null]);
            $this->db()->exec("UPDATE voice_number_orders SET status='failed', result_json=? WHERE id=? AND tenant_user_id=?", [json_encode(['error' => 'purchased_but_not_recorded', 'provider_number_id' => $res['number_id'] ?? null]), $orderId, $tenantId]);
            throw new RuntimeException('The number was purchased but could not be saved. Support has been alerted with the order reference ' . $orderId);
        }
        $numberId = (int) $this->db()->getConnection()->lastInsertId();
        $this->db()->exec("UPDATE voice_number_orders SET status='completed', result_json=? WHERE id=? AND tenant_user_id=?", [json_encode(['simulated' => $simulated, 'reason' => $res['reason'] ?? null, 'number_id' => $numberId]), $orderId, $tenantId]);
        return ['number_id' => $numberId, 'status' => $status, 'simulated' => $simulated, 'reason' => $res['reason'] ?? null];
    }

    /** Add an existing number. provider_native is proven from the customer's own provider account right away. */
    public function connect(int $tenantId, int $actorUserId, string $e164, string $method, ?string $provider = null, ?string $country = null): array
    {
        $e164 = VoiceNumberRules::normalizeE164($e164);
        if (!in_array($method, VoiceNumberRules::METHODS, true)) {
            throw new InvalidArgumentException('Unknown connection method');
        }
        $iso = $country ? strtoupper(trim($country)) : VoiceMarkets::countryOf($e164);
        if ($iso !== null && !preg_match('/^[A-Z]{2}$/', $iso)) {
            throw new InvalidArgumentException('Invalid country');
        }
        $this->assertFree($tenantId, $e164);
        $this->assertRoom($tenantId);

        $status = 'pending_verification';
        $mode = 'none';
        $providerNumberId = null;
        if ($method === 'provider_native') {
            if (!$provider || !VoiceProviderRegistry::has($provider)) {
                throw new InvalidArgumentException('Choose the provider that holds this number');
            }
            $owned = $this->providers->adapter($tenantId, $provider)->listOwnedNumbers();
            foreach ($owned as $n) {
                if ($n['number'] === $e164) {
                    $providerNumberId = $n['number_id'];
                }
            }
            if ($providerNumberId === null) {
                throw new InvalidArgumentException('This number was not found in your provider account');
            }
            $status = 'verified';
            $mode = 'live';
        } elseif ($method === 'port') {
            $status = 'port_requested';
        } elseif ($provider !== null && !VoiceProviderRegistry::has($provider)) {
            $provider = null;
        }
        $ok = $this->db()->exec(
            'INSERT INTO voice_numbers (tenant_user_id, e164, live_key, country, number_type, source, connect_method, provider, provider_number_id, status, verification_mode, verified_at, created_at, updated_at)
             VALUES (?,?,?,?,?,\'connected\',?,?,?,?,?,?,NOW(),NOW())',
            [$tenantId, $e164, $mode === 'live' ? $e164 : null, $iso, 'local', $method, $method === 'provider_native' ? $provider : null, $providerNumberId, $status, $mode, $mode === 'live' ? date('Y-m-d H:i:s') : null]
        );
        if (!$ok) {
            throw new InvalidArgumentException('This number is already in use by another account');
        }
        $id = (int) $this->db()->getConnection()->lastInsertId();
        VoiceAuditService::record($tenantId, $actorUserId, 'number.connected', 'number', $id, ['method' => $method]);

        $target = null;
        if ($method === 'forward') {
            $t = $this->db()->query("SELECT e164 FROM voice_numbers WHERE tenant_user_id = ? AND source = 'purchased' AND status IN ('active','simulated') ORDER BY id LIMIT 1", [$tenantId]);
            $target = $t ? $t[0]['e164'] : null;
        } elseif ($method === 'sip') {
            $dom = trim((new SystemSettingsService())->get('voice_sip_domain', ''));
            $target = $dom !== '' ? 'sip:' . ltrim($e164, '+') . '@' . $dom : null;
        } elseif ($method === 'provider_native') {
            $target = VoiceInboundService::webhookUrls(defined('APP_URL') ? APP_URL : '', (string) $provider, $tenantId)['inbound'];
        }
        return ['number_id' => $id, 'status' => $status, 'steps' => VoiceNumberRules::connectionSteps($method, $e164, $target), 'target' => $target];
    }

    /** Delivers a one-time code to the number by phone call (guarded: only live with approval). */
    public function startVerification(int $tenantId, int $actorUserId, int $numberId, bool $approved): array
    {
        $n = $this->row($tenantId, $numberId);
        if ($n['source'] !== 'connected' || !in_array($n['connect_method'], ['forward', 'sip'], true) || $n['status'] !== 'pending_verification') {
            throw new InvalidArgumentException('This number does not need a verification code');
        }
        if ($n['verification_expires_at'] && strtotime($n['verification_expires_at']) - time() > 540) {
            throw new InvalidArgumentException('A code was just sent; wait a minute before requesting another');
        }
        $from = $this->db()->query("SELECT e164, provider FROM voice_numbers WHERE tenant_user_id = ? AND source = 'purchased' AND status = 'active' AND provider IS NOT NULL ORDER BY id LIMIT 1", [$tenantId]);
        if (!$from) {
            throw new InvalidArgumentException('You need an active Tourfecto number first, so we can place the verification call from it');
        }
        $provider = $from[0]['provider'];
        if (empty(VoiceProviderService::capabilitiesOf($provider)['inline_voice'])) {
            throw new InvalidArgumentException('Automatic verification calls are not available with this provider yet; contact support for manual verification');
        }
        $code = VoiceNumberRules::newVerificationCode();
        $res = $this->providers->guarded($tenantId, $actorUserId, $provider, 'start_call', ['to' => $n['e164'], 'purpose' => 'verify'], $approved,
            fn (VoiceProviderAdapter $a) => $a->startCall($from[0]['e164'], $n['e164'], ['twiml' => VoiceNumberRules::verificationTwiml($code)]));
        $simulated = !empty($res['simulated']);
        if ($simulated && ($res['reason'] ?? '') === 'approval_required') {
            throw new InvalidArgumentException('Please confirm that we may call this number to deliver the verification code');
        }
        $this->db()->exec(
            'UPDATE voice_numbers SET verification_hash=?, verification_expires_at=DATE_ADD(NOW(), INTERVAL 10 MINUTE), verification_attempts=0, verification_mode=?, updated_at=NOW() WHERE id=? AND tenant_user_id=?',
            [VoiceNumberRules::hashCode($code, $numberId), $simulated ? 'sandbox' : 'live', $numberId, $tenantId]
        );
        $out = ['sent' => !$simulated, 'simulated' => $simulated, 'expires_in_seconds' => 600];
        if ($simulated) {
            // Sandbox: no call is placed, so the code is shown for testing. Such a number can never be used for real calls.
            $out['sandbox_code'] = $code;
        }
        return $out;
    }

    public function confirmVerification(int $tenantId, int $actorUserId, int $numberId, string $code): array
    {
        $n = $this->row($tenantId, $numberId);
        if ($n['status'] !== 'pending_verification' || !$n['verification_hash']) {
            throw new InvalidArgumentException('No verification is in progress for this number');
        }
        if (!$n['verification_expires_at'] || strtotime($n['verification_expires_at']) < time()) {
            throw new InvalidArgumentException('The code expired; request a new one');
        }
        if ((int) $n['verification_attempts'] >= 5) {
            throw new InvalidArgumentException('Too many attempts; request a new code');
        }
        if (!VoiceNumberRules::codeMatches((string) $n['verification_hash'], trim($code), $numberId)) {
            $this->db()->exec('UPDATE voice_numbers SET verification_attempts = verification_attempts + 1 WHERE id=? AND tenant_user_id=?', [$numberId, $tenantId]);
            throw new InvalidArgumentException('Incorrect code');
        }
        $liveKey = $n['verification_mode'] === 'live' ? $n['e164'] : null;
        $ok = $this->db()->exec("UPDATE voice_numbers SET status='awaiting_link_test', live_key=?, verified_at=NOW(), verification_hash=NULL, verification_expires_at=NULL, updated_at=NOW() WHERE id=? AND tenant_user_id=?", [$liveKey, $numberId, $tenantId]);
        if (!$ok) {
            throw new InvalidArgumentException('This number is already in use by another account');
        }
        VoiceAuditService::record($tenantId, $actorUserId, 'number.verified', 'number', $numberId, ['mode' => $n['verification_mode']]);
        return ['status' => 'awaiting_link_test', 'verification_mode' => $n['verification_mode']];
    }

    /** Link test: own-account numbers are re-checked at the provider; forwarded/SIP numbers pass when a real call arrives (Phase 4 inbound). */
    public function testLink(int $tenantId, int $numberId): array
    {
        $n = $this->row($tenantId, $numberId);
        if ($n['connect_method'] === 'provider_native') {
            $found = false;
            foreach ($this->providers->adapter($tenantId, (string) $n['provider'])->listOwnedNumbers() as $o) {
                $found = $found || $o['number'] === $n['e164'];
            }
            if (!$found) {
                $this->db()->exec("UPDATE voice_numbers SET status='failed', updated_at=NOW() WHERE id=? AND tenant_user_id=?", [$numberId, $tenantId]);
                return ['state' => 'failed', 'detail' => 'The number is no longer in your provider account'];
            }
            $this->db()->exec("UPDATE voice_numbers SET status='active', link_tested_at=NOW(), updated_at=NOW() WHERE id=? AND tenant_user_id=?", [$numberId, $tenantId]);
            return ['state' => 'active', 'detail' => 'Ownership confirmed in your provider account'];
        }
        if ($n['status'] === 'awaiting_link_test') {
            return ['state' => 'waiting_for_inbound_call', 'detail' => 'Call this number from another phone; the link is confirmed when the call reaches Tourfecto'];
        }
        return ['state' => $n['status'], 'detail' => ''];
    }

    /** Called by the inbound call handler (Phase 4) when a real call reaches a number that is waiting for its link test. */
    public function recordInboundArrival(int $tenantId, string $e164): void
    {
        $this->db()->exec("UPDATE voice_numbers SET status='active', link_tested_at=NOW(), updated_at=NOW() WHERE tenant_user_id=? AND e164=? AND status='awaiting_link_test'", [$tenantId, $e164]);
    }

    public function assign(int $tenantId, int $numberId, ?int $agentId, ?string $forwardTo): array
    {
        $this->row($tenantId, $numberId);
        if ($agentId !== null && !$this->db()->query('SELECT id FROM voice_agents WHERE id = ? AND tenant_user_id = ? LIMIT 1', [$agentId, $tenantId])) {
            throw new InvalidArgumentException('Agent not found');
        }
        $fwd = ($forwardTo !== null && trim($forwardTo) !== '') ? VoiceNumberRules::normalizeE164($forwardTo) : null;
        $this->db()->exec('UPDATE voice_numbers SET agent_id=?, forward_to=?, updated_at=NOW() WHERE id=? AND tenant_user_id=?', [$agentId, $fwd, $numberId, $tenantId]);
        return ['agent_id' => $agentId, 'forward_to' => $fwd];
    }

    /** Purchased numbers are released at the provider (guarded); connected numbers are only removed from Tourfecto. */
    public function release(int $tenantId, int $actorUserId, int $numberId, bool $approved): array
    {
        $n = $this->row($tenantId, $numberId);
        $real = $n['source'] === 'purchased' && $n['provider_number_id'] && strpos((string) $n['provider_number_id'], 'SIM-') !== 0;
        $simulated = false;
        if ($real) {
            $res = $this->providers->guarded($tenantId, $actorUserId, (string) $n['provider'], 'release_number', ['number' => $n['e164']], $approved,
                fn (VoiceProviderAdapter $a) => ['ok' => $a->releaseNumber((string) $n['provider_number_id'])]);
            $simulated = !empty($res['simulated']);
            if ($simulated) {
                return ['released' => false, 'simulated' => true, 'reason' => $res['reason'] ?? null];
            }
        }
        $this->db()->exec('DELETE FROM voice_numbers WHERE id=? AND tenant_user_id=?', [$numberId, $tenantId]);
        VoiceAuditService::record($tenantId, $actorUserId, 'number.released', 'number', $numberId, ['number' => $n['e164']]);
        return ['released' => true, 'simulated' => false];
    }
}

<?php

/**
 * AI Voice & Sales Agent: the database-backed paths against a real MariaDB (the same engine CI uses).
 * Two companies (998001, 998002) are created and removed around every test. Nothing here calls a provider: the company is in sandbox mode,
 * so every "live" operation is simulated by VoiceLiveGuard.
 */

use PHPUnit\Framework\TestCase;

final class VoiceAgentDatabaseTest extends TestCase
{
    private const A = 998001;
    private const B = 998002;
    private static ?bool $ready = null;

    private function db(): Database
    {
        return Database::getInstance();
    }

    private function ready(): bool
    {
        if (self::$ready !== null) {
            return self::$ready;
        }
        try {
            self::$ready = (bool) $this->db()->query("SHOW TABLES LIKE 'voice_calls'") && (bool) $this->db()->query("SHOW TABLES LIKE 'bookings'");
        } catch (Throwable $e) {
            self::$ready = false;
        }
        return self::$ready;
    }

    protected function setUp(): void
    {
        if (!$this->ready()) {
            $this->markTestSkipped('database with the voice migrations is not available');
        }
        $this->clean();
        foreach ([self::A, self::B] as $id) {
            $this->db()->exec("INSERT INTO users (id, email, password, company_name, created_at) VALUES (?, ?, 'x', 'Voice Test', NOW()) ON DUPLICATE KEY UPDATE email = VALUES(email)", [$id, "voice-$id@tourfecto.test"]);
        }
    }

    protected function tearDown(): void
    {
        if (self::$ready) {
            $this->clean();
        }
    }

    private function clean(): void
    {
        $ids = [self::A, self::B];
        $in = implode(',', $ids);
        foreach (['voice_agent_settings', 'voice_agents', 'voice_numbers', 'voice_number_orders', 'voice_providers', 'voice_provider_errors', 'voice_call_costs', 'voice_webhook_events', 'voice_calls', 'voice_call_turns', 'voice_outbound_jobs',
            'voice_outbound_attempts', 'voice_campaigns', 'voice_consents', 'voice_dnc', 'voice_post_call_actions', 'voice_staff', 'voice_call_reviews', 'voice_feature_grants', 'voice_usage_ledger', 'voice_budget_alerts', 'voice_invoices', 'voice_recordings'] as $t) {
            $this->db()->exec("DELETE FROM $t WHERE tenant_user_id IN ($in)");
        }
        $this->db()->exec("DELETE FROM voice_dnc WHERE tenant_user_id = 0 AND reason = 'phpunit'");
        $this->db()->exec("DELETE FROM booking_status_history WHERE booking_id IN (SELECT id FROM bookings WHERE user_id IN ($in))");
        $this->db()->exec("DELETE FROM bookings WHERE user_id IN ($in)");
        $this->db()->exec("DELETE FROM inventory WHERE user_id IN ($in)");
        $this->db()->exec("DELETE FROM crm_tasks WHERE user_id IN ($in)");
        $this->db()->exec("DELETE FROM crm_leads WHERE contact_id IN (SELECT id FROM crm_contacts WHERE user_id IN ($in))");
        $this->db()->exec("DELETE FROM crm_notes WHERE user_id IN ($in)");
        $this->db()->exec("DELETE FROM crm_contacts WHERE user_id IN ($in)");
        $this->db()->exec("DELETE FROM crm_products WHERE user_id IN ($in)");
        $this->db()->exec("DELETE FROM ceo_risk_alerts WHERE user_id IN ($in)");
        $this->db()->exec("DELETE FROM ceo_growth_opportunities WHERE user_id IN ($in)");
        $this->db()->exec("DELETE FROM activity_logs WHERE module = 'voice' AND user_id IN ($in)");
    }

    private function agent(int $t, array $o = []): int
    {
        return (new VoiceSettingsService())->createAgent($t, $t, $o + ['name' => 'Layla', 'language' => 'en', 'status' => 'active']);
    }

    private function number(int $t, string $e164, array $o = []): int
    {
        $o += ['status' => 'active', 'provider' => 'twilio', 'agent_id' => null, 'source' => 'purchased', 'live_key' => null, 'is_default' => 0];
        $this->db()->exec(
            "INSERT INTO voice_numbers (tenant_user_id, e164, live_key, country, number_type, source, provider, provider_number_id, status, verification_mode, agent_id, is_default, monthly_cost, currency, created_at, updated_at)
             VALUES (?,?,?,?, 'local', ?, ?, 'PN-test', ?, 'live', ?, ?, 2.00, 'USD', NOW() - INTERVAL 10 DAY, NOW())",
            [$t, $e164, $o['live_key'], VoiceMarkets::countryOf($e164), $o['source'], $o['provider'], $o['status'], $o['agent_id'], $o['is_default']]
        );
        return (int) $this->db()->getConnection()->lastInsertId();
    }

    private function settings(int $t, array $s): void
    {
        (new VoiceSettingsService())->save($t, $s);
    }

    private function product(int $t, string $name = 'Luxor Day Trip', float $price = 120.0): int
    {
        $this->db()->exec("INSERT INTO crm_products (user_id, name, description, price, currency, is_active) VALUES (?,?,?,?, 'USD', 1)", [$t, $name, 'Temples and tombs', $price]);
        return (int) $this->db()->getConnection()->lastInsertId();
    }

    private function contact(int $t, string $name, string $phone, ?string $email = null): int
    {
        $this->db()->exec('INSERT INTO crm_contacts (user_id, name, email, phone) VALUES (?,?,?,?)', [$t, $name, $email, $phone]);
        return (int) $this->db()->getConnection()->lastInsertId();
    }

    // ------------------------------------------------------------------ settings, assistants, audit

    public function testSettingsRoundTripAndAssistantsAreInvisibleToOtherCompanies(): void
    {
        $svc = new VoiceSettingsService();
        $this->assertSame(0, (int) $svc->get(self::A)['enabled']);                       // safe defaults before anything is saved
        $this->assertSame(1, (int) $svc->get(self::A)['sandbox_mode']);
        $saved = $svc->save(self::A, ['enabled' => true, 'default_language' => 'ar', 'timezone' => 'Africa/Cairo', 'booking_mode' => 'create_pending', 'max_concurrent_calls' => 5, 'monthly_budget_usd' => '150.50']);
        $this->assertSame(1, (int) $saved['enabled']);
        $this->assertSame('ar', $saved['default_language']);
        $this->assertSame('create_pending', $saved['booking_mode']);
        $this->assertSame('150.50', $saved['monthly_budget_usd']);
        $this->assertSame(0, (int) $svc->get(self::B)['enabled']);                       // the other company is untouched

        $id = $this->agent(self::A, ['name' => 'Salma', 'languages' => 'ar,fr', 'role' => 'sales', 'tone' => 'warm', 'opening_text' => 'Welcome', 'instructions' => 'Mention pickup']);
        $this->assertCount(1, $svc->listAgents(self::A));
        $this->assertSame('sales', $svc->listAgents(self::A)[0]['role']);
        $this->assertSame([], $svc->listAgents(self::B));
        $this->assertFalse($svc->updateAgent(self::B, $id, ['name' => 'Hijack']));
        $this->assertTrue($svc->updateAgent(self::A, $id, ['name' => 'Salma 2', 'status' => 'paused']));
        $this->assertSame('Salma 2', $svc->listAgents(self::A)[0]['name']);
    }

    public function testRejectedWebsiteOwnershipWhenSavingSettings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new VoiceSettingsService())->save(self::A, ['knowledge_website_id' => 987654321]);
    }

    public function testAuditGoesToTheSharedActivityLogUnderTheCompany(): void
    {
        VoiceAuditService::record(self::A, self::A, 'test.action', 'number', 42, ['k' => 'v']);
        VoiceAuditService::record(self::A, null, 'test.text_subject', 'provider', 'twilio');
        $rows = VoiceAuditService::recent(self::A);
        $this->assertCount(2, $rows);
        $this->assertSame([], VoiceAuditService::recent(self::B));
        $this->assertContains('test.action', array_column($rows, 'action'));
    }

    // ------------------------------------------------------------------ calls, turns, customers, catalogue

    public function testCallStoreIsIdempotentTenantScopedAndCountsConcurrency(): void
    {
        $s = new VoiceDataStore();
        $n = ['id' => 1];
        $id = $s->createCall(self::A, $n, null, 'twilio', 'CA1', '+201001234567', '+15125550100', 'turn', 'en', null);
        $this->assertSame($id, $s->createCall(self::A, $n, null, 'twilio', 'CA1', '+201001234567', '+15125550100', 'turn', 'en', null)); // provider retry
        $this->assertNotNull($s->getCall(self::A, $id));
        $this->assertNull($s->getCall(self::B, $id));
        $s->updateCall(self::A, $id, ['ai_cost_usd_add' => 0.0123, 'verify_attempts_add' => 1, 'latency_ms_add' => 800, 'language' => 'ar']);
        $s->updateCall(self::A, $id, ['ai_cost_usd_add' => 0.0100, 'verify_attempts_add' => 1, 'latency_ms_add' => 400, 'hacker_column' => 'x']);
        $s->updateCall(self::B, $id, ['language' => 'fr']);                              // another company cannot change it
        $c = $s->getCall(self::A, $id);
        $this->assertSame('0.0223', $c['ai_cost_usd']);
        $this->assertSame(2, (int) $c['verify_attempts']);
        $this->assertSame(1200, (int) $c['latency_ms_total']);
        $this->assertSame(2, (int) $c['latency_samples']);
        $this->assertSame('ar', $c['language']);
        $s->addTurn(self::A, $id, 100, 'caller', 'hello');
        $s->addTurn(self::A, $id, 100, 'caller', 'duplicate ignored');
        $s->addTurn(self::A, $id, 190, 'agent', 'hi');
        $turns = $s->recentTurns(self::A, $id, 10);
        $this->assertSame(['hello', 'hi'], array_column($turns, 'text'));
        $this->assertSame([], $s->recentTurns(self::B, $id, 10));
        $this->assertSame(1, $s->activeCalls(self::A));
        $this->assertSame(0, $s->activeCalls(self::B));
        $this->assertGreaterThanOrEqual(1, VoicePlatformLoad::activeCalls());
    }

    public function testCustomerRecognitionMatchesAcrossPhoneFormatsAndNeverGuessesOnAmbiguity(): void
    {
        $s = new VoiceDataStore();
        $c = $this->contact(self::A, 'Sara', '+20 (100) 123-4567', 'sara@example.com');
        $ctx = $s->customerContext(self::A, '+201001234567');
        $this->assertTrue($ctx['known']);
        $this->assertSame($c, $ctx['contact_id']);
        $this->assertFalse($s->customerContext(self::B, '+201001234567')['known']);       // other company's customer is never exposed
        $this->assertFalse($s->customerContext(self::A, '+201009999999')['known']);
        $this->contact(self::A, 'Sara twin', '01001234567');                              // shared line: two contacts, same last nine digits
        $this->assertFalse($s->customerContext(self::A, '+201001234567')['known']);
        $this->assertFalse($s->customerContext(self::A, '+1')['known']);
    }

    public function testTripSearchEscapesWildcardsAndAvailabilityUsesInventoryAndPriceOverride(): void
    {
        $s = new VoiceDataStore();
        $luxor = $this->product(self::A, 'Luxor Day Trip', 120.0);
        $this->product(self::A, '100% Aswan', 90.0);
        $this->product(self::B, 'Luxor Secret', 1.0);
        $this->assertSame(['Luxor Day Trip'], array_column($s->searchTrips(self::A, 'luxor'), 'name'));     // never the other company's trips
        $this->assertSame(['100% Aswan'], array_column($s->searchTrips(self::A, '100%'), 'name'));
        $this->assertCount(2, $s->searchTrips(self::A, ''));
        $this->assertCount(2, $s->searchTrips(self::A, '%'));                                                 // words shorter than 2 characters are ignored, so this is just "no filter"
        $this->assertSame([], $s->searchTrips(self::A, '%%'));                                                // an escaped wildcard is a literal character, not "match everything"

        $day = date('Y-m-d', strtotime('+30 days'));
        (new InventoryService())->setDay(self::A, $luxor, $day, 4, 99.0);
        $a = $s->tripAvailability(self::A, $luxor, $day, 3);
        $this->assertTrue($a['available']);
        $this->assertSame(99.0, $a['unit_price']);
        $this->assertSame(297.0, $a['total_price']);
        $this->assertFalse($s->tripAvailability(self::A, $luxor, $day, 5)['available']);
        $this->assertSame('not_enough_capacity', $s->tripAvailability(self::A, $luxor, $day, 5)['reason']);
        $this->assertFalse($s->tripAvailability(self::A, $luxor, date('Y-m-d', strtotime('-2 days')), 1)['available']);
        $this->assertFalse($s->tripAvailability(self::A, $luxor, '2026-13-45', 1)['available']);
        $this->assertSame(['found' => false, 'reason' => 'unknown_trip'], $s->tripAvailability(self::B, $luxor, $day, 1));
    }

    // ------------------------------------------------------------------ numbers

    public function testNobodyCanSquatANumberAndTheDefaultAlwaysExists(): void
    {
        $svc = new VoiceNumberService();
        $agent = $this->agent(self::A);
        $r = $svc->connect(self::A, self::A, '+20 100 123 4567', 'forward', null, null, 'mobile');
        $this->assertSame('pending_verification', $r['status']);
        $svc->connect(self::B, self::B, '+201001234567', 'forward');                        // an unproven claim does not block the real owner
        $row = $this->db()->query('SELECT live_key, number_type, is_default, country FROM voice_numbers WHERE tenant_user_id = ?', [self::A])[0];
        $this->assertNull($row['live_key']);
        $this->assertSame('mobile', $row['number_type']);
        $this->assertSame(1, (int) $row['is_default']);                                      // the first number becomes the default
        $this->assertSame('EG', $row['country']);
        try {
            $svc->connect(self::A, self::A, '+201001234567', 'forward');
            $this->fail('duplicate for the same company must be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already registered', $e->getMessage());
        }
        // a number another company proved live is taken
        $this->number(self::B, '+442071234567', ['live_key' => '+442071234567']);
        $this->expectException(InvalidArgumentException::class);
        $svc->connect(self::A, self::A, '+442071234567', 'forward');
    }

    public function testSandboxPurchaseCreatesASimulatedDefaultNumberBoundToTheAssistantAndReleasePromotesAnother(): void
    {
        $this->settings(self::A, ['enabled' => true]);                                       // sandbox stays on: nothing real happens
        $agent = $this->agent(self::A);
        $order = function (string $e164) {
            $this->db()->exec("INSERT INTO voice_number_orders (tenant_user_id, provider, e164, country, number_type, monthly_cost, setup_cost, first_charge, provider_monthly_cost, provider_setup_cost, currency, requirement_level, needs_ack, status, quoted_at) VALUES (?,?,?,?,?,?,?,?,?,?, 'USD','none_typical',0,'quoted',NOW())", [self::A, 'twilio', $e164, 'US', 'local', 1.38, 0, 1.38, 1.15, 0]);
            return (int) $this->db()->getConnection()->lastInsertId();
        };
        $svc = new VoiceNumberService();
        $a = $svc->approve(self::A, self::A, $order('+15125550101'), '1.38', false, [], $agent);
        $this->assertTrue($a['simulated']);
        $this->assertSame('simulated', $a['status']);
        $b = $svc->approve(self::A, self::A, $order('+15125550102'), 1.38, false);
        $rows = $this->db()->query('SELECT id, e164, is_default, agent_id, live_key, status FROM voice_numbers WHERE tenant_user_id = ? ORDER BY id', [self::A]);
        $this->assertSame([1, 0], array_map(fn ($r) => (int) $r['is_default'], $rows));
        $this->assertSame($agent, (int) $rows[0]['agent_id']);
        $this->assertNull($rows[0]['live_key']);                                              // a simulated number holds no real claim
        $this->assertSame('completed', $this->db()->query('SELECT status FROM voice_number_orders WHERE id = ?', [1 + (int) $this->db()->query('SELECT MIN(id) m FROM voice_number_orders WHERE tenant_user_id = ' . self::A)[0]['m'] - 1])[0]['status']);

        $svc->setDefault(self::A, self::A, $b['number_id']);
        $this->assertSame([0, 1], array_map(fn ($r) => (int) $r['is_default'], $this->db()->query('SELECT is_default FROM voice_numbers WHERE tenant_user_id = ? ORDER BY id', [self::A])));
        $svc->release(self::A, self::A, $b['number_id'], false);                              // releasing the default promotes the remaining number
        $left = $this->db()->query('SELECT id, is_default FROM voice_numbers WHERE tenant_user_id = ?', [self::A]);
        $this->assertCount(1, $left);
        $this->assertSame(1, (int) $left[0]['is_default']);
        try {
            $svc->approve(self::A, self::A, 999999, 1.38, false);
            $this->fail('unknown order');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Order not found', $e->getMessage());
        }
        $this->assertSame(0, count($svc->list(self::B)));
    }

    public function testApprovalNeedsTheExactQuotedAmountAndAnOrderCanOnlyBeUsedOnce(): void
    {
        $this->settings(self::A, ['enabled' => true]);
        $this->db()->exec("INSERT INTO voice_number_orders (tenant_user_id, provider, e164, country, number_type, monthly_cost, setup_cost, first_charge, currency, requirement_level, needs_ack, status, quoted_at) VALUES (?, 'twilio', '+15125550111', 'US', 'local', 1.38, 0, 1.38, 'USD', 'none_typical', 1, 'quoted', NOW())", [self::A]);
        $id = (int) $this->db()->getConnection()->lastInsertId();
        $svc = new VoiceNumberService();
        foreach ([[1.37, true], [1.38, false]] as [$amount, $ack]) {                          // wrong amount, then requirements not acknowledged
            try {
                $svc->approve(self::A, self::A, $id, $amount, $ack);
                $this->fail('must refuse');
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $svc->approve(self::A, self::A, $id, 1.38, true);
        $this->expectExceptionMessage('already processed');
        $svc->approve(self::A, self::A, $id, 1.38, true);
    }

    public function testRoutingRulesOnlyAcceptTheCompanysOwnAssistants(): void
    {
        $mine = $this->agent(self::A);
        $theirs = $this->agent(self::B);
        $n = $this->number(self::A, '+15125550120');
        $svc = new VoiceNumberService();
        $r = $svc->setRouting(self::A, self::A, $n, [['when' => ['after_hours' => true], 'agent_id' => $mine]]);
        $this->assertSame($mine, $r['rules'][0]['agent_id']);
        $this->assertSame('after_hours', array_key_first(json_decode($this->db()->query('SELECT routing_json FROM voice_numbers WHERE id = ?', [$n])[0]['routing_json'], true)[0]['when']));
        $this->expectException(InvalidArgumentException::class);
        $svc->setRouting(self::A, self::A, $n, [['when' => ['after_hours' => true], 'agent_id' => $theirs]]);
    }

    // ------------------------------------------------------------------ consent, DNC, outbound

    public function testConsentNeedsEvidenceAndADoNotCallEntryBlocksAndCancelsPendingJobs(): void
    {
        $c = new VoiceConsentService();
        try {
            $c->grantMarketing(self::A, self::A, '+201001234567', 'web_form', 'x');
            $this->fail('evidence required');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('how and when', $e->getMessage());
        }
        $c->grantMarketing(self::A, self::A, '+20 100 123 4567', 'web_form', 'Ticked the offers box on the booking form 2026-10-01');
        $this->assertTrue($c->hasMarketingConsent(self::A, '+201001234567'));
        $this->assertFalse($c->hasMarketingConsent(self::B, '+201001234567'));
        $this->db()->exec("INSERT INTO voice_outbound_jobs (tenant_user_id, number_id, agent_id, to_e164, phone_key, purpose, category, status, next_attempt_at, created_by_user_id, created_at, updated_at) VALUES (?,1,1,'+201001234567','001234567','marketing','marketing','scheduled',NOW(),?,NOW(),NOW())", [self::A, self::A]);
        $c->optOut(self::A, '+201001234567', 7);
        $this->assertTrue($c->isDnc(self::A, '+201001234567'));
        $this->assertFalse($c->isDnc(self::B, '+201001234567'));
        $this->assertFalse($c->hasMarketingConsent(self::A, '+201001234567'));                // consent withdrawn by the opt-out
        $this->assertSame('cancelled', $this->db()->query('SELECT status FROM voice_outbound_jobs WHERE tenant_user_id = ?', [self::A])[0]['status']);
        try {
            $c->grantMarketing(self::A, self::A, '+201001234567', 'web_form', 'Ticked the offers box again');
            $this->fail('a DNC entry must be lifted deliberately first');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('do-not-call', $e->getMessage());
        }
        $this->db()->exec('INSERT INTO voice_dnc (tenant_user_id, phone_key, e164, reason, source, created_at) VALUES (0, ?, ?, \'phpunit\', \'platform\', NOW())', [VoiceConsentService::key('+15125550111'), '+15125550111']);
        $this->assertTrue($c->isDnc(self::B, '+15125550111'));                                  // a platform-wide entry blocks every company
        $c->removeDnc(self::B, self::B, '+15125550111');
        $this->assertTrue($c->isDnc(self::B, '+15125550111'));                                  // and a company cannot remove it
    }

    private function outboundCompany(): array
    {
        $this->settings(self::A, ['enabled' => true, 'outbound_enabled' => true]);              // sandbox stays on
        $agent = $this->agent(self::A);
        $num = $this->number(self::A, '+442071230000', ['agent_id' => $agent, 'is_default' => 1]);
        $contact = $this->contact(self::A, 'Sara El-Sayed', '+20 100 123 4567', 'sara@example.com');
        $product = $this->product(self::A);
        return [$agent, $num, $contact, $product];
    }

    private function booking(int $product, int $contact, string $status = 'confirmed', string $date = '+1 day'): int
    {
        $day = date('Y-m-d', strtotime($date));
        $this->db()->exec("INSERT INTO bookings (booking_reference, user_id, product_id, customer_id, customer_name, customer_phone, start_date, adults_count, total_amount, currency, status, source) VALUES (?,?,?,?,?,?,?,2,240.00,'USD',?, 'voice_ai')",
            ['TF-' . substr(md5((string) mt_rand()), 0, 8), self::A, $product, $contact, 'Sara El-Sayed', '+201001234567', $day, $status]);
        return (int) $this->db()->getConnection()->lastInsertId();
    }

    public function testOutboundQueueingAppliesLawfulBasisAndRunsAsASimulationInSandbox(): void
    {
        [$agent, $num, $contact, $product] = $this->outboundCompany();
        $svc = new VoiceOutboundService();
        $noon = (new DateTimeImmutable('2026-10-28 12:00', new DateTimeZone('Africa/Cairo')))->getTimestamp();     // a Wednesday inside calling hours, in the past
        $base = ['purpose' => 'trip_reminder', 'to' => '+201001234567', 'number_id' => $num, 'agent_id' => $agent, 'contact_id' => $contact, 'country' => 'EG', 'context' => ['first_name' => 'Sara', 'trip_name' => 'Luxor']];

        try {
            $svc->enqueue(self::A, self::A, $base, $noon);
            $this->fail('no open booking: no lawful basis');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('lawful basis', $e->getMessage());
        }
        $this->booking($product, $contact);
        $job = $svc->enqueue(self::A, self::A, $base + ['source_ref' => 'booking:1:trip_reminder'], $noon);
        $again = $svc->enqueue(self::A, self::A, $base + ['source_ref' => 'booking:1:trip_reminder'], $noon);
        $this->assertSame($job['job_id'], $again['job_id']);                                      // one job per source
        $this->assertTrue($again['existing']);
        try {
            $svc->enqueue(self::A, self::A, ['purpose' => 'marketing'] + $base, $noon);
            $this->fail('marketing needs consent and an enabled country');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('not enabled', $e->getMessage());
        }

        $sum = $svc->runDue(5, $noon);
        $this->assertSame(1, $sum['processed']);
        $this->assertSame(1, $sum['simulated']);
        $row = $this->db()->query('SELECT status, last_result, attempts FROM voice_outbound_jobs WHERE id = ?', [$job['job_id']])[0];
        $this->assertSame(['completed', 'simulated', 1], [$row['status'], $row['last_result'], (int) $row['attempts']]);
        $att = $this->db()->query('SELECT simulated FROM voice_outbound_attempts WHERE job_id = ?', [$job['job_id']]);
        $this->assertSame([1], array_map(fn ($r) => (int) $r['simulated'], $att));               // recorded as simulated: it never counts against real daily limits
        $this->assertSame(0, (int) $this->db()->query('SELECT COUNT(*) n FROM voice_calls WHERE tenant_user_id = ?', [self::A])[0]['n']);
    }

    public function testADoNotCallEntryAddedAfterQueueingBlocksTheJobAtDialTime(): void
    {
        [$agent, $num, $contact, $product] = $this->outboundCompany();
        $this->booking($product, $contact);
        $svc = new VoiceOutboundService();
        $noon = (new DateTimeImmutable('2026-10-28 12:00', new DateTimeZone('Africa/Cairo')))->getTimestamp();
        $job = $svc->enqueue(self::A, self::A, ['purpose' => 'booking_confirmation', 'to' => '+201001234567', 'number_id' => $num, 'agent_id' => $agent, 'contact_id' => $contact, 'country' => 'EG'], $noon);
        (new VoiceConsentService())->addDnc(self::A, self::A, '+201001234567', 'manual', 'asked by email');
        // addDnc already cancels pending jobs; re-open one to prove the dial-time check on its own
        $this->db()->exec("UPDATE voice_outbound_jobs SET status = 'scheduled', blocked_reason = NULL WHERE id = ?", [$job['job_id']]);
        $svc->runDue(5, $noon);
        $row = $this->db()->query('SELECT status, blocked_reason FROM voice_outbound_jobs WHERE id = ?', [$job['job_id']])[0];
        $this->assertSame(['blocked', 'do_not_call_list'], [$row['status'], $row['blocked_reason']]);
    }

    public function testCampaignsRunEveryRecipientThroughTheSameChecksAndStayInsideTheCompany(): void
    {
        [$agent, $num, $contact, $product] = $this->outboundCompany();
        $svc = new VoiceCampaignService();
        $id = $svc->create(self::A, self::A, ['name' => 'Tomorrow reminders', 'purpose' => 'trip_reminder', 'number_id' => $num, 'agent_id' => $agent, 'min_gap_hours' => 6, 'tz' => 'Africa/Cairo']);
        $this->booking($product, $contact);
        $r = $svc->addRecipients(self::A, self::A, $id, ['+201001234567', '+201001234567', 'nonsense', '+201005550000']);
        $this->assertSame(1, $r['added']);
        $reasons = array_column($r['rejected'], 'reason');
        $this->assertContains('duplicate_in_list', $reasons);
        $this->assertContains('invalid_number', $reasons);
        $this->assertTrue((bool) array_filter($reasons, fn ($x) => str_contains($x, 'lawful basis')));      // the second number has no booking
        $this->assertSame(6, (int) $this->db()->query('SELECT min_gap_hours FROM voice_outbound_jobs WHERE campaign_id = ?', [$id])[0]['min_gap_hours']);
        $stats = $svc->stats(self::A, $id);
        $this->assertSame(1, $stats['total']);
        $svc->setStatus(self::A, self::A, $id, 'cancel');
        $this->assertSame('cancelled', $this->db()->query('SELECT status FROM voice_outbound_jobs WHERE campaign_id = ?', [$id])[0]['status']);
        $this->expectException(InvalidArgumentException::class);
        $svc->stats(self::B, $id);                                                                          // another company cannot even see it
    }

    public function testBookingsDriveAutomaticFollowUpsButNeverDuplicateThem(): void
    {
        [$agent, $num, $contact, $product] = $this->outboundCompany();
        $this->settings(self::A, ['auto_trip_reminders' => true, 'auto_abandoned_recovery' => true]);
        $tomorrow = $this->booking($product, $contact, 'confirmed', '+1 day');
        $this->booking($product, $contact, 'pending', '+10 days');                              // created just now: not "abandoned" yet (needs 2 hours)
        $svc = new VoiceAutoFollowUps();
        $cands = $svc->candidates(self::A, 'reminder');
        $this->assertSame([$tomorrow], array_map(fn ($r) => (int) $r['id'], $cands));
        $this->assertSame([], $svc->candidates(self::A, 'abandoned'));
        $this->assertSame([], $svc->candidates(self::B, 'reminder'));                           // other company's bookings are never candidates
        $this->db()->exec("UPDATE bookings SET created_at = NOW() - INTERVAL 5 HOUR WHERE user_id = ? AND status = 'pending'", [self::A]);
        $this->assertCount(1, $svc->candidates(self::A, 'abandoned'));
        $this->assertTrue($this->db()->exec("INSERT INTO payment_transactions (internal_transaction_id, user_id, booking_id, amount, currency, payment_method, gateway, status) SELECT CONCAT('voice-test-', id), ?, id, 10, 'USD', 'card', 'stripe', 'succeeded' FROM bookings WHERE user_id = ? AND status = 'pending'", [self::A, self::A]));
        $this->assertSame([], $svc->candidates(self::A, 'abandoned'));                         // paid: not abandoned
        $this->db()->exec('DELETE FROM payment_transactions WHERE user_id = ?', [self::A]);
    }

    // ------------------------------------------------------------------ post-call, analytics, supervisor, platform

    public function testPostCallActionsCreateRealCrmRecordsOnceAndOnlyInsideTheCompany(): void
    {
        $this->settings(self::A, ['enabled' => true]);
        $s = new VoiceDataStore();
        $callId = $s->createCall(self::A, ['id' => 1], null, 'twilio', 'CApost1', '+201007770000', '+15125550100', 'turn', 'en', null);
        $s->updateCall(self::A, $callId, ['turns' => 3, 'outcome' => 'lead_captured', 'qualification' => json_encode(['name' => 'Omar Hassan', 'email' => 'omar@example.com', 'interest' => 'Luxor', 'intent_level' => 'hot']), 'ended_at' => date('Y-m-d H:i:s'), 'duration_s' => 95]);
        $s->addTurn(self::A, $callId, 100, 'caller', 'I want a trip to Luxor');
        $s->addTurn(self::A, $callId, 190, 'agent', 'Happy to help');
        $s->addTurn(self::A, $callId, 200, 'caller', 'Four people in December');
        $svc = new VoicePostCallService($s, new VoicePostCallCrmGateway(), fn ($p, $o) => ['success' => true, 'data' => json_encode(['summary' => 'Omar wants a Luxor trip for four in December.', 'needs' => ['Luxor trip'], 'next_step' => 'Send prices', 'follow_up_needed' => true, 'urgency' => 'normal']), 'cost' => 0.0003]);
        $r = $svc->process(self::A, $callId);
        $this->assertSame('done', $r['contact']);
        $this->assertSame('done', $r['lead']);
        $this->assertSame('done', $r['task']);
        $contact = $this->db()->query('SELECT id, name, email, source FROM crm_contacts WHERE user_id = ?', [self::A]);
        $this->assertCount(1, $contact);
        $this->assertSame(['Omar Hassan', 'omar@example.com', 'voice_ai'], [$contact[0]['name'], $contact[0]['email'], $contact[0]['source']]);
        $this->assertSame(1, (int) $this->db()->query('SELECT COUNT(*) n FROM crm_leads WHERE contact_id = ?', [$contact[0]['id']])[0]['n']);
        $this->assertSame(1, (int) $this->db()->query('SELECT COUNT(*) n FROM crm_tasks WHERE user_id = ?', [self::A])[0]['n']);
        $this->assertSame(1, (int) $this->db()->query('SELECT COUNT(*) n FROM crm_notes WHERE user_id = ?', [self::A])[0]['n']);
        $this->assertSame(0, (int) $this->db()->query('SELECT COUNT(*) n FROM crm_contacts WHERE user_id = ?', [self::B])[0]['n']);
        $this->assertSame('done', $s->getCall(self::A, $callId)['post_call_status']);
        $this->assertSame(['skipped' => 'already_processed'], $svc->process(self::A, $callId));
        $this->assertSame(1, (int) $this->db()->query('SELECT COUNT(*) n FROM crm_tasks WHERE user_id = ?', [self::A])[0]['n']);
    }

    private function seedAnalytics(): array
    {
        $s = new VoiceDataStore();
        $product = $this->product(self::A);
        $contact = $this->contact(self::A, 'Sara', '+201001234567');
        $b1 = $this->booking($product, $contact, 'confirmed');
        $in1 = $s->createCall(self::A, ['id' => 1], null, 'twilio', 'CAa1', '+201001234567', '+15125550100', 'turn', 'en', null);
        $in2 = $s->createCall(self::A, ['id' => 1], null, 'twilio', 'CAa2', '+201002222222', '+15125550100', 'turn', 'en', null);
        $out = $s->createCall(self::A, ['id' => 1], null, 'twilio', 'CAa3', '+15125550100', '+201003333333', 'turn', 'en', null, 'outbound', null);
        $s->updateCall(self::A, $in1, ['status' => 'completed', 'duration_s' => 120, 'turns' => 6, 'booking_id' => $b1, 'ai_cost_usd_add' => 0.05, 'latency_ms_add' => 900, 'outcome' => 'lead_captured', 'utm_link_id' => 77, 'attribution_source' => 'google_ads', 'qualification' => json_encode(['survey_score' => 5]), 'booking_request' => json_encode(['product_id' => $product])]);
        $s->updateCall(self::A, $in2, ['status' => 'missed', 'duration_s' => 3]);
        $s->updateCall(self::A, $out, ['status' => 'completed', 'duration_s' => 60, 'turns' => 3, 'qualification' => json_encode(['survey_score' => 2])]);
        $this->db()->exec("INSERT INTO voice_call_costs (tenant_user_id, provider, call_id, direction, duration_s, cost_usd, cost_source, created_at, updated_at) VALUES (?, 'twilio','CAa1','inbound',120,0.10,'provider',NOW(),NOW()), (?, 'twilio','CAa3','outbound',60,0.20,'provider',NOW(),NOW())", [self::A, self::A]);
        $this->number(self::A, '+15125550100');                                                   // rented for 10 days at $2.00/month
        $this->db()->exec("INSERT INTO voice_call_reviews (tenant_user_id, call_id, source, quality_score, issues, loss_reason, objections, suggestions, transcript_hash, created_at) VALUES (?,?, 'model', 4, '[\"missed_upsell\"]', 'price', '[\"price_too_high\",\"wants_discount\"]', '[]', 'h1', NOW()), (?,?, 'model', 2, '[\"ungrounded_price\"]', 'price', '[\"price_too_high\"]', '[]', 'h2', NOW())", [self::A, $in1, self::A, $in2]);
        return [$in1, $in2, $out, $product];
    }

    public function testAnalyticsAddUpCalls_Money_Satisfaction_Attribution_AndNeverMixCompanies(): void
    {
        [, , , $product] = $this->seedAnalytics();
        $a = (new VoiceAnalyticsService())->overview(self::A, null, null);
        $this->assertSame(3, $a['calls']['total']);
        $this->assertSame([2, 1, 1], [$a['calls']['inbound'], $a['calls']['outbound'], $a['calls']['missed']]);
        $this->assertSame(183, $a['calls']['seconds']);
        $this->assertSame(0, $a['leads']);                                                        // no CRM lead was linked to these calls
        $this->assertSame(1, $a['bookings']['count']);
        $this->assertSame(240.0, $a['bookings']['by_currency']['USD']['confirmed']);
        $this->assertEqualsWithDelta(0.30 + 0.05 + 2.0 * 10 / 30, $a['cost']['total_usd'], 0.1);       // rental is prorated up to the end of today, so a little over 10 days
        $this->assertSame(240.0, $a['roi']['revenue']);
        $this->assertGreaterThan(1000, $a['roi']['roi_pct']);
        $this->assertSame(2, $a['satisfaction']['responses']);
        $this->assertSame(3.5, $a['satisfaction']['average']);
        $this->assertSame(0.5, $a['satisfaction']['csat']);
        $this->assertSame('price_too_high', $a['objections'][0]['key']);
        $this->assertSame(2, $a['objections'][0]['count']);
        $this->assertSame('price', $a['loss_reasons'][0]['key']);
        $this->assertSame(3.0, $a['assistant']['quality_avg']);
        $this->assertSame(900, $a['assistant']['avg_reply_ms']);
        $this->assertSame([$product], array_column($a['top_trips'], 'product_id'));
        $this->assertSame([['source' => 'google_ads', 'utm_link_id' => 77, 'calls' => 1, 'bookings' => 1, 'confirmed_revenue' => 240.0, 'currency' => 'USD']], $a['attribution']);
        $b = (new VoiceAnalyticsService())->overview(self::B, null, null);
        $this->assertSame(0, $b['calls']['total']);
        $this->assertNull((new VoiceInsightsService())->snapshotFor(self::B));
        $snap = (new VoiceInsightsService())->snapshotFor(self::A);
        $this->assertSame(3, $snap['calls']);
        $this->assertNotEmpty((new VoiceAnalyticsService())->reviews(self::A));
        $this->assertSame([], (new VoiceAnalyticsService())->reviews(self::B));
    }

    public function testSupervisorReviewsOnlyWhatDeservesItWithinCapAndOnlyOnce(): void
    {
        $this->settings(self::A, ['enabled' => true, 'supervisor_sample_pct' => 0, 'supervisor_daily_cap' => 1]);
        $s = new VoiceDataStore();
        $mk = function (string $sid, array $meta, string $text) use ($s) {
            $id = $s->createCall(self::A, ['id' => 1], null, 'twilio', $sid, '+201001234567', '+15125550100', 'turn', 'en', null);
            $s->updateCall(self::A, $id, ['turns' => 2, 'ended_at' => date('Y-m-d H:i:s'), 'duration_s' => 60, 'post_call_status' => 'done', 'status' => 'completed']);
            $s->addTurn(self::A, $id, 100, 'caller', 'How much is Luxor?');
            $s->addTurn(self::A, $id, 190, 'agent', $text, $meta);
            return $id;
        };
        $plain = $mk('CAs1', [], 'It is a full day trip.');
        $flagged1 = $mk('CAs2', ['price_guard' => true], 'Let me check that for you.');
        $flagged2 = $mk('CAs3', ['price_guard' => true], 'Let me check that for you.');
        $calls = 0;
        $llm = function ($p, $o) use (&$calls) {
            $calls++;
            return ['success' => true, 'cost' => 0.0006, 'data' => json_encode(['quality' => 3, 'issues' => ['missed_upsell', 'nonsense'], 'loss_reason' => 'price', 'objections' => ['price_too_high'], 'suggestions' => ['Offer the family discount']])];
        };
        $sup = new VoiceSupervisorService($s, $llm);
        $r = $sup->run(10);
        $this->assertSame(1, $calls);                                                       // plain call: no model; flagged #1: model; flagged #2: daily cap reached
        $this->assertSame(3, $r['reviewed']);
        $rows = $this->db()->query('SELECT call_id, source, quality_score, issues FROM voice_call_reviews WHERE tenant_user_id = ? ORDER BY call_id', [self::A]);
        $bySrc = array_column($rows, 'source', 'call_id');
        $this->assertSame('rules', $bySrc[$plain]);
        $this->assertSame('model', $bySrc[$flagged1]);
        $this->assertSame('rules', $bySrc[$flagged2]);
        $issues = json_decode(array_column($rows, 'issues', 'call_id')[$flagged1], true);
        $this->assertContains('ungrounded_price', $issues);
        $this->assertContains('missed_upsell', $issues);
        $this->assertNotContains('nonsense', $issues);
        $this->assertContains('ungrounded_price', json_decode(array_column($rows, 'issues', 'call_id')[$flagged2], true));   // the free rules still record the warning sign
        $sup->run(10);
        $this->assertSame(1, $calls);                                                       // nothing is reviewed twice
        $this->assertSame(0.0006, round((float) $s->getCall(self::A, $flagged1)['ai_cost_usd'], 4));
    }

    public function testStrategicInsightsReachTheAiCeoTablesWithoutDuplicates(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->db()->exec("INSERT INTO voice_call_reviews (tenant_user_id, call_id, source, quality_score, issues, loss_reason, objections, suggestions, transcript_hash, created_at) VALUES (?,?, 'model', 3, ?, 'price', '[\"price_too_high\"]', '[]', ?, NOW())",
                [self::A, 5000 + $i, $i < 3 ? '["ungrounded_price"]' : '[]', "h$i"]);
        }
        $svc = new VoiceInsightsService();
        $this->assertSame(3, $svc->push(self::A));                                          // objection + loss reason (opportunities) and ungrounded prices (risk)
        $this->assertSame(0, $svc->push(self::A));                                          // same week: not repeated
        $this->assertSame(1, (int) $this->db()->query("SELECT COUNT(*) n FROM ceo_risk_alerts WHERE user_id = ? AND source_module = 'voice'", [self::A])[0]['n']);
        $this->assertSame(2, (int) $this->db()->query('SELECT COUNT(*) n FROM ceo_growth_opportunities WHERE user_id = ?', [self::A])[0]['n']);
        $this->assertSame(0, $svc->push(self::B));
    }

    public function testPlatformAndAdminReportsAggregateAcrossCompaniesWithoutLeakingDetail(): void
    {
        $this->seedAnalytics();
        $this->db()->exec("INSERT INTO voice_provider_errors (tenant_user_id, provider, operation, http_code, provider_code, message, created_at) VALUES (?, 'twilio', 'search_numbers', 401, '20003', 'Authenticate', NOW())", [self::B]);
        $sum = VoicePlatformLoad::summary(30);
        $this->assertGreaterThanOrEqual(3, $sum['calls']);
        $this->assertGreaterThanOrEqual(1, $sum['active_companies']);
        $this->assertGreaterThanOrEqual(1, $sum['provider_errors']);
        $this->assertSame(['period_days', 'calls', 'active_companies', 'minutes', 'missed_rate', 'provider_call_cost_usd', 'ai_cost_usd', 'numbers_in_service', 'monthly_number_rent_usd', 'provider_errors'], array_keys($sum));
        $admin = new VoiceAdminReports();
        $costs = $admin->costs(date('Y-m'));
        $mine = array_values(array_filter($costs['companies'], fn ($c) => $c['tenant_user_id'] === self::A));
        $this->assertCount(1, $mine);
        $this->assertEqualsWithDelta(0.10, $mine[0]['inbound_calls_usd'], 0.0001);
        $this->assertEqualsWithDelta(0.20, $mine[0]['outbound_calls_usd'], 0.0001);
        $this->assertContains(self::B, array_column($admin->errors(50), 'tenant_user_id'));
        $providers = array_column($admin->providers(), null, 'provider');
        $this->assertArrayHasKey('twilio', $providers);
        $this->assertSame(['TWILIO_VOICE_ACCOUNT_SID', 'TWILIO_VOICE_AUTH_TOKEN'], VoicePlatformCredentials::envKeys('twilio'));
    }

    public function testStaffAreScopedToTheirCompanyAndOnlyActiveOnesRoute(): void
    {
        $svc = new VoiceStaffService();
        $id = $svc->create(self::A, self::A, ['name' => 'Omar', 'phone' => '+201001230000', 'topics' => 'billing']);
        $svc->create(self::A, self::A, ['name' => 'Off', 'phone' => '+201001230001', 'active' => false]);
        $this->assertCount(2, $svc->list(self::A));
        $this->assertCount(1, $svc->forRouting(self::A));
        $this->assertSame([], $svc->list(self::B));
        $this->assertFalse($svc->update(self::B, self::B, $id, ['name' => 'Hijack', 'phone' => '+201001230000']));
        $this->assertTrue($svc->update(self::A, self::A, $id, ['name' => 'Omar K', 'phone' => '+201001230000']));
        $svc->delete(self::B, self::B, $id);                                                  // another company's delete is a no-op
        $this->assertCount(2, $svc->list(self::A));
        $svc->delete(self::A, self::A, $id);
        $this->assertCount(1, $svc->list(self::A));
    }

    public function testInboundWebhookDedupeAndCostsAreIdempotentPerCompany(): void
    {
        VoiceCostTracker::record(self::A, 'twilio', 'CAcost', 'inbound', 60, null, 'none');
        VoiceCostTracker::record(self::A, 'twilio', 'CAcost', 'inbound', null, 0.0140, 'provider');        // the real price arrives later and replaces the empty record
        VoiceCostTracker::record(self::A, 'twilio', 'CAcost', 'inbound', 60, null, 'none');                 // a later duplicate must not erase it
        $row = $this->db()->query('SELECT duration_s, cost_usd, cost_source FROM voice_call_costs WHERE call_id = ?', ['CAcost']);
        $this->assertCount(1, $row);
        $this->assertSame(['60', '0.0140', 'provider'], [(string) $row[0]['duration_s'], $row[0]['cost_usd'], $row[0]['cost_source']]);
        $m = VoiceCostTracker::monthSummary(self::A, 0.01);
        $this->assertTrue($m['over_budget']);
        $this->assertSame(0.0, VoiceCostTracker::monthSummary(self::B, 10.0)['month_usd']);
    }

    public function testLedgerBudgetAlertsFireOnceAndBlockPolicyIsHonoured(): void
    {
        $usage = new VoiceUsageService();
        $now = gmdate('Y-m-d H:i:s');
        $usage->upsert(self::A, 'call:1', 'inbound_minutes', 2, 'minute', 0.5, 0.6, 'estimated', $now);
        $usage->upsert(self::A, 'call:1', 'inbound_minutes', 2, 'minute', 0.8, 0.96, 'provider', $now); // the real price replaces the estimate
        $usage->upsert(self::B, 'call:1', 'inbound_minutes', 2, 'minute', 9, 9, 'provider', $now);
        $m = $usage->month(self::A);
        $this->assertSame(0.96, $m['price_usd']);
        $this->assertSame(0.8, $m['cost_usd']);
        $settings = ['monthly_budget_usd' => 1.0, 'budget_policy' => 'block', 'daily_minutes_cap' => 0];
        $svc = new VoiceBudgetService($usage);
        $this->assertSame('warning', $svc->state(self::A, $settings)['state']);
        $this->assertNull($svc->blockReason(self::A, $settings));
        $svc->blockReason(self::A, $settings); // second check must not repeat the alert
        $this->assertSame(1, (int) $this->db()->query('SELECT COUNT(*) AS n FROM voice_budget_alerts WHERE tenant_user_id = ?', [self::A])[0]['n']);
        $usage->upsert(self::A, 'call:2', 'inbound_minutes', 1, 'minute', 0.1, 0.2, 'provider', $now);
        $this->assertSame('budget_reached', $svc->blockReason(self::A, $settings));
        $this->assertNull($svc->blockReason(self::A, ['budget_policy' => 'warn'] + $settings)); // warn only
        $this->assertNull($svc->blockReason(self::B, array_merge($settings, ['monthly_budget_usd' => 50.0]))); // other company untouched
    }

    public function testInvoiceIsOnePerMonthAndNeverChargesWhileBillingIsOff(): void
    {
        $usage = new VoiceUsageService();
        $usage->upsert(self::A, 'call:1', 'outbound_minutes', 1, 'minute', 1, 1.25, 'provider', '2026-09-10 10:00:00');
        $billing = new VoiceBillingService($usage);
        $billing->invoice(self::A, '2026-09');
        $billing->invoice(self::A, '2026-09');
        $this->assertSame(1, (int) $this->db()->query('SELECT COUNT(*) AS n FROM voice_invoices WHERE tenant_user_id = ?', [self::A])[0]['n']);
        $this->assertSame('skipped_disabled', $billing->settle(self::A, '2026-09'));
        $this->assertSame('open', $billing->statement(self::A, '2026-09')['invoice']['status']);
        $this->assertSame(0.0, $billing->statement(self::B, '2026-09')['total_usd']);
    }

    public function testErasingAPhoneRemovesTranscriptsButKeepsDoNotCall(): void
    {
        $this->db()->exec("INSERT INTO voice_calls (tenant_user_id, provider, call_sid, direction, from_e164, to_e164, mode, summary, started_at) VALUES (?, 'twilio', 'CAerase', 'inbound', '+201001234567', '+2025550100', 'turn', 'secret summary', NOW())", [self::A]);
        $id = (int) $this->db()->query("SELECT id FROM voice_calls WHERE call_sid = 'CAerase' AND tenant_user_id = ?", [self::A])[0]['id'];
        $this->db()->exec("INSERT INTO voice_call_turns (tenant_user_id, call_id, seq, role, text, created_at) VALUES (?,?,1,'caller','my passport number',NOW())", [self::A, $id]);
        $this->db()->exec("INSERT INTO voice_dnc (tenant_user_id, phone_key, e164, reason, created_at) VALUES (?, ?, '+201001234567', 'phpunit', NOW())", [self::A, VoiceDataStore::phoneKey('+201001234567')]);
        $r = (new VoiceRetentionService())->erasePhone(self::A, '+201001234567');
        $this->assertSame(1, $r['calls']);
        $this->assertSame(0, (int) $this->db()->query('SELECT COUNT(*) AS n FROM voice_call_turns WHERE call_id = ?', [$id])[0]['n']);
        $this->assertNull($this->db()->query('SELECT summary FROM voice_calls WHERE id = ?', [$id])[0]['summary']);
        $this->assertSame(1, (int) $this->db()->query('SELECT COUNT(*) AS n FROM voice_dnc WHERE tenant_user_id = ?', [self::A])[0]['n']);
    }

    public function testRecordingAudioIsOnlyForTheOwningCompanyAndExpiredOnesAreNotServed(): void
    {
        $now = 'NOW()';
        $this->db()->exec("INSERT INTO voice_recordings (tenant_user_id, call_id, provider, recording_sid, status, retention_until, created_at, updated_at) VALUES (?, 77, 'twilio', 'RE1', 'available', NOW() + INTERVAL 1 DAY, $now, $now)", [self::A]);
        $this->db()->exec("INSERT INTO voice_recordings (tenant_user_id, call_id, provider, recording_sid, status, retention_until, created_at, updated_at) VALUES (?, 78, 'twilio', 'RE2', 'available', NOW() - INTERVAL 1 DAY, $now, $now)", [self::A]);
        $svc = new VoiceRecordingService();
        $this->assertNull($svc->audio(self::B, 1, 77)); // another company: nothing, and no provider call is even attempted
        $this->assertNull($svc->audio(self::A, 1, 78)); // past retention: not served
    }

    public function testAdminStopCancelsOnlyTheNamedCompaniesQueuedCalls(): void
    {
        foreach ([self::A, self::B] as $t) {
            $this->db()->exec("INSERT INTO voice_agent_settings (tenant_user_id, enabled, sandbox_mode, created_at, updated_at) VALUES (?,1,1,NOW(),NOW())", [$t]);
            $this->db()->exec("INSERT INTO voice_outbound_jobs (tenant_user_id, number_id, agent_id, purpose, category, to_e164, phone_key, status, next_attempt_at, created_by_user_id, created_at, updated_at) VALUES (?,1,1,'booking_confirmation','service','+201001234567','001234567','scheduled',NOW(),1,NOW(),NOW())", [$t]);
        }
        $r = new VoiceAdminReports();
        $this->assertSame(1, $r->cancelQueuedJobs(self::A, 'admin_stop'));
        $this->assertSame('cancelled', $this->db()->query('SELECT status FROM voice_outbound_jobs WHERE tenant_user_id = ?', [self::A])[0]['status']);
        $this->assertSame('scheduled', $this->db()->query('SELECT status FROM voice_outbound_jobs WHERE tenant_user_id = ?', [self::B])[0]['status']);
        $ids = array_column($r->companies(), 'company');
        $this->assertContains(self::A, array_map('intval', $ids));
    }

    public function testAdminVoiceAccessRefusesStaffAccounts(): void
    {
        $access = new VoiceFeatureAccess();
        $this->db()->exec('UPDATE users SET owner_user_id = ? WHERE id = ?', [self::A, self::B]);
        try {
            $access->set(self::B, true, 3, 1);
            $this->fail('staff must be changed through their owner');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('staff', $e->getMessage());
        }
        $this->assertSame(1, count($access->search('voice-' . self::A)));
        $this->assertSame([], $access->search('a'));
        $this->db()->exec('UPDATE users SET owner_user_id = NULL WHERE id = ?', [self::B]);
    }

    public function testAdminGrantWinsOverThePlanBothWaysAndCanBeReset(): void
    {
        $access = new VoiceFeatureAccess();
        $access->set(self::A, true, 4, 1);
        $this->assertTrue(VoiceAccessService::featureEnabled(self::A));
        $this->assertSame(4, VoiceAccessService::maxNumbers(self::A));
        $this->assertFalse(VoiceAccessService::featureEnabled(self::B)); // nobody else
        $access->set(self::A, false, null, 1);
        $this->assertFalse(VoiceAccessService::featureEnabled(self::A)); // a revoke beats a plan that includes the feature
        $access->set(self::A, true, null, 1);
        $this->assertSame(4, VoiceAccessService::maxNumbers(self::A)); // limit survives off/on
        $this->assertSame('admin', $access->search('voice-' . self::A)[0]['source']);
        $access->set(self::A, null, null, 1);
        $this->assertNull(VoiceAccessService::grant(self::A));
        $this->assertSame('plan', $access->search('voice-' . self::A)[0]['source']);
    }

    public function testCompanyOnItsOwnProviderAccountIsNotBilledForTelephony(): void
    {
        $this->db()->exec("INSERT INTO voice_providers (tenant_user_id, provider, mode, credentials_enc, status, created_at, updated_at) VALUES (?, 'twilio', 'own', 'x', 'ok', NOW(), NOW())", [self::A]);
        $this->db()->exec("INSERT INTO voice_providers (tenant_user_id, provider, mode, credentials_enc, status, created_at, updated_at) VALUES (?, 'twilio', 'platform', 'x', 'ok', NOW(), NOW())", [self::B]);
        foreach ([self::A, self::B] as $t) {
            $this->db()->exec("INSERT INTO voice_calls (tenant_user_id, provider, call_sid, direction, mode, duration_s, ai_cost_usd, started_at, ended_at) VALUES (?, 'twilio', ?, 'inbound', 'turn', 90, 0.02, NOW(), NOW())", [$t, 'CAown' . $t]);
            $id = (int) $this->db()->query("SELECT id FROM voice_calls WHERE call_sid = ? AND tenant_user_id = ?", ['CAown' . $t, $t])[0]['id'];
            (new VoiceUsageService())->recordCall($t, $id, 0.05);
        }
        $a = (new VoiceUsageService())->month(self::A)['by_component'];
        $b = (new VoiceUsageService())->month(self::B)['by_component'];
        $this->assertSame(['ai'], array_keys($a)); // own account: the provider bills them directly
        $this->assertContains('inbound_minutes', array_keys($b)); // platform account: billed with markup
    }

    public function testTelnyxTenantIsTheOwnerOfTheDialledNumberOnly(): void
    {
        $this->db()->exec("INSERT INTO voice_numbers (tenant_user_id, e164, live_key, country, number_type, source, provider, status, verification_mode, created_at, updated_at) VALUES (?, '+14155550177', '+14155550177', 'US', 'local', 'purchased', 'telnyx', 'active', 'live', NOW(), NOW())", [self::B]);
        $this->assertSame(self::B, VoiceInboundService::tenantForCall('telnyx', 0, ['To' => '+14155550177', 'From' => '+201001234567']));
        $this->assertSame(0, VoiceInboundService::tenantForCall('telnyx', 0, ['To' => '+14155550999']));       // unknown number: nobody
        $this->assertSame(0, VoiceInboundService::tenantForCall('twilio', 0, ['To' => '+14155550177']));       // only Telnyx may use the tenant-less URL
        $this->assertSame(self::A, VoiceInboundService::tenantForCall('telnyx', self::A, ['To' => '+14155550177'])); // an explicit tenant is never overridden
    }
}

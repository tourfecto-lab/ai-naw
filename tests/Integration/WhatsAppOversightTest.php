<?php

/**
 * Admin oversight of WhatsApp marketing: cross-customer campaign list with counts and flags, pause/cancel with a reason,
 * account suspension enforced at launch and at send time, resume, history, and the platform hub (read-only).
 * No message is sent and no WhatsApp connection or template is changed by any of this.
 */

use PHPUnit\Framework\TestCase;

final class WhatsAppOversightTest extends TestCase
{
    private const A = 999131;
    private const B = 999132;
    private const MEMBER = 999133;
    private const WA = 999131;
    private const WB = 999132;
    private const KEYS = ['wa_marketing_suspended', 'wa_marketing_admin_audit'];

    private ?PDO $pdo = null;
    private ?Database $db = null;
    private array $saved = [];

    protected function setUp(): void
    {
        try {
            $this->db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $this->pdo = $ref->getValue($this->db);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB not available');
        }
        if (!$this->pdo instanceof PDO || !$this->pdo->query("SHOW TABLES LIKE 'wa_marketing_campaigns'")->fetch()) {
            $this->markTestSkipped('WhatsApp marketing tables not available');
        }
        foreach (self::KEYS as $k) {
            $v = $this->pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = '$k'")->fetchColumn();
            $this->saved[$k] = $v === false ? null : $v;
            $this->pdo->exec("DELETE FROM system_settings WHERE setting_key = '$k'");
        }
        $this->cleanup();
        $this->pdo->exec("INSERT INTO users (id, email, password, company_name, owner_user_id, created_at) VALUES
            (" . self::A . ", 'wa-a@tourfecto.test', 'x', 'Alpha Tours', NULL, NOW()), (" . self::B . ", 'wa-b@tourfecto.test', 'x', 'Beta Travel', NULL, NOW()), (" . self::MEMBER . ", 'wa-m@tourfecto.test', 'x', 'Member', " . self::A . ", NOW())");
        $this->pdo->exec("INSERT INTO websites (id, user_id, main_url, company_name, industry, is_verified, created_at) VALUES
            (" . self::WA . ", " . self::A . ", 'https://a.test', 'Alpha Site', 'tourism', 1, NOW()), (" . self::WB . ", " . self::B . ", 'https://b.test', 'Beta Site', 'tourism', 1, NOW())");
    }

    protected function tearDown(): void
    {
        if (!$this->pdo) {
            return;
        }
        $this->cleanup();
        foreach ($this->saved as $k => $v) {
            $this->pdo->exec("DELETE FROM system_settings WHERE setting_key = '$k'");
            if ($v !== null) {
                $st = $this->pdo->prepare('INSERT INTO system_settings (setting_key, setting_value, is_secret) VALUES (?, ?, 0)');
                $st->execute([$k, $v]);
            }
        }
    }

    private function cleanup(): void
    {
        $ids = self::A . ',' . self::B . ',' . self::MEMBER;
        $this->pdo->exec("DELETE r FROM wa_marketing_recipients r JOIN wa_marketing_campaigns c ON c.id = r.campaign_id WHERE c.user_id IN ($ids)");
        $this->pdo->exec("DELETE FROM wa_marketing_campaigns WHERE user_id IN ($ids)");
        $this->pdo->exec("DELETE FROM wa_marketing_contacts WHERE user_id IN ($ids)");
        $this->pdo->exec("DELETE FROM wa_marketing_consent_events WHERE user_id IN ($ids)");
        $this->pdo->exec("DELETE FROM wa_marketing_templates WHERE user_id IN ($ids)");
        $this->pdo->exec("DELETE FROM wa_signup_forms WHERE user_id IN ($ids)");
        $this->pdo->exec("DELETE FROM platform_connections WHERE user_id IN ($ids)");
        $this->pdo->exec("DELETE FROM websites WHERE id IN (" . self::WA . "," . self::WB . ")");
        $this->pdo->exec("DELETE FROM users WHERE id IN ($ids)");
    }

    private function campaign(int $uid, int $wid, string $name, string $state, array $recipients = []): int
    {
        $h = str_repeat('a', 64);
        $st = $this->pdo->prepare("INSERT INTO wa_marketing_campaigns (user_id, website_id, name, state, template_name, language, template_body, template_hash, connection_hash, parameters_json, scheduled_at) VALUES (?, ?, ?, ?, 'promo_en', 'en', 'Hello', ?, ?, '[]', NOW())");
        $st->execute([$uid, $wid, $name, $state, $h, $h]);
        $id = (int) $this->pdo->lastInsertId();
        $n = 0;
        foreach ($recipients as $rState => $count) {
            for ($i = 0; $i < $count; $i++) {
                $n++;
                $this->pdo->exec("INSERT INTO wa_marketing_recipients (campaign_id, contact_id, phone, name, language, state) VALUES ($id, " . ($id * 1000 + $n) . ", '2010000" . str_pad((string) ($id % 1000 * 100 + $n), 5, '0', STR_PAD_LEFT) . "', 'G', 'en', '$rState')");
            }
        }
        return $id;
    }

    private function row(array $list, int $id): array
    {
        foreach ($list['rows'] as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }
        $this->fail('campaign ' . $id . ' not listed');
    }

    public function testListShowsEveryCustomersCampaignsWithCountsAndFlags(): void
    {
        $bad = $this->campaign(self::A, self::WA, 'Bad run', 'running', ['accepted' => 12, 'failed' => 10, 'pending' => 3]);
        $ok = $this->campaign(self::B, self::WB, 'Good run', 'completed', ['accepted' => 30, 'failed' => 1]);
        $small = $this->campaign(self::B, self::WB, 'Tiny', 'blocked', ['failed' => 3]);
        $list = WhatsAppOversight::campaigns('', '', 1, 100, $this->db);
        $r = $this->row($list, $bad);
        $this->assertSame('Alpha Tours', $r['customer']);
        $this->assertSame(['accepted' => 12, 'failed' => 10, 'pending' => 3], $r['counts']);
        $this->assertContains('high_failure_rate', $r['flags']);
        $this->assertSame([], $this->row($list, $ok)['flags'], '1 failure of 31 is normal');
        $this->assertSame(['blocked'], $this->row($list, $small)['flags'], 'under 20 attempts: no failure-rate flag, but blocked is shown');
        $this->assertSame([$ok], array_column(WhatsAppOversight::campaigns('completed', '', 1, 100, $this->db, self::B)['rows'], 'id'));
        $this->assertCount(1, WhatsAppOversight::campaigns('', 'Alpha', 1, 100, $this->db)['rows']);
    }

    public function testKpisCountThisMonthAcrossCustomers(): void
    {
        $before = WhatsAppOversight::kpis($this->db);
        $this->campaign(self::A, self::WA, 'k1', 'running', ['accepted' => 5, 'failed' => 2, 'uncertain' => 1, 'skipped' => 4]);
        $this->campaign(self::B, self::WB, 'k2', 'paused', ['accepted' => 3]);
        $this->pdo->exec("INSERT INTO wa_marketing_consent_events (contact_id, user_id, website_id, state, evidence) VALUES (1, " . self::A . ", " . self::WA . ", 'opted_out', 'STOP')");
        $after = WhatsAppOversight::kpis($this->db);
        $this->assertSame($before['campaigns'] + 2, $after['campaigns']);
        $this->assertSame($before['accepted'] + 8, $after['accepted']);
        $this->assertSame($before['failed'] + 2, $after['failed']);
        $this->assertSame($before['uncertain'] + 1, $after['uncertain']);
        $this->assertSame($before['opt_outs'] + 1, $after['opt_outs']);
        $this->assertSame($before['paused'] + 1, $after['paused']);
    }

    public function testPauseAndCancelNeedAReasonAndAreRecorded(): void
    {
        $id = $this->campaign(self::A, self::WA, 'To pause', 'running', ['pending' => 2, 'accepted' => 1]);
        foreach ([['pause', ''], ['stop', 'x'], ['pause', str_repeat('x', 301)]] as [$a, $reason]) {
            try {
                WhatsAppOversight::campaignAction($id, $a, $reason, 7, $this->db);
                $this->fail('expected rejection');
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame('running', $this->pdo->query("SELECT state FROM wa_marketing_campaigns WHERE id = $id")->fetchColumn());
        WhatsAppOversight::campaignAction($id, 'pause', 'Complaints about unsolicited messages', 7, $this->db);
        $row = $this->pdo->query("SELECT state, last_error FROM wa_marketing_campaigns WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('paused', $row['state']);
        $this->assertStringContainsString('Paused by the platform: Complaints', $row['last_error']);
        WhatsAppOversight::campaignAction($id, 'cancel', 'Policy breach', 7, $this->db);
        $this->assertSame('cancelled', $this->pdo->query("SELECT state FROM wa_marketing_campaigns WHERE id = $id")->fetchColumn());
        $this->assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM wa_marketing_recipients WHERE campaign_id = $id AND state = 'skipped'")->fetchColumn(), 'pending recipients are skipped, never sent');
        $log = WhatsAppOversight::auditLog(5, $this->db);
        $this->assertSame('campaign_cancel', $log[0]['action']);
        $this->assertSame(7, $log[0]['actor_id']);
        $this->expectException(InvalidArgumentException::class);
        WhatsAppOversight::campaignAction(424242, 'pause', 'x', 7, $this->db);
    }

    public function testSuspendingAnAccountPausesItsCampaignsAndBlocksLaunchAndSending(): void
    {
        $mine = $this->campaign(self::A, self::WA, 'Running', 'running', ['pending' => 2]);
        $sched = $this->campaign(self::A, self::WA, 'Scheduled', 'scheduled', ['pending' => 1]);
        $other = $this->campaign(self::B, self::WB, 'Other customer', 'running', ['pending' => 1]);
        try {
            WhatsAppOversight::suspend(self::A, '   ', 7, $this->db);
            $this->fail('a reason is required');
        } catch (InvalidArgumentException $e) {
            $this->assertTrue(true);
        }
        $this->assertFalse(WhatsAppOversight::suspended(self::A, $this->db));
        WhatsAppOversight::suspend(self::A, 'Spam reports', 7, $this->db);
        $this->assertTrue(WhatsAppOversight::suspended(self::A, $this->db));
        $this->assertFalse(WhatsAppOversight::suspended(self::B, $this->db), 'only that account');
        foreach ([$mine, $sched] as $id) {
            $this->assertSame('paused', $this->pdo->query("SELECT state FROM wa_marketing_campaigns WHERE id = $id")->fetchColumn());
        }
        $this->assertSame('running', $this->pdo->query("SELECT state FROM wa_marketing_campaigns WHERE id = $other")->fetchColumn());
        try {
            (new WhatsAppCampaignTools())->launch(self::A, $mine, [1], null);
            $this->fail('launch must be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('paused for this account by the platform', $e->getMessage());
        }
        $this->pdo->exec("UPDATE wa_marketing_campaigns SET state = 'running' WHERE id = $mine"); // even if something set it running again
        $res = (new WhatsAppMarketingService())->process(self::A, $mine, 1);
        $this->assertTrue($res['suspended'] ?? false, 'sending stops at send time');
        $this->assertSame('paused', $this->pdo->query("SELECT state FROM wa_marketing_campaigns WHERE id = $mine")->fetchColumn());
        $this->assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM wa_marketing_recipients WHERE campaign_id = $mine AND state = 'pending'")->fetchColumn(), 'nothing was sent');
        $this->assertCount(1, WhatsAppOversight::suspendedList($this->db));
    }

    public function testResumeLiftsTheBlockButLeavesCampaignsPausedForTheCustomerToDecide(): void
    {
        $id = $this->campaign(self::A, self::WA, 'Running', 'running', ['pending' => 1]);
        WhatsAppOversight::suspend(self::A, 'Review', 7, $this->db);
        WhatsAppOversight::resume(self::A, 8, $this->db);
        $this->assertFalse(WhatsAppOversight::suspended(self::A, $this->db));
        $this->assertSame('paused', $this->pdo->query("SELECT state FROM wa_marketing_campaigns WHERE id = $id")->fetchColumn());
        try {
            WhatsAppOversight::resume(self::A, 8, $this->db);
            $this->fail('not suspended any more');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('not suspended', $e->getMessage());
        }
        $actions = array_column(WhatsAppOversight::auditLog(5, $this->db), 'action');
        $this->assertSame(['resume', 'suspend'], array_slice($actions, 0, 2));
    }

    public function testATeamMemberCannotBeSuspendedOnlyTheAccountOwner(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WhatsAppOversight::suspend(self::MEMBER, 'x', 7, $this->db);
    }

    public function testHubShowsOnlyTheAdminsOwnAccountAndNeverSecrets(): void
    {
        $this->pdo->exec("INSERT INTO platform_connections (website_id, user_id, platform, status, access_token, external_account_id, external_location_id, meta_json) VALUES (" . self::WA . ", " . self::A . ", 'whatsapp_cloud', 'connected', 'SECRET-TOKEN-DO-NOT-LEAK', '111', '222', '{\"display_phone\":\"+20 100 000 0000\"}')");
        $this->pdo->exec("INSERT INTO wa_marketing_contacts (user_id, website_id, phone, name, language, consent_state) VALUES (" . self::A . ", " . self::WA . ", '201000000001', 'a', 'en', 'opted_in'), (" . self::A . ", " . self::WA . ", '201000000002', 'b', 'en', 'opted_in'), (" . self::A . ", " . self::WA . ", '201000000003', 'c', 'en', 'opted_out'), (" . self::B . ", " . self::WB . ", '201000000004', 'd', 'en', 'opted_in')");
        $this->pdo->exec("INSERT INTO wa_signup_forms (user_id, website_id, token, title, language, privacy_url, active) VALUES (" . self::A . ", " . self::WA . ", '" . str_repeat('b', 64) . "', 'Travel updates', 'en', 'https://a.test/privacy', 1), (" . self::A . ", " . self::WA . ", '" . str_repeat('c', 64) . "', 'Old form', 'en', 'https://a.test/privacy', 0)");
        $this->campaign(self::A, self::WA, 'Mine', 'draft');
        $this->campaign(self::B, self::WB, 'Not mine', 'draft');
        $hub = WhatsAppOversight::hub(self::A, null, $this->db);
        $this->assertSame(self::WA, $hub['website_id']);
        $this->assertTrue($hub['connected']);
        $this->assertSame('+20 100 000 0000', $hub['sender']);
        $this->assertSame(['opted_in' => 2, 'opted_out' => 1, 'other' => 0], $hub['contacts'], 'the other customer contacts are not counted');
        $this->assertCount(1, $hub['forms'], 'only the active opt-in form');
        $this->assertStringContainsString('/wa/signup/' . str_repeat('b', 64), $hub['forms'][0]['url']);
        $this->assertSame(['Mine'], array_column($hub['campaigns'], 'name'));
        $this->assertFalse(strpos(json_encode($hub), 'SECRET-TOKEN-DO-NOT-LEAK') !== false, 'no credential may reach the page');
        $empty = WhatsAppOversight::hub(self::MEMBER, null, $this->db);
        $this->assertSame(0, $empty['website_id'], 'an account without a website gets a clear empty state');
    }

    public function testOptOutRateIsMeasuredPerCampaignWithinThreeDaysOfTheMessage(): void
    {
        $id = $this->campaign(self::A, self::WA, 'Pushy', 'completed', ['accepted' => 40]);
        // contacts 1..40 are the recipients; four say STOP right after the message, one says it two weeks later, one before it was sent
        $rows = $this->pdo->query("SELECT contact_id, updated_at FROM wa_marketing_recipients WHERE campaign_id = $id ORDER BY contact_id")->fetchAll(PDO::FETCH_ASSOC);
        $offsets = [0 => '+1 hour', 1 => '+5 hours', 2 => '+1 day', 3 => '+2 days', 4 => '+14 days', 5 => '-2 days'];
        foreach ($offsets as $i => $off) {
            $at = gmdate('Y-m-d H:i:s', strtotime($rows[$i]['updated_at'] . ' UTC ' . $off));
            $this->pdo->exec("INSERT INTO wa_marketing_consent_events (contact_id, user_id, website_id, state, evidence, created_at) VALUES (" . (int) $rows[$i]['contact_id'] . ", " . self::A . ", " . self::WA . ", 'opted_out', 'STOP', '$at')");
        }
        $r = $this->row(WhatsAppOversight::campaigns('', '', 1, 100, $this->db), $id);
        $this->assertSame(4, $r['opt_outs'], 'only STOPs within 3 days after the message count');
        $this->assertContains('high_opt_out_rate', $r['flags'], '4 of 40 is 10%, above the 5% line');
        $quiet = $this->campaign(self::B, self::WB, 'Welcome', 'completed', ['accepted' => 40]);
        $this->assertSame([], $this->row(WhatsAppOversight::campaigns('', '', 1, 100, $this->db), $quiet)['flags']);
        $this->assertSame([], WhatsAppOversight::flags(['accepted' => 10, 'failed' => 0, 'opt_outs' => 5], 'completed'), 'under 20 accepted: not enough data to flag');
    }
}

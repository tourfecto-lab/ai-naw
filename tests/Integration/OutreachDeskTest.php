<?php

/**
 * مكتب التواصل: شركات كل دولة بأرقامها والرسالة جاهزة للنسخ، وتسجيل نتيجة التواصل اليدوي.
 */

use PHPUnit\Framework\TestCase;

final class OutreachDeskTest extends TestCase
{
    private ?Database $db = null;
    private PlatformOutreachService $svc;

    protected function setUp(): void
    {
        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) foreach (['app.php', 'database.php', 'encryption.php'] as $cfg) if (file_exists($app . '/Config/' . $cfg)) require_once $app . '/Config/' . $cfg;
            if (!class_exists('Database')) require_once $app . '/Core/Database.php';
            $this->db = Database::getInstance();
            $this->svc = new PlatformOutreachService();
        } catch (Throwable $e) {
            $this->markTestSkipped('DB غير متاحة: ' . $e->getMessage());
        }
        $this->clean();
    }

    protected function tearDown(): void
    {
        if ($this->db) $this->clean();
    }

    private function clean(): void
    {
        $ids = array_map('intval', array_column($this->db->query("SELECT id FROM platform_leads WHERE source = 'phpunit-desk'") ?: [], 'id'));
        if ($ids) $this->db->query('DELETE FROM platform_desk_actions WHERE lead_id IN (' . implode(',', $ids) . ')');
        $this->db->query("DELETE FROM platform_leads WHERE source = 'phpunit-desk'");
    }

    private function lead(string $name, string $country, ?string $phone, array $v = []): int
    {
        $v += ['status' => 'contacted', 'step' => 1, 'track' => 'seo', 'token' => bin2hex(random_bytes(16))];
        $slug = strtolower(str_replace(' ', '', $name));
        $this->db->query("INSERT INTO platform_leads (source, company, email, domain, website, phone, country, city, category, language, status, step, track, score, reviews, token)
            VALUES ('phpunit-desk', ?, ?, ?, ?, ?, ?, 'Cairo', 'Tour agency', 'ar', ?, ?, ?, 70, ?, ?)",
            [$name, $slug . '@desk.test', $slug . '.test', 'https://' . $slug . '.test', $phone, $country, $v['status'], $v['step'], $v['track'], $v['reviews'] ?? 10, $v['token']]);
        return (int) ($this->db->query("SELECT MAX(id) AS id FROM platform_leads WHERE source = 'phpunit-desk'")[0]['id'] ?? 0);
    }

    public function testPhoneIsNormalisedForWhatsApp(): void
    {
        $this->assertSame('201015602770', PlatformOutreachService::deskPhone('+20 101 560 2770', 'EG'));
        $this->assertSame('201044561020', PlatformOutreachService::deskPhone('+201044561020', 'EG'));
        $this->assertSame('201044561020', PlatformOutreachService::deskPhone('00201044561020', 'EG'));
        $this->assertSame('201015602770', PlatformOutreachService::deskPhone('0101 560 2770', 'EG'), 'local number gets the country code');
        $this->assertSame('905321234567', PlatformOutreachService::deskPhone('0532 123 45 67', 'TR'));
        $this->assertSame('', PlatformOutreachService::deskPhone('0101 560 2770', null), 'unknown country: do not guess');
        $this->assertSame('', PlatformOutreachService::deskPhone('12345', 'EG'));
        $this->assertSame('', PlatformOutreachService::deskPhone('', 'EG'));
    }

    public function testListShowsThePhoneAndAReadyMessageWithTheFullReportLink(): void
    {
        $token = bin2hex(random_bytes(16));
        $id = $this->lead('Alpha Tours', 'EG', '+20 101 560 2770', ['token' => $token]);
        $r = $this->svc->deskLeads(['country' => 'EG', 'q' => 'desk.test'], ['name' => 'Sara']);
        $row = array_values(array_filter($r['rows'], static fn ($x) => $x['id'] === $id))[0] ?? null;
        $this->assertNotNull($row);
        $this->assertSame('+201015602770', $row['phone_intl']);
        $this->assertStringStartsWith('https://wa.me/201015602770?text=', $row['wa_url'], 'opening WhatsApp puts the message in the chat, ready to send');
        $this->assertTrue($row['wa_has_text']);
        parse_str((string) parse_url($row['wa_url'], PHP_URL_QUERY), $q);
        $this->assertSame($row['message'], $q['text'], 'the link carries exactly the message shown for copying');
        $this->assertStringContainsString('Alpha Tours', $row['message']);
        $this->assertStringContainsString('/p/r/' . $token, $row['message'], 'the message carries the same full-report link as the email');
        $this->assertStringContainsString('Sara', $row['message'], 'signed with the staff member name');
        $this->assertStringContainsString('/p/r/pv-' . $token, $row['report_preview_url'], 'preview link must not count as a company view');
        $this->assertFalse($row['blocked']);
    }

    public function testViewsFilterByDeskResultAndCountsFollow(): void
    {
        $a = $this->lead('Alpha Tours', 'EG', '+201000000001');
        $b = $this->lead('Beta Tours', 'EG', '+201000000002');
        $this->lead('NoPhone Tours', 'EG', null);
        $this->svc->deskAction($a, 'contacted', '', 5, 'Sara');

        $todo = $this->svc->deskLeads(['country' => 'EG', 'view' => 'todo', 'q' => 'desk.test']);
        $this->assertSame([$b], array_column($todo['rows'], 'id'));
        $done = $this->svc->deskLeads(['country' => 'EG', 'view' => 'contacted', 'q' => 'desk.test']);
        $this->assertSame([$a], array_column($done['rows'], 'id'));
        $this->assertSame('Sara', $done['rows'][0]['desk']['by']);
        $eg = array_values(array_filter($todo['counts'], static fn ($c) => $c['country'] === 'EG'))[0];
        $this->assertGreaterThanOrEqual(1, $eg['contacted']);
        $this->assertSame(0, count($this->svc->deskLeads(['country' => 'EG', 'q' => 'NoPhone'])['rows']), 'a company without a phone is not on the desk');
    }

    public function testRepliedInterestedAndRejectedStopTheEmailSequence(): void
    {
        $a = $this->lead('Alpha Tours', 'EG', '+201000000001');
        $b = $this->lead('Beta Tours', 'EG', '+201000000002');
        $c = $this->lead('Gamma Tours', 'EG', '+201000000003');
        $d = $this->lead('Delta Tours', 'EG', '+201000000004');
        $this->svc->deskAction($a, 'replied', 'replied on whatsapp', 5, 'Sara');
        $this->svc->deskAction($b, 'interested', '', 5, 'Sara');
        $this->svc->deskAction($c, 'not_interested', '', 5, 'Sara');
        $this->svc->deskAction($d, 'wrong_number', '', 5, 'Sara');
        $st = fn (int $id) => $this->db->query('SELECT status FROM platform_leads WHERE id = ?', [$id])[0]['status'];
        $this->assertSame('replied', $st($a));
        $this->assertSame('interested', $st($b));
        $this->assertSame('paused', $st($c));
        $this->assertSame('contacted', $st($d), 'a wrong number says nothing about the company');
        $this->assertSame(1, (int) $this->db->query('SELECT COUNT(*) AS n FROM platform_desk_actions WHERE lead_id = ?', [$a])[0]['n']);
    }

    public function testFollowUpListHoldsSendsOlderThanTwoDaysOldestFirstAndFollowingUpRestartsTheClock(): void
    {
        $old = $this->lead('Oldfollow Tours', 'EG', '+201000000011');
        $fresh = $this->lead('Freshfollow Tours', 'EG', '+201000000012');
        $older = $this->lead('Olderfollow Tours', 'EG', '+201000000013');
        $back = $this->lead('Backfollow Tours', 'EG', '+201000000014');
        foreach ([$old, $fresh, $older] as $id) {
            $this->svc->deskAction($id, 'contacted', '', 5, 'Sara');
        }
        $this->svc->deskAction($back, 'call_back', '', 5, 'Sara');
        $this->db->query('UPDATE platform_leads SET desk_at = NOW() - INTERVAL 3 DAY WHERE id = ?', [$old]);
        $this->db->query('UPDATE platform_leads SET desk_at = NOW() - INTERVAL 9 DAY WHERE id = ?', [$older]);

        $f = $this->svc->deskLeads(['country' => 'EG', 'view' => 'followup', 'q' => 'follow']);
        $names = array_column($f['rows'], 'company');
        $this->assertSame(['Olderfollow Tours', 'Oldfollow Tours', 'Backfollow Tours'], $names, 'oldest wait first; a fresh send is not due; "call back" is always due');
        $eg = array_values(array_filter($f['counts'], fn ($c) => $c['country'] === 'EG'))[0];
        $this->assertGreaterThanOrEqual(3, (int) $eg['followup']);

        $this->svc->deskAction($old, 'contacted', 'follow-up sent', 5, 'Sara');
        $names = array_column($this->svc->deskLeads(['country' => 'EG', 'view' => 'followup', 'q' => 'follow'])['rows'], 'company');
        $this->assertNotContains('Oldfollow Tours', $names, 'after following up the company waits two more days');
    }

    public function testFunnelCountsSendsRepliesTrialsAndSubscriptionsPerCountryAndStaff(): void
    {
        $ids = [];
        foreach (range(1, 5) as $i) {
            $ids[$i] = $this->lead('Funnel ' . $i . ' Tours', 'ZZ', '+2010000001' . $i);
        }
        $this->svc->deskAction($ids[1], 'contacted', '', 5, 'Sara');
        $this->svc->deskAction($ids[2], 'contacted', '', 5, 'Sara');
        $this->svc->deskAction($ids[3], 'contacted', '', 6, 'Mona');
        $this->svc->deskAction($ids[4], 'contacted', '', 6, 'Mona');
        $this->svc->deskAction($ids[4], 'undo', '', 6, 'Mona');
        $this->svc->deskAction($ids[2], 'replied', '', 5, 'Sara');
        $this->svc->deskAction($ids[3], 'trial', '', 6, 'Mona');
        $this->svc->deskAction($ids[1], 'subscribed', '', 5, 'Sara');

        $f = $this->svc->deskFunnel('ZZ')['whatsapp'];
        $this->assertSame(5, $f['companies']);
        $this->assertSame(3, $f['sent'], 'a send that was undone is not a send');
        $this->assertSame(3, $f['replied'], 'replied + trial + subscribed all count as a reply');
        $this->assertSame(2, $f['trial'], 'trial and subscribed');
        $this->assertSame(1, $f['subscribed']);
        $this->assertSame(100.0, (float) $f['reply_rate']);
        $staff = array_column($this->svc->deskFunnel('ZZ')['staff'], null, 'name');
        $this->assertSame(2, $staff['Sara']['sent']);
        $this->assertSame(2, $staff['Mona']['sent'], 'the staff list comes from the action log, so it still shows a send she later undid');
        $this->assertSame(2, $staff['Sara']['replied'], 'one reply and one subscription');
        $this->assertSame(0, $this->svc->deskFunnel('QQ')['whatsapp']['sent']);
    }

    public function testWeakMessagesAreHiddenFromTodoButKeptInTheirOwnTab(): void
    {
        $this->assertTrue(OutreachAudit::isWeak([]));
        $this->assertTrue(OutreachAudit::isWeak([['id' => 'no_tracking']]));
        $this->assertTrue(OutreachAudit::isWeak([['id' => 'no_tracking'], ['id' => 'google_few_reviews'], ['id' => 'many_guests']]), 'comparisons and soft points do not make a strong message');
        $this->assertFalse(OutreachAudit::isWeak([['id' => 'no_tracking'], ['id' => 'no_booking']]));

        $weak = $this->lead('Weakmsg Tours', 'EG', '+201000000031');
        $strong = $this->lead('Strongmsg Tours', 'EG', '+201000000032');
        $this->db->query('UPDATE platform_leads SET desk_weak = 1 WHERE id = ?', [$weak]);
        $this->db->query('UPDATE platform_leads SET desk_weak = 0 WHERE id = ?', [$strong]);
        $names = fn (string $view) => array_column($this->svc->deskLeads(['country' => 'EG', 'view' => $view, 'q' => 'msg Tours'])['rows'], 'company');
        $this->assertSame(['Strongmsg Tours'], $names('todo'));
        $this->assertSame(['Weakmsg Tours'], $names('weak'));
        $this->assertEqualsCanonicalizing(['Strongmsg Tours', 'Weakmsg Tours'], $names('all'), 'nothing is deleted');
        $this->svc->deskAction($weak, 'contacted', '', 5, 'Sara');
        $this->assertSame([], $names('weak'), 'once staff acted on it it is no longer a hidden to-do');
    }

    /** @return array{0:int,1:int,2:string} [staff user id, customer user id, customer email] */
    private function twoUsers(): array
    {
        $u = $this->db->query('SELECT id, email FROM users ORDER BY id LIMIT 2');
        if (count($u) < 2) {
            $this->markTestSkipped('needs two users in the test DB');
        }
        return [(int) $u[0]['id'], (int) $u[1]['id'], (string) $u[1]['email']];
    }

    private function cleanStaff(int $staffId, int $custId): void
    {
        $this->db->query('DELETE FROM subscriptions WHERE user_id = ? AND plan_name = ? AND price = 79.00', [$custId, 'starter']);
        $this->db->query('DELETE FROM platform_staff_comp WHERE user_id = ?', [$staffId]);
    }

    public function testCustomerIsAttributedToTheFirstStaffWhoSentAndAnUndoIsIgnored(): void
    {
        [$s1, $s2] = $this->twoUsers();
        $a = $this->lead('Attrib One Tours', 'EG', '+201000000041');
        $b = $this->lead('Attrib Two Tours', 'EG', '+201000000042');
        $this->svc->deskAction($a, 'contacted', '', $s1, 'Sara');
        $this->svc->deskAction($a, 'contacted', '', $s2, 'Mona');
        $this->svc->deskAction($b, 'contacted', '', $s1, 'Sara');
        $this->svc->deskAction($b, 'undo', '', $s1, 'Sara');
        $this->svc->deskAction($b, 'contacted', '', $s2, 'Mona');
        $attr = $this->svc->staffAttribution();
        $this->assertSame($s1, $attr[$a]['user_id'], 'the first staff to send keeps the company');
        $this->assertSame($s2, $attr[$b]['user_id'], 'a send that was undone does not count');
    }

    public function testStaffReportCountsCommissionOnlyForTheirOwnActiveCustomersAndAddsSalary(): void
    {
        [$staff, $cust, $email] = $this->twoUsers();
        $this->cleanStaff($staff, $cust);
        $mine = $this->lead('Commission Mine Tours', 'EG', '+201000000051');
        $other = $this->lead('Commission Other Tours', 'EG', '+201000000052');
        $this->db->query('UPDATE platform_leads SET email = ? WHERE id = ?', [$email, $mine]);
        $this->svc->deskAction($mine, 'contacted', 'first message', $staff, 'Sara');
        $this->svc->deskAction($mine, 'replied', '', $staff, 'Sara');
        $this->svc->deskAction($other, 'contacted', '', $cust, 'Mona'); // someone else's company
        $this->db->query("INSERT INTO subscriptions (user_id, plan_name, plan_type, status, price, currency, start_date) VALUES (?, 'starter', 'monthly', 'active', 79.00, 'USD', '2020-01-01 00:00:00')", [$cust]);
        try {
            $this->svc->staffCompSet($staff, 'both', 10, 3000, 'test', 'Owner');
            $r = $this->svc->staffReport($staff, date('Y-m'), 50.0);
            $this->assertSame('both', $r['comp']['comp_type']);
            $this->assertSame(1, $r['customers_active']);
            $c = $r['customers'][0];
            $this->assertSame('Commission Mine Tours', $c['company']);
            $this->assertSame(3950.0, $c['monthly_egp']);
            $this->assertSame(395.0, $c['commission_egp'], '10% of 79 USD x 50');
            $this->assertSame(395.0, $r['totals']['commission_egp']);
            $this->assertSame(3000.0, $r['totals']['salary_egp']);
            $this->assertSame(3395.0, $r['totals']['total_egp']);
            $this->assertSame(1, $r['summary']['sent']);
            $this->assertSame(1, $r['summary']['replied']);
            $this->assertSame('first message', array_values(array_filter($r['log'], fn ($l) => $l['action'] === 'contacted'))[0]['note'], 'the work log keeps the details');

            $this->svc->staffCompSet($staff, 'salary', 10, 3000, '', 'Owner');
            $r2 = $this->svc->staffReport($staff, date('Y-m'), 50.0);
            $this->assertSame(0.0, $r2['totals']['commission_egp'], 'salary only: no commission');
            $this->assertSame(0.0, $this->svc->staffCompGet($staff)['commission_pct']);

            $old = $this->svc->staffReport($staff, '2019-06', 50.0);
            $this->assertSame(0, $old['customers_active'], 'a subscription that had not started yet earns nothing for that month');
        } finally {
            $this->cleanStaff($staff, $cust);
        }
    }

    public function testStaffProfileKeepsSensitiveFieldsEncryptedMaskedForOthersAndOwnerOnlyFieldsOwnerOnly(): void
    {
        [$staff] = $this->twoUsers();
        $this->db->query('DELETE FROM platform_staff_profile WHERE user_id = ?', [$staff]);
        try {
            $empty = $this->svc->staffProfile($staff, true);
            $this->assertFalse($empty['complete']);
            $this->assertContains('الرقم القومي أو جواز السفر', $empty['missing']);

            try {
                $p = $this->svc->staffProfileSet($staff, ['national_id' => '29901011234567', 'pay_account' => '01012345678'], false, 'Sara');
            } catch (Throwable $e) {
                $this->markTestSkipped('encryption is not configured in this environment: ' . $e->getMessage());
            }
            $this->assertSame('29901011234567', $p['national_id'], 'the owner of the profile sees it in full');

            $row = $this->db->query('SELECT national_id_enc, pay_account_enc FROM platform_staff_profile WHERE user_id = ?', [$staff])[0];
            $this->assertStringNotContainsString('29901011234567', (string) $row['national_id_enc'], 'stored encrypted, never as plain text');
            $this->assertStringNotContainsString('01012345678', (string) $row['pay_account_enc']);

            $other = $this->svc->staffProfile($staff, false);
            $this->assertSame('••••••••••4567', $other['national_id'], 'anyone else sees only the last 4');
            $this->assertSame('•••••••5678', $other['pay_account']);

            $this->svc->staffProfileSet($staff, ['full_name' => 'Sara Ahmed', 'phone' => '+20 100 000 0000', 'address' => 'Cairo', 'emergency_name' => 'Mona', 'emergency_phone' => '01000000001', 'pay_method' => 'vodafone_cash', 'job_title' => 'CEO', 'status' => 'left'], false, 'Sara');
            $mine = $this->svc->staffProfile($staff, true);
            $this->assertSame('', $mine['job_title'], 'job title is owner-only: ignored when the employee sends it');
            $this->assertSame('active', $mine['status']);
            $this->assertTrue($mine['complete'], 'all required data is filled');
            $this->assertSame([], $mine['missing']);

            $owner = $this->svc->staffProfileSet($staff, ['job_title' => 'Sales', 'start_date' => '2026-11-01', 'status' => 'paused', 'internal_note' => 'trial month'], true, 'Owner');
            $this->assertSame('Sales', $owner['job_title']);
            $this->assertSame('2026-11-01', $owner['start_date']);
            $this->assertSame('paused', $owner['status']);
            $this->assertSame('', $this->svc->staffProfile($staff, false)['internal_note'], 'internal notes are hidden from non-owners');

            $cleared = $this->svc->staffProfileSet($staff, ['address' => ''], false, 'Sara');
            $this->assertContains('العنوان', $cleared['missing']);
        } finally {
            $this->db->query('DELETE FROM platform_staff_profile WHERE user_id = ?', [$staff]);
        }
    }

    public function testStaffProfileInputIsValidated(): void
    {
        [$staff] = $this->twoUsers();
        $bad = [['phone' => 'abc'], ['national_id' => '12 34'], ['pay_method' => 'bitcoin'], ['full_name' => str_repeat('x', 200)]];
        foreach ($bad as $in) {
            try {
                $this->svc->staffProfileSet($staff, $in, false, 'Sara');
                $this->fail('should reject ' . json_encode($in));
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        foreach ([['start_date' => '2026-13-40'], ['status' => 'gone']] as $in) {
            try {
                $this->svc->staffProfileSet($staff, $in, true, 'Owner');
                $this->fail('should reject ' . json_encode($in));
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->db->query('DELETE FROM platform_staff_profile WHERE user_id = ?', [$staff]);
    }

    public function testCompInputIsValidated(): void
    {
        [$staff] = $this->twoUsers();
        foreach ([['bogus', 5, 0], ['commission', 101, 0], ['commission', -1, 0], ['salary', 0, -5]] as [$type, $pct, $sal]) {
            try {
                $this->svc->staffCompSet($staff, $type, (float) $pct, (float) $sal, '', 'Owner');
                $this->fail("$type/$pct/$sal should be rejected");
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->cleanStaff($staff, 0);
    }

    public function testTrialAndSubscribedAreWinsAndStopTheEmailSequence(): void
    {
        $a = $this->lead('Trialwin Tours', 'EG', '+201000000021');
        $b = $this->lead('Subwin Tours', 'EG', '+201000000022');
        $this->svc->deskAction($a, 'trial', 'started the trial', 5, 'Sara');
        $this->svc->deskAction($b, 'subscribed', '', 5, 'Sara');
        $st = fn (int $id) => $this->db->query('SELECT status FROM platform_leads WHERE id = ?', [$id])[0]['status'];
        $this->assertSame('interested', $st($a));
        $this->assertSame('signed_up', $st($b));
        $won = array_column($this->svc->deskLeads(['country' => 'EG', 'view' => 'won', 'q' => 'win'])['rows'], 'company');
        sort($won);
        $this->assertSame(['Subwin Tours', 'Trialwin Tours'], $won);
        $this->assertNotContains('Trialwin Tours', array_column($this->svc->deskLeads(['country' => 'EG', 'view' => 'followup', 'q' => 'win'])['rows'], 'company'));
    }

    public function testUndoClearsASendButNeverAReplyAndIsLogged(): void
    {
        $a = $this->lead('Alpha Tours', 'EG', '+201000000001');
        $r = $this->lead('Beta Tours', 'EG', '+201000000002');
        $this->svc->deskAction($a, 'contacted', 'opened whatsapp', 5, 'Sara');
        $row = $this->svc->deskLeads(['country' => 'EG', 'view' => 'contacted', 'q' => 'alphatours'])['rows'][0];
        $this->assertSame(['action' => 'contacted', 'note' => 'opened whatsapp'], ['action' => $row['desk']['action'], 'note' => $row['desk']['note']]);
        $this->assertSame('Sara', $row['desk']['by']);

        $this->svc->deskAction($a, 'undo', '', 5, 'Sara');
        $after = $this->svc->deskLeads(['country' => 'EG', 'view' => 'todo', 'q' => 'alphatours'])['rows'][0];
        $this->assertNull($after['desk']['action'], 'back to the to-do list');
        $this->assertSame(2, (int) $this->db->query('SELECT COUNT(*) AS n FROM platform_desk_actions WHERE lead_id = ?', [$a])[0]['n'], 'the send and the undo are both in the log');

        $this->svc->deskAction($r, 'replied', '', 5, 'Sara');
        $this->expectException(InvalidArgumentException::class);
        $this->svc->deskAction($r, 'undo', '', 5, 'Sara');
    }

    public function testUndoWithNothingToUndoIsRejected(): void
    {
        $a = $this->lead('Alpha Tours', 'EG', '+201000000001');
        $this->expectException(InvalidArgumentException::class);
        $this->svc->deskAction($a, 'undo', '', 5, 'Sara');
    }

    public function testUnsubscribedCompaniesAreBlockedAndCannotBeLogged(): void
    {
        $id = $this->lead('Alpha Tours', 'EG', '+201000000001', ['status' => 'unsubscribed']);
        $row = $this->svc->deskLeads(['country' => 'EG', 'view' => 'all', 'q' => 'desk.test'])['rows'][0];
        $this->assertTrue($row['blocked']);
        $this->assertSame('', $row['message']);
        $this->assertSame('', $row['wa_url']);
        $this->expectException(InvalidArgumentException::class);
        $this->svc->deskAction($id, 'contacted', '', 5, 'Sara');
    }

    public function testSignedUpIsNotDowngradedAndBadInputIsRejected(): void
    {
        $id = $this->lead('Alpha Tours', 'EG', '+201000000001', ['status' => 'signed_up']);
        $this->svc->deskAction($id, 'not_interested', '', 5, 'Sara');
        $this->assertSame('signed_up', $this->db->query('SELECT status FROM platform_leads WHERE id = ?', [$id])[0]['status']);
        $this->expectException(InvalidArgumentException::class);
        $this->svc->deskAction($id, 'delete_everything', '', 5, 'Sara');
    }
}

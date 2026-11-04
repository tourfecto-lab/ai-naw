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
        try {
            $this->svc->deskAction($a, 'contacted', '', $s2, 'Mona');
            $this->fail('a second employee must not be able to take a company that was already sent');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(409, $e->getCode());
        }
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
            $this->assertFalse($c['paid_confirmed'], 'no paid invoice yet: the commission is shown as pending');
            $this->assertSame(0.0, $r['totals']['commission_confirmed_egp']);
            $this->assertSame(395.0, $r['totals']['commission_pending_egp']);
            $this->assertSame(3000.0, $r['totals']['salary_egp']);
            $this->assertSame(3000.0, $r['totals']['total_egp'], 'only the salary is due until a paid invoice exists');

            $inv = 'T-' . bin2hex(random_bytes(5));
            $this->db->query("INSERT INTO invoices (user_id, invoice_number, plan_name, plan_type, amount, due_date, status, paid_at) VALUES (?, ?, 'starter', 'monthly', 79.00, CURDATE(), 'paid', NOW())", [$cust, $inv]);
            try {
                $paid = $this->svc->staffReport($staff, date('Y-m'), 50.0);
                $this->assertTrue($paid['customers'][0]['paid_confirmed']);
                $this->assertSame(395.0, $paid['totals']['commission_confirmed_egp']);
                $this->assertSame(0.0, $paid['totals']['commission_pending_egp']);
                $this->assertSame(3395.0, $paid['totals']['total_egp'], 'confirmed commission + salary');
            } finally {
                $this->db->query('DELETE FROM invoices WHERE invoice_number = ?', [$inv]);
            }
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

    public function testEgyptianNationalIdGivesBirthDateGenderAndGovernorateAndOcrIsParsedSafely(): void
    {
        $i = PlatformOutreachService::egyptianIdInfo('29901011234567');
        $this->assertSame(['birth_date' => '1999-01-01', 'gender' => 'female', 'governorate' => 'الدقهلية'], $i, 'digits: century 2, 99-01-01, governorate 12, even gender digit');
        $this->assertSame('male', PlatformOutreachService::egyptianIdInfo('29901011234577')['gender'], 'odd gender digit');
        $this->assertSame('الجيزة', PlatformOutreachService::egyptianIdInfo('30501152100128')['governorate']);
        $this->assertSame('2005-01-15', PlatformOutreachService::egyptianIdInfo('٣٠٥٠١١٥٢١٠٠١٢٨')['birth_date'], 'Arabic-Indic digits are accepted');
        $this->assertNull(PlatformOutreachService::egyptianIdInfo('29913011234567'), 'month 13');
        $this->assertNull(PlatformOutreachService::egyptianIdInfo('1234'));

        $p = PlatformOutreachService::parseIdOcr("```json\n{\"full_name\": \"سارة أحمد محمد\", \"national_id\": \"٢٩٩٠١٠١١٢٣٤٥٦٧\", \"address\": \"١٢ شارع النصر، مدينة نصر\"}\n```");
        $this->assertSame('29901011234567', $p['national_id']);
        $this->assertSame('1999-01-01', $p['birth_date']);
        $this->assertSame('12 شارع النصر، مدينة نصر', $p['address'], 'Arabic-Indic digits in the address become normal digits');
        $bad = PlatformOutreachService::parseIdOcr('{"full_name":"X","national_id":"12345","address":""}');
        $this->assertArrayHasKey('warning', $bad, 'a number that is not a valid national ID is flagged for a manual check');
        $this->assertSame([], PlatformOutreachService::parseIdOcr('I cannot read this image'));
    }

    public function testIdCardIsStoredEncryptedReEncodedAndReadBackAndOcrNeverSavesAnything(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is not available');
        }
        [$staff] = $this->twoUsers();
        $this->db->query('DELETE FROM platform_staff_docs WHERE user_id = ?', [$staff]);
        $this->db->query('DELETE FROM platform_staff_profile WHERE user_id = ?', [$staff]);
        try {
            $img = imagecreatetruecolor(2400, 1500);
            ob_start();
            imagepng($img);
            $png = (string) ob_get_clean();
            try {
                $this->svc->staffIdCardSave($staff, 'front', $png);
            } catch (Throwable $e) {
                $this->markTestSkipped('encryption is not configured here: ' . $e->getMessage());
            }
            [$mime, $bin] = $this->svc->staffIdCardGet($staff, 'front');
            $this->assertSame('image/jpeg', $mime, 're-encoded: metadata is dropped');
            $this->assertLessThanOrEqual(1600, getimagesizefromstring($bin)[0], 'resized');
            $stored = (string) $this->db->query('SELECT data_enc FROM platform_staff_docs WHERE user_id = ? AND side = ?', [$staff, 'front'])[0]['data_enc'];
            $this->assertStringNotContainsString(base64_encode(substr($bin, 0, 12)), $stored, 'the image is stored encrypted');
            $this->assertNotNull($this->svc->staffIdCardStatus($staff)['front']);
            $this->assertNull($this->svc->staffIdCardStatus($staff)['back']);

            $r = $this->svc->staffIdCardExtract($staff, fn ($b, $m, $p) => ['success' => true, 'data' => '{"full_name":"سارة أحمد","national_id":"29901011234567","address":"القاهرة"}']);
            $this->assertTrue($r['ok']);
            $this->assertSame('سارة أحمد', $r['fields']['full_name']);
            $this->assertSame('', $this->svc->staffProfile($staff, true)['full_name'], 'reading the card only suggests: nothing is saved until the employee confirms');
            $this->assertFalse($this->svc->staffIdCardExtract($staff, fn ($b, $m, $p) => ['success' => false, 'error' => 'x'])['ok']);
            $this->assertFalse($this->svc->staffIdCardExtract($staff, function () { throw new RuntimeException('down'); })['ok'], 'an AI outage falls back to manual entry');

            foreach ([['front', 'not an image'], ['middle', $png], ['front', '']] as [$side, $data]) {
                try {
                    $this->svc->staffIdCardSave($staff, $side, $data);
                    $this->fail("$side should be rejected");
                } catch (InvalidArgumentException $e) {
                    $this->assertNotSame('', $e->getMessage());
                }
            }
        } finally {
            $this->db->query('DELETE FROM platform_staff_docs WHERE user_id = ?', [$staff]);
        }
    }

    public function testFirstLocationIsTheBaselineAndTheDistanceToTheAddressFlagsFarOnes(): void
    {
        [$staff] = $this->twoUsers();
        $this->db->query('DELETE FROM platform_staff_location WHERE user_id = ?', [$staff]);
        $this->db->query('DELETE FROM platform_staff_profile WHERE user_id = ?', [$staff]);
        try {
            $this->assertNull($this->svc->staffLocation($staff));
            $this->assertFalse($this->svc->staffMe($staff)['location_recorded']);
            $loc = $this->svc->staffLocationSave($staff, 30.0444, 31.2357, 25); // Cairo
            $this->assertSame(1, $loc['count']);
            $this->assertSame(25, $loc['first']['accuracy_m']);
            $this->assertStringContainsString('30.0444,31.2357', $loc['first']['maps_url']);
            $this->assertNull($loc['distance_km'], 'no address in the profile yet');
            $this->assertTrue($this->svc->staffMe($staff)['location_recorded']);

            // العنوان اتحوّل لإحداثيات (بنحطها مباشرة من غير نداء جوجل): الإسكندرية ≈ 180 كم من القاهرة
            $this->svc->staffProfileSet($staff, ['address' => '10 Corniche, Alexandria'], false, 'Sara');
            $this->db->query('UPDATE platform_staff_location SET address_lat = 31.2001, address_lng = 29.9187, address_for = ? WHERE user_id = ? AND is_first = 1', [substr(md5('10 Corniche, Alexandria'), 0, 12), $staff]);
            $far = $this->svc->staffLocation($staff);
            $this->assertEqualsWithDelta(180, $far['distance_km'], 15);
            $this->assertTrue($far['far']);

            $this->svc->staffLocationSave($staff, 30.0500, 31.2400, null);
            $again = $this->svc->staffLocation($staff);
            $this->assertSame(2, $again['count']);
            $this->assertSame(30.0444, $again['first']['lat'], 'the first capture stays the baseline');
            $this->assertSame(30.05, $again['latest']['lat']);

            foreach ([[91, 0], [0, 181], [0, 0]] as [$la, $ln]) {
                try {
                    $this->svc->staffLocationSave($staff, (float) $la, (float) $ln, null);
                    $this->fail("$la,$ln should be rejected");
                } catch (InvalidArgumentException $e) {
                    $this->assertNotSame('', $e->getMessage());
                }
            }
        } finally {
            $this->db->query('DELETE FROM platform_staff_location WHERE user_id = ?', [$staff]);
            $this->db->query('DELETE FROM platform_staff_profile WHERE user_id = ?', [$staff]);
        }
    }

    public function testLocationIsHiddenFromTheEmployeeButShownToTheOwnerAndPhotoCountsTowardsTheDocuments(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is not available');
        }
        [$staff, $owner] = $this->twoUsers();
        foreach (['platform_staff_location', 'platform_staff_docs', 'platform_staff_doc_views'] as $t) {
            $this->db->query("DELETE FROM $t WHERE user_id = ?", [$staff]);
        }
        try {
            $this->svc->staffLocationSave($staff, 30.0444, 31.2357, 20);
            $self = $this->svc->staffReport($staff, date('Y-m'), 50.0, true, false);
            $this->assertNull($self['location'], 'the employee never gets the coordinates or the distance');
            $this->assertTrue($self['location_recorded'], 'only that it was recorded');
            $this->assertSame([], $self['doc_views']);
            $admin = $this->svc->staffReport($staff, date('Y-m'), 50.0, true, true);
            $this->assertSame(30.0444, $admin['location']['first']['lat']);

            $img = imagecreatetruecolor(400, 300);
            ob_start();
            imagejpeg($img);
            $jpg = (string) ob_get_clean();
            try {
                $this->svc->staffIdCardSave($staff, 'front', $jpg);
            } catch (Throwable $e) {
                $this->markTestSkipped('encryption is not configured here: ' . $e->getMessage());
            }
            $this->assertFalse($this->svc->staffMe($staff)['id_card'], 'the front alone is not enough: the personal photo is part of it');
            $this->svc->staffIdCardSave($staff, 'photo', $jpg);
            $this->assertTrue($this->svc->staffMe($staff)['id_card']);
            $this->assertNotNull($this->svc->staffIdCardStatus($staff)['photo']);
            $this->assertSame('image/jpeg', $this->svc->staffIdCardGet($staff, 'photo')[0]);

            $this->svc->staffDocViewLog($owner, $staff, 'photo');
            $views = $this->svc->staffReport($staff, date('Y-m'), 50.0, true, true)['doc_views'];
            $this->assertCount(1, $views);
            $this->assertSame('photo', $views[0]['side']);
        } finally {
            foreach (['platform_staff_location', 'platform_staff_docs', 'platform_staff_doc_views'] as $t) {
                $this->db->query("DELETE FROM $t WHERE user_id = ?", [$staff]);
            }
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

    public function testDailyDigestEmailsEachEmployeeTheirDueFollowUpsOncePerDay(): void
    {
        [$sara, $mona] = $this->twoUsers();
        $due = $this->lead('Digest Due Tours', 'EG', '+201000000101');
        $fresh = $this->lead('Digest Fresh Tours', 'EG', '+201000000102');
        $this->svc->deskAction($due, 'contacted', '', $sara, 'Sara');
        $this->svc->deskAction($fresh, 'contacted', '', $mona, 'Mona');
        $this->db->query('UPDATE platform_leads SET desk_at = NOW() - INTERVAL 3 DAY WHERE id = ?', [$due]);
        $settings = new SystemSettingsService();
        $settings->set('outreach_digest_date', '');
        $mails = [];
        $send = function ($to, $name, $subject, $html, $headers) use (&$mails) { $mails[] = compact('to', 'subject', 'html'); };
        try {
            $n = $this->svc->sendFollowupDigest($send, '2030-01-15');
            $saraEmail = (string) $this->db->query('SELECT email FROM users WHERE id = ?', [$sara])[0]['email'];
            $forSara = array_values(array_filter($mails, fn ($m) => $m['to'] === $saraEmail));
            $this->assertCount(1, $forSara, 'Sara has a follow-up that is due');
            $this->assertStringContainsString('Digest Due Tours', $forSara[0]['html']);
            $this->assertStringNotContainsString('Digest Fresh Tours', $forSara[0]['html'], 'a send from today is not due yet');
            $this->assertGreaterThanOrEqual(1, $n);
            $this->assertSame(0, $this->svc->sendFollowupDigest($send, '2030-01-15'), 'only once per day');
            $this->assertGreaterThanOrEqual(0, $this->svc->sendFollowupDigest($send, '2030-01-16'), 'next day it can run again');
        } finally {
            $settings->set('outreach_digest_date', '');
        }
    }

    public function testAYearlyInvoiceCoversTheNextElevenMonthsAndAMonthlyOneOnlyItsOwnMonth(): void
    {
        $from = '2026-11-01 00:00:00';
        $to = '2026-11-30 23:59:59';
        $this->assertTrue(PlatformOutreachService::coveredByPaidInvoice(['2026-11-10 09:00:00'], 'monthly', $from, $to));
        $this->assertFalse(PlatformOutreachService::coveredByPaidInvoice(['2026-10-10 09:00:00'], 'monthly', $from, $to), 'last month\'s invoice does not pay this month');
        $this->assertTrue(PlatformOutreachService::coveredByPaidInvoice(['2026-03-10 09:00:00'], 'yearly', $from, $to), 'a yearly invoice paid in March still covers November');
        $this->assertFalse(PlatformOutreachService::coveredByPaidInvoice(['2025-10-30 09:00:00'], 'yearly', $from, $to), 'but not 13 months later');
        $this->assertFalse(PlatformOutreachService::coveredByPaidInvoice([], 'monthly', $from, $to));
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

    public function testFirstEmployeeToSendClaimsTheCompanyOthersAreRejectedButTheOwnerOfTheTeamCanStillRecordAResult(): void
    {
        [$sara, $mona] = $this->twoUsers();
        $id = $this->lead('Claimrace Tours', 'EG', '+201000000061');
        $this->svc->deskAction($id, 'contacted', '', $sara, 'Sara');
        $row = $this->db->query('SELECT desk_user_id, desk_by FROM platform_leads WHERE id = ?', [$id])[0];
        $this->assertSame($sara, (int) $row['desk_user_id']);
        foreach (['contacted', 'replied', 'undo'] as $act) {
            try {
                $this->svc->deskAction($id, $act, '', $mona, 'Mona');
                $this->fail("$act by another employee must be rejected");
            } catch (InvalidArgumentException $e) {
                $this->assertSame(409, $e->getCode());
                $this->assertStringContainsString('Sara', $e->getMessage(), 'the message says who already has it');
            }
        }
        $this->assertSame('contacted', $this->db->query('SELECT desk_action FROM platform_leads WHERE id = ?', [$id])[0]['desk_action']);

        $this->svc->deskAction($id, 'replied', 'owner saw the reply', $mona, 'Mona', true); // مدير بيسجّل نتيجة
        $after = $this->db->query('SELECT desk_action, desk_user_id, desk_by FROM platform_leads WHERE id = ?', [$id])[0];
        $this->assertSame('replied', $after['desk_action']);
        $this->assertSame($sara, (int) $after['desk_user_id'], 'the company stays attributed to the one who sent it');
        $this->assertSame('Sara', $after['desk_by']);

        $this->svc->deskAction($id, 'interested', '', $sara, 'Sara'); // صاحبها يكمّل عادي
    }

    public function testOtherEmployeesDoNotSeeAClaimedCompanyAndTheListsAreScopedPerEmployee(): void
    {
        [$sara, $mona] = $this->twoUsers();
        $a = $this->lead('Scope Alpha Tours', 'EG', '+201000000071');
        $b = $this->lead('Scope Beta Tours', 'EG', '+201000000072');
        $this->svc->deskAction($a, 'contacted', '', $sara, 'Sara');
        $names = fn (int $uid, string $view, bool $all = false) => array_column($this->svc->deskLeads(['country' => 'EG', 'view' => $view, 'q' => 'Scope '], ['user_id' => $uid, 'all' => $all])['rows'], 'company');

        $this->assertSame(['Scope Beta Tours'], $names($mona, 'todo'), 'claimed by Sara: gone from everyone else\'s to-do');
        $this->assertSame([], $names($mona, 'contacted'), 'and not in their contacted list');
        $this->assertSame(['Scope Alpha Tours'], $names($sara, 'contacted'));
        $this->assertSame(['Scope Beta Tours'], $names($mona, 'all'), 'the all view hides other people\'s companies');
        $this->assertEqualsCanonicalizing(['Scope Alpha Tours', 'Scope Beta Tours'], $names($mona, 'all', true), 'the team owner sees everything');

        $counts = fn (int $uid, bool $all) => array_values(array_filter($this->svc->deskLeads(['country' => 'EG', 'q' => 'Scope '], ['user_id' => $uid, 'all' => $all])['counts'], fn ($c) => $c['country'] === 'EG'))[0];
        $this->assertSame(0, (int) $counts($mona, false)['contacted'], 'counters are scoped too');
        $this->assertGreaterThanOrEqual(1, (int) $counts($sara, false)['contacted']);

        $claimed = $this->svc->deskClaimed([$a, $b], $mona);
        $this->assertSame([$a], array_column($claimed, 'id'), 'the live check reports only what someone else took');
        $this->assertSame('Sara', $claimed[0]['by']);
        $this->assertSame([], $this->svc->deskClaimed([$a], $sara), 'not what I took myself');
        $this->assertSame([], $this->svc->deskClaimed([], $mona));
    }

    public function testSentListGroupsByDayShowsWhoSentAndSkipsUndoneSends(): void
    {
        [$sara, $mona] = $this->twoUsers();
        $a = $this->lead('Sentlist One Tours', 'EG', '+201000000081');
        $b = $this->lead('Sentlist Two Tours', 'EG', '+201000000082');
        $c = $this->lead('Sentlist Three Tours', 'EG', '+201000000083');
        $this->svc->deskAction($a, 'contacted', 'first', $sara, 'Sara');
        $this->svc->deskAction($b, 'contacted', '', $mona, 'Mona');
        $this->svc->deskAction($c, 'contacted', '', $sara, 'Sara');
        $this->svc->deskAction($c, 'undo', '', $sara, 'Sara'); // اتراجع عنها: مش مرسولة
        $this->svc->deskAction($a, 'replied', '', $sara, 'Sara');
        $this->db->query("UPDATE platform_desk_actions SET created_at = created_at - INTERVAL 1 DAY WHERE lead_id = ? AND action = 'contacted'", [$b]);
        $this->svc->deskAction($a, 'contacted', 'follow-up', $sara, 'Sara'); // متابعة لنفس الشركة

        $mine = $this->svc->deskSent(['from' => date('Y-m-d', strtotime('-3 days')), 'to' => date('Y-m-d'), 'q' => 'Sentlist'], $sara, false);
        $companies = array_merge(...array_map(fn ($d) => array_column($d['items'], 'company'), $mine['days']));
        $this->assertEqualsCanonicalizing(['Sentlist One Tours', 'Sentlist One Tours'], $companies, 'an employee sees only her own sends, the undone one excluded');
        $kinds = array_column($mine['days'][0]['items'], 'kind');
        $this->assertContains('first', $kinds);
        $this->assertContains('followup', $kinds, 'a second send to the same company is a follow-up');
        $this->assertSame('followup', $mine['days'][0]['items'][0]['kind'], 'newest first');
        $this->assertSame('contacted', $mine['days'][0]['items'][0]['result'], 'a new send puts the company back to "sent, no reply yet"');
        $this->assertSame('Sara', $mine['days'][0]['items'][0]['staff']);

        $all = $this->svc->deskSent(['from' => date('Y-m-d', strtotime('-3 days')), 'to' => date('Y-m-d'), 'q' => 'Sentlist'], $sara, true);
        $this->assertSame(3, $all['total'], 'the owner sees everyone: two from Sara and one from Mona');
        $this->assertCount(2, $all['days'], 'grouped by day: today and yesterday');
        $this->assertGreaterThan($all['days'][1]['date'], $all['days'][0]['date'], 'newest day first');
        $this->assertSame(['Mona'], array_column($all['days'][1]['items'], 'staff'));
        $onlyMona = $this->svc->deskSent(['from' => date('Y-m-d', strtotime('-3 days')), 'to' => date('Y-m-d'), 'q' => 'Sentlist', 'staff_id' => $mona], $sara, true);
        $this->assertSame(1, $onlyMona['total']);
        $this->assertContains($mona, array_column($all['staff'], 'staff_id'));
        $this->assertSame([], $this->svc->deskSent(['q' => 'Sentlist', 'staff_id' => $mona], $sara, false)['staff'], 'the staff filter list is for the owner only');
        $this->assertSame(0, $this->svc->deskSent(['from' => '2020-01-01', 'to' => '2020-01-02'], $sara, false)['total']);
    }

    public function testLeadsAlreadyEmailedGetTheirGoogleDataRefreshedWithoutChangingTheirEmailTrack(): void
    {
        $id = $this->lead('Refresh Sent Tours', 'EG', '+201000000091', ['step' => 1, 'track' => 'seo']);
        $stale = ['audit' => ['gaps' => [], 'score' => ['total' => 50, 'pillars' => []], 'google' => ['checked' => true, 'found' => true, 'rating' => 3.1, 'reviews' => 7], 'at' => '2026-11-01 10:00:00']];
        $this->db->query("UPDATE platform_leads SET data_json = ?, scanned_at = NOW(), track = 'seo', track2 = 'ads', needs_json = ?, website = NULL, domain = NULL WHERE id = ?", [json_encode($stale), '[{"track":"seo"}]', $id]);
        $svc = new PlatformOutreachService(null, null, fn () => null);
        $svc->places = fn () => ['candidates' => []]; // جوجل: مفيش ملف للشركة بالاسم ده
        $svc->probe = fn () => 'error';
        $svc->scan(50, 60);
        $row = $this->db->query('SELECT data_json, track, track2, needs_json FROM platform_leads WHERE id = ?', [$id])[0];
        $data = json_decode($row['data_json'], true);
        $this->assertSame(8, $data['audit']['v'], 'marked as refreshed');
        $this->assertFalse($data['audit']['google']['found'], 'the wrong 3.1 rating from another place is gone');
        $this->assertSame('seo', $row['track'], 'the follow-up emails keep their topic');
        $this->assertSame('ads', $row['track2']);
        $this->assertSame('[{"track":"seo"}]', $row['needs_json']);
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

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
        $this->assertSame('https://wa.me/201015602770', $row['wa_url']);
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

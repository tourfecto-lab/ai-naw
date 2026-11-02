<?php

/**
 * "الحملات اللي اتبعتت" في تسويق المنصة: الإرسال بيحفظ المستلم ونص الرسالة، والملخص والسجل والتفاصيل بتقرا منهم.
 */

use PHPUnit\Framework\TestCase;

final class OutreachCampaignsTest extends TestCase
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
        $ids = array_map('intval', array_column($this->db->query("SELECT id FROM platform_leads WHERE source = 'phpunit-campaigns'") ?: [], 'id'));
        if ($ids) $this->db->query('DELETE FROM platform_lead_messages WHERE lead_id IN (' . implode(',', $ids) . ')');
        $this->db->query("DELETE FROM platform_leads WHERE source = 'phpunit-campaigns'");
    }

    private function lead(string $name, string $country, array $v = []): int
    {
        $v += ['status' => 'contacted', 'step' => 1, 'approved' => 1, 'track' => 'seo', 'report' => null];
        $this->db->query("INSERT INTO platform_leads (source, company, email, domain, website, country, city, category, language, status, approved, step, track, score, token, report_viewed_at)
            VALUES ('phpunit-campaigns', ?, ?, ?, ?, ?, 'Cairo', 'Tour agency', 'ar', ?, ?, ?, ?, 70, ?, ?)",
            [$name, strtolower(str_replace(' ', '', $name)) . '@campaigns.test', strtolower(str_replace(' ', '', $name)) . '.test', 'https://' . strtolower(str_replace(' ', '', $name)) . '.test', $country, $v['status'], $v['approved'], $v['step'], $v['track'], bin2hex(random_bytes(16)), $v['report']]);
        return (int) ($this->db->query("SELECT MAX(id) AS id FROM platform_leads WHERE source = 'phpunit-campaigns'")[0]['id'] ?? 0);
    }

    private function message(int $lead, array $v = []): int
    {
        $v += ['step' => 1, 'status' => 'sent', 'track' => 'seo', 'subject' => 'Campaign subject', 'clicked' => null, 'body' => null, 'to' => null, 'at' => '2026-11-02 16:30:00'];
        $this->db->query('INSERT INTO platform_lead_messages (lead_id, step, subject, status, track, sent_at, clicked_at, to_email, body_html) VALUES (?,?,?,?,?,?,?,?,?)',
            [$lead, $v['step'], $v['subject'], $v['status'], $v['track'], $v['at'], $v['clicked'], $v['to'], $v['body']]);
        return (int) ($this->db->query('SELECT MAX(id) AS id FROM platform_lead_messages WHERE lead_id = ?', [$lead])[0]['id'] ?? 0);
    }

    public function testCampaignsGroupByCountryTrackAndStepWithTotals(): void
    {
        $a = $this->lead('Alpha Tours', 'EG', ['report' => '2026-11-02 17:00:00']);
        $b = $this->lead('Beta Tours', 'EG', ['status' => 'signed_up']);
        $c = $this->lead('Gamma Tours', 'EG', ['status' => 'bounced']);
        $t = $this->lead('Delta Tours', 'TR', ['track' => 'reputation']);
        $this->message($a, ['clicked' => '2026-11-02 16:40:00']);
        $this->message($b);
        $this->message($c, ['status' => 'failed']);
        $this->message($t, ['track' => 'reputation']);
        $this->message($a, ['step' => 2, 'at' => '2026-11-05 09:00:00']);

        $r = $this->svc->campaigns();
        $eg1 = array_values(array_filter($r['campaigns'], static fn ($x) => $x['country'] === 'EG' && $x['track'] === 'seo' && $x['step'] === 1))[0] ?? null;
        $this->assertNotNull($eg1);
        $this->assertSame(2, $eg1['sent']);
        $this->assertSame(1, $eg1['failed']);
        $this->assertSame(2, $eg1['recipients']);
        $this->assertSame(1, $eg1['clicked']);
        $this->assertSame(1, $eg1['report_views']);
        $this->assertSame(1, $eg1['signed_up']);
        $this->assertSame(1, $eg1['bounced']);
        $this->assertSame('2026-11-02 16:30:00', $eg1['first_sent_at']);
        $this->assertCount(3, array_filter($r['campaigns'], static fn ($x) => in_array($x['country'], ['EG', 'TR'], true) && $x['track'] !== '' && str_contains(json_encode($x), '2026-11')), 'EG step1, EG step2, TR step1 are separate campaigns');
        $this->assertGreaterThanOrEqual(4, $r['totals']['sent']);
        $this->assertGreaterThanOrEqual(1, $r['totals']['failed']);
    }

    public function testSentMessagesFiltersAndPaginates(): void
    {
        $a = $this->lead('Alpha Tours', 'EG');
        $b = $this->lead('Beta Tours', 'TR');
        $this->message($a, ['subject' => 'Hello Alpha']);
        $this->message($a, ['status' => 'failed', 'subject' => 'Bad one']);
        $this->message($b, ['subject' => 'Hello Beta']);

        $eg = $this->svc->sentMessages(['country' => 'EG', 'q' => 'campaigns.test']);
        $this->assertSame(2, $eg['total']);
        $this->assertSame(['EG'], array_values(array_unique(array_column($eg['rows'], 'country'))));
        $failed = $this->svc->sentMessages(['country' => 'EG', 'status' => 'failed', 'q' => 'Alpha']);
        $this->assertSame(1, $failed['total']);
        $this->assertSame('Bad one', $failed['rows'][0]['subject']);
        $this->assertSame('Alpha Tours', $failed['rows'][0]['company']);
        $this->assertSame(0, $this->svc->sentMessages(['q' => 'no-such-company-xyz'])['total']);
        // injection-looking input is only a LIKE pattern
        $this->assertSame(0, $this->svc->sentMessages(['q' => "' OR 1=1 -- "])['total']);
    }

    public function testDetailUsesStoredBodyAndListsEvents(): void
    {
        $a = $this->lead('Alpha Tours', 'EG', ['report' => '2026-11-02 18:00:00']);
        $id = $this->message($a, ['body' => '<p>exact stored body</p>', 'clicked' => '2026-11-02 16:40:00', 'to' => 'info@alpha.test']);
        $this->message($a, ['step' => 2, 'at' => '2026-11-05 09:00:00']);

        $d = $this->svc->messageDetail($id);
        $this->assertSame('stored', $d['html_source']);
        $this->assertSame('<p>exact stored body</p>', $d['html']);
        $this->assertSame('info@alpha.test', $d['message']['to_email']);
        $this->assertSame(['sent', 'clicked', 'report_viewed'], array_column($d['events'], 'type'));
        $this->assertCount(2, $d['siblings']);
        $this->assertStringContainsString('/p/r/pv-', $d['report_url'], 'admin report link must not count as a company view');
        $this->assertNull($this->svc->messageDetail(999999999));
    }

    public function testDetailRendersOldMessagesAgainWithoutBody(): void
    {
        $a = $this->lead('Alpha Tours', 'EG');
        $id = $this->message($a);
        $d = $this->svc->messageDetail($id);
        $this->assertSame('rendered_again', $d['html_source']);
        $this->assertStringContainsString('/p/u/', $d['html'], 'unsubscribe link is part of the re-rendered email');
        $this->assertSame('Alpha Tours', $d['lead']['company']);
    }

    public function testRunStoresRecipientAndBodyOfWhatWasSent(): void
    {
        $a = $this->lead('Alpha Tours', 'EG', ['status' => 'new', 'step' => 0]);
        $this->svc->checkMx = false;
        $sent = [];
        $this->svc->run(static function ($to, $name, $subject, $html, $headers) use (&$sent) {
            $sent[$to] = $html;
            return ['success' => true];
        }, static fn () => null, true);

        $row = $this->db->query('SELECT to_email, body_html, status FROM platform_lead_messages WHERE lead_id = ?', [$a])[0] ?? null;
        $this->assertNotNull($row, 'the run should have sent the approved lead');
        $this->assertSame('alphatours@campaigns.test', $row['to_email']);
        $this->assertSame('sent', $row['status']);
        $this->assertSame($sent['alphatours@campaigns.test'], $row['body_html']);
    }
}

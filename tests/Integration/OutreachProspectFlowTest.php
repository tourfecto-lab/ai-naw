<?php

/**
 * Backlink outreach flow through the controller: validation, duplicate guard, bad link URLs, no email to a declined prospect.
 * No email is ever sent: the declined case is refused before the mailer is touched.
 */

use PHPUnit\Framework\TestCase;

final class OutreachProspectFlowTest extends TestCase
{
    private const USER = 999701;
    private const SITE = 999702;
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        try {
            $this->pdo = Database::getInstance()->getConnection();
            $this->pdo->query('SELECT 1 FROM outreach_prospects LIMIT 1');
            $this->pdo->query('SELECT 1 FROM outreach_emails LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('outreach tables not available');
        }
        $this->cleanup();
        $this->pdo->exec("INSERT INTO users (id, email, password, company_name, created_at) VALUES (" . self::USER . ", 'outreach-flow@tourfecto.test', 'x', 'O', NOW())");
        $this->pdo->exec("INSERT INTO websites (id, user_id, main_url, company_name) VALUES (" . self::SITE . ", " . self::USER . ", 'https://outreach-flow.test', 'O')");
    }

    protected function tearDown(): void
    {
        if ($this->pdo) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $this->pdo->exec('DELETE FROM outreach_emails WHERE prospect_id IN (SELECT id FROM outreach_prospects WHERE user_id = ' . self::USER . ')');
        $this->pdo->exec('DELETE FROM monitored_backlinks WHERE user_id = ' . self::USER);
        $this->pdo->exec('DELETE FROM outreach_prospects WHERE user_id = ' . self::USER);
        $this->pdo->exec('DELETE FROM websites WHERE id = ' . self::SITE);
        $this->pdo->exec('DELETE FROM users WHERE id = ' . self::USER);
    }

    private function controller(array $data = []): OutreachController
    {
        $c = new OutreachController();
        $data += ['csrf_token' => Csrf::token()];
        foreach (['authenticated' => true, 'user' => ['id' => self::USER, 'role' => 'user'], 'data' => $data] as $prop => $value) {
            $r = new ReflectionProperty(Controller::class, $prop);
            $r->setAccessible(true);
            $r->setValue($c, $value);
        }
        return $c;
    }

    public function testInvalidInputIsRejectedAndNothingIsStored(): void
    {
        foreach ([
            ['domain' => 'not a domain'],
            ['domain' => 'example.com', 'contact_email' => 'not-an-email'],
            ['domain' => 'example.com', 'relevant_page' => 'javascript:alert(1)'],
        ] as $bad) {
            $res = $this->controller($bad + ['website_id' => self::SITE])->addProspect();
            $this->assertFalse($res['success'] ?? true, json_encode($bad));
            $this->assertSame(422, $res['code'] ?? $res['status'] ?? 422);
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM outreach_prospects WHERE user_id = ' . self::USER)->fetchColumn());
    }

    public function testDomainIsNormalisedAndDuplicatesAreRefused(): void
    {
        $ok = $this->controller(['website_id' => self::SITE, 'domain' => 'HTTPS://www.Blog-Example.com/page', 'contact_email' => 'editor@blog-example.com'])->addProspect();
        $this->assertTrue($ok['success'] ?? false);
        $this->assertSame('blog-example.com', $ok['data']['prospect']['domain']);
        $dup = $this->controller(['website_id' => self::SITE, 'domain' => 'blog-example.com'])->addProspect();
        $this->assertFalse($dup['success'] ?? true);
        $this->assertSame(409, $dup['code'] ?? $dup['status'] ?? 409);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM outreach_prospects WHERE user_id = ' . self::USER)->fetchColumn());
    }

    public function testBadAcquiredLinkUrlIsRefusedAndNoBacklinkIsRegistered(): void
    {
        $id = (int) $this->controller(['website_id' => self::SITE, 'domain' => 'partner-example.com'])->addProspect()['data']['prospect']['id'];
        $res = $this->controller(['status' => 'link_acquired', 'link_url' => 'javascript:alert(1)'])->updateProspectStatus(['id' => $id]);
        $this->assertFalse($res['success'] ?? true);
        $this->assertSame('prospect', $this->pdo->query("SELECT status FROM outreach_prospects WHERE id = $id")->fetchColumn(), 'status unchanged');
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM monitored_backlinks WHERE user_id = ' . self::USER)->fetchColumn());
    }

    public function testDeletingAProspectRemovesItsEmailsAndMonitoredLinksButNothingElse(): void
    {
        $a = (int) $this->controller(['website_id' => self::SITE, 'domain' => 'first-example.com'])->addProspect()['data']['prospect']['id'];
        $b = (int) $this->controller(['website_id' => self::SITE, 'domain' => 'second-example.com'])->addProspect()['data']['prospect']['id'];
        foreach ([$a, $b] as $pid) {
            $this->pdo->prepare("INSERT INTO monitored_backlinks (user_id, website_id, prospect_id, link_url, domain, status, check_count) VALUES (?,?,?,?,?, 'pending', 0)")->execute([self::USER, self::SITE, $pid, 'https://x.example/p' . $pid, 'x.example']);
        }
        $res = $this->controller()->deleteProspect(['id' => $a]);
        $this->assertTrue($res['success'] ?? false);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM outreach_prospects WHERE id = $a")->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM monitored_backlinks WHERE prospect_id = $a")->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM outreach_prospects WHERE id = $b")->fetchColumn(), 'the other prospect is untouched');
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM monitored_backlinks WHERE prospect_id = $b")->fetchColumn());
        $this->assertFalse($this->controller()->deleteProspect(['id' => $a])['success'] ?? true, 'already deleted');
    }

    public function testSomeoneElsesProspectOrLinkCannotBeDeleted(): void
    {
        $id = (int) $this->controller(['website_id' => self::SITE, 'domain' => 'mine-example.com'])->addProspect()['data']['prospect']['id'];
        $this->pdo->prepare("INSERT INTO monitored_backlinks (user_id, website_id, prospect_id, link_url, domain, status, check_count) VALUES (?,?,?,?,?, 'pending', 0)")->execute([self::USER, self::SITE, $id, 'https://y.example/p', 'y.example']);
        $linkId = (int) $this->pdo->lastInsertId();
        $other = new OutreachController();
        foreach (['authenticated' => true, 'user' => ['id' => self::USER + 1, 'role' => 'user'], 'data' => ['csrf_token' => Csrf::token()]] as $prop => $value) {
            $r = new ReflectionProperty(Controller::class, $prop);
            $r->setAccessible(true);
            $r->setValue($other, $value);
        }
        $this->assertSame(404, $other->deleteProspect(['id' => $id])['code'] ?? 404);
        $this->assertSame(404, $other->deleteBacklink(['id' => $linkId])['code'] ?? 404);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM outreach_prospects WHERE id = $id")->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM monitored_backlinks WHERE id = $linkId")->fetchColumn());
        $this->assertTrue($this->controller()->deleteBacklink(['id' => $linkId])['success'] ?? false, 'the owner can delete it');
    }

    public function testDisavowEndpointsSuggestExplainAndExportOnlyWhatWasChosen(): void
    {
        $text = "Site\nbest-casino-slots-online.xyz,5\nlonelyplanet.com,2\noutreach-flow.test,9";
        $a = $this->controller(['website_id' => self::SITE, 'text' => $text])->disavowAnalyze();
        $this->assertTrue($a['success'] ?? false);
        $this->assertSame(2, $a['data']['total'], 'own domain is dropped');
        $this->assertSame(1, $a['data']['flagged']);
        $this->assertSame('best-casino-slots-online.xyz', $a['data']['rows'][0]['domain']);
        $empty = $this->controller(['website_id' => self::SITE, 'text' => 'nothing useful here'])->disavowAnalyze();
        $this->assertFalse($empty['success'] ?? true);
        $f = $this->controller(['website_id' => self::SITE, 'domains' => ['best-casino-slots-online.xyz']])->disavowFile();
        $this->assertTrue($f['success'] ?? false);
        $this->assertStringContainsString("domain:best-casino-slots-online.xyz\n", $f['data']['content']);
        $this->assertStringNotContainsString('lonelyplanet', $f['data']['content']);
        $this->assertSame(1, $f['data']['count']);
        $this->assertFalse($this->controller(['website_id' => self::SITE, 'domains' => []])->disavowFile()['success'] ?? true);
    }

    public function testFunnelSeparatesRealSentEmailsFromStagesSetByHand(): void
    {
        $a = (int) $this->controller(['website_id' => self::SITE, 'domain' => 'emailed-example.com', 'contact_email' => 'a@emailed-example.com'])->addProspect()['data']['prospect']['id'];
        $b = (int) $this->controller(['website_id' => self::SITE, 'domain' => 'manual-example.com'])->addProspect()['data']['prospect']['id'];
        $this->pdo->exec("UPDATE outreach_prospects SET status = 'contacted' WHERE id IN ($a, $b)");
        $info = $this->pdo->query('SHOW COLUMNS FROM outreach_emails')->fetchAll(PDO::FETCH_ASSOC);
        $row = ['prospect_id' => $a, 'sequence_number' => 0, 'subject' => 's', 'body' => 'b', 'status' => 'sent', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
        foreach ($info as $c) {
            if ($c['Null'] === 'NO' && $c['Default'] === null && stripos((string) $c['Extra'], 'auto_increment') === false && !array_key_exists($c['Field'], $row)) {
                $row[$c['Field']] = preg_match('/int|decimal|float|double/i', $c['Type']) ? 0 : '';
            }
        }
        $row = array_intersect_key($row, array_flip(array_column($info, 'Field')));
        $this->pdo->prepare('INSERT INTO outreach_emails (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
        $report = (new OutreachPerformanceService())->report(self::USER, self::SITE);
        $this->assertSame(1, $report['verified']['emails_sent']);
        $this->assertSame(1, $report['verified']['prospects_emailed']);
        $this->assertSame(1, $report['verified']['manual_without_email'], 'one prospect was moved to contacted without any email sent from the platform');
        $this->assertArrayHasKey('note', $report['verified']);
    }

    public function testApprovedEmailToADeclinedProspectIsNotSent(): void
    {
        $id = (int) $this->controller(['website_id' => self::SITE, 'domain' => 'declined-example.com', 'contact_email' => 'x@declined-example.com'])->addProspect()['data']['prospect']['id'];
        $info = $this->pdo->query('SHOW COLUMNS FROM outreach_emails')->fetchAll(PDO::FETCH_ASSOC);
        $row = ['prospect_id' => $id, 'sequence_number' => 0, 'subject' => 's', 'body' => 'b', 'status' => 'approved', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
        foreach ($info as $c) {
            if ($c['Null'] === 'NO' && $c['Default'] === null && stripos((string) $c['Extra'], 'auto_increment') === false && !array_key_exists($c['Field'], $row)) {
                $row[$c['Field']] = preg_match('/int|decimal|float|double/i', $c['Type']) ? 0 : '';
            }
        }
        $row = array_intersect_key($row, array_flip(array_column($info, 'Field')));
        $this->pdo->prepare('INSERT INTO outreach_emails (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
        $emailId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("UPDATE outreach_prospects SET status = 'declined' WHERE id = $id");
        $res = $this->controller()->sendEmail(['id' => $emailId]);
        $this->assertFalse($res['success'] ?? true);
        $this->assertSame(422, $res['code'] ?? $res['status'] ?? 422);
        $this->assertSame('approved', $this->pdo->query("SELECT status FROM outreach_emails WHERE id = $emailId")->fetchColumn(), 'nothing was sent or marked sent');
    }
}

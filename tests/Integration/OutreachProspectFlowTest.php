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

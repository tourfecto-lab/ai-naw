<?php

/**
 * Reputation: private guest feedback survey (signed link, validation, spam limits, low-score alert, NPS, tenant isolation).
 */

use PHPUnit\Framework\TestCase;

final class GuestSurveysTest extends TestCase
{
    private const A = 999091;
    private const B = 999092;
    private const WA = 999091;
    private const WB = 999092;

    private ?PDO $pdo = null;
    private ?Database $db = null;

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
        if (!$this->pdo instanceof PDO || !GuestSurveys::ready($this->db)) {
            $this->markTestSkipped('DB or guest_surveys table not available');
        }
        $this->cleanup();
        $this->pdo->exec("INSERT INTO users (id, email, password, company_name, created_at) VALUES (" . self::A . ", 'sv-a@tourfecto.test', 'x', 'A', NOW()), (" . self::B . ", 'sv-b@tourfecto.test', 'x', 'B', NOW())");
        $this->pdo->exec("INSERT INTO websites (id, user_id, main_url, company_name, industry, is_verified, created_at) VALUES (" . self::WA . ", " . self::A . ", 'https://sv-a.test', 'A Tours', 'tourism', 1, NOW()), (" . self::WB . ", " . self::B . ", 'https://sv-b.test', 'B Tours', 'tourism', 1, NOW())");
    }

    protected function tearDown(): void
    {
        if ($this->pdo) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM guest_surveys WHERE user_id IN (" . self::A . "," . self::B . ")");
        $this->pdo->exec("DELETE FROM notifications WHERE user_id IN (" . self::A . "," . self::B . ")");
        $this->pdo->exec("DELETE FROM websites WHERE id IN (" . self::WA . "," . self::WB . ")");
        $this->pdo->exec("DELETE FROM users WHERE id IN (" . self::A . "," . self::B . ")");
    }

    private function send(int $site, array $in, string $ip = 'ip-1'): array
    {
        return GuestSurveys::submit($site, $in, GuestSurveys::ipHash($ip), $this->db);
    }

    public function testLinkIsSignedAndNotInterchangeableWithTheReviewPage(): void
    {
        $code = GuestSurveys::code(self::WA);
        $this->assertSame(self::WA, GuestSurveys::verify($code));
        $this->assertSame(0, GuestSurveys::verify(self::WB . substr($code, strlen((string) self::WA))));
        $this->assertSame(0, GuestSurveys::verify("1-' OR 1=1"));
        $this->assertSame(0, GuestSurveys::verify(ReviewLanding::code(self::WA)), 'a review-page link must not open the survey');
    }

    public function testValidSubmissionIsStoredForTheRightOwner(): void
    {
        $r = $this->send(self::WA, ['score' => '9', 'comment' => 'Great trip', 'name' => 'Sam', 'contact' => 'sam@example.com']);
        $this->assertFalse($r['low']);
        $row = $this->pdo->query("SELECT user_id, website_id, score, comment, status FROM guest_surveys WHERE id = " . $r['id'])->fetch(PDO::FETCH_ASSOC);
        $this->assertSame((string) self::A, (string) $row['user_id']);
        $this->assertSame('9', (string) $row['score']);
        $this->assertSame('new', $row['status']);
    }

    public function testInvalidScoresAreRejected(): void
    {
        foreach ([null, '', 'abc', '-1', '11', '5.5'] as $bad) {
            try {
                $this->send(self::WA, ['score' => $bad], 'ip-' . md5((string) $bad));
                $this->fail('expected rejection of ' . var_export($bad, true));
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM guest_surveys WHERE website_id = " . self::WA)->fetchColumn());
    }

    public function testUnknownWebsiteIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->send(424242, ['score' => 8]);
    }

    public function testSameAddressIsLimited(): void
    {
        $this->send(self::WA, ['score' => 8], 'spam');
        $this->send(self::WA, ['score' => 8], 'spam');
        try {
            $this->send(self::WA, ['score' => 8], 'spam');
            $this->fail('third response from the same address must be limited');
        } catch (RuntimeException $e) {
            $this->assertSame('rate', $e->getMessage());
        }
        $this->send(self::WA, ['score' => 8], 'someone-else');
        $this->assertTrue(true);
    }

    public function testLowScoreNotifiesTheOwnerOnly(): void
    {
        $r = $this->send(self::WA, ['score' => 3, 'comment' => 'Late pickup']);
        $this->assertTrue($r['low']);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = " . self::A . " AND type = 'survey_low'")->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = " . self::B)->fetchColumn());
    }

    public function testSummaryComputesNpsAndListsAreTenantScoped(): void
    {
        foreach ([10, 9, 8, 3] as $i => $score) {
            $this->send(self::WA, ['score' => $score], 'ip-n' . $i);
        }
        $this->send(self::WB, ['score' => 1], 'ip-b');
        $s = GuestSurveys::summary(self::A, null, $this->db);
        $this->assertSame(4, $s['total']);
        $this->assertSame(2, $s['promoters']);
        $this->assertSame(1, $s['detractors']);
        $this->assertSame(25, $s['nps'], '(2 promoters - 1 detractor) / 4');
        $list = GuestSurveys::listFor(self::A, '', 1, 20, $this->db);
        $this->assertSame(4, $list['total']);
        foreach ($list['rows'] as $row) {
            $this->assertSame((string) self::WA, (string) $row['website_id']);
        }
    }

    public function testOnlyTheOwnerCanMarkHandled(): void
    {
        $id = $this->send(self::WB, ['score' => 2], 'ip-x')['id'];
        $this->assertFalse(GuestSurveys::markHandled(self::A, $id, true, $this->db));
        $this->assertSame('new', $this->pdo->query("SELECT status FROM guest_surveys WHERE id = $id")->fetchColumn());
        $this->assertTrue(GuestSurveys::markHandled(self::B, $id, true, $this->db));
        $this->assertSame('handled', $this->pdo->query("SELECT status FROM guest_surveys WHERE id = $id")->fetchColumn());
    }

    public function testPageEscapesAndKeepsPublicReviewLinks(): void
    {
        $html = GuestSurveys::page('<img src=x onerror=alert(1)> Co', GuestSurveys::code(self::WA), false, [['key' => 'google', 'label' => 'Google', 'url' => 'https://g.page/r/abc/review']], true);
        $this->assertFalse(strpos($html, '<img src=x') !== false);
        $this->assertStringContainsString('&lt;img src=x', $html);
        $this->assertStringContainsString('https://g.page/r/abc/review', $html);
        $this->assertStringContainsString('name="website_url"', $html, 'honeypot field');
        $this->assertStringContainsString('dir="rtl"', GuestSurveys::page('Co', 'x', true, [], true));
        $this->assertStringContainsString('not available', GuestSurveys::page('Co', 'x', false, [], false));
    }

    public function testReviewPageOffersThePrivateFeedbackLink(): void
    {
        $html = ReviewLanding::page('Co', [['key' => 'google', 'label' => 'Google', 'url' => 'https://g.page/r/abc/review']], '', '/survey/1-abc');
        $this->assertStringContainsString('/survey/1-abc', $html);
        $this->assertStringContainsString('https://g.page/r/abc/review', $html, 'the public link stays');
    }

    public function testSurveysCanBeSwitchedOffPerPlanOrPerCustomer(): void
    {
        $keys = ['reputation_limits', 'reputation_limits_audit', 'reputation_limit_overrides'];
        $saved = [];
        foreach ($keys as $k) {
            $v = $this->pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = '$k'")->fetchColumn();
            $saved[$k] = $v === false ? null : $v;
            $this->pdo->exec("DELETE FROM system_settings WHERE setting_key = '$k'");
        }
        try {
            $this->assertTrue(GuestSurveyController::surveysAllowed(self::A), 'on by default');
            ReputationLimits::save(['free' => ['surveys' => false]], 1, $this->db);
            $this->assertFalse(GuestSurveyController::surveysAllowed(self::A));
            $c = new GuestSurveyController();
            foreach (['data' => ['score' => 9], 'authenticated' => false] as $prop => $value) {
                $r = new ReflectionProperty(Controller::class, $prop);
                $r->setAccessible(true);
                $r->setValue($c, $value);
            }
            $res = $c->submit(['code' => GuestSurveys::code(self::WA)]);
            $this->assertFalse($res['success'] ?? true, 'the public form must refuse while the feature is off');
            $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM guest_surveys WHERE website_id = ' . self::WA)->fetchColumn());
            ReputationLimits::saveOverride(self::A, ['surveys' => true], 1, $this->db);
            $this->assertTrue(GuestSurveyController::surveysAllowed(self::A), 'a per-customer exception turns it back on');
        } finally {
            foreach ($saved as $k => $v) {
                $this->pdo->exec("DELETE FROM system_settings WHERE setting_key = '$k'");
                if ($v !== null) {
                    $st = $this->pdo->prepare('INSERT INTO system_settings (setting_key, setting_value, is_secret) VALUES (?, ?, 0)');
                    $st->execute([$k, $v]);
                }
            }
        }
    }
}

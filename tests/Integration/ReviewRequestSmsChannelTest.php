<?php

/**
 * Reputation: the SMS channel of review requests (storage works, honest not-configured state, monthly SMS cap).
 * No SMS is ever sent by this test.
 */

use PHPUnit\Framework\TestCase;

final class ReviewRequestSmsChannelTest extends TestCase
{
    private const UID = 999111;
    private const WID = 999111;

    private ?PDO $pdo = null;
    private ?Database $db = null;
    private array $savedSettings = [];

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
        if (!$this->pdo instanceof PDO) {
            $this->markTestSkipped('DB not available');
        }
        foreach (['reputation_limits', 'reputation_limits_audit'] as $k) {
            $v = $this->pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = '$k'")->fetchColumn();
            $this->savedSettings[$k] = $v === false ? null : $v;
            $this->pdo->exec("DELETE FROM system_settings WHERE setting_key = '$k'");
        }
        $this->cleanup();
        $this->pdo->exec("INSERT INTO users (id, email, password, company_name, created_at) VALUES (" . self::UID . ", 'sms@tourfecto.test', 'x', 'S', NOW())");
        $this->pdo->exec("INSERT INTO websites (id, user_id, main_url, company_name, industry, is_verified, created_at) VALUES (" . self::WID . ", " . self::UID . ", 'https://sms.test', 'S', 'tourism', 1, NOW())");
    }

    protected function tearDown(): void
    {
        if (!$this->pdo) {
            return;
        }
        $this->cleanup();
        foreach ($this->savedSettings as $k => $v) {
            $this->pdo->exec("DELETE FROM system_settings WHERE setting_key = '$k'");
            if ($v !== null) {
                $st = $this->pdo->prepare('INSERT INTO system_settings (setting_key, setting_value, is_secret) VALUES (?, ?, 0)');
                $st->execute([$k, $v]);
            }
        }
    }

    private function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM review_requests WHERE user_id = " . self::UID);
        $this->pdo->exec("DELETE FROM websites WHERE id = " . self::WID);
        $this->pdo->exec("DELETE FROM users WHERE id = " . self::UID);
    }

    private function row(string $channel, string $status = 'scheduled'): void
    {
        $this->pdo->exec("INSERT INTO review_requests (user_id, website_id, guest_name, channel, service_end_date, delay_hours, review_link, destination_platform, status, scheduled_send_at, attempts, source)
            VALUES (" . self::UID . ", " . self::WID . ", 'G', '$channel', NOW(), 0, 'https://x.test', 'other', '$status', NOW(), 0, 'manual')");
    }

    public function testChannelColumnAcceptsSms(): void
    {
        $this->row('sms');
        $this->assertSame('sms', $this->pdo->query("SELECT channel FROM review_requests WHERE user_id = " . self::UID)->fetchColumn());
    }

    public function testChannelStatusIsHonestWhenTwilioIsNotConfigured(): void
    {
        $status = (new ReviewRequestService())->getChannelStatus(self::WID);
        $this->assertArrayHasKey('sms', $status);
        $this->assertContains($status['sms'], ['connected', 'not_configured']);
        if ($status['sms'] === 'not_configured') {
            ReputationLimits::save(['free' => ['sms_requests_monthly' => 5]], 1, $this->db); // the plan allows SMS; the provider is what is missing
            try {
                (new ReviewRequestService())->createRequest(self::UID, self::WID, 'Guest', '201001234567', date('Y-m-d'), 'sms');
                $this->fail('an SMS request must not be created when SMS is not configured');
            } catch (ReputationLimitReached $e) {
                $this->fail('not a plan limit');
            } catch (Throwable $e) {
                $this->assertStringContainsString('SMS', $e->getMessage());
            }
            $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM review_requests WHERE user_id = " . self::UID)->fetchColumn());
        }
    }

    public function testSmsHasItsOwnMonthlyCapCountedSeparatelyFromOtherChannels(): void
    {
        ReputationLimits::save(['free' => ['sms_requests_monthly' => 1]], 1, $this->db);
        $this->row('email');
        $this->row('whatsapp');
        ReputationLimits::assertCanSendSms(self::UID, $this->db);
        $this->row('sms');
        $this->row('sms', 'failed');
        try {
            ReputationLimits::assertCanSendSms(self::UID, $this->db);
            $this->fail('the SMS cap must block the second SMS');
        } catch (ReputationLimitReached $e) {
            $this->assertSame('sms_requests_monthly', $e->feature);
        }
        ReputationLimits::assertCanCreateRequest(self::UID, $this->db);
        $this->assertSame(1, ReputationLimits::usage(self::UID, $this->db)['sms_requests_monthly'], 'failed SMS are not counted');
    }

    public function testSmsIsOffByDefaultBecauseItIsPaidPerMessage(): void
    {
        $this->assertSame(0, ReputationLimits::limit(self::UID, 'sms_requests_monthly', $this->db));
        try {
            ReputationLimits::assertCanSendSms(self::UID, $this->db);
            $this->fail('SMS must be off until the admin opens it');
        } catch (ReputationLimitReached $e) {
            $this->assertStringContainsString('not included in your plan', $e->getMessage());
        }
        ReputationLimits::save(['free' => ['sms_requests_monthly' => 5]], 1, $this->db);
        ReputationLimits::assertCanSendSms(self::UID, $this->db);
        $this->assertSame(0, ReputationLimits::defaults()['professional']['sms_requests_monthly']);
        $this->assertSame(-1, ReputationLimits::defaults()['professional']['review_requests_monthly'], 'other limits stay unlimited by default');
    }
}

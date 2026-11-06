<?php

/**
 * قراءة إشعارات الارتداد من صندوق المُرسِل: الدايم بيحوّل الشركة لـ bounced، والمؤقت والعادي لأ.
 */

use PHPUnit\Framework\TestCase;

final class OutreachBounceReaderTest extends TestCase
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
            $this->svc->getSettings();
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
        $this->db->query("DELETE FROM platform_leads WHERE source = 'phpunit-bounce'");
    }

    private function lead(string $email, string $status = 'contacted'): void
    {
        $this->db->query("INSERT INTO platform_leads (source, company, email, domain, country, status, approved, step, token) VALUES ('phpunit-bounce', ?, ?, ?, 'EG', ?, 1, 1, ?)",
            ['Co ' . $email, $email, substr(strrchr($email, '@'), 1), $status, bin2hex(random_bytes(16))]);
    }

    private function leadStatus(string $email): string
    {
        return (string) ($this->db->query('SELECT status FROM platform_leads WHERE email = ? AND source = ?', [$email, 'phpunit-bounce'])[0]['status'] ?? '');
    }

    private const EXIM = "From: Mail Delivery System <Mailer-Daemon@srv.example>\nSubject: Mail delivery failed: returning message to sender\n\nThis message was created automatically by mail delivery software.\n\n  gone@dead-co.test\n    host mx.dead-co.test [1.2.3.4]\n    SMTP error from remote mail server after RCPT TO:<gone@dead-co.test>: 550 5.1.1 User unknown\n";
    private const GMAIL = "From: Mail Delivery Subsystem <mailer-daemon@googlemail.com>\nSubject: Delivery Status Notification (Failure)\nX-Failed-Recipients: nobody@nomail.test\n\nAddress not found. 550 5.1.1 The email account that you tried to reach does not exist.\n";
    private const SOFT = "From: Mail Delivery System <Mailer-Daemon@srv.example>\nSubject: Undelivered Mail Returned to Sender\n\nFinal-Recipient: rfc822; full@box.test\nAction: failed\nStatus: 5.2.2\nDiagnostic-Code: smtp; 552 mailbox full over quota\n";

    private const FORWARDED = "From: Mail Delivery System <Mailer-Daemon@s4015.fra1.stableserver.net>
Subject: Mail delivery failed: returning message to sender

A message that you sent could not be delivered to one or more of its
recipients. This is a permanent error. The following address(es) failed:

  egyptdirecttours@gmail.com
    (ultimately generated from info@egyptdirect.co)
    host smtp.antispamcloud.com [154.59.104.24]
    SMTP error from remote mail server after end of data:
    550 Message rejected due to user rules.
";

    public function testForwardedAddressBounceMatchesOriginalLeadEmail(): void
    {
        $b = PlatformOutreachService::parseBounce(self::FORWARDED);
        $this->assertTrue($b['permanent']);
        $this->assertContains('info@egyptdirect.co', $b['emails']);
        $this->lead('info@egyptdirect.co');
        $r = $this->svc->checkBounces(static fn () => [self::FORWARDED]);
        $this->assertSame(1, $r['bounced']);
        $this->assertSame('bounced', $this->leadStatus('info@egyptdirect.co'));
    }

    private const POSTFIX_WITH_COPY = "From: MAILER-DAEMON@mailchannels.net\nSubject: Undelivered Mail Returned to Sender\nContent-Type: multipart/report\n\nThis is the mail system at host relay.mailchannels.net.\n\n<contact@turkeytoursplanners.com>: host\n    mail.turkeytoursplanners.com[5.189.175.9] said: 550 5.1.1\n    <contact@turkeytoursplanners.com>: Recipient address rejected: User unknown\n    in virtual mailbox table (in reply to RCPT TO command)\n\nContent-Type: message/rfc822\n\nTo: contact@turkeytoursplanners.com\nSubject: hi\n\nYour bookings are delayed and the mailbox is temporary full over quota 5.2.2\n";

    public function testReportIsAnalysedWithoutTheAttachedOriginalMessage(): void
    {
        $b = PlatformOutreachService::parseBounce(self::POSTFIX_WITH_COPY);
        $this->assertSame('contact@turkeytoursplanners.com', $b['email']);
        $this->assertTrue($b['permanent'], 'كلمات النسخة المرفقة مابتخلّيش الارتداد مؤقت');
        $this->assertSame('invalid_address', $b['reason']);
        $f = PlatformOutreachService::parseBounce(self::FORWARDED);
        $this->assertSame('invalid_address', $f['reason'], 'اسم سيرفر الفلتر (antispam) مش دليل على حجب السمعة');
    }

    public function testParserReadsPermanentAndIgnoresSoftAndNonBounce(): void
    {
        $a = PlatformOutreachService::parseBounce(self::EXIM);
        $this->assertSame('gone@dead-co.test', $a['email']);
        $this->assertTrue($a['permanent']);
        $b = PlatformOutreachService::parseBounce(self::GMAIL);
        $this->assertSame('nobody@nomail.test', $b['email']);
        $this->assertTrue($b['permanent']);
        $this->assertFalse(PlatformOutreachService::parseBounce(self::SOFT)['permanent'], 'الصندوق الممتلئ مش ارتداد دايم');
        $this->assertNull(PlatformOutreachService::parseBounce("From: person@x.test\nSubject: Thanks for your email\n\nhello"));
    }

    public function testCheckBouncesMarksOnlyPermanentAndKeepsRepliedCompanies(): void
    {
        $this->lead('gone@dead-co.test');
        $this->lead('nobody@nomail.test', 'paused');
        $this->lead('full@box.test');
        $this->lead('gone@replied.test', 'replied');
        $r = $this->svc->checkBounces(static fn () => [self::EXIM, self::GMAIL, self::SOFT, "From: Mail Delivery System <Mailer-Daemon@x>\nSubject: Undelivered Mail\n\nX-Failed-Recipients: gone@replied.test\n550 user unknown"]);

        $this->assertSame(4, $r['scanned']);
        $this->assertSame(2, $r['bounced']);
        $this->assertSame('bounced', $this->leadStatus('gone@dead-co.test'));
        $this->assertSame('bounced', $this->leadStatus('nobody@nomail.test'));
        $this->assertSame('contacted', $this->leadStatus('full@box.test'));
        $this->assertSame('replied', $this->leadStatus('gone@replied.test'));
        $again = $this->svc->checkBounces(static fn () => [self::EXIM]);
        $this->assertSame(0, $again['bounced'], 'التشغيل المتكرر مايعدّش نفس الارتداد تاني');
    }

    public function testFetchFailureIsReportedNotThrown(): void
    {
        $r = $this->svc->checkBounces(static function () { throw new RuntimeException('imap down'); });
        $this->assertSame('imap down', $r['error']);
    }
}

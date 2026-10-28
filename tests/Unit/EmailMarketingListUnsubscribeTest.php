<?php

/**
 * Tourfecto - Email Marketing List-Unsubscribe Headers Test
 * اختبار وحدة لهيدرز RFC 8058 لإلغاء الاشتراك بضغطة واحدة
 * (متطلبات Gmail/Yahoo من فبراير 2024) على الرسالة الصادرة:
 *   - List-Unsubscribe       يحتوي mailto + رابط إلغاء الاشتراك
 *   - List-Unsubscribe-Post  = List-Unsubscribe=One-Click
 *   - الحماية من header injection (CR/LF في القيم)
 * يُفحص عبر compileHeaders داخل Mailer من غير أي socket أو SMTP.
 * @version 1.0.0  @date 2026-08-25
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/Mailer.php';

/** يكشف compileHeaders المحمي للفحص من غير الاتصال بسيرفر البريد. */
final class MailerHeadersProbe extends Mailer
{
    public function compileHeadersForTest(string $toEmail, string $toName, string $subject, array $extraHeaders = []): string
    {
        return $this->compileHeaders($toEmail, $toName, $subject, $extraHeaders);
    }
}

final class EmailMarketingListUnsubscribeTest extends TestCase
{
    private MailerHeadersProbe $probe;

    protected function setUp(): void
    {
        $this->probe = new MailerHeadersProbe();
        $this->probe->configure(['from_email' => 'news@example.com', 'from_name' => 'Example Co']);
    }

    public function testCampaignEmailCarriesBothUnsubscribeHeaders(): void
    {
        $unsubscribeUrl = 'https://app.tourfecto.com/api/email-marketing/unsubscribe/abc123';
        $headers = $this->probe->compileHeadersForTest('user@example.net', 'User', 'عرض', [
            'List-Unsubscribe' => '<mailto:unsubscribe@example.com?subject=unsubscribe>, <' . $unsubscribeUrl . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);

        $this->assertStringContainsString(
            'List-Unsubscribe: <mailto:unsubscribe@example.com?subject=unsubscribe>, <https://app.tourfecto.com/api/email-marketing/unsubscribe/abc123>',
            $headers
        );
        $this->assertStringContainsString('List-Unsubscribe-Post: List-Unsubscribe=One-Click', $headers);
        // الهيدرزان في مكانهما الصحيح (بعد هيدرز الرأس الأساسية وقبل جسم الرسالة)
        $this->assertStringContainsString("Content-Transfer-Encoding: 8bit\r\nList-Unsubscribe:", $headers);
    }

    public function testHeaderValuesAreSanitizedAgainstInjection(): void
    {
        $headers = $this->probe->compileHeadersForTest('user@example.net', 'User', 'Subject', [
            'List-Unsubscribe' => "<mailto:unsubscribe@example.com?subject=unsubscribe>\r\nBcc: evil@example.com, <https://app.tourfecto.com/u>",
            'List-Unsubscribe-Post' => "List-Unsubscribe=One-Click\r\nReply-To: attacker@example.com",
        ]);

        // لا يتسرب أي سطر دخيل إلى هيدرز الرسالة
        $this->assertStringNotContainsString("\r\nBcc:", $headers);
        $this->assertStringNotContainsString("\r\nReply-To:", $headers);
        $this->assertSame(1, substr_count($headers, 'List-Unsubscribe:'));
        $this->assertSame(1, substr_count($headers, 'List-Unsubscribe-Post:'));
    }

    public function testWithoutExtraHeadersNoUnsubscribeHeaderAdded(): void
    {
        $headers = $this->probe->compileHeadersForTest('user@example.net', 'User', 'Subject');
        $this->assertStringNotContainsString('List-Unsubscribe', $headers);
    }
}

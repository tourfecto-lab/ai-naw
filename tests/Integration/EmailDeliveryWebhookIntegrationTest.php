<?php

/**
 * Tourfecto - Email Delivery Webhook Integration Test (بند 1: تتبع التسليم)
 * بيتخطى تلقائيًا (markTestSkipped) لو DB غير متاحة أو ميجريشن الـ webhook
 * لسه ما اتشغّلش: database/migrations/2026_09_01_000001_email_delivery_webhook.sql
 *
 * بيفحص تدفق استقبال مشاكل التسليم من مزوّد البريد (SendGrid/Mailgun/Postmark/عام):
 *   1) bounce صحيح بتوقيع سليم => تتسجّل suppression + حالة المشترك تبقى bounced
 *   2) توقيع غلط => رفض (401) من غير أي تسجيل suppression
 *   3) نوع حدث غير معروف => تجاهل آمن (نجاح من غير معالجة)
 *   4) webhook معطّل => تجاهل آمن حتى مع توقيع سليم
 *   5) الصيغ المختلفة للمزوّدين: SendGrid (مصفوفة) + Mailgun (signature) + عام (header)
 * @version 1.0.0  @date 2026-09-01
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/EmailMarketing/SmtpSettingsService.php';
require_once __DIR__ . '/../../app/Services/EmailMarketing/ContactManagementService.php';

final class EmailDeliveryWebhookIntegrationTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static bool $dbChecked = false;
    private static int $userId = 0;
    private const SECRET = 'test-delivery-webhook-secret-0123456789';

    private function db(): ?PDO
    {
        if (self::$dbChecked) {
            return self::$pdo;
        }
        self::$dbChecked = true;

        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) {
                foreach ([$app . '/Config/app.php', $app . '/Config/database.php'] as $cfg) {
                    if (file_exists($cfg)) {
                        require_once $cfg;
                    }
                }
            }
            foreach ([
                'Database' => '/Core/Database.php',
                'Logger' => '/Core/Logger.php',
                'Model' => '/Core/Model.php',
                'Encryption' => '/Core/Encryption.php',
                'EmailSubscriber' => '/Models/EmailSubscriber.php',
                'EmailListService' => '/Services/EmailMarketing/EmailListService.php',
            ] as $class => $relPath) {
                if (!class_exists($class) && file_exists($app . $relPath)) {
                    require_once $app . $relPath;
                }
            }

            $db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $conn = $ref->getValue($db);
            if (!$conn instanceof PDO) {
                self::$pdo = null;
                return null;
            }

            $cols = $conn->query("SHOW COLUMNS FROM email_smtp_settings LIKE 'delivery_webhook_enabled'")->fetchAll();
            if (empty($cols)) {
                self::$pdo = null;
                return null;
            }
            self::$pdo = $conn;
            return self::$pdo;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function setUp(): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            $this->markTestSkipped('DB غير متاحة أو ميجريشن delivery webhook لسه ما اتشغّلش');
        }
        if (self::$userId === 0) {
            self::$userId = createTestUser();
        }

        // صف SMTP مفعّل الـ webhook بمفتاح سري معروف
        $pdo->exec("DELETE FROM email_smtp_settings WHERE user_id = " . (int) self::$userId);
        $svc = new SmtpSettingsService();
        $svc->saveDeliveryWebhook(self::$userId, ['enabled' => 1, 'secret' => self::SECRET]);
    }

    protected function tearDown(): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return;
        }
        $uid = (int) self::$userId;
        $pdo->exec("DELETE FROM email_smtp_settings WHERE user_id = {$uid}");
        $pdo->exec("DELETE FROM email_suppressions WHERE user_id = {$uid}");
        $pdo->exec("DELETE FROM email_subscribers WHERE user_id = {$uid}");
    }

    private function addSubscriber(string $email): int
    {
        $result = (new EmailListService())->subscribe(self::$userId, $email);
        $this->assertTrue($result['success']);
        return (int) $result['id'];
    }

    private function service(): ContactManagementService
    {
        return new ContactManagementService();
    }

    private function suppressionCount(): int
    {
        return (int) self::$pdo->query(
            "SELECT COUNT(*) FROM email_suppressions WHERE user_id = " . (int) self::$userId
        )->fetchColumn();
    }

    public function testBounceWithValidSignatureRecordsIssue(): void
    {
        $subId = $this->addSubscriber('bounce-ok@example.com');

        // صيغة عامة (الحد الأدنى): header X-Delivery-Webhook-Secret
        $payload = ['event' => 'bounce', 'email' => 'bounce-ok@example.com', 'reason' => 'mailbox full'];
        $result = $this->service()->handleDeliveryWebhook(
            self::$userId,
            json_encode($payload),
            $payload,
            ['x-delivery-webhook-secret' => self::SECRET]
        );

        $this->assertTrue($result['handled']);
        $this->assertSame('bounce', $result['type']);
        $this->assertSame('bounce-ok@example.com', $result['email']);
        $this->assertSame(1, $this->suppressionCount(), 'يجب تسجيل suppression واحدة للارتداد');

        $row = self::$pdo->query("SELECT status, bounce_count FROM email_subscribers WHERE id = {$subId}")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('bounced', $row['status']);
        $this->assertSame(1, (int) $row['bounce_count']);
    }

    public function testWrongSignatureRejectedWithoutRecording(): void
    {
        $this->addSubscriber('bounce-bad@example.com');
        $payload = ['event' => 'bounce', 'email' => 'bounce-bad@example.com'];

        $result = $this->service()->handleDeliveryWebhook(
            self::$userId,
            json_encode($payload),
            $payload,
            ['x-delivery-webhook-secret' => 'wrong-secret']
        );

        $this->assertFalse($result['handled']);
        $this->assertSame('توقيع غير صالح', $result['error'] ?? '');
        $this->assertSame(0, $this->suppressionCount(), 'لا يُسجّل أي suppression مع توقيع غلط');
    }

    public function testUnknownEventTypeSafelyIgnored(): void
    {
        $this->addSubscriber('unknown@example.com');
        $payload = ['event' => 'opened', 'email' => 'unknown@example.com'];

        $result = $this->service()->handleDeliveryWebhook(
            self::$userId,
            json_encode($payload),
            $payload,
            ['x-delivery-webhook-secret' => self::SECRET]
        );

        $this->assertFalse($result['handled'], 'الحدث غير المعروف يُتجاهل بأمان');
        $this->assertEmpty($result['error'] ?? '');
        $this->assertSame(0, $this->suppressionCount());
    }

    public function testDisabledWebhookSafelyIgnored(): void
    {
        $this->addSubscriber('disabled@example.com');
        $svc = new SmtpSettingsService();
        $svc->saveDeliveryWebhook(self::$userId, ['enabled' => 0, 'secret' => self::SECRET]);

        $payload = ['event' => 'bounce', 'email' => 'disabled@example.com'];
        $result = $this->service()->handleDeliveryWebhook(
            self::$userId,
            json_encode($payload),
            $payload,
            ['x-delivery-webhook-secret' => self::SECRET]
        );

        $this->assertFalse($result['handled']);
        $this->assertSame(0, $this->suppressionCount());
    }

    public function testSendGridArrayPayload(): void
    {
        $this->addSubscriber('sg@example.com');
        $payload = [
            ['event' => 'spamreport', 'email' => 'sg@example.com', 'reason' => ''],
        ];

        // SendGrid يتطلب HMAC فوق timestamp + جسم خام
        $raw = json_encode($payload);
        $ts = '1700000000';
        $sig = hash_hmac('sha256', $ts . $raw, self::SECRET);
        $result = $this->service()->handleDeliveryWebhook(
            self::$userId,
            $raw,
            $payload,
            [
                'x-twilio-email-event-webhook-signature' => $sig,
                'x-twilio-email-event-webhook-timestamp' => $ts,
            ]
        );

        $this->assertTrue($result['handled']);
        $this->assertSame('complaint', $result['type']);
        $this->assertSame(1, $this->suppressionCount());
        $row = self::$pdo->query("SELECT status FROM email_subscribers WHERE email = 'sg@example.com'")->fetchColumn();
        $this->assertSame('unsubscribed', $row);
    }

    public function testMailgunSignaturePayload(): void
    {
        $this->addSubscriber('mg@example.com');
        $ts = '1700000000';
        $token = 'webhook-token-abc';
        $payload = [
            'signature' => [
                'timestamp' => $ts,
                'token' => $token,
                'signature' => hash_hmac('sha256', $ts . $token, self::SECRET),
            ],
            'event-data' => ['event' => 'complained', 'recipient' => 'mg@example.com', 'reason' => ''],
        ];

        $result = $this->service()->handleDeliveryWebhook(
            self::$userId,
            json_encode($payload),
            $payload,
            []
        );

        $this->assertTrue($result['handled']);
        $this->assertSame('complaint', $result['type']);
        $this->assertSame(1, $this->suppressionCount());
    }
}

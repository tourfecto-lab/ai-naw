<?php

/**
 * Tourfecto - Send Booking Confirmation Job Test
 * بيفحص Job إيميل تأكيد الحجز (SendBookingConfirmationJob):
 *   - إيميل موجود → handle() يبعت البريد للعميل بالمحتوى الصحيح
 *     (عبر RecordingMailer fake — من غير أي socket/SMTP حقيقي).
 *   - إيميل غائب → handle() يفشل بأمان (Exception) ولا يكسر أي شيء.
 *   - Mailer مش متظبط → يتخطى بسجل warning من غير throw.
 *   - buildConfirmationHtml → يحتوي تفاصيل الحجز ويهرب الإدخال الخام.
 * بيتخطى تلقائيًا لو DB غير متاحة.
 * @version 1.0.0  @date 2026-08-28
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/Mailer.php';
require_once __DIR__ . '/../../app/Jobs/SendBookingConfirmationJob.php';

/** Mailer وهمي يسجّل الإرسالات بدل الاتصال بسيرفر SMTP. */
final class RecordingMailer extends Mailer
{
    /** @var array<int,array<string,mixed>> */
    public array $sent = [];

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $toEmail, string $toName, string $subject, string $htmlBody, array $extraHeaders = []): array
    {
        $this->sent[] = [
            'to' => $toEmail,
            'name' => $toName,
            'subject' => $subject,
            'html' => $htmlBody,
            'headers' => $extraHeaders,
        ];
        return ['success' => true];
    }
}

/** Job نسخة اختبارية بتموّن بـ RecordingMailer عبر factory. */
final class TestSendBookingConfirmationJob extends SendBookingConfirmationJob
{
    public RecordingMailer $mailer;

    public function __construct(?RecordingMailer $mailer = null)
    {
        $this->mailer = $mailer ?? new RecordingMailer();
    }

    protected function makeMailer(): Mailer
    {
        return $this->mailer;
    }
}

/** Mailer وهمي مش متظبط (isConfigured=false). */
final class UnconfiguredMailer extends Mailer
{
    public function isConfigured(): bool
    {
        return false;
    }
}

/** Job نسخة اختبارية بتموّن بـ UnconfiguredMailer. */
final class TestUnconfiguredSendJob extends SendBookingConfirmationJob
{
    protected function makeMailer(): Mailer
    {
        return new UnconfiguredMailer();
    }
}

final class SendBookingConfirmationJobTest extends TestCase
{
    private const USER_ID   = 999530;
    private const PRODUCT_ID = 999531;

    private static ?PDO $pdo = null;
    private static bool $dbChecked = false;

    private function db(): ?PDO
    {
        if (self::$dbChecked) {
            return self::$pdo;
        }
        self::$dbChecked = true;

        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) {
                foreach ([
                    $app . '/Config/app.php',
                    $app . '/Config/database.php',
                ] as $cfg) {
                    if (file_exists($cfg)) {
                        require_once $cfg;
                    }
                }
            }
            if (!class_exists('Database') && file_exists($app . '/Core/Database.php')) {
                require_once $app . '/Core/Database.php';
            }
            if (!class_exists('Logger') && file_exists($app . '/Core/Logger.php')) {
                require_once $app . '/Core/Logger.php';
            }
            if (!class_exists('Model') && file_exists($app . '/Core/Model.php')) {
                require_once $app . '/Core/Model.php';
            }

            $db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $conn = $ref->getValue($db);
            if (!$conn instanceof PDO) {
                return null;
            }

            foreach (['users', 'crm_products', 'bookings'] as $table) {
                $found = $conn->query("SHOW TABLES LIKE '{$table}'")->fetchAll();
                if (empty($found)) {
                    return null;
                }
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
            $this->markTestSkipped('DB غير متاحة أو الميجريشنز لسه ما اتشغّلتش');
        }
        $this->cleanup();
        $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                    VALUES (" . self::USER_ID . ", 'confirm-job@tourfecto.test', 'x', 'Confirm Job Co', NOW())
                    ON DUPLICATE KEY UPDATE email = email");
        $pdo->exec("INSERT INTO crm_products (id, user_id, name, sku, price, currency, is_active)
                    VALUES (" . self::PRODUCT_ID . ", " . self::USER_ID . ", 'رحلة سيوة', 'CONFIRM-JOB', 100.00, 'USD', 1)
                    ON DUPLICATE KEY UPDATE user_id = " . self::USER_ID);
    }

    protected function tearDown(): void
    {
        if (self::$pdo === null) {
            return;
        }
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $pdo = self::$pdo;
        $pdo->exec("DELETE FROM bookings WHERE user_id = " . self::USER_ID);
        $pdo->exec("DELETE FROM crm_products WHERE id = " . self::PRODUCT_ID);
        $pdo->exec("DELETE FROM users WHERE id = " . self::USER_ID);
    }

    /** يُدرج حجز confirmed مباشرة ويرجع الـ booking_id. */
    private function insertConfirmedBooking(?string $email = 'visitor@example.com'): int
    {
        $pdo = self::$pdo;
        $reference = 'BK-' . strtoupper(bin2hex(random_bytes(4)));
        $stmt = $pdo->prepare(
            "INSERT INTO bookings (booking_reference, user_id, product_id, customer_name,
                                   customer_phone, customer_email, start_date, adults_count,
                                   children_count, total_amount, currency, status, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $reference, self::USER_ID, self::PRODUCT_ID, 'زائر الإيميل',
            '+201000000002', $email, '2026-12-20', 1, 0, 100.00, 'USD', 'confirmed', 'website',
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function testHandleSendsConfirmationEmailToCustomer(): void
    {
        $bookingId = $this->insertConfirmedBooking();

        $job = new TestSendBookingConfirmationJob();
        $job->handle(['booking_id' => $bookingId]);

        $this->assertCount(1, $job->mailer->sent, 'يُرسل إيميل واحد بالضبط');
        $sent = $job->mailer->sent[0];
        $this->assertSame('visitor@example.com', $sent['to']);
        $this->assertSame('زائر الإيميل', $sent['name']);
        $this->assertStringContainsString('تأكيد الحجز', $sent['subject']);
        $this->assertStringContainsString('BK-', $sent['subject']);
        $this->assertStringContainsString('رقم الحجز', $sent['html']);
        $this->assertStringContainsString('رحلة سيوة', $sent['html']);
        $this->assertStringContainsString('20/12/2026', $sent['html']);
        $this->assertStringContainsString('100.00', $sent['html']);
        $this->assertStringContainsString('USD', $sent['html']);
        $this->assertStringContainsString('Confirm Job Co', $sent['html']);
    }

    public function testHandleFailsSafelyWhenCustomerEmailMissing(): void
    {
        $bookingId = $this->insertConfirmedBooking(null);

        $job = new TestSendBookingConfirmationJob();
        $this->expectException(Exception::class);
        $job->handle(['booking_id' => $bookingId]);
    }

    public function testHandleSkipsGracefullyWhenMailerNotConfigured(): void
    {
        $bookingId = $this->insertConfirmedBooking();

        $job = new TestUnconfiguredSendJob();
        // لا throw رغم أن mailer غير مضبوط — يُتخطى بسجل warning
        $job->handle(['booking_id' => $bookingId]);

        $this->addToAssertionCount(1);
    }

    public function testBuildConfirmationHtmlContainsDetailsAndEscapesInput(): void
    {
        $html = SendBookingConfirmationJob::buildConfirmationHtml([
            'booking_reference' => 'BK-ABC123',
            'customer_name' => 'عميل <b>مختبر</b>',
            'tour_name' => 'رحلة سيوة',
            'start_date' => '2026-12-20',
            'total_amount' => '100.00',
            'currency' => 'USD',
            'company_name' => 'Confirm Job Co',
        ]);

        $this->assertStringContainsString('BK-ABC123', $html);
        $this->assertStringContainsString('رحلة سيوة', $html);
        $this->assertStringContainsString('20/12/2026', $html);
        $this->assertStringContainsString('100.00', $html);
        $this->assertStringContainsString('USD', $html);
        $this->assertStringContainsString('Confirm Job Co', $html);
        // تهريب الإدخال الخام: وسم <b> في اسم العميل لا يتسرب كـ HTML
        $this->assertStringNotContainsString('<b>مختبر</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;مختبر&lt;/b&gt;', $html);
    }
}

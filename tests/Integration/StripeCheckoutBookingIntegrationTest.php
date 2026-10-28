<?php

/**
 * Tourfecto - Stripe Checkout for Bookings Integration Test (Phase 2)
 *
 * بيفحص دورة حياة الدفع بالكامل على قاعدة البيانات الحقيقية:
 *   1) إنشاء Checkout Session لحجز pending → معاملة pending وربطها بالحجز.
 *   2) Webhook `checkout.session.completed` → الحجز بيتأكد + المعاملة succeeded.
 *   3) إعادة تسليم نفس الـ Webhook → idempotent (مفيش تأكيد مزدوج/تاريخ مكرر).
 *   4) Webhook بتوقيع غلط → مرفوض (استثناء 401).
 *   5) Webhook `checkout.session.expired` → المعاملة failed والحجز لسه pending.
 *
 * الجزء اللي بينادي Stripe الحقيقي معزول: createCheckoutSession بيعمل
 * استثناء واضح لو Stripe مش مُفعّل (مفيش مفاتيح في بيئة الاختبار)، وده
 * متغطى في الاختبار الأول. باقي التدفقات بتتعامل مع صفوف DB مباشرة.
 *
 * بيتمسح تلقائيًا لو DB غير متاحة - راجع نمط BookingEngineIntegrationTest.
 * @version 1.0.0  @date 2026-08-21
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/BookingEngine.php';
require_once __DIR__ . '/../../app/Services/InventoryService.php';
require_once __DIR__ . '/../../app/Services/Payment/StripeCheckoutService.php';
require_once __DIR__ . '/../../app/Models/Booking.php';
require_once __DIR__ . '/../../app/Models/PaymentTransaction.php';

final class StripeCheckoutBookingIntegrationTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static bool $dbChecked = false;
    private static int $testUserId = 0;
    private static int $testProductId = 0;

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

            $tables = $conn->query("SHOW TABLES LIKE 'bookings'")->fetchAll();
            if (empty($tables)) {
                return null;
            }
            $payments = $conn->query("SHOW TABLES LIKE 'payment_transactions'")->fetchAll();
            if (empty($payments)) {
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
            $this->markTestSkipped('DB غير متاحة أو ميجريشنز الدفع/الحجز لسه ما اتشغلتش');
        }

        $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at) VALUES (999002, 'stripe-test@tourfecto.test', 'x', 'Test', NOW())
                     ON DUPLICATE KEY UPDATE email = email");
        self::$testUserId = 999002;

        $pdo->exec("INSERT INTO crm_products (id, user_id, name, price, currency, is_active)
                     VALUES (999002, 999002, 'Stripe Test Tour', 150.00, 'USD', 1)
                     ON DUPLICATE KEY UPDATE user_id = user_id");
        self::$testProductId = 999002;

        $pdo->exec("DELETE FROM booking_status_history WHERE booking_id IN (SELECT id FROM bookings WHERE user_id = 999002)");
        $pdo->exec("DELETE FROM payment_transactions WHERE user_id = 999002");
        $pdo->exec("DELETE FROM bookings WHERE user_id = 999002");
        $pdo->exec("DELETE FROM inventory WHERE product_id = 999002");
    }

    protected function tearDown(): void
    {
        $pdo = self::$pdo;
        if ($pdo === null) {
            return;
        }
        $pdo->exec("DELETE FROM booking_status_history WHERE booking_id IN (SELECT id FROM bookings WHERE user_id = 999002)");
        $pdo->exec("DELETE FROM payment_transactions WHERE user_id = 999002");
        $pdo->exec("DELETE FROM bookings WHERE user_id = 999002");
        $pdo->exec("DELETE FROM inventory WHERE product_id = 999002");
    }

    private function createPendingBooking(): array
    {
        $inventory = new InventoryService();
        $inventory->setDay(self::$testUserId, self::$testProductId, '2026-12-10', 3);

        return (new BookingEngine())->createBooking(self::$testUserId, [
            'product_id' => self::$testProductId,
            'start_date' => '2026-12-10',
            'customer_name' => 'Stripe Customer',
            'customer_email' => 'customer@example.com',
            'adults_count' => 1,
        ]);
    }

    public function testCheckoutRequiresConfiguredStripe(): void
    {
        $booking = $this->createPendingBooking();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('غير مُفعّلة');
        (new StripeCheckoutService())->createCheckoutSession(
            self::$testUserId,
            (int) $booking['id'],
            'https://example.com/success',
            'https://example.com/cancel'
        );
    }

    public function testWebhookCompletedConfirmsBookingAndSucceedsPayment(): void
    {
        $booking = $this->createPendingBooking();
        $service = new StripeCheckoutService();
        $bookingId = (int) $booking['id'];

        // سجّل معاملة pending يدويًا (بنفس الشكل اللي createCheckoutSession بيعمله)
        $pdo = self::$pdo;
        $pdo->prepare('INSERT INTO payment_transactions
            (internal_transaction_id, user_id, amount, currency, payment_method, gateway,
             status, reference, booking_id, metadata, idempotency_key)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                'BTX-' . bin2hex(random_bytes(8)), self::$testUserId, 150.00, 'USD', 'card',
                'stripe', 'pending', $booking['booking_reference'], $bookingId,
                json_encode(['booking_reference' => $booking['booking_reference']]),
                'booking-checkout-' . $bookingId . '-' . $booking['booking_reference'],
            ]);

        $result = $service->handleWebhook(
            json_encode([
                'type' => 'checkout.session.completed',
                'data' => ['object' => [
                    'id' => 'cs_test_completed',
                    'client_reference_id' => $booking['booking_reference'],
                ]],
            ]),
            $this->signWebhook(json_encode([
                'type' => 'checkout.session.completed',
                'data' => ['object' => [
                    'id' => 'cs_test_completed',
                    'client_reference_id' => $booking['booking_reference'],
                ]],
            ]))
        );

        $this->assertTrue($result['handled']);

        // الحجز بقى confirmed
        $row = $pdo->query('SELECT status FROM bookings WHERE id = ' . $bookingId)->fetch();
        $this->assertSame('confirmed', $row['status']);

        // المعاملة succeeded
        $tx = $pdo->query('SELECT status, booking_id FROM payment_transactions WHERE user_id = 999002')->fetchAll();
        $this->assertCount(1, $tx);
        $this->assertSame('succeeded', $tx[0]['status']);
        $this->assertSame($bookingId, (int) $tx[0]['booking_id']);

        // إعادة نفس الـ Webhook → idempotent: لسه معاملة واحدة والحالة confirmed
        $service->handleWebhook(
            json_encode([
                'type' => 'checkout.session.completed',
                'data' => ['object' => [
                    'id' => 'cs_test_completed',
                    'client_reference_id' => $booking['booking_reference'],
                ]],
            ]),
            $this->signWebhook(json_encode([
                'type' => 'checkout.session.completed',
                'data' => ['object' => [
                    'id' => 'cs_test_completed',
                    'client_reference_id' => $booking['booking_reference'],
                ]],
            ]))
        );

        $txAfter = $pdo->query('SELECT COUNT(*) AS c FROM payment_transactions WHERE user_id = 999002')->fetch();
        $this->assertSame(1, (int) $txAfter['c']);
        $history = $pdo->query('SELECT COUNT(*) AS c FROM booking_status_history bsh
                                JOIN bookings b ON b.id = bsh.booking_id
                                WHERE b.user_id = 999002 AND bsh.to_status = "confirmed"')->fetch();
        $this->assertSame(1, (int) $history['c']);
    }

    public function testWebhookRejectsBadSignature(): void
    {
        $booking = $this->createPendingBooking();

        $this->expectException(Exception::class);
        $this->expectExceptionCode(401);
        (new StripeCheckoutService())->handleWebhook(
            json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_bad']]]),
            't=' . time() . ',v1=deadbeef'
        );
    }

    public function testWebhookExpiredMarksPaymentFailedKeepsBookingPending(): void
    {
        $booking = $this->createPendingBooking();
        $bookingId = (int) $booking['id'];
        $pdo = self::$pdo;

        $pdo->prepare('INSERT INTO payment_transactions
            (internal_transaction_id, user_id, amount, currency, payment_method, gateway,
             status, reference, booking_id, metadata, idempotency_key, gateway_transaction_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                'BTX-' . bin2hex(random_bytes(8)), self::$testUserId, 150.00, 'USD', 'card',
                'stripe', 'pending', $booking['booking_reference'], $bookingId,
                json_encode(['booking_reference' => $booking['booking_reference']]),
                'booking-checkout-' . $bookingId . '-' . $booking['booking_reference'],
                'cs_test_expired',
            ]);

        $payload = json_encode([
            'type' => 'checkout.session.expired',
            'data' => ['object' => ['id' => 'cs_test_expired']],
        ]);

        $result = (new StripeCheckoutService())->handleWebhook($payload, $this->signWebhook($payload));
        $this->assertTrue($result['handled']);

        $tx = $pdo->query('SELECT status FROM payment_transactions WHERE user_id = 999002')->fetch();
        $this->assertSame('failed', $tx['status']);

        $row = $pdo->query('SELECT status FROM bookings WHERE id = ' . $bookingId)->fetch();
        $this->assertSame('pending', $row['status']);
    }

    /** توقيع Webhook بالطريقة نفسها اللي Stripe بيعمل بيها (للاختبار بس) */
    private function signWebhook(string $payload): string
    {
        $secret = getenv('STRIPE_WEBHOOK_SECRET') ?: 'test_webhook_secret';
        $ts = time();
        return "t={$ts},v1=" . hash_hmac('sha256', $ts . '.' . $payload, $secret);
    }
}

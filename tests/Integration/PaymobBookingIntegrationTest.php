<?php

/**
 * Tourfecto - Paymob Gateway Booking Integration Test
 *
 * بيفحص دورة حياة الدفع عبر Paymob على قاعدة البيانات الحقيقية:
 *   1) createCheckoutSession → استثناء واضح لو Paymob غير مُفعّل
 *      (مفيش مفاتيح في بيئة الاختبار - الجزء اللي بينادي Paymob الحقيقي
 *      معزول تمامًا، نفس نهج اختبار Stripe).
 *   2) Webhook transaction.response (success=true) → الحجز بيتأكد +
 *      المعاملة succeeded.
 *   3) إعادة تسليم نفس الـ Webhook → idempotent (مفيش تأكيد مزدوج).
 *   4) Webhook بتوقيع غلط → مرفوض (استثناء 401).
 *   5) Webhook (success=false) → المعاملة failed والحجز لسه pending.
 *
 * توقيع الـ HMAC بيتم بنفس الخوارزمية اللي Paymob بتستخدمها (مفاتيح
 * مرتبة + استثناء القيم الفارغة) للاختبار فقط.
 *
 * بيتمسح تلقائيًا لو DB غير متاحة - راجع نمط StripeCheckoutBookingIntegrationTest.
 * @version 1.0.0  @date 2026-08-25
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/BookingEngine.php';
require_once __DIR__ . '/../../app/Services/InventoryService.php';
require_once __DIR__ . '/../../app/Services/Payment/PaymobGateway.php';
require_once __DIR__ . '/../../app/Models/Booking.php';
require_once __DIR__ . '/../../app/Models/PaymentTransaction.php';

final class PaymobBookingIntegrationTest extends TestCase
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

        $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at) VALUES (999003, 'paymob-test@tourfecto.test', 'x', 'Test', NOW())
                     ON DUPLICATE KEY UPDATE email = email");
        self::$testUserId = 999003;

        $pdo->exec("INSERT INTO crm_products (id, user_id, name, price, currency, is_active)
                     VALUES (999003, 999003, 'Paymob Test Tour', 250.00, 'EGP', 1)
                     ON DUPLICATE KEY UPDATE user_id = user_id");
        self::$testProductId = 999003;

        $pdo->exec("DELETE FROM booking_status_history WHERE booking_id IN (SELECT id FROM bookings WHERE user_id = 999003)");
        $pdo->exec("DELETE FROM payment_transactions WHERE user_id = 999003");
        $pdo->exec("DELETE FROM bookings WHERE user_id = 999003");
        $pdo->exec("DELETE FROM inventory WHERE product_id = 999003");
    }

    protected function tearDown(): void
    {
        $pdo = self::$pdo;
        if ($pdo === null) {
            return;
        }
        $pdo->exec("DELETE FROM booking_status_history WHERE booking_id IN (SELECT id FROM bookings WHERE user_id = 999003)");
        $pdo->exec("DELETE FROM payment_transactions WHERE user_id = 999003");
        $pdo->exec("DELETE FROM bookings WHERE user_id = 999003");
        $pdo->exec("DELETE FROM inventory WHERE product_id = 999003");
    }

    private function createPendingBooking(): array
    {
        $inventory = new InventoryService();
        $inventory->setDay(self::$testUserId, self::$testProductId, '2026-12-11', 3);

        return (new BookingEngine())->createBooking(self::$testUserId, [
            'product_id' => self::$testProductId,
            'start_date' => '2026-12-11',
            'customer_name' => 'Paymob Customer',
            'customer_email' => 'paymob-customer@example.com',
            'adults_count' => 1,
        ]);
    }

    private function transactionPayload(string $txId, string $reference, bool $success = true): array
    {
        return [
            'id' => $txId,
            'transaction_id' => $txId,
            'amount_cents' => 25000,
            'currency' => 'EGP',
            'success' => $success,
            'pending' => false,
            'error_occurred' => !$success,
            'is_refunded' => false,
            'is_voided' => false,
            'is_auth' => false,
            'is_capture' => true,
            'is_3d_secure' => true,
            'is_standalone_payment' => true,
            'created_at' => date('Y-m-d H:i:s'),
            'integration_id' => 12345,
            'has_ssl_certificate' => true,
            'owner' => 6789,
            'source_data' => ['pan' => 'xxxxxx', 'sub_type' => 'VISA', 'type' => 'card'],
            'order' => ['id' => 999000, 'merchant_order_id' => $reference],
        ];
    }

    public function testCheckoutRequiresConfiguredPaymob(): void
    {
        $booking = $this->createPendingBooking();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('غير مُفعّلة');
        (new PaymobGateway())->createCheckoutSession(
            self::$testUserId,
            (int) $booking['id'],
            'https://example.com/success',
            'https://example.com/cancel'
        );
    }

    public function testWebhookCompletedConfirmsBookingAndSucceedsPayment(): void
    {
        $booking = $this->createPendingBooking();
        $service = new PaymobGateway();
        $bookingId = (int) $booking['id'];
        $reference = $booking['booking_reference'];
        $txId = 'pm_tx_1001';

        // سجّل معاملة pending يدويًا (بنفس الشكل اللي createCheckoutSession بيعمله)
        $pdo = self::$pdo;
        $pdo->prepare('INSERT INTO payment_transactions
            (internal_transaction_id, user_id, amount, currency, payment_method, gateway,
             status, reference, booking_id, metadata, idempotency_key, gateway_transaction_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                'PTX-' . bin2hex(random_bytes(8)), self::$testUserId, 250.00, 'EGP', 'card',
                'paymob', 'pending', $reference, $bookingId,
                json_encode(['booking_reference' => $reference]),
                'booking-checkout-paymob-' . $bookingId . '-' . $reference,
                $txId,
            ]);

        $payload = json_encode($this->transactionPayload($txId, $reference));

        $result = $service->handleWebhook($payload, $this->signWebhook($payload));
        $this->assertTrue($result['handled']);

        // الحجز بقى confirmed
        $row = $pdo->query('SELECT status FROM bookings WHERE id = ' . $bookingId)->fetch();
        $this->assertSame('confirmed', $row['status']);

        // المعاملة succeeded
        $tx = $pdo->query('SELECT status, booking_id FROM payment_transactions WHERE user_id = 999003')->fetchAll();
        $this->assertCount(1, $tx);
        $this->assertSame('succeeded', $tx[0]['status']);
        $this->assertSame($bookingId, (int) $tx[0]['booking_id']);

        // إعادة نفس الـ Webhook → idempotent: لسه معاملة واحدة والحالة confirmed
        $service->handleWebhook($payload, $this->signWebhook($payload));

        $txAfter = $pdo->query('SELECT COUNT(*) AS c FROM payment_transactions WHERE user_id = 999003')->fetch();
        $this->assertSame(1, (int) $txAfter['c']);
        $history = $pdo->query('SELECT COUNT(*) AS c FROM booking_status_history bsh
                                JOIN bookings b ON b.id = bsh.booking_id
                                WHERE b.user_id = 999003 AND bsh.to_status = "confirmed"')->fetch();
        $this->assertSame(1, (int) $history['c']);
    }

    public function testWebhookRejectsBadSignature(): void
    {
        $booking = $this->createPendingBooking();
        $payload = json_encode($this->transactionPayload('pm_tx_1002', $booking['booking_reference']));

        $this->expectException(Exception::class);
        $this->expectExceptionCode(401);
        (new PaymobGateway())->handleWebhook($payload, str_repeat('a', 64));
    }

    public function testWebhookFailedKeepsBookingPending(): void
    {
        $booking = $this->createPendingBooking();
        $bookingId = (int) $booking['id'];
        $reference = $booking['booking_reference'];
        $txId = 'pm_tx_1003';
        $pdo = self::$pdo;

        $pdo->prepare('INSERT INTO payment_transactions
            (internal_transaction_id, user_id, amount, currency, payment_method, gateway,
             status, reference, booking_id, metadata, idempotency_key, gateway_transaction_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                'PTX-' . bin2hex(random_bytes(8)), self::$testUserId, 250.00, 'EGP', 'card',
                'paymob', 'pending', $reference, $bookingId,
                json_encode(['booking_reference' => $reference]),
                'booking-checkout-paymob-' . $bookingId . '-' . $reference,
                $txId,
            ]);

        $payload = json_encode($this->transactionPayload($txId, $reference, false));

        $result = (new PaymobGateway())->handleWebhook($payload, $this->signWebhook($payload));
        $this->assertTrue($result['handled']);

        $tx = $pdo->query('SELECT status FROM payment_transactions WHERE user_id = 999003')->fetch();
        $this->assertSame('failed', $tx['status']);

        $row = $pdo->query('SELECT status FROM bookings WHERE id = ' . $bookingId)->fetch();
        $this->assertSame('pending', $row['status']);
    }

    /** توقيع HMAC بنفس خوارزمية Paymob (للاختبار فقط). */
    private function signWebhook(string $payload): string
    {
        $secret = getenv('PAYMOB_HMAC_SECRET') ?: 'test_paymob_hmac';
        $data = json_decode($payload, true);

        $keys = [
            'amount_cents', 'created_at', 'currency', 'error_occurred', 'has_ssl_certificate',
            'id', 'integration_id', 'is_3d_secure', 'is_auth', 'is_capture', 'is_refunded',
            'is_standalone_payment', 'is_voided', 'order.id', 'owner', 'pending',
            'source_data.pan', 'source_data.sub_type', 'source_data.type', 'success', 'transaction_id',
        ];

        $concatenated = '';
        foreach ($keys as $key) {
            $value = $this->getValueByKey($data, $key);
            if ($value !== null && $value !== '' && $value !== false && $value !== 0) {
                $concatenated .= (string) $value;
            }
        }

        return hash_hmac('sha256', $concatenated, $secret);
    }

    private function getValueByKey(array $data, string $key)
    {
        $current = $data;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }
        return $current;
    }
}

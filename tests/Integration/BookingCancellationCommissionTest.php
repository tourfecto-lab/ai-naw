<?php

/**
 * Tourfecto - Booking Cancellation Commission Test
 * بيفحص معالجة عمولة الوكالة عند إلغاء حجز مؤكد (BookingEngine::cancelBooking):
 *   - pending → voided (تُلغى تلقائيًا داخل نفس الـ transaction).
 *   - paid    → تبقى كما هي (لا تُعكس تلقائيًا أبدًا) + تنبيه للأدمن
 *               (صاحب الوكالة) عبر Notification + Logger.
 *   - مفيش عمولة → بلا أثر جانبي والإلغاء ينجح.
 * بيتخطى تلقائيًا لو DB غير متاحة.
 * @version 1.0.0  @date 2026-08-28
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/BookingEngine.php';
require_once __DIR__ . '/../../app/Models/Notification.php';
require_once __DIR__ . '/../../app/Models/User.php';

final class BookingCancellationCommissionTest extends TestCase
{
    private const AGENCY_OWNER = 999520;
    private const COMPANY_USER = 999521;
    private const AGENCY       = 999522;
    private const AGENCY_CLIENT = 999523;
    private const PRODUCT_ID   = 999524;

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

            foreach (['users', 'agencies', 'agency_clients', 'agency_commissions',
                      'crm_products', 'inventory', 'bookings'] as $table) {
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
        $this->seedFixtures();
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
        $pdo->exec("DELETE FROM notifications WHERE user_id = " . self::AGENCY_OWNER);
        $pdo->exec("DELETE FROM booking_status_history WHERE booking_id IN (SELECT id FROM bookings WHERE user_id = " . self::COMPANY_USER . ")");
        $pdo->exec("DELETE FROM agency_commissions WHERE agency_id = " . self::AGENCY);
        $pdo->exec("DELETE FROM bookings WHERE user_id = " . self::COMPANY_USER);
        $pdo->exec("DELETE FROM inventory WHERE product_id = " . self::PRODUCT_ID);
        $pdo->exec("DELETE FROM crm_products WHERE id = " . self::PRODUCT_ID);
        $pdo->exec("DELETE FROM agency_clients WHERE agency_id = " . self::AGENCY);
        $pdo->exec("DELETE FROM agencies WHERE id = " . self::AGENCY);
        $pdo->exec("DELETE FROM users WHERE id IN (" . self::AGENCY_OWNER . ", " . self::COMPANY_USER . ")");
    }

    private function seedFixtures(): void
    {
        $pdo = self::$pdo;
        $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                    VALUES (" . self::AGENCY_OWNER . ", 'cancel-owner@tourfecto.test', 'x', 'Cancel Owner', NOW()),
                           (" . self::COMPANY_USER . ", 'cancel-co@tourfecto.test', 'x', 'Cancel Co', NOW())
                    ON DUPLICATE KEY UPDATE email = email");

        $pdo->exec("INSERT INTO agencies (id, owner_user_id, name, slug, status, plan_seats)
                    VALUES (" . self::AGENCY . ", " . self::AGENCY_OWNER . ", 'Cancel Agency', 'cancel-agency', 'active', 10)
                    ON DUPLICATE KEY UPDATE owner_user_id = " . self::AGENCY_OWNER);
        $pdo->exec("INSERT INTO agency_clients (id, agency_id, client_user_id, status, commission_rate)
                    VALUES (" . self::AGENCY_CLIENT . ", " . self::AGENCY . ", " . self::COMPANY_USER . ", 'active', 10.00)
                    ON DUPLICATE KEY UPDATE agency_id = " . self::AGENCY . ", client_user_id = " . self::COMPANY_USER . ", status = 'active', commission_rate = 10.00");

        $pdo->exec("INSERT INTO crm_products (id, user_id, name, sku, price, currency, is_active)
                    VALUES (" . self::PRODUCT_ID . ", " . self::COMPANY_USER . ", 'رحلة اختبار', 'CANCEL-TEST', 100.00, 'USD', 1)
                    ON DUPLICATE KEY UPDATE user_id = " . self::COMPANY_USER);
        $pdo->exec("INSERT INTO inventory (product_id, user_id, date, capacity, booked_count, price_override, is_blocked)
                    VALUES (" . self::PRODUCT_ID . ", " . self::COMPANY_USER . ", '2026-12-20', 10, 0, NULL, 0)
                    ON DUPLICATE KEY UPDATE capacity = 10, booked_count = 0, is_blocked = 0");
    }

    /** ينشئ حجز pending، يؤكده يدويًا (confirmBooking)، ويرجع الـ booking_id. */
    private function confirmedBooking(): int
    {
        $engine = new BookingEngine();
        $booking = $engine->createBooking(self::COMPANY_USER, [
            'product_id' => self::PRODUCT_ID,
            'start_date' => '2026-12-20',
            'customer_name' => 'عميل الإلغاء',
            'customer_email' => 'cancel-visitor@example.com',
            'customer_phone' => '+201000000001',
            'adults_count' => 1,
            'children_count' => 0,
            'source' => 'website',
        ]);
        $this->assertTrue($engine->confirmBooking(self::COMPANY_USER, (int) $booking['id']));
        return (int) $booking['id'];
    }

    public function testCancelVoidsPendingCommission(): void
    {
        $bookingId = $this->confirmedBooking();

        $comm = self::$pdo->query('SELECT * FROM agency_commissions WHERE booking_id = ' . $bookingId)->fetch();
        $this->assertNotEmpty($comm, 'عمولة الوكالة تتسجّل بعد تأكيد الحجز');
        $this->assertSame('pending', $comm['status']);

        $engine = new BookingEngine();
        $this->assertTrue($engine->cancelBooking(self::COMPANY_USER, $bookingId, 'إلغاء اختبار'));

        $after = self::$pdo->query('SELECT status FROM agency_commissions WHERE booking_id = ' . $bookingId)->fetch();
        $this->assertSame('voided', $after['status'], 'عمولة pending لحجز ملغي بقت voided');
    }

    public function testCancelLeavesPaidCommissionAndNotifiesAgencyOwner(): void
    {
        $bookingId = $this->confirmedBooking();

        // محاكاة أن الأدمن دفع العمولة فعلًا قبل الإلغاء
        self::$pdo->exec("UPDATE agency_commissions SET status = 'paid' WHERE booking_id = " . $bookingId);

        $engine = new BookingEngine();
        $this->assertTrue($engine->cancelBooking(self::COMPANY_USER, $bookingId, 'إلغاء بعد الدفع'));

        // العمولة المدفوعة لا تُعكس تلقائيًا أبدًا
        $after = self::$pdo->query('SELECT status FROM agency_commissions WHERE booking_id = ' . $bookingId)->fetch();
        $this->assertSame('paid', $after['status'], 'العمولة المدفوعة لا تُغيّر تلقائيًا بعد الإلغاء');

        // تنبيه للأدمن (صاحب الوكالة) اتسجّل
        $notif = self::$pdo->query(
            "SELECT * FROM notifications WHERE user_id = " . self::AGENCY_OWNER
            . " AND type = 'commission_paid_on_cancelled_booking' ORDER BY id DESC LIMIT 1"
        )->fetch();
        $this->assertNotEmpty($notif, 'تنبيه العمولة المدفوعة على حجز ملغي يوصل لصاحب الوكالة');
        $this->assertStringContainsString('مدفوعة', (string) ($notif['title'] ?? ''));
    }

    public function testCancelWithNoCommissionHasNoSideEffect(): void
    {
        // نفس المستخدم لكن من غير ربط وكالة active → مفيش عمولة أصلًا
        self::$pdo->exec("UPDATE agency_clients SET status = 'suspended' WHERE id = " . self::AGENCY_CLIENT);

        $engine = new BookingEngine();
        $booking = $engine->createBooking(self::COMPANY_USER, [
            'product_id' => self::PRODUCT_ID,
            'start_date' => '2026-12-20',
            'customer_name' => 'عميل عادي',
            'customer_email' => 'regular-visitor@example.com',
            'adults_count' => 1,
            'children_count' => 0,
        ]);
        $bookingId = (int) $booking['id'];
        $this->assertTrue($engine->confirmBooking(self::COMPANY_USER, $bookingId));

        $commCount = (int) self::$pdo->query('SELECT COUNT(*) FROM agency_commissions WHERE booking_id = ' . $bookingId)->fetchColumn();
        $this->assertSame(0, $commCount, 'مفيش عمولة لحجز عميله مش تابع لوكالة active');

        $this->assertTrue($engine->cancelBooking(self::COMPANY_USER, $bookingId, 'إلغاء بدون عمولة'));
        $after = self::$pdo->query('SELECT status FROM bookings WHERE id = ' . $bookingId)->fetch();
        $this->assertSame('cancelled', $after['status'], 'الإلغاء ينجح حتى من غير عمولة');
    }
}

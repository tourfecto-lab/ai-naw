<?php

/**
 * Tourfecto - Agency Commission Integration Test (White-Label)
 * بيفحص عمولات الوكالات من الحجوزات المؤكدة:
 *   1) تسجيل عمولة تلقائيًا عند تأكيد الحجز (يدوي confirmBooking أو بعد
 *      الدفع confirmBookingFromPayment) = total_amount × commission_rate.
 *   2) نسبة العميل القابلة للتعديل (commission_rate) بتحكم في المبلغ.
 *   3) مفيش عمولة للمستخدمين غير التابعين لوكالة (أو تابعين بس معلّقين).
 *   4) Idempotency: إعادة التأكيد بتحدّث المبلغ مش بتضيف سجلات مكررة.
 *   5) عزل صارم بين الوكلاء: وكيل ميقدرش يشوف أو يعلّم عمولات وكيل تاني.
 *
 * محتاج الميجريشن: database/migrations/2026_08_26_000002_agency_commissions.sql
 * بيتخطى تلقائيًا (markTestSkipped) لو DB غير متاحة أو الجداول غير موجودة.
 * @version 1.0.0  @date 2026-08-26
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Core/Model.php';
require_once __DIR__ . '/../../app/Models/Booking.php';
require_once __DIR__ . '/../../app/Models/Agency.php';
require_once __DIR__ . '/../../app/Models/AgencyClient.php';
require_once __DIR__ . '/../../app/Models/AgencyBranding.php';
require_once __DIR__ . '/../../app/Models/AgencyCommission.php';
require_once __DIR__ . '/../../app/Models/ActivityLog.php';
require_once __DIR__ . '/../../app/Services/BookingEngine.php';
require_once __DIR__ . '/../../app/Services/InventoryService.php';
require_once __DIR__ . '/../../app/Services/WhiteLabel/AgencyService.php';
require_once __DIR__ . '/../../app/Controllers/AgencyController.php';

final class AgencyCommissionIntegrationTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static bool $dbChecked = false;

    // معرّفات ثابتة (بعيدة عن 999001 اللي بيستخدمه اختبار الـ booking القديم)
    private const OWNER_A = 999100;
    private const CLIENT_A1 = 999101;
    private const OWNER_B = 999102;
    private const CLIENT_B1 = 999103;
    private const NON_AGENCY = 999104;
    private const CLIENT_A2 = 999105; // معلّق

    private const AGENCY_A = 999200;
    private const AGENCY_B = 999201;

    private const PRODUCT_A1 = 999301;
    private const PRODUCT_B1 = 999302;
    private const PRODUCT_NON = 999303;
    private const PRODUCT_A2 = 999304;

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

            $db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $conn = $ref->getValue($db);
            if (!$conn instanceof PDO) {
                self::$pdo = null;
                return null;
            }

            // تأكد إن جداول العمولات والوكالات موجودة فعلًا (الميجريشن اتشغّلت)
            $tables = $conn->query("SHOW TABLES LIKE 'agency_commissions'")->fetchAll();
            $tables2 = $conn->query("SHOW TABLES LIKE 'agencies'")->fetchAll();
            if (empty($tables) || empty($tables2)) {
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
            $this->markTestSkipped('DB غير متاحة أو ميجريشن الوكالات مش متشغّل - راجع تعليق أعلى الملف');
        }

        // تنظيف أي بيانات قديمة من تشغيل سابق (قبل إدراج بيانات جديدة)
        $this->cleanup();

        // المستخدمون: مالكا وكالتين + عملاء + مستخدم عادي (مش تابع لوكالة)
        foreach ([
            [self::OWNER_A, 'owner-a@tourfecto.test', 'Agency A Owner'],
            [self::CLIENT_A1, 'client-a1@tourfecto.test', 'Client A1'],
            [self::OWNER_B, 'owner-b@tourfecto.test', 'Agency B Owner'],
            [self::CLIENT_B1, 'client-b1@tourfecto.test', 'Client B1'],
            [self::NON_AGENCY, 'non-agency@tourfecto.test', 'Standalone User'],
            [self::CLIENT_A2, 'client-a2@tourfecto.test', 'Client A2'],
        ] as [$id, $email, $name]) {
            $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                        VALUES ({$id}, '{$email}', 'x', '{$name}', NOW())
                        ON DUPLICATE KEY UPDATE email = email");
        }

        // الوكالتان
        $pdo->exec("INSERT INTO agencies (id, owner_user_id, name, slug, status, plan_seats)
                    VALUES (999200, 999100, 'Agency A', 'agency-comm-a', 'active', 10)
                    ON DUPLICATE KEY UPDATE owner_user_id = 999100");
        $pdo->exec("INSERT INTO agencies (id, owner_user_id, name, slug, status, plan_seats)
                    VALUES (999201, 999102, 'Agency B', 'agency-comm-b', 'active', 10)
                    ON DUPLICATE KEY UPDATE owner_user_id = 999102");

        // ربط العملاء: A1 بنسبة مخصّصة 15%، B1 بالنسبة الافتراضية 10%، A2 معلّق
        $pdo->exec("INSERT INTO agency_clients (agency_id, client_user_id, status, commission_rate)
                    VALUES (999200, 999101, 'active', 15.00)
                    ON DUPLICATE KEY UPDATE status = 'active', commission_rate = 15.00");
        $pdo->exec("INSERT INTO agency_clients (agency_id, client_user_id, status)
                    VALUES (999201, 999103, 'active')
                    ON DUPLICATE KEY UPDATE status = 'active', commission_rate = DEFAULT");
        $pdo->exec("INSERT INTO agency_clients (agency_id, client_user_id, status, commission_rate)
                    VALUES (999200, 999105, 'suspended', 10.00)
                    ON DUPLICATE KEY UPDATE status = 'suspended', commission_rate = 10.00");

        // منتجات لكل عميل (الكتلة اللي بيعمل عليها الحجوزات)
        foreach ([
            [self::PRODUCT_A1, self::CLIENT_A1],
            [self::PRODUCT_B1, self::CLIENT_B1],
            [self::PRODUCT_NON, self::NON_AGENCY],
            [self::PRODUCT_A2, self::CLIENT_A2],
        ] as [$pid, $uid]) {
            $pdo->exec("INSERT INTO crm_products (id, user_id, name, price, currency, is_active)
                        VALUES ({$pid}, {$uid}, 'Test Product', 100.00, 'USD', 1)
                        ON DUPLICATE KEY UPDATE user_id = user_id");
        }
    }

    protected function tearDown(): void
    {
        $pdo = self::$pdo;
        if ($pdo === null) {
            return;
        }
        $this->cleanup();
        unset($_SESSION['user']);
    }

    /** تنظيف كامل للبيانات اللي بتتسجّل جوه الاختبار (قبل وبعد) */
    private function cleanup(): void
    {
        $pdo = self::$pdo;
        $pdo->exec('DELETE FROM agency_commissions WHERE agency_id IN (999200, 999201)');
        $pdo->exec('DELETE FROM booking_status_history WHERE booking_id IN (SELECT id FROM bookings WHERE user_id IN (999101, 999103, 999104, 999105))');
        $pdo->exec('DELETE FROM bookings WHERE user_id IN (999101, 999103, 999104, 999105)');
        $pdo->exec('DELETE FROM inventory WHERE product_id IN (999301, 999302, 999303, 999304)');
        $pdo->exec('DELETE FROM crm_deals WHERE owner_user_id IN (999100, 999101, 999102, 999103, 999104, 999105)');
        $pdo->exec('DELETE FROM agency_clients WHERE agency_id IN (999200, 999201)');
        $pdo->exec('DELETE FROM agency_branding WHERE agency_id IN (999200, 999201)');
        $pdo->exec('DELETE FROM agencies WHERE id IN (999200, 999201)');
        $pdo->exec('DELETE FROM crm_products WHERE id IN (999301, 999302, 999303, 999304)');
        $pdo->exec('DELETE FROM users WHERE id IN (999100, 999101, 999102, 999103, 999104, 999105)');
    }

    private function createAndConfirmBooking(int $userId, int $productId, string $date, float $total, bool $viaPayment = false): int
    {
        $inventory = new InventoryService();
        $inventory->setDay($userId, $productId, $date, 5);

        $engine = new BookingEngine();
        $result = $engine->createBooking($userId, [
            'product_id' => $productId,
            'start_date' => $date,
            'customer_name' => 'Test Customer',
            'total_amount' => $total,
            'adults_count' => 1,
        ]);

        if ($viaPayment) {
            $ok = $engine->confirmBookingFromPayment((int) $result['id']);
        } else {
            $ok = $engine->confirmBooking($userId, (int) $result['id']);
        }
        $this->assertTrue($ok, 'تأكيد الحجز لازم ينجح');

        return (int) $result['id'];
    }

    private function commissionRows(): array
    {
        return self::$pdo->query(
            'SELECT agency_id, agency_client_id, booking_id, commission_amount, status
             FROM agency_commissions WHERE agency_id IN (999200, 999201)
             ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------
    // 1) احتساب العمولة التلقائي
    // ------------------------------------------------------------

    public function testCommissionRecordedOnManualConfirmWithCustomRate(): void
    {
        $bookingId = $this->createAndConfirmBooking(self::CLIENT_A1, self::PRODUCT_A1, '2026-12-01', 1000.00);

        $rows = $this->commissionRows();
        $this->assertCount(1, $rows);
        $this->assertSame(self::AGENCY_A, (int) $rows[0]['agency_id']);
        $this->assertSame((int) $bookingId, (int) $rows[0]['booking_id']);
        $this->assertSame(150.00, (float) $rows[0]['commission_amount']); // 1000 × 15%
        $this->assertSame('pending', $rows[0]['status']);
    }

    public function testCommissionRecordedOnConfirmFromPaymentWithDefaultRate(): void
    {
        $bookingId = $this->createAndConfirmBooking(self::CLIENT_B1, self::PRODUCT_B1, '2026-12-02', 2000.00, true);

        $rows = $this->commissionRows();
        $this->assertCount(1, $rows);
        $this->assertSame(self::AGENCY_B, (int) $rows[0]['agency_id']);
        $this->assertSame((int) $bookingId, (int) $rows[0]['booking_id']);
        $this->assertSame(200.00, (float) $rows[0]['commission_amount']); // 2000 × 10% (الافتراضي)
        $this->assertSame('pending', $rows[0]['status']);
    }

    public function testNoCommissionForNonAgencyUser(): void
    {
        $this->createAndConfirmBooking(self::NON_AGENCY, self::PRODUCT_NON, '2026-12-03', 500.00);

        $this->assertSame([], $this->commissionRows());
    }

    public function testNoCommissionForSuspendedAgencyClient(): void
    {
        $this->createAndConfirmBooking(self::CLIENT_A2, self::PRODUCT_A2, '2026-12-04', 500.00);

        $this->assertSame([], $this->commissionRows());
    }

    public function testReconfirmIsIdempotent(): void
    {
        $this->createAndConfirmBooking(self::CLIENT_A1, self::PRODUCT_A1, '2026-12-05', 1000.00);

        $engine = new BookingEngine();
        // إعادة التأكيد (already confirmed) - confirmBookingFromPayment بيرجع true وبيتجاهل
        $this->assertTrue($engine->confirmBookingFromPayment(
            (int) self::$pdo->query('SELECT id FROM bookings WHERE user_id = 999101 LIMIT 1')->fetchColumn()
        ));

        $rows = $this->commissionRows();
        $this->assertCount(1, $rows); // مش سجلات مكررة
        $this->assertSame(150.00, (float) $rows[0]['commission_amount']);
    }

    public function testChangedRateAppliesToNewBookings(): void
    {
        self::$pdo->exec('UPDATE agency_clients SET commission_rate = 20.00 WHERE agency_id = 999200 AND client_user_id = 999101');
        $this->createAndConfirmBooking(self::CLIENT_A1, self::PRODUCT_A1, '2026-12-06', 1000.00);

        $rows = $this->commissionRows();
        $this->assertCount(1, $rows);
        $this->assertSame(200.00, (float) $rows[0]['commission_amount']); // 1000 × 20%
    }

    // ------------------------------------------------------------
    // 2) عزل صارم بين الوكلاء (وكيل لا يرى بيانات وكيل آخر)
    // ------------------------------------------------------------

    private function sessionAs(int $userId): void
    {
        $_SESSION['user'] = ['id' => $userId, 'role' => 'agency_owner', 'company_name' => 'Test'];
    }

    public function testListCommissionsIsolation(): void
    {
        $this->createAndConfirmBooking(self::CLIENT_A1, self::PRODUCT_A1, '2026-12-07', 1000.00);
        $this->createAndConfirmBooking(self::CLIENT_B1, self::PRODUCT_B1, '2026-12-08', 2000.00, true);

        $this->sessionAs(self::OWNER_A);
        $ctrl = new AgencyController();
        $res = $ctrl->listCommissions(['id' => self::AGENCY_A]);
        $this->assertTrue($res['success']);
        $this->assertCount(1, $res['data']['commissions']); // عمولة A بس
        $this->assertSame(150.00, (float) $res['data']['commissions'][0]['commission_amount']);
    }

    public function testCrossAgencyAccessIsRejected(): void
    {
        $this->createAndConfirmBooking(self::CLIENT_A1, self::PRODUCT_A1, '2026-12-09', 1000.00);

        // مالك وكالة B يحاول يقرا بيانات وكالة A → 404 (وكأنها مش موجودة)
        $this->sessionAs(self::OWNER_B);
        $ctrl = new AgencyController();
        $this->assertSame(404, $ctrl->listCommissions(['id' => self::AGENCY_A])['code']);
        $this->assertSame(404, $ctrl->performanceReport(['id' => self::AGENCY_A])['code']);
        $this->assertSame(404, $ctrl->listClients(['id' => self::AGENCY_A])['code']);
    }

    public function testPerformanceReportIsolationAndAggregation(): void
    {
        $this->createAndConfirmBooking(self::CLIENT_A1, self::PRODUCT_A1, '2026-12-10', 1000.00);
        $this->createAndConfirmBooking(self::CLIENT_B1, self::PRODUCT_B1, '2026-12-11', 2000.00, true);

        $this->sessionAs(self::OWNER_A);
        $ctrl = new AgencyController();
        $res = $ctrl->performanceReport(['id' => self::AGENCY_A]);

        $this->assertTrue($res['success']);
        $data = $res['data'];
        $this->assertSame(1, $data['active_clients_count']); // A1 فقط (A2 معلّق)
        $this->assertSame(1, $data['confirmed_bookings_count']); // حجز A1 بس
        $this->assertSame(1000.00, (float) $data['total_revenue']); // 1000 بس مش 3000
        $this->assertSame(150.00, (float) $data['commissions']['pending_total']);
        $this->assertSame(0.0, (float) $data['commissions']['paid_total']);
    }

    public function testMarkCommissionPaidByOwnAgency(): void
    {
        $this->createAndConfirmBooking(self::CLIENT_A1, self::PRODUCT_A1, '2026-12-12', 1000.00);
        $commissionId = (int) self::$pdo->query(
            'SELECT id FROM agency_commissions WHERE agency_id = 999200 LIMIT 1'
        )->fetchColumn();

        $this->sessionAs(self::OWNER_A);
        $ctrl = new AgencyController();
        $res = $ctrl->markCommissionPaid(['id' => $commissionId]);
        $this->assertTrue($res['success']);
        $this->assertSame('paid', $res['data']['commission']['status']);

        // إعادة التعليم مفيش مشكلة (already paid)
        $res2 = $ctrl->markCommissionPaid(['id' => $commissionId]);
        $this->assertTrue($res2['success']);
    }

    public function testMarkCommissionPaidCrossAgencyForbidden(): void
    {
        $this->createAndConfirmBooking(self::CLIENT_A1, self::PRODUCT_A1, '2026-12-13', 1000.00);
        $commissionId = (int) self::$pdo->query(
            'SELECT id FROM agency_commissions WHERE agency_id = 999200 LIMIT 1'
        )->fetchColumn();

        // مالك وكالة B يحاول يعلّم عمولة وكالة A مدفوعة → 404 والعمولة لسه pending
        $this->sessionAs(self::OWNER_B);
        $ctrl = new AgencyController();
        $this->assertSame(404, $ctrl->markCommissionPaid(['id' => $commissionId])['code']);

        $status = self::$pdo->query("SELECT status FROM agency_commissions WHERE id = {$commissionId}")->fetchColumn();
        $this->assertSame('pending', $status);
    }
}

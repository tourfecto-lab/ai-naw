<?php

/**
 * Tourfecto - Booking → Revenue Intelligence Integration Test
 * بيتخطى تلقائيًا (markTestSkipped) لو DB غير متاحة أو جداول الحجز/الإيراد
 * غير مثبتة (bookings/inventory/crm_products/rev_revenue_records).
 *
 * يغطي ربط الحجوزات الفعلية بذكاء الإيرادات (rev_revenue_records):
 *   1) تأكيد حجز (يدوي) → سجل إيراد source='booking' بصحيح
 *      user_id/reference_id/amount/currency/recorded_at.
 *   2) تأكيد حجز بعد الدفع (confirmBookingFromPayment) → نفس السجل.
 *   3) تأكيد مكرر لنفس الحجز → لا سجل مكرر (idempotent).
 *   4) إلغاء حجز كان مؤكَّدًا → سجل تصحيحي source='booking_refund'
 *      بمبلغ سالب (= -total_amount) لنفس reference_id.
 *   5) إلغاء حجز pending (غير مؤكد) → لا سجل تصحيحي.
 *   6) RevenueOverviewService::getOverview + getRevenueBySourceWithGrowth
 *      ترجع أرقام صحيحة لما فيه بيانات حجوزات حقيقية مختلطة مع بيانات
 *      يدوية (Stripe/CRM المصدر الأصلي للموديول).
 *   7) CustomerRevenueService (المصدر crm_deals won) يبقى صحيحًا بلا
 *      تأثر بسجلات الحجوزات الجديدة.
 *
 * @version 1.0.0  @date 2026-08-30
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/BookingEngine.php';
require_once __DIR__ . '/../../app/Services/InventoryService.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/RevenueDataGateway.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/RevenueOverviewService.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/CustomerRevenueService.php';

final class BookingRevenueIntegrationTest extends TestCase
{
    private const TEST_USER_ID = 999015;

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

            foreach (['users', 'bookings', 'inventory', 'crm_products', 'rev_revenue_records'] as $table) {
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
            $this->markTestSkipped('DB غير متاحة أو جداول الحجز/الإيراد غير مثبتة');
        }

        $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                    VALUES (" . self::TEST_USER_ID . ", 'booking-revenue@tourfecto.test', 'x', 'Booking Rev', NOW())
                    ON DUPLICATE KEY UPDATE email = email");
        $pdo->exec("INSERT INTO crm_products (id, user_id, name, price, currency, is_active)
                    VALUES (999015, " . self::TEST_USER_ID . ", 'Rev Tour', 200.00, 'USD', 1)
                    ON DUPLICATE KEY UPDATE user_id = user_id");
        $pdo->exec("INSERT INTO crm_pipeline_stages (id, agency_id, name, slug, sort_order, win_probability)
                    VALUES (999015, NULL, 'Rev Stage', 'rev-stage-booking', 1, 50)
                    ON DUPLICATE KEY UPDATE slug = slug");
        $pdo->exec("INSERT INTO crm_contacts (id, user_id, name, email, phone)
                    VALUES (999015, " . self::TEST_USER_ID . ", 'Rev Contact', 'rev-customer@tourfecto.test', '+15550009915')
                    ON DUPLICATE KEY UPDATE email = email, phone = phone");

        $pdo->exec("DELETE FROM booking_status_history WHERE booking_id IN (SELECT id FROM bookings WHERE user_id = " . self::TEST_USER_ID . ")");
        $pdo->exec("DELETE FROM bookings WHERE user_id = " . self::TEST_USER_ID);
        $pdo->exec("DELETE FROM inventory WHERE product_id = 999015");
        $pdo->exec("DELETE FROM crm_deals WHERE owner_user_id = " . self::TEST_USER_ID);
        $pdo->exec("DELETE FROM rev_revenue_records WHERE user_id = " . self::TEST_USER_ID);
    }

    protected function tearDown(): void
    {
        $pdo = self::$pdo;
        if ($pdo === null) {
            return;
        }
        $pdo->exec("DELETE FROM booking_status_history WHERE booking_id IN (SELECT id FROM bookings WHERE user_id = " . self::TEST_USER_ID . ")");
        $pdo->exec("DELETE FROM bookings WHERE user_id = " . self::TEST_USER_ID);
        $pdo->exec("DELETE FROM inventory WHERE product_id = 999015");
        $pdo->exec("DELETE FROM crm_deals WHERE owner_user_id = " . self::TEST_USER_ID);
        $pdo->exec("DELETE FROM rev_revenue_records WHERE user_id = " . self::TEST_USER_ID);
        $pdo->exec("DELETE FROM crm_contacts WHERE id = 999015");
        $pdo->exec("DELETE FROM crm_pipeline_stages WHERE id = 999015");
        $pdo->exec("DELETE FROM crm_products WHERE id = 999015");
        $pdo->exec("DELETE FROM users WHERE id = " . self::TEST_USER_ID);
    }

    private function createConfirmedBooking(string $date = '2026-12-01'): array
    {
        $inventory = new InventoryService();
        $inventory->setDay(self::TEST_USER_ID, 999015, $date, 5);

        $engine = new BookingEngine();
        $result = $engine->createBooking(self::TEST_USER_ID, [
            'product_id' => 999015,
            'start_date' => $date,
            'customer_name' => 'Rev Customer',
            'customer_email' => 'rev-customer@tourfecto.test',
            'adults_count' => 1,
        ]);

        $this->assertTrue($engine->confirmBooking(self::TEST_USER_ID, (int) $result['id']));
        return $result;
    }

    private function fetchBookingRevenue(string $source): array
    {
        $rows = self::$pdo->query(
            "SELECT source, reference_id, amount, currency, recorded_at
             FROM rev_revenue_records
             WHERE user_id = " . self::TEST_USER_ID . " AND source = '" . $source . "'
             ORDER BY id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    /**
     * يعيد توقيت كل سجلات الإيراد للمستخدم لوقت سابق صراحةً (ساعة واحدة
     * مضت) — getOverview/getRevenueBySourceWithGrowth يستخدمان نهاية فترة
     * حصرية (recorded_at < now)، والسجلات المكتوبة بـ NOW() في نفس الثانية
     * اللي بيتنفذ فيها الاستعلام كانت ستُستبعد، فالاختبار كان هيبقى
     * متوقفًا على توقيت تنفيذ غير محكوم. الخطوة دي تخلي التوقيت حتميًا.
     */
    private function backdateRevenueRecords(): void
    {
        self::$pdo->exec(
            "UPDATE rev_revenue_records
             SET recorded_at = DATE_SUB(NOW(), INTERVAL 1 HOUR)
             WHERE user_id = " . self::TEST_USER_ID
        );
    }

    public function testConfirmBookingCreatesRevenueRecord(): void
    {
        $result = $this->createConfirmedBooking('2026-12-01');

        $rows = $this->fetchBookingRevenue('booking');
        $this->assertCount(1, $rows);
        $this->assertSame('booking', $rows[0]['source']);
        $this->assertSame($result['booking_reference'], $rows[0]['reference_id']);
        $this->assertSame('200.00', number_format((float) $rows[0]['amount'], 2, '.', ''));
        $this->assertSame('USD', $rows[0]['currency']);
        $this->assertNotNull($rows[0]['recorded_at']);
    }

    public function testConfirmBookingFromPaymentCreatesRevenueRecord(): void
    {
        $inventory = new InventoryService();
        $inventory->setDay(self::TEST_USER_ID, 999015, '2026-12-02', 5);

        $engine = new BookingEngine();
        $result = $engine->createBooking(self::TEST_USER_ID, [
            'product_id' => 999015,
            'start_date' => '2026-12-02',
            'customer_name' => 'Rev Customer',
            'customer_email' => 'rev-customer@tourfecto.test',
            'adults_count' => 1,
        ]);

        $this->assertTrue($engine->confirmBookingFromPayment((int) $result['id']));

        $rows = $this->fetchBookingRevenue('booking');
        $this->assertCount(1, $rows);
        $this->assertSame($result['booking_reference'], $rows[0]['reference_id']);
        $this->assertSame('200.00', number_format((float) $rows[0]['amount'], 2, '.', ''));
    }

    public function testDuplicateConfirmDoesNotDuplicateRevenueRecord(): void
    {
        $result = $this->createConfirmedBooking('2026-12-03');

        $engine = new BookingEngine();
        // إعادة تأكيد (يدوي) - الكود الحالي بيسمح بها، لكن الإيراد لازم يفضل idempotent
        $engine->confirmBooking(self::TEST_USER_ID, (int) $result['id']);
        // وإعادة تأكيد من مسار الدفع
        $engine->confirmBookingFromPayment((int) $result['id']);

        $rows = $this->fetchBookingRevenue('booking');
        $this->assertCount(1, $rows);
        $this->assertSame($result['booking_reference'], $rows[0]['reference_id']);
    }

    public function testCancelConfirmedBookingAddsRefundRecord(): void
    {
        $result = $this->createConfirmedBooking('2026-12-04');

        $engine = new BookingEngine();
        $this->assertTrue($engine->cancelBooking(self::TEST_USER_ID, (int) $result['id'], 'اختبار الإلغاء'));

        $booking = $this->fetchBookingRevenue('booking');
        $this->assertCount(1, $booking);

        $refunds = $this->fetchBookingRevenue('booking_refund');
        $this->assertCount(1, $refunds);
        $this->assertSame('booking_refund', $refunds[0]['source']);
        $this->assertSame($result['booking_reference'], $refunds[0]['reference_id']);
        $this->assertSame('-200.00', number_format((float) $refunds[0]['amount'], 2, '.', ''));
        $this->assertSame('USD', $refunds[0]['currency']);
    }

    public function testCancelPendingBookingDoesNotAddRefundRecord(): void
    {
        $inventory = new InventoryService();
        $inventory->setDay(self::TEST_USER_ID, 999015, '2026-12-05', 5);

        $engine = new BookingEngine();
        $result = $engine->createBooking(self::TEST_USER_ID, [
            'product_id' => 999015,
            'start_date' => '2026-12-05',
            'customer_name' => 'Rev Customer',
            'customer_email' => 'rev-customer@tourfecto.test',
            'adults_count' => 1,
        ]);

        $this->assertTrue($engine->cancelBooking(self::TEST_USER_ID, (int) $result['id'], 'إلغاء قبل التأكيد'));

        $this->assertCount(0, $this->fetchBookingRevenue('booking'));
        $this->assertCount(0, $this->fetchBookingRevenue('booking_refund'));
    }

    public function testDuplicateCancelDoesNotAddDuplicateRefund(): void
    {
        $result = $this->createConfirmedBooking('2026-12-06');

        $engine = new BookingEngine();
        $this->assertTrue($engine->cancelBooking(self::TEST_USER_ID, (int) $result['id'], 'إلغاء أول'));
        // إلغاء حجز ملغي يرفض (Exception) — نتأكد أصلًا إن التصحيح لم يتكرر
        try {
            $engine->cancelBooking(self::TEST_USER_ID, (int) $result['id'], 'إلغاء مكرر');
            $this->fail('إلغاء حجز ملغي بالفعل يجب أن يرفض');
        } catch (Exception $e) {
            $this->assertStringContainsString('لا يمكن إلغاء', $e->getMessage());
        }

        $refunds = $this->fetchBookingRevenue('booking_refund');
        $this->assertCount(1, $refunds);
    }

    public function testOverviewReflectsBookingRevenueMixedWithManualData(): void
    {
        // سجل يدوي (مصدر Stripe/CRM الحالي للموديول)
        self::$pdo->exec(
            "INSERT INTO rev_revenue_records (user_id, source, amount, currency, recorded_at, notes)
             VALUES (" . self::TEST_USER_ID . ", 'manual', 150.00, 'USD', NOW(), 'manual seed')"
        );
        // حجز مؤكد = 200 → صافي الإيراد المتوقع = 350
        $result = $this->createConfirmedBooking('2026-12-07');
        $this->backdateRevenueRecords();

        $overview = (new RevenueOverviewService())->getOverview(self::TEST_USER_ID, 'monthly');

        $this->assertSame(350.0, $overview['total_revenue']);
        $this->assertSame(2, $overview['revenue_records_count']);

        // إلغاء الحجز المؤكد → سجل تصحيحي -200 → صافي = 150
        (new BookingEngine())->cancelBooking(self::TEST_USER_ID, (int) $result['id'], 'إلغاء');
        $this->backdateRevenueRecords();

        $overview2 = (new RevenueOverviewService())->getOverview(self::TEST_USER_ID, 'monthly');
        $this->assertSame(150.0, $overview2['total_revenue']);
        $this->assertSame(3, $overview2['revenue_records_count']); // manual + booking + booking_refund

        // توزيع المصادر
        $bySource = $overview2['revenue_by_source'];
        $bySourceIndexed = [];
        foreach ($bySource as $item) {
            $bySourceIndexed[$item['source']] = $item;
        }
        $this->assertSame(150.0, $bySourceIndexed['manual']['total']);
        $this->assertSame(200.0, $bySourceIndexed['booking']['total']);
        $this->assertSame(-200.0, $bySourceIndexed['booking_refund']['total']);
    }

    public function testRevenueBySourceWithGrowthIncludesBooking(): void
    {
        $this->createConfirmedBooking('2026-12-08');
        $this->backdateRevenueRecords();

        $out = (new RevenueOverviewService())->getRevenueBySourceWithGrowth(self::TEST_USER_ID, 'monthly');

        $this->assertTrue($out['has_data']);
        $sources = array_column($out['sources'], 'source');
        $this->assertContains('booking', $sources);
        foreach ($out['sources'] as $item) {
            if ($item['source'] === 'booking') {
                $this->assertSame(200.0, $item['revenue']);
            }
        }
    }

    public function testCustomerRevenueServiceUnaffectedByBookingRecords(): void
    {
        // صفقة CRM مكسوبة لعملاء الحساب (المصدر الأصلي لـ CustomerRevenue)
        self::$pdo->exec(
            "INSERT INTO crm_deals (id, owner_user_id, contact_id, stage_id, title, value, currency, status, closed_at)
             VALUES (999015, " . self::TEST_USER_ID . ", 999015, 999015, 'Won Rev Deal', 500.00, 'USD', 'won', NOW())
             ON DUPLICATE KEY UPDATE status = 'won', closed_at = NOW()"
        );
        // حجز مؤكد يُسجّل إيراد booking — يجب ألا يغير تقرير العملاء
        $this->createConfirmedBooking('2026-12-09');

        $customer = (new CustomerRevenueService())->getCustomerRevenueIntelligence(self::TEST_USER_ID);

        $this->assertTrue($customer['has_data']);
        $this->assertCount(1, $customer['customers']);
        $this->assertSame(500.0, $customer['customers'][0]['customer_revenue']);
        $this->assertSame('crm_deals (status=won)', $customer['data_source']);
    }
}

<?php

/**
 * Tourfecto - Website Builder Booking Integration Test
 * بيتخطى تلقائيًا (markTestSkipped) لو DB غير متاحة.
 *
 * بيفحص ربط جولات/غرف الموقع المولّد بصفوف crm_products الفعلية عبر
 * (website_id + tour_slug) وتدفق الحجز المباشر من الصفحة العامة:
 *   1) syncTourToProduct upsert: بيعمل صف جديد أول مرة، وبيحدّث من غير
 *      تكرار لما يتنادى تاني (سعر/اسم متغيرين).
 *   2) bookSiteTour بينشئ حجز عبر BookingEngine بـ source='website'
 *      وproduct_id صحيح، وبيعمل توفر افتراضي لو مفيش inventory متسجّل.
 *   3) Fallback آمن لما مفيش منتج مرتبط بالعنصر (ما بيكسّرش، بيرجع
 *      رسالة whatsapp_fallback).
 *   4) رفض تاريخ ماضي/فاضي بدون استثناءات غير متوقعة.
 * @version 1.0.0  @date 2026-08-25
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/BookingEngine.php';
require_once __DIR__ . '/../../app/Services/InventoryService.php';
require_once __DIR__ . '/../../app/Services/Payment/StripeCheckoutService.php';
require_once __DIR__ . '/../../app/Services/WebsiteBuilderService.php';
require_once __DIR__ . '/../../app/Models/Booking.php';
require_once __DIR__ . '/../../app/Models/GeneratedWebsite.php';
require_once __DIR__ . '/../../app/Models/CrmProduct.php';
require_once __DIR__ . '/../../app/Controllers/WebsiteBuilderController.php';

final class WebsiteBookingIntegrationTest extends TestCase
{
    private const TEST_USER_ID = 999003;
    private const TEST_WEBSITE_ID = 999003;
    private const TEST_SLUG = 'booking-test-site';
    private const TEST_TOUR_SLUG = 'nile-cruise';

    private static ?PDO $pdo = null;
    private static bool $dbChecked = false;
    private static ?WebsiteBuilderController $controller = null;

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
            if (!class_exists('Validator') && file_exists($app . '/Core/Validator.php')) {
                require_once $app . '/Core/Validator.php';
            }
            if (!class_exists('Controller') && file_exists($app . '/Core/Controller.php')) {
                require_once $app . '/Core/Controller.php';
            }

            $db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $conn = $ref->getValue($db);
            if (!$conn instanceof PDO) {
                return null;
            }

            // تأكد إن الجداول المطلوبة موجودة (الميجريشنز اتشغّلت)
            foreach (['bookings', 'generated_websites', 'crm_products'] as $table) {
                $found = $conn->query("SHOW TABLES LIKE '{$table}'")->fetchAll();
                if (empty($found)) {
                    return null;
                }
            }
            // تأكد إن عمود website_id موجود (ميجريشن 2026_08_25)
            $cols = $conn->query("SHOW COLUMNS FROM crm_products LIKE 'website_id'")->fetchAll();
            if (empty($cols)) {
                return null;
            }

            self::$pdo = $conn;
            return self::$pdo;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function tourContent(): string
    {
        return json_encode([
            'industry' => 'tours',
            'business_name' => 'Booking Test Travel',
            'language' => 'ar',
            'contact' => ['whatsapp' => '+201001234567'],
            'tours' => [
                [
                    'slug' => self::TEST_TOUR_SLUG,
                    'name' => 'رحلة النيل',
                    'short_description' => 'جولة نيلية مميزة',
                    'price' => '350$',
                    'duration' => 'يومين',
                ],
                [
                    'slug' => 'luxor-tour',
                    'name' => 'رحلة الأقصر',
                    'short_description' => 'زيارة معابد الأقصر',
                    'price' => '€150',
                    'duration' => 'يوم',
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    protected function setUp(): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            $this->markTestSkipped('DB غير متاحة أو ميجريشن ربط المواقع لسه ما اتشغّلش');
        }

        $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                    VALUES (999003, 'website-booking@tourfecto.test', 'x', 'Test', NOW())
                    ON DUPLICATE KEY UPDATE email = email");

        $content = $this->tourContent();
        $pdo->exec("INSERT INTO generated_websites
                        (id, user_id, slug, status, content_json, created_at)
                    VALUES (999003, 999003, 'booking-test-site', 'published', " . $pdo->quote($content) . ", NOW())
                    ON DUPLICATE KEY UPDATE user_id = user_id, content_json = VALUES(content_json), status = 'published'");

        $pdo->exec("DELETE FROM booking_status_history WHERE booking_id IN (SELECT id FROM bookings WHERE user_id = 999003)");
        $pdo->exec("DELETE FROM bookings WHERE user_id = 999003");
        $pdo->exec("DELETE FROM inventory WHERE user_id = 999003");
        $pdo->exec("DELETE FROM crm_products WHERE website_id = 999003");

        if (self::$controller === null) {
            self::$controller = new WebsiteBuilderController();
        }

        // كل الاختبارات بتتصل بالـ endpoint اللي بيرجع JSON
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
    }

    protected function tearDown(): void
    {
        $pdo = self::$pdo;
        if ($pdo === null) {
            return;
        }
        $pdo->exec("DELETE FROM booking_status_history WHERE booking_id IN (SELECT id FROM bookings WHERE user_id = 999003)");
        $pdo->exec("DELETE FROM bookings WHERE user_id = 999003");
        $pdo->exec("DELETE FROM inventory WHERE user_id = 999003");
        $pdo->exec("DELETE FROM crm_products WHERE website_id = 999003");
    }

    private function invokePrivate(object $obj, string $method, array $args)
    {
        $ref = new ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($obj, $args);
    }

    private function setControllerData(array $data): void
    {
        $ref = new ReflectionProperty(self::$controller, 'data');
        $ref->setAccessible(true);
        $ref->setValue(self::$controller, $data);
    }

    private function linkedProductRow(): ?array
    {
        $rows = self::$pdo->query(
            "SELECT id, website_id, tour_slug, name, price, currency, is_active
             FROM crm_products WHERE website_id = 999003 ORDER BY id"
        )->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    public function testSyncTourToProductCreatesLinkedRows(): void
    {
        $website = (new GeneratedWebsite())->find(self::TEST_WEBSITE_ID);
        $content = $website->getContent();

        foreach ($content['tours'] as $tour) {
            $this->invokePrivate(self::$controller, 'syncTourToProduct', [$website, $tour]);
        }

        $rows = $this->linkedProductRow();
        $this->assertCount(2, $rows, 'لازم يتعمل صف منتج واحد لكل رحلة (مفيش تكرار)');

        $nile = null;
        foreach ($rows as $r) {
            $this->assertSame(self::TEST_WEBSITE_ID, (int) $r['website_id']);
            $this->assertSame(self::TEST_USER_ID, (int) self::$pdo->query("SELECT user_id FROM crm_products WHERE id = " . (int) $r['id'])->fetchColumn());
            if ($r['tour_slug'] === self::TEST_TOUR_SLUG) {
                $nile = $r;
            }
        }
        $this->assertNotNull($nile);
        $this->assertSame('رحلة النيل', $nile['name']);
        $this->assertEquals(350.0, (float) $nile['price']);
        $this->assertSame('USD', $nile['currency']);
        $this->assertSame(1, (int) $nile['is_active']);

        $luxor = null;
        foreach ($rows as $r) {
            if ($r['tour_slug'] === 'luxor-tour') {
                $luxor = $r;
            }
        }
        $this->assertNotNull($luxor);
        $this->assertEquals(150.0, (float) $luxor['price']);
        $this->assertSame('EUR', $luxor['currency']);
    }

    public function testSyncTourToProductDoesNotDuplicateOnResync(): void
    {
        $website = (new GeneratedWebsite())->find(self::TEST_WEBSITE_ID);
        $content = $website->getContent();
        $tour = $content['tours'][0];

        $this->invokePrivate(self::$controller, 'syncTourToProduct', [$website, $tour]);
        $this->invokePrivate(self::$controller, 'syncTourToProduct', [$website, $tour]);
        $this->invokePrivate(self::$controller, 'syncTourToProduct', [$website, $tour]);

        $this->assertCount(1, $this->linkedProductRow());
    }

    public function testSyncTourToProductUpdatesExistingRow(): void
    {
        $website = (new GeneratedWebsite())->find(self::TEST_WEBSITE_ID);
        $content = $website->getContent();
        $tour = $content['tours'][0];

        $this->invokePrivate(self::$controller, 'syncTourToProduct', [$website, $tour]);

        $updated = $tour;
        $updated['price'] = '420 $';
        $updated['name'] = 'رحلة النيل الفاخرة';
        $this->invokePrivate(self::$controller, 'syncTourToProduct', [$website, $updated]);

        $rows = $this->linkedProductRow();
        $this->assertCount(1, $rows, 'التحديث ميكررش الصف - بيحدّث نفس الصف');
        $this->assertSame('رحلة النيل الفاخرة', $rows[0]['name']);
        $this->assertEquals(420.0, (float) $rows[0]['price']);
    }

    public function testBookSiteTourCreatesBookingFromWebsite(): void
    {
        $website = (new GeneratedWebsite())->find(self::TEST_WEBSITE_ID);
        $content = $website->getContent();
        $this->invokePrivate(self::$controller, 'syncTourToProduct', [$website, $content['tours'][0]]);

        $this->setControllerData([
            'start_date' => '2026-12-10',
            'customer_name' => 'عميل تجريبي',
            'customer_phone' => '+201000000001',
            'customer_email' => 'customer@test.com',
            'adults_count' => 2,
            'children_count' => 1,
        ]);

        $result = self::$controller->bookSiteTour([
            'slug' => self::TEST_SLUG,
            'tourSlug' => self::TEST_TOUR_SLUG,
        ]);

        $this->assertTrue($result['success'], 'الحجز لازم ينجح رغم عدم تسجيل توفر - بيعمل افتراضي');
        $this->assertNotEmpty($result['data']['booking_reference']);
        $this->assertNull($result['data']['checkout_url'], 'Stripe مش مفعّل في الاختبار - مفيش رابط دفع');

        $bookingRow = self::$pdo->query(
            "SELECT * FROM bookings WHERE booking_reference = " . self::$pdo->quote($result['data']['booking_reference'])
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($bookingRow);
        $this->assertSame('website', $bookingRow['source']);
        $this->assertSame('pending', $bookingRow['status']);
        $this->assertSame('عميل تجريبي', $bookingRow['customer_name']);
        $this->assertEquals(2, (int) $bookingRow['adults_count']);
        $this->assertEquals(1, (int) $bookingRow['children_count']);

        $productId = (int) $bookingRow['product_id'];
        $linked = self::$pdo->query(
            "SELECT website_id, tour_slug FROM crm_products WHERE id = {$productId}"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(self::TEST_WEBSITE_ID, (int) $linked['website_id']);
        $this->assertSame(self::TEST_TOUR_SLUG, $linked['tour_slug']);

        // الحجز شغّل عداد التوفر الافتراضي اللي اتعمل
        $inv = self::$pdo->query(
            "SELECT capacity, booked_count FROM inventory WHERE product_id = {$productId} AND date = '2026-12-10'"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($inv);
        $this->assertEquals(50, (int) $inv['capacity']);
        $this->assertEquals(1, (int) $inv['booked_count']);
    }

    public function testBookSiteTourRejectsPastDate(): void
    {
        $website = (new GeneratedWebsite())->find(self::TEST_WEBSITE_ID);
        $content = $website->getContent();
        $this->invokePrivate(self::$controller, 'syncTourToProduct', [$website, $content['tours'][0]]);

        $this->setControllerData([
            'start_date' => '2020-01-01',
            'customer_name' => 'عميل تجريبي',
        ]);

        $result = self::$controller->bookSiteTour([
            'slug' => self::TEST_SLUG,
            'tourSlug' => self::TEST_TOUR_SLUG,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(422, $result['code']);
    }

    public function testBookSiteTourFallsBackWhenNoLinkedProduct(): void
    {
        // مفيش sync حاصل - العنصر مش مرتبط بأي crm_product
        $this->setControllerData([
            'start_date' => '2026-12-11',
            'customer_name' => 'عميل تجريبي',
        ]);

        $result = self::$controller->bookSiteTour([
            'slug' => self::TEST_SLUG,
            'tourSlug' => self::TEST_TOUR_SLUG,
        ]);

        $this->assertFalse($result['success'], 'مفيش منتج مرتبط = fallback آمن مش نجاح كاذب');
        $this->assertTrue($result['details']['whatsapp_fallback'] ?? false, 'الـ fallback لازم يعلّم إن واتساب متاح');
        $this->assertSame(422, $result['code']);
    }

    public function testBookSiteTourMissingFieldsValidation(): void
    {
        $this->setControllerData([
            'start_date' => '2026-12-12',
        ]);

        $result = self::$controller->bookSiteTour([
            'slug' => self::TEST_SLUG,
            'tourSlug' => self::TEST_TOUR_SLUG,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(422, $result['code']);
    }
}

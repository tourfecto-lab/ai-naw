<?php

/**
 * Tourfecto - Full Booking Journey Integration Test (Documentation / Discovery)
 * بيتخطى تلقائيًا (markTestSkipped) لو DB غير متاحة.
 *
 * بيعيد بناء الرحلة الكاملة لحجز سياحي من الموقع العام لحد العمولة، خطوة بخطوة،
 * وبيوثّق السلوك الفعلي الحالي للنظام (مع نتائج مختلطة: نجاح / فجوات موثقة):
 *
 *   1) زائر على الصفحة العامة بيحجز رحلة من موقع الشركة المولّد (bookSiteTour)
 *      → حجز pending بـ source='website' و product_id مربوط بالرحلة.
 *   2) لو فيه CrmDeal مفتوحة لنفس العميل (نفس الإيميل) → بتتربط بالحجز
 *      (crm_deals.booking_id) وهي لسه open.
 *   3) معاملة دفع pending اتنشأت (المحاكاة المحلية للـ Stripe Checkout Session).
 *   4) Webhook نجاح Stripe (checkout.session.completed) → الحجز confirmed
 *      والمعاملة succeeded (idempotent).
 *   5) الصفقة المربوطة بتتقفل won تلقائيًا (markLinkedDealWon).
 *   6) عمولة الوكالة بتتسجّل تلقائيًا = total_amount × commission_rate (pending).
 *   7) إيميل تأكيد الحجز بيتجدول كـ Job غير متزامن (SendBookingConfirmationJob)
 *      على طابور 'email' — الاختبار بيثبت إن الجدولة بتتم فعلًا.
 *   8) نفس الرحلة عبر Paymob (webhook success=true) → نفس النتائج (4-6).
 *   9) Webhook فشل (Stripe expired) → الحجز لسه pending، المعاملة failed،
 *      مفيش عمولة ومفيش deal اتقفلت won بالغلط.
 *  10) [مُصلحة] إلغاء بعد التأكيد → cancelBooking بيلغي عمولة الـ pending
 *      تلقائيًا (voided)، والـ deal اللي اتقفلت won بتفضل won (crm_deals لا
 *      تُلمس عمدًا — قرار بشري موثق في PROGRESS.md).
 *
 * ملاحظة: ده اختبار توثيقي/اكتشافي. أي خطوة فاشلة = فجوة حقيقية بتتوثّق في
 * PROGRESS.md تحت "نتيجة اختبار الرحلة الكاملة" — مش بتتصلّح هنا (عدا فجوات
 * تافهة واضحة). مفيش أي استدعاء فعلي لـ Stripe/Rest — كل webhook بيتنفّذ على
 * الـ service مباشرة بتوقيع صحيح.
 * @version 1.0.0  @date 2026-08-26
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/BookingEngine.php';
require_once __DIR__ . '/../../app/Services/InventoryService.php';
require_once __DIR__ . '/../../app/Services/Payment/StripeCheckoutService.php';
require_once __DIR__ . '/../../app/Services/Payment/PaymobGateway.php';
require_once __DIR__ . '/../../app/Services/WebsiteBuilderService.php';
require_once __DIR__ . '/../../app/Models/Booking.php';
require_once __DIR__ . '/../../app/Models/GeneratedWebsite.php';
require_once __DIR__ . '/../../app/Models/CrmProduct.php';
require_once __DIR__ . '/../../app/Controllers/WebsiteBuilderController.php';

final class FullBookingJourneyIntegrationTest extends TestCase
{
    private const AGENCY_OWNER = 999510;
    private const COMPANY_USER = 999511;
    private const AGENCY       = 999512;
    private const AGENCY_CLIENT = 999513;
    private const WEBSITE_ID   = 999514;
    private const PRODUCT_ID   = 999515;
    private const CONTACT_ID   = 999516;
    private const STAGE_ID     = 999517;
    private const DEAL_ID      = 999518;

    private const SLUG        = 'journey-travel-site';
    private const TOUR_SLUG   = 'siwa-trip';
    private const VISITOR_EMAIL = 'journey-visitor@example.com';
    private const VISITOR_PHONE = '+201002000000';
    private const COMMISSION_RATE = 12.00;
    private const TOUR_PRICE_USD = 100.00;

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

            foreach (['users', 'agencies', 'agency_clients', 'agency_commissions',
                      'generated_websites', 'crm_products', 'crm_contacts', 'crm_deals',
                      'bookings', 'payment_transactions', 'jobs'] as $table) {
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
            $this->markTestSkipped('DB غير متاحة أو ميجريشنز الرحلة لسه ما اتشغّلتش');
        }

        // 1) تنظيف صريح قبل أي اختبار (نفس أسلوب بقية الـ Suite)
        $this->cleanup();

        // 2) الـ fixtures الأساسية للرحلة
        foreach ([
            [self::AGENCY_OWNER, 'journey-agency-owner@tourfecto.test', 'Journey Agency Owner'],
            [self::COMPANY_USER, 'journey-travel-co@tourfecto.test', 'Journey Travel Co'],
        ] as $u) {
            $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                        VALUES ({$u[0]}, '{$u[1]}', 'x', '{$u[2]}', NOW())
                        ON DUPLICATE KEY UPDATE email = email");
        }

        // الوكالة اللي الشركة عميل تحتها بنسبة 12%
        $pdo->exec("INSERT INTO agencies (id, owner_user_id, name, slug, status, plan_seats)
                    VALUES (999512, 999510, 'Journey Agency', 'journey-agency', 'active', 10)
                    ON DUPLICATE KEY UPDATE owner_user_id = 999510");
        $pdo->exec("INSERT INTO agency_clients (id, agency_id, client_user_id, status, commission_rate)
                    VALUES (999513, 999512, 999511, 'active', 12.00)
                    ON DUPLICATE KEY UPDATE agency_id = 999512, client_user_id = 999511, status = 'active', commission_rate = 12.00");

        // الموقع المولّد لشركة السياحة (اللي الزائر هيسيب عليه)
        $content = $this->siteContent();
        $pdo->exec("INSERT INTO generated_websites
                        (id, user_id, slug, status, content_json, created_at)
                    VALUES (999514, 999511, 'journey-travel-site', 'published', "
                        . $pdo->quote($content) . ", NOW())
                    ON DUPLICATE KEY UPDATE user_id = 999511, content_json = VALUES(content_json), status = 'published'");

        // نزرع المنتج برقم ثابت بنفس (website_id, tour_slug) اللي الـ sync بيحدّثه
        $pdo->exec("INSERT INTO crm_products
                        (id, user_id, website_id, tour_slug, name, sku, price, currency, is_active)
                    VALUES (999515, 999511, 999514, 'siwa-trip', 'رحلة سيوة', 'WS999514-siwa-trip', 100.00, 'USD', 1)
                    ON DUPLICATE KEY UPDATE website_id = 999514, tour_slug = 'siwa-trip'");

        // عميل CRM + مرحلة + صفقة مفتوحة لنفس إيميل الزائر
        $pdo->exec("INSERT INTO crm_pipeline_stages (id, agency_id, name, slug, sort_order, win_probability)
                    VALUES (999517, NULL, 'Journey Stage', 'journey-stage', 1, 50)
                    ON DUPLICATE KEY UPDATE slug = slug");
        $pdo->exec("INSERT INTO crm_contacts (id, user_id, name, email, phone)
                    VALUES (999516, 999511, 'Journey Visitor', 'journey-visitor@example.com', '+201002000000')
                    ON DUPLICATE KEY UPDATE email = email, phone = phone");
        $pdo->exec("INSERT INTO crm_deals (id, owner_user_id, contact_id, stage_id, title, value, currency, status)
                    VALUES (999518, 999511, 999516, 999517, 'Deal Journey Visitor', 100.00, 'USD', 'open')
                    ON DUPLICATE KEY UPDATE status = 'open', booking_id = NULL");

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
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $pdo = self::$pdo;
        if ($pdo === null) {
            return;
        }
        $pdo->exec("DELETE FROM booking_status_history WHERE booking_id IN (SELECT id FROM bookings WHERE user_id = 999511)");
        $pdo->exec("DELETE FROM agency_commissions WHERE agency_id = 999512");
        $pdo->exec("DELETE FROM payment_transactions WHERE user_id = 999511");
        $pdo->exec("DELETE FROM bookings WHERE user_id = 999511");
        $pdo->exec("DELETE FROM inventory WHERE product_id = 999515");
        $pdo->exec("DELETE FROM crm_deals WHERE id = 999518 OR owner_user_id = 999511");
        $pdo->exec("DELETE FROM crm_contacts WHERE id = 999516");
        $pdo->exec("DELETE FROM crm_pipeline_stages WHERE id = 999517");
        $pdo->exec("DELETE FROM crm_products WHERE id = 999515 OR website_id = 999514");
        $pdo->exec("DELETE FROM agency_clients WHERE agency_id = 999512");
        $pdo->exec("DELETE FROM agencies WHERE id = 999512");
        $pdo->exec("DELETE FROM generated_websites WHERE id = 999514");
        $pdo->exec("DELETE FROM users WHERE id IN (999510, 999511)");
    }

    private function siteContent(): string
    {
        return json_encode([
            'industry' => 'tours',
            'business_name' => 'Journey Travel Co',
            'language' => 'ar',
            'contact' => ['whatsapp' => '+201002000000'],
            'tours' => [
                [
                    'slug' => self::TOUR_SLUG,
                    'name' => 'رحلة سيوة',
                    'short_description' => 'مغامرة صحراوية',
                    'price' => '100$',
                    'duration' => 'يومين',
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);
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

    /**
     * الخطوة 1+2: حجز من الصفحة العامة وربط الـ deal المفتوحة (لو موجودة).
     * بترجع الـ booking row كامل.
     */
    private function bookFromPublicSite(string $date = '2026-12-15'): array
    {
        // sync الرحلة للـ crm_products (بحدّث الصف المزروع برقمه الثابت)
        $website = (new GeneratedWebsite())->find(self::WEBSITE_ID);
        $this->invokePrivate(self::$controller, 'syncTourToProduct', [$website, $website->getContent()['tours'][0]]);

        $this->setControllerData([
            'start_date' => $date,
            'customer_name' => 'زائر الرحلة الكاملة',
            'customer_phone' => self::VISITOR_PHONE,
            'customer_email' => self::VISITOR_EMAIL,
            'adults_count' => 1,
            'children_count' => 0,
        ]);

        $result = self::$controller->bookSiteTour([
            'slug' => self::SLUG,
            'tourSlug' => self::TOUR_SLUG,
        ]);

        $this->assertTrue($result['success'], 'الخطوة 1: الحجز من الصفحة العامة لازم ينجح');
        $this->assertNotEmpty($result['data']['booking_reference']);

        $row = self::$pdo->query(
            'SELECT * FROM bookings WHERE booking_reference = '
            . self::$pdo->quote($result['data']['booking_reference'])
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($row, 'الخطوة 1: صف الحجز لازم يتسجّل');

        return $row;
    }

    /**
     * الخطوة 3: إدراج معاملة دفع pending (المحاكاة المحلية لما Stripe/Paymob
     * ينشئ جلسة دفع ويحجزه في payment_transactions).
     */
    private function insertPendingTx(int $bookingId, string $reference, string $gateway, string $gatewayTxId, string $amountCents = '10000'): int
    {
        $amount = $gateway === 'paymob' ? $amountCents : '100.00';
        $currency = $gateway === 'paymob' ? 'EGP' : 'USD';
        self::$pdo->prepare('INSERT INTO payment_transactions
            (internal_transaction_id, user_id, amount, currency, payment_method, gateway,
             status, reference, booking_id, metadata, idempotency_key, gateway_transaction_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                'ptx_' . $gateway . '_' . uniqid(),
                self::COMPANY_USER,
                $amount,
                $currency,
                'card',
                $gateway,
                'pending',
                $reference,
                $bookingId,
                '{}',
                'idem_' . $gateway . '_' . $bookingId,
                $gatewayTxId,
            ]);
        return (int) self::$pdo->lastInsertId();
    }

    private function signStripeWebhook(string $payload): string
    {
        $secret = getenv('STRIPE_WEBHOOK_SECRET') ?: 'test_webhook_secret';
        $ts = time();
        return "t={$ts},v1=" . hash_hmac('sha256', $ts . '.' . $payload, $secret);
    }

    private function stripePayload(string $type, string $sessionId, string $reference): string
    {
        $object = ['id' => $sessionId, 'client_reference_id' => $reference];
        if ($type === 'checkout.session.expired') {
            $object['status'] = 'expired';
        }
        return json_encode(['type' => $type, 'data' => ['object' => $object]]);
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

    private function signPaymobWebhook(string $payload): string
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

    private function paymobPayload(string $txId, string $reference, bool $success = true): string
    {
        return json_encode([
            'id' => $txId,
            'transaction_id' => $txId,
            'amount_cents' => 10000,
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
            'order' => ['id' => 999510, 'merchant_order_id' => $reference],
        ]);
    }

    /**
     * الخطوات 5-7 المشتركة: deal won + عمولة الوكالة + فحص إيميل التأكيد (فجوة).
     */
    private function assertPostPaymentOutcomes(int $bookingId): void
    {
        // الخطوة 5: الـ deal المربوطة اتقفلت won تلقائيًا
        $deal = self::$pdo->query('SELECT status, closed_at, booking_id FROM crm_deals WHERE id = 999518')->fetch();
        $this->assertSame((int) $bookingId, (int) $deal['booking_id'], 'الخطوة 5: الـ deal لسه مربوطة بنفس الحجز');
        $this->assertSame('won', $deal['status'], 'الخطوة 5: الصفقة المربوطة بالحجز بتتقفل won تلقائيًا');
        $this->assertNotEmpty($deal['closed_at'], 'الخطوة 5: بتتسجّل closed_at');

        // الخطوة 6: عمولة الوكالة اتسجّلت تلقائيًا = total × نسبة العميل
        $comm = self::$pdo->query('SELECT * FROM agency_commissions WHERE booking_id = ' . $bookingId)->fetch();
        $this->assertNotEmpty($comm, 'الخطوة 6: عمولة الوكالة لازم تتسجّل تلقائيًا بعد تأكيد الدفع');
        $this->assertSame(self::AGENCY, (int) $comm['agency_id']);
        $this->assertSame(self::AGENCY_CLIENT, (int) $comm['agency_client_id']);
        $this->assertEquals(12.00, (float) $comm['commission_amount'], 'الخطوة 6: 100 × 12% = 12');
        $this->assertSame('pending', $comm['status'], 'الخطوة 6: العمولة بتبدأ pending');

        // الخطوة 7 [مُصلحة]: إيميل تأكيد الحجز بيتجدول كـ Job غير متزامن
        // (SendBookingConfirmationJob) على طابور 'email' للعميل.
        $job = self::$pdo->query(
            "SELECT * FROM jobs
             WHERE job_class = 'SendBookingConfirmationJob'
             ORDER BY id DESC LIMIT 1"
        )->fetch();
        $this->assertNotEmpty($job, 'الخطوة 7: Job إيميل التأكيد اتجدول فعلًا');
        $this->assertSame('email', $job['queue'], 'الخطوة 7: على طابور email');
        $jobPayload = json_decode((string) ($job['payload'] ?? '[]'), true);
        $this->assertSame($bookingId, (int) ($jobPayload['booking_id'] ?? 0), 'الخطوة 7: مرتبط بنفس الحجز');
        $this->assertSame(
            'pending',
            $job['status'],
            'الخطوة 7: المهمة مستحقة التنفيذ (pending) — الإرسال غير متزامن'
        );
    }

    /**
     * الرحلة الكاملة (الخطوات 1→7) عبر Stripe webhook.
     * أي خطوة تفشل هنا = فجوة حقيقية تتوثق في PROGRESS.md.
     */
    public function testFullJourneyStripeSuccess(): void
    {
        $pdo = self::$pdo;

        // الخطوة 1: حجز من الصفحة العامة (زائر مش مسجّل)
        $booking = $this->bookFromPublicSite();
        $bookingId = (int) $booking['id'];
        $reference = (string) $booking['booking_reference'];

        $this->assertSame('pending', $booking['status'], 'الخطوة 1: الحجز يبدأ pending');
        $this->assertSame('website', $booking['source'], 'الخطوة 1: المصدر هو الموقع العام');
        $this->assertSame(self::COMPANY_USER, (int) $booking['user_id'], 'الخطوة 1: صاحب الحجز = الشركة المالكة للموقع');
        $this->assertSame(self::PRODUCT_ID, (int) $booking['product_id'], 'الخطوة 1: المنتج المربوط بالرحلة');
        $this->assertEquals(100.00, (float) $booking['total_amount'], 'الخطوة 1: شخص واحد × سعر الرحلة');

        // الخطوة 2: الـ deal المفتوحة لنفس العميل اتربطت تلقائيًا (لسه open)
        $deal = $pdo->query('SELECT booking_id, status FROM crm_deals WHERE id = 999518')->fetch();
        $this->assertSame($bookingId, (int) $deal['booking_id'], 'الخطوة 2: ربط تلقائي للـ deal المفتوحة بنفس العميل');
        $this->assertSame('open', $deal['status'], 'الخطوة 2: لسه مفتوحة قبل الدفع');

        // الخطوة 3: معاملة دفع pending (محاكاة جلسة Stripe المحفوظة محليًا)
        $this->insertPendingTx($bookingId, $reference, 'stripe', 'cs_test_journey');
        $tx = $pdo->query("SELECT * FROM payment_transactions WHERE booking_id = {$bookingId}")->fetch();
        $this->assertSame('pending', $tx['status'], 'الخطوة 3: المعاملة بتبدأ pending');

        // الخطوة 4: Webhook نجاح Stripe
        $payload = $this->stripePayload('checkout.session.completed', 'cs_test_journey', $reference);
        $result = (new StripeCheckoutService())->handleWebhook($payload, $this->signStripeWebhook($payload));
        $this->assertTrue($result['handled'], 'الخطوة 4: الـ webhook اتعامل معاه');

        $bookingAfter = $pdo->query('SELECT status FROM bookings WHERE id = ' . $bookingId)->fetch();
        $this->assertSame('confirmed', $bookingAfter['status'], 'الخطوة 4: الحجز بقى confirmed');

        $txAfter = $pdo->query('SELECT status FROM payment_transactions WHERE booking_id = ' . $bookingId)->fetch();
        $this->assertSame('succeeded', $txAfter['status'], 'الخطوة 4: المعاملة succeeded');

        // Idempotent: إعادة نفس الـ webhook ماتعملش تأكيد مكرر
        $result2 = (new StripeCheckoutService())->handleWebhook($payload, $this->signStripeWebhook($payload));
        $this->assertTrue($result2['handled']);
        $confirmedHistory = (int) $pdo->query(
            "SELECT COUNT(*) FROM booking_status_history bsh JOIN bookings b ON b.id = bsh.booking_id
             WHERE b.id = {$bookingId} AND bsh.to_status = 'confirmed'"
        )->fetchColumn();
        $this->assertSame(1, $confirmedHistory, 'الخطوة 4: التفعيل idempotent');

        // الخطوات 5-7
        $this->assertPostPaymentOutcomes($bookingId);
    }

    /**
     * الخطوة 8: نفس الرحلة (1→7) عبر Paymob webhook بدل Stripe.
     */
    public function testFullJourneyPaymobSuccess(): void
    {
        $pdo = self::$pdo;

        $booking = $this->bookFromPublicSite('2026-12-16');
        $bookingId = (int) $booking['id'];
        $reference = (string) $booking['booking_reference'];

        $this->insertPendingTx($bookingId, $reference, 'paymob', 'pm_tx_journey');

        $payload = $this->paymobPayload('pm_tx_journey', $reference, true);
        $result = (new PaymobGateway())->handleWebhook($payload, $this->signPaymobWebhook($payload));
        $this->assertTrue($result['handled'], 'الخطوة 8: الـ webhook اتعامل معاه');

        $bookingAfter = $pdo->query('SELECT status FROM bookings WHERE id = ' . $bookingId)->fetch();
        $this->assertSame('confirmed', $bookingAfter['status'], 'الخطوة 8: الحجز بقى confirmed');

        $txAfter = $pdo->query('SELECT status FROM payment_transactions WHERE booking_id = ' . $bookingId)->fetch();
        $this->assertSame('succeeded', $txAfter['status'], 'الخطوة 8: المعاملة succeeded');

        $this->assertPostPaymentOutcomes($bookingId);
    }

    /**
     * الخطوة 9: Webhook فشل (Stripe expired) → الحجز لسه pending، مفيش عمولة،
     * ومفيش deal اتقفلت won بالغلط.
     */
    public function testPaymentFailureKeepsBookingPending(): void
    {
        $pdo = self::$pdo;

        $booking = $this->bookFromPublicSite('2026-12-17');
        $bookingId = (int) $booking['id'];
        $reference = (string) $booking['booking_reference'];

        // الـ deal اتربطت بالحجز لكن لسه open
        $deal = $pdo->query('SELECT status FROM crm_deals WHERE id = 999518')->fetch();
        $this->assertSame('open', $deal['status']);

        $this->insertPendingTx($bookingId, $reference, 'stripe', 'cs_test_expired');

        $payload = $this->stripePayload('checkout.session.expired', 'cs_test_expired', $reference);
        $result = (new StripeCheckoutService())->handleWebhook($payload, $this->signStripeWebhook($payload));
        $this->assertTrue($result['handled'], 'الخطوة 9: الـ webhook اتعامل معاه');

        $bookingAfter = $pdo->query('SELECT status FROM bookings WHERE id = ' . $bookingId)->fetch();
        $this->assertSame('pending', $bookingAfter['status'], 'الخطوة 9: فشل الدفع مأكّدش الحجز');

        $txAfter = $pdo->query('SELECT status FROM payment_transactions WHERE booking_id = ' . $bookingId)->fetch();
        $this->assertSame('failed', $txAfter['status'], 'الخطوة 9: المعاملة failed');

        $dealAfter = $pdo->query('SELECT status FROM crm_deals WHERE id = 999518')->fetch();
        $this->assertSame('open', $dealAfter['status'], 'الخطوة 9: مفيش deal اتقفلت won على فشل دفع');

        $commCount = (int) $pdo->query('SELECT COUNT(*) FROM agency_commissions WHERE booking_id = ' . $bookingId)->fetchColumn();
        $this->assertSame(0, $commCount, 'الخطوة 9: مفيش عمولة على حجز غير مؤكد');
    }

    /**
     * الخطوة 10 [مُصلحة]: إلغاء بعد التأكيد.
     * السلوك الجديد: cancelBooking بيرجّع الحجز cancelled وبيلغي عمولة
     * الـ pending تلقائيًا (voided). الـ deal اللي اتقفلت won مش بتترجع
     * لـ open — قرار بشري موثق (العمولة هي اللي بتتصفى، مش تاريخ الصفقة).
     */
    public function testCancelAfterConfirmVoidsCommissionAndKeepsDealWon(): void
    {
        $pdo = self::$pdo;

        $booking = $this->bookFromPublicSite('2026-12-18');
        $bookingId = (int) $booking['id'];
        $reference = (string) $booking['booking_reference'];

        $this->insertPendingTx($bookingId, $reference, 'stripe', 'cs_test_cancel');
        $payload = $this->stripePayload('checkout.session.completed', 'cs_test_cancel', $reference);
        $result = (new StripeCheckoutService())->handleWebhook($payload, $this->signStripeWebhook($payload));
        $this->assertTrue($result['handled']);

        $afterConfirm = $pdo->query('SELECT status FROM bookings WHERE id = ' . $bookingId)->fetch();
        $this->assertSame('confirmed', $afterConfirm['status']);

        // العمولة اتسجلت pending بعد التأكيد
        $comm = $pdo->query('SELECT status FROM agency_commissions WHERE booking_id = ' . $bookingId)->fetch();
        $this->assertNotEmpty($comm);
        $this->assertSame('pending', $comm['status']);

        // الإلغاء بعد التأكيد
        $cancelled = (new BookingEngine())->cancelBooking(self::COMPANY_USER, $bookingId, 'الزائر ألغى بعد الدفع');
        $this->assertTrue($cancelled, 'الخطوة 10: الإلغاء بينفّذ');

        $bookingAfter = $pdo->query('SELECT status FROM bookings WHERE id = ' . $bookingId)->fetch();
        $this->assertSame('cancelled', $bookingAfter['status']);

        // [مُصلحة] الـ deal فضلت won (crm_deals لا تُلمس — قرار بشري موثق)
        $deal = $pdo->query('SELECT status FROM crm_deals WHERE id = 999518')->fetch();
        $this->assertSame(
            'won',
            $deal['status'],
            'الخطوة 10: الـ deal المربوطة بالحجز الملغي فضلت won (crm_deals لا تُلمس)'
        );

        // [مُصلحة] عمولة الـ pending اتلغت تلقائيًا (voided)
        $commAfter = $pdo->query('SELECT status FROM agency_commissions WHERE booking_id = ' . $bookingId)->fetch();
        $this->assertNotEmpty($commAfter);
        $this->assertSame(
            'voided',
            $commAfter['status'],
            'الخطوة 10: عمولة حجز ملغي بقت voided تلقائيًا'
        );
    }
}

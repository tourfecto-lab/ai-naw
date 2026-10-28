<?php

/**
 * Tourfecto - Booking Ad Attribution + CAPI Integration Test
 * بيفحص مسار إسناد الحجوزات للإعلانات (Phase CAPI):
 *   1) ربط الحجز برابط UTM إعلاني (attributed_utm_link_id) + source
 *      الإعلاني (ad:meta / ad:google) عند الحجز.
 *   2) تجاهل الإسناد من حملة تابعة لحساب تاني (منع تلاعب الإسناد).
 *   3) تأكيد الحجز (يدوي أو بعد الدفع) بيجهّز حدث CAPI غير متزامن
 *      (SendAdConversionJob) للحجوزات المئسندة فقط.
 *   4) حساب ROAS من الحجوزات المرتبطة فعليًا (calculateRoas).
 *   5) تحويل PII لـ SHA-256 فقط (AdPiiHasher) في حمولة CAPI - مفيش أي
 *      بيانات شخصية خام تُرسل.
 *   6) تدفق الكوكي: /r/{code} بيخزّن الإسناد، والقراءة بتحترم نافذة 30 يوم.
 *
 * محتاج الميجريشنز: 2026_08_21_000001 (booking engine), 2026_08_15_000050
 * (ads + ad_utm_links), 2026_08_28_000001 (bookings.attributed_utm_link_id),
 * 2026_07_13_000001 (jobs). بيتخطى تلقائيًا لو DB غير متاحة.
 * @version 1.0.0  @date 2026-08-28
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Core/Container.php';
require_once __DIR__ . '/../../app/Core/Queue/QueueManager.php';
require_once __DIR__ . '/../../app/Core/Contracts/QueueJobInterface.php';
require_once __DIR__ . '/../../app/Models/AdCampaign.php';
require_once __DIR__ . '/../../app/Services/Ads/AdPiiHasher.php';
require_once __DIR__ . '/../../app/Services/Ads/AdTrackingService.php';
require_once __DIR__ . '/../../app/Services/Ads/MetaAdsAPI.php';
require_once __DIR__ . '/../../app/Services/Ads/GoogleAdsAPI.php';
require_once __DIR__ . '/../../app/Services/Ads/AdReportService.php';
require_once __DIR__ . '/../../app/Services/BookingEngine.php';
require_once __DIR__ . '/../../app/Jobs/SendAdConversionJob.php';

/** MetaAdsAPI وهمي بيرجّع نجاح فوري ويسجّل آخر حمولة - منع أي شبكة فعلية */
class FakeMetaAdsAPIForCapiTest extends MetaAdsAPI
{
    public ?array $lastEvent = null;

    public function __construct(string $accessToken)
    {
    }

    public function sendConversionEvent(string $pixelId, array $conversion): array
    {
        $this->lastEvent = ['pixel_id' => $pixelId, 'conversion' => $conversion];
        return ['success' => true, 'received' => true];
    }
}

/** SendAdConversionJob مع حقن الفيك API - نفس فكرة FakeOutreachEmailGenerator */
class FakeSendAdConversionJob extends SendAdConversionJob
{
    public ?FakeMetaAdsAPIForCapiTest $api = null;

    protected function makeMetaApi(string $token): MetaAdsAPI
    {
        $this->api = new FakeMetaAdsAPIForCapiTest($token);
        return $this->api;
    }
}

final class BookingAdAttributionCapiIntegrationTest extends TestCase
{
    private const USER_ID = 999500;
    private const OTHER_USER_ID = 999501;

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
                    $app . '/Config/encryption.php',
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

            $db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $conn = $ref->getValue($db);
            if (!$conn instanceof PDO) {
                self::$pdo = null;
                return null;
            }

            foreach (['bookings', 'ad_utm_links', 'ad_campaigns', 'platform_connections', 'jobs', 'crm_products', 'inventory'] as $table) {
                $found = $conn->query("SHOW TABLES LIKE '{$table}'")->fetchAll();
                if (empty($found)) {
                    self::$pdo = null;
                    return null;
                }
            }

            // عمود الإسناد نفسه لازم يكون موجود (migration 2026_08_28)
            $cols = $conn->query("SHOW COLUMNS FROM bookings LIKE 'attributed_utm_link_id'")->fetchAll();
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
            $this->markTestSkipped('DB غير متاحة أو جداول الإسناد مش متشغّلة - راجع تعليق أعلى الملف');
        }

        $_COOKIE = [];
        $_SESSION = [];
        $_SERVER['auth_user'] = null;
        unset($_SERVER['auth_user']);

        $this->cleanup();

        foreach ([self::USER_ID, self::OTHER_USER_ID] as $uid) {
            $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                        VALUES ($uid, 'capi-" . $uid . "@tourfecto.test', 'x', 'CAPI Test Travel', NOW())
                        ON DUPLICATE KEY UPDATE email = email");
        }
    }

    private function cleanup(): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return;
        }
        $pdo->exec("DELETE FROM jobs WHERE job_class = 'SendAdConversionJob'");
        $pdo->exec("DELETE FROM bookings WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
        $pdo->exec("DELETE FROM ad_utm_links WHERE campaign_id IN
                    (SELECT id FROM ad_campaigns WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . "))");
        $pdo->exec("DELETE FROM ad_campaigns WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
        $pdo->exec("DELETE FROM platform_connections WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
        $pdo->exec("DELETE FROM crm_products WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
        $pdo->exec("DELETE FROM inventory WHERE user_id IN (" . self::USER_ID . ", " . self::OTHER_USER_ID . ")");
    }

    /** حساب تابع لـ Meta ويبقى مسجّل */
    private function addPlatformConnection(int $userId, string $platform = 'meta_ads'): int
    {
        $pdo = $this->db();
        $stmt = $pdo->prepare("INSERT INTO platform_connections (user_id, platform, status, access_token, external_account_id)
                               VALUES (?, ?, 'connected', 'enc:test-token', 'ext-account-1')");
        $stmt->execute([$userId, $platform]);
        return (int) $pdo->lastInsertId();
    }

    /** حملة إعلانية فعلية للمستخدم مع إنفاق معلوم */
    private function addCampaign(int $userId, ?int $connectionId = null, float $spend = 100): int
    {
        $pdo = $this->db();
        $stmt = $pdo->prepare("INSERT INTO ad_campaigns
                    (user_id, platform_connection_id, name, objective, status, spend, currency)
                    VALUES (?, ?, ?, 'traffic', 'active', ?, 'USD')");
        $stmt->execute([$userId, $connectionId, 'Campaign ' . uniqid(), $spend]);
        return (int) $pdo->lastInsertId();
    }

    private function addUtmLink(int $campaignId, string $code = ''): int
    {
        $pdo = $this->db();
        $code = $code !== '' ? $code : bin2hex(random_bytes(5));
        $stmt = $pdo->prepare("INSERT INTO ad_utm_links (campaign_id, code, destination_url, utm_source, utm_campaign)
                               VALUES (?, ?, 'https://landing.example.com/promo', 'google', 'promo')");
        $stmt->execute([$campaignId, $code]);
        return (int) $pdo->lastInsertId();
    }

    private function addProduct(int $userId, float $price = 500, string $currency = 'USD'): int
    {
        $pdo = $this->db();
        $stmt = $pdo->prepare("INSERT INTO crm_products (user_id, name, price, currency, is_active)
                               VALUES (?, 'Nile Cruise', ?, ?, 1)");
        $stmt->execute([$userId, $price, $currency]);
        return (int) $pdo->lastInsertId();
    }

    private function addInventory(int $userId, int $productId, string $date): void
    {
        $pdo = $this->db();
        $stmt = $pdo->prepare("INSERT INTO inventory (user_id, product_id, date, capacity, booked_count)
                               VALUES (?, ?, ?, 10, 0)");
        $stmt->execute([$userId, $productId, $date]);
    }

    private function createBooking(
        int $userId,
        int $productId,
        string $date,
        string $source = 'website',
        ?int $attributedUtmLinkId = null,
        ?string $email = 'customer@example.com',
        ?string $phone = '+966500000001'
    ): array {
        $data = [
            'product_id' => $productId,
            'start_date' => $date,
            'customer_name' => 'CAPI Customer',
            'customer_email' => $email,
            'customer_phone' => $phone,
            'adults_count' => 2,
            'source' => $source,
        ];
        if ($attributedUtmLinkId !== null) {
            $data['attributed_utm_link_id'] = $attributedUtmLinkId;
        }

        return (new BookingEngine())->createBooking($userId, $data);
    }

    private function queuedConversionJobs(): array
    {
        $pdo = $this->db();
        return $pdo->query("SELECT * FROM jobs WHERE job_class = 'SendAdConversionJob' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }

    // ================================================================
    // تدفق الكوكي + resolveAndTrackClick
    // ================================================================

    public function testResolveAndTrackClickReturnsAttributionData(): void
    {
        $conn = $this->addPlatformConnection(self::USER_ID, 'google_ads');
        $campaign = $this->addCampaign(self::USER_ID, $conn);
        $linkId = $this->addUtmLink($campaign);

        $pdo = $this->db();
        $code = $pdo->query("SELECT code FROM ad_utm_links WHERE id = $linkId")->fetchColumn();

        $service = new AdTrackingService();
        $tracked = $service->resolveAndTrackClick((string) $code);

        $this->assertNotNull($tracked);
        $this->assertSame($linkId, $tracked['utm_link_id']);
        $this->assertSame('google_ads', $tracked['platform']);
        $this->assertStringStartsWith('https://landing.example.com/promo', $tracked['destination']);

        $clicks = (int) $pdo->query("SELECT clicks FROM ad_utm_links WHERE id = $linkId")->fetchColumn();
        $this->assertSame(1, $clicks);
    }

    public function testAttributionCookieRoundTripAndExpiry(): void
    {
        $service = new AdTrackingService();
        $service->storeAttribution(42, 'meta_ads');
        $this->assertArrayHasKey(AdTrackingService::ATTRIBUTION_COOKIE, $_COOKIE);

        $read = $service->readAttribution();
        $this->assertNotNull($read);
        $this->assertSame(42, $read['utm_link_id']);
        $this->assertSame('meta_ads', $read['platform']);

        $service->clearAttribution();
        $this->assertNull($service->readAttribution());

        // نافذة 30 يوم: إسناد قديم منتهي بيتجاهل
        $_COOKIE[AdTrackingService::ATTRIBUTION_COOKIE] = json_encode([
            'utm_link_id' => 7, 'platform' => 'google_ads', 'ts' => time() - AdTrackingService::ATTRIBUTION_WINDOW - 60,
        ]);
        $this->assertNull($service->readAttribution());
    }

    // ================================================================
    // إسناد الحجز + منع التلاعب
    // ================================================================

    public function testBookingWithAttributionPersistsUtmLinkAndSource(): void
    {
        $pdo = $this->db();
        $conn = $this->addPlatformConnection(self::USER_ID, 'meta_ads');
        $campaign = $this->addCampaign(self::USER_ID, $conn);
        $linkId = $this->addUtmLink($campaign);
        $productId = $this->addProduct(self::USER_ID);
        $date = date('Y-m-d', strtotime('+30 days'));
        $this->addInventory(self::USER_ID, $productId, $date);

        $booking = $this->createBooking(self::USER_ID, $productId, $date, 'ad:meta', $linkId);
        $id = (int) $booking['id'];

        $row = $pdo->query("SELECT source, attributed_utm_link_id FROM bookings WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('ad:meta', $row['source']);
        $this->assertSame($linkId, (int) $row['attributed_utm_link_id']);
    }

    public function testBookingWithoutAttributionKeepsNormalSource(): void
    {
        $pdo = $this->db();
        $productId = $this->addProduct(self::USER_ID);
        $date = date('Y-m-d', strtotime('+30 days'));
        $this->addInventory(self::USER_ID, $productId, $date);

        $booking = $this->createBooking(self::USER_ID, $productId, $date, 'website');
        $id = (int) $booking['id'];

        $row = $pdo->query("SELECT source, attributed_utm_link_id FROM bookings WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('website', $row['source']);
        $this->assertNull($row['attributed_utm_link_id']);
    }

    public function testAttributionFromForeignCampaignIsIgnored(): void
    {
        $pdo = $this->db();
        // رابط UTM تابع لحساب تاني تمامًا
        $conn = $this->addPlatformConnection(self::OTHER_USER_ID, 'meta_ads');
        $campaign = $this->addCampaign(self::OTHER_USER_ID, $conn);
        $foreignLinkId = $this->addUtmLink($campaign);

        $productId = $this->addProduct(self::USER_ID);
        $date = date('Y-m-d', strtotime('+30 days'));
        $this->addInventory(self::USER_ID, $productId, $date);

        $booking = $this->createBooking(self::USER_ID, $productId, $date, 'ad:meta', $foreignLinkId);
        $id = (int) $booking['id'];

        $row = $pdo->query("SELECT attributed_utm_link_id FROM bookings WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
        $this->assertNull($row['attributed_utm_link_id'], 'إسناد من حملة حساب تاني لازم يتتجاهل بصمت');
    }

    // ================================================================
    // CAPI dispatch عند التأكيد
    // ================================================================

    public function testConfirmAttributedBookingDispatchesCapiJob(): void
    {
        $conn = $this->addPlatformConnection(self::USER_ID, 'meta_ads');
        $campaign = $this->addCampaign(self::USER_ID, $conn);
        $linkId = $this->addUtmLink($campaign);
        $productId = $this->addProduct(self::USER_ID);
        $date = date('Y-m-d', strtotime('+30 days'));
        $this->addInventory(self::USER_ID, $productId, $date);

        $booking = $this->createBooking(self::USER_ID, $productId, $date, 'ad:meta', $linkId);
        $engine = new BookingEngine();
        $engine->confirmBooking(self::USER_ID, (int) $booking['id']);

        $jobs = $this->queuedConversionJobs();
        $this->assertCount(1, $jobs);
        $this->assertSame('SendAdConversionJob', $jobs[0]['job_class']);
        $payload = json_decode($jobs[0]['payload'], true);
        $this->assertSame((int) $booking['id'], $payload['booking_id']);
        $this->assertSame('pending', $jobs[0]['status']);
    }

    public function testConfirmNonAttributedBookingDoesNotDispatch(): void
    {
        $productId = $this->addProduct(self::USER_ID);
        $date = date('Y-m-d', strtotime('+30 days'));
        $this->addInventory(self::USER_ID, $productId, $date);

        $booking = $this->createBooking(self::USER_ID, $productId, $date, 'website');
        (new BookingEngine())->confirmBooking(self::USER_ID, (int) $booking['id']);

        $this->assertSame([], $this->queuedConversionJobs());
    }

    public function testConfirmFromPaymentDispatchesCapiJob(): void
    {
        $conn = $this->addPlatformConnection(self::USER_ID, 'google_ads');
        $campaign = $this->addCampaign(self::USER_ID, $conn);
        $linkId = $this->addUtmLink($campaign);
        $productId = $this->addProduct(self::USER_ID);
        $date = date('Y-m-d', strtotime('+30 days'));
        $this->addInventory(self::USER_ID, $productId, $date);

        $booking = $this->createBooking(self::USER_ID, $productId, $date, 'ad:google', $linkId);
        (new BookingEngine())->confirmBookingFromPayment((int) $booking['id']);

        $jobs = $this->queuedConversionJobs();
        $this->assertCount(1, $jobs);
        $this->assertSame('SendAdConversionJob', $jobs[0]['job_class']);
    }

    // ================================================================
    // ROAS من الحجوزات المرتبطة
    // ================================================================

    public function testCalculateRoasUsesAttributedConfirmedBookings(): void
    {
        $pdo = $this->db();
        $campaign = $this->addCampaign(self::USER_ID, null, 100.00);
        $linkA = $this->addUtmLink($campaign);
        $linkB = $this->addUtmLink($campaign);
        $productId = $this->addProduct(self::USER_ID, 250);
        $date = date('Y-m-d', strtotime('+30 days'));
        $this->addInventory(self::USER_ID, $productId, $date);

        $b1 = $this->createBooking(self::USER_ID, $productId, $date, 'ad', $linkA); // confirmed, 2 adults * 250 = 500
        $b2 = $this->createBooking(self::USER_ID, $productId, $date, 'ad', $linkB); // cancelled => excluded
        $b3 = $this->createBooking(self::USER_ID, $productId, $date, 'ad', $linkB); // confirmed

        $engine = new BookingEngine();
        $engine->confirmBooking(self::USER_ID, (int) $b1['id']);
        $engine->confirmBooking(self::USER_ID, (int) $b3['id']);
        $engine->cancelBooking(self::USER_ID, (int) $b2['id'], 'test');

        // حجز بدون إسناد ومؤكد - مش لازم يظهر في ROAS
        $b4 = $this->createBooking(self::USER_ID, $productId, $date, 'website');
        $engine->confirmBooking(self::USER_ID, (int) $b4['id']);

        $roas = (new AdReportService())->calculateRoas(self::USER_ID);

        $this->assertCount(1, $roas, 'حملة واحدة بس ليها حجوزات مئسندة مؤكدة');
        $entry = $roas[0];
        $this->assertSame($campaign, $entry['campaign_id']);
        $this->assertSame(2, $entry['attributed_bookings']); // b1 + b3 (b2 ملغي مش محسوب)
        $this->assertSame(1000.0, $entry['attributed_revenue']); // 500 + 500
        $this->assertSame(100.0, $entry['spend']);
        $this->assertSame(10.0, $entry['roas']); // 1000 / 100
    }

    // ================================================================
    // AdPiiHasher + حمولة CAPI
    // ================================================================

    public function testAdPiiHasherProducesSha256Only(): void
    {
        $emailHash = AdPiiHasher::hashEmail(' Customer@Example.COM ');
        $this->assertSame(hash('sha256', 'customer@example.com'), $emailHash);
        $this->assertSame(64, strlen((string) $emailHash));

        $phoneHash = AdPiiHasher::hashPhone('+966 50 000 0001');
        $this->assertSame(hash('sha256', '966500000001'), $phoneHash);

        $this->assertNull(AdPiiHasher::hashEmail(''));
        $this->assertNull(AdPiiHasher::hashEmail('not-an-email'));
        $this->assertNull(AdPiiHasher::hashPhone(''));
        $this->assertNull(AdPiiHasher::hashPhone('!!!'));
        $this->assertNull(AdPiiHasher::hashEmail(null));
    }

    public function testMetaCapiPayloadContainsHashesNotRawPii(): void
    {
        $api = $this->getMockBuilder(MetaAdsAPI::class)
            ->setConstructorArgs(['tok'])
            ->onlyMethods(['post'])
            ->getMock();

        $captured = null;
        $api->method('post')->willReturnCallback(function (string $path, array $fields = []) use (&$captured) {
            $captured = ['path' => $path, 'fields' => $fields];
            return ['success' => true, 'data' => ['events_received' => 1]];
        });

        $result = $api->sendConversionEvent('PIXEL_123', [
            'event_id' => 'BK-TEST123',
            'value' => 500.0,
            'currency' => 'USD',
            'email_hash' => AdPiiHasher::hashEmail('customer@example.com'),
            'phone_hash' => AdPiiHasher::hashPhone('+966500000001'),
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('PIXEL_123/events', $captured['path']);

        $events = json_decode($captured['fields']['data'], true);
        $event = $events[0];
        $this->assertSame('Purchase', $event['event_name']);
        $this->assertSame('BK-TEST123', $event['event_id']);
        $this->assertSame('website', $event['action_source']);
        $this->assertEquals(500.0, $event['custom_data']['value']);
        $this->assertSame('USD', $event['custom_data']['currency']);
        $this->assertSame([AdPiiHasher::hashEmail('customer@example.com')], $event['user_data']['em']);
        $this->assertSame([AdPiiHasher::hashPhone('+966500000001')], $event['user_data']['ph']);

        $raw = json_encode($event);
        $this->assertStringNotContainsString('customer@example.com', $raw, 'الإيميل الخام ممنوع يتساب في الحمولة');
        $this->assertStringNotContainsString('966500000001', $raw, 'الهاتف الخام ممنوع يتساب في الحمولة');
    }

    public function testSendAdConversionJobSendsHashedEventForMeta(): void
    {
        putenv('META_CAPI_PIXEL_ID=PIXEL_TEST');
        putenv('META_CAPI_ACCESS_TOKEN=capitoken-test');

        $conn = $this->addPlatformConnection(self::USER_ID, 'meta_ads');
        $campaign = $this->addCampaign(self::USER_ID, $conn);
        $linkId = $this->addUtmLink($campaign);
        $productId = $this->addProduct(self::USER_ID);
        $date = date('Y-m-d', strtotime('+30 days'));
        $this->addInventory(self::USER_ID, $productId, $date);

        $booking = $this->createBooking(self::USER_ID, $productId, $date, 'ad:meta', $linkId);
        $engine = new BookingEngine();
        $engine->confirmBooking(self::USER_ID, (int) $booking['id']);

        $jobs = $this->queuedConversionJobs();
        $this->assertCount(1, $jobs);

        // تنفيذ الـ job فعليًا بالفيك API
        $job = new FakeSendAdConversionJob();
        $job->handle(json_decode($jobs[0]['payload'], true));

        $this->assertNotNull($job->api, 'الـ job لازم يستدعي API المنصة');
        $event = $job->api->lastEvent;
        $this->assertNotNull($event);
        $this->assertSame('PIXEL_TEST', $event['pixel_id']);
        $this->assertSame((string) $booking['booking_reference'], $event['conversion']['event_id']);
        $this->assertSame(AdPiiHasher::hashEmail('customer@example.com'), $event['conversion']['email_hash']);
        $this->assertSame(AdPiiHasher::hashPhone('+966500000001'), $event['conversion']['phone_hash']);

        putenv('META_CAPI_PIXEL_ID');
        putenv('META_CAPI_ACCESS_TOKEN');
    }

    public function testSendAdConversionJobSkipsWhenNoPii(): void
    {
        putenv('META_CAPI_PIXEL_ID=PIXEL_TEST');
        putenv('META_CAPI_ACCESS_TOKEN=capitoken-test');

        $conn = $this->addPlatformConnection(self::USER_ID, 'meta_ads');
        $campaign = $this->addCampaign(self::USER_ID, $conn);
        $linkId = $this->addUtmLink($campaign);
        $productId = $this->addProduct(self::USER_ID);
        $date = date('Y-m-d', strtotime('+30 days'));
        $this->addInventory(self::USER_ID, $productId, $date);

        // حجز من غير إيميل ولا هاتف - مفيش PII للتطبيع
        $booking = $this->createBooking(self::USER_ID, $productId, $date, 'ad:meta', $linkId, null, null);
        $engine = new BookingEngine();
        $engine->confirmBooking(self::USER_ID, (int) $booking['id']);

        $jobs = $this->queuedConversionJobs();
        $this->assertCount(1, $jobs);

        $job = new FakeSendAdConversionJob();
        $job->handle(json_decode($jobs[0]['payload'], true));

        $this->assertNull($job->api, 'من غير PII مينفعش يتبعت حدث أصلاً');

        putenv('META_CAPI_PIXEL_ID');
        putenv('META_CAPI_ACCESS_TOKEN');
    }

    public function testSendAdConversionJobSkipsNonAttributedOrNotConfirmed(): void
    {
        putenv('META_CAPI_PIXEL_ID=PIXEL_TEST');
        putenv('META_CAPI_ACCESS_TOKEN=capitoken-test');

        $conn = $this->addPlatformConnection(self::USER_ID, 'meta_ads');
        $campaign = $this->addCampaign(self::USER_ID, $conn);
        $linkId = $this->addUtmLink($campaign);
        $productId = $this->addProduct(self::USER_ID);
        $date = date('Y-m-d', strtotime('+30 days'));
        $this->addInventory(self::USER_ID, $productId, $date);

        // لسه pending (مش confirmed) رغم الإسناد - الـ job ما يبعتهاش
        $booking = $this->createBooking(self::USER_ID, $productId, $date, 'ad:meta', $linkId);

        $job = new FakeSendAdConversionJob();
        $job->handle(['booking_id' => (int) $booking['id']]);
        $this->assertNull($job->api, 'حجز pending مش confirmed لازم ما يبعتش حدث');

        putenv('META_CAPI_PIXEL_ID');
        putenv('META_CAPI_ACCESS_TOKEN');
    }
}

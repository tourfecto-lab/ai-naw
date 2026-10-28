<?php

/**
 * Tourfecto - OTA Module Integration Test (Item 1)
 * @version 1.0.0  @date 2026-08-31
 *
 * يغطي:
 *   أ) GetYourGuideAPI و ViatorAPI: نجاح الاتصال، مفتاح غير صالح،
 *      فشل الشبكة، وبيانات حجز مشوّهة من الطرف الخارجي — كلها عبر
 *      transport وهمي (fakes) — بلا أي استدعاء HTTP حقيقي إطلاقًا.
 *   ب) التعامل الآمن مع أخطاء الـ API الخارجي: لا استثناءات تُرمى
 *      أبدًا، والنتيجة دايمًا envelope موحّد، والأخطاء بتتسجّل في Logger.
 *   ج) ربط حجوزات OTA الناجحة بنفس مسار rev_revenue_records
 *      (OtaBookingService): إدراج إيراد، idempotency، استرداد سالب،
 *      وظهورها في RevenueOverviewService.
 *
 * يتحقق من الجداول المطلوبة (users/rev_revenue_records) ويتخطى تلقائيًا
 * لو DB غير متاحة.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/OTA/GetYourGuideAPI.php';
require_once __DIR__ . '/../../app/Services/OTA/ViatorAPI.php';
require_once __DIR__ . '/../../app/Services/OTA/OtaBookingService.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/RevenueDataGateway.php';
require_once __DIR__ . '/../../app/Services/RevenueIntelligence/RevenueOverviewService.php';

/** Transport وهمي بيعيد استجابات جاهزة من غير أي اتصال شبكة */
class FakeOtaTransport
{
    public array $calls = [];
    private string $body;
    private int $httpCode;
    private ?string $curlError;

    public function __construct(string $body, int $httpCode = 200, ?string $curlError = null)
    {
        $this->body = $body;
        $this->httpCode = $httpCode;
        $this->curlError = $curlError;
    }

    public function __invoke(string $method, string $url, array $headers, ?string $body): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        return ['response' => $this->body, 'http_code' => $this->httpCode, 'error' => $this->curlError];
    }
}

final class OtaModuleIntegrationTest extends TestCase
{
    private const TEST_USER_ID = 999500;

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

            $db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $conn = $ref->getValue($db);
            if (!$conn instanceof PDO) {
                return null;
            }

            foreach (['users', 'rev_revenue_records'] as $table) {
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
            $this->markTestSkipped('DB غير متاحة أو جداول الإيراد غير مثبتة');
        }

        $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                    VALUES (" . self::TEST_USER_ID . ", 'ota-test@tourfecto.test', 'x', 'OTA Test Travel', NOW())
                    ON DUPLICATE KEY UPDATE email = email");
        $pdo->exec("DELETE FROM rev_revenue_records WHERE user_id = " . self::TEST_USER_ID);
    }

    protected function tearDown(): void
    {
        $pdo = self::$pdo;
        if ($pdo !== null) {
            $pdo->exec("DELETE FROM rev_revenue_records WHERE user_id = " . self::TEST_USER_ID);
        }
    }

    /** إعادة توقيع سجلات الإيراد لساعة مضت — نفس الأسلوب في BookingRevenueIntegrationTest */
    private function backdateRevenueRecords(): void
    {
        self::$pdo->exec(
            "UPDATE rev_revenue_records SET recorded_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE user_id = " . self::TEST_USER_ID
        );
    }

    // ============================================================
    // أ) GetYourGuideAPI
    // ============================================================

    public function testGetYourGuideVerifyTokenSuccess(): void
    {
        $transport = new FakeOtaTransport(json_encode(['tours' => []]));
        $client = new GetYourGuideAPI('good-token', $transport);

        $res = $client->verifyToken();

        $this->assertTrue($res['success']);
        $this->assertCount(1, $transport->calls);
        $this->assertSame('GET', $transport->calls[0]['method']);
        $this->assertStringContainsString('/tours', $transport->calls[0]['url']);
        $this->assertStringContainsString('X-ACCESS-TOKEN: good-token', implode("\n", $transport->calls[0]['headers']));
    }

    public function testGetYourGuideVerifyTokenInvalidKey(): void
    {
        $transport = new FakeOtaTransport('{"error":"unauthorized"}', 401);
        $client = new GetYourGuideAPI('bad-token', $transport);

        $res = $client->verifyToken();

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('غير صحيح أو منتهي', (string) $res['error']);
    }

    public function testGetYourGuideVerifyTokenNetworkFailure(): void
    {
        $transport = new FakeOtaTransport('', 0, 'Failed to connect');
        $client = new GetYourGuideAPI('token', $transport);

        $res = $client->verifyToken();

        $this->assertFalse($res['success']);
        // verifyToken بترجّع بس success+error (بدون http_code) - الخطأ بتاع الشبكة متسجّل بأمان
        $this->assertNotEmpty($res['error']);
    }

    public function testGetYourGuideGetToursSuccessAndQuery(): void
    {
        $transport = new FakeOtaTransport(json_encode(['tours' => [['id' => 'T1', 'title' => 'Cairo Tour']]]));
        $client = new GetYourGuideAPI('token', $transport);

        $res = $client->getTours(3, 25);

        $this->assertTrue($res['success']);
        $this->assertSame('T1', $res['data']['tours'][0]['id']);
        $this->assertStringContainsString('page=3', $transport->calls[0]['url']);
        $this->assertStringContainsString('limit=25', $transport->calls[0]['url']);
    }

    public function testGetYourGuideGetBookingSuccess(): void
    {
        $booking = json_encode([
            'hash' => 'abc123',
            'status' => 'CONFIRMED',
            'total' => 250.0,
            'currency' => 'EUR',
            'customer' => ['name' => 'Test Customer'],
        ]);
        $transport = new FakeOtaTransport($booking);
        $client = new GetYourGuideAPI('token', $transport);

        $res = $client->getBooking('abc123');

        $this->assertTrue($res['success']);
        $this->assertSame('CONFIRMED', $res['data']['status']);
        $this->assertEquals(250, $res['data']['total']);
        $this->assertStringContainsString('bookings/abc123', $transport->calls[0]['url']);
    }

    public function testGetYourGuideMalformedBookingDataIsSafe(): void
    {
        // رد 200 بمحتوى غير JSON (بيانات مشوّهة من الطرف الخارجي)
        $transport = new FakeOtaTransport('<html>502 Bad Gateway</html>', 200);
        $client = new GetYourGuideAPI('token', $transport);

        $res = $client->getBooking('weird');

        // لازم مترميش استثناء - envelope موحّد على طول
        $this->assertTrue($res['success']);
        $this->assertSame(200, $res['http_code']);
        $this->assertNull($res['data']);
    }

    public function testGetYourGuideThirdPartyRejectSurfacesError(): void
    {
        $transport = new FakeOtaTransport(json_encode(['error' => 'rate_limited']), 429);
        $client = new GetYourGuideAPI('token', $transport);

        $res = $client->getBooking('abc123');

        $this->assertFalse($res['success']);
        $this->assertSame(429, $res['http_code']);
        $this->assertSame('HTTP 429', $res['error']);
    }

    // ============================================================
    // أ) ViatorAPI
    // ============================================================

    public function testViatorVerifyTokenSuccess(): void
    {
        $transport = new FakeOtaTransport(json_encode(['data' => [['id' => 1]]]));
        $client = new ViatorAPI('good-key', 'en-US', $transport);

        $res = $client->verifyToken();

        $this->assertTrue($res['success']);
        $this->assertStringContainsString('/destinations', $transport->calls[0]['url']);
        $this->assertStringContainsString('exp-api-key: good-key', implode("\n", $transport->calls[0]['headers']));
    }

    public function testViatorVerifyTokenInvalidKey401And403(): void
    {
        foreach ([401, 403] as $code) {
            $transport = new FakeOtaTransport('{"error":"denied"}', $code);
            $client = new ViatorAPI('bad-key', 'en-US', $transport);
            $res = $client->verifyToken();
            $this->assertFalse($res['success']);
            $this->assertStringContainsString('غير صحيح أو منتهي', (string) $res['error']);
        }
    }

    public function testViatorVerifyTokenNetworkFailure(): void
    {
        $transport = new FakeOtaTransport('', 0, 'Connection timed out');
        $client = new ViatorAPI('key', 'en-US', $transport);

        $res = $client->verifyToken();

        $this->assertFalse($res['success']);
        $this->assertNotEmpty($res['error']);
    }

    public function testViatorSearchProductsSendsFiltersAsJsonBody(): void
    {
        $transport = new FakeOtaTransport(json_encode(['results' => []]));
        $client = new ViatorAPI('key', 'en-US', $transport);

        $res = $client->searchProducts(['destination' => 123, 'pageSize' => 10]);

        $this->assertTrue($res['success']);
        $this->assertSame('POST', $transport->calls[0]['method']);
        $body = json_decode((string) $transport->calls[0]['body'], true);
        $this->assertSame(123, $body['destination']);
        $this->assertSame(10, $body['pageSize']);
    }

    public function testViatorGetBookingSuccess(): void
    {
        $transport = new FakeOtaTransport(json_encode([
            'booking_ref' => 'VI-777',
            'status' => 'CONFIRMED',
            'total' => ['amount' => 120.0, 'currency' => 'USD'],
        ]));
        $client = new ViatorAPI('key', 'en-US', $transport);

        $res = $client->getBooking('VI-777');

        $this->assertTrue($res['success']);
        $this->assertSame('CONFIRMED', $res['data']['status']);
        $this->assertStringContainsString('bookings/VI-777/status', $transport->calls[0]['url']);
    }

    public function testViatorMalformedBookingDataIsSafe(): void
    {
        // بيانات مشوّهة: استجابة 200 بجسم غير JSON
        $transport = new FakeOtaTransport('not-json-at-all', 200);
        $client = new ViatorAPI('key', 'en-US', $transport);

        $res = $client->getBooking('x');

        $this->assertTrue($res['success']);
        $this->assertNull($res['data']); // لا استثناء
    }

    // ============================================================
    // ج) ربط حجوزات OTA الناجحة بـ rev_revenue_records
    // ============================================================

    public function testOtaRevenueRecordInsertedWithCorrectFields(): void
    {
        $service = new OtaBookingService();
        $inserted = $service->recordBookingRevenue(
            self::TEST_USER_ID,
            'getyourguide',
            'GYG-HASH-001',
            250.00,
            'USD',
            'Cairo Nile Cruise',
            'tours'
        );

        $this->assertTrue($inserted);

        $row = self::$pdo->query(
            "SELECT * FROM rev_revenue_records WHERE user_id = " . self::TEST_USER_ID . " AND source = 'ota_booking'"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame('GYG-HASH-001', $row['reference_id']);
        $this->assertSame('250.00', $row['amount']);
        $this->assertSame('USD', $row['currency']);
        $this->assertSame('Cairo Nile Cruise', $row['product_name']);
        $this->assertSame('tours', $row['category']);
        $this->assertStringContainsString('getyourguide', (string) $row['notes']);
    }

    public function testOtaRevenueRecordIsIdempotent(): void
    {
        $service = new OtaBookingService();
        $first = $service->recordBookingRevenue(self::TEST_USER_ID, 'viator', 'VI-REF-1', 99.00, 'USD');
        $second = $service->recordBookingRevenue(self::TEST_USER_ID, 'viator', 'VI-REF-1', 99.00, 'USD');

        $this->assertTrue($first);
        $this->assertFalse($second, 'التكرار لازم يرجّع false ولا يضيف سجل');
        $count = self::$pdo->query(
            "SELECT COUNT(*) FROM rev_revenue_records WHERE user_id = " . self::TEST_USER_ID . " AND reference_id = 'VI-REF-1'"
        )->fetchColumn();
        $this->assertSame('1', (string) $count);
    }

    public function testOtaRevenueRejectsInvalidInput(): void
    {
        $service = new OtaBookingService();
        $this->assertFalse($service->recordBookingRevenue(self::TEST_USER_ID, 'viator', '   ', 50.0, 'USD'));
        $this->assertFalse($service->recordBookingRevenue(self::TEST_USER_ID, 'viator', 'REF', 0.0, 'USD'));
        $this->assertFalse($service->recordBookingRevenue(self::TEST_USER_ID, 'viator', 'REF', -5.0, 'USD'));

        $count = self::$pdo->query(
            "SELECT COUNT(*) FROM rev_revenue_records WHERE user_id = " . self::TEST_USER_ID
        )->fetchColumn();
        $this->assertSame('0', (string) $count);
    }

    public function testOtaRefundOnlyAfterPositiveRecordAndOnce(): void
    {
        $service = new OtaBookingService();

        // بدون إيراد موجب أولًا → مرفوض
        $this->assertFalse($service->recordBookingRefund(self::TEST_USER_ID, 'getyourguide', 'REF-REF', 50.0, 'USD'));

        $service->recordBookingRevenue(self::TEST_USER_ID, 'getyourguide', 'REF-REF', 200.00, 'USD');

        // استرداد بعد إيراد موجب → سجل سالب
        $this->assertTrue($service->recordBookingRefund(self::TEST_USER_ID, 'getyourguide', 'REF-REF', 50.0, 'USD'));
        // استرداد مكرر → مرفوض
        $this->assertFalse($service->recordBookingRefund(self::TEST_USER_ID, 'getyourguide', 'REF-REF', 50.0, 'USD'));

        $refund = self::$pdo->query(
            "SELECT amount FROM rev_revenue_records
             WHERE user_id = " . self::TEST_USER_ID . " AND source = 'ota_refund'"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('-50.00', $refund['amount']);
    }

    public function testOtaRevenueAppearsInRevenueOverview(): void
    {
        // سجل يدوي (مصدر الموديول الأصلي) + حجز OTA → التقرير يجمع الاتنين
        self::$pdo->exec(
            "INSERT INTO rev_revenue_records (user_id, source, reference_id, amount, currency, recorded_at)
             VALUES (" . self::TEST_USER_ID . ", 'manual', 'M-1', 100.00, 'USD', NOW())"
        );

        $service = new OtaBookingService();
        $service->recordBookingRevenue(self::TEST_USER_ID, 'viator', 'VI-REV-9', 300.00, 'USD');
        $this->backdateRevenueRecords();

        $overview = (new RevenueOverviewService())->getOverview(self::TEST_USER_ID, 'monthly');

        $this->assertSame(400.0, $overview['total_revenue']);
        $this->assertSame(2, $overview['revenue_records_count']);

        $bySourceIndexed = [];
        foreach ($overview['revenue_by_source'] as $item) {
            $bySourceIndexed[$item['source']] = $item;
        }
        $this->assertSame(100.0, $bySourceIndexed['manual']['total']);
        $this->assertSame(300.0, $bySourceIndexed['ota_booking']['total']);
    }

    public function testOtaRevenueIsolationBetweenUsers(): void
    {
        self::$pdo->exec("INSERT IGNORE INTO users (id, email, password, company_name, created_at)
                          VALUES (999501, 'ota-other@tourfecto.test', 'x', 'OTA Other', NOW())");

        $service = new OtaBookingService();
        $service->recordBookingRevenue(self::TEST_USER_ID, 'getyourguide', 'GYG-ISO-1', 500.00, 'USD');
        $service->recordBookingRevenue(999501, 'getyourguide', 'GYG-ISO-2', 9999.00, 'USD');

        $mine = self::$pdo->query(
            "SELECT COALESCE(SUM(amount),0) FROM rev_revenue_records WHERE user_id = " . self::TEST_USER_ID
        )->fetchColumn();
        $this->assertSame('500.00', (string) $mine);
    }
}

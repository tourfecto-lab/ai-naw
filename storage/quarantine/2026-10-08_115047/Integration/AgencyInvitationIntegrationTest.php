<?php

/**
 * Tourfecto - Agency Invitation & Dashboard Integration Test (White-Label)
 * بيفحص تدفّق دعوات العملاء بالرمز/الرابط ولوحة تحكم الوكيل:
 *   1) إنشاء دعوة: رمز فريد + idempotency + رفض بريد/نسبة غير صالحة.
 *   2) قبول الدعوة: يضيف العميل في agency_clients ويعلّم الدعوة accepted،
 *      ويرفض الرموز غير الصالحة/المنتهية/الملغاة/البريد غير المطابق،
 *      ويحترم حد مقاعد الوكالة.
 *   3) Endpoints الـ API: عزل صارم بين الوكلاء (404 لوكالة غير مملوكة).
 *   4) لوحة تحكم الوكيل: agencyStats + clientPerformance بتجمّع حجوزات
 *      وعمولات حقيقية داخل عزل agency_id.
 *
 * محتاج الميجريشن: database/migrations/2026_08_31_000002_agency_invitations.sql
 * يتخطى تلقائيًا (markTestSkipped) لو DB غير متاحة أو الجداول غير موجودة.
 * @version 1.0.0  @date 2026-08-31
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Core/Model.php';
require_once __DIR__ . '/../../app/Models/User.php';
require_once __DIR__ . '/../../app/Models/Booking.php';
require_once __DIR__ . '/../../app/Models/Agency.php';
require_once __DIR__ . '/../../app/Models/AgencyClient.php';
require_once __DIR__ . '/../../app/Models/AgencyBranding.php';
require_once __DIR__ . '/../../app/Models/AgencyCommission.php';
require_once __DIR__ . '/../../app/Models/AgencyInvitation.php';
require_once __DIR__ . '/../../app/Models/ActivityLog.php';
require_once __DIR__ . '/../../app/Models/Notification.php';
require_once __DIR__ . '/../../app/Services/BookingEngine.php';
require_once __DIR__ . '/../../app/Services/InventoryService.php';
require_once __DIR__ . '/../../app/Services/WhiteLabel/AgencyService.php';
require_once __DIR__ . '/../../app/Controllers/AgencyController.php';

final class AgencyInvitationIntegrationTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static bool $dbChecked = false;

    private const OWNER = 999110;
    private const CLIENT = 999111;
    private const CLIENT2 = 999112;
    private const OTHER_OWNER = 999113;
    private const OTHER_CLIENT = 999114;

    private const AGENCY = 999210;
    private const OTHER_AGENCY = 999211;

    private const PRODUCT_CLIENT = 999310;
    private const PRODUCT_CLIENT2 = 999311;
    private const PRODUCT_OTHER = 999312;

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

            $tables = $conn->query("SHOW TABLES LIKE 'agency_invitations'")->fetchAll();
            if (empty($tables)) {
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
            $this->markTestSkipped('DB غير متاحة أو ميجريشن agency_invitations مش متشغّل');
        }

        $this->cleanup();

        foreach ([
            [self::OWNER, 'owner@tourfecto.test', 'Agency Owner'],
            [self::CLIENT, 'client@tourfecto.test', 'Client One'],
            [self::CLIENT2, 'client2@tourfecto.test', 'Client Two'],
            [self::OTHER_OWNER, 'other-owner@tourfecto.test', 'Other Owner'],
            [self::OTHER_CLIENT, 'other-client@tourfecto.test', 'Other Client'],
        ] as [$id, $email, $name]) {
            $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                        VALUES ({$id}, '{$email}', 'x', '{$name}', NOW())
                        ON DUPLICATE KEY UPDATE email = email");
        }

        $pdo->exec("INSERT INTO agencies (id, owner_user_id, name, slug, status, plan_seats)
                    VALUES (999210, 999110, 'Agency Alpha', 'agency-inv-alpha', 'active', 2)
                    ON DUPLICATE KEY UPDATE owner_user_id = 999110");
        $pdo->exec("INSERT INTO agencies (id, owner_user_id, name, slug, status, plan_seats)
                    VALUES (999211, 999113, 'Agency Beta', 'agency-inv-beta', 'active', 5)
                    ON DUPLICATE KEY UPDATE owner_user_id = 999113");

        foreach ([
            [self::PRODUCT_CLIENT, self::CLIENT],
            [self::PRODUCT_CLIENT2, self::CLIENT2],
            [self::PRODUCT_OTHER, self::OTHER_CLIENT],
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
        unset($_SESSION['user'], $_POST['token'], $_POST['email']);
    }

    private function cleanup(): void
    {
        $pdo = self::$pdo;
        $pdo->exec('DELETE FROM agency_commissions WHERE agency_id IN (999210, 999211)');
        $pdo->exec('DELETE FROM agency_invitations WHERE agency_id IN (999210, 999211)');
        $pdo->exec('DELETE FROM booking_status_history WHERE booking_id IN (SELECT id FROM bookings WHERE user_id IN (999111, 999112, 999114))');
        $pdo->exec('DELETE FROM bookings WHERE user_id IN (999111, 999112, 999114)');
        $pdo->exec('DELETE FROM inventory WHERE product_id IN (999310, 999311, 999312)');
        $pdo->exec('DELETE FROM crm_deals WHERE owner_user_id IN (999110, 999111, 999112, 999113, 999114)');
        $pdo->exec('DELETE FROM agency_clients WHERE agency_id IN (999210, 999211)');
        $pdo->exec('DELETE FROM agency_branding WHERE agency_id IN (999210, 999211)');
        $pdo->exec('DELETE FROM agencies WHERE id IN (999210, 999211)');
        $pdo->exec('DELETE FROM crm_products WHERE id IN (999310, 999311, 999312)');
        $pdo->exec('DELETE FROM users WHERE id IN (999110, 999111, 999112, 999113, 999114)');
    }

    private function makeInvitation(int $agencyId = self::AGENCY, string $email = 'client@tourfecto.test', float $rate = 10.00): AgencyInvitation
    {
        return (new AgencyService())->createInvitation($agencyId, $email, $rate, self::OWNER);
    }

    private function addClientRow(int $agencyId, int $userId, float $rate = 10.00): void
    {
        self::$pdo->exec(
            "INSERT INTO agency_clients (agency_id, client_user_id, status, commission_rate)
             VALUES ({$agencyId}, {$userId}, 'active', {$rate})
             ON DUPLICATE KEY UPDATE status = 'active', commission_rate = {$rate}"
        );
    }

    private function createAndConfirmBooking(int $userId, int $productId, string $date, float $total): int
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
        $this->assertTrue($engine->confirmBooking($userId, (int) $result['id']), 'تأكيد الحجز لازم ينجح');
        return (int) $result['id'];
    }

    private function sessionAs(int $userId, string $role = 'agency_owner'): void
    {
        $_SESSION['user'] = ['id' => $userId, 'role' => $role, 'company_name' => 'Test'];
        // بعض اختبارات الـ bootstrap بتحط CONTENT_TYPE=application/json فتقرا
        // parseInput جسم فارغ من php://input بدل $_POST - نعيده للوضع العادي.
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
    }

    // ------------------------------------------------------------
    // 1) إنشاء الدعوة
    // ------------------------------------------------------------

    public function testCreateInvitationPersistsWithUniqueToken(): void
    {
        $inv = $this->makeInvitation();

        $this->assertTrue((int) $inv->getAttribute('id') > 0);
        $this->assertSame('pending', $inv->getAttribute('status'));
        $this->assertSame('client@tourfecto.test', $inv->getAttribute('email'));
        $this->assertSame(10.0, (float) $inv->getAttribute('commission_rate'));
        $this->assertNotEmpty($inv->getAttribute('token'));
        $this->assertTrue(strtotime((string) $inv->getAttribute('expires_at')) > time());

        $inv2 = $this->makeInvitation(self::AGENCY, 'client2@tourfecto.test');
        $this->assertNotSame($inv->getAttribute('token'), $inv2->getAttribute('token'));
    }

    public function testCreateInvitationIdempotentForSamePendingEmail(): void
    {
        $inv = $this->makeInvitation();
        $inv2 = $this->makeInvitation();

        $this->assertSame((int) $inv->getAttribute('id'), (int) $inv2->getAttribute('id'));
        $count = self::$pdo->query(
            "SELECT COUNT(*) FROM agency_invitations WHERE agency_id = 999210 AND email = 'client@tourfecto.test'"
        )->fetchColumn();
        $this->assertSame(1, (int) $count);
    }

    public function testCreateInvitationRejectsInvalidEmailOrRate(): void
    {
        $service = new AgencyService();

        try {
            $service->createInvitation(self::AGENCY, 'not-an-email', 10.00, self::OWNER);
            $this->fail('بريد غير صالح لازم يفشل');
        } catch (Exception $e) {
            $this->assertStringContainsString('بريد', $e->getMessage());
        }

        try {
            $service->createInvitation(self::AGENCY, 'client@tourfecto.test', 150.00, self::OWNER);
            $this->fail('نسبة عمولة فوق 100 لازم تفشل');
        } catch (Exception $e) {
            $this->assertStringContainsString('نسبة العمولة', $e->getMessage());
        }

        $this->assertSame(0, (int) self::$pdo->query(
            "SELECT COUNT(*) FROM agency_invitations WHERE agency_id = 999210"
        )->fetchColumn());
    }

    public function testCreateInvitationRejectsUnknownAgency(): void
    {
        $this->expectException(Exception::class);
        $this->makeInvitation(999999, 'client@tourfecto.test');
    }

    // ------------------------------------------------------------
    // 2) قبول الدعوة
    // ------------------------------------------------------------

    public function testAcceptInvitationCreatesClientAndMarksAccepted(): void
    {
        $inv = $this->makeInvitation();
        $token = (string) $inv->getAttribute('token');

        $service = new AgencyService();
        $accepted = $service->acceptInvitation(self::CLIENT, $token);

        $this->assertSame('accepted', $accepted->getAttribute('status'));
        $this->assertNotEmpty($accepted->getAttribute('accepted_at'));

        $clientRows = (new AgencyClient())->where(['agency_id' => self::AGENCY, 'client_user_id' => self::CLIENT]);
        $this->assertCount(1, $clientRows);
        $this->assertSame('active', $clientRows[0]->getAttribute('status'));
        $this->assertSame('10.00', (string) $clientRows[0]->getAttribute('commission_rate'));

        $logs = (new ActivityLog())->where(['subject_type' => 'agency_invitations', 'subject_id' => (int) $inv->getAttribute('id')]);
        $this->assertNotEmpty($logs);
    }

    public function testAcceptInvitationUsesInviteCommissionRate(): void
    {
        $inv = $this->makeInvitation(self::AGENCY, 'client@tourfecto.test', 22.50);

        (new AgencyService())->acceptInvitation(self::CLIENT, (string) $inv->getAttribute('token'));

        $rate = self::$pdo->query(
            "SELECT commission_rate FROM agency_clients WHERE agency_id = 999210 AND client_user_id = 999111"
        )->fetchColumn();
        $this->assertSame('22.50', (string) $rate);
    }

    public function testAcceptInvitationWrongEmailRejected(): void
    {
        $inv = $this->makeInvitation();
        $service = new AgencyService();

        try {
            $service->acceptInvitation(self::CLIENT2, (string) $inv->getAttribute('token'));
            $this->fail('بريد مختلف لازم يرفض القبول');
        } catch (Exception $e) {
            $this->assertStringContainsString('بريد إلكتروني آخر', $e->getMessage());
        }

        $this->assertCount(0, (new AgencyClient())->where(['agency_id' => self::AGENCY, 'client_user_id' => self::CLIENT2]));
    }

    public function testAcceptInvitationRevokedRejected(): void
    {
        $inv = $this->makeInvitation();
        $service = new AgencyService();
        $service->revokeInvitation((int) $inv->getAttribute('id'));

        $this->expectException(Exception::class);
        $service->acceptInvitation(self::CLIENT, (string) $inv->getAttribute('token'));
    }

    public function testAcceptInvitationExpiredRejected(): void
    {
        $inv = $this->makeInvitation();
        self::$pdo->exec(
            "UPDATE agency_invitations SET expires_at = '2020-01-01 00:00:00' WHERE id = " . (int) $inv->getAttribute('id')
        );

        $this->expectException(Exception::class);
        (new AgencyService())->acceptInvitation(self::CLIENT, (string) $inv->getAttribute('token'));
    }

    public function testAcceptInvitationInvalidTokenThrows(): void
    {
        $this->expectException(Exception::class);
        (new AgencyService())->acceptInvitation(self::CLIENT, 'nonexistent-token-123');
    }

    public function testAcceptInvitationRespectsSeatLimit(): void
    {
        $this->addClientRow(self::AGENCY, self::CLIENT, 10.00);
        $this->addClientRow(self::AGENCY, self::CLIENT2, 10.00); // plan_seats = 2

        $inv = $this->makeInvitation(self::AGENCY, 'other-client@tourfecto.test');

        $this->expectException(Exception::class);
        (new AgencyService())->acceptInvitation(self::OTHER_CLIENT, (string) $inv->getAttribute('token'));
    }

    public function testAcceptInvitationAlreadyActiveClientIsIdempotent(): void
    {
        $this->addClientRow(self::AGENCY, self::CLIENT, 10.00);
        $inv = $this->makeInvitation();

        $accepted = (new AgencyService())->acceptInvitation(self::CLIENT, (string) $inv->getAttribute('token'));
        $this->assertSame('accepted', $accepted->getAttribute('status'));
        $this->assertCount(1, (new AgencyClient())->where(['agency_id' => self::AGENCY, 'client_user_id' => self::CLIENT]));
    }

    // ------------------------------------------------------------
    // 3) Endpoints الـ API
    // ------------------------------------------------------------

    public function testCreateInvitationEndpointOwnershipRequired(): void
    {
        $this->sessionAs(self::OTHER_OWNER);
        $_POST['email'] = 'client@tourfecto.test';
        $ctrl = new AgencyController();
        $this->assertSame(404, $ctrl->createInvitation(['id' => self::AGENCY])['code']);
    }

    public function testCreateInvitationEndpointRejectsUnknownEmail(): void
    {
        $this->sessionAs(self::OWNER);
        $_POST['email'] = 'ghost@tourfecto.test';
        $ctrl = new AgencyController();
        $res = $ctrl->createInvitation(['id' => self::AGENCY]);
        $this->assertSame(404, $res['code']);
    }

    public function testCreateInvitationEndpointRejectsExistingClient(): void
    {
        $this->addClientRow(self::AGENCY, self::CLIENT, 10.00);
        $this->sessionAs(self::OWNER);
        $_POST['email'] = 'client@tourfecto.test';
        $ctrl = new AgencyController();
        $res = $ctrl->createInvitation(['id' => self::AGENCY]);
        $this->assertSame(422, $res['code']);
    }

    public function testListInvitationsEndpointIsolation(): void
    {
        $this->makeInvitation(self::AGENCY, 'client@tourfecto.test');

        $this->sessionAs(self::OWNER);
        $res = (new AgencyController())->listInvitations(['id' => self::AGENCY]);
        $this->assertTrue($res['success']);
        $this->assertCount(1, $res['data']['invitations']);

        $this->sessionAs(self::OTHER_OWNER);
        $this->assertSame(404, (new AgencyController())->listInvitations(['id' => self::AGENCY])['code']);
    }

    public function testRevokeInvitationEndpointThenAcceptFails(): void
    {
        $inv = $this->makeInvitation();
        $token = (string) $inv->getAttribute('token');

        $this->sessionAs(self::OWNER);
        $ctrl = new AgencyController();
        $res = $ctrl->revokeInvitation(['id' => self::AGENCY, 'inviteId' => (int) $inv->getAttribute('id')]);
        $this->assertTrue($res['success']);

        // cross-agency revoke -> 404
        $this->sessionAs(self::OTHER_OWNER);
        $this->assertSame(404, $ctrl->revokeInvitation(['id' => self::OTHER_AGENCY, 'inviteId' => (int) $inv->getAttribute('id')])['code']);

        $this->expectException(Exception::class);
        (new AgencyService())->acceptInvitation(self::CLIENT, $token);
    }

    public function testAcceptInvitationEndpointReturnsInvitationWithoutToken(): void
    {
        $inv = $this->makeInvitation();
        $this->sessionAs(self::CLIENT, 'customer');

        $_POST['token'] = (string) $inv->getAttribute('token');
        $res = (new AgencyController())->acceptInvitation();

        $this->assertTrue($res['success']);
        $this->assertSame('accepted', $res['data']['invitation']['status']);
        $this->assertArrayNotHasKey('token', $res['data']['invitation']);

        $this->sessionAs(self::CLIENT2, 'customer');
        $_POST['token'] = 'bad-token';
        $res2 = (new AgencyController())->acceptInvitation();
        $this->assertSame(422, $res2['code']);
    }

    // ------------------------------------------------------------
    // 4) لوحة تحكم الوكيل
    // ------------------------------------------------------------

    public function testAgencyDashboardAggregatesStatsAndClientPerformance(): void
    {
        $this->addClientRow(self::AGENCY, self::CLIENT, 15.00);
        $this->addClientRow(self::AGENCY, self::CLIENT2, 10.00);
        $this->createAndConfirmBooking(self::CLIENT, self::PRODUCT_CLIENT, '2026-12-20', 1000.00);
        $this->createAndConfirmBooking(self::CLIENT, self::PRODUCT_CLIENT, '2026-12-21', 500.00);

        $this->sessionAs(self::OWNER);
        $res = (new AgencyController())->agencyDashboard(['id' => self::AGENCY]);
        $this->assertTrue($res['success']);

        $stats = $res['data']['stats'];
        $this->assertSame(2, $stats['clients']['active']);
        $this->assertSame(2, $stats['bookings']['confirmed_count']);
        $this->assertSame(1500.00, (float) $stats['bookings']['total_revenue']);

        // 1000×15% + 500×15% = 225
        $this->assertSame(225.00, (float) $stats['commissions']['pending_total']);
        $this->assertSame(0.0, (float) $stats['commissions']['paid_total']);
        $this->assertSame(2, $stats['commissions']['pending_count']);
        $this->assertCount(2, $stats['recent_commissions']);

        $perf = $res['data']['clients_performance'];
        $this->assertCount(2, $perf);
        $top = $perf[0];
        $this->assertSame('Client One', $top['company_name']);
        $this->assertSame(2, $top['bookings_count']);
        $this->assertSame(1500.00, (float) $top['revenue']);
        $this->assertSame(225.00, (float) $top['commission_pending_total']);
        $this->assertSame(15.00, (float) $top['commission_rate']);

        // عميل تاني بدون حجوزات = أصفار
        $this->assertSame(0, $perf[1]['bookings_count']);
        $this->assertSame(0.0, (float) $perf[1]['revenue']);

        // عزل صارم
        $this->sessionAs(self::OTHER_OWNER);
        $this->assertSame(404, (new AgencyController())->agencyDashboard(['id' => self::AGENCY])['code']);
    }

    public function testAgencyDashboardPendingInviteCount(): void
    {
        $this->makeInvitation(self::AGENCY, 'client@tourfecto.test');
        $this->makeInvitation(self::AGENCY, 'other-client@tourfecto.test');

        $this->sessionAs(self::OWNER);
        $res = (new AgencyController())->agencyDashboard(['id' => self::AGENCY]);
        $this->assertTrue($res['success']);
        $this->assertSame(2, $res['data']['stats']['pending_invites']);
    }
}

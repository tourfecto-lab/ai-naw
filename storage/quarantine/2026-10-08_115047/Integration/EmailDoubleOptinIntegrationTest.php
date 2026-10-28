<?php

/**
 * Tourfecto - Email Double Opt-In Integration Test (بند 2)
 * بيتخطى تلقائيًا (markTestSkipped) لو DB غير متاحة أو ميجريشن الـ opt-in
 * لسه ما اتشغّلش: database/migrations/2026_09_01_000002_email_double_optin.sql
 *
 * بيفحص:
 *   1) الاشتراك العام (require_optin) => حالة pending_optin + توكن تأكيد +
 *      محاولة إرسال بريد تأكيد (best-effort)
 *   2) إدخال/استيراد الأدمن (بدون require_optin) => subscribed فورًا بلا توكن
 *   3) تفعيل التوكن => subscribed + تسجيل optin_ip/optin_at + مسح التوكن
 *   4) توكن غير صالح/منتهٍ => رفض بلا تغيير
 *   5) استعلامات الجمهور (audience + evaluateSegment) تستثني pending_optin
 * @version 1.0.0  @date 2026-09-01
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Models/EmailSubscriber.php';
require_once __DIR__ . '/../../app/Models/EmailList.php';
require_once __DIR__ . '/../../app/Models/EmailCampaign.php';
require_once __DIR__ . '/../../app/Services/EmailMarketing/EmailListService.php';
require_once __DIR__ . '/../../app/Services/EmailMarketing/EmailCampaignService.php';
require_once __DIR__ . '/../../app/Services/EmailMarketing/ContactManagementService.php';

final class EmailDoubleOptinIntegrationTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static bool $dbChecked = false;
    private static int $userId = 0;

    private function db(): ?PDO
    {
        if (self::$dbChecked) {
            return self::$pdo;
        }
        self::$dbChecked = true;

        try {
            $app = dirname(__DIR__, 2) . '/app';
            if (!defined('APP_ENV')) {
                foreach ([$app . '/Config/app.php', $app . '/Config/database.php'] as $cfg) {
                    if (file_exists($cfg)) {
                        require_once $cfg;
                    }
                }
            }
            foreach ([
                'Database' => '/Core/Database.php',
                'Logger' => '/Core/Logger.php',
                'Model' => '/Core/Model.php',
                'EmailListService' => '/Services/EmailMarketing/EmailListService.php',
            ] as $class => $relPath) {
                if (!class_exists($class) && file_exists($app . $relPath)) {
                    require_once $app . $relPath;
                }
            }

            $db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $conn = $ref->getValue($db);
            if (!$conn instanceof PDO) {
                self::$pdo = null;
                return null;
            }

            $cols = $conn->query("SHOW COLUMNS FROM email_subscribers LIKE 'optin_token'")->fetchAll();
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
            $this->markTestSkipped('DB غير متاحة أو ميجريشن double opt-in لسه ما اتشغّلش');
        }
        if (self::$userId === 0) {
            self::$userId = createTestUser();
        }
    }

    protected function tearDown(): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return;
        }
        $uid = (int) self::$userId;
        $pdo->exec("DELETE FROM email_campaign_recipients WHERE campaign_id IN (SELECT id FROM email_campaigns WHERE user_id = {$uid})");
        $pdo->exec("DELETE FROM email_campaigns WHERE user_id = {$uid}");
        $pdo->exec("DELETE FROM email_segments WHERE user_id = {$uid}");
        $pdo->exec("DELETE FROM email_list_subscriber WHERE subscriber_id IN (SELECT id FROM email_subscribers WHERE user_id = {$uid})");
        $pdo->exec("DELETE FROM email_subscribers WHERE user_id = {$uid}");
        $pdo->exec("DELETE FROM email_lists WHERE user_id = {$uid}");
    }

    private function row(string $email): array
    {
        $rows = self::$pdo->query(
            "SELECT * FROM email_subscribers WHERE user_id = " . (int) self::$userId . " AND email = " . self::$pdo->quote($email)
        )->fetchAll(PDO::FETCH_ASSOC);
        return $rows[0] ?? [];
    }

    public function testPublicSubscribeStartsPendingOptinWithToken(): void
    {
        $service = new EmailListService();
        $listId = $service->createList(self::$userId, 'نشرة عامة')['id'];

        $result = $service->subscribe(self::$userId, 'optin@example.com', ['name' => 'سارة', 'source' => 'form', 'require_optin' => true], $listId);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['pending_optin']);
        $this->assertSame('pending_optin', $result['status']);

        $row = $this->row('optin@example.com');
        $this->assertSame('pending_optin', $row['status']);
        $this->assertNotEmpty($row['optin_token'], 'يجب توليد توكن تأكيد');
        $this->assertSame('form', $row['source']);
    }

    public function testAdminOrImportSubscribeStaysSubscribedImmediately(): void
    {
        $service = new EmailListService();

        // إدخال يدوي من الأدمن
        $manual = $service->subscribe(self::$userId, 'manual@example.com', ['name' => 'أحمد', 'source' => 'manual']);
        $this->assertTrue($manual['success']);
        $this->assertFalse($manual['pending_optin']);
        $this->assertSame('subscribed', $manual['status']);

        // استيراد
        $import = $service->subscribe(self::$userId, 'import@example.com', ['source' => 'import']);
        $this->assertFalse($import['pending_optin']);

        $m = $this->row('manual@example.com');
        $i = $this->row('import@example.com');
        $this->assertSame('subscribed', $m['status']);
        $this->assertNull($m['optin_token']);
        $this->assertSame('subscribed', $i['status']);
        $this->assertNull($i['optin_token']);
    }

    public function testConfirmOptinActivatesSubscriberAndRecordsIp(): void
    {
        $service = new EmailListService();
        $service->subscribe(self::$userId, 'confirm@example.com', ['require_optin' => true]);
        $token = $this->row('confirm@example.com')['optin_token'];
        $this->assertNotEmpty($token);

        $result = $service->confirmOptin((string) $token, '203.0.113.7');

        $this->assertTrue($result['success']);
        $row = $this->row('confirm@example.com');
        $this->assertSame('subscribed', $row['status']);
        $this->assertNull($row['optin_token'], 'يُمسح التوكن بعد التأكيد');
        $this->assertSame('203.0.113.7', $row['optin_ip']);
        $this->assertNotEmpty($row['optin_at']);
    }

    public function testConfirmOptinInvalidTokenRejected(): void
    {
        $service = new EmailListService();
        $service->subscribe(self::$userId, 'bad@example.com', ['require_optin' => true]);

        $result = $service->confirmOptin('nonexistent-token', '203.0.113.9');

        $this->assertFalse($result['success']);
        $row = $this->row('bad@example.com');
        $this->assertSame('pending_optin', $row['status'], 'الحالة لا تتغير مع توكن غير صالح');
        $this->assertNotEmpty($row['optin_token']);
    }

    public function testCampaignAudienceExcludesPendingOptin(): void
    {
        $listService = new EmailListService();
        $campaignService = new EmailCampaignService();

        $listId = $listService->createList(self::$userId, 'جمهور مختلط')['id'];
        $listService->subscribe(self::$userId, 'active-optin@example.com', [], $listId);
        $listService->subscribe(self::$userId, 'pending-optin@example.com', ['require_optin' => true], $listId);

        $campaign = new EmailCampaign([
            'user_id' => self::$userId,
            'name' => 'حملة تستثني المعلقين',
            'subject' => 'اختبار',
            'html_body' => '<p>مرحبا</p>',
            'list_id' => $listId,
            'status' => EmailCampaign::STATUS_DRAFT,
        ]);
        $campaign->save();

        $audience = $campaignService->audience(self::$userId, $campaign);
        $emails = array_column($audience, 'email');
        $this->assertContains('active-optin@example.com', $emails);
        $this->assertNotContains('pending-optin@example.com', $emails, 'pending_optin لا يصل لأي حملة');
    }

    public function testSegmentEvaluationExcludesPendingOptin(): void
    {
        $listService = new EmailListService();
        $contacts = new ContactManagementService();

        $listService->subscribe(self::$userId, 'seg-active@example.com', []);
        $listService->subscribe(self::$userId, 'seg-pending@example.com', ['require_optin' => true]);

        $seg = $contacts->createSegment(self::$userId, [
            'name' => 'شريحة البريد',
            'match_all' => true,
            'conditions' => [
                ['field' => 'email', 'operator' => 'contains', 'value' => 'seg-'],
            ],
        ]);
        $this->assertTrue($seg['success']);

        $result = $contacts->evaluateSegment(self::$userId, (int) $seg['id']);
        $this->assertTrue($result['success']);
        $emails = array_column($result['data'], 'email');
        $this->assertContains('seg-active@example.com', $emails);
        $this->assertNotContains('seg-pending@example.com', $emails, 'الشرائح تستثني pending_optin');
    }
}

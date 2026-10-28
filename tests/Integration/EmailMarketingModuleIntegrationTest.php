<?php

/**
 * Tourfecto - Email Marketing Module Integration Test (M4)
 * بيفحص فجوات Competitive Analysis EmailMarketing:
 *   G2 استهداف الشرائح كجمهور للحملات (segment_id يغلب على القوائم)
 *   G3 تتبع فتح/كليك رسائل الأتمتة (email_automation_logs)
 *   G9 حساب درجة التفاعل (engagement_score) من أحداث حقيقية
 *
 * بيتخطى تلقائيًا (markTestSkipped) لو DB غير متاحة أو ميجريشن
 * 2026_08_29_000002_email_marketing_segment_and_automation_tracking.sql
 * لسه ما اتشغّلش.
 * @version 1.0.0  @date 2026-08-29
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/Mailer.php';
require_once __DIR__ . '/../../app/Models/EmailList.php';
require_once __DIR__ . '/../../app/Models/EmailSubscriber.php';
require_once __DIR__ . '/../../app/Models/EmailTemplate.php';
require_once __DIR__ . '/../../app/Models/EmailCampaign.php';
require_once __DIR__ . '/../../app/Models/EmailCampaignRecipient.php';
require_once __DIR__ . '/../../app/Models/EmailSegment.php';
require_once __DIR__ . '/../../app/Services/EmailMarketing/EmailRenderer.php';
require_once __DIR__ . '/../../app/Services/EmailMarketing/EmailListService.php';
require_once __DIR__ . '/../../app/Services/EmailMarketing/SmtpSettingsService.php';
require_once __DIR__ . '/../../app/Services/EmailMarketing/ContactManagementService.php';
require_once __DIR__ . '/../../app/Services/EmailMarketing/EmailCampaignService.php';
require_once __DIR__ . '/../../app/Services/EmailMarketing/EmailTrackingService.php';

final class EmailMarketingModuleIntegrationTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static bool $dbChecked = false;
    private static int $userId = 0;
    private static array $subscriberIds = [];
    private static int $automationId = 0;
    private static int $campaignId = 0;

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
            if (!class_exists('Database') && file_exists($app . '/Core/Database.php')) {
                require_once $app . '/Core/Database.php';
            }
            if (!class_exists('Logger') && file_exists($app . '/Core/Logger.php')) {
                require_once $app . '/Core/Logger.php';
            }
            if (!class_exists('Model') && file_exists($app . '/Core/Model.php')) {
                require_once $app . '/Core/Model.php';
            }
            if (!class_exists('EmailAutomation') && file_exists($app . '/Models/EmailAutomation.php')) {
                require_once $app . '/Models/EmailAutomation.php';
            }
            if (!class_exists('EmailAutomationEntry') && file_exists($app . '/Models/EmailAutomationEntry.php')) {
                require_once $app . '/Models/EmailAutomationEntry.php';
            }

            $db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $conn = $ref->getValue($db);
            if (!$conn instanceof PDO) {
                self::$pdo = null;
                return null;
            }

            $col = $conn->query("SHOW COLUMNS FROM email_campaigns LIKE 'segment_id'")->fetchAll();
            $logs = $conn->query("SHOW TABLES LIKE 'email_automation_logs'")->fetchAll();
            if (empty($col) || empty($logs)) {
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
            $this->markTestSkipped('DB غير متاحة أو ميجريشن M4 (segment_id/automation_logs) لسه ما اتشغّلش');
        }
        if (self::$userId === 0) {
            self::$userId = createTestUser();
        }
        if (empty(self::$subscriberIds)) {
            self::$subscriberIds = $this->seedSubscribers((int) self::$userId);
        }
        if (self::$automationId === 0) {
            self::$automationId = $this->insertAutomation((int) self::$userId);
        }
        if (self::$campaignId === 0) {
            $base = (new EmailCampaignService())->create((int) self::$userId, [
                'name' => 'حملة أساس',
                'subject' => 'أساس',
                'html_body' => '<p>أساس</p>',
            ]);
            self::$campaignId = (int) ($base['id'] ?? 0);
        }
    }

    protected function tearDown(): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return;
        }
        $uid = (int) self::$userId;
        $pdo->exec("DELETE FROM email_automation_logs WHERE user_id = {$uid}");
        $pdo->exec("DELETE FROM email_automations WHERE user_id = {$uid}");
        $pdo->exec("DELETE FROM email_campaign_recipients WHERE campaign_id IN (SELECT id FROM email_campaigns WHERE user_id = {$uid})");
        $pdo->exec("DELETE FROM email_campaigns WHERE user_id = {$uid}");
        $pdo->exec("DELETE FROM email_list_subscriber WHERE list_id IN (SELECT id FROM email_lists WHERE user_id = {$uid})");
        $pdo->exec("DELETE FROM email_suppressions WHERE user_id = {$uid}");
        $pdo->exec("DELETE FROM email_subscribers WHERE user_id = {$uid}");
        $pdo->exec("DELETE FROM email_segments WHERE user_id = {$uid}");
        $pdo->exec("DELETE FROM email_lists WHERE user_id = {$uid}");
        self::$subscriberIds = [];
        self::$automationId = 0;
        self::$campaignId = 0;
    }

    // ============================ Helpers ============================

    /** ينشئ قائمة + 3 مشتركين (2 باسم VIP ضمن شريحة الـ VIP) ويعيد معرفاتهم. */
    private function seedSubscribers(int $userId): array
    {
        $pdo = $this->db();
        $listService = new EmailListService();

        $list = $listService->createList($userId, 'قائمة كل العملاء');
        $listId = (int) ($list['id'] ?? 0);

        $subs = [];
        foreach (['VIP عميل أول', 'VIP عميل تاني', 'عميل عادي'] as $i => $name) {
            $email = 'm4_' . $userId . '_' . $i . '_' . uniqid() . '@example.com';
            $r = $listService->subscribe($userId, $email, ['name' => $name, 'source' => 'manual'], $listId);
            $subs[] = (int) $r['id'];
        }

        $this->insertSegment($userId, 'عملاء VIP', [['field' => 'name', 'operator' => 'contains', 'value' => 'VIP']]);

        return $subs;
    }

    private function insertSegment(int $userId, string $name, array $conditions): int
    {
        $pdo = $this->db();
        $pdo->exec(
            "INSERT INTO email_segments (user_id, name, conditions, match_all, subscriber_count)
             VALUES ({$userId}, '" . addslashes($name) . "', '" . addslashes(json_encode($conditions, JSON_UNESCAPED_UNICODE)) . "', 1, 0)"
        );
        return (int) $pdo->lastInsertId();
    }

    private function insertAutomation(int $userId): int
    {
        $pdo = $this->db();
        $pdo->exec(
            "INSERT INTO email_automations (user_id, name, trigger_type, status)
             VALUES ({$userId}, 'أتمتة اختبار', 'subscribed', 'active')"
        );
        return (int) $pdo->lastInsertId();
    }

    private function createSegmentCampaign(int $userId, int $segmentId, ?int $listId = null): array
    {
        $service = new EmailCampaignService();
        return $service->create($userId, [
            'name' => 'حملة شريحة VIP',
            'subject' => 'عرض VIP {{first_name}}',
            'html_body' => '<p>أهلًا {{first_name}}</p>',
            'list_id' => $listId,
            'segment_id' => $segmentId,
        ]);
    }

    // ============================ G2: استهداف الشرائح ============================

    public function testCreateCampaignRejectsForeignSegment(): void
    {
        $other = createTestUser();
        $foreignSegment = $this->insertSegment((int) $other, 'شريحة غريبة', [['field' => 'name', 'operator' => 'is', 'value' => 'X']]);
        $result = $this->createSegmentCampaign((int) self::$userId, $foreignSegment);
        $this->assertFalse($result['success']);
        $this->assertSame('الشريحة غير موجودة', $result['error']);
    }

    public function testSegmentAudienceOverridesLists(): void
    {
        $userId = (int) self::$userId;
        $segment = $this->insertSegment($userId, 'عملاء VIP', [['field' => 'name', 'operator' => 'contains', 'value' => 'VIP']]);
        $list = (new EmailListService())->lists($userId);
        $listId = (int) ($list[0]['id'] ?? 0);

        $campaign = $this->createSegmentCampaign($userId, $segment, $listId);
        $this->assertTrue($campaign['success']);

        $stored = (new EmailCampaignService())->get($userId, (int) $campaign['id']);
        $this->assertNotNull($stored);
        $this->assertSame($segment, (int) $stored['segment_id']);
        $this->assertSame('عملاء VIP', $stored['segment_name']);

        $audience = (new EmailCampaignService())->audience($userId, new EmailCampaign(['segment_id' => $segment, 'list_id' => $listId]));
        $this->assertCount(2, $audience);
        foreach ($audience as $member) {
            $this->assertStringContainsString('VIP', (string) $member['name']);
        }
    }

    public function testSegmentAudienceExcludesUnsubscribedAndSuppressed(): void
    {
        $userId = (int) self::$userId;
        $segment = $this->insertSegment($userId, 'عملاء VIP', [['field' => 'name', 'operator' => 'contains', 'value' => 'VIP']]);
        $pdo = $this->db();

        $vipEmails = $pdo->query(
            "SELECT id, email FROM email_subscribers WHERE user_id = {$userId} AND name LIKE '%VIP%' ORDER BY id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $vipEmails);

        $pdo->exec("UPDATE email_subscribers SET status = 'unsubscribed' WHERE id = " . (int) $vipEmails[0]['id']);
        $pdo->exec(
            "INSERT INTO email_suppressions (user_id, email, type, source)
             VALUES ({$userId}, '" . $vipEmails[1]['email'] . "', 'bounce', 'test')"
        );

        $audience = (new EmailCampaignService())->audience($userId, new EmailCampaign(['segment_id' => $segment]));
        $this->assertSame([], $audience);
    }

    // ============================ G3: تتبع رسائل الأتمتة ============================

    private function insertAutomationLog(array $overrides = []): int
    {
        $pdo = $this->db();
        $userId = (int) self::$userId;
        $data = array_merge([
            'user_id' => $userId,
            'automation_id' => self::$automationId,
            'entry_id' => null,
            'step_id' => null,
            'subscriber_id' => (int) (self::$subscriberIds[0] ?? 0),
            'to_email' => 'auto_' . $userId . '@example.com',
            'to_name' => 'عميل أتمتة',
            'subject' => 'رسالة أتمتة',
            'status' => 'sent',
            'error' => null,
            'open_token' => bin2hex(random_bytes(16)),
            'click_token' => bin2hex(random_bytes(16)),
        ], $overrides);

        $pdo->exec(
            "INSERT INTO email_automation_logs
                (user_id, automation_id, entry_id, step_id, subscriber_id, to_email, to_name, subject, status, error, open_token, click_token)
             VALUES ({$data['user_id']}, {$data['automation_id']}, " . ($data['entry_id'] === null ? 'NULL' : (int) $data['entry_id']) . ", "
                . ($data['step_id'] === null ? 'NULL' : (int) $data['step_id']) . ", "
                . ($data['subscriber_id'] === null ? 'NULL' : (int) $data['subscriber_id']) . ", '"
                . addslashes($data['to_email']) . "', '" . addslashes($data['to_name']) . "', '"
                . addslashes($data['subject']) . "', '{$data['status']}', "
                . ($data['error'] === null ? 'NULL' : "'" . addslashes($data['error']) . "'") . ", '"
                . $data['open_token'] . "', '" . $data['click_token'] . "')"
        );
        return (int) $pdo->lastInsertId();
    }

    public function testAutomationOpenAndClickTracking(): void
    {
        $subId = (int) (self::$subscriberIds[0] ?? 0);
        $logId = $this->insertAutomationLog(['subscriber_id' => $subId, 'to_email' => 'auto_track_' . self::$userId . '@example.com']);

        $pdo = $this->db();
        $log = $pdo->query("SELECT * FROM email_automation_logs WHERE id = {$logId}")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($log);
        $this->assertSame('sent', $log['status']);
        $this->assertNotEmpty($log['open_token']);
        $this->assertNotEmpty($log['click_token']);

        $tracking = new EmailTrackingService();
        $this->assertTrue($tracking->recordAutomationOpen((string) $log['open_token']));

        $encoded = strtr(base64_encode('https://tourfecto.com/hotel/5'), '+/', '-_');
        $redirect = $tracking->recordAutomationClick((string) $log['click_token'], $encoded);
        $this->assertSame('https://tourfecto.com/hotel/5', $redirect);

        $after = $pdo->query("SELECT * FROM email_automation_logs WHERE id = {$logId}")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $after['open_count']);
        $this->assertSame(1, (int) $after['click_count']);
        $this->assertNotEmpty($after['opened_at']);
        $this->assertNotEmpty($after['clicked_at']);

        $this->assertNull($tracking->recordAutomationClick(bin2hex(random_bytes(16)), $encoded));
        $this->assertFalse($tracking->recordAutomationOpen(bin2hex(random_bytes(16))));
    }

    public function testAutomationTrackingDoesNotLeakToOtherTenant(): void
    {
        $other = createTestUser();
        $otherAuto = $this->insertAutomation((int) $other);
        $logId = $this->insertAutomationLog(['user_id' => (int) $other, 'automation_id' => $otherAuto, 'subscriber_id' => null, 'to_email' => 'other@example.com']);
        $pdo = $this->db();
        $log = $pdo->query("SELECT * FROM email_automation_logs WHERE id = {$logId}")->fetch(PDO::FETCH_ASSOC);

        // التوكنات عمومية (المتتبع البكسل بلا جلسة) لكن الربط بقاعدة عزل تينانت
        $tracking = new EmailTrackingService();
        $ok = $tracking->recordAutomationOpen((string) $log['open_token']);
        $this->assertTrue($ok);
        $after = $pdo->query("SELECT open_count FROM email_automation_logs WHERE id = {$logId}")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $after['open_count']);
    }

    // ============================ G9: درجة التفاعل ============================

    public function testEngagementScoreComputedFromRealEvents(): void
    {
        $userId = (int) self::$userId;
        $subId = (int) (self::$subscriberIds[0] ?? 0);
        $pdo = $this->db();

        // أحداث حملة: فتح + كليك
        $cid = self::$campaignId;
        $pdo->exec(
            "INSERT INTO email_campaign_recipients (campaign_id, subscriber_id, email, status, opened_at, clicked_at)
             VALUES ({$cid}, {$subId}, 'sub0@example.com', 'clicked', NOW(), NOW())"
        );
        // حدث أتمتة: فتح
        $this->insertAutomationLog(['subscriber_id' => $subId, 'to_email' => 'eng_' . $userId . '@example.com']);
        $pdo->exec(
            "UPDATE email_automation_logs SET opened_at = NOW() WHERE user_id = {$userId} AND to_email = 'eng_{$userId}@example.com'"
        );

        $score = (new ContactManagementService())->recomputeEngagementScore($userId, $subId);
        // فتح ×20 + كليك ×30 + فتح ×20 = 70
        $this->assertSame(70, $score);

        $row = $pdo->query("SELECT engagement_score FROM email_subscribers WHERE id = {$subId}")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(70, (int) $row['engagement_score']);
    }

    public function testEngagementScoreCappedAt100(): void
    {
        $userId = (int) self::$userId;
        $subId = (int) (self::$subscriberIds[0] ?? 0);
        $pdo = $this->db();

        for ($i = 0; $i < 3; $i++) {
            $cid = self::$campaignId;
            $pdo->exec(
                "INSERT INTO email_campaign_recipients (campaign_id, subscriber_id, email, status, opened_at, clicked_at)
                 VALUES ({$cid}, {$subId}, 'cap{$i}@example.com', 'clicked', NOW(), NOW())"
            );
        }

        $score = (new ContactManagementService())->recomputeEngagementScore($userId, $subId);
        $this->assertSame(100, $score);
    }
}

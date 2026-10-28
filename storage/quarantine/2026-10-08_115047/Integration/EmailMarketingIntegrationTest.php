<?php

/**
 * Tourfecto - Email Marketing Integration Test
 * بيتخطى تلقائيًا (markTestSkipped) لو DB غير متاحة أو الميجريشن لسه
 * ما اتشغّلش: database/migrations/2026_08_21_000010_create_email_marketing_tables.sql
 *
 * بيفحص الدورة الكاملة:
 *   1) إنشاء قائمة + اشتراك مشتركين (جديد/مكرر/إلغاء/استيراد)
 *   2) الحصر الصحيح للجمهور (استبعاد unsubscribed/bounced)
 *   3) إنشاء حملة + تجهيز المستلمين (توكنات فتح/كليك فريدة)
 *   4) معالجة دفعة إرسال مع SMTP غير مكوّن → تسجيل failed + انتقال للحالة
 *   5) تتبع الفتح/الكليك/إلغاء الاشتراك وتحديث العدادات والتقرير
 * @version 1.0.0  @date 2026-08-21
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/Mailer.php';
require_once __DIR__ . '/../../app/Models/EmailList.php';
require_once __DIR__ . '/../../app/Models/EmailSubscriber.php';
require_once __DIR__ . '/../../app/Models/EmailTemplate.php';
require_once __DIR__ . '/../../app/Models/EmailCampaign.php';
require_once __DIR__ . '/../../app/Models/EmailCampaignRecipient.php';
require_once __DIR__ . '/../../app/Services/EmailMarketing/EmailRenderer.php';
require_once __DIR__ . '/../../app/Services/EmailMarketing/EmailListService.php';
require_once __DIR__ . '/../../app/Services/EmailMarketing/SmtpSettingsService.php';
require_once __DIR__ . '/../../app/Services/EmailMarketing/EmailCampaignService.php';
require_once __DIR__ . '/../../app/Services/EmailMarketing/EmailTrackingService.php';

final class EmailMarketingIntegrationTest extends TestCase
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
                self::$pdo = null;
                return null;
            }

            $tables = $conn->query("SHOW TABLES LIKE 'email_campaigns'")->fetchAll();
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
            $this->markTestSkipped('DB غير متاحة أو ميجريشن email marketing لسه ما اتشغّلش');
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
        $pdo->exec("DELETE FROM email_list_subscriber WHERE list_id IN (SELECT id FROM email_lists WHERE user_id = {$uid})");
        $pdo->exec("DELETE FROM email_templates WHERE user_id = {$uid}");
        $pdo->exec("DELETE FROM email_subscribers WHERE user_id = {$uid}");
        $pdo->exec("DELETE FROM email_lists WHERE user_id = {$uid}");
        try {
            $pdo->exec("DELETE FROM jobs");
        } catch (\Throwable $e) {
            // جدول jobs غير موجود - غير مهم للتنظيف
        }
    }

    // ============================ Lists & Subscribers ============================

    public function testCreateListAndSubscribe(): void
    {
        $service = new EmailListService();

        $created = $service->createList(self::$userId, 'قائمة العروض');
        $this->assertTrue($created['success']);
        $listId = $created['id'];

        $sub1 = $service->subscribe(self::$userId, 'client1@example.com', ['name' => 'أحمد'], $listId);
        $this->assertTrue($sub1['success']);
        $this->assertTrue($sub1['created']);

        // تكرار نفس البريد → تحديث مش إنشاء جديد
        $sub2 = $service->subscribe(self::$userId, 'client1@example.com', ['name' => 'أحمد علي'], $listId);
        $this->assertTrue($sub2['success']);
        $this->assertFalse($sub2['created']);

        // بريد غير صالح مرفوض
        $bad = $service->subscribe(self::$userId, 'not-an-email', [], $listId);
        $this->assertFalse($bad['success']);

        $lists = $service->lists(self::$userId);
        $this->assertCount(1, $lists);
        $this->assertSame('قائمة العروض', $lists[0]['name']);
    }

    public function testUnsubscribeAndRejoin(): void
    {
        $service = new EmailListService();
        $listId = $service->createList(self::$userId, 'قائمة 2')['id'];

        $sub = $service->subscribe(self::$userId, 'rejoin@example.com', ['name' => 'سارة'], $listId);
        $this->assertTrue($sub['success']);

        $row = (new EmailSubscriber())->where(['user_id' => self::$userId, 'email' => 'rejoin@example.com']);
        $this->assertNotEmpty($row);
        $token = $row[0]->getAttribute('unsubscribe_token');

        $ok = $service->unsubscribeByToken($token);
        $this->assertTrue($ok);

        $after = (new EmailSubscriber())->find($row[0]->getAttribute('id'));
        $this->assertSame('unsubscribed', $after->getAttribute('status'));

        // إعادة الاشتراك بتنشّط من جديد
        $re = $service->subscribe(self::$userId, 'rejoin@example.com', ['name' => 'سارة'], $listId);
        $this->assertTrue($re['success']);
        $this->assertFalse($re['created']);
        $after2 = (new EmailSubscriber())->find($row[0]->getAttribute('id'));
        $this->assertSame('subscribed', $after2->getAttribute('status'));
    }

    public function testImportSubscribers(): void
    {
        $service = new EmailListService();
        $listId = $service->createList(self::$userId, 'قائمة الاستيراد')['id'];

        $result = $service->import(self::$userId, [
            ['email' => 'a@example.com', 'name' => 'A'],
            ['email' => 'b@example.com', 'name' => 'B'],
            ['email' => 'a@example.com', 'name' => 'A2'], // مكرر
            ['email' => 'invalid', 'name' => 'X'], // غير صالح
        ], $listId);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['added']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['invalid']);
    }

    // ============================ Audience & Campaign ============================

    public function testAudienceExcludesUnsubscribedAndBounced(): void
    {
        $listService = new EmailListService();
        $campaignService = new EmailCampaignService();

        $listId = $listService->createList(self::$userId, 'جمهور الحصر')['id'];
        $listService->subscribe(self::$userId, 'active@example.com', ['name' => 'نشط'], $listId);
        $sub = $listService->subscribe(self::$userId, 'inactive@example.com', ['name' => 'خامل'], $listId);

        // إلغاء اشتراك الـ inactive
        $row = (new EmailSubscriber())->where(['user_id' => self::$userId, 'email' => 'inactive@example.com']);
        $listService->unsubscribeByToken($row[0]->getAttribute('unsubscribe_token'));

        $campaign = new EmailCampaign([
            'user_id' => self::$userId,
            'name' => 'حملة حصر',
            'subject' => 'اختبار',
            'html_body' => '<p>مرحبا {{first_name}}</p>',
            'list_id' => $listId,
            'status' => EmailCampaign::STATUS_DRAFT,
        ]);
        $campaign->save();

        $audience = $campaignService->audience(self::$userId, $campaign);
        $emails = array_column($audience, 'email');
        $this->assertContains('active@example.com', $emails);
        $this->assertNotContains('inactive@example.com', $emails);
    }

    public function testPrepareRecipientsCreatesUniqueTokens(): void
    {
        $listService = new EmailListService();
        $campaignService = new EmailCampaignService();

        $listId = $listService->createList(self::$userId, 'جمهور توكنز')['id'];
        $listService->subscribe(self::$userId, 't1@example.com', [], $listId);
        $listService->subscribe(self::$userId, 't2@example.com', [], $listId);

        $campaignId = $campaignService->create(self::$userId, [
            'name' => 'حملة توكنز',
            'subject' => 'س {{first_name}}',
            'html_body' => '<a href="https://example.com/x">رابط</a>',
            'list_id' => $listId,
        ])['id'];

        $prepared = $campaignService->prepareRecipients(self::$userId, $campaignId);
        $this->assertTrue($prepared['success']);
        $this->assertSame(2, $prepared['total']);

        $rows = self::$pdo->query(
            "SELECT open_token, click_token FROM email_campaign_recipients WHERE campaign_id = {$campaignId}"
        )->fetchAll();

        $this->assertCount(2, $rows);
        $this->assertNotSame($rows[0]['open_token'], $rows[1]['open_token']);
        $this->assertNotSame($rows[0]['click_token'], $rows[1]['click_token']);
        $this->assertNotSame($rows[0]['open_token'], $rows[0]['click_token']);
        $this->assertNotEmpty($rows[0]['open_token']);
        $this->assertNotEmpty($rows[0]['click_token']);
    }

    public function testSendBatchWithoutSmtpMarksRecipientsFailedAndCampaignFailed(): void
    {
        $listService = new EmailListService();
        $campaignService = new EmailCampaignService();

        $listId = $listService->createList(self::$userId, 'جمهور إرسال')['id'];
        $listService->subscribe(self::$userId, 'send1@example.com', [], $listId);

        $campaignId = $campaignService->create(self::$userId, [
            'name' => 'حملة إرسال',
            'subject' => 'اختبار إرسال',
            'html_body' => '<p>مرحبا {{first_name}}</p>',
            'list_id' => $listId,
        ])['id'];

        $campaignService->prepareRecipients(self::$userId, $campaignId);
        $result = $campaignService->sendBatch(self::$userId, $campaignId);

        // SMTP مش مكوّن في بيئة الاختبار → كل الرسائل فشلت بأمان (من غير استثناء)
        $this->assertSame(0, $result['processed']);
        $this->assertSame(1, $result['failed']);
        $this->assertFalse($result['remaining']);

        $status = self::$pdo->query("SELECT status FROM email_campaigns WHERE id = {$campaignId}")->fetchColumn();
        $this->assertSame(EmailCampaign::STATUS_FAILED, $status);

        $rcptStatus = self::$pdo->query("SELECT status FROM email_campaign_recipients WHERE campaign_id = {$campaignId}")->fetchColumn();
        $this->assertSame('failed', $rcptStatus);
        $this->assertNotEmpty(self::$pdo->query("SELECT error_message FROM email_campaign_recipients WHERE campaign_id = {$campaignId}")->fetchColumn());
    }

    // ============================ Tracking ============================

    public function testTrackingOpenClickAndUnsubscribe(): void
    {
        $listService = new EmailListService();
        $campaignService = new EmailCampaignService();
        $trackingService = new EmailTrackingService();

        $listId = $listService->createList(self::$userId, 'جمهور تتبع')['id'];
        $listService->subscribe(self::$userId, 'track@example.com', ['name' => 'متتبع'], $listId);

        $campaignId = $campaignService->create(self::$userId, [
            'name' => 'حملة تتبع',
            'subject' => 'تتبع',
            'html_body' => '<a href="https://example.com/offer">عرض</a>',
            'list_id' => $listId,
        ])['id'];

        $campaignService->prepareRecipients(self::$userId, $campaignId);

        $rcpt = self::$pdo->query("SELECT * FROM email_campaign_recipients WHERE campaign_id = {$campaignId}")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($rcpt);

        // فتح
        $openOk = $trackingService->recordOpen($rcpt['open_token']);
        $this->assertTrue($openOk);
        $this->assertSame('opened', self::$pdo->query("SELECT status FROM email_campaign_recipients WHERE id = {$rcpt['id']}")->fetchColumn());
        $this->assertSame(1, (int) self::$pdo->query("SELECT open_count FROM email_campaign_recipients WHERE id = {$rcpt['id']}")->fetchColumn());

        // كليك (base64url)
        $encoded = rtrim(base64_encode('https://example.com/offer'), '=');
        $url = $trackingService->recordClick($rcpt['click_token'], $encoded);
        $this->assertSame('https://example.com/offer', $url);
        $this->assertSame('clicked', self::$pdo->query("SELECT status FROM email_campaign_recipients WHERE id = {$rcpt['id']}")->fetchColumn());

        // توكن غير صالح → لا يعيد URL
        $bad = $trackingService->recordClick('nope', $encoded);
        $this->assertNull($bad);

        // منع open redirect: توكن صالح لكن بروتوكول غير http(s)
        $evil = rtrim(base64_encode('file:///etc/passwd'), '=');
        $this->assertNull($trackingService->recordClick($rcpt['click_token'], $evil));

        // إلغاء الاشتراك عبر توكن المشترك
        $subToken = self::$pdo->query("SELECT unsubscribe_token FROM email_subscribers WHERE email = 'track@example.com'")->fetchColumn();
        $this->assertTrue($trackingService->unsubscribe($subToken));
        $this->assertSame('unsubscribed', self::$pdo->query("SELECT status FROM email_subscribers WHERE email = 'track@example.com'")->fetchColumn());

        // عدادات الحملة اتحسبت
        $report = $campaignService->report(self::$userId, $campaignId);
        $this->assertNotNull($report);
        $this->assertSame(1, (int) $report['opened_count']);
        $this->assertSame(1, (int) $report['clicked_count']);
        $this->assertSame(1, (int) $report['unsubscribed_count']);
    }

    public function testReportComputesRates(): void
    {
        $campaignService = new EmailCampaignService();

        $campaign = new EmailCampaign([
            'user_id' => self::$userId,
            'name' => 'حملة تقرير',
            'subject' => 'تقرير',
            'html_body' => '<p>x</p>',
            'status' => EmailCampaign::STATUS_SENT,
            'sent_count' => 100,
            'opened_count' => 50,
            'clicked_count' => 10,
            'unsubscribed_count' => 2,
        ]);
        $campaign->save();

        $report = $campaignService->report(self::$userId, (int) $campaign->getAttribute('id'));
        $this->assertSame(50.0, $report['open_rate']);
        $this->assertSame(10.0, $report['click_rate']);
        $this->assertSame(20.0, $report['click_to_open_rate']);
        $this->assertSame(2.0, $report['unsubscribe_rate']);
    }
}

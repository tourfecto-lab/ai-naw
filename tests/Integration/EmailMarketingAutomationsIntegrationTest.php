<?php

/**
 * Tourfecto - Email Marketing Automations Integration Test (المرحلة 3)
 * بيتخطى تلقائيًا (markTestSkipped) لو DB غير متاحة أو الميجريشن لسه
 * ما اتشغّلش: database/migrations/2026_08_22_000013_email_marketing_automations.sql
 *
 * بيفحص:
 *   1) CRUD سير العمل: إنشاء/تحديث/حذف + قائمة + حالة
 *   2) الخطوات: حفظ/استبدال + قراءة بالترتيب
 *   3) مشغل الاشتراك: تسجيل دخول + معالجة الخطوات الفورية
 *   4) مشغل الوسم (tag_added): دخول عند إضافة وسم
 *   5) مشغلات فتح/نقر حملة: دخول عند الأحداث
 *   6) خطوات الانتظار: جدولة next_run_at + الاستحقاق
 *   7) منع ازدواج المشاركة النشطة + إعادة الدخول بعد الاكتمال
 *   8) قوائم الخروج: خروج المشترك إذا ترك القائمة المؤهلة
 * @version 1.0.0  @date 2026-08-22
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Models/EmailAutomation.php';
require_once __DIR__ . '/../../app/Models/EmailAutomationStep.php';
require_once __DIR__ . '/../../app/Models/EmailAutomationEntry.php';
require_once __DIR__ . '/../../app/Services/EmailMarketing/EmailAutomationService.php';

final class EmailMarketingAutomationsIntegrationTest extends TestCase
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
            if (!class_exists('EmailSubscriber') && file_exists($app . '/Models/EmailSubscriber.php')) {
                require_once $app . '/Models/EmailSubscriber.php';
            }
            if (!class_exists('EmailList') && file_exists($app . '/Models/EmailList.php')) {
                require_once $app . '/Models/EmailList.php';
            }
            if (!class_exists('EmailCampaign') && file_exists($app . '/Models/EmailCampaign.php')) {
                require_once $app . '/Models/EmailCampaign.php';
            }
            if (!class_exists('EmailListService') && file_exists($app . '/Services/EmailMarketing/EmailListService.php')) {
                require_once $app . '/Services/EmailMarketing/EmailListService.php';
            }
            if (!class_exists('ContactManagementService') && file_exists($app . '/Services/EmailMarketing/ContactManagementService.php')) {
                require_once $app . '/Services/EmailMarketing/ContactManagementService.php';
            }
            if (!class_exists('EmailTag') && file_exists($app . '/Models/EmailTag.php')) {
                require_once $app . '/Models/EmailTag.php';
            }
            if (!class_exists('EmailCustomField') && file_exists($app . '/Models/EmailCustomField.php')) {
                require_once $app . '/Models/EmailCustomField.php';
            }
            if (!class_exists('EmailSegment') && file_exists($app . '/Models/EmailSegment.php')) {
                require_once $app . '/Models/EmailSegment.php';
            }
            if (!class_exists('EmailSuppression') && file_exists($app . '/Models/EmailSuppression.php')) {
                require_once $app . '/Models/EmailSuppression.php';
            }
            if (!class_exists('EmailTrackingService') && file_exists($app . '/Services/EmailMarketing/EmailTrackingService.php')) {
                require_once $app . '/Services/EmailMarketing/EmailTrackingService.php';
            }
            if (!class_exists('EmailTemplate') && file_exists($app . '/Models/EmailTemplate.php')) {
                require_once $app . '/Models/EmailTemplate.php';
            }
            if (!class_exists('EmailRenderer') && file_exists($app . '/Services/EmailMarketing/EmailRenderer.php')) {
                require_once $app . '/Services/EmailMarketing/EmailRenderer.php';
            }
            if (!class_exists('Mailer') && file_exists($app . '/Services/Mailer.php')) {
                require_once $app . '/Services/Mailer.php';
            }

            $db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $conn = $ref->getValue($db);
            if (!$conn instanceof PDO) {
                self::$pdo = null;
                return null;
            }

            $tables = $conn->query("SHOW TABLES LIKE 'email_automations'")->fetchAll();
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
            $this->markTestSkipped('DB غير متاحة أو ميجريشن automations لسه ما اتشغّلش');
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
        $autoIds = $pdo->query("SELECT id FROM email_automations WHERE user_id = {$uid}")->fetchAll(PDO::FETCH_COLUMN);
        $idsList = $autoIds ? implode(',', array_map('intval', $autoIds)) : '0';
        $pdo->exec("DELETE FROM email_automation_entries WHERE automation_id IN ({$idsList})");
        $pdo->exec("DELETE FROM email_automation_steps WHERE automation_id IN ({$idsList})");
        $pdo->exec("DELETE FROM email_automations WHERE user_id = {$uid}");
        $pdo->exec("DELETE FROM email_subscriber_tag WHERE subscriber_id IN (SELECT id FROM email_subscribers WHERE user_id = {$uid})");
        $pdo->exec("DELETE FROM email_list_subscriber WHERE subscriber_id IN (SELECT id FROM email_subscribers WHERE user_id = {$uid})");
        $pdo->exec("DELETE FROM email_subscribers WHERE user_id = {$uid}");
        $pdo->exec("DELETE FROM email_tags WHERE user_id = {$uid}");
        $pdo->exec("DELETE FROM email_lists WHERE user_id = {$uid}");
        $campaignIds = $pdo->query("SELECT id FROM email_campaigns WHERE user_id = {$uid}")->fetchAll(PDO::FETCH_COLUMN);
        $campaignList = $campaignIds ? implode(',', array_map('intval', $campaignIds)) : '0';
        $pdo->exec("DELETE FROM email_campaign_recipients WHERE campaign_id IN ({$campaignList})");
        $pdo->exec("DELETE FROM email_campaigns WHERE user_id = {$uid}");
    }

    private function service(): EmailAutomationService
    {
        return new EmailAutomationService();
    }

    private function dbq(string $sql, array $params = []): array
    {
        return Database::getInstance()->query($sql, $params);
    }

    private function dbe(string $sql, array $params = []): void
    {
        Database::getInstance()->exec($sql, $params);
    }

    private function makeAutomation(array $overrides = []): array
    {
        $result = $this->service()->create(self::$userId, array_merge([
            'name' => 'سير عمل ترحيبي',
            'trigger_type' => EmailAutomation::TRIGGER_SUBSCRIBED,
            'trigger_value' => [],
            'status' => EmailAutomation::STATUS_ACTIVE,
        ], $overrides));
        $this->assertTrue($result['success'], $result['error'] ?? '');
        return $result;
    }

    private function makeSubscriber(string $email, array $data = []): int
    {
        $result = (new EmailListService())->subscribe(self::$userId, $email, $data);
        $this->assertTrue($result['success']);
        return (int) $result['id'];
    }

    public function testCreateGetUpdateDeleteAutomation(): void
    {
        $service = $this->service();
        $created = $this->makeAutomation(['name' => 'أتمتة الاختبار']);

        $fetched = $service->get(self::$userId, (int) $created['id']);
        $this->assertNotNull($fetched);
        $this->assertSame('أتمتة الاختبار', $fetched['name']);
        $this->assertSame(EmailAutomation::TRIGGER_SUBSCRIBED, $fetched['trigger_type']);

        $updated = $service->update(self::$userId, (int) $created['id'], ['name' => 'اسم معدل']);
        $this->assertTrue($updated['success']);
        $this->assertSame('اسم معدل', $service->get(self::$userId, (int) $created['id'])['name']);

        $deleted = $service->delete(self::$userId, (int) $created['id']);
        $this->assertTrue($deleted['success']);
        $this->assertNull($service->get(self::$userId, (int) $created['id']));
    }

    public function testForeignAutomationRejected(): void
    {
        $service = $this->service();
        $created = $this->makeAutomation();
        $this->assertNull($service->get(999999, (int) $created['id']));
        $this->assertFalse($service->update(999999, (int) $created['id'], ['name' => 'x'])['success']);
        $this->assertFalse($service->delete(999999, (int) $created['id'])['success']);
    }

    public function testSetStepsStoresInOrder(): void
    {
        $service = $this->service();
        $created = $this->makeAutomation();
        $steps = [
            ['step_type' => EmailAutomationStep::STEP_WAIT, 'step_value' => ['days' => 1, 'hours' => 0, 'minutes' => 0]],
            ['step_type' => EmailAutomationStep::STEP_ADD_TAG, 'step_value' => ['tag' => 'vip']],
            ['step_type' => EmailAutomationStep::STEP_SEND_EMAIL, 'step_value' => ['subject' => 'أهلاً', 'html' => '<p>مرحباً</p>']],
            ['step_type' => EmailAutomationStep::STEP_END, 'step_value' => []],
        ];
        $result = $service->setSteps(self::$userId, (int) $created['id'], $steps);
        $this->assertTrue($result['success']);

        $fetched = $service->get(self::$userId, (int) $created['id']);
        $this->assertCount(4, $fetched['steps']);
        $this->assertSame(EmailAutomationStep::STEP_WAIT, $fetched['steps'][0]['step_type']);
        $this->assertSame(EmailAutomationStep::STEP_ADD_TAG, $fetched['steps'][1]['step_type']);
        $this->assertSame(EmailAutomationStep::STEP_SEND_EMAIL, $fetched['steps'][2]['step_type']);
        $this->assertSame(EmailAutomationStep::STEP_END, $fetched['steps'][3]['step_type']);

        // استبدال: خطوة واحدة فقط
        $service->setSteps(self::$userId, (int) $created['id'], [
            ['step_type' => EmailAutomationStep::STEP_END, 'step_value' => []],
        ]);
        $fetched2 = $service->get(self::$userId, (int) $created['id']);
        $this->assertCount(1, $fetched2['steps']);
    }

    public function testSubscribeTriggerEnrollsAndProcessesImmediateSteps(): void
    {
        $service = $this->service();
        $created = $this->makeAutomation();
        $service->setSteps(self::$userId, (int) $created['id'], [
            ['step_type' => EmailAutomationStep::STEP_ADD_TAG, 'step_value' => ['tag' => 'welcome']],
            ['step_type' => EmailAutomationStep::STEP_ADD_TAG, 'step_value' => ['tag' => 'new']],
        ]);

        // subscribe يطلق خطاف handleEvent('subscribed') تلقائيًا ثم يعالج الخطوات الفورية
        $subId = $this->makeSubscriber('auto1@example.com', ['name' => 'أحمد']);

        $entries = $this->dbq(
            "SELECT * FROM email_automation_entries WHERE automation_id = ? AND subscriber_id = ?",
            [(int) $created['id'], $subId]
        );
        $this->assertCount(1, $entries);
        $entry = $entries[0];
        $this->assertSame(EmailAutomationEntry::STATUS_COMPLETED, $entry['status']);
        $this->assertSame(2, (int) $entry['step_position']);

        // الوسوم أُضيفت
        $tags = $this->dbq(
            "SELECT t.name FROM email_tags t
             JOIN email_subscriber_tag st ON st.tag_id = t.id
             WHERE st.subscriber_id = ? AND t.user_id = ?",
            [$subId, self::$userId]
        );
        $names = array_column($tags, 'name');
        $this->assertContains('welcome', $names);
        $this->assertContains('new', $names);
    }

    public function testSubscribeTriggerSkipsWrongList(): void
    {
        $service = $this->service();
        $list = new EmailList();
        $list->setAttribute('user_id', self::$userId);
        $list->setAttribute('name', 'قائمة vip');
        $list->setAttribute('description', '');
        $list->save();
        $listId = (int) $list->getAttribute('id');

        $created = $this->makeAutomation(['trigger_value' => ['list_id' => $listId]]);
        $service->setSteps(self::$userId, (int) $created['id'], [
            ['step_type' => EmailAutomationStep::STEP_END, 'step_value' => []],
        ]);

        $subId = $this->makeSubscriber('auto2@example.com');
        $service->handleEvent(self::$userId, EmailAutomation::TRIGGER_SUBSCRIBED, [
            'subscriber_id' => $subId,
            'list_id' => 0,
        ]);
        $entries = $this->dbq(
            "SELECT * FROM email_automation_entries WHERE automation_id = ? AND subscriber_id = ?",
            [(int) $created['id'], $subId]
        );
        $this->assertCount(0, $entries);

        // الاشتراك في القائمة الصحيحة يسجّل
        (new EmailListService())->attachToList(self::$userId, $subId, $listId);
        $service->handleEvent(self::$userId, EmailAutomation::TRIGGER_SUBSCRIBED, [
            'subscriber_id' => $subId,
            'list_id' => $listId,
        ]);
        $entries2 = $this->dbq(
            "SELECT * FROM email_automation_entries WHERE automation_id = ? AND subscriber_id = ?",
            [(int) $created['id'], $subId]
        );
        $this->assertCount(1, $entries2);
    }

    public function testTagAddedTriggerEnrolls(): void
    {
        $service = $this->service();
        $created = $this->makeAutomation(['trigger_type' => EmailAutomation::TRIGGER_TAG_ADDED]);
        $service->setSteps(self::$userId, (int) $created['id'], [
            ['step_type' => EmailAutomationStep::STEP_END, 'step_value' => []],
        ]);

        $subId = $this->makeSubscriber('auto3@example.com');
        $contacts = new ContactManagementService();
        $tag = $contacts->createTag(self::$userId, 'sale');
        $this->assertTrue($tag['success']);

        $assign = $contacts->assignTag(self::$userId, $subId, (int) $tag['id']);
        $this->assertTrue($assign['success']);

        $entries = $this->dbq(
            "SELECT * FROM email_automation_entries WHERE automation_id = ? AND subscriber_id = ?",
            [(int) $created['id'], $subId]
        );
        $this->assertCount(1, $entries);
    }

    public function testCampaignOpenTriggerEnrollsOnTrackOpen(): void
    {
        $service = $this->service();
        $campaign = new EmailCampaign([
            'user_id' => self::$userId,
            'name' => 'حملة الفتح',
            'subject' => 'موضوع',
            'html_body' => '<p>x</p>',
            'status' => 'sent',
        ]);
        $campaign->save();
        $campaignId = (int) $campaign->getAttribute('id');

        $created = $this->makeAutomation([
            'trigger_type' => EmailAutomation::TRIGGER_CAMPAIGN_OPENED,
            'trigger_value' => ['campaign_id' => $campaignId],
        ]);
        $service->setSteps(self::$userId, (int) $created['id'], [
            ['step_type' => EmailAutomationStep::STEP_END, 'step_value' => []],
        ]);

        $subId = $this->makeSubscriber('auto4@example.com');
        $tracking = new EmailTrackingService();
        $tracking->recordOpen('open-token-' . $subId);

        // بدون مستلم لا يدخل
        $entries = $this->dbq(
            "SELECT * FROM email_automation_entries WHERE automation_id = ? AND subscriber_id = ?",
            [(int) $created['id'], $subId]
        );
        $this->assertCount(0, $entries);

        // إنشاء مستلم بالتوكن ثم فتحه
        $this->dbe(
            "INSERT INTO email_campaign_recipients (campaign_id, subscriber_id, email, open_token, click_token, status)
             VALUES ({$campaignId}, {$subId}, 'auto4@example.com', 'open-token-{$subId}', 'click-token-{$subId}', 'sent')"
        );
        $tracking->recordOpen('open-token-' . $subId);
        $entries2 = $this->dbq(
            "SELECT * FROM email_automation_entries WHERE automation_id = ? AND subscriber_id = ?",
            [(int) $created['id'], $subId]
        );
        $this->assertCount(1, $entries2);
    }

    public function testCampaignClickTriggerEnrollsOnTrackClick(): void
    {
        $service = $this->service();
        $campaign = new EmailCampaign([
            'user_id' => self::$userId,
            'name' => 'حملة الكليك',
            'subject' => 'موضوع',
            'html_body' => '<p>x</p>',
            'status' => 'sent',
        ]);
        $campaign->save();
        $campaignId = (int) $campaign->getAttribute('id');

        $created = $this->makeAutomation([
            'trigger_type' => EmailAutomation::TRIGGER_CAMPAIGN_CLICKED,
            'trigger_value' => [],
        ]);
        $service->setSteps(self::$userId, (int) $created['id'], [
            ['step_type' => EmailAutomationStep::STEP_END, 'step_value' => []],
        ]);

        $subId = $this->makeSubscriber('auto5@example.com');
        $this->dbe(
            "INSERT INTO email_campaign_recipients (campaign_id, subscriber_id, email, open_token, click_token, status)
             VALUES ({$campaignId}, {$subId}, 'auto5@example.com', 'o5', 'click-token-{$subId}', 'sent')"
        );
        $tracking = new EmailTrackingService();
        $url = $tracking->recordClick('click-token-' . $subId, base64_encode('https://example.com/x'));
        $this->assertSame('https://example.com/x', $url);

        $entries = $this->dbq(
            "SELECT * FROM email_automation_entries WHERE automation_id = ? AND subscriber_id = ?",
            [(int) $created['id'], $subId]
        );
        $this->assertCount(1, $entries);
    }

    public function testWaitStepSchedulesAndCompletesLater(): void
    {
        $service = $this->service();
        $created = $this->makeAutomation();
        $service->setSteps(self::$userId, (int) $created['id'], [
            ['step_type' => EmailAutomationStep::STEP_WAIT, 'step_value' => ['days' => 0, 'hours' => 1, 'minutes' => 0]],
            ['step_type' => EmailAutomationStep::STEP_ADD_TAG, 'step_value' => ['tag' => 'later']],
        ]);

        $subId = $this->makeSubscriber('auto6@example.com');
        $service->handleEvent(self::$userId, EmailAutomation::TRIGGER_SUBSCRIBED, [
            'subscriber_id' => $subId,
            'list_id' => 0,
        ]);

        $entry = $this->dbq(
            "SELECT * FROM email_automation_entries WHERE automation_id = ? AND subscriber_id = ?",
            [(int) $created['id'], $subId]
        )[0];
        $this->assertSame(0, (int) $entry['step_position']);
        $this->assertNotEmpty($entry['next_run_at']);

        // قبل الاستحقاق لا يتقدم
        $service->processDue();
        $entry2 = $this->dbq(
            "SELECT * FROM email_automation_entries WHERE automation_id = ? AND subscriber_id = ?",
            [(int) $created['id'], $subId]
        )[0];
        $this->assertSame(0, (int) $entry2['step_position']);

        // استحقاق فوري عبر تحديث next_run_at
        $this->dbe(
            "UPDATE email_automation_entries SET next_run_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE automation_id = ? AND subscriber_id = ?",
            [(int) $created['id'], $subId]
        );
        $service->processDue();

        $entry3 = $this->dbq(
            "SELECT * FROM email_automation_entries WHERE automation_id = ? AND subscriber_id = ?",
            [(int) $created['id'], $subId]
        )[0];
        $this->assertSame(2, (int) $entry3['step_position']);
        $this->assertSame(EmailAutomationEntry::STATUS_COMPLETED, $entry3['status']);
        $tags = array_column($this->dbq(
            "SELECT t.name FROM email_tags t JOIN email_subscriber_tag st ON st.tag_id = t.id
             WHERE st.subscriber_id = ? AND t.user_id = ?",
            [$subId, self::$userId]
        ), 'name');
        $this->assertContains('later', $tags);
    }

    public function testNoDuplicateActiveEntryButReentryAfterCompletion(): void
    {
        $service = $this->service();
        $created = $this->makeAutomation();
        $service->setSteps(self::$userId, (int) $created['id'], [
            ['step_type' => EmailAutomationStep::STEP_WAIT, 'step_value' => ['days' => 0, 'hours' => 0, 'minutes' => 5]],
        ]);

        $subId = $this->makeSubscriber('auto7@example.com');
        // حدثان متتاليان أثناء نشاط المشاركة → لا ازدواج
        $service->handleEvent(self::$userId, EmailAutomation::TRIGGER_SUBSCRIBED, [
            'subscriber_id' => $subId,
            'list_id' => 0,
        ]);
        $service->handleEvent(self::$userId, EmailAutomation::TRIGGER_SUBSCRIBED, [
            'subscriber_id' => $subId,
            'list_id' => 0,
        ]);

        $entries = $this->dbq(
            "SELECT * FROM email_automation_entries WHERE automation_id = ? AND subscriber_id = ?",
            [(int) $created['id'], $subId]
        );
        $this->assertCount(1, $entries);
        $this->assertSame(EmailAutomationEntry::STATUS_ACTIVE, $entries[0]['status']);

        // إكمال المشاركة (انتهاء الانتظار) ثم حدث جديد → إعادة دخول مسموحة
        $this->dbe(
            "UPDATE email_automation_entries SET next_run_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE automation_id = ? AND subscriber_id = ?",
            [(int) $created['id'], $subId]
        );
        $service->processDue();
        $service->handleEvent(self::$userId, EmailAutomation::TRIGGER_SUBSCRIBED, [
            'subscriber_id' => $subId,
            'list_id' => 0,
        ]);

        $entries2 = $this->dbq(
            "SELECT * FROM email_automation_entries WHERE automation_id = ? AND subscriber_id = ? ORDER BY id ASC",
            [(int) $created['id'], $subId]
        );
        $this->assertCount(2, $entries2);
        $this->assertSame(EmailAutomationEntry::STATUS_COMPLETED, $entries2[0]['status']);
        $this->assertSame(EmailAutomationEntry::STATUS_ACTIVE, $entries2[1]['status']);
    }

    public function testPausedAutomationDoesNotEnroll(): void
    {
        $service = $this->service();
        $created = $this->makeAutomation(['status' => EmailAutomation::STATUS_PAUSED]);
        $service->setSteps(self::$userId, (int) $created['id'], [
            ['step_type' => EmailAutomationStep::STEP_END, 'step_value' => []],
        ]);

        $subId = $this->makeSubscriber('auto8@example.com');
        $service->handleEvent(self::$userId, EmailAutomation::TRIGGER_SUBSCRIBED, [
            'subscriber_id' => $subId,
            'list_id' => 0,
        ]);
        $entries = $this->dbq(
            "SELECT * FROM email_automation_entries WHERE automation_id = ? AND subscriber_id = ?",
            [(int) $created['id'], $subId]
        );
        $this->assertCount(0, $entries);
    }

    public function testExitAudienceRemovesEntry(): void
    {
        $service = $this->service();
        $list = new EmailList();
        $list->setAttribute('user_id', self::$userId);
        $list->setAttribute('name', 'قائمة الخروج');
        $list->setAttribute('description', '');
        $list->save();
        $listId = (int) $list->getAttribute('id');

        $created = $this->makeAutomation(['exit_audience_ids' => [$listId]]);
        $service->setSteps(self::$userId, (int) $created['id'], [
            ['step_type' => EmailAutomationStep::STEP_WAIT, 'step_value' => ['days' => 0, 'hours' => 0, 'minutes' => 5]],
            ['step_type' => EmailAutomationStep::STEP_ADD_TAG, 'step_value' => ['tag' => 'x']],
        ]);

        // subscribe مع القائمة يضيف المشترك للقائمة ثم يطلق الخطاف → تبقى المشاركة نشطة
        $sub = (new EmailListService())->subscribe(self::$userId, 'auto9@example.com', [], $listId);
        $subId = (int) $sub['id'];
        $this->assertTrue($sub['success']);
        $entries = $this->dbq(
            "SELECT * FROM email_automation_entries WHERE automation_id = ? AND subscriber_id = ? ORDER BY id ASC",
            [(int) $created['id'], $subId]
        );
        $this->assertCount(1, $entries);
        $this->assertSame(EmailAutomationEntry::STATUS_ACTIVE, $entries[0]['status']);

        // بعد إزالة من قائمة الخروج + استحقاق → خروج
        $this->dbe(
            "DELETE FROM email_list_subscriber WHERE subscriber_id = ? AND list_id = ?",
            [$subId, $listId]
        );
        $this->dbe(
            "UPDATE email_automation_entries SET next_run_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE automation_id = ? AND subscriber_id = ?",
            [(int) $created['id'], $subId]
        );
        $service->processDue();
        $entry2 = $this->dbq(
            "SELECT * FROM email_automation_entries WHERE automation_id = ? AND subscriber_id = ?",
            [(int) $created['id'], $subId]
        )[0];
        $this->assertSame(EmailAutomationEntry::STATUS_EXITED, $entry2['status']);
    }

    public function testEntryAudienceRequiresMembership(): void
    {
        $service = $this->service();
        $list = new EmailList();
        $list->setAttribute('user_id', self::$userId);
        $list->setAttribute('name', 'قائمة الدخول');
        $list->setAttribute('description', '');
        $list->save();
        $listId = (int) $list->getAttribute('id');

        $created = $this->makeAutomation(['entry_audience_ids' => [$listId]]);
        $service->setSteps(self::$userId, (int) $created['id'], [
            ['step_type' => EmailAutomationStep::STEP_END, 'step_value' => []],
        ]);

        $subId = $this->makeSubscriber('auto10@example.com');
        $service->handleEvent(self::$userId, EmailAutomation::TRIGGER_SUBSCRIBED, [
            'subscriber_id' => $subId,
            'list_id' => 0,
        ]);
        $entries = $this->dbq(
            "SELECT * FROM email_automation_entries WHERE automation_id = ? AND subscriber_id = ?",
            [(int) $created['id'], $subId]
        );
        $this->assertCount(0, $entries);

        // إضافة للقائمة المؤهلة ثم حدث → يدخل
        (new EmailListService())->attachToList(self::$userId, $subId, $listId);
        $service->handleEvent(self::$userId, EmailAutomation::TRIGGER_SUBSCRIBED, [
            'subscriber_id' => $subId,
            'list_id' => $listId,
        ]);
        $entries2 = $this->dbq(
            "SELECT * FROM email_automation_entries WHERE automation_id = ? AND subscriber_id = ?",
            [(int) $created['id'], $subId]
        );
        $this->assertCount(1, $entries2);
    }

    public function testDateAfterTriggerEnrollsQualifiedSubscribers(): void
    {
        $service = $this->service();
        $created = $this->makeAutomation([
            'trigger_type' => EmailAutomation::TRIGGER_DATE_AFTER,
            'trigger_value' => ['days' => 2],
        ]);
        $service->setSteps(self::$userId, (int) $created['id'], [
            ['step_type' => EmailAutomationStep::STEP_END, 'step_value' => []],
        ]);

        // مشترك قديم (قبل المدة)
        $old = $this->makeSubscriber('old@example.com');
        $this->dbe(
            "UPDATE email_subscribers SET created_at = DATE_SUB(NOW(), INTERVAL 5 DAY) WHERE id = ?",
            [$old]
        );
        // مشترك حديث (أقل من المدة) — عبر SQL مباشرة لتفادي الخطاف
        $sub = new EmailSubscriber([
            'user_id' => self::$userId,
            'email' => 'recent@example.com',
            'status' => 'subscribed',
            'unsubscribe_token' => 't-recent',
        ]);
        $sub->save();
        $newId = (int) $sub->getAttribute('id');

        $service->processDue();

        $counts = $this->dbq(
            "SELECT subscriber_id FROM email_automation_entries WHERE automation_id = ?",
            [(int) $created['id']]
        );
        $ids = array_column($counts, 'subscriber_id');
        $this->assertContains($old, $ids);
        $this->assertNotContains($newId, $ids);
    }
}

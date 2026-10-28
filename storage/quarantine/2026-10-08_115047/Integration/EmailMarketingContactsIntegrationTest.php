<?php

/**
 * Tourfecto - Email Marketing Contacts Management Integration Test (المرحلة 1)
 * بيتخطى تلقائيًا (markTestSkipped) لو DB غير متاحة أو الميجريشن لسه
 * ما اتشغّلش: database/migrations/2026_08_21_000011_email_marketing_contacts.sql
 *
 * بيفحص:
 *   1) الحقول المخصصة: إنشاء/حفظ قيم/منع الحقول النظامية
 *   2) الوسوم: إنشاء/ربط/فك/تطبيق بالاسم
 *   3) الشرائح: إنشاء وتقييم (status/email/has_tag/نص)
 *   4) قائمة الممنوعين: إضافة/استبعاد من الجمهور/تسجيل مشاكل التسليم
 *   5) الاستيراد المتقدم (custom/tags/status) والتصدير
 * @version 1.0.0  @date 2026-08-22
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Models/EmailCustomField.php';
require_once __DIR__ . '/../../app/Models/EmailSubscriberCustomValue.php';
require_once __DIR__ . '/../../app/Models/EmailTag.php';
require_once __DIR__ . '/../../app/Models/EmailSegment.php';
require_once __DIR__ . '/../../app/Models/EmailSuppression.php';
require_once __DIR__ . '/../../app/Services/EmailMarketing/ContactManagementService.php';

final class EmailMarketingContactsIntegrationTest extends TestCase
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
            if (!class_exists('EmailListService') && file_exists($app . '/Services/EmailMarketing/EmailListService.php')) {
                require_once $app . '/Services/EmailMarketing/EmailListService.php';
            }

            $db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $conn = $ref->getValue($db);
            if (!$conn instanceof PDO) {
                self::$pdo = null;
                return null;
            }

            $tables = $conn->query("SHOW TABLES LIKE 'email_custom_fields'")->fetchAll();
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
            $this->markTestSkipped('DB غير متاحة أو ميجريشن contacts لسه ما اتشغّلش');
        }
        if (self::$userId === 0) {
            self::$userId = createTestUser();
        } else {
            // Defensive: التنفيذ العشوائي بين الملفات بيخلي FixtureLoader::cleanDatabase()
            // يمسح users ويرجّع الفيكتشرز بس، فالمستخدم بتاعنا بيترشّح -> نعيد إنشاؤه
            $stmt = $pdo->query("SELECT id FROM users WHERE id = " . (int) self::$userId);
            $exists = $stmt ? $stmt->fetchAll() : [];
            if (empty($exists)) {
                self::$userId = createTestUser();
            }
        }
    }

    protected function tearDown(): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return;
        }
        $uid = (int) self::$userId;
        $pdo->exec("DELETE FROM email_subscriber_custom_values WHERE subscriber_id IN (SELECT id FROM email_subscribers WHERE user_id = {$uid})");
        $pdo->exec("DELETE FROM email_subscriber_tag WHERE subscriber_id IN (SELECT id FROM email_subscribers WHERE user_id = {$uid})");
        $pdo->exec("DELETE FROM email_subscribers WHERE user_id = {$uid}");
        $pdo->exec("DELETE FROM email_suppressions WHERE user_id = {$uid}");
        $pdo->exec("DELETE FROM email_segments WHERE user_id = {$uid}");
        $pdo->exec("DELETE FROM email_tags WHERE user_id = {$uid}");
        $pdo->exec("DELETE FROM email_custom_fields WHERE user_id = {$uid}");
    }

    private function service(): ContactManagementService
    {
        return new ContactManagementService();
    }

    private function addSubscriber(string $email, string $name = '', int $listId = 0): int
    {
        $result = (new EmailListService())->subscribe(self::$userId, $email, ['name' => $name], $listId);
        $this->assertTrue($result['success']);
        return (int) $result['id'];
    }

    // ============================ Custom Fields ============================

    public function testSystemFieldsAutoCreated(): void
    {
        $fields = $this->service()->customFields(self::$userId);
        $names = array_column($fields, 'name');
        $this->assertContains('first_name', $names);
        $this->assertContains('last_name', $names);
        $this->assertContains('birthday', $names);
        $system = array_filter($fields, fn ($f) => (int) $f['is_system'] === 1);
        $this->assertGreaterThanOrEqual(6, count($system));
    }

    public function testCreateAndUpdateCustomField(): void
    {
        $created = $this->service()->createCustomField(self::$userId, [
            'name' => 'job_title',
            'label' => 'المسمى الوظيفي',
            'field_type' => 'text',
            'is_required' => 1,
        ]);
        $this->assertTrue($created['success']);
        $fieldId = (int) $created['id'];

        // اسم مكرر مرفوض
        $dup = $this->service()->createCustomField(self::$userId, [
            'name' => 'job_title', 'label' => 'مكرر', 'field_type' => 'text',
        ]);
        $this->assertFalse($dup['success']);

        // تحديث التسمية
        $updated = $this->service()->updateCustomField(self::$userId, $fieldId, ['label' => 'المنصب']);
        $this->assertTrue($updated['success']);
        $fields = $this->service()->customFields(self::$userId);
        $row = current(array_filter($fields, fn ($f) => (int) $f['id'] === $fieldId));
        $this->assertSame('المنصب', $row['label']);
    }

    public function testSystemFieldCannotBeDeleted(): void
    {
        $fields = $this->service()->customFields(self::$userId);
        $first = current(array_filter($fields, fn ($f) => (int) $f['is_system'] === 1));
        $this->assertNotFalse($first);
        $result = $this->service()->deleteCustomField(self::$userId, (int) $first['id']);
        $this->assertFalse($result['success']);
    }

    public function testSaveAndReadCustomValues(): void
    {
        $service = $this->service();
        $subscriberId = $this->addSubscriber('cv@example.com', 'قيم');

        $fields = $service->customFields(self::$userId);
        $company = current(array_filter($fields, fn ($f) => $f['name'] === 'company'));

        $service->saveCustomValues(self::$userId, $subscriberId, [
            (int) $company['id'] => 'Acme Inc',
            'birthday' => '1991-03-03',
        ]);

        $values = $service->subscriberCustomValues(self::$userId, $subscriberId);
        $byName = [];
        foreach ($values as $v) {
            $byName[$v['name']] = $v['value'];
        }
        $this->assertSame('Acme Inc', $byName['company']);
        $this->assertSame('1991-03-03', $byName['birthday']);
    }

    // ============================ Tags ============================

    public function testTagCrudAndAssignment(): void
    {
        $service = $this->service();
        $tag = $service->createTag(self::$userId, 'VIP', '#eab308');
        $this->assertTrue($tag['success']);
        $tagId = (int) $tag['id'];

        $subscriberId = $this->addSubscriber('tag@example.com');

        $assign = $service->assignTag(self::$userId, $subscriberId, $tagId);
        $this->assertTrue($assign['success']);
        $tags = $service->subscriberTags(self::$userId, $subscriberId);
        $this->assertCount(1, $tags);
        $this->assertSame('VIP', $tags[0]['name']);

        // الربط المكرر لا يضاعف
        $service->assignTag(self::$userId, $subscriberId, $tagId);
        $this->assertCount(1, $service->subscriberTags(self::$userId, $subscriberId));

        // فك الربط
        $this->assertTrue($service->removeTag(self::$userId, $subscriberId, $tagId)['success']);
        $this->assertCount(0, $service->subscriberTags(self::$userId, $subscriberId));

        // تطبيق بالاسم ينشئ الوسم تلقائيًا
        $service->applyTagByName(self::$userId, $subscriberId, 'عميل جديد');
        $names = array_column($service->subscriberTags(self::$userId, $subscriberId), 'name');
        $this->assertContains('عميل جديد', $names);
    }

    // ============================ Segments ============================

    public function testSegmentByStatusAndEmail(): void
    {
        $service = $this->service();
        $this->addSubscriber('seg-a@example.com', 'نشط');
        $this->addSubscriber('seg-b@example.com', 'خامل');

        $seg = $service->createSegment(self::$userId, [
            'name' => 'نشطون',
            'match_all' => true,
            'conditions' => [
                ['field' => 'status', 'operator' => 'is', 'value' => 'subscribed'],
                ['field' => 'email', 'operator' => 'contains', 'value' => 'seg-'],
            ],
        ]);
        $this->assertTrue($seg['success']);

        $result = $service->evaluateSegment(self::$userId, (int) $seg['id']);
        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['count']);
    }

    public function testSegmentByTag(): void
    {
        $service = $this->service();
        $subId = $this->addSubscriber('seg-tag@example.com');
        $service->applyTagByName(self::$userId, $subId, 'خاص');

        $seg = $service->createSegment(self::$userId, [
            'name' => 'أصحاب وسم خاص',
            'match_all' => true,
            'conditions' => [
                ['field' => 'has_tag', 'operator' => 'has_tag', 'value' => 'خاص'],
            ],
        ]);
        $this->assertTrue($seg['success']);

        $result = $service->evaluateSegment(self::$userId, (int) $seg['id']);
        $this->assertSame(1, $result['count']);
        $this->assertContains('seg-tag@example.com', array_column($result['data'], 'email'));
    }

    // ============================ Suppressions ============================

    public function testSuppressionAndAudienceExclusion(): void
    {
        $service = $this->service();
        $this->addSubscriber('ok@example.com');
        $subId = $this->addSubscriber('blocked@example.com');

        $added = $service->addSuppression(self::$userId, 'blocked@example.com', 'bounce', 'اختبار');
        $this->assertTrue($added['success']);
        $this->assertTrue($service->isSuppressed(self::$userId, 'blocked@example.com'));
        $this->assertFalse($service->isSuppressed(self::$userId, 'ok@example.com'));

        // تحديث حالة المشترك إلى مرتد عند تسجيل مشكلة تسليم
        $service->recordDeliveryIssue(self::$userId, 'blocked@example.com', 'bounce');
        $row = self::$pdo->query("SELECT status FROM email_subscribers WHERE id = {$subId}")->fetchColumn();
        $this->assertSame('bounced', $row);
    }

    // ============================ Import / Export ============================

    public function testAdvancedImportWithCustomAndTags(): void
    {
        $service = $this->service();
        $result = $service->importContacts(self::$userId, [
            ['email' => 'imp-a@example.com', 'first_name' => 'أحمد', 'custom' => ['3' => 'Acme'], 'tags' => ['VIP']],
            ['email' => 'imp-b@example.com', 'first_name' => 'سارة', 'status' => 'unsubscribed'],
            ['email' => 'bad-email', 'first_name' => 'x'],
        ], ['tags' => ['VIP']]);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['added']);
        $this->assertSame(1, $result['invalid']);

        $detail = $service->subscriberDetail(self::$userId, (int) self::$pdo->query(
            "SELECT id FROM email_subscribers WHERE email = 'imp-a@example.com'"
        )->fetchColumn());

        $this->assertNotNull($detail);
        $this->assertSame('أحمد', $detail['name']);
        $this->assertContains('VIP', array_column($detail['tags'], 'name'));

        $statusB = self::$pdo->query("SELECT status FROM email_subscribers WHERE email = 'imp-b@example.com'")->fetchColumn();
        $this->assertSame('unsubscribed', $statusB);
    }

    public function testExportSubscribers(): void
    {
        $service = $this->service();
        $subId = $this->addSubscriber('exp@example.com', 'تصدير');
        $service->applyTagByName(self::$userId, $subId, 'VIP');

        $rows = $service->exportSubscribers(self::$userId);
        $row = current(array_filter($rows, fn ($r) => $r['email'] === 'exp@example.com'));
        $this->assertNotEmpty($row);
        $this->assertSame('exp@example.com', $row['email']);
    }
}

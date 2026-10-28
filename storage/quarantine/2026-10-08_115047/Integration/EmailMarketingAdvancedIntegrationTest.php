<?php

/**
 * Tourfecto - Email Marketing Advanced Integration Test (المرحلة 4)
 * SMTP settings + Transactional Emails + A/B Testing.
 * بيتخطى تلقائيًا (markTestSkipped) لو DB غير متاحة أو الميجريشن لسه
 * ما اتشغّلش: database/migrations/2026_08_22_000014_email_marketing_advanced.sql
 *
 * بيفحص:
 *   1) SMTP settings: save/get/effective/upsert + test من غير إرسال
 *   2) رسائل المعاملات: CRUD قوالب + slug فريد + send (يفشل اتصال SMTP
 *      لكن يسجّل failed) + سجل + إحصائيات + تتبع فتح/كليك
 *   3) A/B: إنشاء من حملة أساسية + متغيرات + تقسيم الجمهور + تقرير +
 *      إعلان فائز + نسخ للحملة الأساسية
 * @version 1.0.0  @date 2026-08-22
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Models/EmailSmtpSetting.php';
require_once __DIR__ . '/../../app/Models/EmailTransactionalTemplate.php';
require_once __DIR__ . '/../../app/Models/EmailTransactionalLog.php';
require_once __DIR__ . '/../../app/Models/EmailAbTest.php';
require_once __DIR__ . '/../../app/Services/EmailMarketing/SmtpSettingsService.php';
require_once __DIR__ . '/../../app/Services/EmailMarketing/TransactionalEmailService.php';
require_once __DIR__ . '/../../app/Services/EmailMarketing/AbTestService.php';
require_once __DIR__ . '/../../app/Core/Queue/QueueManager.php';
require_once __DIR__ . '/../../app/Jobs/SendAbTestBatchJob.php';

final class EmailMarketingAdvancedIntegrationTest extends TestCase
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
                'EmailSubscriber' => '/Models/EmailSubscriber.php',
                'EmailList' => '/Models/EmailList.php',
                'EmailCampaign' => '/Models/EmailCampaign.php',
                'EmailCampaignRecipient' => '/Models/EmailCampaignRecipient.php',
                'EmailListService' => '/Services/EmailMarketing/EmailListService.php',
                'EmailCampaignService' => '/Services/EmailMarketing/EmailCampaignService.php',
                'EmailRenderer' => '/Services/EmailMarketing/EmailRenderer.php',
                'EmailTrackingService' => '/Services/EmailMarketing/EmailTrackingService.php',
                'Mailer' => '/Services/Mailer.php',
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

            $tables = $conn->query("SHOW TABLES LIKE 'email_ab_tests'")->fetchAll();
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
            $this->markTestSkipped('DB غير متاحة أو ميجريشن advanced لسه ما اتشغّلش');
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
        $abIds = $pdo->query("SELECT id FROM email_ab_tests WHERE user_id = {$uid}")->fetchAll(PDO::FETCH_COLUMN);
        $abList = $abIds ? implode(',', array_map('intval', $abIds)) : '0';
        $variantIds = $pdo->query("SELECT variant_a_id FROM email_ab_tests WHERE user_id = {$uid} UNION SELECT variant_b_id FROM email_ab_tests WHERE user_id = {$uid}")->fetchAll(PDO::FETCH_COLUMN);
        $variantList = $variantIds ? implode(',', array_map('intval', $variantIds)) : '0';
        $pdo->exec("DELETE FROM email_ab_tests WHERE user_id = {$uid}");
        $pdo->exec("DELETE FROM email_transactional_logs WHERE user_id = {$uid}");
        $pdo->exec("DELETE FROM email_transactional_templates WHERE user_id = {$uid}");
        $pdo->exec("DELETE FROM email_smtp_settings WHERE user_id = {$uid}");
        $pdo->exec("DELETE FROM email_campaign_recipients WHERE campaign_id IN ({$variantList})");
        $pdo->exec("DELETE FROM email_campaigns WHERE id IN ({$variantList}) OR user_id = {$uid}");
        $pdo->exec("DELETE FROM email_list_subscriber WHERE subscriber_id IN (SELECT id FROM email_subscribers WHERE user_id = {$uid})");
        $pdo->exec("DELETE FROM email_subscribers WHERE user_id = {$uid}");
        $pdo->exec("DELETE FROM email_lists WHERE user_id = {$uid}");
    }

    private function dbq(string $sql, array $params = []): array
    {
        return Database::getInstance()->query($sql, $params);
    }

    private function dbe(string $sql, array $params = []): void
    {
        Database::getInstance()->exec($sql, $params);
    }

    // ============================ SMTP Settings ============================

    public function testSmtpSettingsSaveGetEffective(): void
    {
        $svc = new SmtpSettingsService();
        $result = $svc->save(self::$userId, [
            'host' => 'smtp.example.com',
            'port' => 587,
            'username' => 'test@example.com',
            'password' => 'secret-pass',
            'encryption' => 'tls',
            'from_email' => 'no-reply@example.com',
            'from_name' => 'شركتي',
            'is_active' => 1,
        ]);
        $this->assertTrue($result['success']);

        $saved = $svc->get(self::$userId);
        $this->assertNotNull($saved);
        $this->assertSame('smtp.example.com', $saved['host']);
        $this->assertSame(587, (int) $saved['port']);
        $this->assertNotSame('secret-pass', $saved['password']); // مشفرة في DB

        $effective = $svc->settingsForUser(self::$userId);
        $this->assertSame('smtp.example.com', $effective['host']);
        $this->assertSame('secret-pass', $effective['password']); // مفكوكة عند الاستخدام
        $this->assertSame('no-reply@example.com', $effective['from_email']);
        $this->assertTrue($svc->isReady(self::$userId));
    }

    public function testSmtpSettingsUpsertAndFallback(): void
    {
        $svc = new SmtpSettingsService();
        $svc->save(self::$userId, [
            'host' => 'smtp.a.com', 'port' => 465, 'username' => 'u', 'password' => 'p', 'encryption' => 'ssl',
        ]);
        // تحديث جزئي
        $svc->save(self::$userId, ['host' => 'smtp.b.com']);
        $saved = $svc->get(self::$userId);
        $this->assertSame('smtp.b.com', $saved['host']);
        $this->assertSame(465, (int) $saved['port']); // لم يتغير
        $this->assertSame('u', $saved['username']);

        // fallback من env لما مفيش host مخصوص
        $this->dbe("DELETE FROM email_smtp_settings WHERE user_id = ?", [self::$userId]);
        $effective = $svc->settingsForUser(self::$userId);
        $this->assertNotEmpty($effective['host']); // بياخد من MAIL_HOST أو ''
    }

    public function testSmtpSettingsValidation(): void
    {
        $svc = new SmtpSettingsService();
        $this->assertFalse($svc->save(self::$userId, ['host' => ''])['success']);
        $this->assertFalse($svc->save(self::$userId, ['host' => 'h', 'username' => ''])['success']);
        $this->assertFalse($svc->save(self::$userId, ['host' => 'h', 'username' => 'u', 'password' => ''])['success']);
    }

    public function testSmtpTestConnectionFailsGracefully(): void
    {
        $svc = new SmtpSettingsService();
        $result = $svc->test(self::$userId, [
            'host' => '127.0.0.1',
            'port' => 1,
            'username' => 'u',
            'password' => 'p',
            'encryption' => '',
        ]);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        // على أي حال ما بيرميش استثناء و بيرجع نتيجة (نجاح أو فشل موثق)
        $this->assertIsBool($result['success']);
    }

    public function testMailerConfigureOverridesEnv(): void
    {
        $mailer = new Mailer();
        $mailer->configure(['host' => 'override.example.com', 'port' => 2525, 'encryption' => 'ssl']);
        $result = $mailer->testConnection();
        // host وهمي => فشل اتصال لكن بدون استثناء
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    // ============================ Transactional Emails ============================

    public function testTransactionalTemplateCrud(): void
    {
        $svc = new TransactionalEmailService();
        $created = $svc->createTemplate(self::$userId, [
            'name' => 'ترحيب',
            'slug' => 'welcome',
            'subject' => 'أهلاً {{first_name}}',
            'html_body' => '<p>مرحباً {{first_name}}</p>',
        ]);
        $this->assertTrue($created['success']);
        $id = (int) $created['id'];

        $fetched = $svc->getTemplate(self::$userId, $id);
        $this->assertNotNull($fetched);
        $this->assertSame('welcome', $fetched['slug']);

        $updated = $svc->updateTemplate(self::$userId, $id, ['subject' => 'موضوع جديد']);
        $this->assertTrue($updated['success']);
        $this->assertSame('موضوع جديد', $svc->getTemplate(self::$userId, $id)['subject']);

        $list = $svc->listTemplates(self::$userId);
        $this->assertNotEmpty($list);
        $this->assertSame('welcome', $list[0]['slug']);

        $this->assertTrue($svc->deleteTemplate(self::$userId, $id)['success']);
        $this->assertNull($svc->getTemplate(self::$userId, $id));
    }

    public function testTransactionalTemplateSlugUnique(): void
    {
        $svc = new TransactionalEmailService();
        $svc->createTemplate(self::$userId, [
            'name' => 'أول', 'slug' => 'dup', 'subject' => 's', 'html_body' => '<p>x</p>',
        ]);
        $result = $svc->createTemplate(self::$userId, [
            'name' => 'ثاني', 'slug' => 'dup', 'subject' => 's2', 'html_body' => '<p>y</p>',
        ]);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('slug', $result['error']);
    }

    public function testTransactionalTemplateForeignRejected(): void
    {
        $svc = new TransactionalEmailService();
        $created = $svc->createTemplate(self::$userId, [
            'name' => 'خاص', 'subject' => 's', 'html_body' => '<p>x</p>',
        ]);
        $id = (int) $created['id'];
        $this->assertNull($svc->getTemplate(999999, $id));
        $this->assertFalse($svc->updateTemplate(999999, $id, ['name' => 'x'])['success']);
        $this->assertFalse($svc->deleteTemplate(999999, $id)['success']);
    }

    public function testTransactionalTemplateDuplicate(): void
    {
        $svc = new TransactionalEmailService();
        $created = $svc->createTemplate(self::$userId, [
            'name' => 'أصلي', 'slug' => 'original-tx', 'subject' => 'أهلاً {{first_name}}', 'html_body' => '<p>مرحبًا {{first_name}}</p>',
        ]);
        $id = (int) $created['id'];

        $dup = $svc->duplicateTemplate(self::$userId, $id);
        $this->assertTrue($dup['success']);
        $copyId = (int) $dup['id'];
        $this->assertGreaterThan(0, $copyId);
        $this->assertNotSame($id, $copyId);

        $copy = $svc->getTemplate(self::$userId, $copyId);
        $this->assertSame('أصلي (نسخة)', $copy['name']);
        $this->assertSame('original-tx-copy', $copy['slug']);
        $this->assertSame('أهلاً {{first_name}}', $copy['subject']);
        $this->assertSame('<p>مرحبًا {{first_name}}</p>', $copy['html_body']);

        $this->assertFalse($svc->duplicateTemplate(999999, $id)['success']);
    }

    public function testSmtpSettingsDeleteAndToggleFallback(): void
    {
        $svc = new SmtpSettingsService();
        $svc->save(self::$userId, [
            'host' => 'smtp.toggle.com', 'port' => 587, 'username' => 'u', 'password' => 'p',
            'encryption' => 'tls', 'is_active' => 1,
        ]);
        // نشط => ياخد إعدادات المستخدم
        $this->assertSame('smtp.toggle.com', $svc->settingsForUser(self::$userId)['host']);

        // تعطيل => يرجع للـ .env العام
        $svc->save(self::$userId, ['is_active' => 0]);
        $effective = $svc->settingsForUser(self::$userId);
        $this->assertNotSame('smtp.toggle.com', $effective['host']);

        // إعادة التفعيل ثم حذف => يرجع للـ env
        $svc->save(self::$userId, ['is_active' => 1]);
        $del = $svc->delete(self::$userId);
        $this->assertTrue($del['success']);
        $this->assertTrue($del['deleted']);
        $this->assertNull($svc->get(self::$userId));

        // الحذف مرة أخرى لا يفشل
        $del2 = $svc->delete(self::$userId);
        $this->assertTrue($del2['success']);
        $this->assertFalse($del2['deleted']);
    }

    public function testTransactionalStatsIncludesRecent(): void
    {
        $svc = new TransactionalEmailService();
        $created = $svc->createTemplate(self::$userId, [
            'name' => 'سجل', 'subject' => 'إحصائية', 'html_body' => '<p>x</p>',
        ]);
        $id = (int) $created['id'];

        // محاكاة سجل فاشل عبر send (بدون SMTP حقيقي) أو إدراج مباشر
        $this->dbe(
            "INSERT INTO email_transactional_logs (user_id, template_id, to_email, subject, status, created_at)
             VALUES (?, ?, 'log@example.com', 'إحصائية', 'failed', NOW())",
            [self::$userId, $id]
        );

        $stats = $svc->stats(self::$userId);
        $this->assertSame(1, (int) $stats['total']);
        $this->assertSame(1, (int) $stats['failed']);
        $this->assertArrayHasKey('recent', $stats);
        $this->assertNotEmpty($stats['recent']);
        $this->assertSame('log@example.com', $stats['recent'][0]['to_email']);
        $this->assertSame('سجل', $stats['recent'][0]['template_name']);
    }

    public function testTransactionalSendLogsFailure(): void
    {
        $svc = new TransactionalEmailService();
        $created = $svc->createTemplate(self::$userId, [
            'name' => 'ترحيب', 'subject' => 'أهلاً {{first_name}}', 'html_body' => '<a href="https://example.com">رابط</a>',
        ]);
        $id = (int) $created['id'];

        // SMTP غير مهيأ فعليًا => الإرسال يفشل لكن يُسجّل في logs بحالة failed
        $result = $svc->send(self::$userId, $id, 'test@example.com', ['first_name' => 'أحمد']);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertGreaterThan(0, (int) $result['id']);

        $logRow = $this->dbq("SELECT * FROM email_transactional_logs WHERE id = ?", [(int) $result['id']])[0];
        $this->assertSame('test@example.com', $logRow['to_email']);
        $this->assertNotEmpty($logRow['subject']);
        $this->assertNotEmpty($logRow['open_token']);
        $this->assertNotEmpty($logRow['click_token']);
    }

    public function testTransactionalSendValidation(): void
    {
        $svc = new TransactionalEmailService();
        $created = $svc->createTemplate(self::$userId, [
            'name' => 'ترحيب', 'subject' => 's', 'html_body' => '<p>x</p>',
        ]);
        $id = (int) $created['id'];

        $bad = $svc->send(self::$userId, $id, 'not-an-email');
        $this->assertFalse($bad['success']);

        $missing = $svc->send(self::$userId, 999999, 'test@example.com');
        $this->assertFalse($missing['success']);
    }

    public function testTransactionalLogsAndStats(): void
    {
        $svc = new TransactionalEmailService();
        $created = $svc->createTemplate(self::$userId, [
            'name' => 'ترحيب', 'subject' => 's', 'html_body' => '<p>x</p>',
        ]);
        $id = (int) $created['id'];
        $svc->send(self::$userId, $id, 'a@example.com', []);
        $svc->send(self::$userId, $id, 'b@example.com', []);

        $logs = $svc->logs(self::$userId);
        $this->assertCount(2, $logs);
        $emails = array_column($logs, 'to_email');
        sort($emails);
        $this->assertSame(['a@example.com', 'b@example.com'], $emails);

        $byTemplate = $svc->logs(self::$userId, ['template_id' => $id]);
        $this->assertCount(2, $byTemplate);

        $stats = $svc->stats(self::$userId);
        $this->assertSame(2, $stats['total']);
        $this->assertSame(2, $stats['sent'] + $stats['failed']);
    }

    public function testTransactionalTrackingOpenAndClick(): void
    {
        $svc = new TransactionalEmailService();
        $created = $svc->createTemplate(self::$userId, [
            'name' => 'ترحيب', 'subject' => 's', 'html_body' => '<a href="https://example.com/target">رابط</a>',
        ]);
        $id = (int) $created['id'];
        $result = $svc->send(self::$userId, $id, 'track@example.com', []);
        $this->assertGreaterThan(0, (int) $result['id']);

        $row = $this->dbq("SELECT * FROM email_transactional_logs WHERE id = ?", [(int) $result['id']])[0];

        $tracking = new EmailTrackingService();
        $this->assertTrue($tracking->recordTransactionalOpen((string) $row['open_token']));
        $this->assertFalse($tracking->recordTransactionalOpen('nonexistent-token'));

        $url = $tracking->recordTransactionalClick((string) $row['click_token'], rtrim(base64_encode('https://example.com/target'), '='));
        $this->assertSame('https://example.com/target', $url);

        $updated = $this->dbq("SELECT * FROM email_transactional_logs WHERE id = ?", [(int) $result['id']])[0];
        $this->assertSame(1, (int) $updated['open_count']);
        $this->assertSame(1, (int) $updated['click_count']);
        $this->assertNotNull($updated['opened_at']);
        $this->assertNotNull($updated['clicked_at']);
    }

    public function testTransactionalRendererNoUnsubscribeFooter(): void
    {
        $renderer = new EmailRenderer();
        $html = $renderer->finalizeTransactional(
            '<p>مرحبا {{first_name}}</p><a href="https://x.com">رابط</a>',
            ['first_name' => 'أحمد', 'email' => 'a@b.com'],
            'open-token',
            'click-token',
            'https://track.example.com'
        );
        $this->assertStringNotContainsString('إلغاء الاشتراك', $html);
        $this->assertStringContainsString('أحمد', $html);
        $this->assertStringContainsString('/api/email-marketing/track/open/open-token.gif', $html);
        $this->assertStringContainsString('/api/email-marketing/track/click/', $html);
        $this->assertStringNotContainsString('{{first_name}}', $html);
    }

    // ============================ A/B Testing ============================

    private function makeCampaign(string $name = 'حملة أساسية'): int
    {
        // قائمة + مشتركين
        $listResult = (new EmailListService())->createList(self::$userId, 'قائمة أ/ب');
        $listId = (int) ($listResult['id'] ?? 0);
        $this->assertGreaterThan(0, $listId, $listResult['error'] ?? 'list creation failed');

        for ($i = 1; $i <= 8; $i++) {
            (new EmailListService())->subscribe(self::$userId, "ab{$i}@example.com", ['first_name' => "مشترك{$i}"], $listId);
        }

        $result = (new EmailCampaignService())->create(self::$userId, [
            'name' => $name,
            'subject' => 'عنوان أساسي',
            'html_body' => '<p>محتوى أساسي</p>',
            'list_id' => $listId,
        ]);
        $this->assertTrue($result['success'], $result['error'] ?? '');
        return (int) $result['id'];
    }

    public function testAbTestCreateGetVariants(): void
    {
        $baseId = $this->makeCampaign();
        $svc = new AbTestService();
        $created = $svc->create(self::$userId, [
            'name' => 'اختبار العنوان',
            'base_campaign_id' => $baseId,
            'split_percent' => 50,
            'metric' => 'open',
        ]);
        $this->assertTrue($created['success'], $created['error'] ?? '');
        $abId = (int) $created['id'];

        $fetched = $svc->get(self::$userId, $abId);
        $this->assertNotNull($fetched);
        $this->assertSame($baseId, (int) $fetched['base_campaign_id']);
        $this->assertGreaterThan(0, (int) $fetched['variant_a_id']);
        $this->assertGreaterThan(0, (int) $fetched['variant_b_id']);
        $this->assertSame('draft', $fetched['status']);

        $list = $svc->list(self::$userId);
        $this->assertNotEmpty($list);
        $this->assertSame('اختبار العنوان', $list[0]['name']);
    }

    public function testAbTestCreateValidation(): void
    {
        $svc = new AbTestService();
        $this->assertFalse($svc->create(self::$userId, ['name' => ''])['success']);
        $this->assertFalse($svc->create(self::$userId, ['name' => 'x', 'base_campaign_id' => 999999])['success']);
        $this->assertFalse($svc->create(self::$userId, ['name' => 'x', 'base_campaign_id' => 999999, 'split_percent' => 200])['success']);
    }

    public function testAbTestVariantContentUpdate(): void
    {
        $baseId = $this->makeCampaign();
        $svc = new AbTestService();
        $created = $svc->create(self::$userId, ['name' => 'اختبار', 'base_campaign_id' => $baseId]);
        $abId = (int) $created['id'];

        $updated = $svc->setVariantContent(self::$userId, $abId, 'a', [
            'subject' => 'عنوان أ',
            'html_body' => '<p>محتوى أ</p>',
        ]);
        $this->assertTrue($updated['success']);

        $fetched = $svc->get(self::$userId, $abId);
        $this->assertSame('عنوان أ', $fetched['variant_a']['subject']);

        // متغير ب يبقى زي الأساس
        $this->assertSame('عنوان أساسي', $fetched['variant_b']['subject']);

        $bad = $svc->setVariantContent(self::$userId, $abId, 'c', ['subject' => 'x']);
        $this->assertFalse($bad['success']);
    }

    public function testAbTestStartSplitsAudience(): void
    {
        $baseId = $this->makeCampaign();
        $svc = new AbTestService();
        $created = $svc->create(self::$userId, ['name' => 'اختبار', 'base_campaign_id' => $baseId, 'split_percent' => 50]);
        $abId = (int) $created['id'];

        $started = $svc->start(self::$userId, $abId);
        $this->assertTrue($started['success'], $started['error'] ?? '');
        $this->assertSame(8, $started['total']);
        $this->assertGreaterThan(0, $started['a']);
        $this->assertGreaterThan(0, $started['b']);

        // المتغيرات بقى ليهم مستلمين pending
        $fetched = $svc->get(self::$userId, $abId);
        $aCount = (int) $this->dbq(
            "SELECT COUNT(*) AS c FROM email_campaign_recipients WHERE campaign_id = ? AND status = 'pending'",
            [(int) $fetched['variant_a_id']]
        )[0]['c'];
        $this->assertSame($started['a'], $aCount);

        // إعادة التشغيل تقسم تاني بنفس الإجمالي
        $started2 = $svc->start(self::$userId, $abId);
        $this->assertTrue($started2['success']);
        $this->assertSame(8, $started2['total']);
    }

    public function testAbTestStartEmptyAudienceFails(): void
    {
        $baseId = $this->makeCampaign();
        $svc = new AbTestService();
        $created = $svc->create(self::$userId, ['name' => 'اختبار', 'base_campaign_id' => $baseId]);
        $abId = (int) $created['id'];

        // فاضي الجمهور
        $campaignService = new EmailCampaignService();
        $campaignService->update(self::$userId, $baseId, ['list_id' => 0, 'audience_ids' => []]);

        $started = $svc->start(self::$userId, $abId);
        $this->assertFalse($started['success']);
        $this->assertStringContainsString('جمهور', $started['error']);
    }

    public function testAbTestSendBatch(): void
    {
        $baseId = $this->makeCampaign();
        $svc = new AbTestService();
        $created = $svc->create(self::$userId, ['name' => 'اختبار', 'base_campaign_id' => $baseId, 'split_percent' => 50]);
        $abId = (int) $created['id'];
        $svc->start(self::$userId, $abId);

        // بدون SMTP => كل المستلمين يفشلوا لكن الدفعة تُعالج
        $result = $svc->sendBatch(self::$userId, $abId);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('processed', $result);
        $this->assertArrayHasKey('failed', $result);
        $this->assertSame(8, $result['processed'] + $result['failed']);
    }

    public function testAbTestSendBatchRequiresRunning(): void
    {
        $baseId = $this->makeCampaign();
        $svc = new AbTestService();
        $created = $svc->create(self::$userId, ['name' => 'اختبار', 'base_campaign_id' => $baseId]);
        $abId = (int) $created['id'];

        $result = $svc->sendBatch(self::$userId, $abId);
        $this->assertFalse($result['remaining']);
        $this->assertStringContainsString('قيد التشغيل', $result['error']);
    }

    public function testAbTestSendTestValidatesAndFailsGracefully(): void
    {
        $baseId = $this->makeCampaign();
        $svc = new AbTestService();
        $created = $svc->create(self::$userId, ['name' => 'اختبار', 'base_campaign_id' => $baseId, 'split_percent' => 50]);
        $abId = (int) $created['id'];

        // بريد غير صالح
        $bad = $svc->sendTest(self::$userId, $abId, 'all', 'not-an-email');
        $this->assertFalse($bad['success']);
        $this->assertStringContainsString('غير صالح', $bad['error']);

        // متغير غير صالح
        $badVar = $svc->sendTest(self::$userId, $abId, 'z', 'test@example.com');
        $this->assertFalse($badVar['success']);

        // غير موجود
        $missing = $svc->sendTest(self::$userId, 99999999, 'a', 'test@example.com');
        $this->assertFalse($missing['success']);
        $this->assertStringContainsString('غير موجود', $missing['error']);

        // بدون SMTP => يحاول الإرسال ويفشل بأمان (لا يرمي استثناء)
        $result = $svc->sendTest(self::$userId, $abId, 'all', 'test@example.com');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('sent', $result);
        $this->assertArrayHasKey('a', $result['sent']);
        $this->assertArrayHasKey('b', $result['sent']);
    }

    public function testAbTestSendBatchJobRunsViaQueue(): void
    {
        if (!class_exists('QueueManager')) {
            $this->markTestSkipped('QueueManager غير متاح');
            return;
        }
        $queue = new QueueManager();
        if (!$queue->isReady()) {
            $this->markTestSkipped('جدول jobs غير موجود');
            return;
        }

        $baseId = $this->makeCampaign();
        $svc = new AbTestService();
        $created = $svc->create(self::$userId, ['name' => 'اختبار بالطابور', 'base_campaign_id' => $baseId, 'split_percent' => 50]);
        $abId = (int) $created['id'];
        $svc->start(self::$userId, $abId);

        $this->dbe("DELETE FROM jobs");

        $jobId = $queue->push(SendAbTestBatchJob::class, [
            'user_id' => self::$userId,
            'ab_test_id' => $abId,
        ], 'email');
        $this->assertNotFalse($jobId);

        $result = $queue->processDue(20);
        $this->assertSame(1, $result['processed']);

        $row = $this->dbq("SELECT * FROM jobs WHERE id = ?", [(int) $jobId]);
        $this->assertSame('completed', $row[0]['status'] ?? '');

        // بدون SMTP => كل المستلمين اتعالجوا (فشل فردي) وعددهم 8
        $ab = (new EmailAbTest())->find($abId);
        $total = (int) $this->dbq(
            "SELECT COUNT(*) AS total FROM email_campaign_recipients WHERE campaign_id IN (?, ?)",
            [(int) $ab->getAttribute('variant_a_id'), (int) $ab->getAttribute('variant_b_id')]
        )[0]['total'];
        $this->assertSame(8, $total);

        $this->dbe("DELETE FROM jobs");
    }

    public function testAbTestReportAndWinner(): void
    {
        $baseId = $this->makeCampaign();
        $svc = new AbTestService();
        $created = $svc->create(self::$userId, ['name' => 'اختبار', 'base_campaign_id' => $baseId]);
        $abId = (int) $created['id'];
        $fetched = $svc->get(self::$userId, $abId);

        // محاكاة تفاعل: المتغير أ عنده فتحات أكتر
        $this->dbe(
            "UPDATE email_campaign_recipients SET status = 'sent', sent_at = NOW() WHERE campaign_id = ?",
            [(int) $fetched['variant_a_id']]
        );
        $this->dbe(
            "UPDATE email_campaign_recipients SET status = 'sent', sent_at = NOW() WHERE campaign_id = ?",
            [(int) $fetched['variant_b_id']]
        );
        // recompute العدادات
        $tracking = new EmailTrackingService();
        $tracking->recomputeCampaignCounts((int) $fetched['variant_a_id']);
        $tracking->recomputeCampaignCounts((int) $fetched['variant_b_id']);

        $report = $svc->report(self::$userId, $abId);
        $this->assertNotNull($report);
        $this->assertArrayHasKey('variant_a', $report);
        $this->assertArrayHasKey('variant_b', $report);
        $this->assertArrayHasKey('winner', $report);

        // إعلان فائز
        $declared = $svc->declareWinner(self::$userId, $abId, 'a');
        $this->assertTrue($declared['success']);
        $after = $svc->get(self::$userId, $abId);
        $this->assertSame('a', $after['winner']);
        $this->assertSame('finished', $after['status']);
    }

    public function testAbTestApplyWinnerToBase(): void
    {
        $baseId = $this->makeCampaign();
        $svc = new AbTestService();
        $created = $svc->create(self::$userId, ['name' => 'اختبار', 'base_campaign_id' => $baseId]);
        $abId = (int) $created['id'];

        $svc->setVariantContent(self::$userId, $abId, 'a', [
            'subject' => 'عنوان الفائز',
            'html_body' => '<p>محتوى الفائز</p>',
        ]);
        $svc->declareWinner(self::$userId, $abId, 'a');

        $applied = $svc->applyWinnerToBase(self::$userId, $abId);
        $this->assertTrue($applied['success']);

        $base = (new EmailCampaign())->find($baseId);
        $this->assertSame('عنوان الفائز', $base->getAttribute('subject'));
        $this->assertSame('<p>محتوى الفائز</p>', $base->getAttribute('html_body'));
    }

    public function testAbTestForeignRejected(): void
    {
        $baseId = $this->makeCampaign();
        $svc = new AbTestService();
        $created = $svc->create(self::$userId, ['name' => 'اختبار', 'base_campaign_id' => $baseId]);
        $abId = (int) $created['id'];

        $this->assertNull($svc->get(999999, $abId));
        $this->assertFalse($svc->delete(999999, $abId)['success']);
        $this->assertFalse($svc->start(999999, $abId)['success']);
        $this->assertFalse($svc->setVariantContent(999999, $abId, 'a', ['subject' => 'x'])['success']);
        $this->assertNull($svc->report(999999, $abId));
    }

    public function testAbTestDeleteDraftAllowed(): void
    {
        $baseId = $this->makeCampaign();
        $svc = new AbTestService();
        $created = $svc->create(self::$userId, ['name' => 'اختبار', 'base_campaign_id' => $baseId]);
        $abId = (int) $created['id'];

        $this->assertTrue($svc->delete(self::$userId, $abId)['success']);
        $this->assertNull($svc->get(self::$userId, $abId));
    }

    public function testRetryFailedCampaignResetsRecipients(): void
    {
        $campaignId = $this->makeCampaign();
        $svc = new EmailCampaignService();

        $prepared = $svc->prepareRecipients(self::$userId, $campaignId);
        $this->assertTrue($prepared['success'], $prepared['error'] ?? '');
        $this->assertSame(8, $prepared['total']);

        // محاكاة فشل كامل: كل المستلمين failed + الحملة failed
        $this->dbe(
            "UPDATE email_campaign_recipients SET status = 'failed', error_message = 'SMTP timeout' WHERE campaign_id = ?",
            [$campaignId]
        );
        $this->dbe("UPDATE email_campaigns SET status = 'failed' WHERE id = ?", [$campaignId]);

        // الرفض لحملة غير فاشلة
        $this->dbe("UPDATE email_campaigns SET status = 'draft' WHERE id = ?", [$campaignId]);
        $this->assertFalse($svc->retryFailed(self::$userId, $campaignId)['success']);
        $this->dbe("UPDATE email_campaigns SET status = 'failed' WHERE id = ?", [$campaignId]);

        $result = $svc->retryFailed(self::$userId, $campaignId);
        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame(8, $result['reset']);

        $pending = (int) $this->dbq(
            "SELECT COUNT(*) AS total FROM email_campaign_recipients WHERE campaign_id = ? AND status = 'pending'",
            [$campaignId]
        )[0]['total'];
        $this->assertSame(8, $pending);

        $campaign = $svc->get(self::$userId, $campaignId);
        $this->assertSame('sending', $campaign['status']);

        // حملة غير موجودة
        $this->assertFalse($svc->retryFailed(self::$userId, 99999999)['success']);
    }

    public function testRetryFailedRejectsWhenNoFailedRecipients(): void
    {
        $campaignId = $this->makeCampaign();
        $svc = new EmailCampaignService();

        $prepared = $svc->prepareRecipients(self::$userId, $campaignId);
        $this->assertTrue($prepared['success']);
        $this->dbe("UPDATE email_campaigns SET status = 'failed' WHERE id = ?", [$campaignId]);

        $result = $svc->retryFailed(self::$userId, $campaignId);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('لا يوجد', $result['error']);
    }
}

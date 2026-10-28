<?php

/**
 * Tourfecto - Email Marketing Template Studio Integration Test (المرحلة 2)
 * بيتخطى تلقائيًا (markTestSkipped) لو DB غير متاحة أو الميجريشن لسه
 * ما اتشغّلش: database/migrations/2026_08_22_000012_email_marketing_template_studio.sql
 *
 * بيفحص:
 *   1) المعرض المدمج (catalog + categories + blockTypes)
 *   2) تحويل البلوكات إلى HTML (blocksToHtml)
 *   3) إنشاء قالب من المعرض (createFromCatalog)
 *   4) نسخ القوالب (duplicateTemplate)
 *   5) المشاركة العامة (setShared/byShareToken/importShared)
 *   6) نسخ الحملات (duplicateCampaign)
 * @version 1.0.0  @date 2026-08-22
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Models/EmailTemplate.php';
require_once __DIR__ . '/../../app/Models/EmailCampaign.php';
require_once __DIR__ . '/../../app/Services/EmailMarketing/EmailTemplateEditorService.php';

final class EmailMarketingTemplateStudioIntegrationTest extends TestCase
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

            $tables = $conn->query("SHOW TABLES LIKE 'email_templates'")->fetchAll();
            if (empty($tables)) {
                self::$pdo = null;
                return null;
            }
            // عمود blocks (المرحلة 2)
            $cols = $conn->query("SHOW COLUMNS FROM email_templates LIKE 'blocks'")->fetchAll();
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
            $this->markTestSkipped('DB غير متاحة أو ميجريشن template studio لسه ما اتشغّلش');
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
        $pdo->exec("DELETE FROM email_templates WHERE user_id = {$uid}");
        $pdo->exec("DELETE FROM email_campaigns WHERE user_id = {$uid}");
    }

    private function service(): EmailTemplateEditorService
    {
        return new EmailTemplateEditorService();
    }

    public function testCatalogHasTemplatesAndCategories(): void
    {
        $service = $this->service();
        $catalog = $service->catalog();
        $this->assertGreaterThanOrEqual(6, count($catalog));
        foreach ($catalog as $key => $item) {
            $this->assertNotEmpty($item['name']);
            $this->assertNotEmpty($item['subject']);
            $this->assertNotEmpty($item['blocks']);
            $this->assertNotEmpty($item['category']);
        }
        $this->assertArrayHasKey('welcome', $service->categories());
        $this->assertGreaterThanOrEqual(6, count($service->blockTypes()));
    }

    public function testBlocksToHtmlRendersAllBlockTypes(): void
    {
        $service = $this->service();
        $html = $service->blocksToHtml([
            ['type' => 'heading', 'text' => 'العنوان', 'level' => 'h1', 'align' => 'center'],
            ['type' => 'text', 'content' => '<p>نص تجريبي</p>'],
            ['type' => 'button', 'text' => 'زر', 'url' => 'https://example.com', 'bg' => '#2563eb', 'color' => '#ffffff'],
            ['type' => 'divider', 'color' => '#e5e7eb', 'thickness' => '1'],
            ['type' => 'spacer', 'height' => '30'],
            ['type' => 'social', 'networks' => ['facebook', 'twitter']],
            ['type' => 'html', 'html' => '<div>كود</div>'],
        ]);
        $this->assertStringContainsString('العنوان', $html);
        $this->assertStringContainsString('نص تجريبي', $html);
        $this->assertStringContainsString('https://example.com', $html);
        $this->assertStringContainsString('facebook.com', $html);
        $this->assertStringContainsString('<html', $html);
    }

    public function testImageBlockRendersPlaceholderWhenNoSrc(): void
    {
        $service = $this->service();
        $html = $service->blocksToHtml([['type' => 'image', 'src' => '', 'alt' => '', 'width' => '600', 'url' => '']]);
        $this->assertStringContainsString('أضف رابط صورة', $html);
    }

    public function testCreateFromCatalogSavesBlocksAndHtml(): void
    {
        $service = $this->service();
        $result = $service->createFromCatalog(self::$userId, 'welcome');
        $this->assertTrue($result['success']);
        $template = (new EmailTemplate())->find((int) $result['id']);
        $this->assertNotNull($template);
        $this->assertSame('welcome', $template->getAttribute('category'));
        $this->assertNotEmpty($template->getAttribute('blocks'));
        $this->assertNotEmpty($template->getAttribute('html_body'));

        $blocks = json_decode((string) $template->getAttribute('blocks'), true);
        $this->assertGreaterThanOrEqual(4, count($blocks));
    }

    public function testCreateFromCatalogInvalidKey(): void
    {
        $result = $this->service()->createFromCatalog(self::$userId, 'nope');
        $this->assertFalse($result['success']);
    }

    public function testDuplicateTemplate(): void
    {
        $service = $this->service();
        $orig = $service->createFromCatalog(self::$userId, 'promo');
        $dup = $service->duplicateTemplate(self::$userId, (int) $orig['id']);
        $this->assertTrue($dup['success']);
        $this->assertNotSame($orig['id'], $dup['id']);

        $copy = (new EmailTemplate())->find((int) $dup['id']);
        $this->assertStringContainsString('(نسخة)', (string) $copy->getAttribute('name'));
        $this->assertSame((string) (new EmailTemplate())->find((int) $orig['id'])->getAttribute('blocks'), (string) $copy->getAttribute('blocks'));

        // ملكية أجنبية مرفوضة
        $foreign = $service->duplicateTemplate(999999, (int) $orig['id']);
        $this->assertFalse($foreign['success']);
    }

    public function testShareAndImportSharedTemplate(): void
    {
        $service = $this->service();
        $orig = $service->createFromCatalog(self::$userId, 'event');
        $shared = $service->setShared(self::$userId, (int) $orig['id'], true);
        $this->assertTrue($shared['success']);
        $this->assertNotEmpty($shared['share_token']);

        // الجلب العام بدون ملكية
        $fetched = $service->byShareToken((string) $shared['share_token']);
        $this->assertNotNull($fetched);
        $this->assertSame('event', $fetched['category']);
        $this->assertNotEmpty($fetched['blocks']);

        // توكن غير صالح
        $this->assertNull($service->byShareToken('invalid-token'));

        // استيراد إلى مستخدم آخر
        $imported = $service->importShared(self::$userId, (string) $shared['share_token']);
        $this->assertTrue($imported['success']);
        $importedTemplate = (new EmailTemplate())->find((int) $imported['id']);
        $this->assertSame('event', $importedTemplate->getAttribute('category'));

        // إيقاف المشاركة
        $stopped = $service->setShared(self::$userId, (int) $orig['id'], false);
        $this->assertTrue($stopped['success']);
        $this->assertNull($stopped['share_token']);
        $this->assertNull($service->byShareToken((string) $shared['share_token']));
    }

    public function testShareForeignTemplateRejected(): void
    {
        $service = $this->service();
        $orig = $service->createFromCatalog(self::$userId, 'newsletter');
        $result = $service->setShared(999999, (int) $orig['id'], true);
        $this->assertFalse($result['success']);
    }

    public function testDuplicateCampaign(): void
    {
        $service = $this->service();
        $campaign = new EmailCampaign([
            'user_id' => self::$userId,
            'name' => 'حملة أصلية',
            'subject' => 'موضوع',
            'html_body' => '<p>x</p>',
            'status' => 'draft',
        ]);
        $campaign->save();
        $id = (int) $campaign->getAttribute('id');

        $dup = $service->duplicateCampaign(self::$userId, $id);
        $this->assertTrue($dup['success']);
        $copy = (new EmailCampaign())->find((int) $dup['id']);
        $this->assertStringContainsString('(نسخة)', (string) $copy->getAttribute('name'));
        $this->assertSame('draft', $copy->getAttribute('status'));

        // حملة مُرسلة لا تُنسخ
        $sent = new EmailCampaign([
            'user_id' => self::$userId,
            'name' => 'مُرسلة',
            'subject' => 'موضوع',
            'html_body' => '<p>x</p>',
            'status' => 'sent',
        ]);
        $sent->save();
        $this->assertFalse($service->duplicateCampaign(self::$userId, (int) $sent->getAttribute('id'))['success']);
    }
}

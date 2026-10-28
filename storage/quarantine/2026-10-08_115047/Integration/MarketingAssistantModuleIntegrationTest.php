<?php

/**
 * Tourfecto - Marketing Assistant (Module 6) Integration Test
 * بيفحص خدمة مساعد التسويق الذكي `MarketingAssistantService` بمصادر بيانات
 * حقيقية في `tourfecto_test`:
 *   1) الأدوات الست المتاحة (ad_copy/slogan/email_subject/social_bio/
 *      product_description/campaign_ideas).
 *   2) `run()`: بناء البرومبت من القالب + النداء على محرك AI وهمي (صفر
 *      شبكة) + حفظ تفاعل `ai_assistant_interactions` فعلًا + تسجيل
 *      `activity_logs`.
 *   3) فشل AI (ناتج "خطأ: ..." محفوظ بلا throw)، أداة غير معروفة
 *      (InvalidArgumentException بلا كتابة)، اقتطاع العنوان لـ 100 حرف.
 *   4) التاريخ: استعلام AIAssistantInteraction بالترتيب التنازلي.
 *   5) ربط مع Action Center (الموديول 5): ناتج Marketing Assistant بيظهر
 *      كعنصر `marketing` في `getActionItems`.
 *
 * صفر شبكة/AI حقيقية. معرّفات معزولة: المستخدم 999900.
 * @version 1.0.0  @date 2026-08-31
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Core/Model.php';
require_once __DIR__ . '/../../app/Core/Logger.php';
require_once __DIR__ . '/../../app/Models/ActivityLog.php';
require_once __DIR__ . '/../../app/Models/AIAssistantInteraction.php';
require_once __DIR__ . '/../../app/Services/AI/GeminiClient.php';
require_once __DIR__ . '/../../app/Services/MarketingAssistant/MarketingAssistantService.php';
require_once __DIR__ . '/../../app/Services/ActionCenter/ActionCenterService.php';

final class MarketingFakeGemini extends GeminiClient
{
    public array $calls = [];
    private array $result;

    public function __construct(array $result = ['success' => true, 'data' => 'محتوى جاهز', 'provider' => 'gemini'])
    {
        $this->result = $result;
    }

    public function setResult(array $r): void
    {
        $this->result = $r;
    }

    public function generateContent(string $prompt, array $options = []): array
    {
        $this->calls[] = ['prompt' => $prompt, 'options' => $options];
        return $this->result;
    }
}

final class MarketingAssistantModuleIntegrationTest extends TestCase
{
    private const USER = 999900;

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
                    $app . '/Config/gemini.php',
                    $app . '/Config/encryption.php',
                    $app . '/Config/constants.php',
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

            foreach (['ai_assistant_interactions', 'activity_logs'] as $t) {
                if (empty($conn->query("SHOW TABLES LIKE '{$t}'")->fetchAll())) {
                    self::$pdo = null;
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
            $this->markTestSkipped('DB غير متاحة أو جداول مساعد التسويق غير موجودة');
        }
        $this->cleanup();

        $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                    VALUES (999900, 'marketing@tourfecto.test', 'x', 'Marketing User', NOW())
                    ON DUPLICATE KEY UPDATE email = email");
    }

    protected function tearDown(): void
    {
        $pdo = self::$pdo;
        if ($pdo === null) {
            return;
        }
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $pdo = self::$pdo;
        $u = 999900;

        $pdo->exec("DELETE FROM activity_logs WHERE user_id = {$u}");
        $pdo->exec("DELETE FROM ai_assistant_interactions WHERE user_id = {$u}");
        $pdo->exec("DELETE FROM users WHERE id = {$u}");
    }

    private function dbInstance(): Database
    {
        return Database::getInstance();
    }

    private function countInteractions(): int
    {
        $rows = self::$pdo->query("SELECT COUNT(*) AS c FROM ai_assistant_interactions WHERE user_id = 999900")->fetchAll(PDO::FETCH_ASSOC);
        return (int) ($rows[0]['c'] ?? 0);
    }

    private function lastInteraction(): array
    {
        $rows = self::$pdo->query("SELECT * FROM ai_assistant_interactions WHERE user_id = 999900 ORDER BY id DESC LIMIT 1")->fetchAll(PDO::FETCH_ASSOC);
        return $rows[0] ?? [];
    }

    // ================================================================
    // الأدوات المتاحة
    // ================================================================

    public function testAvailableToolsReturnsAllSix(): void
    {
        $tools = (new MarketingAssistantService())->availableTools();

        $this->assertCount(6, $tools);
        $this->assertContains('ad_copy', $tools);
        $this->assertContains('slogan', $tools);
        $this->assertContains('email_subject', $tools);
        $this->assertContains('social_bio', $tools);
        $this->assertContains('product_description', $tools);
        $this->assertContains('campaign_ideas', $tools);
    }

    // ================================================================
    // run(): نجاح + حفظ + برومبت
    // ================================================================

    public function testRunSuccessPersistsInteraction(): void
    {
        $fake = new MarketingFakeGemini(['success' => true, 'data' => 'نص إعلان جاهز']);
        $service = new MarketingAssistantService($fake);

        $interaction = $service->run(999900, 'ad_copy', 'رحلة النيل');

        $this->assertSame('نص إعلان جاهز', $interaction->getAttribute('output'));
        $this->assertSame(999900, (int) $interaction->getAttribute('user_id'));
        $this->assertSame('ad_copy', $interaction->getAttribute('type'));
        $this->assertNotEmpty($interaction->getAttribute('id'));
        $this->assertSame(1, $this->countInteractions());

        $row = $this->lastInteraction();
        $this->assertSame('ad_copy', $row['type']);
        $this->assertSame('رحلة النيل', $row['title']);
        $this->assertSame('نص إعلان جاهز', $row['output']);
        $this->assertStringContainsString('رحلة النيل', $row['input_payload']);
    }

    public function testRunBuildsPromptFromToolTemplate(): void
    {
        $fake = new MarketingFakeGemini();
        $service = new MarketingAssistantService($fake);

        $service->run(999900, 'campaign_ideas', 'أفكار حملة رمضان');

        $this->assertCount(1, $fake->calls);
        $this->assertStringContainsString('أفكار حملة رمضان', $fake->calls[0]['prompt']);
        $this->assertStringContainsString('5 أفكار حملة', $fake->calls[0]['prompt']);
        $this->assertSame(1024, $fake->calls[0]['options']['maxOutputTokens']);
    }

    public function testRunAllToolsProduceRecordedRows(): void
    {
        $fake = new MarketingFakeGemini(['success' => true, 'data' => 'ناتج']);
        $service = new MarketingAssistantService($fake);

        foreach (['ad_copy', 'slogan', 'email_subject', 'social_bio', 'product_description', 'campaign_ideas'] as $tool) {
            $service->run(999900, $tool, 'باقة سياحية');
        }

        $this->assertSame(6, $this->countInteractions());
        $rows = self::$pdo->query("SELECT type FROM ai_assistant_interactions WHERE user_id = 999900")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame(['ad_copy', 'slogan', 'email_subject', 'social_bio', 'product_description', 'campaign_ideas'], array_column($rows, 'type'));
    }

    // ================================================================
    // run(): فشل AI + أداة غير معروفة + اقتطاع العنوان
    // ================================================================

    public function testRunAiFailurePersistsErrorOutput(): void
    {
        $fake = new MarketingFakeGemini(['success' => false, 'error' => 'انقطع الاتصال']);
        $service = new MarketingAssistantService($fake);

        $interaction = $service->run(999900, 'slogan', 'فندق');

        $this->assertStringStartsWith('خطأ: ', (string) $interaction->getAttribute('output'));
        $this->assertStringContainsString('انقطع الاتصال', (string) $interaction->getAttribute('output'));
        $this->assertSame(1, $this->countInteractions());
    }

    public function testRunUnknownToolThrowsAndWritesNothing(): void
    {
        $fake = new MarketingFakeGemini();
        $service = new MarketingAssistantService($fake);

        try {
            $service->run(999900, 'not_a_tool', 'x');
            $this->fail('يجب أن يرمي InvalidArgumentException لأداة غير معروفة');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('أداة غير معروفة', $e->getMessage());
        }

        $this->assertSame(0, $this->countInteractions());
        $this->assertCount(0, $fake->calls, 'لا يجب استدعاء محرك AI لأداة غير معروفة');
    }

    public function testRunTruncatesTitleTo100Chars(): void
    {
        $fake = new MarketingFakeGemini(['success' => true, 'data' => 'x']);
        $service = new MarketingAssistantService($fake);

        $longInput = str_repeat('ح', 250);
        $service->run(999900, 'ad_copy', $longInput);

        $row = $this->lastInteraction();
        $this->assertSame(100, mb_strlen($row['title']));
    }

    // ================================================================
    // السجل + ActivityLog + الربط مع Action Center
    // ================================================================

    public function testHistoryReturnsInteractionsNewestFirst(): void
    {
        $fake = new MarketingFakeGemini(['success' => true, 'data' => 'ناتج']);
        $service = new MarketingAssistantService($fake);

        $service->run(999900, 'ad_copy', 'أول طلب');
        $service->run(999900, 'slogan', 'ثاني طلب');

        $items = (new AIAssistantInteraction())->where(['user_id' => 999900], ['created_at' => 'DESC'], 10);

        $this->assertCount(2, $items);
        $this->assertSame('slogan', $items[0]->getAttribute('type'));
        $this->assertSame('ad_copy', $items[1]->getAttribute('type'));
    }

    public function testRunRecordsActivityLog(): void
    {
        $fake = new MarketingFakeGemini(['success' => true, 'data' => 'ناتج']);
        $service = new MarketingAssistantService($fake);

        $interaction = $service->run(999900, 'email_subject', 'عرض الصيف');

        $rows = self::$pdo->query(
            "SELECT * FROM activity_logs WHERE user_id = 999900 AND module = 'marketing_assistant' AND action = 'tool.used'"
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $rows);
        $this->assertSame('ai_assistant_interactions', $rows[0]['subject_type']);
        $this->assertSame((string) $interaction->getAttribute('id'), (string) $rows[0]['subject_id']);
        $this->assertStringContainsString('email_subject', (string) $rows[0]['meta']);
    }

    public function testActionCenterIngestsMarketingOutput(): void
    {
        $fake = new MarketingFakeGemini(['success' => true, 'data' => 'نص جاهز للحملة']);
        $service = new MarketingAssistantService($fake);
        $service->run(999900, 'ad_copy', 'حملة رأس السنة');

        $items = (new ActionCenterService())->getActionItems($this->dbInstance(), 999900);

        $marketing = array_values(array_filter($items, fn ($i) => ($i['source'] ?? '') === 'marketing'));

        $this->assertCount(1, $marketing);
        $this->assertSame('marketing_output', $marketing[0]['action_type']);
        $this->assertStringContainsString('نفّذ المحتوى التسويقي: حملة رأس السنة', $marketing[0]['title']);
        $this->assertStringContainsString('نص جاهز للحملة', $marketing[0]['description']);
    }
}

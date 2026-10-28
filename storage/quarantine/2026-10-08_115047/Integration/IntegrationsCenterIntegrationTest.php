<?php

/**
 * Tourfecto - Integrations Center Integration Test
 *
 * بيفحص وحدة التكاملات الخارجية (IntegrationsController + IntegrationManager
 * + BaseIntegrationService::conf):
 *   1) Registry فيه كل التكاملات المتوقعة (slack/zapier/hubspot/algolia/
 *      calendly/zoom/mixpanel/onesignal + AI/Google/Meta/Stripe).
 *   2) conf() في BaseIntegrationService بتقرأ من system_settings الأول
 *      (المسار اللي اتخزّن فيه من لوحة الأدمن) ثم env كـ fallback.
 *   3) IntegrationManager::isConfigured() بيعكس الحالة بشكل صحيح (مفاتيح
 *      ناقصة = غير مُهيأ، وبعد الحفظ في system_settings = مُهيأ).
 *   4) التحقق من الأدمن في IntegrationsController (رفض غير الأدمن).
 *
 * بيتخطى تلقائيًا لو قاعدة البيانات غير متاحة.
 *
 * @version 1.0.0
 * @date 2026-08-24
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/System/SystemSettingsService.php';

final class IntegrationsCenterIntegrationTest extends TestCase
{
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
                    $app . '/Config/encryption.php',
                ] as $cfg) {
                    if (file_exists($cfg)) {
                        require_once $cfg;
                    }
                }
            }
            foreach ([
                $app . '/Core/Database.php',
                $app . '/Core/Logger.php',
                $app . '/Core/Encryption.php',
                $app . '/Core/Contracts/IntegrationInterface.php',
                $app . '/Core/IntegrationManager.php',
                $app . '/Services/Integrations/BaseIntegrationService.php',
            ] as $req) {
                if (!class_exists(basename($req, '.php')) && !interface_exists(basename($req, '.php')) && file_exists($req)) {
                    require_once $req;
                }
            }

            $db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $conn = $ref->getValue($db);
            if (!$conn instanceof PDO) {
                throw new RuntimeException('no active PDO connection');
            }
            self::$pdo = $conn;
        } catch (Throwable $e) {
            self::$pdo = null;
        }
        return self::$pdo;
    }

    private function requireDb(): PDO
    {
        $pdo = $this->db();
        if ($pdo === null) {
            $this->markTestSkipped('MySQL غير متاح في هذه البيئة - شغّل الاختبار على سيرفر بقاعدة بيانات حقيقية.');
        }
        return $pdo;
    }

    public function testRegistryContainsExternalIntegrations(): void
    {
        $this->requireDb();
        if (!class_exists('IntegrationManager')) {
            $this->markTestSkipped('IntegrationManager غير محمّل في هذه البيئة.');
        }

        $all = IntegrationManager::all();
        $keys = array_keys($all);

        // كل الخدمات الخارجية اللي كانت "يتيمة" لازم تكون مسجلة في registry
        foreach (['slack', 'zapier', 'hubspot', 'algolia', 'calendly', 'zoom', 'mixpanel', 'onesignal'] as $expected) {
            $this->assertContains($expected, $keys, "التكامل {$expected} غير مسجّل في integrations registry");
            $this->assertNotEmpty($all[$expected]['class'], "التكامل {$expected} بدون class");
            $this->assertNotEmpty($all[$expected]['env_keys'], "التكامل {$expected} بدون env_keys");
        }
    }

    public function testConfReadsFromSystemSettingsFirst(): void
    {
        $pdo = $this->requireDb();
        if (!class_exists('BaseIntegrationService')) {
            $this->markTestSkipped('BaseIntegrationService غير محمّل في هذه البيئة.');
        }

        // إعداد تجريبي يُحفظ في system_settings
        $settings = new SystemSettingsService();
        $key = 'integration_test_probe_key';
        $pdo->exec("DELETE FROM system_settings WHERE setting_key = '{$key}'");
        $settings->set('integration_test_probe_key', 'db_value_xyz');

        // كلاس تجريبي صغير بيورّث BaseIntegrationService ويستخدم conf()
        $probe = new class () extends BaseIntegrationService {
            public function key(): string
            {
                return 'probe';
            }
            public function isConfigured(): bool
            {
                return true;
            }
            public function request(string $action, array $params = [], array $context = []): array
            {
                return ['success' => true];
            }
            public function read(string $const, string $env): string
            {
                return $this->conf($const, $env);
            }
        };

        $this->assertSame('db_value_xyz', $probe->read('', 'TEST_PROBE_KEY'));

        // بعد المسح، يرجع للـ env/فاضي
        $pdo->exec("DELETE FROM system_settings WHERE setting_key = '{$key}'");
        $this->assertSame('', $probe->read('', 'TEST_PROBE_KEY'));
    }

    public function testIsConfiguredReflectsSavedSettings(): void
    {
        $pdo = $this->requireDb();
        if (!class_exists('IntegrationManager')) {
            $this->markTestSkipped('IntegrationManager غير محمّل في هذه البيئة.');
        }

        $settings = new SystemSettingsService();
        // نظافة: نشيل أي قيمة قديمة
        $pdo->exec("DELETE FROM system_settings WHERE setting_key LIKE 'integration_slack_%'");

        // نقيّم الحالة الافتراضية مع مراعاة إن الـ enabled gate (SLACK_ENABLED)
        // ممكن يكون واخد قيمته من env - لو كذلك بنتخطى التنظيف وبنكتفي
        // بالتأكد إن الحالة متوافقة مع وجود/غياب المفتاح.
        $envToken = defined('SLACK_BOT_TOKEN') ? constant('SLACK_BOT_TOKEN') : getenv('SLACK_BOT_TOKEN');
        $envToken = $envToken ?: '';
        $envEnabled = defined('SLACK_ENABLED') ? constant('SLACK_ENABLED') : getenv('SLACK_ENABLED');
        $envEnabled = filter_var($envEnabled, FILTER_VALIDATE_BOOLEAN);

        if ($envToken !== '' && $envEnabled) {
            // slack مُهيأ بالفعل في env - المهم إن الـ status بيرجّع true
            $this->assertTrue(IntegrationManager::isConfigured('slack'));
            return;
        }

        // slack غير مُهيأ في env: المفروض الحالة تكون false، وبعد الحفظ
        // في system_settings تبقى true (المفتاح + بوابة التفعيل).
        $this->assertFalse(IntegrationManager::isConfigured('slack'), 'slack مفترض غير مُهيأ قبل الحفظ');

        $settings->set('integration_slack_bot_token', 'xoxb-test-token');
        $settings->set('integration_slack_enabled', '1');

        $this->assertTrue(IntegrationManager::isConfigured('slack'), 'بعد حفظ المفتاح والتفعيل في system_settings، slack لازم يبقى مُهيأ');

        // تنظيف
        $pdo->exec("DELETE FROM system_settings WHERE setting_key = 'integration_slack_bot_token'");
        $pdo->exec("DELETE FROM system_settings WHERE setting_key = 'integration_slack_enabled'");
    }

    public function testControllerRejectsNonAdmin(): void
    {
        $this->requireDb();
        foreach ([
            'app/Controllers/Controller.php',
            'app/Controllers/IntegrationsController.php',
        ] as $req) {
            $path = dirname(__DIR__, 2) . '/' . $req;
            if (!class_exists('IntegrationsController') && file_exists($path)) {
                require_once $path;
            }
        }
        if (!class_exists('IntegrationsController')) {
            $this->markTestSkipped('IntegrationsController غير محمّل في هذه البيئة.');
        }

        $controller = new IntegrationsController();
        // استدعاء index بدون مصادقة - لازم يرفض
        $result = $controller->index([]);
        $this->assertFalse($result['success'] ?? true);
        $this->assertEquals(403, $result['code'] ?? ($result['status'] ?? 0));
    }

    public function testAllServicesSupportTestAction(): void
    {
        // تحقق بنيوي (مفيش استدعاءات شبكة): كل كلاس في integrations registry
        // لازم يوفّر case 'test' في دوال request() عشان زر "اختبار الاتصال"
        // في لوحة الأدمن يشتغل فعلًا.
        if (!class_exists('IntegrationManager')) {
            $this->markTestSkipped('IntegrationManager غير محمّل في هذه البيئة.');
        }

        $registry = IntegrationManager::all();
        $baseDir = dirname(__DIR__, 2);

        foreach ($registry as $key => $meta) {
            $classFile = $baseDir . '/app/Services/Integrations/' . $meta['class'] . '.php';
            if (!is_file($classFile)) {
                continue;
            }
            $src = (string) file_get_contents($classFile);
            $this->assertStringContainsString(
                "case 'test':",
                $src,
                "الخدمة {$meta['class']} لازم توفّر case 'test' في request() عشان اختبار الاتصال يشتغل"
            );
        }
    }
}

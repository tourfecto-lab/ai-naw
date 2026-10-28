<?php

/**
 * Tourfecto - Comprehensive Rate Limiting (Item 1) Integration Test
 * بيفحص حماية المعدلات الشاملة الجديدة على `tourfecto_test`:
 *   1) حارس `Controller::rateLimitGuard()`:
 *        - نطاق المستخدم (AI: 20/دقيقة لكل user) - يتجاوز الحد → 429 بالعربي،
 *          وبعد إعادة ضبط النافذة → يسمح تاني.
 *        - نطاق الـ IP (auth: 30/دقيقة لكل عنوان) - نفس السلوك.
 *        - عداد مشترك بين كل نقط نهايات AI لنفس المستخدم (ميزة تكلفة AI).
 *        - fail-open بدون مستخدم موثّق (نعتمد على الطبقة العامة بالـ IP).
 *   2) `RateLimiter::resetWindow()` الجديدة: تمسح العدّاد + الحظر معًا.
 *   3) `RateLimitMiddleware`: رسالة 429 بالعربي + التعافي بعد إعادة الضبط.
 *
 * صفر شبكة/AI حقيقية. معرّفات معزولة: المستخدم 999951 وعناوين 203.0.113.x.
 * @version 1.0.0  @date 2026-08-31
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Core/Controller.php';
require_once __DIR__ . '/../../app/Services/Security/RateLimiter.php';
require_once __DIR__ . '/../../app/Middleware/RateLimitMiddleware.php';

if (!function_exists('getallheaders')) {
    /**
     * polyfill: في CLI بدون خادم ويب، getallheaders غير معرّفة نهائيًا -
     * نعرّفها عشان RateLimitMiddleware::getApiKey() تشتغل (وترجع null هنا).
     */
    function getallheaders(): array
    {
        $headers = [];
        if (isset($_SERVER['HTTP_X_API_KEY'])) {
            $headers['X-API-Key'] = $_SERVER['HTTP_X_API_KEY'];
        }
        return $headers;
    }
}

final class RateLimitTestController extends Controller
{
    public function check(string $tier, string $scope, int $max, int $window = 60): ?array
    {
        return $this->rateLimitGuard($tier, $scope, $max, $window);
    }

    public function currentUserId(): int
    {
        return (int) ($this->user['id'] ?? 0);
    }
}

final class NoHeaderRateLimitMiddleware extends RateLimitMiddleware
{
    protected function addRateLimitHeaders(array $result): void
    {
        // CLI: header() بيولّد تحذير "headers already sent" - نلغي الرؤوس هنا.
    }
}

final class RateLimitingModuleIntegrationTest extends TestCase
{
    private const USER = 999951;
    private const TEST_IP = '203.0.113.50';

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
            if (empty($conn->query("SHOW TABLES LIKE 'rate_limit_blocks'")->fetchAll())) {
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
            $this->markTestSkipped('DB غير متاحة أو جدول rate_limit_blocks غير موجود');
        }
        $this->resetLimits();

        $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                    VALUES (999951, 'ratelimit@tourfecto.test', 'x', 'Rate Limit User', NOW())
                    ON DUPLICATE KEY UPDATE email = email");
    }

    protected function tearDown(): void
    {
        if (self::$pdo === null) {
            return;
        }
        $this->resetLimits();
        self::$pdo->exec("DELETE FROM users WHERE id = 999951");
        unset($_SESSION['user'], $_SERVER['REMOTE_ADDR'], $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
    }

    /** مسح عدّادات + حظر كل المعرّفات المعزولة عشان الاختبارات ما تعتمدش على الترتيب */
    private function resetLimits(): void
    {
        $pdo = self::$pdo;
        $limiter = new RateLimiter();
        foreach (['user:999951', self::TEST_IP, 'ip_' . self::TEST_IP, 'ip_203.0.113.42'] as $id) {
            $limiter->resetWindow($id, 'ai');
            $limiter->resetWindow($id, 'auth_ip');
            $limiter->resetWindow($id, 'default');
            $pdo->exec("DELETE FROM rate_limit_blocks WHERE identifier = '" . $id . "'");
        }
    }

    private function makeController(): RateLimitTestController
    {
        $_SESSION['user'] = ['id' => self::USER, 'email' => 'ratelimit@tourfecto.test'];
        $_SERVER['REMOTE_ADDR'] = self::TEST_IP;
        return new RateLimitTestController();
    }

    // ================================================================
    // نطاق المستخدم (AI)
    // ================================================================

    public function testUserTierAllowsUpToLimitThenRejectsWithArabic429(): void
    {
        $c = $this->makeController();

        for ($i = 1; $i <= 3; $i++) {
            $this->assertNull($c->check('user', 'ai', 3, 60), "call #{$i} should be allowed");
        }

        $rejected = $c->check('user', 'ai', 3, 60);
        $this->assertNotNull($rejected);
        $this->assertSame(429, $rejected['code']);
        $this->assertFalse($rejected['success']);
        $this->assertStringContainsString('طلبات كتير أوي', $rejected['error']);
        $this->assertArrayHasKey('retry_after', $rejected['details']);
        $this->assertArrayHasKey('limit', $rejected['details']);
    }

    public function testUserTierAllowedAgainAfterWindowReset(): void
    {
        $c = $this->makeController();

        for ($i = 1; $i <= 3; $i++) {
            $this->assertNull($c->check('user', 'ai', 3, 60));
        }
        $this->assertNotNull($c->check('user', 'ai', 3, 60));

        // محاكاة مرور النافذة: resetWindow يمسح العدّاد + الحظر
        $limiter = new RateLimiter();
        $limiter->resetWindow('user:' . self::USER, 'ai');

        $this->assertNull($c->check('user', 'ai', 3, 60));
        $this->assertNull($c->check('user', 'ai', 3, 60));
    }

    /** كل نقط نهايات AI لنفس المستخدم تشارك عداد واحد (حد تكلفة شامل) */
    public function testAiScopeSharesCounterAcrossEndpointsForSameUser(): void
    {
        $c = $this->makeController();

        // محاكاة نقطتي نهايات مختلفتين (نفس الـ scope ai)
        $this->assertNull($c->check('user', 'ai', 3, 60)); // endpoint A
        $this->assertNull($c->check('user', 'ai', 3, 60)); // endpoint B
        $this->assertNull($c->check('user', 'ai', 3, 60)); // endpoint A

        $rejected = $c->check('user', 'ai', 3, 60); // endpoint B
        $this->assertSame(429, $rejected['code']);
    }

    /** بدون مستخدم موثّق → fail-open (الطبقة العامة بالـ IP بتشتغل مكانه) */
    public function testUserTierFailsOpenWhenNoAuthenticatedUser(): void
    {
        unset($_SESSION['user']);
        $_SERVER['REMOTE_ADDR'] = self::TEST_IP;
        $c = new RateLimitTestController();

        for ($i = 1; $i <= 5; $i++) {
            $this->assertNull($c->check('user', 'ai', 3, 60));
        }
        $this->assertSame(0, $c->currentUserId());
    }

    // ================================================================
    // نطاق الـ IP (auth)
    // ================================================================

    public function testIpTierAllowsUpToLimitThenRejectsWithArabic429(): void
    {
        unset($_SESSION['user']);
        $_SERVER['REMOTE_ADDR'] = self::TEST_IP;
        $c = new RateLimitTestController();

        for ($i = 1; $i <= 3; $i++) {
            $this->assertNull($c->check('ip', 'auth_ip', 3, 60), "call #{$i} should be allowed");
        }

        $rejected = $c->check('ip', 'auth_ip', 3, 60);
        $this->assertNotNull($rejected);
        $this->assertSame(429, $rejected['code']);
        $this->assertStringContainsString('طلبات كتير أوي', $rejected['error']);
    }

    public function testIpTierAllowedAgainAfterWindowReset(): void
    {
        unset($_SESSION['user']);
        $_SERVER['REMOTE_ADDR'] = self::TEST_IP;
        $c = new RateLimitTestController();

        for ($i = 1; $i <= 3; $i++) {
            $this->assertNull($c->check('ip', 'auth_ip', 3, 60));
        }
        $this->assertNotNull($c->check('ip', 'auth_ip', 3, 60));

        $limiter = new RateLimiter();
        $limiter->resetWindow(self::TEST_IP, 'auth_ip');

        $this->assertNull($c->check('ip', 'auth_ip', 3, 60));
    }

    // ================================================================
    // RateLimiter مباشرة
    // ================================================================

    public function testRateLimiterCheckRejectsAndResetWindowRecovers(): void
    {
        $limiter = new RateLimiter();
        $identifier = 'unit_rl_' . uniqid('', true);

        for ($i = 1; $i <= 3; $i++) {
            $this->assertTrue($limiter->check($identifier, 'unit', 3, 60));
        }
        $this->assertFalse($limiter->check($identifier, 'unit', 3, 60));
        $this->assertTrue($limiter->isBlocked($identifier));

        $limiter->resetWindow($identifier, 'unit');

        $this->assertFalse($limiter->isBlocked($identifier));
        $this->assertTrue($limiter->check($identifier, 'unit', 3, 60));
    }

    // ================================================================
    // RateLimitMiddleware (الطبقة العامة) - عربي + تعافي
    // ================================================================

    public function testMiddlewareReturnsArabic429AndRecoversAfterReset(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.42';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $mw = new NoHeaderRateLimitMiddleware();
        $mw->setLimit('/api/test-rl', 3, 60);
        $_SERVER['REQUEST_URI'] = '/api/test-rl';

        for ($i = 1; $i <= 3; $i++) {
            $this->assertNull($mw->handle(), "middleware call #{$i} should pass");
        }

        $blocked = $mw->handle();
        $this->assertNotNull($blocked);
        $this->assertSame(429, $blocked['code']);
        $this->assertSame('طلبات كتير أوي - من فضلك انتظر لحظة وحاول تاني', $blocked['error']);

        $limiter = new RateLimiter();
        $limiter->resetWindow('ip_203.0.113.42', 'default');

        $this->assertNull($mw->handle());
    }
}

<?php

/**
 * Tourfecto - AI Resolution Rate Integration Test (بند 8)
 * بيفحص سطح صحة/حالة "معدل الحل" الإحصائي المبني على بيانات موجودة:
 *   1) معدل الحل = المحادثات المنتهية (resolved/closed) المحسومة بالكامل
 *      عبر الـAI (بدون تحويل) ÷ الإجمالي المنتهي.
 *   2) المحادثات المفتوحة (open/pending) لا تدخل في المقام.
 *   3) جودة الاستدعاء من ai_usage_logs (نسبة success).
 *   4) لا بيانات → null صراحةً + ثقة low (لا اختراع أرقام).
 *   5) عزل الموقع: بيانات موقع لا تُقرأ من موقع آخر.
 *
 * محتاج جداول: websites + ai_conversations + ai_usage_logs (سكيما أساسية).
 * بيتخطى تلقائيًا لو DB غير متاحة.
 * @version 1.0.0  @date 2026-08-28
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Models/AiUsageLog.php';
require_once __DIR__ . '/../../app/Services/AI/AiResolutionRateService.php';

final class AiResolutionRateIntegrationTest extends TestCase
{
    private const USER_ID = 999770;

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
            if (!class_exists('Database') && file_exists($app . '/Core/Database.php')) {
                require_once $app . '/Core/Database.php';
            }
            if (!class_exists('Logger') && file_exists($app . '/Core/Logger.php')) {
                require_once $app . '/Core/Logger.php';
            }

            $db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $conn = $ref->getValue($db);
            if (!$conn instanceof PDO) {
                self::$pdo = null;
                return null;
            }

            foreach (['users', 'websites', 'ai_conversations', 'ai_usage_logs'] as $table) {
                $found = $conn->query("SHOW TABLES LIKE '{$table}'")->fetchAll();
                if (empty($found)) {
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
            $this->markTestSkipped('DB غير متاحة - راجع تعليق أعلى الملف');
        }

        $this->cleanup();

        $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                    VALUES (" . self::USER_ID . ", 'resolution-" . self::USER_ID . "@tourfecto.test', 'x', 'Res Travel', NOW())
                    ON DUPLICATE KEY UPDATE email = email");
    }

    private function cleanup(): void
    {
        $pdo = $this->db();
        if ($pdo === null) {
            return;
        }
        $pdo->exec("DELETE FROM ai_usage_logs WHERE user_id = " . self::USER_ID);
        $pdo->exec("DELETE FROM ai_conversations WHERE user_id = " . self::USER_ID);
        $pdo->exec("DELETE FROM websites WHERE user_id = " . self::USER_ID);
    }

    private function addWebsite(): int
    {
        $pdo = $this->db();
        $stmt = $pdo->prepare("INSERT INTO websites (user_id, main_url, company_name, brand_name)
                               VALUES (?, ?, ?, ?)");
        $stmt->execute([self::USER_ID, 'https://res.example.com', 'Res Travel', 'Res Travel']);
        return (int) $pdo->lastInsertId();
    }

    private function addConversation(int $websiteId, string $status, ?string $handoffAt): int
    {
        $pdo = $this->db();
        $stmt = $pdo->prepare("INSERT INTO ai_conversations
                    (website_id, user_id, channel, status, ai_status, handoff_at, created_at)
                    VALUES (?, ?, 'website_chat', ?, 'ai', ?, NOW())");
        $stmt->execute([$websiteId, self::USER_ID, $status, $handoffAt]);
        return (int) $pdo->lastInsertId();
    }

    private function addUsage(int $websiteId, string $status): void
    {
        $pdo = $this->db();
        $stmt = $pdo->prepare("INSERT INTO ai_usage_logs
                    (website_id, user_id, provider, feature, tokens_total, status, created_at)
                    VALUES (?, ?, 'gemini', 'chat_reply', 100, ?, NOW())");
        $stmt->execute([$websiteId, self::USER_ID, $status]);
    }

    private function service(): AiResolutionRateService
    {
        return new AiResolutionRateService();
    }

    // ================================================================
    // الاختبارات
    // ================================================================

    public function testResolutionRateFromEndedConversations(): void
    {
        $websiteId = $this->addWebsite();
        // 6 منتهية حُسمت عبر الـAI (بلا تحويل) + 2 منتهية بتحويل لموظف
        for ($i = 0; $i < 6; $i++) {
            $this->addConversation($websiteId, 'resolved', null);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->addConversation($websiteId, 'closed', date('Y-m-d H:i:s'));
        }
        // مفتوحة لا تدخل في المقام
        $this->addConversation($websiteId, 'open', null);

        $result = $this->service()->resolutionRate($websiteId);

        $this->assertSame('statistical', $result['basis']);
        $this->assertSame(75.0, (float) $result['resolution_rate_percent']); // 6/8
        $this->assertSame('low', $result['resolution_confidence']); // 8 < 10
        $this->assertSame(8, $result['conversations']['total_ended']);
        $this->assertSame(6, $result['conversations']['ai_resolved']);
        $this->assertSame(2, $result['conversations']['human_resolved']);
        $this->assertSame(1, $result['conversations']['still_open']);
    }

    public function testUsageSuccessRateFromAiUsageLogs(): void
    {
        $websiteId = $this->addWebsite();
        $this->addUsage($websiteId, 'success');
        $this->addUsage($websiteId, 'success');
        $this->addUsage($websiteId, 'success');
        $this->addUsage($websiteId, 'failed');
        $this->addUsage($websiteId, 'fallback_used');

        $result = $this->service()->resolutionRate($websiteId);

        $this->assertSame(5, $result['ai_usage']['total_requests']);
        $this->assertSame(3, $result['ai_usage']['success_requests']);
        $this->assertSame(1, $result['ai_usage']['failed_requests']);
        $this->assertSame(1, $result['ai_usage']['fallback_used']);
        $this->assertSame(60.0, (float) $result['ai_usage']['success_rate_percent']);
    }

    public function testNoDataYieldsExplicitNulls(): void
    {
        $websiteId = $this->addWebsite();

        $result = $this->service()->resolutionRate($websiteId);

        $this->assertNull($result['resolution_rate_percent']);
        $this->assertNull($result['ai_usage']['success_rate_percent']);
        $this->assertSame('low', $result['resolution_confidence']);
        $this->assertSame(0, $result['conversations']['total_ended']);
        $this->assertStringContainsString('لا توجد محادثات منتهية', $result['conversations']['note']);
    }

    public function testTenantIsolationBetweenWebsites(): void
    {
        $websiteId = $this->addWebsite();
        $other = $this->addWebsite();

        for ($i = 0; $i < 4; $i++) {
            $this->addConversation($websiteId, 'resolved', null);
            $this->addConversation($other, 'resolved', date('Y-m-d H:i:s'));
        }

        $result = $this->service()->resolutionRate($websiteId);
        $this->assertSame(100.0, (float) $result['resolution_rate_percent']);
        $this->assertSame(4, $result['conversations']['total_ended']);
    }

    public function testHighConfidenceWithLargerSample(): void
    {
        $websiteId = $this->addWebsite();
        for ($i = 0; $i < 30; $i++) {
            $this->addConversation($websiteId, 'resolved', null);
        }

        $result = $this->service()->resolutionRate($websiteId);
        $this->assertSame('high', $result['resolution_confidence']);
        $this->assertSame(100.0, (float) $result['resolution_rate_percent']);
    }
}

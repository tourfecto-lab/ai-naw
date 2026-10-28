<?php

/**
 * Tourfecto - Knowledge Base Re-ranking Integration Test (بند 7)
 * بيفحص طبقة إعادة الترتيب (Re-ranking) في KnowledgeBaseService:
 *   1) rerankForQuery ترتّب العناصر حسب صلة العنوان/المحتوى بالرسالة.
 *   2) buildContextForPrompt مع customerMessage + maxEntries يقتطع
 *      المحتوى للأعلى صلة فقط (يوفّر توكنز ويحسّن الدقة).
 *   3) لغة محايدة: تعمل على عربي وإنجليزي.
 *   4) رسالة بدون كلمات مفتاحية → لا تختفي العناصر (score أدنى 0.05).
 *   5) brand_voice يُستبعد دائمًا من الـContext المدمج.
 *
 * محتاج جداول: ai_knowledge_base + websites (موجودة في السكيما الأساسية).
 * بيتخطى تلقائيًا لو DB غير متاحة.
 * @version 1.0.0  @date 2026-08-28
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Models/AiKnowledgeBase.php';
require_once __DIR__ . '/../../app/Services/AI/KnowledgeBaseService.php';

final class KnowledgeBaseRerankIntegrationTest extends TestCase
{
    private const USER_ID = 999760;

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

            foreach (['users', 'websites', 'ai_knowledge_base'] as $table) {
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

        $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                    VALUES (" . self::USER_ID . ", 'kb-rerank-" . self::USER_ID . "@tourfecto.test', 'x', 'Kb Travel', NOW())
                    ON DUPLICATE KEY UPDATE email = email");
        $pdo->exec("DELETE kb FROM ai_knowledge_base kb
                    JOIN websites w ON w.id = kb.website_id
                    WHERE w.user_id = " . self::USER_ID);
        $pdo->exec("DELETE FROM websites WHERE user_id = " . self::USER_ID);
    }

    private function addWebsite(): int
    {
        $pdo = $this->db();
        $stmt = $pdo->prepare("INSERT INTO websites (user_id, main_url, company_name, brand_name)
                               VALUES (?, ?, ?, ?)");
        $ok = $stmt->execute([self::USER_ID, 'https://kb.example.com', 'KB Travel', 'KB Travel']);
        if (!$ok) {
            $this->fail('Failed to create test website');
        }
        $id = (int) $pdo->lastInsertId();
        if ($id < 1) {
            $this->fail('Failed to obtain test website id');
        }
        return $id;
    }

    private function addEntry(int $websiteId, string $section, string $title, string $content, int $priority = 0): int
    {
        $pdo = $this->db();
        $stmt = $pdo->prepare("INSERT INTO ai_knowledge_base
                    (website_id, section, title, content, language, priority, is_active)
                    VALUES (?, ?, ?, ?, 'en', ?, 1)");
        $stmt->execute([$websiteId, $section, $title, $content, $priority]);
        return (int) $pdo->lastInsertId();
    }

    private function service(): KnowledgeBaseService
    {
        return new KnowledgeBaseService();
    }

    private function seedTravelKb(int $websiteId): void
    {
        $this->addEntry($websiteId, 'company_info', 'About us', 'We organize travel tours around Egypt since 2005.');
        $this->addEntry($websiteId, 'tour', 'Cairo city tour', 'Visit pyramids, the Egyptian museum, and a local bazaar in one day.');
        $this->addEntry($websiteId, 'pricing', 'Tour prices', 'Cairo day tour starts at $50 per person. Hurghada starts at $80.');
        $this->addEntry($websiteId, 'policy', 'Refund policy', 'Full refund if cancelled 48 hours before departure.');
        $this->addEntry($websiteId, 'brand_voice', 'Voice', 'Friendly and professional.');
    }

    // ================================================================
    // الاختبارات
    // ================================================================

    public function testRerankOrdersByRelevance(): void
    {
        $websiteId = $this->addWebsite();
        $this->seedTravelKb($websiteId);

        $entries = $this->service()->rerankForQuery(
            (new AiKnowledgeBase())->activeFor($websiteId),
            'How much does the tour cost per person?',
            10
        );

        $this->assertNotEmpty($entries);
        $titles = array_map(fn ($e) => $e->getAttribute('title'), $entries);

        // عنصري السعر وجولة القاهرة في المقدمة
        $this->assertSame('Tour prices', $titles[0]);
        $this->assertSame('Cairo city tour', $titles[1]);

        // brand_voice يُرتَّب لكنه يُستبعد من الـContext المدمج
        $this->assertContains('Voice', $titles);
    }

    public function testBuildContextWithRerankTrimsToRelevantOnly(): void
    {
        $websiteId = $this->addWebsite();
        $this->seedTravelKb($websiteId);

        $context = $this->service()->buildContextForPrompt(
            $websiteId,
            'en',
            'How much is the Cairo tour?',
            2
        );

        // الأعلى صلة فقط (2 عنصر) + لا يُستبعد من حيث الأقسام
        $this->assertStringContainsString('Tour prices', $context);
        $this->assertStringContainsString('Cairo city tour', $context);
        // محتوى غير ذي صلة خارج الحد
        $this->assertStringNotContainsString('Refund policy', $context);
        // الـBrand Voice يُحقن بشكل منفصل - لا يظهر في الـContext
        $this->assertStringNotContainsString('Friendly and professional', $context);
    }

    public function testRerankIsLanguageAgnosticArabic(): void
    {
        $websiteId = $this->addWebsite();
        $this->addEntry($websiteId, 'faq', 'مواعيد الجولات', 'تبدأ جولاتنا اليومية في التاسعة صباحًا من أي فندق.');
        $this->addEntry($websiteId, 'faq', 'أسعار الجولات', 'تبدأ أسعار الجولات من 50 دولار، وسعر الجولة للفرد يشمل الفندق والمواصلات.');
        $this->addEntry($websiteId, 'company_info', 'عن الشركة', 'شركة سياحة متخصصة في جولات مصر.');

        $entries = $this->service()->rerankForQuery(
            (new AiKnowledgeBase())->activeFor($websiteId),
            'كم سعر الجولة؟',
            3
        );

        $this->assertNotEmpty($entries);
        $this->assertSame('أسعار الجولات', $entries[0]->getAttribute('title'));
    }

    public function testRerankNeverDropsAllEntries(): void
    {
        $websiteId = $this->addWebsite();
        $this->seedTravelKb($websiteId);

        // رسالة بلا كلمات مفتاحية مطابقة → كل العناصر تبقى (score أدنى 0.05)
        $entries = $this->service()->rerankForQuery(
            (new AiKnowledgeBase())->activeFor($websiteId),
            'zzzzz qqqqq',
            10
        );

        $this->assertCount(5, $entries);
    }

    public function testBuildContextWithoutMessageReturnsAll(): void
    {
        $websiteId = $this->addWebsite();
        $this->seedTravelKb($websiteId);

        $context = $this->service()->buildContextForPrompt($websiteId, 'en');

        $this->assertStringContainsString('Tour prices', $context);
        $this->assertStringContainsString('Refund policy', $context);
        $this->assertStringContainsString('About us', $context);
    }
}

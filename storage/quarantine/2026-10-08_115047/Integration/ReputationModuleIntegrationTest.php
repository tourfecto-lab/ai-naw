<?php

/**
 * Tourfecto - Reputation Module Integration Test
 * بيتخطى تلقائيًا (markTestSkipped) لو DB غير متاحة.
 *
 * يغطي تحسينات موديول السمعة (2026-08-29):
 *   1) ReviewTopicExtractor (G4): استخراج موضوعات ديناميكي ثنائي اللغة
 *      (عربي/إنجليزي) من نصوص المراجعات مع تجميع المشاعر ومتوسط التقييم
 *      وأهم الموضوعات في المراجعات السلبية (اقتراحات التحسين).
 *   2) قناة SMS لطلبات المراجعة (G2): getChannelStatus يضم مفتاح sms،
 *      والإنشاء عبر sms يتطلب رقم هاتف ويرفض بوضوح لما تكامل Twilio
 *      غير مهيأ (Graceful fallback - لا Mock أبدًا).
 * @version 1.0.0  @date 2026-08-29
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/Reputation/ReviewTopicExtractor.php';
require_once __DIR__ . '/../../app/Services/Reputation/ReviewRequestService.php';

final class ReputationModuleIntegrationTest extends TestCase
{
    private const TEST_USER_ID = 999009;

    private static ?PDO $pdo = null;
    private static bool $dbChecked = false;
    private static ?ReviewRequestService $rrService = null;

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
            if (!class_exists('Model') && file_exists($app . '/Core/Model.php')) {
                require_once $app . '/Core/Model.php';
            }
            if (!class_exists('Encryption') && file_exists($app . '/Security/Encryption.php')) {
                require_once $app . '/Security/Encryption.php';
            }
            if (!class_exists('ChatManager') && file_exists($app . '/Services/Chat/ChatManager.php')) {
                require_once $app . '/Services/Chat/ChatManager.php';
            }
            if (!class_exists('PlatformConnection') && file_exists($app . '/Models/PlatformConnection.php')) {
                require_once $app . '/Models/PlatformConnection.php';
            }
            if (!class_exists('ReviewRequestSettings') && file_exists($app . '/Models/ReviewRequestSettings.php')) {
                require_once $app . '/Models/ReviewRequestSettings.php';
            }

            $db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $conn = $ref->getValue($db);
            if (!$conn instanceof PDO) {
                return null;
            }

            foreach (['users', 'websites', 'review_requests'] as $table) {
                $found = $conn->query("SHOW TABLES LIKE '{$table}'")->fetchAll();
                if (empty($found)) {
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
            $this->markTestSkipped('DB غير متاحة');
        }

        $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                    VALUES (999009, 'reputation-module@tourfecto.test', 'x', 'Test', NOW())
                    ON DUPLICATE KEY UPDATE email = email");

        $pdo->exec("INSERT INTO websites (id, user_id, main_url, company_name, industry, is_verified, created_at)
                    VALUES (999009, 999009, 'https://reputation-module.test', 'Test Rep', 'tourism', 1, NOW())
                    ON DUPLICATE KEY UPDATE user_id = user_id");

        if (self::$rrService === null) {
            self::$rrService = new ReviewRequestService();
        }
    }

    protected function tearDown(): void
    {
        $pdo = self::$pdo;
        if ($pdo === null) {
            return;
        }
        $pdo->exec("DELETE FROM review_requests WHERE website_id = 999009 OR user_id = 999009");
        $pdo->exec("DELETE FROM review_request_opt_outs WHERE website_id = 999009");
        $pdo->exec("DELETE FROM review_request_templates WHERE website_id = 999009");
        $pdo->exec("DELETE FROM review_request_settings WHERE website_id = 999009");
        $pdo->exec("DELETE FROM platform_connections WHERE website_id = 999009");
        $pdo->exec("DELETE FROM websites WHERE id = 999009");
        $pdo->exec("DELETE FROM users WHERE id = 999009");
    }

    public function testExtractFromTextMatchesArabicTopic(): void
    {
        $extractor = new ReviewTopicExtractor();
        $matched = $extractor->extractFromText('الغرفة كانت نظيفة جداً والتعامل في الاستقبال راقي');

        $keys = array_column($matched, 'key');
        $this->assertContains('cleanliness', $keys, 'نص عربي يذكر النظافة لازم يطابق موضوع cleanliness');
        $this->assertContains('staff_service', $keys, 'نص عربي يذكر الاستقبال/التعامل لازم يطابق موضوع staff_service');
        $this->assertNotEmpty($matched[0]['label']);
        $this->assertGreaterThan(0, $matched[0]['score']);
    }

    public function testExtractFromTextMatchesEnglishTopic(): void
    {
        $extractor = new ReviewTopicExtractor();
        $matched = $extractor->extractFromText('The breakfast buffet was amazing but the wifi was very weak');

        $keys = array_column($matched, 'key');
        $this->assertContains('food_dining', $keys);
        $this->assertContains('wifi_connectivity', $keys);
    }

    public function testExtractFromTextEmptyReturnsEmptyArray(): void
    {
        $extractor = new ReviewTopicExtractor();
        $this->assertSame([], $extractor->extractFromText(''));
        $this->assertSame([], $extractor->extractFromText('   '));
    }

    public function testExtractTopicsAggregatesSentimentAndRating(): void
    {
        $extractor = new ReviewTopicExtractor();
        $reviews = [
            ['review_text' => 'الغرفة نضيفة والمكيف ممتاز', 'sentiment' => 'positive', 'rating' => 5],
            ['review_text' => 'الغرفة كانت وسخة جدا والمكيف بيعمل صوت عالي', 'sentiment' => 'negative', 'rating' => 1],
            ['review_text' => 'الغرفة مقبولة', 'sentiment' => 'neutral', 'rating' => 3],
        ];

        $topics = $extractor->extractTopics($reviews);

        $this->assertNotEmpty($topics, 'لازم يطلع في موضوعات من المراجعات');

        $room = null;
        foreach ($topics as $t) {
            if ($t['key'] === 'room_quality') {
                $room = $t;
            }
        }
        $this->assertNotNull($room, 'موضوع room_quality لازم يكون ظاهر');
        $this->assertGreaterThanOrEqual(2, $room['count']);
        $this->assertGreaterThanOrEqual(1, $room['positive']);
        $this->assertGreaterThanOrEqual(1, $room['negative']);
        $this->assertSame(3.0, $room['avg_rating']);
        $this->assertGreaterThan(0.0, $room['share_percent']);
    }

    public function testExtractTopicsEmptyInputReturnsEmpty(): void
    {
        $extractor = new ReviewTopicExtractor();
        $this->assertSame([], $extractor->extractTopics([]));
    }

    public function testTopTopicsForNegativeOnlyNegativeReviews(): void
    {
        $extractor = new ReviewTopicExtractor();
        $reviews = [
            ['review_text' => 'الغرفة وسخة والمكيف واقف والواي فاي ضعيف', 'sentiment' => 'negative'],
            ['review_text' => 'الغرفة متسخة والسرير مكسور', 'sentiment' => 'negative'],
            ['review_text' => 'الغرفة كانت صغيرة والسرير غير مريح', 'sentiment' => 'negative'],
            ['review_text' => 'خدمة ممتازة والغرفة نظيفة', 'sentiment' => 'positive'],
        ];

        $improvements = $extractor->topTopicsForNegative($reviews);

        $this->assertNotEmpty($improvements);
        $this->assertSame('room_quality', $improvements[0]['key'], 'الموضوع الأكثر تكرارًا في السلبيات لازم يكون الأول');
        $this->assertSame('high', $improvements[0]['priority']);
        foreach ($improvements as $t) {
            $this->assertContains($t['key'], ['room_quality', 'cleanliness', 'wifi_connectivity'], 'السلبيات بس هي اللي تظهر');
        }
    }

    public function testGetChannelStatusIncludesSmsKey(): void
    {
        $channels = self::$rrService->getChannelStatus(999009);
        $this->assertArrayHasKey('sms', $channels, 'getChannelStatus لازم يضم قناة SMS');
        $this->assertArrayHasKey('whatsapp', $channels);
        $this->assertArrayHasKey('email', $channels);
    }

    public function testSmsChannelRejectsWhenTwilioNotConfigured(): void
    {
        try {
            self::$rrService->createRequest(
                self::TEST_USER_ID,
                999009,
                'ضيف SMS',
                '201000000123',
                date('Y-m-d H:i:s', strtotime('-1 hour')),
                'sms'
            );
            $this->fail('كان المفروض يرمي Exception لأن Twilio غير مهيأ في بيئة الاختبار');
        } catch (Exception $e) {
            $this->assertStringContainsString('غير مفعّلة', $e->getMessage(), 'رسالة الخطأ لازم توضح أن قناة SMS غير مفعّلة');
        }
    }

    public function testSmsChannelRequiresPhone(): void
    {
        try {
            self::$rrService->createRequest(
                self::TEST_USER_ID,
                999009,
                'ضيف بدون رقم',
                null,
                date('Y-m-d H:i:s'),
                'sms',
                'guest@example.com'
            );
            $this->fail('كان المفروض يرمي Exception لأن قناة SMS تتطلب رقم هاتف');
        } catch (Exception $e) {
            $this->assertStringContainsString('رقم الهاتف مطلوب', $e->getMessage());
        }
    }
}

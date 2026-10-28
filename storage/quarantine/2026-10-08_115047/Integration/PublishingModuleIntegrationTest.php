<?php

/**
 * Tourfecto - Publishing Module Integration Test
 * بيفحص موديول النشر:
 *   1) WordPressPublisher: testConnection / createPost / updatePost عبر
 *      transport وهمي (صفر curl) - تحقق من الـ URL/الهيدرز/الجسم + أخطاء
 *      401/5xx/رد غير JSON/فشل شبكة.
 *   2) CustomApiPublisher: publish عبر transport وهمي - الهيدرز (Authorization
 *      + X-Tourfecto-Secret عند وجود توكن)، is_test/source في الجسم، استخراج
 *      url/published_url، أخطاء HTTP وشبكة.
 *   3) PublishScheduledArticleJob end-to-end: حقنة publisherFactory تسمح
 *      بمحاكاة النشر الناجح/الفاشل. نجاح → published + published_at +
 *      wp_post_id؛ فشل النشر الفعلي → publish_failed + error_message؛
 *      فشل قبل التنفيذ (لا اتصال) → schedule_failed؛ عدم كون المقال
 *      scheduled → لا يعمل شيء.
 *   4) انحراف الـ enum: status في ai_articles لازم يتضمن published +
 *      publish_failed بعد الميجريشن 2026_08_31_000003.
 *
 * محتاج الميجريشن: 2026_08_31_000003_fix_ai_articles_publish_status.sql
 * يتخطى تلقائيًا (markTestSkipped) لو DB غير متاحة أو الجداول غير موجودة.
 * @version 1.0.0  @date 2026-08-31
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Core/Model.php';
require_once __DIR__ . '/../../app/Models/User.php';
require_once __DIR__ . '/../../app/Models/Website.php';
require_once __DIR__ . '/../../app/Models/AIArticle.php';
require_once __DIR__ . '/../../app/Models/PlatformConnection.php';
require_once __DIR__ . '/../../app/Models/Notification.php';
require_once __DIR__ . '/../../app/Core/Encryption.php';
require_once __DIR__ . '/../../app/Core/Contracts/QueueJobInterface.php';
require_once __DIR__ . '/../../app/Services/Publishing/WordPressPublisher.php';
require_once __DIR__ . '/../../app/Services/Publishing/CustomApiPublisher.php';
require_once __DIR__ . '/../../app/Services/Publishing/ContentFormatter.php';
require_once __DIR__ . '/../../app/Jobs/PublishScheduledArticleJob.php';

/**
 * transport وهمي بنفس عقدة الحقنة: يستقبل ['method','url','headers','body']
 * ويعيد ['body','http_code','error'].
 */
final class FakePublishTransport
{
    public array $calls = [];
    private string $body;
    private int $httpCode;
    private ?string $error;

    public function __construct(string $body = '{}', int $httpCode = 200, ?string $error = null)
    {
        $this->body = $body;
        $this->httpCode = $httpCode;
        $this->error = $error;
    }

    public function __invoke(array $request): array
    {
        $this->calls[] = $request;
        return ['body' => $this->body, 'http_code' => $this->httpCode, 'error' => $this->error];
    }
}

final class PublishingModuleIntegrationTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static bool $dbChecked = false;

    private const USER = 999400;
    private const WEBSITE = 999450;
    private const ARTICLE = 999470;

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

            $db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $conn = $ref->getValue($db);
            if (!$conn instanceof PDO) {
                self::$pdo = null;
                return null;
            }

            foreach (['ai_articles', 'platform_connections', 'websites'] as $t) {
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
            $this->markTestSkipped('DB غير متاحة أو الجداول الأساسية مش متشغّلة');
        }
        $this->cleanup();

        $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                    VALUES (999400, 'publish@tourfecto.test', 'x', 'Publish User', NOW())
                    ON DUPLICATE KEY UPDATE email = email");
        $pdo->exec("INSERT INTO websites (id, user_id, main_url, company_name)
                    VALUES (999450, 999400, 'https://client-site.test', 'Client Site')
                    ON DUPLICATE KEY UPDATE user_id = 999400");
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
        $pdo->exec('DELETE FROM ai_articles WHERE id = 999470');
        $pdo->exec('DELETE FROM platform_connections WHERE website_id = 999450');
        $pdo->exec('DELETE FROM websites WHERE id = 999450');
        $pdo->exec('DELETE FROM users WHERE id = 999400');
    }

    // ------------------------------------------------------------
    // WordPressPublisher
    // ------------------------------------------------------------

    public function testWordPressTestConnectionSuccess(): void
    {
        $transport = new FakePublishTransport(json_encode(['id' => 7, 'name' => 'Admin']));
        $publisher = new WordPressPublisher($transport);

        $res = $publisher->testConnection('https://site.test', 'admin', 'pass');

        $this->assertTrue($res['success']);
        $this->assertSame(7, $res['user']['id']);
        $this->assertSame('Admin', $res['user']['name']);
        $this->assertSame('https://site.test/wp-json/wp/v2/users/me', $transport->calls[0]['url']);
        $this->assertSame('GET', $transport->calls[0]['method']);
        $this->assertStringContainsString('Basic ' . base64_encode('admin:pass'), implode("\n", $transport->calls[0]['headers']));
    }

    public function testWordPressTestConnectionInvalidCredentials(): void
    {
        $transport = new FakePublishTransport(json_encode(['message' => 'no']), 401);
        $res = (new WordPressPublisher($transport))->testConnection('https://site.test', 'admin', 'wrong');

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('بيانات الدخول', (string) $res['error']);
    }

    public function testWordPressTestConnectionNetworkFailure(): void
    {
        $transport = new FakePublishTransport('', 0, 'Connection refused');
        $res = (new WordPressPublisher($transport))->testConnection('https://site.test', 'admin', 'pass');

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('تعذر الوصول', (string) $res['error']);
    }

    public function testWordPressCreatePostSendsPayloadAndReturnsIds(): void
    {
        $transport = new FakePublishTransport(json_encode(['id' => 42, 'link' => 'https://site.test/article-1']));
        $publisher = new WordPressPublisher($transport);

        $res = $publisher->createPost('https://site.test', 'admin', 'pass', 'My Title', '<p>Hi</p>', 'Excerpt', 'publish');

        $this->assertTrue($res['success']);
        $this->assertSame(42, $res['post_id']);
        $this->assertSame('https://site.test/article-1', $res['url']);

        $call = $transport->calls[0];
        $this->assertSame('https://site.test/wp-json/wp/v2/posts', $call['url']);
        $this->assertSame('POST', $call['method']);
        $body = json_decode($call['body'], true);
        $this->assertSame('My Title', $body['title']);
        $this->assertSame('<p>Hi</p>', $body['content']);
        $this->assertSame('Excerpt', $body['excerpt']);
        $this->assertSame('publish', $body['status']);
    }

    public function testWordPressCreatePostDraftStatus(): void
    {
        $transport = new FakePublishTransport(json_encode(['id' => 1, 'link' => null]));
        $publisher = new WordPressPublisher($transport);

        $publisher->createPost('https://site.test', 'admin', 'pass', 'T', '<p>x</p>', '', 'draft');

        $body = json_decode($transport->calls[0]['body'], true);
        $this->assertSame('draft', $body['status']);
    }

    public function testWordPressCreatePostServerErrorSurfaces(): void
    {
        $transport = new FakePublishTransport(json_encode(['message' => 'Insufficient permissions']), 500);
        $res = (new WordPressPublisher($transport))->createPost('https://site.test', 'admin', 'pass', 'T', '<p>x</p>');

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('Insufficient permissions', (string) $res['error']);
        $this->assertSame(500, $res['http_code']);
    }

    public function testWordPressCreatePostMalformedResponseIsSafe(): void
    {
        $transport = new FakePublishTransport('<html>not json</html>', 200);
        $res = (new WordPressPublisher($transport))->createPost('https://site.test', 'admin', 'pass', 'T', '<p>x</p>');

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('رد غير متوقع', (string) $res['error']);
    }

    public function testWordPressUpdatePostUsesPostIdPath(): void
    {
        $transport = new FakePublishTransport(json_encode(['id' => 42, 'link' => 'https://site.test/updated']));
        $publisher = new WordPressPublisher($transport);

        $res = $publisher->updatePost('https://site.test', 'admin', 'pass', 42, 'New Title', '<p>new</p>');

        $this->assertTrue($res['success']);
        $this->assertSame('https://site.test/wp-json/wp/v2/posts/42', $transport->calls[0]['url']);
    }

    public function testWordPressMarkdownToHtmlBasic(): void
    {
        $html = WordPressPublisher::markdownToHtml("# Title\n\nSome text with **bold**.\n\n## Sub");

        $this->assertStringContainsString('<h2>Title</h2>', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<h3>Sub</h3>', $html);
        $this->assertStringContainsString('<p>Some text with', $html);
    }

    // ------------------------------------------------------------
    // CustomApiPublisher
    // ------------------------------------------------------------

    public function testCustomApiPublishSendsPayloadAndAuthHeaders(): void
    {
        $transport = new FakePublishTransport(json_encode(['url' => 'https://site.test/live']));
        $publisher = new CustomApiPublisher($transport);

        $res = $publisher->publish('https://site.test/hook', 'secret-token', [
            'article_id' => 5, 'title' => 'Art', 'content_html' => '<p>c</p>',
        ], false);

        $this->assertTrue($res['success']);
        $this->assertSame('https://site.test/live', $res['url']);

        $call = $transport->calls[0];
        $this->assertSame('https://site.test/hook', $call['url']);
        $this->assertSame('POST', $call['method']);
        $headers = implode("\n", $call['headers']);
        $this->assertStringContainsString('Authorization: Bearer secret-token', $headers);
        $this->assertStringContainsString('X-Tourfecto-Secret: secret-token', $headers);

        $body = json_decode($call['body'], true);
        $this->assertSame('tourfecto', $body['source']);
        $this->assertFalse($body['is_test']);
        $this->assertSame(5, $body['article_id']);
    }

    public function testCustomApiPublishWithoutTokenOmitsAuthHeaders(): void
    {
        $transport = new FakePublishTransport(json_encode(['ok' => 1]));
        $publisher = new CustomApiPublisher($transport);

        $res = $publisher->publish('https://site.test/hook', '', ['title' => 'T'], true);

        $this->assertTrue($res['success']);
        $headers = implode("\n", $transport->calls[0]['headers']);
        $this->assertStringNotContainsString('Authorization', $headers);
        $this->assertStringNotContainsString('X-Tourfecto-Secret', $headers);
        $body = json_decode($transport->calls[0]['body'], true);
        $this->assertTrue($body['is_test']);
    }

    public function testCustomApiPublishHttpError(): void
    {
        $transport = new FakePublishTransport('{"error":"bad payload"}', 422);
        $res = (new CustomApiPublisher($transport))->publish('https://site.test/hook', '', ['title' => 'T']);

        $this->assertFalse($res['success']);
        $this->assertSame(422, $res['http_code']);
        $this->assertStringContainsString('رفض الطلب', (string) $res['error']);
    }

    public function testCustomApiPublishNetworkFailure(): void
    {
        $transport = new FakePublishTransport('', 0, 'Connection timed out');
        $res = (new CustomApiPublisher($transport))->publish('https://site.test/hook', '', ['title' => 'T']);

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('تعذر الوصول', (string) $res['error']);
    }

    public function testCustomApiPublishExtractsPublishedUrlAlias(): void
    {
        $transport = new FakePublishTransport(json_encode(['published_url' => 'https://site.test/published']));
        $res = (new CustomApiPublisher($transport))->publish('https://site.test/hook', '', ['title' => 'T']);

        $this->assertTrue($res['success']);
        $this->assertSame('https://site.test/published', $res['url']);
    }

    // ------------------------------------------------------------
    // PublishScheduledArticleJob (end-to-end)
    // ------------------------------------------------------------

    private function createArticle(string $status = 'scheduled', string $platform = 'wordpress', ?string $connectionStatus = 'connected'): array
    {
        $pdo = self::$pdo;
        $pdo->exec("INSERT INTO ai_articles (id, user_id, website_id, topic, target_language, title, meta_description, slug, content, status, error_message, scheduled_at)
                    VALUES (999470, 999400, 999450, 'topic', 'ar', 'Scheduled Article', 'desc', 'slug-1', '# Head', '{$status}', NULL, NOW())
                    ON DUPLICATE KEY UPDATE status = '{$status}'");

        if ($connectionStatus !== null) {
            $accessToken = null;
            if ($connectionStatus === 'connected' && $platform === 'wordpress') {
                $accessToken = (new Encryption())->encrypt('admin:app-pass');
            } elseif ($connectionStatus === 'connected') {
                $accessToken = (new Encryption())->encrypt('custom-token');
            }
            $encrypted = $accessToken === null ? 'NULL' : "'" . addslashes($accessToken) . "'";
            $pdo->exec(
                "INSERT INTO platform_connections (website_id, user_id, platform, access_token, external_location_id, external_location_name, status)
                 VALUES (999450, 999400, '{$platform}', {$encrypted}, 'https://site.test', 'site', '{$connectionStatus}')
                 ON DUPLICATE KEY UPDATE platform = VALUES(platform), status = VALUES(status)"
            );
        }

        return ['article_id' => 999470, 'website_id' => 999450, 'draft' => false];
    }

    private function runJob(array $payload, ?callable $factory = null): void
    {
        $job = new PublishScheduledArticleJob($factory);
        $job->handle($payload);
    }

    public function testScheduledArticleWordPressSuccess(): void
    {
        $this->createArticle('scheduled', 'wordpress', 'connected');

        $transport = new FakePublishTransport(json_encode(['id' => 88, 'link' => 'https://site.test/live']));
        $factory = function () use ($transport) {
            return new WordPressPublisher($transport);
        };

        $this->runJob(['article_id' => 999470, 'website_id' => 999450, 'draft' => false], $factory);

        $row = self::$pdo->query('SELECT * FROM ai_articles WHERE id = 999470')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('published', $row['status']);
        $this->assertNotNull($row['published_at']);
        $this->assertSame('https://site.test/live', $row['published_url']);
        $this->assertSame('88', (string) $row['wp_post_id']);
        $this->assertNull($row['scheduled_job_id']);

        // تحقق إن الطلب فعليًا اتبعت لـ wp/v2/posts
        $this->assertSame('https://site.test/wp-json/wp/v2/posts', $transport->calls[0]['url']);
    }

    public function testScheduledArticleWordPressPublishFailureSetsPublishFailed(): void
    {
        $this->createArticle('scheduled', 'wordpress', 'connected');

        $transport = new FakePublishTransport(json_encode(['message' => 'rest_cannot_create']), 403);
        $factory = function () use ($transport) {
            return new WordPressPublisher($transport);
        };

        $this->runJob(['article_id' => 999470, 'website_id' => 999450, 'draft' => false], $factory);

        $row = self::$pdo->query('SELECT * FROM ai_articles WHERE id = 999470')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('publish_failed', $row['status']);
        $this->assertStringContainsString('بيانات الدخول', (string) $row['error_message']);
        $this->assertNull($row['published_at']);
    }

    public function testScheduledArticleCustomApiSuccess(): void
    {
        $this->createArticle('scheduled', 'custom_api', 'connected');

        $transport = new FakePublishTransport(json_encode(['url' => 'https://site.test/custom-live']));
        $factory = function () use ($transport) {
            return new CustomApiPublisher($transport);
        };

        $this->runJob(['article_id' => 999470, 'website_id' => 999450, 'draft' => false], $factory);

        $row = self::$pdo->query('SELECT * FROM ai_articles WHERE id = 999470')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('published', $row['status']);
        $this->assertSame('https://site.test/custom-live', $row['published_url']);
        $this->assertNull($row['wp_post_id']);
    }

    public function testScheduledArticleMissingConnectionFailsAsScheduleFailed(): void
    {
        $this->createArticle('scheduled', 'wordpress', null);

        $this->runJob(['article_id' => 999470, 'website_id' => 999450, 'draft' => false]);

        $row = self::$pdo->query('SELECT * FROM ai_articles WHERE id = 999470')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('schedule_failed', $row['status']);
        $this->assertStringContainsString('الاتصال', (string) $row['error_message']);
    }

    public function testScheduledArticleNotScheduledIsNoop(): void
    {
        $this->createArticle('completed', 'wordpress', 'connected');

        $called = false;
        $factory = function () use (&$called) {
            $called = true;
            return new WordPressPublisher(new FakePublishTransport('{}'));
        };

        $this->runJob(['article_id' => 999470, 'website_id' => 999450, 'draft' => false], $factory);

        $this->assertFalse($called, 'المقال غير المقرر جدولته مينفعش يتحرك');
        $row = self::$pdo->query('SELECT * FROM ai_articles WHERE id = 999470')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('completed', $row['status']);
    }

    // ------------------------------------------------------------
    // انحراف الـ enum (published / publish_failed)
    // ------------------------------------------------------------

    public function testAiArticlesStatusEnumIncludesPublishStates(): void
    {
        $col = self::$pdo->query("SHOW COLUMNS FROM ai_articles LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        $type = (string) ($col['Type'] ?? '');
        $this->assertStringContainsString('published', $type);
        $this->assertStringContainsString('publish_failed', $type);
    }
}

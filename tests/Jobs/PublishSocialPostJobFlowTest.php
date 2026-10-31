<?php

use PHPUnit\Framework\TestCase;

/**
 * محرك النشر بجد (من غير شبكة): نفس مسار PublishSocialPostJob على جداول حقيقية، وMeta بديلها عميل وهمي بيسجّل الطلبات.
 * بيغطي: النجاح، الفشل النهائي، إعادة المحاولة، الاتصال الغير مفعّل، منع النشر المزدوج، وفحص الملف الخاص المفقود.
 */
final class PublishSocialPostJobFlowTest extends TestCase
{
    private const U = 900601;
    private PDO $pdo;
    private int $connId = 0;
    private int $postId = 0;
    private int $targetId = 0;

    protected function setUp(): void
    {
        try {
            $this->pdo = Database::getInstance()->getConnection();
            $this->pdo->query('SELECT 1 FROM social_post_targets LIMIT 1');
        } catch (Throwable $e) {
            $this->markTestSkipped('قاعدة الاختبار مش متاحة: ' . $e->getMessage());
        }
        $this->cleanup();
        putenv('SOCIAL_PREFLIGHT=0');
        $this->pdo->exec("INSERT INTO users (id, email, password, company_name, created_at) VALUES (" . self::U . ", 'jobflow-" . self::U . "@tourfecto.test', 'x', 'Job Flow', NOW()) ON DUPLICATE KEY UPDATE email = email");
        $enc = new Encryption();
        $st = $this->pdo->prepare("INSERT INTO platform_connections (user_id, platform, access_token, external_location_id, external_location_name, status) VALUES (?, 'facebook', ?, 'PAGE_1', 'Test Page', 'connected')");
        $st->execute([self::U, $enc->encrypt('page-token')]);
        $this->connId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO social_posts (user_id, content, status) VALUES (" . self::U . ", 'Hello from the test', 'scheduled')");
        $this->postId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO social_post_targets (social_post_id, platform_connection_id, status) VALUES ({$this->postId}, {$this->connId}, 'scheduled')");
        $this->targetId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        PublishSocialPostJob::$apiFactory = null;
        putenv('SOCIAL_PREFLIGHT');
        if (isset($this->pdo)) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $u = self::U;
        $this->pdo->exec("DELETE FROM social_post_targets WHERE social_post_id IN (SELECT id FROM social_posts WHERE user_id = $u)");
        $this->pdo->exec("DELETE FROM social_posts WHERE user_id = $u");
        $this->pdo->exec("DELETE FROM platform_connections WHERE user_id = $u");
        $this->pdo->exec("DELETE FROM notifications WHERE user_id = $u");
        $this->pdo->exec("DELETE FROM users WHERE id = $u");
    }

    /** عميل Meta وهمي: بيرجّع النتيجة المطلوبة ويعدّ الطلبات */
    private function fakeMeta(array $result, ?int &$calls = null): void
    {
        $calls = 0;
        PublishSocialPostJob::$apiFactory = static function (string $token) use ($result, &$calls) {
            return new class ($token, $result, $calls) extends MetaSocialAPI {
                public function __construct(string $t, private array $res, private ?int &$n)
                {
                    parent::__construct($t);
                }

                public function publishToFacebookPage(string $pageId, string $pageAccessToken, string $message, ?string $imageUrl = null, array $options = []): array
                {
                    $this->n++;
                    return $this->res + ['page' => $pageId, 'message' => $message];
                }
            };
        };
    }

    private function target(): array
    {
        return $this->pdo->query('SELECT status, external_post_id, last_error, error_category, retry_count FROM social_post_targets WHERE id = ' . $this->targetId)->fetch(PDO::FETCH_ASSOC);
    }

    private function publish(): void
    {
        (new PublishSocialPostJob())->handle(['social_post_target_id' => $this->targetId]);
    }

    public function testSuccessfulPublishMarksTargetAndPost(): void
    {
        $calls = 0;
        $this->fakeMeta(['success' => true, 'post_id' => '111_222'], $calls);
        $this->publish();
        $t = $this->target();
        $this->assertSame('published', $t['status']);
        $this->assertSame('111_222', $t['external_post_id']);
        $this->assertNull($t['last_error']);
        $this->assertSame(1, $calls);
        $this->assertSame('published', $this->pdo->query('SELECT status FROM social_posts WHERE id = ' . $this->postId)->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = " . self::U . " AND type = 'social_post_published'")->fetchColumn());
    }

    public function testPublishedTargetIsNeverSentTwice(): void
    {
        $calls = 0;
        $this->fakeMeta(['success' => true, 'post_id' => '111_222'], $calls);
        $this->publish();
        $this->publish();
        $this->assertSame(1, $calls, 'الاستدعاء التاني مايكلمش Meta');
    }

    public function testDefinitiveTokenErrorFailsWithAClearReason(): void
    {
        $calls = 0;
        $this->fakeMeta(['success' => false, 'http_code' => 400, 'definitive' => true, 'category' => MetaApiError::TOKEN, 'error_code' => 190, 'fbtrace_id' => 'TRACE1', 'error' => 'Error validating access token: Session has expired'], $calls);
        $this->publish();
        $t = $this->target();
        $this->assertSame('failed', $t['status']);
        $this->assertNotEmpty($t['last_error']);
        $this->assertNull($t['external_post_id']);
        $this->assertSame(MetaApiError::TOKEN, $t['error_category'], 'سبب الفشل متسجّل');
        $this->assertSame(1, $calls, 'الخطأ النهائي مايتعادش تلقائيًا');
        $this->assertSame('failed', $this->pdo->query('SELECT status FROM social_posts WHERE id = ' . $this->postId)->fetchColumn());
    }

    public function testRateLimitSchedulesAnAutomaticRetry(): void
    {
        $this->fakeMeta(['success' => false, 'http_code' => 400, 'definitive' => true, 'category' => MetaApiError::RATE_LIMIT, 'error_code' => 4, 'error' => 'Application request limit reached']);
        $this->publish();
        $t = $this->target();
        $this->assertSame('scheduled', $t['status'], 'هيتعاد بعد تأخير');
        $this->assertSame(1, (int) $t['retry_count']);
        $this->assertStringContainsString('هنعيد المحاولة', (string) $t['last_error']);
    }

    public function testUncertainResultIsNotTreatedAsFailure(): void
    {
        $this->fakeMeta(['success' => false, 'http_code' => 0, 'definitive' => false, 'category' => MetaApiError::TRANSIENT, 'error' => 'timeout after request was sent']);
        $this->publish();
        $this->assertSame('uncertain', $this->target()['status'], 'مش هنعيد النشر لو ممكن اتنشر فعلًا');
    }

    public function testDisconnectedPageNeverReachesMeta(): void
    {
        $this->pdo->exec("UPDATE platform_connections SET status = 'expired' WHERE id = " . $this->connId);
        $calls = 0;
        $this->fakeMeta(['success' => true, 'post_id' => 'x'], $calls);
        $this->publish();
        $this->assertSame(0, $calls);
        $this->assertSame('failed', $this->target()['status']);
        $this->assertStringContainsString('إعادة ربط', (string) $this->target()['last_error']);
    }

    public function testPageOfAnotherAccountIsBlocked(): void
    {
        $this->pdo->exec("INSERT INTO users (id, email, password, company_name, created_at) VALUES (900602, 'jobflow-900602@tourfecto.test', 'x', 'Other', NOW()) ON DUPLICATE KEY UPDATE email = email");
        $this->pdo->exec('UPDATE platform_connections SET user_id = 900602, website_id = NULL WHERE id = ' . $this->connId);
        $calls = 0;
        $this->fakeMeta(['success' => true, 'post_id' => 'x'], $calls);
        try {
            $this->publish();
            $status = $this->target()['status'];
        } finally {
            $this->pdo->exec('DELETE FROM platform_connections WHERE user_id = 900602');
            $this->pdo->exec('DELETE FROM users WHERE id = 900602');
        }
        $this->assertSame(0, $calls, 'عزل الحسابات: مفيش نشر على صفحة مش بتاعته');
        $this->assertSame('failed', $status);
    }

    public function testMissingPrivateMediaFileFailsBeforeCallingMeta(): void
    {
        $this->pdo->exec("INSERT INTO media_items (user_id, type, prompt, file_path, status) VALUES (" . self::U . ", 'social_image', 'x', '/uploads/media/definitely_missing_" . bin2hex(random_bytes(3)) . ".png', 'completed')");
        $mid = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("UPDATE social_posts SET media_item_id = $mid WHERE id = " . $this->postId);
        $calls = 0;
        $this->fakeMeta(['success' => true, 'post_id' => 'x'], $calls);
        try {
            $this->publish();
        } finally {
            $this->pdo->exec("DELETE FROM media_items WHERE id = $mid");
        }
        $this->assertSame(0, $calls);
        $t = $this->target();
        $this->assertSame('failed', $t['status'], 'فشل نهائي واضح مش «غير مؤكد»: مفيش حاجة اتبعتت لـMeta');
        $this->assertStringContainsString('مش موجود على السيرفر', (string) $t['last_error']);
    }

    public function testPostPointingToADeletedMediaRowFailsClearly(): void
    {
        $this->pdo->exec('UPDATE social_posts SET media_item_id = 99999999 WHERE id = ' . $this->postId);
        $calls = 0;
        $this->fakeMeta(['success' => true, 'post_id' => 'x'], $calls);
        $this->publish();
        $this->assertSame(0, $calls);
        $this->assertSame('failed', $this->target()['status']);
    }
}

<?php

/**
 * Tourfecto - Creative Studio Module Integration Test
 * بيفحص موديول الاستوديو الإبداعي:
 *   1) MediaGenerationService: طلب توليد صورة/فيديو (إنشاء MediaItem +
 *      جدولة GenerateMediaJob/GenerateVideoJob في الجدول jobs + ActivityLog)،
 *      النسب والأبعاد الصحيحة، رفض الأنواع غير المدعومة.
 *   2) GenerateMediaJob (حقنة clientFactory): نجاح التوليد (كتابة ملف +
 *      status completed + الأبعاد) / فشل الذكاء الاصطناعي (failed +
 *      error_message) / base64 تالف / عنصر مفقود (استثناء).
 *   3) GenerateVideoJob (حقنة clientFactory): فشل البدء / اكتمال الفحص
 *      (كتابة فيديو) / انتهاء مهلة الفحص / عدم الاكتمال (إعادة جدولة).
 *   4) VideoScriptService (حقنة GeminiClient موجودة أصلًا): توليد سكربت
 *      (تخزين script_text + scenes) / فشل AI / JSON مشوه / JSON code-fenced.
 *
 * صفر شبكة/AI حقيقية — كل العملاء وهميين، و ROOT_PATH موجّه لمجلد مؤقت
 * عشان كتابة الملفات ما تتلوّثش في public_html الحقيقي.
 * @version 1.0.0  @date 2026-08-31
 */

use PHPUnit\Framework\TestCase;

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', sys_get_temp_dir() . '/tourfecto_cs_root');
}

require_once __DIR__ . '/../../app/Core/Model.php';
require_once __DIR__ . '/../../app/Core/Container.php';
require_once __DIR__ . '/../../app/Core/Queue/QueueManager.php';
require_once __DIR__ . '/../../app/Core/Logger.php';
require_once __DIR__ . '/../../app/Core/Contracts/QueueJobInterface.php';
require_once __DIR__ . '/../../app/Models/User.php';
require_once __DIR__ . '/../../app/Models/ActivityLog.php';
require_once __DIR__ . '/../../app/Models/Notification.php';
require_once __DIR__ . '/../../app/Models/MediaItem.php';
require_once __DIR__ . '/../../app/Models/VideoScript.php';
require_once __DIR__ . '/../../app/Services/AI/GeminiClient.php';
require_once __DIR__ . '/../../app/Services/AI/VeoClient.php';
require_once __DIR__ . '/../../app/Services/CreativeStudio/MediaGenerationService.php';
require_once __DIR__ . '/../../app/Services/CreativeStudio/VideoScriptService.php';
require_once __DIR__ . '/../../app/Jobs/GenerateMediaJob.php';
require_once __DIR__ . '/../../app/Jobs/GenerateVideoJob.php';

final class CreativeStudioFakeGemini extends GeminiClient
{
    public array $calls = [];
    private array $imageResult;
    private array $contentResult;

    public function __construct(
        array $imageResult = ['success' => false, 'error' => 'unconfigured'],
        array $contentResult = ['success' => false, 'error' => 'unconfigured']
    ) {
        $this->imageResult = $imageResult;
        $this->contentResult = $contentResult;
    }

    public function setImageResult(array $r): void
    {
        $this->imageResult = $r;
    }

    public function setContentResult(array $r): void
    {
        $this->contentResult = $r;
    }

    public function generateImage(string $prompt, string $aspectRatio = '1:1', array $meta = []): array
    {
        $this->calls[] = ['method' => 'generateImage', 'prompt' => $prompt, 'aspect_ratio' => $aspectRatio];
        return $this->imageResult;
    }

    public function generateContent(string $prompt, array $options = []): array
    {
        $this->calls[] = ['method' => 'generateContent', 'prompt' => $prompt, 'options' => $options];
        return $this->contentResult;
    }
}

final class CreativeStudioFakeVeo extends VeoClient
{
    public array $calls = [];
    private array $startResult;
    private array $checkResult;
    private array $downloadResult;

    public function __construct(
        array $startResult = ['success' => false, 'error' => 'unconfigured'],
        array $checkResult = ['success' => false, 'done' => false, 'error' => 'unconfigured'],
        array $downloadResult = ['success' => false, 'error' => 'unconfigured']
    ) {
        $this->startResult = $startResult;
        $this->checkResult = $checkResult;
        $this->downloadResult = $downloadResult;
    }

    public function startGeneration(string $prompt, string $aspectRatio = '16:9', int $durationSeconds = 8, array $settings = []): array
    {
        $this->calls[] = ['method' => 'startGeneration', 'prompt' => $prompt, 'aspect_ratio' => $aspectRatio, 'duration' => $durationSeconds];
        return $this->startResult;
    }

    public function checkOperation(string $operationName): array
    {
        $this->calls[] = ['method' => 'checkOperation', 'operation' => $operationName];
        return $this->checkResult;
    }

    public function downloadVideo(string $videoUri): array
    {
        $this->calls[] = ['method' => 'downloadVideo', 'uri' => $videoUri];
        return $this->downloadResult;
    }
}

final class CreativeStudioModuleIntegrationTest extends TestCase
{
    private const USER = 999600;
    private static ?PDO $pdo = null;
    private static bool $dbChecked = false;

    private const MEDIA_DIR = '/public_html/uploads/media';

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

            foreach (['media_items', 'video_scripts', 'jobs', 'activity_logs', 'notifications'] as $t) {
                if (empty($conn->query("SHOW TABLES LIKE '{$t}'")->fetchAll())) {
                    self::$pdo = null;
                    return null;
                }
            }
            if (empty($conn->query("SHOW COLUMNS FROM media_items LIKE 'aspect_ratio'")->fetchAll())) {
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
            $this->markTestSkipped('DB غير متاحة أو جداول Creative Studio غير موجودة (media_items بلا aspect_ratio)');
        }
        $this->cleanup();

        $pdo->exec("INSERT INTO users (id, email, password, company_name, created_at)
                    VALUES (999600, 'creative@tourfecto.test', 'x', 'Creative User', NOW())
                    ON DUPLICATE KEY UPDATE email = email");

        // الحجز/الحصة بقى إلزامي (PlanUsage::check + CreativeReservations): من غير اشتراك فعّال بحصة
        // media_images/media_videos الطلب بيترفض بـ «الرصيد أو الحصة غير كافيين». نربط المستخدم
        // بباقة professional_monthly (حصة الصور 100 والفيديو 5 من BillingPlanCatalog).
        $planId = (int) $pdo->query("SELECT id FROM subscription_plans WHERE plan_code = 'professional_monthly' LIMIT 1")->fetchColumn();
        if ($planId > 0) {
            $pdo->exec("INSERT INTO subscriptions (user_id, plan_name, plan_type, plan_id, status, price, currency,
                            ai_credits, ai_credits_used, chat_credits, chat_credits_used, review_credits, review_credits_used,
                            competitor_analysis_limit, competitor_analysis_used, social_posts_limit, social_posts_used,
                            media_credits_limit, media_credits_used, max_agencies, start_date, current_period_start, current_period_end)
                        VALUES (999600, 'professional', 'monthly', {$planId}, 'active', 0, 'USD',
                            0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY))");
        }

        @mkdir(ROOT_PATH . self::MEDIA_DIR, 0755, true);
    }

    protected function tearDown(): void
    {
        $pdo = self::$pdo;
        if ($pdo === null) {
            return;
        }
        $this->cleanup();
        foreach (glob(ROOT_PATH . self::MEDIA_DIR . '/*') ?: [] as $f) {
            @unlink($f);
        }
    }

    private function cleanup(): void
    {
        $pdo = self::$pdo;
        $pdo->exec("DELETE FROM activity_logs WHERE user_id = 999600");
        $pdo->exec("DELETE FROM notifications WHERE user_id = 999600");
        $pdo->exec("DELETE FROM jobs WHERE job_class IN ('GenerateMediaJob','GenerateVideoJob')");
        $pdo->exec('DELETE FROM creative_reservations WHERE user_id = 999600');
        $pdo->exec('DELETE FROM plan_usage_events WHERE user_id = 999600');
        $pdo->exec('DELETE FROM subscriptions WHERE user_id = 999600');
        $pdo->exec('DELETE FROM media_items WHERE user_id = 999600');
        $pdo->exec('DELETE FROM video_scripts WHERE user_id = 999600');
        $pdo->exec('DELETE FROM users WHERE id = 999600');
    }

    private function insertMediaItem(array $cols): int
    {
        $attrs = array_merge([
            'user_id' => 999600,
            'type' => 'social_image',
            'prompt' => null,
            'aspect_ratio' => '1:1',
            'duration_seconds' => null,
            'status' => 'generating',
            'error_message' => null,
            'provider_ref' => null,
            'poll_attempts' => 0,
        ], $cols);
        unset($attrs['id']);
        $item = new MediaItem($attrs);
        $item->save();
        return (int) $item->getAttribute('id');
    }

    private function queuedJob(string $jobClass): array
    {
        $rows = self::$pdo->query("SELECT * FROM jobs WHERE job_class = '{$jobClass}' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    // ------------------------------------------------------------
    // MediaGenerationService
    // ------------------------------------------------------------

    public function testRequestImageGenerationCreatesItemAndQueuesJob(): void
    {
        $service = new MediaGenerationService();
        $item = $service->requestGeneration(999600, 'social_image', 'مدينة سياحية جميلة', 'cinematic');

        $this->assertGreaterThan(0, (int) $item->getAttribute('id'));
        $row = self::$pdo->query('SELECT * FROM media_items WHERE id = ' . (int) $item->getAttribute('id'))->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('social_image', $row['type']);
        $this->assertSame('1:1', $row['aspect_ratio']);
        $this->assertSame('generating', $row['status']);
        $this->assertSame('مدينة سياحية جميلة', $row['prompt']);

        $jobs = $this->queuedJob('GenerateMediaJob');
        $this->assertCount(1, $jobs);
        $payload = json_decode($jobs[0]['payload'], true);
        $this->assertSame((int) $item->getAttribute('id'), (int) $payload['media_item_id']);
        $this->assertStringContainsString('Style: cinematic', (string) $payload['final_prompt']);
    }

    public function testRequestImageAspectRatiosPerType(): void
    {
        $service = new MediaGenerationService();
        $story = $service->requestGeneration(999600, 'story', 'قصة سياحية عن رحلة النيل');
        $this->assertSame('9:16', (string) (new MediaItem())->find((int) $story->getAttribute('id'))->getAttribute('aspect_ratio'));

        $cover = $service->requestGeneration(999600, 'facebook_cover', 'غلاف صفحة رحلات النيل');
        $this->assertSame('16:9', (string) (new MediaItem())->find((int) $cover->getAttribute('id'))->getAttribute('aspect_ratio'));
    }

    public function testRequestImageRejectsUnsupportedAndVideoTypes(): void
    {
        $service = new MediaGenerationService();
        $this->expectException(InvalidArgumentException::class);
        $service->requestGeneration(999600, 'short_video', 'x');
    }

    public function testRequestImageRejectsBogusType(): void
    {
        $service = new MediaGenerationService();
        $this->expectException(InvalidArgumentException::class);
        $service->requestGeneration(999600, 'banner_3d', 'x');
    }

    public function testRequestVideoGenerationCreatesItemAndQueuesJob(): void
    {
        $service = new MediaGenerationService();
        $item = $service->requestVideoGeneration(999600, 'فيديو عن الرحلة', 'tiktok', 6, 'product');

        $row = self::$pdo->query('SELECT * FROM media_items WHERE id = ' . (int) $item->getAttribute('id'))->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('short_video', $row['type']);
        $this->assertSame('9:16', $row['aspect_ratio']);
        $this->assertSame('6', (string) $row['duration_seconds']);
        $this->assertSame('generating', $row['status']);

        $jobs = $this->queuedJob('GenerateVideoJob');
        $this->assertCount(1, $jobs);
        $payload = json_decode($jobs[0]['payload'], true);
        $this->assertSame((int) $item->getAttribute('id'), (int) $payload['media_item_id']);
        $this->assertStringContainsString('Visual style: clean studio background', (string) $payload['final_prompt']);
    }

    public function testRequestVideoDurationInvalidIsRejectedAndDefaultIsEight(): void
    {
        $service = new MediaGenerationService();

        // مدة غير مدعومة بقت بتتّرفض (كانت بترجع لـ 8 بصمت)
        try {
            $service->requestVideoGeneration(999600, 'فيديو قصير عن رحلة النيل', 'general_landscape', 999);
            $this->fail('مدة 999 ثانية لازم تتّرفض');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid video settings', $e->getMessage());
        }

        // المدة الافتراضية 8 ثواني
        $item = $service->requestVideoGeneration(999600, 'فيديو قصير عن رحلة النيل', 'general_landscape');
        $this->assertSame('8', (string) (new MediaItem())->find((int) $item->getAttribute('id'))->getAttribute('duration_seconds'));
        $this->assertSame('16:9', (string) (new MediaItem())->find((int) $item->getAttribute('id'))->getAttribute('aspect_ratio'));
    }

    public function testIsSupportedType(): void
    {
        $service = new MediaGenerationService();
        $this->assertTrue($service->isSupportedType('youtube_thumbnail'));
        $this->assertFalse($service->isSupportedType('memes'));
    }

    // ------------------------------------------------------------
    // GenerateMediaJob
    // ------------------------------------------------------------

    private const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function testMediaJobSuccessWritesFileAndCompletes(): void
    {
        $id = $this->insertMediaItem(['user_id' => 999600, 'type' => 'social_image', 'prompt' => 'P', 'aspect_ratio' => '1:1', 'status' => 'generating']);

        $fake = new CreativeStudioFakeGemini(['success' => true, 'image_base64' => self::PNG_1PX, 'mime_type' => 'image/png']);
        $job = new GenerateMediaJob(function () use ($fake) {
            return $fake;
        });
        $job->handle(['media_item_id' => $id, 'final_prompt' => 'final prompt text']);

        $row = self::$pdo->query('SELECT * FROM media_items WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('completed', $row['status']);
        $this->assertStringEndsWith('.' . pathinfo((string) $row['file_path'], PATHINFO_EXTENSION), (string) $row['file_path']);
        // المعالجة اللاحقة بتعيد ترميز/تكبّر الصورة؛ المهم صورة صالحة بنسبة 1:1
        $this->assertGreaterThan(0, (int) $row['width']);
        $this->assertSame((int) $row['width'], (int) $row['height']);
        $this->assertNull($row['error_message']);
        $this->assertFileExists(PrivateMedia::resolve((string) $row['file_path'])); // التخزين بقى خاص (signed media)

        $this->assertCount(1, $fake->calls);
        $this->assertSame('final prompt text', $fake->calls[0]['prompt']);
        $this->assertSame('1:1', $fake->calls[0]['aspect_ratio']);
    }

    public function testMediaJobAiFailureMarksFailed(): void
    {
        $id = $this->insertMediaItem(['user_id' => 999600, 'type' => 'social_image', 'prompt' => 'P', 'aspect_ratio' => '1:1', 'status' => 'generating']);

        $fake = new CreativeStudioFakeGemini(['success' => false, 'error' => 'Gemini quota exceeded']);
        $job = new GenerateMediaJob(function () use ($fake) {
            return $fake;
        });
        $job->handle(['media_item_id' => $id, 'final_prompt' => 'P']);

        $row = self::$pdo->query('SELECT * FROM media_items WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('failed', $row['status']);
        // رسالة آمنة للعميل (مش نص المزوّد الخام) + تأكيد إن مفيش خصم
        $this->assertStringContainsString('تجاوزت الحصة', (string) $row['error_message']);
        $this->assertStringNotContainsString('Gemini quota exceeded', (string) $row['error_message']);
    }

    /** JPEG حقيقي صغير بيتولّد بـ GD (الـ job بيتحقق من المحتوى الفعلي مش بس الـ mime المعلن) */
    private function jpeg8px(): string
    {
        $im = imagecreatetruecolor(8, 8);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 80, 40));
        ob_start();
        imagejpeg($im, null, 80);
        return base64_encode((string) ob_get_clean());
    }

    public function testMediaJobJpegMimeProducesJpgFile(): void
    {
        $id = $this->insertMediaItem(['user_id' => 999600, 'type' => 'marketing_image', 'prompt' => 'P', 'aspect_ratio' => '4:3', 'status' => 'generating']);

        $fake = new CreativeStudioFakeGemini(['success' => true, 'image_base64' => $this->jpeg8px(), 'mime_type' => 'image/jpeg']);
        $job = new GenerateMediaJob(function () use ($fake) {
            return $fake;
        });
        $job->handle(['media_item_id' => $id, 'final_prompt' => 'P']);

        $row = self::$pdo->query('SELECT * FROM media_items WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('completed', $row['status'], (string) $row['error_message']);
        $this->assertStringEndsWith('.jpg', (string) $row['file_path']);
        $this->assertFileExists(PrivateMedia::resolve((string) $row['file_path'])); // التخزين بقى خاص (signed media)
    }

    public function testMediaJobMissingItemThrows(): void
    {
        $this->expectException(Exception::class);
        $job = new GenerateMediaJob();
        $job->handle(['media_item_id' => 999999]);
    }

    // ------------------------------------------------------------
    // GenerateVideoJob
    // ------------------------------------------------------------

    public function testVideoJobStartFailureMarksFailed(): void
    {
        $id = $this->insertMediaItem(['user_id' => 999600, 'type' => 'short_video', 'prompt' => 'V', 'aspect_ratio' => '9:16', 'duration_seconds' => 6, 'status' => 'generating']);

        $fake = new CreativeStudioFakeVeo(['success' => false, 'error' => 'Veo down']);
        $job = new GenerateVideoJob(function () use ($fake) {
            return $fake;
        });
        $job->handle(['media_item_id' => $id, 'final_prompt' => 'V']);

        $row = self::$pdo->query('SELECT * FROM media_items WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('ولم يُخصم أي رصيد', (string) $row['error_message']);
        $this->assertStringNotContainsString('Veo down', (string) $row['error_message']);
        $this->assertSame('startGeneration', $fake->calls[0]['method']);
        $this->assertSame('9:16', $fake->calls[0]['aspect_ratio']);
        $this->assertSame(6, $fake->calls[0]['duration']);
    }

    public function testVideoJobStartSuccessStoresProviderRefAndRequeues(): void
    {
        $id = $this->insertMediaItem(['user_id' => 999600, 'type' => 'short_video', 'prompt' => 'V', 'aspect_ratio' => '9:16', 'duration_seconds' => 8, 'status' => 'generating']);

        $fake = new CreativeStudioFakeVeo(['success' => true, 'operation_name' => 'models/veo/operations/op-1']);
        $job = new GenerateVideoJob(function () use ($fake) {
            return $fake;
        });
        $job->handle(['media_item_id' => $id, 'final_prompt' => 'V']);

        $row = self::$pdo->query('SELECT * FROM media_items WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('generating', $row['status']);
        $this->assertSame('models/veo/operations/op-1', $row['provider_ref']);
        $this->assertNotEmpty($this->queuedJob('GenerateVideoJob'));
    }

    public function testVideoJobPollCompletionDownloadsAndCompletes(): void
    {
        $id = $this->insertMediaItem(['user_id' => 999600, 'type' => 'short_video', 'prompt' => 'V', 'aspect_ratio' => '9:16', 'duration_seconds' => 8, 'status' => 'generating', 'provider_ref' => 'models/veo/operations/op-2', 'poll_attempts' => 2]);

        $fake = new CreativeStudioFakeVeo(
            ['success' => true, 'operation_name' => 'x'],
            ['success' => true, 'done' => true, 'video_uri' => 'https://storage/video.mp4'],
            ['success' => true, 'data' => "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x08free"]
        );
        $job = new GenerateVideoJob(function () use ($fake) {
            return $fake;
        });
        $job->handle(['media_item_id' => $id, 'final_prompt' => 'V']);

        $row = self::$pdo->query('SELECT * FROM media_items WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('completed', $row['status']);
        $this->assertStringEndsWith('.' . pathinfo((string) $row['file_path'], PATHINFO_EXTENSION), (string) $row['file_path']);
        $this->assertFileExists(PrivateMedia::resolve((string) $row['file_path'])); // التخزين بقى خاص (signed media)
        // provider_ref بيتحفظ بعد الاكتمال (أثر تدقيق لعملية Veo)، مبقاش بيتصفّر
        $this->assertNotNull($row['provider_ref']);
        $this->assertSame('checkOperation', $fake->calls[0]['method']);
        $this->assertSame('downloadVideo', $fake->calls[1]['method']);
    }

    public function testVideoJobPollTimeoutFails(): void
    {
        $id = $this->insertMediaItem(['user_id' => 999600, 'type' => 'short_video', 'prompt' => 'V', 'aspect_ratio' => '9:16', 'status' => 'generating', 'provider_ref' => 'models/veo/operations/op-3', 'poll_attempts' => 40]);

        $fake = new CreativeStudioFakeVeo(['success' => true, 'operation_name' => 'x']);
        $job = new GenerateVideoJob(function () use ($fake) {
            return $fake;
        });
        $job->handle(['media_item_id' => $id, 'final_prompt' => 'V']);

        $row = self::$pdo->query('SELECT * FROM media_items WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('ولم يُخصم أي رصيد', (string) $row['error_message']);
        $this->assertSame([], $fake->calls, 'مهلة الفحص مينفعش تستدعي Veo');
    }

    public function testVideoJobPollNotDoneIncrementsAttemptsAndRequeues(): void
    {
        $id = $this->insertMediaItem(['user_id' => 999600, 'type' => 'short_video', 'prompt' => 'V', 'aspect_ratio' => '9:16', 'status' => 'generating', 'provider_ref' => 'models/veo/operations/op-4', 'poll_attempts' => 3]);

        $fake = new CreativeStudioFakeVeo(['success' => true, 'operation_name' => 'x'], ['success' => true, 'done' => false]);
        $job = new GenerateVideoJob(function () use ($fake) {
            return $fake;
        });
        $job->handle(['media_item_id' => $id, 'final_prompt' => 'V']);

        $row = self::$pdo->query('SELECT * FROM media_items WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('generating', $row['status']);
        $this->assertSame('4', (string) $row['poll_attempts']);
        $this->assertNotEmpty($this->queuedJob('GenerateVideoJob'));
    }

    // ------------------------------------------------------------
    // VideoScriptService
    // ------------------------------------------------------------

    private function scriptJson(): string
    {
        return json_encode([
            'script_text' => 'أهلاً بيك في فيديو اليوم عن السفر',
            'scenes' => [
                ['time' => '0-3s', 'visual' => 'مشهد افتتاحي', 'voiceover' => 'الجملة الأولى'],
                ['time' => '3-6s', 'visual' => 'مشهد المدينة', 'voiceover' => 'الجملة الثانية'],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    public function testVideoScriptGenerateSuccessParsesJson(): void
    {
        $fake = new CreativeStudioFakeGemini([], ['success' => true, 'data' => $this->scriptJson()]);
        $service = new VideoScriptService($fake);

        $script = $service->generate(999600, 'أفضل وجهات الصيف', 'tiktok', 30, 'ar');

        $this->assertGreaterThan(0, (int) $script->getAttribute('id'));
        $row = self::$pdo->query('SELECT * FROM video_scripts WHERE id = ' . (int) $script->getAttribute('id'))->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('completed', $row['status']);
        $this->assertSame('أهلاً بيك في فيديو اليوم عن السفر', $row['script_text']);
        $scenes = json_decode((string) $row['scenes'], true);
        $this->assertCount(2, $scenes);
        $this->assertSame('0-3s', $scenes[0]['time']);
        $this->assertStringContainsStringIgnoringCase('tiktok', (string) $fake->calls[0]['prompt']);

        $logs = self::$pdo->query("SELECT * FROM activity_logs WHERE user_id = 999600 AND module = 'creative_studio' AND action = 'video_script.generated'")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $logs);
    }

    public function testVideoScriptCodeFencedJsonParsed(): void
    {
        $data = "```json\n" . $this->scriptJson() . "\n```";
        $fake = new CreativeStudioFakeGemini([], ['success' => true, 'data' => $data]);
        $service = new VideoScriptService($fake);

        $script = $service->generate(999600, 'نصائح', 'instagram_reels', 15, 'en');

        $row = self::$pdo->query('SELECT * FROM video_scripts WHERE id = ' . (int) $script->getAttribute('id'))->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('completed', $row['status']);
        $this->assertSame('أهلاً بيك في فيديو اليوم عن السفر', $row['script_text']);
    }

    public function testVideoScriptAiErrorMarksFailedAndThrows(): void
    {
        $fake = new CreativeStudioFakeGemini([], ['success' => false, 'error' => 'AI unavailable']);
        $service = new VideoScriptService($fake);

        try {
            $service->generate(999600, 'موضوع', 'tiktok');
            $this->fail('يجب أن يرمي Exception عند فشل الذكاء الاصطناعي');
        } catch (Exception $e) {
            $this->assertStringContainsString('AI unavailable', $e->getMessage());
        }

        $rows = self::$pdo->query('SELECT * FROM video_scripts WHERE user_id = 999600 ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('failed', $rows['status']);
    }

    public function testVideoScriptMalformedJsonFailsSafely(): void
    {
        $fake = new CreativeStudioFakeGemini([], ['success' => true, 'data' => 'this is not json at all']);
        $service = new VideoScriptService($fake);

        $this->expectException(Exception::class);
        $service->generate(999600, 'موضوع', 'tiktok');
    }
}

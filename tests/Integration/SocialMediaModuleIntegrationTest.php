<?php

/**
 * Tourfecto - Social Media (Item 3c) Integration Test
 * بيفحص عملاء النشر الاجتماعي (Meta/TikTok/YouTube) + توليد الكابشن
 * **بمصادر حقيقية وصفر شبكة** — حقن transport وهمي بنفس بنية رد curl
 * (نفس نمط حقن WordPressPublisher من م3) + محرك Gemini وهمي:
 *   1) MetaSocialAPI: listPages (نجاح/خطأ auth/فشل شبكة)، النشر على صفحة
 *      فيسبوك (نص + صورة)، انستجرام خطوتين (container + publish)،
 *      video container + فحص الحالة، ونشر container.
 *   2) TikTokAPI: publishVideo بيرفع publish_id + فحص الحالة (PUBLISHED/
 *      FAILED) + خطأ API.
 *   3) YouTubeAPI: checkVideoStatus (FINISHED/IN_PROGRESS/ERROR/مفقود/
 *      فشل شبكة) + validation محلي لملف غير موجود.
 *   4) SocialPostService::generateCaption: نجاح وفشل عبر GeminiClient وهمي
 *      (بدون أي شبكة/AI حقيقية).
 *
 * لا يمس أي جدول — كل شيء داخل الذاكرة.
 * @version 1.0.0  @date 2026-08-31
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AI/GeminiClient.php';
require_once __DIR__ . '/../../app/Services/SocialMedia/MetaSocialAPI.php';
require_once __DIR__ . '/../../app/Services/SocialMedia/TikTokAPI.php';
require_once __DIR__ . '/../../app/Services/SocialMedia/YouTubeAPI.php';
require_once __DIR__ . '/../../app/Services/SocialMedia/SocialPostService.php';

/** transport وهمي بيسجّل الطلبات ويرجّع استجابات مسرّحة بنفس بنية رد curl */
final class SocialMediaTransportFake
{
    public array $calls = [];
    private array $responses;

    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function __invoke(array $req): array
    {
        $this->calls[] = $req;
        $idx = min(count($this->calls) - 1, count($this->responses) - 1);
        return $this->responses[$idx];
    }
}

/** محرك Gemini وهمي (نفس نمط MarketingFakeGemini من م6) - صفر شبكة */
final class SocialFakeGemini extends GeminiClient
{
    public array $calls = [];
    private array $result;

    public function __construct(array $result = ['success' => true, 'data' => '{}'])
    {
        $this->result = $result;
    }

    public function generateContent(string $prompt, array $options = []): array
    {
        $this->calls[] = ['prompt' => $prompt, 'options' => $options];
        return $this->result;
    }
}

final class SocialMediaModuleIntegrationTest extends TestCase
{
    // ================================================================
    // MetaSocialAPI — listPages
    // ================================================================

    public function testMetaListPagesMapsPagesAndInstagram(): void
    {
        $transport = new SocialMediaTransportFake([
            ['http_code' => 200, 'body' => json_encode([
                'data' => [
                    ['id' => 'page1', 'name' => 'Agency Page', 'access_token' => 'pat1', 'instagram_business_account' => ['id' => 'ig1', 'username' => 'agency.eg']],
                    ['id' => 'page2', 'name' => 'Second', 'access_token' => 'pat2'],
                ],
            ]), 'error' => null],
        ]);
        $api = new MetaSocialAPI('user-tok', $transport);

        $result = $api->listPages();
        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['pages']);
        $this->assertSame('Agency Page', $result['pages'][0]['name']);
        $this->assertSame('pat1', $result['pages'][0]['access_token']);
        $this->assertSame('ig1', $result['pages'][0]['instagram_id']);
        $this->assertSame('agency.eg', $result['pages'][0]['instagram_username']);
        $this->assertNull($result['pages'][1]['instagram_id']);

        $this->assertStringContainsString('graph.facebook.com/v25.0/me/accounts', $transport->calls[0]['url']);
        $this->assertStringContainsString('access_token=user-tok', $transport->calls[0]['url']);
    }

    public function testMetaListPagesAuthError(): void
    {
        $transport = new SocialMediaTransportFake([
            ['http_code' => 200, 'body' => json_encode([
                'error' => ['message' => 'Invalid OAuth access token'],
            ]), 'error' => null],
        ]);
        $api = new MetaSocialAPI('bad', $transport);

        $result = $api->listPages();
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid OAuth access token', $result['error']);
    }

    public function testMetaListPagesNetworkFailure(): void
    {
        $transport = new SocialMediaTransportFake([
            ['http_code' => 0, 'body' => '', 'error' => 'Could not resolve host: graph.facebook.com'],
        ]);
        $api = new MetaSocialAPI('tok', $transport);

        $result = $api->listPages();
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('cURL Error', $result['error']);
    }

    // ================================================================
    // MetaSocialAPI — النشر
    // ================================================================

    public function testMetaPublishTextToFacebookPage(): void
    {
        $transport = new SocialMediaTransportFake([
            ['http_code' => 200, 'body' => json_encode(['id' => 'post123']), 'error' => null],
        ]);
        $api = new MetaSocialAPI('user-tok', $transport);

        $result = $api->publishToFacebookPage('page1', 'pat1', 'مرحبا من تورفكتو');
        $this->assertTrue($result['success']);
        $this->assertSame('post123', $result['post_id']);
        $this->assertSame('https://www.facebook.com/post123', $result['post_url']);

        $call = $transport->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertStringContainsString('/v25.0/page1/feed', $call['url']);
        parse_str((string) $call['body'], $payload);
        $this->assertSame('مرحبا من تورفكتو', $payload['message']);
        $this->assertSame('pat1', $payload['access_token']);
    }

    public function testMetaPublishWithImageUsesPhotosEndpoint(): void
    {
        $transport = new SocialMediaTransportFake([
            ['http_code' => 200, 'body' => json_encode(['id' => 'photo-post']), 'error' => null],
        ]);
        $api = new MetaSocialAPI('user-tok', $transport);

        $result = $api->publishToFacebookPage('page1', 'pat1', 'رحلة الصحراء', 'https://cdn.example/x.jpg');
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('/v25.0/page1/photos', $transport->calls[0]['url']);
        parse_str((string) $transport->calls[0]['body'], $payload);
        $this->assertSame('https://cdn.example/x.jpg', $payload['url']);
        $this->assertSame('رحلة الصحراء', $payload['caption']);
    }

    public function testMetaPublishToInstagramTwoStepFlow(): void
    {
        $transport = new SocialMediaTransportFake([
            ['http_code' => 200, 'body' => json_encode(['id' => 'cont1']), 'error' => null],
            ['http_code' => 200, 'body' => json_encode(['id' => 'igpost1']), 'error' => null],
        ]);
        $api = new MetaSocialAPI('user-tok', $transport);

        $result = $api->publishToInstagram('ig1', 'pat1', 'https://cdn.example/x.jpg', 'كابشن');
        $this->assertTrue($result['success']);
        $this->assertSame('igpost1', $result['post_id']);

        $first = $transport->calls[0];
        $this->assertStringContainsString('/v25.0/ig1/media', $first['url']);
        parse_str((string) $first['body'], $cPayload);
        $this->assertSame('https://cdn.example/x.jpg', $cPayload['image_url']);

        $second = $transport->calls[1];
        $this->assertStringContainsString('/v25.0/ig1/media_publish', $second['url']);
        parse_str((string) $second['body'], $pPayload);
        $this->assertSame('cont1', $pPayload['creation_id']);
    }

    public function testMetaPublishToInstagramContainerMissing(): void
    {
        $transport = new SocialMediaTransportFake([
            ['http_code' => 200, 'body' => json_encode([]), 'error' => null],
        ]);
        $api = new MetaSocialAPI('user-tok', $transport);

        $result = $api->publishToInstagram('ig1', 'pat1', 'https://cdn.example/x.jpg');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('container', $result['error']);
    }

    public function testMetaInstagramVideoContainerLifecycle(): void
    {
        $transport = new SocialMediaTransportFake([
            ['http_code' => 200, 'body' => json_encode(['id' => 'reel1']), 'error' => null],
            ['http_code' => 200, 'body' => json_encode(['status_code' => 'FINISHED']), 'error' => null],
            ['http_code' => 200, 'body' => json_encode(['id' => 'igreel1']), 'error' => null],
        ]);
        $api = new MetaSocialAPI('user-tok', $transport);

        $container = $api->createInstagramVideoContainer('ig1', 'pat1', 'https://cdn.example/v.mp4', 'ريلز');
        $this->assertTrue($container['success']);
        $this->assertSame('reel1', $container['container_id']);

        parse_str((string) $transport->calls[0]['body'], $cPayload);
        $this->assertSame('REELS', $cPayload['media_type']);
        $this->assertSame('https://cdn.example/v.mp4', $cPayload['video_url']);

        $status = $api->checkInstagramContainerStatus('reel1', 'pat1');
        $this->assertTrue($status['success']);
        $this->assertSame('FINISHED', $status['status']);
        $this->assertStringContainsString('/v25.0/reel1?', $transport->calls[1]['url']);

        $publish = $api->publishInstagramContainer('ig1', 'pat1', 'reel1');
        $this->assertTrue($publish['success']);
        $this->assertSame('igreel1', $publish['post_id']);
        parse_str((string) $transport->calls[2]['body'], $pPayload);
        $this->assertSame('reel1', $pPayload['creation_id']);
    }

    // ================================================================
    // TikTokAPI
    // ================================================================

    public function testTikTokPublishVideoHoistsPublishId(): void
    {
        $transport = new SocialMediaTransportFake([
            ['http_code' => 200, 'body' => json_encode([
                'data' => ['publish_id' => 'pub-1'],
                'error' => ['code' => 0],
            ]), 'error' => null],
        ]);
        $api = new TikTokAPI('tt-tok', 'open-id-1', $transport);

        $result = $api->publishVideo('https://cdn.example/v.mp4', 'مغامرة الصحراء');
        $this->assertTrue($result['success']);
        $this->assertSame('pub-1', $result['publish_id']);

        $call = $transport->calls[0];
        $this->assertStringContainsString('open-api.tiktok.com/share/video/upload/', $call['url']);
        parse_str((string) $call['body'], $payload);
        $this->assertSame('tt-tok', $payload['access_token']);
        $this->assertSame('open-id-1', $payload['open_id']);
        $sourceInfo = json_decode($payload['source_info'], true);
        $this->assertSame('PULL_FROM_URL', $sourceInfo['source']);
        $this->assertSame('https://cdn.example/v.mp4', $sourceInfo['url']);
        $this->assertSame('public', $payload['privacy_level']);
    }

    public function testTikTokCheckPublishStatus(): void
    {
        $transport = new SocialMediaTransportFake([
            ['http_code' => 200, 'body' => json_encode([
                'data' => ['data' => ['status' => 'PUBLISHED']],
                'error' => ['code' => 0],
            ]), 'error' => null],
        ]);
        $api = new TikTokAPI('tt-tok', 'open-id-1', $transport);

        $result = $api->checkPublishStatus('pub-1');
        $this->assertTrue($result['success']);
        $this->assertSame('PUBLISHED', $result['status']);
        $this->assertStringContainsString('publish_id=pub-1', $transport->calls[0]['url']);
    }

    public function testTikTokCheckPublishStatusFailed(): void
    {
        $transport = new SocialMediaTransportFake([
            ['http_code' => 200, 'body' => json_encode([
                'data' => ['data' => ['status' => 'FAILED', 'fail_reason' => 'invalid video format']],
                'error' => ['code' => 0],
            ]), 'error' => null],
        ]);
        $api = new TikTokAPI('tt-tok', 'open-id-1', $transport);

        $result = $api->checkPublishStatus('pub-1');
        $this->assertTrue($result['success']);
        $this->assertSame('FAILED', $result['status']);
        $this->assertSame('invalid video format', $result['error']);
    }

    public function testTikTokApiErrorSurfaces(): void
    {
        $transport = new SocialMediaTransportFake([
            ['http_code' => 200, 'body' => json_encode([
                'data' => [],
                'error' => ['code' => 10001, 'message' => 'unauthorized access'],
            ]), 'error' => null],
        ]);
        $api = new TikTokAPI('bad', 'open-id-1', $transport);

        $result = $api->checkPublishStatus('pub-1');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('unauthorized access', $result['error']);
    }

    // ================================================================
    // YouTubeAPI
    // ================================================================

    public function testYouTubeCheckVideoStatusFinished(): void
    {
        $transport = new SocialMediaTransportFake([
            ['http_code' => 200, 'body' => json_encode([
                'items' => [
                    ['status' => ['uploadStatus' => 'processed'], 'processingDetails' => ['processingStatus' => 'succeeded']],
                ],
            ]), 'error' => null],
        ]);
        $api = new YouTubeAPI('yt-tok', $transport);

        $result = $api->checkVideoStatus('vid-1');
        $this->assertTrue($result['success']);
        $this->assertSame('FINISHED', $result['status']);
        $this->assertStringContainsString('youtube/v3/videos?id=vid-1', $transport->calls[0]['url']);
        $this->assertContains('Authorization: Bearer yt-tok', $transport->calls[0]['headers']);
    }

    public function testYouTubeCheckVideoStatusInProgress(): void
    {
        $transport = new SocialMediaTransportFake([
            ['http_code' => 200, 'body' => json_encode([
                'items' => [['status' => ['uploadStatus' => 'uploaded'], 'processingDetails' => ['processingStatus' => 'inProgress']]],
            ]), 'error' => null],
        ]);
        $api = new YouTubeAPI('yt-tok', $transport);

        $result = $api->checkVideoStatus('vid-1');
        $this->assertTrue($result['success']);
        $this->assertSame('IN_PROGRESS', $result['status']);
    }

    public function testYouTubeCheckVideoStatusFailed(): void
    {
        $transport = new SocialMediaTransportFake([
            ['http_code' => 200, 'body' => json_encode([
                'items' => [['status' => ['uploadStatus' => 'failed', 'rejectionReason' => 'copyright claim']]],
            ]), 'error' => null],
        ]);
        $api = new YouTubeAPI('yt-tok', $transport);

        $result = $api->checkVideoStatus('vid-1');
        $this->assertTrue($result['success']);
        $this->assertSame('ERROR', $result['status']);
        $this->assertSame('copyright claim', $result['error']);
    }

    public function testYouTubeCheckVideoStatusMissing(): void
    {
        $transport = new SocialMediaTransportFake([
            ['http_code' => 200, 'body' => json_encode(['items' => []]), 'error' => null],
        ]);
        $api = new YouTubeAPI('yt-tok', $transport);

        $result = $api->checkVideoStatus('vid-999');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('غير موجود', $result['error']);
    }

    public function testYouTubeCheckVideoStatusNetworkFailure(): void
    {
        $transport = new SocialMediaTransportFake([
            ['http_code' => 0, 'body' => '', 'error' => 'Connection refused'],
        ]);
        $api = new YouTubeAPI('yt-tok', $transport);

        $result = $api->checkVideoStatus('vid-1');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('cURL Error', $result['error']);
    }

    public function testYouTubeUploadShortRejectsMissingFile(): void
    {
        $api = new YouTubeAPI('yt-tok', new SocialMediaTransportFake([]));
        $result = $api->uploadShort('/nonexistent/video.mp4', 'Short');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('غير موجود', $result['error']);
    }

    // ================================================================
    // SocialPostService — generateCaption عبر AI وهمي
    // ================================================================

    public function testGenerateCaptionSuccessParsesJson(): void
    {
        $fake = new SocialFakeGemini([
            'success' => true,
            'data' => '{"content": "اكتشف جمال الصحراء! رحلة لا تُنسى.", "hashtags": ["#saudi", "#desert"]}',
        ]);
        $service = new SocialPostService($fake);

        $result = $service->generateCaption('رحلة صحراوية', 'instagram', 'ar');
        $this->assertTrue($result['success']);
        $this->assertSame('اكتشف جمال الصحراء! رحلة لا تُنسى.', $result['content']);
        $this->assertSame(['#saudi', '#desert'], $result['hashtags']);

        $this->assertCount(1, $fake->calls);
        $this->assertStringContainsString('رحلة صحراوية', $fake->calls[0]['prompt']);
        $this->assertSame('application/json', $fake->calls[0]['options']['responseMimeType']);
    }

    public function testGenerateCaptionStripsJsonFences(): void
    {
        $fake = new SocialFakeGemini([
            'success' => true,
            'data' => "```json\n{\"content\": \"Hello\", \"hashtags\": [\"#trip\"]}\n```",
        ]);
        $service = new SocialPostService($fake);

        $result = $service->generateCaption('Trip', 'tiktok', 'en');
        $this->assertTrue($result['success']);
        $this->assertSame('Hello', $result['content']);
        $this->assertSame(['#trip'], $result['hashtags']);
    }

    public function testGenerateCaptionAiFailureSurfaces(): void
    {
        $fake = new SocialFakeGemini(['success' => false, 'error' => 'Gemini rate limit']);
        $service = new SocialPostService($fake);

        $result = $service->generateCaption('Trip', 'instagram');
        $this->assertFalse($result['success']);
        $this->assertSame('Gemini rate limit', $result['error']);
    }

    public function testGenerateCaptionUnparseableJsonSurfaces(): void
    {
        $fake = new SocialFakeGemini(['success' => true, 'data' => 'not-json-at-all']);
        $service = new SocialPostService($fake);

        $result = $service->generateCaption('Trip', 'instagram');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('تحليل', $result['error']);
    }
}

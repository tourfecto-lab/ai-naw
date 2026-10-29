<?php

use PHPUnit\Framework\TestCase;

/** Gemini Omni (Interactions API) replaced Veo for video: request shape, answer parsing, file readiness and errors. */
final class GeminiOmniClientTest extends TestCase
{
    private const MP4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x08free";

    private function client(callable $answer, ?array &$calls = null): GeminiOmniClient
    {
        $calls = [];
        return new class('gemini-omni-1.1-flash', $answer, $calls) extends GeminiOmniClient {
            private $answer;
            private $log;

            public function __construct(string $model, callable $answer, array &$calls)
            {
                parent::__construct($model);
                $this->answer = $answer;
                $this->log = &$calls;
                $this->apiKey = 'test-key';
            }

            protected function request(string $method, string $url, ?array $payload): array
            {
                $this->log[] = [$method, $url, $payload];
                return ($this->answer)($method, $url, $payload);
            }

            protected function pause(int $seconds): void
            {
            }
        };
    }

    public function testModelsAreRoutedToTheRightClient(): void
    {
        $this->assertTrue(GeminiOmniClient::handles('gemini-omni-1.1-flash'));
        $this->assertFalse(GeminiOmniClient::handles('veo-3.1-fast-generate-preview'));
        $this->assertInstanceOf(GeminiOmniClient::class, GeminiOmniClient::forModel('gemini-omni-1.1-flash'));
        $this->assertNotInstanceOf(GeminiOmniClient::class, GeminiOmniClient::forModel('veo-3.1-generate-preview'));
        $this->assertTrue(VeoClient::supportsResolution('1080p', 'gemini-omni-1.1-flash'));
        $this->assertFalse(VeoClient::supportsResolution('4k', 'veo-3.0-fast-generate-001'));
    }

    public function testTextToVideoRequest(): void
    {
        $b = GeminiOmniClient::buildRequest('gemini-omni-1.1-flash', 'A felucca at sunset', '9:16', 8, ['resolution' => '1080p', 'negative_prompt' => 'text, logos']);
        $this->assertSame('gemini-omni-1.1-flash', $b['model']);
        $this->assertSame(['type' => 'video', 'aspect_ratio' => '9:16', 'resolution' => '1080p', 'delivery' => 'uri'], $b['response_format']);
        $this->assertIsString($b['input']);
        $this->assertStringContainsString('A felucca at sunset', $b['input']);
        $this->assertStringContainsString('about 8 seconds', $b['input']);
        $this->assertStringContainsString('Avoid: text, logos', $b['input']);
        $this->assertArrayNotHasKey('generation_config', $b);
    }

    public function testImageToVideoRequestCarriesTheReference(): void
    {
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        $b = GeminiOmniClient::buildRequest('gemini-omni-1.1-flash', 'Slow drift', '16:9', 8, ['reference' => ['mimeType' => 'image/png', 'data' => $png]]);
        $this->assertSame('image', $b['input'][0]['type']);
        $this->assertSame('image/png', $b['input'][0]['mime_type']);
        $this->assertSame($png, $b['input'][0]['data']);
        $this->assertSame('text', $b['input'][1]['type']);
        $this->assertSame('image_to_video', $b['generation_config']['video_config']['task']);
    }

    public function testInlineVideoIsReturnedAsBytes(): void
    {
        $c = $this->client(fn () => ['success' => true, 'data' => ['id' => 'v1_abc', 'status' => 'completed', 'steps' => [
            ['type' => 'user_input'], ['type' => 'thought'],
            ['type' => 'model_output', 'content' => [['type' => 'video', 'mime_type' => 'video/mp4', 'data' => base64_encode(self::MP4)]]],
        ]]], $calls);
        $r = $c->startGeneration('x', '9:16', 8);
        $this->assertTrue($r['success'], json_encode($r));
        $this->assertSame('omni:v1_abc', $r['operation_name']);
        $this->assertSame(self::MP4, $r['video_data']);
        $this->assertStringEndsWith('/interactions', $calls[0][1]);
    }

    public function testHostedVideoWaitsForTheFileThenReturnsTheDownloadUrl(): void
    {
        $polls = 0;
        $c = $this->client(function ($m, $url) use (&$polls) {
            if ($m === 'POST') {
                return ['success' => true, 'data' => ['id' => 'i1', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'video', 'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/abc123xyz']]]]]];
            }
            $polls++;
            return ['success' => true, 'data' => ['state' => $polls < 3 ? 'PROCESSING' : 'ACTIVE']];
        }, $calls);
        $r = $c->startGeneration('x', '9:16', 8);
        $this->assertTrue($r['success'], json_encode($r));
        $this->assertSame(3, $polls);
        $this->assertStringEndsWith('/files/abc123xyz:download?alt=media', $r['video_uri']);
        $this->assertTrue(VeoClient::allowedDownloadUrl($r['video_uri']), 'the download goes through the existing SSRF-guarded downloader');
    }

    public function testFailuresAreReported(): void
    {
        $noVideo = $this->client(fn () => ['success' => true, 'data' => ['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => 'I cannot create that video.']]]]]]);
        $r = $noVideo->startGeneration('x', '9:16', 8);
        $this->assertFalse($r['success']);
        $this->assertStringContainsString('I cannot create that video', $r['error']);

        $failed = $this->client(fn () => ['success' => false, 'error' => 'Quota exceeded']);
        $this->assertSame('Quota exceeded', $failed->startGeneration('x', '9:16', 8)['error']);

        $failedFile = $this->client(fn ($m) => $m === 'POST'
            ? ['success' => true, 'data' => ['steps' => [['type' => 'model_output', 'content' => [['type' => 'video', 'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/zzz']]]]]]
            : ['success' => true, 'data' => ['state' => 'FAILED']]);
        $this->assertFalse($failedFile->startGeneration('x', '9:16', 8)['success']);

        $bad = $this->client(fn () => ['success' => true, 'data' => []]);
        $this->assertFalse($bad->startGeneration('x', '4:3', 8)['success'], 'unsupported ratio is refused before any request');
        $this->assertFalse($bad->checkOperation('omni:abc')['success'], 'Omni results cannot be polled');
    }
}

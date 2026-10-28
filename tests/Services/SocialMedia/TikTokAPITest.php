<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Services/SocialMedia/TikTokAPI.php';

class TikTokAPITest extends TestCase
{
    public function testPublishVideoSuccess(): void
    {
        // v2: طلب أول لـ creator_info (الخصوصيات المسموحة) ثم طلب بدء النشر
        $api = $this->createPartialMock(TikTokAPI::class, ['request']);
        $urls = [];
        $api->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function (string $method, string $url) use (&$urls) {
                $urls[] = $url;
                return count($urls) === 1
                    ? ['success' => true, 'data' => ['privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'SELF_ONLY']]]
                    : ['success' => true, 'data' => ['publish_id' => 'pub_123']];
            });

        $result = $api->publishVideo('https://example.com/vid.mp4', 'Test Title');
        $this->assertTrue($result['success']);
        $this->assertEquals('pub_123', $result['publish_id']);
        $this->assertEquals('PUBLIC_TO_EVERYONE', $result['privacy']);
        $this->assertStringContainsString('/v2/post/publish/creator_info/query/', $urls[0]);
        $this->assertStringContainsString('/v2/post/publish/video/init/', $urls[1]);
    }

    public function testPublishVideoFailure(): void
    {
        $api = $this->createPartialMock(TikTokAPI::class, ['request']);
        $api->expects($this->once())
            ->method('request')
            ->willReturn(['success' => false, 'error' => 'Invalid access_token']);

        $result = $api->publishVideo('https://example.com/vid.mp4', 'Test');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid', $result['error']);
    }

    public function testCheckPublishStatusPending(): void
    {
        $api = $this->createPartialMock(TikTokAPI::class, ['request']);
        $api->expects($this->once())
            ->method('request')
            ->willReturn([
                'success' => true,
                'data'    => ['status' => 'PROCESSING_DOWNLOAD'],
            ]);

        $result = $api->checkPublishStatus('pub_123');
        $this->assertTrue($result['success']);
        $this->assertEquals('PENDING', $result['status']);
    }

    public function testCheckPublishStatusPublished(): void
    {
        $api = $this->createPartialMock(TikTokAPI::class, ['request']);
        $api->expects($this->once())
            ->method('request')
            ->willReturn([
                'success' => true,
                'data'    => ['status' => 'PUBLISH_COMPLETE'],
            ]);

        $result = $api->checkPublishStatus('pub_123');
        $this->assertEquals('PUBLISHED', $result['status']);
    }

    public function testCheckPublishStatusFailed(): void
    {
        $api = $this->createPartialMock(TikTokAPI::class, ['request']);
        $api->expects($this->once())
            ->method('request')
            ->willReturn([
                'success' => true,
                'data'    => ['status' => 'FAILED', 'fail_reason' => 'Policy violation'],
            ]);

        $result = $api->checkPublishStatus('pub_123');
        $this->assertEquals('FAILED', $result['status']);
        $this->assertEquals('Policy violation', $result['error']);
    }
}

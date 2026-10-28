<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Services/SocialMedia/TikTokAPI.php';

class TikTokAPITest extends TestCase
{
    public function testPublishVideoSuccess(): void
    {
        $api = $this->createPartialMock(TikTokAPI::class, ['request']);
        $api->expects($this->once())
            ->method('request')
            ->with('POST', $this->stringContains('share/video/upload/'), $this->anything())
            ->willReturn([
                'success' => true,
                'data'    => ['publish_id' => 'pub_123'],
            ]);

        $result = $api->publishVideo('https://example.com/vid.mp4', 'Test Title');
        $this->assertTrue($result['success']);
        $this->assertEquals('pub_123', $result['publish_id']);
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
                'data'    => ['data' => ['status' => 'PENDING']],
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
                'data'    => ['data' => ['status' => 'PUBLISHED']],
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
                'data'    => ['data' => ['status' => 'FAILED', 'fail_reason' => 'Policy violation']],
            ]);

        $result = $api->checkPublishStatus('pub_123');
        $this->assertEquals('FAILED', $result['status']);
        $this->assertEquals('Policy violation', $result['error']);
    }
}

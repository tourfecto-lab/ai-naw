<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Services/SocialMedia/YouTubeAPI.php';

class YouTubeAPITest extends TestCase
{
    public function testCheckVideoStatusFinished(): void
    {
        $api = $this->createPartialMock(YouTubeAPI::class, ['request']);
        $api->expects($this->once())
            ->method('request')
            ->with('GET', $this->stringContains('videos?id=vid_123'))
            ->willReturn([
                'success' => true,
                'data'    => [
                    'items' => [[
                        'status'            => ['uploadStatus' => 'processed'],
                        'processingDetails' => ['processingStatus' => 'succeeded'],
                    ]],
                ],
            ]);

        $result = $api->checkVideoStatus('vid_123');
        $this->assertTrue($result['success']);
        $this->assertEquals('FINISHED', $result['status']);
    }

    public function testCheckVideoStatusInProgress(): void
    {
        $api = $this->createPartialMock(YouTubeAPI::class, ['request']);
        $api->expects($this->once())
            ->method('request')
            ->willReturn([
                'success' => true,
                'data'    => [
                    'items' => [[
                        'status'            => ['uploadStatus' => 'uploaded'],
                        'processingDetails' => ['processingStatus' => 'inProgress'],
                    ]],
                ],
            ]);

        $result = $api->checkVideoStatus('vid_123');
        $this->assertEquals('IN_PROGRESS', $result['status']);
    }

    public function testCheckVideoStatusError(): void
    {
        $api = $this->createPartialMock(YouTubeAPI::class, ['request']);
        $api->expects($this->once())
            ->method('request')
            ->willReturn([
                'success' => true,
                'data'    => [
                    'items' => [[
                        'status' => ['uploadStatus' => 'failed', 'rejectionReason' => 'Copyright claim'],
                    ]],
                ],
            ]);

        $result = $api->checkVideoStatus('vid_123');
        $this->assertEquals('ERROR', $result['status']);
        $this->assertStringContainsString('Copyright', $result['error']);
    }

    public function testCheckVideoStatusNotFound(): void
    {
        $api = $this->createPartialMock(YouTubeAPI::class, ['request']);
        $api->expects($this->once())
            ->method('request')
            ->willReturn([
                'success' => true,
                'data'    => ['items' => []],
            ]);

        $result = $api->checkVideoStatus('vid_123');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('غير موجود', $result['error']);
    }
}

<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/Seo/BaiduIndexingService.php';

class BaiduIndexingServiceTest extends TestCase
{
    public function testIsChinaTarget()
    {
        $this->assertTrue(BaiduIndexingService::isChinaTarget('[{"code":"zh"}]'));
        $this->assertTrue(BaiduIndexingService::isChinaTarget('[{"code":"zh-cn"}]'));
        $this->assertFalse(BaiduIndexingService::isChinaTarget('[{"code":"ar"}]'));
        $this->assertFalse(BaiduIndexingService::isChinaTarget(null));
    }

    public function testSubmitUrlsWithEmptyToken()
    {
        $service = new BaiduIndexingService();
        $result = $service->submitUrls('https://example.com', '', ['https://example.com/page']);
        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['status']);
    }
}

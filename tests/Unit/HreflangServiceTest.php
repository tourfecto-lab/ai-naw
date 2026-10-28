<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/Seo/SeoProxyService.php';

class HreflangServiceTest extends TestCase
{
    public function testBuildHreflangUrl()
    {
        $db = $this->createMock(Database::class);
        $service = new SeoProxyService($db);
        $ref = new ReflectionMethod($service, 'buildHreflangUrl');
        $ref->setAccessible(true);

        $this->assertSame(
            'https://example.com/fr/about',
            $ref->invoke($service, 'https://example.com/about', 'fr')
        );
        $this->assertSame(
            'https://example.com/zh-cn/',
            $ref->invoke($service, 'https://example.com/', 'zh-cn')
        );
    }
}

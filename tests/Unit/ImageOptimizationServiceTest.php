<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/Seo/ImageOptimizationService.php';

class ImageOptimizationServiceTest extends TestCase
{
    public function testApplyLazyLoadingSkipsFirstImage()
    {
        $service = new ImageOptimizationService();
        $html = '<img src="hero.jpg"><img src="second.jpg"><img src="third.jpg">';
        $result = $service->applyLazyLoading($html);
        $this->assertSame(2, substr_count($result, 'loading="lazy"'));
    }

    public function testApplyLazyLoadingPreservesExistingLoading()
    {
        $service = new ImageOptimizationService();
        $html = '<img src="a.jpg" loading="eager"><img src="b.jpg">';
        $result = $service->applyLazyLoading($html);
        $this->assertStringContainsString('loading="eager"', $result);
        $this->assertStringContainsString('loading="lazy"', $result);
    }

    public function testOptimizeReturnsOriginalOnFailure()
    {
        $service = new ImageOptimizationService();
        $result = $service->optimize('https://invalid-domain-12345.com/image.jpg');
        $this->assertSame('https://invalid-domain-12345.com/image.jpg', $result);
    }

    public function testRewriteImageSrcIgnoresDataUri()
    {
        $service = new ImageOptimizationService();
        $html = '<img src="data:image/png;base64,abc">';
        $result = $service->rewriteImageSrc($html, 'https://example.com');
        $this->assertSame($html, $result);
    }
}

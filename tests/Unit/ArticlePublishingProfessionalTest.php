<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/Publishing/ArticleSiteGuard.php';
require_once __DIR__ . '/../../app/Services/Publishing/ContentFormatter.php';

final class ArticlePublishingProfessionalTest extends TestCase
{
    public function testArticleForAnotherSiteIsBlocked(): void
    {
        $this->assertTrue(ArticleSiteGuard::isMismatch(46, 45));
        $this->assertTrue(ArticleSiteGuard::isMismatch('46', 45));
    }

    public function testSameSiteOrUnassignedArticleIsAllowed(): void
    {
        $this->assertFalse(ArticleSiteGuard::isMismatch(45, 45));
        $this->assertFalse(ArticleSiteGuard::isMismatch(null, 45));
        $this->assertFalse(ArticleSiteGuard::isMismatch(0, 45));
    }

    public function testTopLevelSectionsBecomeH2WhenGeneratorUsesDoubleHash(): void
    {
        $html = ContentFormatter::markdownToHtml("## Intro\nSome **text**.\n\n## Costs\n\n### Detail\n\nMore");
        $this->assertStringContainsString('<h2>Intro</h2>', $html);
        $this->assertStringContainsString('<h2>Costs</h2>', $html);
        $this->assertStringContainsString('<h3>Detail</h3>', $html);
        $this->assertStringNotContainsString('<h4>', $html);
        $this->assertStringContainsString('<p>Some <strong>text</strong>.</p>', $html);
    }

    public function testSingleHashStillMapsToH2AndNeverSkipsLevels(): void
    {
        $html = ContentFormatter::markdownToHtml("# Title\n\nText\n\n## Sub");
        $this->assertStringContainsString('<h2>Title</h2>', $html);
        $this->assertStringContainsString('<h3>Sub</h3>', $html);
        $this->assertStringNotContainsString('<h1>', $html);
    }
}

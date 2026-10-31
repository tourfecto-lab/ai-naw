<?php

use PHPUnit\Framework\TestCase;

/** robots.txt parsing for the "open to AI crawlers" check of the free site check. */
final class FreeCheckAiCrawlersTest extends TestCase
{
    public function testOpenSiteBlocksNothing(): void
    {
        $this->assertSame([], FreeSiteCheckService::blockedAiBots("User-agent: *\nDisallow: /admin\nSitemap: https://x.test/sitemap.xml"));
        $this->assertSame([], FreeSiteCheckService::blockedAiBots(''));
    }

    public function testBlockingEverythingBlocksAllAiBots(): void
    {
        $this->assertSame(['gptbot', 'oai-searchbot', 'claudebot', 'perplexitybot', 'google-extended'], FreeSiteCheckService::blockedAiBots("User-agent: *\nDisallow: /"));
    }

    public function testSpecificBotGroupOverridesTheWildcard(): void
    {
        $robots = "User-agent: GPTBot\nUser-agent: ClaudeBot\nDisallow: /\n\nUser-agent: *\nDisallow: /private\n";
        $this->assertSame(['gptbot', 'claudebot'], FreeSiteCheckService::blockedAiBots($robots));
        $open = "User-agent: *\nDisallow: /\n\nUser-agent: PerplexityBot\nAllow: /\n";
        $this->assertNotContains('perplexitybot', FreeSiteCheckService::blockedAiBots($open));
        $this->assertContains('gptbot', FreeSiteCheckService::blockedAiBots($open));
    }

    public function testCommentsCaseAndWindowsLineEndingsAreHandled(): void
    {
        $robots = "# no AI\r\nUSER-AGENT: gptbot # block\r\nDISALLOW: /\r\n";
        $this->assertSame(['gptbot'], FreeSiteCheckService::blockedAiBots($robots));
    }
}

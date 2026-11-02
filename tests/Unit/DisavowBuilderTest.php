<?php

use PHPUnit\Framework\TestCase;

/** Disavow suggestions are explained, never automatic, and the exported file only contains what the owner selected. */
final class DisavowBuilderTest extends TestCase
{
    public function testParsingAcceptsDomainsUrlsAndSearchConsoleCsvRows(): void
    {
        $text = "Site,Linked pages\nexample-blog.com,12\n\"https://www.Travel-Tips.org/page?x=1\",3\ndomain:spam-casino-site.xyz\n# comment\nnot a domain\nmy-own-site.com,9\nsub.my-own-site.com\nexample-blog.com";
        $r = DisavowBuilder::parse($text, 'https://www.my-own-site.com/');
        $this->assertSame(['example-blog.com', 'travel-tips.org', 'spam-casino-site.xyz'], $r['domains'], 'own domain and its subdomains are dropped, duplicates merged, header ignored');
        $this->assertSame(1, $r['skipped'], 'only the real garbage line is counted');
        $this->assertFalse($r['truncated']);
    }

    public function testScoringExplainsEverySuggestionAndLeavesNormalSitesAlone(): void
    {
        $rows = DisavowBuilder::analyze(['lonelyplanet.com', 'best-casino-slots-online.xyz', 'cheap-backlinks-seo-service.top', 'travel-blog-egypt.com', '123456789012.click']);
        $by = array_column($rows, null, 'domain');
        $this->assertArrayNotHasKey('lonelyplanet.com', $by);
        $this->assertArrayNotHasKey('travel-blog-egypt.com', $by);
        $this->assertSame('high', $by['best-casino-slots-online.xyz']['level']);
        $this->assertNotEmpty($by['best-casino-slots-online.xyz']['reasons']);
        $this->assertGreaterThanOrEqual(60, $by['cheap-backlinks-seo-service.top']['score']);
        $this->assertSame('best-casino-slots-online.xyz', $rows[0]['domain'] === 'best-casino-slots-online.xyz' ? $rows[0]['domain'] : $rows[1]['domain']);
        foreach ($rows as $r) {
            $this->assertContains($r['level'], ['high', 'medium', 'low']);
            $this->assertSame(count($r['reasons']) > 0, true);
        }
    }

    public function testFileContainsOnlySelectedValidDomainsInGoogleFormat(): void
    {
        $file = DisavowBuilder::file(['Spam-Site.xyz', 'https://www.bad-links.top/page', 'spam-site.xyz', 'not valid', ''], 'my-own-site.com', '2026-11-02');
        $lines = explode("\n", trim($file));
        $this->assertStringStartsWith('# Disavow file prepared by Tourfecto for my-own-site.com on 2026-11-02', $lines[0]);
        $this->assertSame(['domain:spam-site.xyz', 'domain:bad-links.top'], array_values(array_filter($lines, static fn ($l) => str_starts_with($l, 'domain:'))));
        $this->assertStringEndsWith("\n", $file);
        $this->assertStringNotContainsString('not valid', $file);
    }
}

<?php

use PHPUnit\Framework\TestCase;

final class FreeSocialCheckServiceTest extends TestCase
{
    private const GOOD = '<html><head><meta property="og:title" content="Luxor Tours"><meta property="og:description" content="Day trips"><meta property="og:image" content="https://x.com/i.jpg"><meta name="twitter:card" content="summary">
        <script>fbq("init","1");</script></head><body>
        <a href="https://www.facebook.com/luxortours">fb</a><a href="https://facebook.com/sharer/sharer.php?u=1">share</a>
        <a href="https://www.instagram.com/luxortours/?hl=en">ig</a><a href="https://wa.me/20100">wa</a>
        <a href="https://www.tiktok.com/@luxortours">tt</a><a href="https://www.tripadvisor.com/Attraction_Review-g1.html">ta</a></body></html>';

    public function testGoodSiteScoresHighAndFindsProfiles(): void
    {
        $r = (new FreeSocialCheckService('en'))->analyze(self::GOOD, 'https://luxor.example/');
        $this->assertTrue($r['ok']);
        $this->assertGreaterThanOrEqual(90, $r['score']);
        $this->assertSame('luxortours', $r['profiles']['facebook']['handle']);
        $this->assertSame('luxortours', $r['profiles']['instagram']['handle']);
        $this->assertSame('https://www.instagram.com/luxortours/', $r['profiles']['instagram']['url']);
        $this->assertSame([], $r['top']);
    }

    public function testEmptySiteScoresLowAndListsTopProblems(): void
    {
        $r = (new FreeSocialCheckService('ar'))->analyze('<html><head><title>x</title></head><body>hi</body></html>', 'https://empty.example/');
        $this->assertLessThan(25, $r['score']);
        $this->assertCount(3, $r['top']);
        $this->assertContains('facebook', $r['top']);
        $this->assertSame([], $r['profiles']);
    }

    public function testShareAndGenericLinksAreNotTreatedAsProfiles(): void
    {
        $html = '<a href="https://www.facebook.com/sharer/sharer.php?u=x">s</a><a href="https://facebook.com/">home</a><a href="https://www.instagram.com/p/ABC/">post</a><a href="https://www.instagram.com/explore/">e</a>';
        $this->assertSame([], FreeSocialCheckService::profiles($html));
    }

    public function testMismatchedHandlesWarn(): void
    {
        $html = '<a href="https://facebook.com/luxortours">f</a><a href="https://instagram.com/luxor_trips">i</a>';
        $r = (new FreeSocialCheckService('en'))->analyze($html, 'https://a.example/');
        $byKey = array_column($r['checks'], 'status', 'key');
        $this->assertSame('warn', $byKey['consistent']);
        $this->assertSame('pass', $byKey['facebook']);
    }

    public function testMetaTagsParsingIsOrderIndependent(): void
    {
        $m = FreeSocialCheckService::metaTags('<meta content="Hello" property="og:title"><meta name="twitter:card" content="summary_large_image">');
        $this->assertSame('Hello', $m['og:title']);
        $this->assertSame('summary_large_image', $m['twitter:card']);
    }
}

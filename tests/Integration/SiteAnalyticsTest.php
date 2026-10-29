<?php

use PHPUnit\Framework\TestCase;

/** Site analytics: counts humans only, stores aggregates (no IP / user agent), and reports per site. */
final class SiteAnalyticsTest extends TestCase
{
    private const SITE = 999970;
    private const OTHER = 999971;
    private const OWNER = 999970;

    private PDO $pdo;
    private string $day;

    protected function setUp(): void
    {
        $this->pdo = Database::getInstance()->getConnection();
        $this->day = gmdate('Y-m-d');
        $this->cleanup();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_HOST'] = 'tourfecto.test';
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        foreach (['site_daily_stats', 'site_daily_pages', 'site_daily_sources', 'site_visitor_seen'] as $t) {
            $this->pdo->exec("DELETE FROM $t WHERE website_id IN (" . self::SITE . ',' . self::OTHER . ')');
        }
    }

    private function hit(int $site = self::SITE, string $page = 'home', string $ip = '198.51.100.1', string $ua = 'Mozilla/5.0 (iPhone) Safari', string $ref = '', array $q = []): bool
    {
        return SiteAnalytics::track($site, $page, false, $ua, $ref, $ip, $q, $this->day);
    }

    public function testSourceClassification(): void
    {
        $this->assertSame('direct', SiteAnalytics::classifySource(''));
        $this->assertSame('search', SiteAnalytics::classifySource('https://www.google.com/search?q=x'));
        $this->assertSame('search', SiteAnalytics::classifySource('https://duckduckgo.com/'));
        $this->assertSame('social', SiteAnalytics::classifySource('https://l.facebook.com/l.php'));
        $this->assertSame('social', SiteAnalytics::classifySource('https://t.co/abc'));
        $this->assertSame('ads', SiteAnalytics::classifySource('', '', ['utm_medium' => 'cpc']));
        $this->assertSame('ads', SiteAnalytics::classifySource('https://google.com', '', ['gclid' => 'x']));
        $this->assertSame('social', SiteAnalytics::classifySource('', '', ['utm_source' => 'instagram']));
        $this->assertSame('direct', SiteAnalytics::classifySource('https://tourfecto.test/sites/a', 'tourfecto.test'));
        $this->assertSame('other', SiteAnalytics::classifySource('https://some-blog.example/post'));
    }

    public function testBotsAndEmptyAgentsAreNotCounted(): void
    {
        $this->assertFalse($this->hit(self::SITE, 'home', '1.1.1.1', 'Googlebot/2.1'));
        $this->assertFalse($this->hit(self::SITE, 'home', '1.1.1.1', ''));
        $this->assertFalse($this->hit(self::SITE, 'home', '1.1.1.1', 'Lighthouse'));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM site_daily_stats WHERE website_id = ' . self::SITE)->fetchColumn());
    }

    public function testViewsVisitorsPagesAndSourcesAggregate(): void
    {
        $this->assertTrue($this->hit(self::SITE, 'home', '203.0.113.1', 'Mozilla/5.0 A', 'https://www.google.com/'));
        $this->assertTrue($this->hit(self::SITE, 'tour:felucca', '203.0.113.1', 'Mozilla/5.0 A', ''));
        $this->assertTrue($this->hit(self::SITE, 'home', '203.0.113.2', 'Mozilla/5.0 B', 'https://facebook.com/'));
        $r = SiteAnalytics::report(self::SITE, self::OWNER, 7);
        $this->assertSame(3, $r['views']);
        $this->assertSame(2, $r['visitors'], 'the same person on two pages is one visitor that day');
        $pages = array_column($r['pages'], 'views', 'page');
        $this->assertSame(2, $pages['home']);
        $this->assertSame(1, $pages['tour:felucca']);
        $src = array_column($r['sources'], 'views', 'source');
        $this->assertEquals(['search' => 1, 'direct' => 1, 'social' => 1], $src);
        $this->assertCount(7, $r['daily']);
    }

    public function testSitesAreCountedSeparately(): void
    {
        $this->hit(self::SITE);
        $this->hit(self::OTHER);
        $this->hit(self::OTHER, 'home', '9.9.9.9');
        $this->assertSame(1, SiteAnalytics::report(self::SITE, self::OWNER, 7)['views']);
        $this->assertSame(2, SiteAnalytics::report(self::OTHER, self::OWNER, 7)['views']);
    }

    public function testNothingIdentifyingIsStored(): void
    {
        $this->hit(self::SITE, 'home', '203.0.113.77', 'Mozilla/5.0 UniqueAgentString');
        foreach (['site_daily_stats', 'site_daily_pages', 'site_daily_sources', 'site_visitor_seen'] as $t) {
            $dump = json_encode($this->pdo->query("SELECT * FROM $t WHERE website_id = " . self::SITE)->fetchAll(PDO::FETCH_ASSOC));
            $this->assertStringNotContainsString('203.0.113.77', $dump, $t);
            $this->assertStringNotContainsString('UniqueAgentString', $dump, $t);
        }
    }

    public function testOwnerViewsAndPrefetchAreIgnoredAndBadPageKeysAreCleaned(): void
    {
        $this->assertFalse(SiteAnalytics::track(self::SITE, 'home', true, 'Mozilla/5.0', '', '1.1.1.1', [], $this->day));
        $this->assertSame('tour:felucca-1', SiteAnalytics::pageKey('tour', 'felucca-1'));
        $this->assertSame('script', SiteAnalytics::pageKey('<script>', ''), 'markup characters are stripped');
        $this->assertSame('home', SiteAnalytics::pageKey('///', ''));
        $this->assertLessThanOrEqual(100, strlen(SiteAnalytics::pageKey('tour', str_repeat('a', 300))));
    }

    public function testConversionRateUsesLeadsAndBookingsOverVisitors(): void
    {
        $this->hit(self::SITE, 'home', '5.5.5.1');
        $this->hit(self::SITE, 'home', '5.5.5.2');
        $r = SiteAnalytics::report(self::SITE, self::OWNER, 7);
        $this->assertSame(0.0, (float) $r['conversion']);
        $this->assertSame(0, $r['leads'] + $r['bookings']);
    }
}

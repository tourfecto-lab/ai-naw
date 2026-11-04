<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/Publishing/WordPressConnector.php';
require_once __DIR__ . '/../../app/Services/SeoExpert/SeoDiscoveryService.php';
require_once __DIR__ . '/../../app/Services/SeoExpert/SeoExpertService.php';

/** tour.php?id=12 and tour.php?id=19 are two different pages; filters, calendars and tracking never create pages. */
final class SeoPageIdentityTest extends TestCase
{
    private const HOME = 'https://shop.example.test/';

    public function testIdentityParameterIsKeptAndTrackingIsDropped(): void
    {
        $this->assertSame('https://shop.example.test/tour.php?id=12', SeoDiscoveryService::pageUrl('https://shop.example.test/tour.php?id=12', self::HOME));
        $this->assertSame('https://shop.example.test/tour.php?id=19', SeoDiscoveryService::pageUrl('https://shop.example.test/tour.php?utm_source=fb&id=19&fbclid=abc', self::HOME));
    }

    public function testTwoToursAreDifferentPages(): void
    {
        $a = SeoExpertService::path('https://shop.example.test/tour.php?id=12');
        $b = SeoExpertService::path('https://shop.example.test/tour.php?id=19');
        $this->assertSame('/tour.php?id=12', $a);
        $this->assertSame('/tour.php?id=19', $b);
        $this->assertNotSame($a, $b);
    }

    public function testFiltersAndCalendarsAreNotCrawled(): void
    {
        foreach (['tours.php?category=day&sort=price', 'tour.php?id=12&date=2026-12-01', 'tours.php?page=3', 'search.php?q=nile'] as $u) {
            $this->assertNull(SeoDiscoveryService::pageUrl('https://shop.example.test/' . $u, self::HOME), $u);
        }
    }

    public function testTrackingOnlyUrlCollapsesToThePlainPage(): void
    {
        $this->assertSame('https://shop.example.test/blog/nile', SeoDiscoveryService::pageUrl('https://shop.example.test/blog/nile?utm_campaign=x', self::HOME));
    }

    public function testPhpTourPageIsClassifiedAsAServicePage(): void
    {
        $this->assertSame('service', SeoPagePolicy::route('https://shop.example.test/tour.php?id=12')['kind']);
    }

    public function testCanonicalPointingToAnotherTourIsReviewedNotTreatedAsSelf(): void
    {
        $page = ['url' => 'https://shop.example.test/tour.php?id=12', 'canonical' => 'https://shop.example.test/tour.php?id=19', 'robots' => ''];
        $this->assertSame('canonical_review', SeoPagePolicy::classify($page)['kind']);
        $page['canonical'] = 'https://shop.example.test/tour.php?id=12';
        $this->assertTrue(SeoPagePolicy::classify($page)['eligible']);
    }
}

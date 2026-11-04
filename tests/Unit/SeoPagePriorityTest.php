<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/SeoExpert/SeoPagePriority.php';

/** The plan listed 40 identical "Add breadcrumb" tasks in crawl order; bookable pages must come first and utility pages not at all. */
final class SeoPagePriorityTest extends TestCase
{
    public function testTripsAndServicePagesOutrankInformationPages(): void
    {
        $trip = SeoPagePriority::score(['page_path' => '/st_tour/two-hour-walking-tour'], true);
        $activity = SeoPagePriority::score(['page_path' => '/st_activity/horseback-tour']);
        $destination = SeoPagePriority::score(['page_path' => '/st_location/united-states']);
        $article = SeoPagePriority::score(['page_path' => '/blog/the-best-secrets']);
        $legal = SeoPagePriority::score(['page_path' => '/privacy-policy']);
        $this->assertGreaterThan($activity['score'], $trip['score']);
        $this->assertGreaterThan($destination['score'], $activity['score']);
        $this->assertGreaterThan($article['score'], $destination['score']);
        $this->assertGreaterThan($legal['score'], $article['score']);
        $this->assertSame('utility', $legal['tier']);
        $this->assertNotSame('', $trip['reason']);
    }

    public function testOrderIsStableForEqualScoresAndPutsBookablePagesFirst(): void
    {
        $pages = [
            ['page_path' => '/privacy-policy'],
            ['page_path' => '/some-page-a'],
            ['page_path' => '/some-page-b'],
            ['page_path' => '/st_tour/x'],
            ['page_path' => '/'],
        ];
        $ordered = array_column(SeoPagePriority::order($pages, static fn ($p) => $p['page_path'] === '/st_tour/x'), 'page_path');
        $this->assertSame(['/st_tour/x', '/', '/some-page-a', '/some-page-b', '/privacy-policy'], $ordered);
    }
}

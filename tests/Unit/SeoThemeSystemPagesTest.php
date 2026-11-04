<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/Publishing/WordPressConnector.php';
require_once __DIR__ . '/../../app/Services/SeoExpert/SeoPagePolicy.php';
require_once __DIR__ . '/../../app/Services/SeoExpert/SeoSchemaBuilder.php';

/** Live plan on a Traveler site proposed schema for /footer, checkout and search pages, and TouristTrip for a hotel. */
final class SeoThemeSystemPagesTest extends TestCase
{
    /** @dataProvider systemPaths */
    public function testThemeAndOperationalPagesAreOutOfMarketingScope(string $path): void
    {
        $this->assertFalse(SeoPagePolicy::route('https://x.test' . $path)['eligible'], $path);
    }

    public static function systemPaths(): array
    {
        return array_map(static fn ($p) => [$p], ['/footer', '/header', '/function-memberpackage-checkout-page', '/memberpackage', '/hotel-search-halfmap', '/hotel-search-popup-map']);
    }

    /** @dataProvider marketingPaths */
    public function testRealContentPagesStayInScope(string $path): void
    {
        $this->assertTrue(SeoPagePolicy::route('https://x.test' . $path)['eligible'], $path);
    }

    public static function marketingPaths(): array
    {
        return array_map(static fn ($p) => [$p], ['/', '/st_tour/two-hour-walking-tour-of-manhattan', '/st_activity/paramount-ranch-guided-horseback-tour-2', '/tours/luxor', '/blog/nile-guide']);
    }

    public function testAHotelOrCarIsNeverATripPage(): void
    {
        foreach (['/st_hotel/grand-desert-resort', '/hotel_room/deluxe-room', '/st_cars/bmw-x5', '/st_rental/villa-1'] as $path) {
            $this->assertFalse(SeoSchemaBuilder::isTourPage(['page_path' => $path, 'h1' => 'Grand Desert Resort', 'title' => 'Grand Desert Resort', 'card_prices' => []]), $path);
        }
    }
}

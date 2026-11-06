<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/SeoExpert/SeoSchemaBuilder.php';

/** A hotel/room/rental page must never receive TouristTrip schema, at planning time or when an old plan is applied. */
final class SeoTripSchemaCompatibilityTest extends TestCase
{
    private const TRIP_SCHEMA = '{"@context":"https://schema.org","@graph":[{"@type":"TouristTrip","name":"X"}]}';

    public function testHotelPageIsNotATourPage(): void
    {
        foreach (['/st_hotel/grand-desert-resort/', '/hotel_room/room-sheraton-hotel/', '/st_rental/luxury-studio/', '/st_cars/jeep/'] as $path) {
            $this->assertFalse(SeoSchemaBuilder::isTourPage(['page_path' => $path, 'h1' => 'Grand Desert Resort Day Trip', 'title' => 'x']), $path);
        }
    }

    public function testOldPlanWithTripSchemaIsRejectedForHotelPage(): void
    {
        $hotel = ['page_path' => '/st_hotel/grand-desert-resort/', 'h1' => 'Grand Desert Resort'];
        $this->assertFalse(SeoSchemaBuilder::taskCompatible($hotel, 'jsonld', self::TRIP_SCHEMA));
    }

    public function testNonSchemaFieldsAreNotAffected(): void
    {
        $hotel = ['page_path' => '/st_hotel/grand-desert-resort/', 'h1' => 'Grand Desert Resort'];
        $this->assertTrue(SeoSchemaBuilder::taskCompatible($hotel, 'title', 'Grand Desert Resort – Test Company'));
    }
}

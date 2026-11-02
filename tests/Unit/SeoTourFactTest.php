<?php

use PHPUnit\Framework\TestCase;

/** Per-tour facts are read only when the tour page states them (live page: Nubian Village Trip, tourfecto.com). */
final class SeoTourFactTest extends TestCase
{
    private function ctx(string $text, ?float $price = 60.0): array
    {
        return [
            'schema' => [
                ['@type' => 'TouristTrip', 'name' => 'Nubian Village Trip by Boat to Sohail Island', 'offers' => $price === null ? [] : ['@type' => 'Offer', 'price' => number_format($price, 2, '.', ''), 'priceCurrency' => 'USD']],
                ['@type' => 'Product', 'name' => 'Nubian Village Trip by Boat to Sohail Island'],
            ],
            'text' => $text,
        ];
    }

    public function testLivePageFactsAreExtractedExactlyAsStated(): void
    {
        $text = 'Home Aswan Nubian Village Trip ⏱ Duration 4 hours 📍 Location Aswan 🚐 Pick-up From your hotel 🎓 Guide Licensed Egyptologist ↺ Cancellation Free up to 24h Overview Quick answer: Nubian Village Trip by Boat to Sohail Island lasts 4 hours in Aswan, from $60.00 per person, with a licensed guide and hotel pick-up.';
        $this->assertSame(
            'Nubian Village Trip by Boat to Sohail Island — 4 hours; from USD 60 per person; free cancellation up to 24 hours',
            SeoBusinessBriefBuilder::tourFact($this->ctx($text))
        );
    }

    public function testNothingIsInferredWhenThePageDoesNotSayIt(): void
    {
        $line = SeoBusinessBriefBuilder::tourFact($this->ctx('A lovely trip with tea and a boat.', 60.0));
        $this->assertSame('Nubian Village Trip by Boat to Sohail Island — from USD 60', $line, 'price from schema only, no "per person", no duration, no cancellation');
        $this->assertNull(SeoBusinessBriefBuilder::tourFact($this->ctx('A lovely trip with tea and a boat.', null)), 'no verifiable fact means no line');
    }

    public function testPagesWithoutATripNameGiveNothing(): void
    {
        $this->assertNull(SeoBusinessBriefBuilder::tourFact(['schema' => [['@type' => 'WebSite', 'name' => 'Tourfecto']], 'text' => 'Duration 4 hours']));
        $this->assertNull(SeoBusinessBriefBuilder::tourFact([]));
    }

    public function testRangeDurationsAndPartialCancellationWordingAreHandled(): void
    {
        $line = SeoBusinessBriefBuilder::tourFact($this->ctx('Duration 6-7 hours. Cancellation: Free up to 48 hours before.', 120.5));
        $this->assertSame('Nubian Village Trip by Boat to Sohail Island — 6-7 hours; from USD 120.5; free cancellation up to 48 hours', $line);
        $notFree = SeoBusinessBriefBuilder::tourFact($this->ctx('Duration 3 days. Cancellation 50% fee after booking.', 300.0));
        $this->assertStringNotContainsString('free cancellation', (string) $notFree);
    }
}

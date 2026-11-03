<?php

use PHPUnit\Framework\TestCase;

/** Find Place returns the closest hit even for another business: its rating must never be told to the company. */
final class OutreachGoogleMatchTest extends TestCase
{
    public function testSameNameMatches(): void
    {
        $this->assertTrue(OutreachAudit::placeNameMatches('Vibrant Egypt', 'Vibrant Egypt Tours'));
        $this->assertTrue(OutreachAudit::placeNameMatches('Nile Tours', "\u{202A}Nile Tours\u{202C}"));
    }

    public function testDifferentBusinessDoesNotMatch(): void
    {
        $this->assertFalse(OutreachAudit::placeNameMatches('Vibrant Egypt', 'Vibrant Cairo Apartments Rental Group'));
        $this->assertFalse(OutreachAudit::placeNameMatches('Nile Tours', 'Sunset Travel'));
        $this->assertFalse(OutreachAudit::placeNameMatches('Nile Tours', ''));
    }

    public function testMismatchedCandidateIsTreatedAsNotFound(): void
    {
        $resp = ['candidates' => [['name' => 'Sunset Travel', 'rating' => 3.1, 'user_ratings_total' => 7, 'place_id' => 'x']]];
        $g = OutreachAudit::googleFacts($resp, 'Vibrant Egypt');
        $this->assertTrue($g['checked']);
        $this->assertFalse($g['found']);
    }

    public function testRetailPlaceIsRejectedEvenWithSimilarName(): void
    {
        $resp = ['candidates' => [['name' => 'Vibrant', 'types' => ['clothing_store', 'store'], 'rating' => 3.1, 'user_ratings_total' => 7]]];
        $this->assertFalse(OutreachAudit::googleFacts($resp, 'Vibrant Egypt')['found']);
    }

    public function testMatchingTravelAgencyIsKept(): void
    {
        $resp = ['candidates' => [['name' => 'Vibrant Egypt', 'types' => ['travel_agency'], 'rating' => 4.8, 'user_ratings_total' => 40, 'place_id' => 'p']]];
        $g = OutreachAudit::googleFacts($resp, 'Vibrant Egypt');
        $this->assertTrue($g['found']);
        $this->assertSame(40, $g['reviews']);
    }
}

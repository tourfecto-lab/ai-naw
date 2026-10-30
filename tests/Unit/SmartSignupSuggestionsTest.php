<?php

use PHPUnit\Framework\TestCase;

/** The smart signup must suggest real tour-company competitors and booking keywords, not magazines and trivia searches. */
final class SmartSignupSuggestionsTest extends TestCase
{
    public function testMagazinesEncyclopediasAndMarketplacesAreNotCompetitors(): void
    {
        foreach (['nationalgeographic.com', 'www.history.com', 'worldhistory.org', 'en.wikipedia.org', 'viator.com', 'booking.com', 'x.com'] as $host) {
            $this->assertTrue(SmartOnboardingService::notACompetitor($host), $host);
        }
    }

    public function testTourCompaniesWhoseNameContainsABlockedDomainStayCompetitors(): void
    {
        foreach (['egyptsphinx.com', 'egypthistory.com', 'cairohotels.com', 'nilebooking.com', 'egypttoursportal.com'] as $host) {
            $this->assertFalse(SmartOnboardingService::notACompetitor($host), $host);
        }
    }

    public function testGiantPublishersAreDroppedButBiggerTourOperatorsStay(): void
    {
        $this->assertTrue(SmartOnboardingService::isPublisher(1200000, 18400));
        $this->assertTrue(SmartOnboardingService::isPublisher(421100, 18400));
        $this->assertFalse(SmartOnboardingService::isPublisher(23600, 18400));
        $this->assertFalse(SmartOnboardingService::isPublisher(160000, 20000));
    }

    public function testBookingKeywordsComeBeforeBiggerInformationalOnes(): void
    {
        $ranked = SmartOnboardingService::bookingKeywordsFirst([
            ['keyword' => 'egypt', 'position' => 17, 'volume' => 550000, 'intent' => 'navigational'],
            ['keyword' => 'cleopatra queen', 'position' => 16, 'volume' => 301000, 'intent' => 'informational'],
            ['keyword' => 'petra location', 'position' => 8, 'volume' => 135000, 'intent' => ''],
            ['keyword' => 'egypt tours', 'position' => 12, 'volume' => 40500, 'intent' => 'commercial'],
            ['keyword' => 'nile cruise luxor to aswan', 'position' => 6, 'volume' => 9900, 'intent' => ''],
        ], 4);
        $this->assertSame(['egypt tours', 'nile cruise luxor to aswan'], array_column(array_slice($ranked, 0, 2), 'keyword'));
        $this->assertCount(4, $ranked);
        $this->assertArrayNotHasKey('intent', $ranked[0]);
    }
}

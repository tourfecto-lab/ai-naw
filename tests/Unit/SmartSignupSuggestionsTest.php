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

    public function testBookingKeywordsAreAskedForFirstAndTopUpOnlyWhenTooFew(): void
    {
        $kw = static fn (string $k, int $pos, int $vol) => ['keyword_data' => ['keyword' => $k, 'keyword_info' => ['search_volume' => $vol]], 'ranked_serp_element' => ['serp_item' => ['rank_group' => $pos]]];
        $popular = [$kw('egypt', 17, 550000), $kw('mumbai', 13, 135000), $kw('petra', 22, 135000)];

        $few = new FakeRankedKeywordsClient([$kw('egypt tours', 12, 40500), $kw('nile cruise luxor to aswan', 6, 9900)], $popular);
        $result = $this->service($few)->competitors('https://memphistours.com', [], [], 'en');
        $this->assertSame(['egypt tours', 'nile cruise luxor to aswan'], array_column(array_slice($result['keywords'], 0, 2), 'keyword'));
        $this->assertCount(5, $result['keywords'], 'too few booking keywords: topped up with the most searched ones');
        $this->assertSame([true, false], $few->rankedCalls, 'booking filter first, unfiltered only as a top-up');
        $this->assertSame(['keyword_data.keyword', 'like', '%tour%'], $few->firstFilter[0]);

        $many = new FakeRankedKeywordsClient(array_map(fn ($i) => $kw('egypt tour ' . $i, 8, 1000 * $i), range(1, 6)), $popular);
        $result = $this->service($many)->competitors('https://memphistours.com', [], [], 'en');
        $this->assertSame([true], $many->rankedCalls);
        $this->assertNotContains('egypt', array_column($result['keywords'], 'keyword'));
    }

    public function testBookingFilterStaysWithinTheEightConditionLimit(): void
    {
        foreach (['en', 'de', 'fr', 'es', 'it', 'ar', 'pl'] as $lang) {
            $conditions = array_filter(SmartOnboardingService::bookingKeywordFilter($lang), 'is_array');
            $this->assertLessThanOrEqual(8, count($conditions), $lang);
            $this->assertNotEmpty($conditions, $lang);
        }
    }

    private function service(DataForSeoClient $client): SmartOnboardingService
    {
        return new class ($client) extends SmartOnboardingService {
            protected function verify(array $domains, int $timeout): array
            {
                return [];
            }
        };
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

/** Google data stand-in: the filtered ranked-keywords call gets the booking keywords, the plain one the most searched. */
final class FakeRankedKeywordsClient extends DataForSeoClient
{
    /** @var bool[] one entry per ranked-keywords call: was the booking filter sent */
    public array $rankedCalls = [];
    public array $firstFilter = [];

    public function __construct(private array $booking, private array $popular)
    {
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function callPlatform(string $endpoint, array $tasks, float $ceiling): array
    {
        $items = [];
        if (str_contains($endpoint, 'domain_rank_overview')) {
            $items = [['metrics' => ['organic' => ['count' => 18400, 'etv' => 22000, 'pos_1' => 100]]]];
        } elseif (str_contains($endpoint, 'ranked_keywords')) {
            $filtered = isset($tasks[0]['filters']);
            $this->rankedCalls[] = $filtered;
            if ($filtered && !$this->firstFilter) {
                $this->firstFilter = $tasks[0]['filters'];
            }
            $items = $filtered ? $this->booking : $this->popular;
        }
        return ['available' => true, 'response' => ['tasks' => [['result' => [['items' => $items]]]]]];
    }
}

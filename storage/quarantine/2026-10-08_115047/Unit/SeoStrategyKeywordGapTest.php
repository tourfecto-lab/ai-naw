<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/SeoStrategy/SeoStrategyService.php';

class SeoStrategyKeywordGapTest extends TestCase
{
    public function testFetchKeywordGapsReturnsEmptyWhenNoCompetitors()
    {
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturn([]);

        $service = new SeoStrategyService();
        $ref = new ReflectionMethod($service, 'fetchKeywordGaps');
        $ref->setAccessible(true);

        $result = $ref->invoke($service, $db, 1, 1);
        $this->assertSame([], $result);
    }

    public function testFetchKeywordGapsFiltersClientKeywords()
    {
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            [['id' => 1, 'competitor_domain' => 'competitor.com']],
            [
                ['keyword' => 'travel egypt', 'competitor_id' => 1, 'search_volume' => 1000, 'difficulty' => 45],
                ['keyword' => 'cairo tours', 'competitor_id' => 1, 'search_volume' => 500, 'difficulty' => 30],
            ],
            [['keyword' => 'travel egypt']]
        );

        $service = new SeoStrategyService();
        $ref = new ReflectionMethod($service, 'fetchKeywordGaps');
        $ref->setAccessible(true);

        $result = $ref->invoke($service, $db, 1, 1);
        $this->assertCount(1, $result);
        $this->assertSame('cairo tours', $result[0]['keyword']);
    }
}

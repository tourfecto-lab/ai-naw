<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/SeoExpert/SeoPlanQualityGate.php';

/** Live plan (site 45): two different pages got the identical "unique" title, which recreates the duplicate it was meant to fix. */
final class SeoDuplicateProposalsTest extends TestCase
{
    private function current(): callable
    {
        return static fn (array $p, string $field): string => (string) ($p['values'][$field][0] ?? ($p[$field] ?? ''));
    }

    public function testTheSameProposedValueForTwoPagesIsDroppedForBoth(): void
    {
        $pages = [['title' => 'Horseback Tour'], ['title' => 'Horseback Tour']];
        $kept = [
            ['field' => 'title', 'page_index' => 0, 'value' => 'Paramount Ranch Guided Horseback Tour – Test Company'],
            ['field' => 'title', 'page_index' => 1, 'value' => 'paramount ranch guided horseback tour – test company '],
            ['field' => 'description', 'page_index' => 0, 'value' => 'A distinct description for page one.'],
        ];
        $out = SeoPlanQualityGate::dropDuplicateProposals($kept, $pages, $this->current());
        $this->assertCount(1, $out);
        $this->assertSame('description', $out[0]['field']);
    }

    public function testAProposalEqualToAnotherPagesExistingValueIsDropped(): void
    {
        $pages = [['title' => 'Old A'], ['title' => 'Luxor Day Tours | Nile Journeys']];
        $kept = [['field' => 'title', 'page_index' => 0, 'value' => 'Luxor Day Tours | Nile Journeys']];
        $this->assertSame([], SeoPlanQualityGate::dropDuplicateProposals($kept, $pages, $this->current()));
    }

    public function testDistinctProposalsAreKept(): void
    {
        $pages = [['title' => 'A'], ['title' => 'B']];
        $kept = [
            ['field' => 'title', 'page_index' => 0, 'value' => 'Cairo Pyramids Day Tour | Nile Journeys'],
            ['field' => 'title', 'page_index' => 1, 'value' => 'Luxor Temples Day Tour | Nile Journeys'],
        ];
        $this->assertCount(2, SeoPlanQualityGate::dropDuplicateProposals($kept, $pages, $this->current()));
    }
}

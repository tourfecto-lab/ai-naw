<?php

use PHPUnit\Framework\TestCase;

/** A title proposal must not get the brand appended twice (live case: "... | Tourfecto | Tourfecto Travel", 69 characters). */
final class SeoTitleBrandTest extends TestCase
{
    private function run1(string $now, string $proposal, string $brand): array
    {
        $pages = [['title' => $now]];
        $changes = [['page_index' => 0, 'field' => 'title', 'value' => $proposal, 'reason' => 'x']];
        return SeoPlanQualityGate::filterChanges($changes, $pages, $brand, []);
    }

    public function testShortBrandAlreadyInTheProposalIsNotDuplicatedWithTheFullBrand(): void
    {
        $kept = $this->run1('Day Tours Tours in Egypt | Tourfecto Travel', 'Day Tours in Egypt: Private Excursions | Tourfecto', 'Tourfecto Travel');
        $this->assertCount(1, $kept);
        $this->assertSame('Day Tours in Egypt: Private Excursions | Tourfecto', $kept[0]['value']);
    }

    public function testBrandIsStillAddedWhenTheProposalDropsIt(): void
    {
        $kept = $this->run1('Day Tours Tours in Egypt | Tourfecto Travel', 'Day Tours in Egypt: Private Excursions', 'Tourfecto Travel');
        $this->assertCount(1, $kept);
        $this->assertSame('Day Tours in Egypt: Private Excursions | Tourfecto Travel', $kept[0]['value']);
    }

    public function testMentionsBrandRules(): void
    {
        $this->assertTrue(SeoPlanQualityGate::mentionsBrand('Luxor Tours | Tourfecto', 'Tourfecto Travel'));
        $this->assertTrue(SeoPlanQualityGate::mentionsBrand('Luxor Tours | Tourfecto Travel', 'Tourfecto Travel'));
        $this->assertFalse(SeoPlanQualityGate::mentionsBrand('Luxor Tours and Temples', 'Tourfecto Travel'));
        $this->assertFalse(SeoPlanQualityGate::mentionsBrand('Luxor Tours in Egypt', 'AB Travel'), 'a first word under 4 letters is not enough evidence');
    }
}

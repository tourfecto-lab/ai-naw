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

    public function testExistingDuplicatedBrandTitleIsFlaggedSoThePlanRepairsIt(): void
    {
        $this->assertSame('repeated_brand', SeoPlanQualityGate::issue('title', 'Day Tours in Egypt: Private Excursions | Tourfecto | Tourfecto Travel'));
        $this->assertNull(SeoPlanQualityGate::issue('title', 'Hurghada Tours and Excursions | Tourfecto Travel'));
        $this->assertNull(SeoPlanQualityGate::issue('title', 'Luxor Tours | Cairo Tours | Egypt Travel Guide'), 'only words repeated after the first part count');
    }

    public function testDuplicatedBrandIsCollapsedDeterministically(): void
    {
        $this->assertSame('Day Tours in Egypt: Private Excursions | Tourfecto Travel', SeoPlanQualityGate::collapseRepeatedBrand('Day Tours in Egypt: Private Excursions | Tourfecto | Tourfecto Travel'));
        $this->assertSame('Cairo Shore Excursion from Sokhna Port | Tourfecto Travel', SeoPlanQualityGate::collapseRepeatedBrand('Cairo Shore Excursion from Sokhna Port | Tourfecto | Tourfecto Travel'));
        $this->assertNull(SeoPlanQualityGate::collapseRepeatedBrand('Hurghada Tours and Excursions | Tourfecto Travel'));
        $this->assertNull(SeoPlanQualityGate::collapseRepeatedBrand('Luxor Tours | Cairo Tours | Egypt Travel Guide'));
        $this->assertNull(SeoPlanQualityGate::collapseRepeatedBrand('A | Tourfecto | Tourfecto Travel'), 'a result shorter than 25 characters is not a valid title');
    }

    public function testProposalThatRepairsTheDuplicateIsKeptAndOneThatRepeatsItIsDropped(): void
    {
        $now = 'Day Tours in Egypt: Private Excursions | Tourfecto | Tourfecto Travel';
        $kept = $this->run1($now, 'Private Day Tours in Egypt from Cairo and Luxor | Tourfecto Travel', 'Tourfecto Travel');
        $this->assertCount(1, $kept);
        $this->assertSame([], $this->run1($now, 'Egypt Day Tours | Tourfecto | Tourfecto Travel', 'Tourfecto Travel'));
    }
}

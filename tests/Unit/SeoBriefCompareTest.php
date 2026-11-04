<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/SeoExpert/SeoBriefCompare.php';

/** Production incident (cycle SEO-18): identical company facts compared as "changed" because MySQL JSON reorders keys. */
final class SeoBriefCompareTest extends TestCase
{
    public function testSameContentInADifferentKeyOrderIsEqual(): void
    {
        $text = json_decode('{"business_name":"A","goal":"g","shared_profile":{"version":1,"facts":{"cta":"c","goal":"g"}},"language":"en"}', true);
        $mysqlJson = json_decode('{"goal":"g","language":"en","business_name":"A","shared_profile":{"facts":{"goal":"g","cta":"c"},"version":1}}', true);
        $this->assertNotSame($text, $mysqlJson, 'the strict comparison used before fails on this input');
        $this->assertTrue(SeoBriefCompare::same($text, $mysqlJson));
    }

    public function testRealChangesStillDiffer(): void
    {
        $a = ['business_name' => 'A', 'shared_profile' => ['version' => 1]];
        $this->assertFalse(SeoBriefCompare::same($a, ['business_name' => 'A', 'shared_profile' => ['version' => 2]]));
        $this->assertFalse(SeoBriefCompare::same($a, ['business_name' => 'B', 'shared_profile' => ['version' => 1]]));
    }

    public function testListOrderStillMatters(): void
    {
        $this->assertFalse(SeoBriefCompare::same(['x' => [1, 2]], ['x' => [2, 1]]));
    }
}

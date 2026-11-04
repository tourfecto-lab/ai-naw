<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AI/AiSampleChecks.php';

final class AiSampleChecksTest extends TestCase
{
    public function testInventedNumbersAreCaught(): void
    {
        $this->assertTrue(AiSampleChecks::numbersSubset('A 2 hours walk from EUR 160 per person', ['2', '160']));
        $this->assertFalse(AiSampleChecks::numbersSubset('Rated 4.9 by 1200 guests', ['2', '160']));
    }

    public function testArabicIndicDigitsCountAsTheSameNumbers(): void
    {
        $this->assertTrue(AiSampleChecks::numbersSubset('رحلة ساعتان من ٤٥ دولارًا', ['2', '45']));
        $this->assertFalse(AiSampleChecks::numbersSubset('رحلة من ٩٩ دولارًا', ['45']));
    }

    public function testLanguageAndLength(): void
    {
        $this->assertGreaterThan(0.9, AiSampleChecks::arabicRatio('استمتع برحلة نيلية في القاهرة'));
        $this->assertLessThan(0.05, AiSampleChecks::arabicRatio('Enjoy a Nile cruise in Cairo'));
        $this->assertTrue(AiSampleChecks::lengthBetween(str_repeat('a', 100), 90, 155));
        $this->assertFalse(AiSampleChecks::lengthBetween(str_repeat('a', 200), 90, 155));
    }

    public function testPromisesAndJson(): void
    {
        $this->assertFalse(AiSampleChecks::containsNone('Free cancellation and best price guaranteed', ['free cancellation']));
        $this->assertTrue(AiSampleChecks::containsNone('Walk Manhattan with a local guide', ['free cancellation']));
        $this->assertTrue(AiSampleChecks::validJson("```json\n{\"price\":\"USD 60\"}\n```"));
        $this->assertFalse(AiSampleChecks::validJson('price: 60'));
    }

    public function testSummary(): void
    {
        $s = AiSampleChecks::summarize(['a' => true, 'b' => false, 'c' => true, 'd' => true]);
        $this->assertSame([3, 4, 0.75, ['b']], [$s['passed'], $s['total'], $s['score'], $s['failed']]);
    }
}

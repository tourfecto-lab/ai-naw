<?php

/**
 * Gemini price table (verified 2026-10-31): real cost per call, date switch on 2027-01-01, never understates unknown models.
 */

use PHPUnit\Framework\TestCase;

final class GeminiPricingTest extends TestCase
{
    private const BEFORE = 1798675200; // 2026-12-31 00:00 UTC
    private const AFTER = 1798761600;  // 2027-01-01 00:00 UTC

    public function testFlashIsPricedByInputAndOutputSeparately(): void
    {
        // 1,000 input + 600 output tokens on Gemini 3.8 Flash at 0.75 / 3.75 per 1M
        $this->assertEquals(0.00075 + 0.00225, GeminiPricing::cost('gemini-3.8-flash', 1000, 600, self::BEFORE));
    }

    public function testPricesDoubleOnTheChangeDateWithoutAnyEdit(): void
    {
        $before = GeminiPricing::cost('gemini-3.8-flash', 1000000, 1000000, self::BEFORE);
        $after = GeminiPricing::cost('gemini-3.8-flash', 1000000, 1000000, self::AFTER);
        $this->assertEquals(4.5, $before);
        $this->assertEquals(9.0, $after);
        $this->assertEquals(2.8, GeminiPricing::cost('gemini-3.5-flash-lite', 1000000, 1000000, self::AFTER), 'lite prices do not change');
    }

    public function testLongestPrefixWinsSoLiteIsNotPricedAsFlash(): void
    {
        $this->assertEquals([0.10, 0.40, true], GeminiPricing::rates('gemini-2.5-flash-lite-preview', self::BEFORE));
        $this->assertEquals([0.30, 2.50, true], GeminiPricing::rates('gemini-2.5-flash', self::BEFORE));
    }

    public function testUnknownModelIsPricedAtTheHighestKnownRateAndFlagged(): void
    {
        [$in, $out, $known] = GeminiPricing::rates('gemini-9-future', self::BEFORE);
        $this->assertFalse($known);
        $this->assertEquals(0.75, $in);
        $this->assertEquals(3.75, $out);
        $this->assertEquals(0.0, GeminiPricing::cost('gemini-3.8-flash', 0, 0, self::BEFORE));
        $this->assertEquals(0.0, GeminiPricing::cost('gemini-3.8-flash', -5, -5, self::BEFORE), 'negative tokens never reduce a cost');
    }
}

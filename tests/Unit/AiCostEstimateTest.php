<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AI/AiCostEstimate.php';

final class AiCostEstimateTest extends TestCase
{
    public function testKnownModelIsPricedFromTokens(): void
    {
        $this->assertEqualsWithDelta(0.0003, AiCostEstimate::usd('gpt-4o-mini', 1000, 1000, 0) * 2, 0.0000001, '0.15 USD per 1M input tokens');
        $this->assertEqualsWithDelta(0.00075, AiCostEstimate::usd('gemini-flash-latest', 1000, 0, 300), 0.0000001, '300 output tokens at 2.50 per 1M');
    }

    public function testUnknownModelOrNoTokensIsNotEstimatedNeverZero(): void
    {
        $this->assertNull(AiCostEstimate::usd('some-new-model', 5000));
        $this->assertNull(AiCostEstimate::usd('gpt-4o-mini', 0));
    }

    public function testBlendedEstimateWhenOnlyTheTotalIsKnown(): void
    {
        $blend = AiCostEstimate::usd('gpt-4o-mini', 10000);
        $this->assertGreaterThan(0.0, $blend);
        $this->assertLessThan(AiCostEstimate::usd('gpt-4o', 10000), $blend);
    }
}

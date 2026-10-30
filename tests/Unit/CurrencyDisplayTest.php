<?php

use PHPUnit\Framework\TestCase;

/** The dollar is the charged currency: plan cards must show the exact price, other currencies may round. */
final class CurrencyDisplayTest extends TestCase
{
    public function testDollarPricesAreShownExactly(): void
    {
        $this->assertSame('$199', CurrencyService::format(199, 'USD'));
        $this->assertSame('$449', CurrencyService::format(449, 'USD'));
        $this->assertSame(549.0, CurrencyService::fromUsd(549, 'USD'));
    }

    public function testLocalCurrenciesStillRoundToANicePrice(): void
    {
        $egp = CurrencyService::fromUsd(199, 'EGP');
        $this->assertSame(0.0, fmod($egp, 5.0));
    }
}

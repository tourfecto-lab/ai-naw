<?php

use PHPUnit\Framework\TestCase;

/** Meta rejected a 50 EGP/day ad set (its floor was 53.75 EGP), so the dialog and the server must ask for more. */
final class AdBudgetMinimumTest extends TestCase
{
    public function testMinimumDailyClearsMetasObservedEgpFloor(): void
    {
        $this->assertGreaterThan(53.75, AdBudgetCurrency::minimumDaily('EGP'));
        $this->assertSame(1.5, AdBudgetCurrency::minimumDaily('USD'));
    }
}

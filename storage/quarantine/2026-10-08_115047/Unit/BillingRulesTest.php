<?php

/**
 * Tourfecto - Billing Rules Unit Test
 * اختبار للقواعد المالية النقية في BillingRules (مرجع القواعد الموحّد)
 * باستخدام PHPUnit TestCase - بيعمل offline تمامًا (من غير قاعدة بيانات)
 * لأنه كلاس pure منطق بحت.
 *
 * التشغيل: phpunit --configuration tests/phpunit.xml --filter BillingRulesTest
 * @version 1.0.0
 * @date 2026-08-17
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/Subscription/BillingRules.php';

final class BillingRulesTest extends TestCase
{
    /**
     * @dataProvider planChangeChargeProvider
     */
    public function testPlanChangeCharge(float $oldPrice, float $newPrice, float $expected): void
    {
        $this->assertSame($expected, BillingRules::planChangeCharge($oldPrice, $newPrice));
    }

    public static function planChangeChargeProvider(): array
    {
        return [
            'upgrade positive diff'        => [10.0, 25.0, 15.0],
            'downgrade negative diff'      => [25.0, 10.0, -15.0],
            'same plan zero diff'          => [20.0, 20.0, 0.0],
            'rounding to 2 decimals'       => [9.99, 20.005, 10.02],
            'zero old price (new sub)'     => [0.0, 49.0, 49.0],
        ];
    }

    public function testIsUpgradeDetectsPositiveCharge(): void
    {
        $this->assertTrue(BillingRules::isUpgrade(15.0));
        $this->assertFalse(BillingRules::isUpgrade(-15.0));
        $this->assertFalse(BillingRules::isUpgrade(0.0));
    }

    public function testIsDowngradeDetectsNegativeCharge(): void
    {
        $this->assertTrue(BillingRules::isDowngrade(-15.0));
        $this->assertFalse(BillingRules::isDowngrade(15.0));
        $this->assertFalse(BillingRules::isDowngrade(0.0));
    }

    /**
     * ALLOW_PRORATED_DOWNGRADE_CREDIT مقفول افتراضيًا (قرار مالي
     * بياخده مالك المنصة) - لازم downgradeCredit يرجع 0 دايمًا
     * حاليًا مهما كان الفرق، عشان الحماية من التفعيل الضمني.
     */
    public function testDowngradeCreditLockedByDefault(): void
    {
        $this->assertSame(0.0, BillingRules::downgradeCredit(25.0, 10.0));
        $this->assertSame(0.0, BillingRules::downgradeCredit(10.0, 25.0));
        $this->assertSame(0.0, BillingRules::downgradeCredit(0.0, 0.0));
        // لو الاتنين نفس السعر - مش تخفيض
        $this->assertSame(0.0, BillingRules::downgradeCredit(20.0, 20.0));
    }

    /**
     * @dataProvider proratedCreditProvider
     */
    public function testProratedCredit(float $oldPrice, float $newPrice, int $remaining, int $period, float $expected): void
    {
        $this->assertSame($expected, BillingRules::proratedCredit($oldPrice, $newPrice, $remaining, $period));
    }

    public static function proratedCreditProvider(): array
    {
        return [
            'full period left -> full diff'    => [30.0, 20.0, 30, 30, 10.0],
            'half period left -> half diff'    => [30.0, 20.0, 15, 30, 5.0],
            'one day left -> small credit'     => [30.0, 20.0, 1, 30, 0.33],
            'upgrade -> no credit (0)'         => [20.0, 30.0, 30, 30, 0.0],
            'same price -> no credit (0)'      => [25.0, 25.0, 30, 30, 0.0],
            'zero period -> 0 (guard)'         => [30.0, 20.0, 10, 0, 0.0],
            'zero remaining -> 0 (guard)'      => [30.0, 20.0, 0, 30, 0.0],
            'rounding to 2 decimals'           => [100.0, 55.0, 1, 365, 0.12],
        ];
    }

    /**
     * @dataProvider dunningWindowProvider
     */
    public function testIsInDunningWindow(int $elapsedDays, bool $expected): void
    {
        $this->assertSame($expected, BillingRules::isInDunningWindow($elapsedDays));
    }

    public static function dunningWindowProvider(): array
    {
        return [
            'start of grace'   => [0, false],
            'before window'    => [2, false],
            'window start'     => [5, true],
            'mid window'       => [6, true],
            'at grace boundary' => [7, false],
            'past grace'       => [10, false],
        ];
    }

    /**
     * @dataProvider graceProvider
     */
    public function testGracePeriodExpired(int $elapsedDays, bool $expected): void
    {
        $this->assertSame($expected, BillingRules::gracePeriodExpired($elapsedDays));
    }

    public static function graceProvider(): array
    {
        return [
            'not yet expired'  => [6, false],
            'exactly at 7'     => [7, true],
            'past grace'       => [14, true],
            'start'            => [0, false],
        ];
    }

    /**
     * @dataProvider reminderWindowProvider
     */
    public function testIsInReminderWindow(int $daysUntilEnd, int $reminderDays, bool $expected): void
    {
        $this->assertSame($expected, BillingRules::isInReminderWindow($daysUntilEnd, $reminderDays));
    }

    public static function reminderWindowProvider(): array
    {
        return [
            'due today'            => [0, 3, true],
            'inside 3-day window'  => [3, 3, true],
            'one day after'        => [1, 3, true],
            'outside 3-day window' => [4, 3, false],
            'inside 7-day window'  => [7, 7, true],
            'exactly at 7'         => [7, 3, false],
            'negative (expired)'   => [-1, 3, false],
        ];
    }
}

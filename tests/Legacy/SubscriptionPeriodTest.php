<?php

/**
 * Tourfecto - Subscription Period Unit Test
 * @version 1.0.0
 * @date 2026-08-15
 *
 * اختبار offline بالكامل - SubscriptionPeriod منطق بحت (تواريخ ومفاتيح)،
 * مفيش أي اتصال بقاعدة بيانات، فممكن تشغيله مباشرة:
 *   php tests/Unit/SubscriptionPeriodTest.php
 */
require_once dirname(__DIR__, 2) . '/app/Services/Subscription/SubscriptionPeriod.php';

class SubscriptionPeriodTest
{
    private $passed = 0;
    private $failed = 0;

    public function runAll(): void
    {
        echo "\nSubscriptionPeriod Tests\n========================\n\n";

        $this->testMonthlyExtension();
        $this->testYearlyExtension();
        $this->testYearlyExtendsFromGivenDateNotNow();
        $this->testDefaultsToMonthlyForUnknownType();
        $this->testInvalidDateFallsBackToNow();
        $this->testIdempotencyKeyFormat();
        $this->testIdempotencyKeyUniquePerPeriod();
        $this->testIdempotencyKeyStableForSameInput();

        $this->printSummary();
    }

    private function testMonthlyExtension(): void
    {
        $this->startTest('Monthly: +1 month from given date');
        $result = SubscriptionPeriod::nextPeriodEnd('2026-01-15 10:00:00', 'monthly');
        $result === '2026-02-15 10:00:00'
            ? $this->pass("monthly: {$result}")
            : $this->fail("expected 2026-02-15 10:00:00, got {$result}");
    }

    private function testYearlyExtension(): void
    {
        $this->startTest('Yearly: +1 year from given date');
        $result = SubscriptionPeriod::nextPeriodEnd('2026-08-15 08:30:00', 'yearly');
        $result === '2027-08-15 08:30:00'
            ? $this->pass("yearly: {$result}")
            : $this->fail("expected 2027-08-15 08:30:00, got {$result}");
    }

    private function testYearlyExtendsFromGivenDateNotNow(): void
    {
        $this->startTest('Extension anchored on input date, not now()');
        // لو اتاخد بالغلط من now()، تاريخ ماضي هيدّي نتيجة مختلفة.
        $past = SubscriptionPeriod::nextPeriodEnd('2025-01-01 00:00:00', 'yearly');
        $past === '2026-01-01 00:00:00'
            ? $this->pass("anchored on input: {$past}")
            : $this->fail("expected 2026-01-01 00:00:00, got {$past}");
    }

    private function testDefaultsToMonthlyForUnknownType(): void
    {
        $this->startTest('Unknown plan type defaults to monthly (conservative)');
        $result = SubscriptionPeriod::nextPeriodEnd('2026-06-01 00:00:00', 'weekly');
        $result === '2026-07-01 00:00:00'
            ? $this->pass("unknown type -> monthly: {$result}")
            : $this->fail("expected 2026-07-01 00:00:00, got {$result}");
    }

    private function testInvalidDateFallsBackToNow(): void
    {
        $this->startTest('Invalid date falls back to now (no exception)');
        $result = SubscriptionPeriod::nextPeriodEnd('not-a-date', 'monthly');
        // لازم تكون تاريخ مستقبلي بحوالي شهر من الآن، ومش أي تاريخ غلط
        $ts = strtotime($result);
        ($ts !== false && $ts > time())
            ? $this->pass("graceful fallback: {$result}")
            : $this->fail("expected a valid future date, got {$result}");
    }

    private function testIdempotencyKeyFormat(): void
    {
        $this->startTest('Idempotency key format: renewal_{id}_{stamp}');
        $key = SubscriptionPeriod::renewalIdempotencyKey(42, '2026-08-15 00:00:00');
        (strpos($key, 'renewal_42_') === 0)
            ? $this->pass("format ok: {$key}")
            : $this->fail("unexpected format: {$key}");
    }

    private function testIdempotencyKeyUniquePerPeriod(): void
    {
        $this->startTest('Different period end -> different key');
        $k1 = SubscriptionPeriod::renewalIdempotencyKey(42, '2026-08-15 00:00:00');
        $k2 = SubscriptionPeriod::renewalIdempotencyKey(42, '2026-09-15 00:00:00');
        $k1 !== $k2
            ? $this->pass("distinct per period")
            : $this->fail("keys collided: {$k1}");
    }

    private function testIdempotencyKeyStableForSameInput(): void
    {
        $this->startTest('Same input -> same key (deterministic)');
        $a = SubscriptionPeriod::renewalIdempotencyKey(7, '2026-08-15 00:00:00');
        $b = SubscriptionPeriod::renewalIdempotencyKey(7, '2026-08-15 00:00:00');
        $a === $b
            ? $this->pass("stable")
            : $this->fail("not stable: {$a} vs {$b}");
    }

    private function startTest(string $name): void
    {
        echo "  - {$name} ... ";
    }

    private function pass(string $detail): void
    {
        $this->passed++;
        echo "PASS ({$detail})\n";
    }

    private function fail(string $detail): void
    {
        $this->failed++;
        echo "FAIL ({$detail})\n";
    }

    private function printSummary(): void
    {
        echo "\n========================\n";
        echo "Passed: {$this->passed}  Failed: {$this->failed}\n";
        echo ($this->failed === 0) ? "ALL TESTS PASSED\n" : "SOME TESTS FAILED\n";
        echo "========================\n\n";
        exit($this->failed === 0 ? 0 : 1);
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    $test = new SubscriptionPeriodTest();
    $test->runAll();
}

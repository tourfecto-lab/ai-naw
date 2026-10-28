<?php

/**
 * Tourfecto - Competitor Intelligence: Offline Test Runner
 * @version 1.0.0
 *
 * Harness صغير مشترك لتشغيل اختبارات الـ unit البسيطة بدون أي اعتماديات:
 * بيسجل النتائج ويطبع ملخص في الآخر. الاختبارات بتستدعي نفس طريقة
 * الاستخدام المتعارف عليها في الموديول (startTest/pass/fail).
 *
 * مثال:
 *   php tests/Unit/CompetitorIntelligence/CompetitorDomainTest.php
 */
abstract class CiOfflineTestCase
{
    protected $passed = 0;
    protected $failed = 0;
    protected $currentTest = '';

    abstract public function runAll(): void;

    protected function startTest(string $name): void
    {
        $this->currentTest = $name;
        echo "\n  TEST: {$name}\n";
    }

    protected function pass(string $message): void
    {
        echo "    OK: {$message}\n";
        $this->passed++;
    }

    protected function fail(string $message): void
    {
        echo "    FAIL: {$message}\n";
        $this->failed++;
    }

    protected function assertTrue(bool $condition, string $message): void
    {
        $condition ? $this->pass($message) : $this->fail($message);
    }

    protected function assertFalse(bool $condition, string $message): void
    {
        $condition ? $this->fail($message) : $this->pass($message);
    }

    protected function assertNull($value, string $message): void
    {
        $value === null
            ? $this->pass($message)
            : $this->fail("{$message} - expected null, got " . var_export($value, true));
    }

    protected function assertSame($expected, $actual, string $message): void
    {
        $expected === $actual
            ? $this->pass("{$message} (got " . var_export($actual, true) . ')')
            : $this->fail("{$message} - expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }

    public function printSummary(): void
    {
        $total = $this->passed + $this->failed;
        $pct = $total > 0 ? round(($this->passed / $total) * 100, 2) : 0;
        echo "\n" . str_repeat('=', 50) . "\n";
        echo "Summary: passed={$this->passed} failed={$this->failed} total={$total} success_rate={$pct}%\n";
        echo str_repeat('=', 50) . "\n\n";
    }
}

<?php

/**
 * Tourfecto - Competitor Intelligence: PriceExtractor Test
 * @version 1.5.1
 *
 * اختبار offline كامل لاستخراج الأسعار المهيكلة (ميزة "تاريخ الأسعار"
 * المميزة لمنصات التسعير مثل Prisync) - منطق نقي بدون أي اتصال.
 *
 * تشغيل:
 *   php tests/Unit/CompetitorIntelligence/PriceExtractorTest.php
 */
require_once __DIR__ . '/CiOfflineTestCase.php';
require_once dirname(__DIR__, 3) . '/app/Services/CompetitorIntelligence/PriceExtractor.php';

class PriceExtractorTest extends CiOfflineTestCase
{
    public function runAll(): void
    {
        echo "\nPriceExtractor Tests\n====================\n";

        $this->testDollarPrefix();
        $this->testCodePrefix();
        $this->testCodeSuffix();
        $this->testSymbols();
        $this->testArabicWords();
        $this->testArabicIndicDigits();
        $this->testNoCurrencyRejected();
        $this->testParseAmount();
        $this->testInvalidAmounts();

        $this->printSummary();
    }

    private function testDollarPrefix(): void
    {
        $this->startTest('$ prefix');
        $this->assertSame(['amount' => 1299.0, 'currency' => 'USD'], PriceExtractor::extract('Package costs $1,299.00 /month'), 'thousands comma + cents');
        $this->assertSame(['amount' => 49.0, 'currency' => 'USD'], PriceExtractor::extract('Just $49'), 'plain dollar');
    }

    private function testCodePrefix(): void
    {
        $this->startTest('currency code before amount');
        $this->assertSame(['amount' => 150.0, 'currency' => 'AED'], PriceExtractor::extract('AED 150 per night'), 'AED prefix');
        $this->assertSame(['amount' => 89.99, 'currency' => 'USD'], PriceExtractor::extract('USD 89.99 monthly'), 'USD prefix');
    }

    private function testCodeSuffix(): void
    {
        $this->startTest('currency code after amount');
        $this->assertSame(['amount' => 4900.0, 'currency' => 'EGP'], PriceExtractor::extract('4,900 EGP per month'), 'EGP suffix');
        $this->assertSame(['amount' => 12.5, 'currency' => 'SAR'], PriceExtractor::extract('starts at 12.5 SAR'), 'SAR suffix decimal');
    }

    private function testSymbols(): void
    {
        $this->startTest('currency symbols');
        $this->assertSame(['amount' => 25.0, 'currency' => 'EUR'], PriceExtractor::extract('€25'), 'euro');
        $this->assertSame(['amount' => 12.0, 'currency' => 'GBP'], PriceExtractor::extract('only £12 /m'), 'pound');
        $this->assertSame(['amount' => 999.0, 'currency' => 'INR'], PriceExtractor::extract('₹999 only'), 'rupee');
    }

    private function testArabicWords(): void
    {
        $this->startTest('Arabic currency words');
        $this->assertSame(['amount' => 150.0, 'currency' => 'SAR'], PriceExtractor::extract('تبدأ الأسعار من 150 ريال'), 'ريال');
        $this->assertSame(['amount' => 3500.0, 'currency' => 'EGP'], PriceExtractor::extract('3,500 جنيه للشهر'), 'جنيه');
        $this->assertSame(['amount' => 120.0, 'currency' => 'AED'], PriceExtractor::extract('120 درهم'), 'درهم');
    }

    private function testArabicIndicDigits(): void
    {
        $this->startTest('Arabic-Indic digits normalized');
        $this->assertSame(['amount' => 1234.0, 'currency' => 'EGP'], PriceExtractor::extract('١٢٣٤ جنيه'), 'Arabic-Indic ١٢٣٤');
        $this->assertSame(['amount' => 500.0, 'currency' => 'SAR'], PriceExtractor::extract('۵۰۰ ریال'), 'Extended Arabic-Indic');
    }

    private function testNoCurrencyRejected(): void
    {
        $this->startTest('no clear currency indicator -> null');
        $this->assertSame(null, PriceExtractor::extract('no pricing info here'), 'plain text');
        $this->assertSame(null, PriceExtractor::extract('Version 1.2 is available'), 'version number');
        $this->assertSame(null, PriceExtractor::extract('Call us at 1000'), 'bare number');
        $this->assertSame(null, PriceExtractor::extract(''), 'empty string');
        $this->assertSame(null, PriceExtractor::extract('50% off everything'), 'percent only');
    }

    private function testParseAmount(): void
    {
        $this->startTest('parseAmount separators');
        $this->assertSame(1299.0, PriceExtractor::parseAmount('1,299.00'), 'comma thousands');
        $this->assertSame(1299.0, PriceExtractor::parseAmount('1.299,00'), 'european decimal comma');
        $this->assertSame(49.99, PriceExtractor::parseAmount('49,99'), 'decimal comma');
        $this->assertSame(5000.0, PriceExtractor::parseAmount('5,000'), 'thousands only');
        $this->assertSame(49.99, PriceExtractor::parseAmount('49.99'), 'decimal dot');
        $this->assertSame(1000000.0, PriceExtractor::parseAmount('1,000,000'), 'millions');
        $this->assertSame(1234.0, PriceExtractor::parseAmount('١٢٣٤'), 'arabic digits');
    }

    private function testInvalidAmounts(): void
    {
        $this->startTest('parseAmount rejects invalid');
        $this->assertSame(null, PriceExtractor::parseAmount(''), 'empty');
        $this->assertSame(null, PriceExtractor::parseAmount('abc'), 'letters');
        $this->assertSame(null, PriceExtractor::parseAmount('-5'), 'negative');
        $this->assertSame(null, PriceExtractor::parseAmount('1,2,3'), 'malformed commas');
        $this->assertSame(null, PriceExtractor::parseAmount('$99'), 'symbol in amount');
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    (new PriceExtractorTest())->runAll();
}

<?php

/**
 * Tourfecto - Competitor Intelligence: CompetitorDomain Test
 * @version 1.0.0
 *
 * اختبار offline للمنطق الصافي في CompetitorDomain (تطبيع + host + أمان).
 * الفحص الأمني بيتطلب DNS حقيقي، فنفس قاعدة SsrfGuardTest: لو الشبكة
 * مش متاحة أو الدومين مش بيفضى، النتيجة بتعتبر مقبولة طالما المنطق
 * نفسه سليم (مش private_or_unresolvable_host بشكل خاطئ).
 *
 * تشغيل:
 *   php tests/Unit/CompetitorIntelligence/CompetitorDomainTest.php
 */
require_once __DIR__ . '/CiOfflineTestCase.php';
require_once dirname(__DIR__, 3) . '/app/Services/CompetitorIntelligence/SsrfGuard.php';
require_once dirname(__DIR__, 3) . '/app/Services/CompetitorIntelligence/CompetitorDomain.php';

class CompetitorDomainTest extends CiOfflineTestCase
{
    public function runAll(): void
    {
        echo "\nCompetitorDomain Tests\n=====================\n";

        $this->testNormalize();
        $this->testHost();
        $this->testNormalizeSafe();

        $this->printSummary();
    }

    private function testNormalize(): void
    {
        $this->startTest('normalize() adds https:// when scheme missing');
        $this->assertSame('https://example.com', CompetitorDomain::normalize('example.com'), 'bare domain');
        $this->assertSame('https://example.com/pricing', CompetitorDomain::normalize('example.com/pricing'), 'bare domain with path');
        $this->assertSame('https://example.com', CompetitorDomain::normalize('  example.com  '), 'trims whitespace');
        $this->assertSame('http://example.com', CompetitorDomain::normalize('http://example.com'), 'keeps existing http scheme');
        // حالة الأحرف مختلفة: الفحص نفسه case-insensitive، فالـ scheme بيتعرف
        // وبيتبقى زي ما هو (https:// و HTTPS:// الاتنين مقبولين وبيمروا للفحص الأمني)
        $upper = CompetitorDomain::normalize('HTTPS://example.com');
        $this->assertTrue(strtolower($upper) === 'https://example.com', 'https scheme recognized regardless of case');
        $this->assertSame('', CompetitorDomain::normalize('   '), 'empty input returns empty string');
        $this->assertSame('', CompetitorDomain::normalize(null), 'null returns empty string');
    }

    private function testHost(): void
    {
        $this->startTest('host() extracts lowercase host');
        $this->assertSame('example.com', CompetitorDomain::host('https://example.com/pricing'), 'full url');
        $this->assertSame('example.com', CompetitorDomain::host('EXAMPLE.com'), 'bare domain lowercased');
        $this->assertSame('example.com', CompetitorDomain::host('https://Example.com:443/x'), 'port and path stripped');
        $this->assertSame(null, CompetitorDomain::host(''), 'empty input returns null');
    }

    private function testNormalizeSafe(): void
    {
        $this->startTest('normalizeSafe() validates SSRF safety');

        // unsafe: loopback / private ranges -> null
        $this->assertSame(null, CompetitorDomain::normalizeSafe('http://127.0.0.1/'), 'loopback rejected');
        $this->assertSame(null, CompetitorDomain::normalizeSafe('http://192.168.1.1/'), 'private range rejected');
        $this->assertSame(null, CompetitorDomain::normalizeSafe('http://169.254.169.254/latest/meta-data/'), 'metadata endpoint rejected');
        $this->assertSame(null, CompetitorDomain::normalizeSafe('file:///etc/passwd'), 'non-http scheme rejected');
        $this->assertSame(null, CompetitorDomain::normalizeSafe(''), 'empty input rejected');

        // public domain: normalized or unresolvable (both acceptable offline)
        $result = CompetitorDomain::normalizeSafe('example.com');
        $ok = $result === 'https://example.com' || $result === null;
        $this->assertTrue($ok, 'example.com -> normalized https url or null when unresolvable (safe logic, offline-tolerant)');
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    (new CompetitorDomainTest())->runAll();
}

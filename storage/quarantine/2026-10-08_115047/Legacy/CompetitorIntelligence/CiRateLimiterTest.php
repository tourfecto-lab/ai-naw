<?php

/**
 * Tourfecto - Competitor Intelligence: CiRateLimiter Test
 * @version 1.0.0
 *
 * اختبار offline للمنطق الصافي في CiRateLimiter (windowStart - حساب بداية
 * نافذة fixed-window). مش بنلمس الـ DB هنا، بس بنتأكد إن حساب النوافذ
 * سليم، لأن أي خطأ فيه = فتح/قفل خاطئ في الحماية.
 *
 * تشغيل:
 *   php tests/Unit/CompetitorIntelligence/CiRateLimiterTest.php
 */
require_once __DIR__ . '/CiOfflineTestCase.php';
require_once dirname(__DIR__, 3) . '/app/Services/CompetitorIntelligence/CiRateLimiter.php';

class CiRateLimiterTest extends CiOfflineTestCase
{
    public function runAll(): void
    {
        echo "\nCiRateLimiter Tests\n===================\n";

        $this->testWindowStart();
        $this->testWindowStartEdgeCases();

        $this->printSummary();
    }

    private function testWindowStart(): void
    {
        $this->startTest('windowStart() aligns to window boundaries');

        // تانية 100 ثانية ونافذة 60 -> بداية النافذة الحالية 60
        $this->assertSame(60, CiRateLimiter::windowStart(100, 60), 't=100 / window=60');

        // بالظبط عند حد النافذة -> النافذة الجديدة (كل القسمة صحيح)
        $this->assertSame(120, CiRateLimiter::windowStart(120, 60), 't=120 / window=60 (boundary)');

        // نافذة 3600 (ساعة) - تانية 5400 -> 3600
        $this->assertSame(3600, CiRateLimiter::windowStart(5400, 3600), 't=5400 / window=3600');

        // نافذة 86400 (يوم) - بداية اليوم الأول
        $this->assertSame(0, CiRateLimiter::windowStart(1, 86400), 't=1 / window=86400');

        // منتصف اليوم التاني - تانية 90000 => 86400
        $this->assertSame(86400, CiRateLimiter::windowStart(90000, 86400), 't=90000 / window=86400');
    }

    private function testWindowStartEdgeCases(): void
    {
        $this->startTest('windowStart() edge cases');

        // نافذة صغيرة جدًا - كل ثانية نافذة لوحدها
        $this->assertSame(7, CiRateLimiter::windowStart(7, 1), 'window=1 returns the second itself');

        // نافذة موجبة أصلًا (نافذة 5 دقايق = 300 ثانية)
        $this->assertSame(300, CiRateLimiter::windowStart(599, 300), 't=599 / window=300 -> 300');

        // القيمة صفر/سالبة لا تنهار (fallback لنفس الثانية)
        $this->assertSame(42, CiRateLimiter::windowStart(42, 0), 'window=0 falls back to now');
        $this->assertSame(42, CiRateLimiter::windowStart(42, -5), 'negative window falls back to now');
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    (new CiRateLimiterTest())->runAll();
}

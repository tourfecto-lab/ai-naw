<?php

/**
 * Tourfecto - Competitor Intelligence: SitemapMonitor Careers Test
 * @version 1.5.1
 *
 * اختبار offline للـ heuristic الخاص بإشارة التوظيف (Job Postings) -
 * منصة Crayon/Kompyte بتعتبره مصدر استخبارات استراتيجي. الطريقة
 * isCareerUrl عامة وثابتة (لا تحتاج قاعدة بيانات) فلين يمكن اختبارها
 * مباشرة.
 *
 * تشغيل:
 *   php tests/Unit/CompetitorIntelligence/SitemapMonitorTest.php
 */
require_once __DIR__ . '/CiOfflineTestCase.php';
require_once dirname(__DIR__, 3) . '/app/Services/CompetitorIntelligence/SitemapMonitor.php';

class SitemapMonitorTest extends CiOfflineTestCase
{
    public function runAll(): void
    {
        echo "\nSitemapMonitor Careers Tests\n============================\n";

        $this->testCareerUrls();
        $this->testNonCareerUrls();

        $this->printSummary();
    }

    private function testCareerUrls(): void
    {
        $this->startTest('careers/jobs/join/hiring/vacancies detected');

        $this->assertTrue(SitemapMonitor::isCareerUrl('https://example.com/careers'), '/careers');
        $this->assertTrue(SitemapMonitor::isCareerUrl('https://example.com/careers/sales'), '/careers/sales');
        $this->assertTrue(SitemapMonitor::isCareerUrl('https://example.com/jobs'), '/jobs');
        $this->assertTrue(SitemapMonitor::isCareerUrl('https://example.com/join-us'), '/join-us');
        $this->assertTrue(SitemapMonitor::isCareerUrl('https://example.com/join'), '/join');
        $this->assertTrue(SitemapMonitor::isCareerUrl('https://example.com/hiring'), '/hiring');
        $this->assertTrue(SitemapMonitor::isCareerUrl('https://example.com/vacancies'), '/vacancies');
        $this->assertTrue(SitemapMonitor::isCareerUrl('https://careers.example.com/'), 'careers subdomain');
    }

    private function testNonCareerUrls(): void
    {
        $this->startTest('non-career URLs not flagged');

        $this->assertFalse(SitemapMonitor::isCareerUrl('https://example.com/'), 'homepage');
        $this->assertFalse(SitemapMonitor::isCareerUrl('https://example.com/pricing'), 'pricing');
        $this->assertFalse(SitemapMonitor::isCareerUrl('https://example.com/products/joinery'), 'joinery contains join');
        $this->assertFalse(SitemapMonitor::isCareerUrl('https://example.com/blog/jobs-in-seo'), 'blog about jobs is not a posting page');
        $this->assertFalse(SitemapMonitor::isCareerUrl('https://example.com/about'), 'about');
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    (new SitemapMonitorTest())->runAll();
}

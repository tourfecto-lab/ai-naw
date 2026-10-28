<?php

/**
 * Tourfecto - Competitor Intelligence: CiConstants Test
 * @version 1.0.0
 *
 * اختبار offline للقيم المركزية: التأكد إن القوائم ما اتحورتش (إضافة/
 * حذف قيم) وإن within() بيعمل fallback صح. أي تغيير هنا لازم يتبقى
 * متوافق مع الـ ENUMs في قاعدة البيانات.
 *
 * تشغيل:
 *   php tests/Unit/CompetitorIntelligence/CiConstantsTest.php
 */
require_once __DIR__ . '/CiOfflineTestCase.php';
require_once dirname(__DIR__, 3) . '/app/Services/CompetitorIntelligence/CiConstants.php';

class CiConstantsTest extends CiOfflineTestCase
{
    public function runAll(): void
    {
        echo "\nCiConstants Tests\n=================\n";

        $this->testAllowedLists();
        $this->testWithin();
        $this->testSeverityRankConsistency();

        $this->printSummary();
    }

    private function testAllowedLists(): void
    {
        $this->startTest('allowed lists stay aligned with DB enums');

        $this->assertSame(['direct', 'indirect', 'emerging', 'potential'], CiConstants::CATEGORIES, 'categories match competitors.category');
        $this->assertSame(['daily', 'weekly', 'custom'], CiConstants::FREQUENCIES, 'frequencies match monitoring_frequency');
        $this->assertSame(['info', 'low', 'medium', 'high', 'critical'], CiConstants::SEVERITIES, 'severities match severity enum');
        $this->assertSame(['high', 'medium', 'low'], CiConstants::CONFIDENCE_LEVELS, 'confidence levels');
        $this->assertSame(['new', 'reviewed', 'dismissed'], CiConstants::INSIGHT_STATUSES, 'insight statuses match ci_insights.status');
        $this->assertSame(['dashboard', 'email', 'in_app', 'webhook', 'slack'], CiConstants::ALERT_CHANNELS, 'alert channels');
        $this->assertTrue(in_array('pricing', CiConstants::PAGE_TYPES, true), 'PAGE_TYPES includes pricing');
        $this->assertTrue(in_array('careers', CiConstants::PAGE_TYPES, true), 'PAGE_TYPES includes careers (hiring signal)');
    }

    private function testWithin(): void
    {
        $this->startTest('within() returns value only when allowed, else default');

        $this->assertSame('direct', CiConstants::within(CiConstants::CATEGORIES, 'direct', 'fallback'), 'allowed value passes through');
        $this->assertSame('fallback', CiConstants::within(CiConstants::CATEGORIES, 'evil', 'fallback'), 'disallowed value -> default');
        $this->assertSame('fallback', CiConstants::within(CiConstants::CATEGORIES, '', 'fallback'), 'empty -> default');
        $this->assertSame('fallback', CiConstants::within(CiConstants::CATEGORIES, null, 'fallback'), 'null -> default');
        $this->assertSame('weekly', CiConstants::within(CiConstants::FREQUENCIES, 'weekly', 'daily'), 'weekly allowed');
        $this->assertSame('daily', CiConstants::within(CiConstants::FREQUENCIES, 'hourly', 'daily'), 'hourly not allowed -> daily');
    }

    private function testSeverityRankConsistency(): void
    {
        $this->startTest('severity rank is monotonic across all severities');

        $ranks = array_values(CiConstants::SEVERITY_RANK);
        for ($i = 1; $i < count($ranks); $i++) {
            $this->assertTrue($ranks[$i] > $ranks[$i - 1], "rank[{$i}] > rank[" . ($i - 1) . ']');
        }
        // كل خطورة موجودة في الـ rank
        foreach (CiConstants::SEVERITIES as $sev) {
            $this->assertTrue(array_key_exists($sev, CiConstants::SEVERITY_RANK), "rank includes {$sev}");
        }
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    (new CiConstantsTest())->runAll();
}

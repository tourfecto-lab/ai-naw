<?php

/**
 * Tourfecto - GBP Reputation Analytics Test
 * اختبارات منطق Reputation Intelligence (Tier 1): KPIs + Risk Signals +
 * Share of Voice. تُختبر الـ Pure Function scoreRisk() فقط بمثيلات ثابتة
 * (Fixtures) - بدون أي اتصال بقاعدة بيانات حقيقية (Section 22 convention).
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

require_once __DIR__ . '/../../app/Services/GoogleBusiness/GbpReputationAnalyticsService.php';

class GbpReputationAnalyticsTest
{
    /** @var int */
    private $passed = 0;
    /** @var int */
    private $failed = 0;
    /** @var array */
    private $testResults = [];

    public function runAll(): void
    {
        echo "\n⭐ GBP Reputation Analytics Tests\n";
        echo "=================================\n";

        $this->testAllClearLowRisk();
        $this->testRatingDropDetection();
        $this->testReviewSpikeDetection();
        $this->testNegativeSpikeDetection();
        $this->testSuspiciousPatternDetection();
        $this->testRatingDropBoundary();
        $this->testBelowRatingDropBoundary();
        $this->testRiskLevelEscalation();
        $this->testEmptyMetrics();

        $this->printSummary();
    }

    private function testAllClearLowRisk(): void
    {
        $result = GbpReputationAnalyticsService::scoreRisk([
            'avg7' => 4.5, 'avg30' => 4.4, 'cnt7' => 3, 'cnt30' => 90, 'neg7' => 0, 'neg30' => 9,
        ]);
        $this->assertTrue(
            $result['risk_level'] === 'low' && $result['active_signals'] === 0,
            'All-clear data returns low risk with zero active signals'
        );
    }

    private function testRatingDropDetection(): void
    {
        $result = GbpReputationAnalyticsService::scoreRisk([
            'avg7' => 3.5, 'avg30' => 4.4, 'cnt7' => 5, 'cnt30' => 60, 'neg7' => 3, 'neg30' => 6,
        ]);
        $this->assertTrue(
            $result['signal_scores']['rating_drop'] === 1,
            'Rating drop >= 0.5 point over 7 days is flagged'
        );
        $this->assertTrue(
            isset($result['details']['rating_drop']['avg_7d']) && $result['details']['rating_drop']['avg_7d'] === 3.5,
            'Rating drop detail carries the true averages'
        );
    }

    private function testReviewSpikeDetection(): void
    {
        $result = GbpReputationAnalyticsService::scoreRisk([
            'avg7' => 4.6, 'avg30' => 4.6, 'cnt7' => 14, 'cnt30' => 60, 'neg7' => 1, 'neg30' => 5,
        ]);
        $this->assertTrue(
            $result['signal_scores']['review_spike'] === 1,
            'Review spike (7d > 2x daily average and >= 3) is flagged'
        );
        $this->assertTrue(
            $result['signal_scores']['rating_drop'] === 0,
            'Rating drop is not flagged on a spike-only case'
        );
    }

    private function testNegativeSpikeDetection(): void
    {
        $result = GbpReputationAnalyticsService::scoreRisk([
            'avg7' => 4.0, 'avg30' => 4.4, 'cnt7' => 10, 'cnt30' => 60, 'neg7' => 4, 'neg30' => 6,
        ]);
        $this->assertTrue(
            $result['signal_scores']['negative_spike'] === 1,
            'Negative-rate spike (7d rate >= 30d rate + 15 points) is flagged'
        );
    }

    private function testSuspiciousPatternDetection(): void
    {
        $result = GbpReputationAnalyticsService::scoreRisk([
            'avg7' => 4.6, 'avg30' => 4.6, 'cnt7' => 2, 'cnt30' => 30, 'neg7' => 0, 'neg30' => 0,
        ], [['date' => '2026-08-01', 'negative_reviews' => 4]]);
        $this->assertTrue(
            $result['signal_scores']['suspicious_pattern'] === 1,
            'Suspicious same-day low-rating cluster is flagged'
        );
        $this->assertTrue(
            $result['details']['suspicious_pattern'][0]['negative_reviews'] === 4,
            'Suspicious pattern details keep the true counts'
        );
    }

    private function testRatingDropBoundary(): void
    {
        $result = GbpReputationAnalyticsService::scoreRisk([
            'avg7' => 4.0, 'avg30' => 4.5, 'cnt7' => 3, 'cnt30' => 30, 'neg7' => 1, 'neg30' => 2,
        ]);
        $this->assertTrue(
            $result['signal_scores']['rating_drop'] === 1,
            'Exact 0.5-point drop boundary triggers the signal'
        );
    }

    private function testBelowRatingDropBoundary(): void
    {
        $result = GbpReputationAnalyticsService::scoreRisk([
            'avg7' => 4.1, 'avg30' => 4.5, 'cnt7' => 3, 'cnt30' => 30, 'neg7' => 1, 'neg30' => 2,
        ]);
        $this->assertTrue(
            $result['signal_scores']['rating_drop'] === 0,
            'Sub-0.5-point drop does not trigger the signal'
        );
    }

    private function testRiskLevelEscalation(): void
    {
        $result = GbpReputationAnalyticsService::scoreRisk([
            'avg7' => 3.5, 'avg30' => 4.4, 'cnt7' => 5, 'cnt30' => 60, 'neg7' => 3, 'neg30' => 6,
        ]);
        $this->assertTrue(
            $result['risk_level'] === 'high' && $result['active_signals'] >= 2,
            'Two or more active signals escalate risk level to high'
        );
    }

    private function testEmptyMetrics(): void
    {
        $result = GbpReputationAnalyticsService::scoreRisk([
            'avg7' => null, 'avg30' => null, 'cnt7' => 0, 'cnt30' => 0, 'neg7' => 0, 'neg30' => 0,
        ]);
        $this->assertTrue(
            $result['risk_level'] === 'low' && $result['active_signals'] === 0,
            'Empty metrics degrade gracefully to low risk'
        );
    }

    private function assertTrue(bool $condition, string $message): void
    {
        if ($condition) {
            echo "    ✅ {$message}\n";
            $this->passed++;
            $this->testResults[] = ['status' => 'PASS', 'message' => $message];
        } else {
            echo "    ❌ {$message}\n";
            $this->failed++;
            $this->testResults[] = ['status' => 'FAIL', 'message' => $message];
        }
    }

    private function printSummary(): void
    {
        $total = $this->passed + $this->failed;
        $percentage = $total > 0 ? round(($this->passed / $total) * 100, 2) : 0;

        echo "\n" . str_repeat('=', 50) . "\n";
        echo "⭐ GBP Reputation Analytics Test Summary\n";
        echo str_repeat('=', 50) . "\n";
        echo "  ✅ Passed: {$this->passed}\n";
        echo "  ❌ Failed: {$this->failed}\n";
        echo "  📝 Total: {$total}\n";
        echo "  📈 Success Rate: {$percentage}%\n";
        echo str_repeat('=', 50) . "\n\n";
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    $test = new GbpReputationAnalyticsTest();
    $test->runAll();
}

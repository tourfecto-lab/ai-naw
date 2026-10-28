<?php

/**
 * Tourfecto - GBP Local SEO Audit Test
 * اختبارات منطق Local SEO Audit (Tier 3). تُختبر الـ Pure Functions
 * الثابتة فقط بمثيلات (Fixtures) - بدون أي اتصال بقاعدة بيانات حقيقية
 * (Section 22 convention): sameish/digits/addressToString +
 * scoreReputation/scoreVisibility.
 * @version 1.0.0
 * @since 2026-08-15
 */

require_once __DIR__ . '/../../app/Services/GoogleBusiness/GbpLocalSeoAuditService.php';

class GbpLocalSeoAuditTest
{
    /** @var int */
    private $passed = 0;
    /** @var int */
    private $failed = 0;
    /** @var array */
    private $testResults = [];

    public function runAll(): void
    {
        echo "\n⭐ GBP Local SEO Audit Tests\n";
        echo "=================================\n";

        $this->testSameishIdentical();
        $this->testSameishCaseAndSlash();
        $this->testSameishWww();
        $this->testSameishFullUrlWithWww();
        $this->testSameishDifferent();
        $this->testSameishEmpty();
        $this->testSameishArabic();
        $this->testDigitsOnlyNumbers();
        $this->testDigitsEmpty();
        $this->testDigitsDashes();
        $this->testAddressToStringJoined();
        $this->testAddressToStringEmpty();
        $this->testScoreReputationPerfect();
        $this->testScoreReputationLow();
        $this->testScoreReputationWeight();
        $this->testScoreVisibilityMissingKey();
        $this->testScoreVisibilityFullMarks();
        $this->testScoreVisibilityWeight();

        $this->printSummary();
    }

    private function testSameishIdentical(): void
    {
        $this->assertTrue(
            GbpLocalSeoAuditService::sameish('https://tourfecto.com', 'https://tourfecto.com'),
            'Identical website strings match'
        );
    }

    private function testSameishCaseAndSlash(): void
    {
        $this->assertTrue(
            GbpLocalSeoAuditService::sameish('HTTPS://Tourfecto.com/', 'https://tourfecto.com'),
            'Case and trailing slash are ignored'
        );
    }

    private function testSameishWww(): void
    {
        $this->assertTrue(
            GbpLocalSeoAuditService::sameish('www.tourfecto.com', 'tourfecto.com'),
            'www prefix is ignored'
        );
    }

    private function testSameishFullUrlWithWww(): void
    {
        $this->assertTrue(
            GbpLocalSeoAuditService::sameish('https://www.tourfecto.com/', 'https://tourfecto.com'),
            'Scheme, www and trailing slash are ignored in full URLs'
        );
    }

    private function testSameishDifferent(): void
    {
        $this->assertTrue(
            !GbpLocalSeoAuditService::sameish('https://tourfecto.com', 'https://competitor.com'),
            'Different websites do not match'
        );
    }

    private function testSameishEmpty(): void
    {
        $this->assertTrue(
            !GbpLocalSeoAuditService::sameish('', 'tourfecto.com')
            && !GbpLocalSeoAuditService::sameish('tourfecto.com', ''),
            'Empty string never matches'
        );
    }

    private function testSameishArabic(): void
    {
        $this->assertTrue(
            GbpLocalSeoAuditService::sameish('شركة السفاري للسياحة', 'شركة السفاري للسياحة'),
            'Identical Arabic names match'
        );
        $this->assertTrue(
            !GbpLocalSeoAuditService::sameish('شركة السفاري للسياحة', 'شركة الرحلات الذهبية'),
            'Different Arabic names do not match'
        );
    }

    private function testDigitsOnlyNumbers(): void
    {
        $this->assertTrue(
            GbpLocalSeoAuditService::digits('+20 101 234 5678') === '201012345678',
            'digits() strips non-numeric characters'
        );
    }

    private function testDigitsEmpty(): void
    {
        $this->assertTrue(
            GbpLocalSeoAuditService::digits('') === '',
            'digits() handles empty string'
        );
    }

    private function testDigitsDashes(): void
    {
        $this->assertTrue(
            GbpLocalSeoAuditService::digits('010-123-456-78') === '01012345678',
            'digits() handles dashes'
        );
    }

    private function testAddressToStringJoined(): void
    {
        $result = GbpLocalSeoAuditService::addressToString([
            'addressLines' => ['12 شارع التحرير'],
            'locality' => 'القاهرة',
            'postalCode' => '11511',
        ]);
        $this->assertTrue(
            mb_strpos($result, '12 شارع التحرير') !== false && mb_strpos($result, 'القاهرة') !== false,
            'addressToString() joins address lines and locality'
        );
    }

    private function testAddressToStringEmpty(): void
    {
        $this->assertTrue(
            GbpLocalSeoAuditService::addressToString([]) === '',
            'addressToString() returns empty for empty input'
        );
    }

    private function testScoreReputationPerfect(): void
    {
        $result = GbpLocalSeoAuditService::scoreReputation([
            'response_rate' => 100.0,
            'review_count_30d' => 12,
            'unreplied_negative' => 0,
        ]);
        $this->assertTrue(
            $result['score'] === 100,
            'Perfect response metrics score 100'
        );
    }

    private function testScoreReputationLow(): void
    {
        $result = GbpLocalSeoAuditService::scoreReputation([
            'response_rate' => 30.0,
            'review_count_30d' => 3,
            'unreplied_negative' => 2,
        ]);
        $this->assertTrue(
            $result['score'] > 0 && $result['score'] < 100,
            'Low response metrics score between 0 and 100'
        );
    }

    private function testScoreReputationWeight(): void
    {
        $result = GbpLocalSeoAuditService::scoreReputation([
            'response_rate' => 0,
            'review_count_30d' => 0,
            'unreplied_negative' => 1,
        ]);
        $this->assertTrue(
            $result['weight'] === 25,
            'Reputation section weight is 25'
        );
    }

    private function testScoreVisibilityMissingKey(): void
    {
        $result = GbpLocalSeoAuditService::scoreVisibility([
            'found' => false,
            'photo_count' => 0,
            'rating' => null,
            'review_count' => 0,
        ]);
        $this->assertTrue(
            $result['score'] === 0,
            'Business not found on Places scores 0'
        );
    }

    private function testScoreVisibilityFullMarks(): void
    {
        $result = GbpLocalSeoAuditService::scoreVisibility([
            'found' => true,
            'photo_count' => 12,
            'rating' => 4.7,
            'review_count' => 85,
        ]);
        $this->assertTrue(
            $result['score'] === 100,
            'Strong Places presence scores 100'
        );
    }

    private function testScoreVisibilityWeight(): void
    {
        $result = GbpLocalSeoAuditService::scoreVisibility([
            'found' => true,
            'photo_count' => 10,
            'rating' => 4.5,
            'review_count' => 50,
        ]);
        $this->assertTrue(
            $result['weight'] === 20,
            'Visibility section weight is 20'
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
        echo "⭐ GBP Local SEO Audit Test Summary\n";
        echo str_repeat('=', 50) . "\n";
        echo "  ✅ Passed: {$this->passed}\n";
        echo "  ❌ Failed: {$this->failed}\n";
        echo "  📝 Total: {$total}\n";
        echo "  📈 Success Rate: {$percentage}%\n";
        echo str_repeat('=', 50) . "\n\n";
    }
}

if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    $test = new GbpLocalSeoAuditTest();
    $test->runAll();
}

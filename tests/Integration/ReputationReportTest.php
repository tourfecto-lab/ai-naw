<?php

/**
 * Reputation: monthly white-label report (real numbers only, previous-month comparison, escaping, tenant isolation).
 */

use PHPUnit\Framework\TestCase;

final class ReputationReportTest extends TestCase
{
    private const A = 999081;
    private const B = 999082;
    private const WA = 999081;
    private const WB = 999082;

    private ?PDO $pdo = null;
    private ?Database $db = null;

    protected function setUp(): void
    {
        try {
            $this->db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $this->pdo = $ref->getValue($this->db);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB not available');
        }
        if (!$this->pdo instanceof PDO) {
            $this->markTestSkipped('DB not available');
        }
        $this->cleanup();
        $this->pdo->exec("INSERT INTO users (id, email, password, company_name, created_at) VALUES (" . self::A . ", 'rep-a@tourfecto.test', 'x', 'A', NOW()), (" . self::B . ", 'rep-b@tourfecto.test', 'x', 'B', NOW())");
        $this->pdo->exec("INSERT INTO websites (id, user_id, main_url, company_name, industry, is_verified, created_at) VALUES (" . self::WA . ", " . self::A . ", 'https://rep-a.test', '<b>Nile</b> Tours', 'tourism', 1, NOW()), (" . self::WB . ", " . self::B . ", 'https://rep-b.test', 'B Co', 'tourism', 1, NOW())");
        // current month 2026-09: 3 reviews (5, 4, 1 stars), 2 replied; previous month 2026-08: 1 review (5 stars)
        $this->review(self::A, self::WA, 'a1', 5, 'positive', 'Wonderful guide, the driver was safe and the trip was perfectly organised', '2026-09-05 10:00:00', '2026-09-05 12:00:00');
        $this->review(self::A, self::WA, 'a2', 4, 'positive', 'Good value', '2026-09-10 10:00:00', '2026-09-11 10:00:00');
        $this->review(self::A, self::WA, 'a3', 1, 'negative', 'Late pickup and rude staff', '2026-09-15 10:00:00', null);
        $this->review(self::A, self::WA, 'a4', 5, 'positive', 'Great', '2026-08-20 10:00:00', null);
        $this->review(self::B, self::WB, 'b1', 1, 'negative', 'Other tenant secret', '2026-09-12 10:00:00', null);
    }

    protected function tearDown(): void
    {
        if ($this->pdo) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM reviews WHERE user_id IN (" . self::A . "," . self::B . ")");
        $this->pdo->exec("DELETE FROM websites WHERE id IN (" . self::WA . "," . self::WB . ")");
        $this->pdo->exec("DELETE FROM users WHERE id IN (" . self::A . "," . self::B . ")");
    }

    private function review(int $uid, int $wid, string $ext, int $rating, string $sentiment, string $text, string $date, ?string $repliedAt): void
    {
        $st = $this->pdo->prepare("INSERT INTO reviews (website_id, user_id, source_platform, external_review_id, reviewer_name, review_text, rating, sentiment, review_date, reply_sent_at, reply_status) VALUES (?, ?, 'google_business', ?, 'Guest', ?, ?, ?, ?, ?, ?)");
        $st->execute([$wid, $uid, $ext, $text, $rating, $sentiment, $date, $repliedAt, $repliedAt ? 'sent' : null]);
    }

    public function testNumbersMatchTheStoredReviewsAndComparePreviousMonth(): void
    {
        $d = ReputationReport::build(self::A, self::WA, '2026-09', $this->db);
        $this->assertSame(3, $d['current']['total']);
        $this->assertSame(3.33, $d['current']['avg_rating']);
        $this->assertSame(2, $d['current']['positive']);
        $this->assertSame(1, $d['current']['negative']);
        $this->assertSame(67, (int) $d['current']['response_rate']);
        $this->assertSame(13.0, $d['current']['avg_reply_hours'], 'average of 2h and 24h');
        $this->assertSame(1, $d['previous']['total']);
        $this->assertSame([5 => 1, 4 => 1, 3 => 0, 2 => 0, 1 => 1], $d['by_rating']);
        $this->assertCount(1, $d['needs_reply']);
        $this->assertSame('Late pickup and rude staff', $d['needs_reply'][0]['review_text']);
    }

    public function testAnotherAccountsWebsiteCannotBeReported(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReputationReport::build(self::A, self::WB, '2026-09', $this->db);
    }

    public function testReportNeverContainsAnotherTenantsData(): void
    {
        $html = ReputationReport::render(ReputationReport::build(self::A, self::WA, '2026-09', $this->db), false);
        $this->assertFalse(strpos($html, 'Other tenant secret') !== false);
    }

    public function testRenderEscapesNamesAndIsWhiteLabelled(): void
    {
        $d = ReputationReport::build(self::A, self::WA, '2026-09', $this->db);
        $html = ReputationReport::render($d, false);
        $this->assertStringContainsString('&lt;b&gt;Nile&lt;/b&gt; Tours', $html);
        $this->assertFalse(stripos($html, 'tourfecto') !== false, 'no platform branding in a white-label report');
        $this->assertStringContainsString('Monthly reputation report', $html);
        $ar = ReputationReport::render($d, true);
        $this->assertStringContainsString('dir="rtl"', $ar);
    }

    public function testEmptyMonthExplainsInsteadOfShowingZerosAsPerformance(): void
    {
        $d = ReputationReport::build(self::A, self::WA, '2026-01', $this->db);
        $this->assertSame(0, $d['current']['total']);
        $this->assertCount(1, $d['insights']);
        $this->assertStringContainsString('No new reviews', $d['insights'][0][1]);
        $this->assertNull($d['current']['avg_rating']);
    }

    public function testInsightsFlagLowResponseRateAndRatingDrop(): void
    {
        $d = ['current' => ['total' => 10, 'avg_rating' => 4.0, 'negative' => 3, 'response_rate' => 40, 'avg_reply_hours' => 60.0],
            'previous' => ['total' => 12, 'avg_rating' => 4.6], 'needs_reply' => [[], []]];
        $text = implode(' | ', array_column(ReputationReport::insights($d), 1));
        $this->assertStringContainsString('2 review(s)', $text);
        $this->assertStringContainsString('Response rate is 40%', $text);
        $this->assertStringContainsString('dropped from 4.6 to 4', $text);
        $this->assertStringContainsString('60 hours', $text);
    }

    public function testMonthParameterIsValidated(): void
    {
        $this->assertSame('2026-09', ReputationReport::monthOrDefault('2026-09'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}$/', ReputationReport::monthOrDefault('2026-13'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}$/', ReputationReport::monthOrDefault("2026-09' OR 1=1"));
    }
}

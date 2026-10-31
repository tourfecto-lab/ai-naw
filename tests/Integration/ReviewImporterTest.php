<?php

/**
 * Reputation: CSV import of reviews from sites without an open API (parse rules, scale, dedupe, tenant ownership).
 */

use PHPUnit\Framework\TestCase;

final class ReviewImporterTest extends TestCase
{
    private const UID = 999051;
    private const OTHER = 999052;
    private const WID = 999051;

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
        $this->pdo->exec("INSERT INTO users (id, email, password, company_name, created_at) VALUES (" . self::UID . ", 'imp@tourfecto.test', 'x', 'T', NOW()), (" . self::OTHER . ", 'imp2@tourfecto.test', 'x', 'T2', NOW())");
        $this->pdo->exec("INSERT INTO websites (id, user_id, main_url, company_name, industry, is_verified, created_at) VALUES (" . self::WID . ", " . self::UID . ", 'https://imp.test', 'T', 'tourism', 1, NOW())");
    }

    protected function tearDown(): void
    {
        if ($this->pdo) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM reviews WHERE user_id IN (" . self::UID . "," . self::OTHER . ")");
        $this->pdo->exec("DELETE FROM websites WHERE id = " . self::WID);
        $this->pdo->exec("DELETE FROM users WHERE id IN (" . self::UID . "," . self::OTHER . ")");
    }

    private const CSV = "reviewer_name,rating,review_text,review_date,external_id\nAnna,5,\"Great guide, loved it\",2026-10-01,b1\nBob,2,Late pickup,2026-10-03,b2\n";

    public function testParseReadsRowsAndConvertsATenPointScale(): void
    {
        $p = ReviewImporter::parse("score;comment;name;date\n9,2;Wonderful;Sam;2026-09-30\n4;Poor;Lee;2026-09-29\n", 10);
        $this->assertSame([], $p['errors']);
        $this->assertSame(5, $p['rows'][0]['rating'], '9.2/10 rounds to 5 stars');
        $this->assertSame(2, $p['rows'][1]['rating'], '4/10 rounds to 2 stars');
        $this->assertSame('Sam', $p['rows'][0]['reviewer_name']);
    }

    public function testParseReportsBadRowsWithoutStoppingTheRest(): void
    {
        $p = ReviewImporter::parse("rating,review_text,review_date\n5,ok,2026-10-01\nabc,bad rating,2026-10-01\n9,out of range,2026-10-01\n4,bad date,not-a-date\n", 5);
        $this->assertCount(1, $p['rows']);
        $this->assertCount(3, $p['errors']);
    }

    public function testParseRejectsAFileWithoutTheRequiredColumns(): void
    {
        $p = ReviewImporter::parse("foo,bar\n1,2\n");
        $this->assertSame([], $p['rows']);
        $this->assertStringContainsString('Missing column', $p['errors'][0]);
    }

    public function testParseRejectsNonUtf8(): void
    {
        $p = ReviewImporter::parse("rating,review_text\n5," . "\xE4\xF6" . "\n");
        $this->assertStringContainsString('UTF-8', $p['errors'][0]);
    }

    public function testDryRunStoresNothingThenImportStoresAndReimportIsIdempotent(): void
    {
        $dry = ReviewImporter::import(self::UID, self::WID, 'booking', self::CSV, 5, true, $this->db);
        $this->assertSame(2, $dry['imported']);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM reviews WHERE user_id = " . self::UID)->fetchColumn());

        $first = ReviewImporter::import(self::UID, self::WID, 'booking', self::CSV, 5, false, $this->db);
        $this->assertSame(2, $first['imported']);
        $row = $this->pdo->query("SELECT source_platform, rating, sentiment, reply_status FROM reviews WHERE user_id = " . self::UID . " AND reviewer_name = 'Bob'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('booking', $row['source_platform']);
        $this->assertSame('negative', $row['sentiment']);
        $this->assertNull($row['reply_status']);

        $again = ReviewImporter::import(self::UID, self::WID, 'booking', self::CSV, 5, false, $this->db);
        $this->assertSame(0, $again['imported']);
        $this->assertSame(2, $again['duplicates']);
        $this->assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM reviews WHERE user_id = " . self::UID)->fetchColumn());
    }

    public function testSameExternalIdOnAnotherWebsiteIsNotTreatedAsADuplicate(): void
    {
        ReviewImporter::import(self::UID, self::WID, 'booking', self::CSV, 5, false, $this->db);
        $this->pdo->exec("INSERT INTO websites (id, user_id, main_url, company_name, industry, is_verified, created_at) VALUES (" . (self::WID + 1) . ", " . self::OTHER . ", 'https://imp2.test', 'T2', 'tourism', 1, NOW())");
        try {
            $res = ReviewImporter::import(self::OTHER, self::WID + 1, 'booking', self::CSV, 5, false, $this->db);
            $this->assertSame(2, $res['imported'], 'another account importing the same ids must not be blocked or see the first');
            $this->assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM reviews WHERE user_id = " . self::OTHER)->fetchColumn());
        } finally {
            $this->pdo->exec("DELETE FROM reviews WHERE website_id = " . (self::WID + 1));
            $this->pdo->exec("DELETE FROM websites WHERE id = " . (self::WID + 1));
        }
    }

    public function testUnknownSourceIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReviewImporter::import(self::UID, self::WID, 'google_business', self::CSV, 5, true, $this->db);
    }
}

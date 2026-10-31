<?php

/**
 * Reputation: the admin decides, per outside review site, whether CSV import and automatic sync are on.
 */

use PHPUnit\Framework\TestCase;

final class ReviewSourcesTest extends TestCase
{
    private ?PDO $pdo = null;
    private ?Database $db = null;
    private array $saved = [];

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
        foreach (['reputation_sources', 'reputation_limits_audit'] as $k) {
            $v = $this->pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = '$k'")->fetchColumn();
            $this->saved[$k] = $v === false ? null : $v;
            $this->pdo->exec("DELETE FROM system_settings WHERE setting_key = '$k'");
        }
    }

    protected function tearDown(): void
    {
        if (!$this->pdo) {
            return;
        }
        foreach ($this->saved as $k => $v) {
            $this->pdo->exec("DELETE FROM system_settings WHERE setting_key = '$k'");
            if ($v !== null) {
                $st = $this->pdo->prepare('INSERT INTO system_settings (setting_key, setting_value, is_secret) VALUES (?, ?, 0)');
                $st->execute([$k, $v]);
            }
        }
    }

    public function testDefaultsKeepTodaysBehaviour(): void
    {
        foreach (['booking', 'expedia', 'trustpilot', 'custom'] as $s) {
            $this->assertTrue(ReviewSources::allowed($s, 'csv_import', $this->db), $s);
            $this->assertFalse(ReviewSources::allowed($s, 'auto_sync', $this->db), $s . ' has no connector');
        }
        $this->assertTrue(ReviewSources::allowed('tripadvisor', 'auto_sync', $this->db));
        $this->assertFalse(ReviewSources::allowed('tripadvisor', 'csv_import', $this->db));
        $this->assertFalse(ReviewSources::allowed('google_business', 'csv_import', $this->db), 'unknown sources are never allowed');
    }

    public function testAdminCanTurnCsvImportOffAndOnPerSource(): void
    {
        $r = ReviewSources::save(['booking' => ['csv_import' => false]], 5, $this->db);
        $this->assertCount(1, $r['changes']);
        $this->assertFalse(ReviewSources::allowed('booking', 'csv_import', $this->db));
        $this->assertTrue(ReviewSources::allowed('expedia', 'csv_import', $this->db), 'other sources unaffected');
        ReviewSources::save(['booking' => ['csv_import' => true]], 5, $this->db);
        $this->assertTrue(ReviewSources::allowed('booking', 'csv_import', $this->db));
    }

    public function testAutomaticSyncCannotBeSwitchedOnWithoutAConnector(): void
    {
        try {
            ReviewSources::save(['booking' => ['auto_sync' => true]], 5, $this->db);
            $this->fail('Booking has no connector');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('no connector', $e->getMessage());
        }
        $this->assertFalse(ReviewSources::allowed('booking', 'auto_sync', $this->db));
        ReviewSources::save(['booking' => ['auto_sync' => false]], 5, $this->db); // turning off something that is off is fine
        ReviewSources::save(['tripadvisor' => ['auto_sync' => false]], 5, $this->db);
        $this->assertFalse(ReviewSources::allowed('tripadvisor', 'auto_sync', $this->db));
    }

    public function testBothWaysCanBeOnTogetherAndUnknownInputIsRejected(): void
    {
        $this->assertTrue(ReviewSources::allowed('tripadvisor', 'auto_sync', $this->db));
        foreach ([['nope' => ['csv_import' => true]], ['booking' => ['price' => true]], ['tripadvisor' => ['csv_import' => true]]] as $bad) {
            try {
                ReviewSources::save($bad, 5, $this->db);
                $this->fail('expected rejection of ' . json_encode($bad));
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function testChangesAreRecordedInTheAdminHistory(): void
    {
        ReviewSources::save(['expedia' => ['csv_import' => false]], 77, $this->db);
        $log = ReputationLimits::auditLog(5, $this->db);
        $this->assertSame(77, $log[0]['actor_id']);
        $this->assertSame('source:expedia', $log[0]['changes'][0]['plan']);
        $this->assertTrue($log[0]['changes'][0]['from']);
        $this->assertFalse($log[0]['changes'][0]['to']);
    }

    public function testTripAdvisorScheduledSyncStopsWhenTheAdminTurnsItOff(): void
    {
        ReviewSources::save(['tripadvisor' => ['auto_sync' => false]], 5, $this->db);
        $summary = (new TripAdvisorReviewSyncService())->syncAll();
        $this->assertSame(0, $summary['synced']);
        $this->assertSame(0, $summary['new_reviews']);
    }
}

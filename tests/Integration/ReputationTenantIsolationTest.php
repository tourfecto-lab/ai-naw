<?php

/**
 * Reputation: one account must not be able to read, change, disconnect or leave traces on another account's data.
 */

use PHPUnit\Framework\TestCase;

final class ReputationTenantIsolationTest extends TestCase
{
    private const A = 999071;
    private const B = 999072;
    private const WA = 999071;
    private const WB = 999072;

    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        try {
            $db = Database::getInstance();
            $ref = new ReflectionProperty(Database::class, 'connection');
            $ref->setAccessible(true);
            $this->pdo = $ref->getValue($db);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB not available');
        }
        if (!$this->pdo instanceof PDO) {
            $this->markTestSkipped('DB not available');
        }
        $this->cleanup();
        $this->pdo->exec("INSERT INTO users (id, email, password, company_name, created_at) VALUES (" . self::A . ", 'iso-a@tourfecto.test', 'x', 'A', NOW()), (" . self::B . ", 'iso-b@tourfecto.test', 'x', 'B', NOW())");
        $this->pdo->exec("INSERT INTO websites (id, user_id, main_url, company_name, industry, is_verified, created_at) VALUES (" . self::WA . ", " . self::A . ", 'https://a.test', 'A', 'tourism', 1, NOW()), (" . self::WB . ", " . self::B . ", 'https://b.test', 'B', 'tourism', 1, NOW())");
        $this->pdo->exec("INSERT INTO platform_connections (website_id, user_id, platform, status) VALUES (" . self::WB . ", " . self::B . ", 'google_business', 'connected'), (" . self::WB . ", " . self::B . ", 'tripadvisor', 'connected')");
        $this->pdo->exec("INSERT INTO reviews (website_id, user_id, source_platform, external_review_id, reviewer_name, review_text, rating, review_date) VALUES (" . self::WB . ", " . self::B . ", 'google_business', 'iso-b-1', 'Guest B', 'Secret review of B', 5, NOW())");
    }

    protected function tearDown(): void
    {
        if ($this->pdo) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $ids = self::A . ',' . self::B;
        $this->pdo->exec("DELETE FROM gbp_audit_log WHERE website_id IN (" . self::WA . "," . self::WB . ")");
        $this->pdo->exec("DELETE FROM reviews WHERE user_id IN ($ids)");
        $this->pdo->exec("DELETE FROM platform_connections WHERE user_id IN ($ids)");
        $this->pdo->exec("DELETE FROM websites WHERE id IN (" . self::WA . "," . self::WB . ")");
        $this->pdo->exec("DELETE FROM users WHERE id IN ($ids)");
    }

    private function asUserA(array $data = []): ReputationController
    {
        $c = new ReputationController();
        foreach (['authenticated' => true, 'user' => ['id' => self::A], 'data' => $data] as $prop => $value) {
            $r = new ReflectionProperty(Controller::class, $prop);
            $r->setAccessible(true);
            $r->setValue($c, $value);
        }
        return $c;
    }

    private function bReviewId(): int
    {
        return (int) $this->pdo->query("SELECT id FROM reviews WHERE external_review_id = 'iso-b-1'")->fetchColumn();
    }

    public function testCannotReadAnotherAccountsReview(): void
    {
        $res = $this->asUserA()->getReview(['id' => $this->bReviewId()]);
        $this->assertFalse($res['success'] ?? true);
        $this->assertSame(404, $res['code'] ?? $res['status'] ?? 404);
    }

    public function testCannotDeleteAnotherAccountsReview(): void
    {
        $this->asUserA()->deleteReview(['id' => $this->bReviewId()]);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM reviews WHERE external_review_id = 'iso-b-1'")->fetchColumn());
    }

    public function testListingOnlyReturnsOwnReviews(): void
    {
        $res = $this->asUserA()->getReviews();
        $this->assertTrue($res['success'] ?? false);
        $this->assertSame(0, count($res['data']['reviews'] ?? []));
    }

    public function testDisconnectingSomeoneElsesWebsiteChangesNothingAndLeavesNoAuditTrace(): void
    {
        foreach (['disconnectGoogleBusiness', 'disconnectTripAdvisor'] as $method) {
            $res = $this->asUserA()->$method(['website_id' => self::WB]);
            $this->assertFalse($res['success'] ?? true, $method);
        }
        $this->assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM platform_connections WHERE website_id = " . self::WB)->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM gbp_audit_log WHERE website_id = " . self::WB)->fetchColumn());
    }

    public function testImportAndAssignmentRejectAnotherAccountsWebsiteOrReview(): void
    {
        $import = $this->asUserA(['website_id' => self::WB, 'source' => 'booking', 'csv' => "rating,review_text\n5,x\n", 'csrf_token' => Csrf::token()])->importReviews();
        $this->assertFalse($import['success'] ?? true);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM reviews WHERE website_id = " . self::WB . " AND review_text = 'x'")->fetchColumn());
        $assign = ReviewAssignment::assign(self::A, $this->bReviewId(), self::A, self::A);
        $this->assertSame(404, $assign['code'] ?? 0);
    }
}

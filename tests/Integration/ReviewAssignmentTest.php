<?php

/**
 * Reputation: assigning a review to a team member (tenant isolation, team membership, deadline, clearing).
 */

use PHPUnit\Framework\TestCase;

final class ReviewAssignmentTest extends TestCase
{
    private const OWNER = 999041;
    private const MEMBER = 999042;
    private const OTHER_OWNER = 999043;
    private const OUTSIDER = 999044;
    private const WID = 999041;

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
        if (!$this->pdo instanceof PDO || !ReviewAssignment::ready($this->db)) {
            $this->markTestSkipped('DB or review assignment columns not available');
        }
        $this->cleanup();
        $this->pdo->exec("INSERT INTO users (id, email, password, company_name, owner_user_id, created_at) VALUES
            (" . self::OWNER . ", 'asg-owner@tourfecto.test', 'x', 'Owner Co', NULL, NOW()),
            (" . self::MEMBER . ", 'asg-member@tourfecto.test', 'x', 'Member', " . self::OWNER . ", NOW()),
            (" . self::OTHER_OWNER . ", 'asg-other@tourfecto.test', 'x', 'Other Co', NULL, NOW()),
            (" . self::OUTSIDER . ", 'asg-out@tourfecto.test', 'x', 'Outsider', " . self::OTHER_OWNER . ", NOW())");
        $this->pdo->exec("INSERT INTO websites (id, user_id, main_url, company_name, industry, is_verified, created_at) VALUES (" . self::WID . ", " . self::OWNER . ", 'https://asg.test', 'T', 'tourism', 1, NOW())");
    }

    protected function tearDown(): void
    {
        if ($this->pdo) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $ids = implode(',', [self::OWNER, self::MEMBER, self::OTHER_OWNER, self::OUTSIDER]);
        $this->pdo->exec("DELETE FROM notifications WHERE user_id IN ($ids)");
        $this->pdo->exec("DELETE FROM reviews WHERE user_id IN ($ids)");
        $this->pdo->exec("DELETE FROM websites WHERE id = " . self::WID);
        $this->pdo->exec("DELETE FROM users WHERE id IN ($ids)");
    }

    private function review(int $owner): int
    {
        $st = $this->pdo->prepare("INSERT INTO reviews (website_id, user_id, source_platform, external_review_id, reviewer_name, review_text, rating, review_date) VALUES (?, ?, 'google_business', ?, 'Guest', 'Terrible', 1, NOW())");
        $st->execute([self::WID, $owner, 'x-' . bin2hex(random_bytes(4))]);
        return (int) $this->pdo->lastInsertId();
    }

    private function row(int $id): array
    {
        return $this->pdo->query("SELECT assigned_to, assigned_by, assigned_at, assignment_due_at, assignment_note FROM reviews WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    }

    public function testAssignableListsOwnerThenTeamOnly(): void
    {
        $ids = array_column(ReviewAssignment::assignable(self::OWNER, $this->db), 'id');
        $this->assertSame([self::OWNER, self::MEMBER], $ids);
    }

    public function testAssignToTeamMemberSetsDeadlineAndNote(): void
    {
        $id = $this->review(self::OWNER);
        $res = ReviewAssignment::assign(self::OWNER, $id, self::OWNER, self::MEMBER, 48, '  Call the guest  ', $this->db);
        $this->assertTrue($res['success']);
        $row = $this->row($id);
        $this->assertSame((string) self::MEMBER, (string) $row['assigned_to']);
        $this->assertSame('Call the guest', $row['assignment_note']);
        $hours = (strtotime($row['assignment_due_at']) - strtotime($row['assigned_at'])) / 3600;
        $this->assertSame(48, (int) round($hours));
    }

    public function testCannotAssignToSomeoneOutsideTheTeam(): void
    {
        $id = $this->review(self::OWNER);
        $res = ReviewAssignment::assign(self::OWNER, $id, self::OWNER, self::OUTSIDER, 24, '', $this->db);
        $this->assertFalse($res['success']);
        $this->assertSame(422, $res['code']);
        $this->assertNull($this->row($id)['assigned_to']);
    }

    public function testCannotAssignAnotherAccountsReview(): void
    {
        $theirs = $this->review(self::OTHER_OWNER);
        $res = ReviewAssignment::assign(self::OWNER, $theirs, self::OWNER, self::MEMBER, 24, '', $this->db);
        $this->assertFalse($res['success']);
        $this->assertSame(404, $res['code']);
        $this->assertNull($this->row($theirs)['assigned_to']);
    }

    public function testClearingRemovesTheAssignment(): void
    {
        $id = $this->review(self::OWNER);
        ReviewAssignment::assign(self::OWNER, $id, self::OWNER, self::MEMBER, 24, 'x', $this->db);
        $res = ReviewAssignment::assign(self::OWNER, $id, self::OWNER, null, 24, '', $this->db);
        $this->assertTrue($res['success']);
        $row = $this->row($id);
        $this->assertNull($row['assigned_to']);
        $this->assertNull($row['assignment_due_at']);
    }

    public function testOverdueListsOnlyUnrepliedPastDeadline(): void
    {
        $late = $this->review(self::OWNER);
        $fine = $this->review(self::OWNER);
        ReviewAssignment::assign(self::OWNER, $late, self::OWNER, self::MEMBER, 24, '', $this->db);
        ReviewAssignment::assign(self::OWNER, $fine, self::OWNER, self::MEMBER, 24, '', $this->db);
        $this->pdo->exec("UPDATE reviews SET assignment_due_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE id = $late");
        $ids = array_map('intval', array_column(ReviewAssignment::overdue(self::OWNER, $this->db), 'id'));
        $this->assertSame([$late], $ids);
        $this->pdo->exec("UPDATE reviews SET reply_sent_at = NOW() WHERE id = $late");
        $this->assertSame([], ReviewAssignment::overdue(self::OWNER, $this->db));
    }
}

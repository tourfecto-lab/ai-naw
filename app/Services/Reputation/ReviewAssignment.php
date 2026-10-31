<?php

/**
 * Reputation: assign a review (typically a negative or critical one) to a team member with a reply deadline.
 * Account model: reviews belong to the account owner (reviews.user_id). Team members are users with
 * users.owner_user_id = owner. Only the owner or one of their team members can be assigned.
 * Needs migration 2026_10_31_000001_review_assignment.sql; until it is applied ready() is false and callers
 * show "migration pending" instead of failing.
 */
class ReviewAssignment
{
    public const MAX_DUE_HOURS = 720;
    public const COLUMNS = ['assigned_to', 'assigned_by', 'assigned_at', 'assignment_due_at', 'assignment_note'];

    private static ?bool $ready = null;

    public static function ready(?Database $db = null): bool
    {
        if (self::$ready !== null) {
            return self::$ready;
        }
        try {
            $db = $db ?: Database::getInstance();
            $have = array_column($db->query("SHOW COLUMNS FROM reviews WHERE Field IN ('assigned_to','assigned_by','assigned_at','assignment_due_at','assignment_note')"), 'Field');
            return self::$ready = count($have) === count(self::COLUMNS);
        } catch (Throwable $e) {
            return self::$ready = false;
        }
    }

    public static function flush(): void
    {
        self::$ready = null;
    }

    /** @return array<int,array{id:int,name:string,email:string,role:string}> the owner first, then team members */
    public static function assignable(int $ownerId, ?Database $db = null): array
    {
        $db = $db ?: Database::getInstance();
        $rows = $db->query(
            'SELECT id, email, COALESCE(NULLIF(company_name, \'\'), email) AS name, owner_user_id FROM users WHERE id = ? OR owner_user_id = ? ORDER BY (id = ?) DESC, id ASC LIMIT 100',
            [$ownerId, $ownerId, $ownerId]
        );
        return array_map(static fn ($r) => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'email' => (string) $r['email'],
            'role' => (int) $r['id'] === $ownerId ? 'owner' : 'member',
        ], $rows);
    }

    /**
     * @param int|null $assigneeId null clears the assignment
     * @return array{success:bool,error?:string,code?:int,assignment?:array}
     */
    public static function assign(int $ownerId, int $reviewId, int $actorId, ?int $assigneeId, ?int $dueHours = 24, string $note = '', ?Database $db = null): array
    {
        $db = $db ?: Database::getInstance();
        if (!self::ready($db)) {
            return ['success' => false, 'code' => 503, 'error' => 'Assignment needs a database update that has not been applied yet'];
        }
        $review = $db->query('SELECT id, rating, reviewer_name, source_platform FROM reviews WHERE id = ? AND user_id = ? LIMIT 1', [$reviewId, $ownerId])[0] ?? null;
        if (!$review) {
            return ['success' => false, 'code' => 404, 'error' => 'Review not found'];
        }
        if ($assigneeId === null) {
            $db->exec('UPDATE reviews SET assigned_to = NULL, assigned_by = ?, assigned_at = NULL, assignment_due_at = NULL, assignment_note = NULL WHERE id = ? AND user_id = ?', [$actorId, $reviewId, $ownerId]);
            return ['success' => true, 'assignment' => null];
        }
        $allowed = array_column(self::assignable($ownerId, $db), 'id');
        if (!in_array($assigneeId, $allowed, true)) {
            return ['success' => false, 'code' => 422, 'error' => 'This person is not a member of your team'];
        }
        $hours = max(1, min(self::MAX_DUE_HOURS, (int) $dueHours));
        $note = mb_substr(trim($note), 0, 500);
        $db->exec(
            'UPDATE reviews SET assigned_to = ?, assigned_by = ?, assigned_at = UTC_TIMESTAMP(), assignment_due_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? HOUR), assignment_note = ? WHERE id = ? AND user_id = ?',
            [$assigneeId, $actorId, $hours, $note !== '' ? $note : null, $reviewId, $ownerId]
        );
        if ($assigneeId !== $actorId && class_exists('Notification')) {
            $who = trim((string) ($review['reviewer_name'] ?? '')) ?: 'a guest';
            Notification::notify($assigneeId, 'review_assigned', 'A review was assigned to you (' . (int) round((float) $review['rating']) . '★)', $who . ($note !== '' ? ': ' . mb_substr($note, 0, 120) : ''), '/reputation/review/' . $reviewId);
        }
        return ['success' => true, 'assignment' => ['assigned_to' => $assigneeId, 'due_hours' => $hours, 'note' => $note]];
    }

    /** Assigned reviews still without a sent reply whose deadline has passed. */
    public static function overdue(int $ownerId, ?Database $db = null): array
    {
        $db = $db ?: Database::getInstance();
        if (!self::ready($db)) {
            return [];
        }
        return $db->query(
            'SELECT id, reviewer_name, rating, assigned_to, assignment_due_at FROM reviews WHERE user_id = ? AND assigned_to IS NOT NULL AND reply_sent_at IS NULL AND assignment_due_at < UTC_TIMESTAMP() ORDER BY assignment_due_at ASC LIMIT 100',
            [$ownerId]
        );
    }
}

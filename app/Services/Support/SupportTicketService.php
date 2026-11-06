<?php

/**
 * Tourfecto - support tickets (customer <-> support team).
 *
 * Status flow: open (waiting for staff) -> pending (staff replied, waiting for the customer)
 * -> resolved / closed. A customer reply on a pending/resolved/closed ticket reopens it.
 * Internal notes ('note' messages) are never returned to the customer.
 */
class SupportTicketService
{
    public const CATEGORIES = [
        'question' => 'سؤال / استفسار',
        'problem' => 'مشكلة تقنية',
        'billing' => 'الفلوس والاشتراك',
        'complaint' => 'شكوى',
        'feature' => 'اقتراح / ميزة جديدة',
        'other' => 'حاجة تانية',
    ];
    public const PRIORITIES = ['low' => 'منخفضة', 'normal' => 'عادية', 'high' => 'عالية', 'urgent' => 'عاجلة'];
    public const STATUSES = ['open' => 'مفتوحة', 'pending' => 'في انتظار العميل', 'resolved' => 'تم الحل', 'closed' => 'مقفولة'];
    public const MAX_OPEN_PER_USER = 15;

    private static bool $ready = false;

    private static function db(): Database
    {
        return Database::getInstance();
    }

    public static function ensureSchema(): void
    {
        if (self::$ready) {
            return;
        }
        $db = self::db();
        $db->exec("CREATE TABLE IF NOT EXISTS support_tickets (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, subject VARCHAR(200) NOT NULL,
            category VARCHAR(30) NOT NULL DEFAULT 'question', priority VARCHAR(10) NOT NULL DEFAULT 'normal', status VARCHAR(20) NOT NULL DEFAULT 'open',
            assigned_to BIGINT UNSIGNED NULL, last_reply_by VARCHAR(10) NOT NULL DEFAULT 'customer', rating TINYINT NULL, rating_comment VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, closed_at DATETIME NULL,
            KEY idx_st_user (user_id, last_activity_at), KEY idx_st_status (status, last_activity_at), KEY idx_st_assigned (assigned_to, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS support_ticket_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ticket_id BIGINT UNSIGNED NOT NULL, author_id BIGINT UNSIGNED NOT NULL,
            author_type VARCHAR(10) NOT NULL DEFAULT 'customer', body TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_stm_ticket (ticket_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::$ready = true;
    }

    /** @throws InvalidArgumentException */
    private static function cleanText(string $v, int $max, string $what, int $min = 1): string
    {
        $v = trim(str_replace("\r\n", "\n", $v));
        $len = mb_strlen($v);
        if ($len < $min) {
            throw new InvalidArgumentException("اكتب $what");
        }
        if ($len > $max) {
            throw new InvalidArgumentException("$what أطول من المسموح ($max حرف)");
        }
        return $v;
    }

    // ------------------------------------------------------------------ customer side

    /** @return int ticket id @throws InvalidArgumentException */
    public static function create(int $userId, string $subject, string $body, string $category = 'question', string $priority = 'normal'): int
    {
        self::ensureSchema();
        $subject = self::cleanText($subject, 200, 'عنوان المشكلة', 3);
        $body = self::cleanText($body, 5000, 'تفاصيل المشكلة', 5);
        if (!isset(self::CATEGORIES[$category])) {
            $category = 'other';
        }
        // العميل بيختار بس منخفضة/عادية/عالية؛ «عاجلة» للفريق
        if (!in_array($priority, ['low', 'normal', 'high'], true)) {
            $priority = 'normal';
        }
        $open = self::db()->query("SELECT COUNT(*) c FROM support_tickets WHERE user_id = ? AND status IN ('open','pending')", [$userId]);
        if ((int) ($open[0]['c'] ?? 0) >= self::MAX_OPEN_PER_USER) {
            throw new InvalidArgumentException('عندك تذاكر مفتوحة كتير. استنى الرد على القديمة أو اقفلها الأول.');
        }
        $id = (int) self::db()->query(
            'INSERT INTO support_tickets (user_id, subject, category, priority, status, last_reply_by) VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $subject, $category, $priority, 'open', 'customer']
        );
        self::db()->query('INSERT INTO support_ticket_messages (ticket_id, author_id, author_type, body) VALUES (?, ?, ?, ?)', [$id, $userId, 'customer', $body]);
        return $id;
    }

    public static function listForUser(int $userId, string $status = ''): array
    {
        self::ensureSchema();
        $sql = 'SELECT id, subject, category, priority, status, last_reply_by, created_at, last_activity_at FROM support_tickets WHERE user_id = ?';
        $bind = [$userId];
        if (isset(self::STATUSES[$status])) {
            $sql .= ' AND status = ?';
            $bind[] = $status;
        }
        return self::db()->query($sql . ' ORDER BY last_activity_at DESC, id DESC LIMIT 100', $bind) ?: [];
    }

    /** ticket + messages (without internal notes), or null if it isn't this user's */
    public static function getForUser(int $userId, int $id): ?array
    {
        self::ensureSchema();
        $t = self::db()->query('SELECT id, subject, category, priority, status, last_reply_by, rating, rating_comment, created_at, last_activity_at, closed_at FROM support_tickets WHERE id = ? AND user_id = ? LIMIT 1', [$id, $userId]);
        if (!$t) {
            return null;
        }
        $m = self::db()->query("SELECT id, author_type, body, created_at FROM support_ticket_messages WHERE ticket_id = ? AND author_type IN ('customer','staff') ORDER BY id ASC", [$id]);
        return ['ticket' => $t[0], 'messages' => $m ?: []];
    }

    /** @throws InvalidArgumentException */
    public static function customerReply(int $userId, int $id, string $body): void
    {
        self::ensureSchema();
        $body = self::cleanText($body, 5000, 'الرد');
        $t = self::db()->query('SELECT id, status FROM support_tickets WHERE id = ? AND user_id = ? LIMIT 1', [$id, $userId]);
        if (!$t) {
            throw new InvalidArgumentException('التذكرة مش موجودة');
        }
        self::db()->query('INSERT INTO support_ticket_messages (ticket_id, author_id, author_type, body) VALUES (?, ?, ?, ?)', [$id, $userId, 'customer', $body]);
        self::db()->query("UPDATE support_tickets SET status = 'open', last_reply_by = 'customer', closed_at = NULL, last_activity_at = NOW() WHERE id = ?", [$id]);
    }

    public static function customerSetClosed(int $userId, int $id, bool $closed): bool
    {
        self::ensureSchema();
        $n = self::db()->query(
            $closed
                ? "UPDATE support_tickets SET status = 'closed', closed_at = NOW(), last_activity_at = NOW() WHERE id = ? AND user_id = ? AND status <> 'closed'"
                : "UPDATE support_tickets SET status = 'open', last_reply_by = 'customer', closed_at = NULL, last_activity_at = NOW() WHERE id = ? AND user_id = ? AND status IN ('closed','resolved')",
            [$id, $userId]
        );
        return (int) $n > 0;
    }

    /** @throws InvalidArgumentException */
    public static function rate(int $userId, int $id, int $rating, string $comment = ''): void
    {
        self::ensureSchema();
        if ($rating < 1 || $rating > 5) {
            throw new InvalidArgumentException('التقييم من 1 لـ 5');
        }
        $comment = mb_substr(trim($comment), 0, 500);
        $n = self::db()->query(
            "UPDATE support_tickets SET rating = ?, rating_comment = ? WHERE id = ? AND user_id = ? AND status IN ('resolved','closed')",
            [$rating, $comment === '' ? null : $comment, $id, $userId]
        );
        if ((int) $n < 1) {
            throw new InvalidArgumentException('تقدر تقيّم بعد ما التذكرة تتحل أو تتقفل');
        }
    }

    // ------------------------------------------------------------------ staff side

    /** @return array{items:array,total:int,page:int,per:int} */
    public static function adminList(array $f, int $page = 1, int $per = 30): array
    {
        self::ensureSchema();
        $where = ['1=1'];
        $bind = [];
        if (isset(self::STATUSES[$f['status'] ?? ''])) {
            $where[] = 't.status = ?';
            $bind[] = $f['status'];
        } elseif (($f['status'] ?? '') === 'active') {
            $where[] = "t.status IN ('open','pending')";
        }
        if (isset(self::PRIORITIES[$f['priority'] ?? ''])) {
            $where[] = 't.priority = ?';
            $bind[] = $f['priority'];
        }
        if (isset(self::CATEGORIES[$f['category'] ?? ''])) {
            $where[] = 't.category = ?';
            $bind[] = $f['category'];
        }
        $assigned = (string) ($f['assigned'] ?? '');
        if ($assigned === 'none') {
            $where[] = 't.assigned_to IS NULL';
        } elseif (ctype_digit($assigned) && (int) $assigned > 0) {
            $where[] = 't.assigned_to = ?';
            $bind[] = (int) $assigned;
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 80)) . '%';
            $where[] = '(t.subject LIKE ? OR u.email LIKE ?' . (ctype_digit($q) ? ' OR t.id = ?' : '') . ')';
            array_push($bind, $like, $like);
            if (ctype_digit($q)) {
                $bind[] = (int) $q;
            }
        }
        $w = implode(' AND ', $where);
        $page = max(1, $page);
        $per = max(5, min(100, $per));
        $total = (int) (self::db()->query("SELECT COUNT(*) c FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id WHERE $w", $bind)[0]['c'] ?? 0);
        $items = self::db()->query(
            "SELECT t.id, t.user_id, t.subject, t.category, t.priority, t.status, t.assigned_to, t.last_reply_by, t.rating, t.created_at, t.last_activity_at,
                    u.email AS customer_email, COALESCE(NULLIF(u.display_name,''), NULLIF(u.company_name,''), u.email) AS customer_name,
                    a.email AS assignee_email
               FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id LEFT JOIN users a ON a.id = t.assigned_to
              WHERE $w
              ORDER BY (t.status IN ('open','pending')) DESC, FIELD(t.priority,'urgent','high','normal','low'), t.last_activity_at ASC, t.id ASC
              LIMIT " . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per),
            $bind
        ) ?: [];
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per' => $per];
    }

    public static function stats(): array
    {
        self::ensureSchema();
        $r = self::db()->query(
            "SELECT SUM(status='open') open_n, SUM(status='pending') pending_n, SUM(status IN ('open','pending') AND assigned_to IS NULL) unassigned,
                    SUM(status IN ('open','pending') AND priority IN ('high','urgent')) urgent_n,
                    SUM(status IN ('resolved','closed') AND closed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) closed_7d,
                    ROUND(AVG(rating), 1) avg_rating, COUNT(rating) rated
               FROM support_tickets"
        )[0] ?? [];
        return [
            'open' => (int) ($r['open_n'] ?? 0), 'pending' => (int) ($r['pending_n'] ?? 0), 'unassigned' => (int) ($r['unassigned'] ?? 0),
            'high_priority' => (int) ($r['urgent_n'] ?? 0), 'closed_7d' => (int) ($r['closed_7d'] ?? 0),
            'avg_rating' => $r['avg_rating'] !== null ? (float) $r['avg_rating'] : null, 'rated' => (int) ($r['rated'] ?? 0),
        ];
    }

    public static function adminGet(int $id): ?array
    {
        self::ensureSchema();
        $t = self::db()->query(
            "SELECT t.*, u.email AS customer_email, COALESCE(NULLIF(u.display_name,''), NULLIF(u.company_name,''), u.email) AS customer_name, a.email AS assignee_email
               FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id LEFT JOIN users a ON a.id = t.assigned_to WHERE t.id = ? LIMIT 1",
            [$id]
        );
        if (!$t) {
            return null;
        }
        $m = self::db()->query(
            "SELECT m.id, m.author_id, m.author_type, m.body, m.created_at, COALESCE(NULLIF(u.display_name,''), u.email) AS author_name
               FROM support_ticket_messages m LEFT JOIN users u ON u.id = m.author_id WHERE m.ticket_id = ? ORDER BY m.id ASC",
            [$id]
        );
        return ['ticket' => $t[0], 'messages' => $m ?: []];
    }

    /** reply visible to the customer, or an internal note ($internal). @throws InvalidArgumentException */
    public static function staffReply(int $staffId, int $id, string $body, bool $internal = false, ?string $setStatus = null): void
    {
        self::ensureSchema();
        $body = self::cleanText($body, 5000, $internal ? 'الملاحظة' : 'الرد');
        $t = self::db()->query('SELECT id, assigned_to FROM support_tickets WHERE id = ? LIMIT 1', [$id]);
        if (!$t) {
            throw new InvalidArgumentException('التذكرة مش موجودة');
        }
        self::db()->query('INSERT INTO support_ticket_messages (ticket_id, author_id, author_type, body) VALUES (?, ?, ?, ?)', [$id, $staffId, $internal ? 'note' : 'staff', $body]);
        if ($internal) {
            return;
        }
        // أول من يرد بياخد التذكرة لو مفيش حد عليها
        $status = in_array($setStatus, ['pending', 'resolved', 'closed'], true) ? $setStatus : 'pending';
        self::db()->query(
            "UPDATE support_tickets SET status = ?, last_reply_by = 'staff', last_activity_at = NOW(),
                    assigned_to = COALESCE(assigned_to, ?), closed_at = " . ($status === 'closed' || $status === 'resolved' ? 'NOW()' : 'NULL') . ' WHERE id = ?',
            [$status, $staffId, $id]
        );
    }

    /** @throws InvalidArgumentException */
    public static function setStatus(int $id, string $status): void
    {
        self::ensureSchema();
        if (!isset(self::STATUSES[$status])) {
            throw new InvalidArgumentException('الحالة غير معروفة');
        }
        $closing = in_array($status, ['resolved', 'closed'], true);
        self::db()->query('UPDATE support_tickets SET status = ?, closed_at = ' . ($closing ? 'COALESCE(closed_at, NOW())' : 'NULL') . ', last_activity_at = NOW() WHERE id = ?', [$status, $id]);
    }

    /** @throws InvalidArgumentException */
    public static function setPriority(int $id, string $priority): void
    {
        self::ensureSchema();
        if (!isset(self::PRIORITIES[$priority])) {
            throw new InvalidArgumentException('الأولوية غير معروفة');
        }
        self::db()->query('UPDATE support_tickets SET priority = ? WHERE id = ?', [$priority, $id]);
    }

    /** $staffId null = un-assign. Only admin accounts can be assigned. @throws InvalidArgumentException */
    public static function assign(int $id, ?int $staffId): void
    {
        self::ensureSchema();
        if ($staffId !== null) {
            $u = self::db()->query("SELECT id FROM users WHERE id = ? AND role IN ('admin','super_admin') AND deleted_at IS NULL LIMIT 1", [$staffId]);
            if (!$u) {
                throw new InvalidArgumentException('الموظف مش موجود في فريق الأدمن');
            }
        }
        self::db()->query('UPDATE support_tickets SET assigned_to = ? WHERE id = ?', [$staffId, $id]);
    }

    /** staff who can take tickets: admin accounts with a ticket permission */
    public static function assignableStaff(): array
    {
        $out = [];
        foreach (self::db()->query("SELECT id, email, role FROM users WHERE role IN ('admin','super_admin') AND deleted_at IS NULL ORDER BY email") ?: [] as $u) {
            if (AdminAccess::can($u, 'tickets.manage')) {
                $out[] = ['id' => (int) $u['id'], 'email' => $u['email']];
            }
        }
        return $out;
    }
}

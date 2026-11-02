<?php
/**
 * روابط مشاركة تقرير الظهور مع العميل (قراءة فقط، منتهية الصلاحية، قابلة للإلغاء).
 * التوكن عشوائي 256 بت، بيتخزّن SHA-256 بتاعه بس، والخام بيرجع مرة واحدة وقت الإنشاء.
 * كل الدوال مقيّدة بـuser_id+website_id، وبتفشل بأمان لو الجدول لسه ما اتعملش له migration (ready() = false).
 */
final class SeoReportShareService
{
    public const TTL_DAYS = 30;
    public const MAX_ACTIVE = 5;

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function ready(): bool
    {
        try {
            return (bool) $this->db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'seo_report_shares'");
        } catch (Throwable $e) {
            return false;
        }
    }

    /** @return array{token:string,id:int,expires_at:string,lang:string} */
    public function create(int $uid, int $wid, string $lang, ?string $now = null): array
    {
        if (!$this->ready()) {
            throw new RuntimeException('not_ready');
        }
        $this->assertOwner($uid, $wid);
        $lang = $lang === 'en' ? 'en' : 'ar';
        $now = $now ?? gmdate('Y-m-d H:i:s');
        $active = $this->db->query('SELECT COUNT(*) AS n FROM seo_report_shares WHERE user_id = ? AND website_id = ? AND revoked_at IS NULL AND expires_at > ?', [$uid, $wid, $now]);
        if ((int) ($active[0]['n'] ?? 0) >= self::MAX_ACTIVE) {
            throw new InvalidArgumentException('وصلت للحد الأقصى من الروابط الفعّالة (5). الغِ رابطًا قديمًا الأول.');
        }
        $token = bin2hex(random_bytes(32));
        $expires = gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') + self::TTL_DAYS * 86400);
        $this->db->exec('INSERT INTO seo_report_shares (user_id, website_id, token_hash, lang, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)', [$uid, $wid, self::hash($token), $lang, $now, $expires]);
        $id = (int) $this->db->query('SELECT id FROM seo_report_shares WHERE token_hash = ?', [self::hash($token)])[0]['id'];
        return ['token' => $token, 'id' => $id, 'expires_at' => $expires, 'lang' => $lang];
    }

    /** @return array<int,array<string,mixed>> بدون التوكن (مش بيتخزّن) */
    public function list(int $uid, int $wid, ?string $now = null): array
    {
        if (!$this->ready()) {
            return [];
        }
        $this->assertOwner($uid, $wid);
        $now = $now ?? gmdate('Y-m-d H:i:s');
        $rows = $this->db->query('SELECT id, lang, created_at, expires_at, revoked_at, last_viewed_at, view_count FROM seo_report_shares WHERE user_id = ? AND website_id = ? ORDER BY id DESC LIMIT 20', [$uid, $wid]);
        foreach ($rows as &$r) {
            $r['active'] = self::isActive($r, $now);
        }
        return $rows;
    }

    public function revoke(int $uid, int $id): bool
    {
        if (!$this->ready()) {
            return false;
        }
        $this->db->exec('UPDATE seo_report_shares SET revoked_at = UTC_TIMESTAMP() WHERE id = ? AND user_id = ? AND revoked_at IS NULL', [$id, $uid]);
        $r = $this->db->query('SELECT revoked_at FROM seo_report_shares WHERE id = ? AND user_id = ?', [$id, $uid]);
        return !empty($r[0]['revoked_at']);
    }

    /** @return ?array{user_id:int,website_id:int,lang:string} null لو التوكن غلط أو منتهي أو ملغي */
    public function resolve(string $token, ?string $now = null): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token) || !$this->ready()) {
            return null;
        }
        $now = $now ?? gmdate('Y-m-d H:i:s');
        $r = $this->db->query('SELECT id, user_id, website_id, lang, expires_at, revoked_at FROM seo_report_shares WHERE token_hash = ?', [self::hash($token)]);
        if (!$r || !self::isActive($r[0], $now)) {
            return null;
        }
        $this->db->exec('UPDATE seo_report_shares SET last_viewed_at = UTC_TIMESTAMP(), view_count = view_count + 1 WHERE id = ?', [$r[0]['id']]);
        return ['user_id' => (int) $r[0]['user_id'], 'website_id' => (int) $r[0]['website_id'], 'lang' => (string) $r[0]['lang']];
    }

    public static function isActive(array $row, string $now): bool
    {
        return empty($row['revoked_at']) && strcmp((string) $row['expires_at'], $now) > 0;
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function assertOwner(int $uid, int $wid): void
    {
        if (!$this->db->query('SELECT 1 FROM websites WHERE id = ? AND user_id = ?', [$wid, $uid])) {
            throw new InvalidArgumentException('الموقع مش موجود');
        }
    }
}

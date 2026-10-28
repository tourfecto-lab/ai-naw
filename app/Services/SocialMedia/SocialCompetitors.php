<?php

/**
 * Tourfecto - مراقبة المنافسين على إنستجرام (Business Discovery API)
 *
 * العميل بيضيف لحد 5 حسابات منافسين (بيزنس/كريتور). يوميًا بنجيب: المتابعين، معدل النشر في الأسبوع،
 * متوسط التفاعل لآخر 12 منشور، أنواع المحتوى (ريلز/صور/كاروسيل)، أكتر الهاشتاجات، وأنجح 3 منشورات -
 * ونقارنهم بحساب العميل نفسه. كل ده بصلاحية instagram_basic الموجودة أصلًا (مفيش App Review إضافي).
 * analyze() نقية (بتتختبر).
 * @version 1.0.0
 */
require_once __DIR__ . '/MetaSocialAPI.php';

final class SocialCompetitors
{
    public const MAX_PER_USER = 50; // سقف أمان؛ الحد الفعلي من باقة السوشيال (SocialPackages)

    /** @var callable|null حقن عميل Meta في الاختبارات */
    public static $apiFactory = null;

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function ready(): bool
    {
        try {
            return (bool) $this->db->query("SHOW TABLES LIKE 'social_competitors'");
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * تحليل آخر منشورات حساب.
     * @param array $media [['caption','likes','comments','timestamp','type','permalink']]
     */
    public static function analyze(array $media): array
    {
        $n = count($media);
        if ($n === 0) {
            return ['avg_engagement' => null, 'posts_per_week' => null, 'mix' => [], 'hashtags' => [], 'top' => []];
        }
        $eng = array_map(static fn ($m) => (int) $m['likes'] + (int) $m['comments'], $media);
        $times = array_values(array_filter(array_map(static fn ($m) => $m['timestamp'] ? strtotime((string) $m['timestamp']) : false, $media)));
        $ppw = null;
        if (count($times) >= 2) {
            $spanDays = max(1, (max($times) - min($times)) / 86400);
            $ppw = round((count($times) - 1) / $spanDays * 7, 1);
        }
        $mix = [];
        foreach ($media as $m) {
            $k = ['VIDEO' => 'reels', 'REELS' => 'reels', 'CAROUSEL_ALBUM' => 'carousel', 'IMAGE' => 'photo'][strtoupper((string) $m['type'])] ?? 'other';
            $mix[$k] = ($mix[$k] ?? 0) + 1;
        }
        arsort($mix);
        $tags = [];
        foreach ($media as $m) {
            preg_match_all('/#([\p{L}\p{N}_]{2,40})/u', (string) $m['caption'], $mm);
            foreach ($mm[1] as $t) {
                $k = mb_strtolower($t);
                $tags[$k] = ($tags[$k] ?? 0) + 1;
            }
        }
        arsort($tags);
        $idx = array_keys($eng);
        usort($idx, static fn ($a, $b) => $eng[$b] <=> $eng[$a]);
        $top = [];
        foreach (array_slice($idx, 0, 3) as $i) {
            $m = $media[$i];
            $top[] = ['caption' => mb_substr((string) $m['caption'], 0, 140), 'engagement' => $eng[$i], 'type' => (string) $m['type'], 'permalink' => $m['permalink'], 'timestamp' => $m['timestamp']];
        }
        return ['avg_engagement' => round(array_sum($eng) / $n, 1), 'posts_per_week' => $ppw, 'mix' => $mix, 'hashtags' => array_slice(array_keys($tags), 0, 8), 'top' => $top];
    }

    /** حساب إنستجرام بتاع العميل اللي هنسأل بيه (أي حساب متصل) */
    private function igConnection(int $userId): ?array
    {
        return $this->db->query(
            "SELECT id, access_token, external_location_id, external_location_name FROM platform_connections
              WHERE platform = 'instagram' AND status = 'connected' AND (user_id = ? OR website_id IN (SELECT id FROM websites WHERE user_id = ?))
              ORDER BY id DESC LIMIT 1",
            [$userId, $userId]
        )[0] ?? null;
    }

    private function fetch(array $conn, string $username): array
    {
        $token = (new Encryption())->decrypt((string) $conn['access_token']);
        $api = self::$apiFactory ? (self::$apiFactory)($token) : new MetaSocialAPI($token);
        return $api->businessDiscovery((string) $conn['external_location_id'], $token, $username, 12);
    }

    public function add(int $userId, string $username): array
    {
        if (!$this->ready()) {
            throw new InvalidArgumentException('شغّل migration السوشيال (2026_10_21_000001_social_growth.sql) الأول');
        }
        $username = strtolower(ltrim(trim($username), '@'));
        if (preg_match('#instagram\.com/([A-Za-z0-9._]+)#i', $username, $m)) {
            $username = strtolower($m[1]);
        }
        if (!preg_match('/^[a-z0-9._]{1,30}$/', $username)) {
            throw new InvalidArgumentException('اكتب اسم حساب إنستجرام صحيح (مثلًا redsea_tours)');
        }
        $count = (int) ($this->db->query('SELECT COUNT(*) AS n FROM social_competitors WHERE user_id = ?', [$userId])[0]['n'] ?? 0);
        if ($count >= self::MAX_PER_USER) {
            throw new InvalidArgumentException('الحد الأقصى ' . self::MAX_PER_USER . ' منافسين - شيل واحد الأول');
        }
        $conn = $this->igConnection($userId);
        if (!$conn) {
            throw new InvalidArgumentException('اربط حساب إنستجرام بيزنس الأول (مراقبة المنافسين بتشتغل من خلاله)');
        }
        if (strtolower((string) $conn['external_location_name']) === $username) {
            throw new InvalidArgumentException('ده حسابك انت 🙂 ضيف حساب منافس');
        }
        $res = $this->fetch($conn, $username);
        if (empty($res['success'])) {
            throw new InvalidArgumentException('مقدرناش نجيب بيانات @' . $username . ': ' . (string) ((($res['friendly'] ?? '') ?: ($res['error'] ?? '')) ?: 'غير معروف') . ' (لازم يكون حساب بيزنس أو كريتور عام)');
        }
        $this->db->query('INSERT IGNORE INTO social_competitors (user_id, platform, username) VALUES (?, \'instagram\', ?)', [$userId, $username]);
        $id = (int) ($this->db->query('SELECT id FROM social_competitors WHERE user_id = ? AND platform = \'instagram\' AND username = ?', [$userId, $username])[0]['id'] ?? 0);
        $this->store($id, $res);
        return $this->list($userId);
    }

    public function remove(int $userId, int $id): void
    {
        $this->db->query('DELETE FROM social_competitors WHERE id = ? AND user_id = ?', [$id, $userId]);
        $this->db->query('DELETE FROM social_competitor_snapshots WHERE competitor_id = ? AND NOT EXISTS (SELECT 1 FROM social_competitors WHERE id = ?)', [$id, $id]);
    }

    private function store(int $id, array $res): void
    {
        if (empty($res['success'])) {
            $this->db->query('UPDATE social_competitors SET last_error = ?, last_checked_at = UTC_TIMESTAMP() WHERE id = ?', [mb_substr((string) ((($res['friendly'] ?? '') ?: ($res['error'] ?? '')) ?: 'error'), 0, 290), $id]);
            return;
        }
        $a = self::analyze($res['media']);
        $p = $res['profile'];
        $this->db->query(
            'UPDATE social_competitors SET display_name = ?, followers = ?, media_count = ?, avg_engagement = ?, posts_per_week = ?, top_posts_json = ?, last_checked_at = UTC_TIMESTAMP(), last_error = NULL WHERE id = ?',
            [mb_substr($p['name'] ?: $p['username'], 0, 190), $p['followers'], $p['media_count'], $a['avg_engagement'], $a['posts_per_week'], json_encode(['top' => $a['top'], 'mix' => $a['mix'], 'hashtags' => $a['hashtags']], JSON_UNESCAPED_UNICODE), $id]
        );
        $this->db->query(
            'INSERT INTO social_competitor_snapshots (competitor_id, snap_date, followers, avg_engagement) VALUES (?, UTC_DATE(), ?, ?) ON DUPLICATE KEY UPDATE followers = VALUES(followers), avg_engagement = VALUES(avg_engagement)',
            [$id, $p['followers'], $a['avg_engagement']]
        );
    }

    /** تحديث المنافسين اللي عدّى على آخر فحص ليهم 20 ساعة */
    public function refreshDue(int $limit = 60): int
    {
        if (!$this->ready()) {
            return 0;
        }
        $rows = $this->db->query('SELECT id, user_id, username FROM social_competitors WHERE last_checked_at IS NULL OR last_checked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 20 HOUR) ORDER BY last_checked_at LIMIT ' . max(1, min(500, $limit))) ?: [];
        $n = 0;
        foreach ($rows as $r) {
            $conn = $this->igConnection((int) $r['user_id']);
            if (!$conn) {
                continue;
            }
            try {
                $this->store((int) $r['id'], $this->fetch($conn, (string) $r['username']));
                $n++;
            } catch (Throwable $e) {
            }
        }
        return $n;
    }

    public function refreshUser(int $userId): int
    {
        $conn = $this->igConnection($userId);
        if (!$conn || !$this->ready()) {
            return 0;
        }
        $n = 0;
        foreach ($this->db->query('SELECT id, username FROM social_competitors WHERE user_id = ?', [$userId]) ?: [] as $r) {
            $this->store((int) $r['id'], $this->fetch($conn, (string) $r['username']));
            $n++;
        }
        return $n;
    }

    /** المنافسين + حساب العميل للمقارنة */
    public function list(int $userId): array
    {
        if (!$this->ready()) {
            return ['installed' => false, 'competitors' => [], 'me' => null];
        }
        $rows = $this->db->query('SELECT * FROM social_competitors WHERE user_id = ? ORDER BY followers DESC, id', [$userId]) ?: [];
        foreach ($rows as &$r) {
            $extra = json_decode((string) $r['top_posts_json'], true) ?: [];
            $r['top'] = $extra['top'] ?? [];
            $r['mix'] = $extra['mix'] ?? [];
            $r['hashtags'] = $extra['hashtags'] ?? [];
            unset($r['top_posts_json']);
            $hist = $this->db->query('SELECT followers FROM social_competitor_snapshots WHERE competitor_id = ? AND snap_date >= DATE_SUB(UTC_DATE(), INTERVAL 30 DAY) ORDER BY snap_date LIMIT 1', [(int) $r['id']])[0]['followers'] ?? null;
            $r['followers_change_30d'] = ($hist !== null && $r['followers'] !== null) ? (int) $r['followers'] - (int) $hist : null;
        }
        unset($r);
        $me = null;
        $conn = $this->igConnection($userId);
        if ($conn) {
            $snap = $this->db->query('SELECT followers FROM social_account_snapshots WHERE platform_connection_id = ? ORDER BY snap_date DESC LIMIT 1', [(int) $conn['id']])[0]['followers'] ?? null;
            $own = $this->db->query(
                "SELECT AVG(pm.likes + pm.comments) AS avg_eng, COUNT(*) AS n, MIN(t.published_at) AS first_at, MAX(t.published_at) AS last_at
                   FROM social_post_metrics pm JOIN social_post_targets t ON t.id = pm.target_id
                  WHERE t.platform_connection_id = ? AND pm.state = 'ok' AND t.published_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)",
                [(int) $conn['id']]
            )[0] ?? [];
            $ppw = null;
            if ((int) ($own['n'] ?? 0) >= 2 && $own['first_at'] && $own['last_at']) {
                $span = max(1, (strtotime($own['last_at']) - strtotime($own['first_at'])) / 86400);
                $ppw = round(((int) $own['n'] - 1) / $span * 7, 1);
            }
            $me = ['username' => $conn['external_location_name'], 'followers' => $snap !== null ? (int) $snap : null, 'avg_engagement' => isset($own['avg_eng']) && $own['avg_eng'] !== null ? round((float) $own['avg_eng'], 1) : null, 'posts_per_week' => $ppw];
        }
        return ['installed' => true, 'competitors' => $rows, 'me' => $me, 'insights' => self::compare($me, $rows)];
    }

    /** توصيات من المقارنة (نقية) - كل عنصر [ar, en] */
    public static function compare(?array $me, array $competitors): array
    {
        $ok = array_values(array_filter($competitors, static fn ($c) => $c['avg_engagement'] !== null));
        if (!$ok) {
            return [];
        }
        $out = [];
        $avgPpw = array_sum(array_map(static fn ($c) => (float) $c['posts_per_week'], $ok)) / count($ok);
        if ($me && $me['posts_per_week'] !== null && $avgPpw > 0 && $me['posts_per_week'] < $avgPpw * 0.7) {
            $out[] = ['منافسينك بينشروا في المتوسط ' . round($avgPpw, 1) . ' منشور في الأسبوع وانت ' . $me['posts_per_week'] . ' - زوّد معدل النشر (الخطة الشهرية بتسهّلها).', 'Competitors post ' . round($avgPpw, 1) . ' times a week on average vs your ' . $me['posts_per_week'] . ' — increase your posting rhythm (the monthly plan makes it easy).'];
        }
        $reels = 0;
        $total = 0;
        foreach ($ok as $c) {
            $reels += (int) ($c['mix']['reels'] ?? 0);
            $total += array_sum((array) $c['mix']);
        }
        if ($total > 0 && $reels / $total >= 0.4) {
            $out[] = [round($reels / $total * 100) . '% من منشورات منافسينك ريلز - الفيديو القصير هو اللي شغال في سوقك.', round($reels / $total * 100) . '% of your competitors\' posts are Reels — short video is what works in your market.'];
        }
        usort($ok, static fn ($a, $b) => (float) $b['avg_engagement'] <=> (float) $a['avg_engagement']);
        $lead = $ok[0];
        if ($me && $me['avg_engagement'] !== null && (float) $lead['avg_engagement'] > (float) $me['avg_engagement'] * 1.3) {
            $out[] = ['@' . $lead['username'] . ' بيجيب متوسط ' . (float) $lead['avg_engagement'] . ' تفاعل للمنشور مقابل ' . $me['avg_engagement'] . ' عندك - بص على أنجح منشوراته تحت واستلهم الفكرة (مش النسخ).', '@' . $lead['username'] . ' averages ' . (float) $lead['avg_engagement'] . ' engagements per post vs your ' . $me['avg_engagement'] . ' — study their top posts below for ideas (not copying).'];
        }
        $tags = [];
        foreach ($ok as $c) {
            foreach ((array) $c['hashtags'] as $t) {
                $tags[$t] = ($tags[$t] ?? 0) + 1;
            }
        }
        arsort($tags);
        if ($tags) {
            $list = '#' . implode(' #', array_slice(array_keys($tags), 0, 6));
            $out[] = ['أكتر هاشتاجات بيستخدمها منافسينك: ' . $list, 'Hashtags your competitors use most: ' . $list];
        }
        return $out;
    }
}

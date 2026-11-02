<?php

/**
 * تنبيه العميل لو موصّل موقعه وقف عن سحب قواعد المنصة: التعديلات المعتمدة بتفضل «محفوظة» ومبتظهرش على الموقع،
 * والعميل مفكر إن كل حاجة شغالة (حصل فعلًا: 38 ساعة من غير سحب). التنبيه مرة كل 24 ساعة لكل موقع، وبس لو فيه قواعد سيو نشطة.
 */
final class EdgeConnectionWatch
{
    public const STALE_HOURS = 24;
    public const REPEAT_HOURS = 24;

    public function __construct(private ?Database $db = null)
    {
        $this->db ??= Database::getInstance();
    }

    /** @return array{checked:int,alerted:int} */
    public function run(?int $now = null): array
    {
        $now ??= time();
        $out = ['checked' => 0, 'alerted' => 0];
        $pdo = $this->db->getConnection();
        $rows = $pdo->query("SELECT e.website_id, e.last_seen_at, w.user_id, w.main_url,
                (SELECT COUNT(*) FROM seo_expert_tasks t WHERE t.website_id = e.website_id AND t.kind = 'metadata' AND t.is_active = 1 AND t.status IN ('active','verified','needs_client')) AS rules
            FROM edge_connections e JOIN websites w ON w.id = e.website_id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if ((int) $r['rules'] < 1) {
                continue;
            }
            $out['checked']++;
            $fresh = EdgeFixController::freshness((string) $r['last_seen_at'], $now);
            if ($fresh['minutes'] === null || $fresh['minutes'] < self::STALE_HOURS * 60) {
                continue;
            }
            if ($this->recentlyAlerted((int) $r['user_id'], (int) $r['website_id'], $now)) {
                continue;
            }
            $hours = (int) round($fresh['minutes'] / 60);
            $host = (string) parse_url((string) $r['main_url'], PHP_URL_HOST);
            $pdo->prepare('INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)')->execute([
                (int) $r['user_id'],
                'edge_stale',
                'موقعك وقف عن استلام تعديلات السيو',
                'موصّل Tourfecto على ' . ($host !== '' ? $host : 'موقعك') . ' ما سحبش قواعد المنصة من حوالي ' . $hours . ' ساعة، فالتعديلات المعتمدة (عناوين وأوصاف وروابط) مش بتظهر على موقعك. أعد تركيب الربط من إعدادات خبير السيو.',
                '/seo-expert?page=settings&website_id=' . (int) $r['website_id'] . '#' . (int) $r['website_id'],
            ]);
            $out['alerted']++;
        }
        return $out;
    }

    private function recentlyAlerted(int $userId, int $websiteId, int $now): bool
    {
        $st = $this->db->getConnection()->prepare("SELECT created_at FROM notifications WHERE user_id = ? AND type = 'edge_stale' AND link LIKE ? ORDER BY id DESC LIMIT 1");
        $st->execute([$userId, '/seo-expert?page=settings&website_id=' . $websiteId . '#%']);
        $t = $st->fetchColumn();
        return $t !== false && strtotime((string) $t) > $now - self::REPEAT_HOURS * 3600;
    }
}

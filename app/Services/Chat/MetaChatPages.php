<?php
/**
 * Tourfecto - رسائل ماسنجر وإنستجرام من غير توكنات
 *
 * بعد ما العميل يسجّل دخول بفيسبوك (نفس ربط Meta بتاع السوشيال/الإعلانات) صفحاته
 * بتتحفظ بتوكناتها. هنا العميل بيختار الصفحة اللي الشات بوت يرد على رسايلها:
 * بنشترك بالصفحة في تطبيق المنصة (الـ webhook العام بيوجّه الرسايل بمعرّف الصفحة)
 * ونسجّل اتصال messenger. رسايل إنستجرام بتيجي من نفس اشتراك الصفحة.
 */
final class MetaChatPages
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function overview(int $uid, int $wid): array
    {
        $pages = $this->db->query("SELECT id, external_location_id AS page_id, external_location_name AS name, meta_json FROM platform_connections WHERE website_id = ? AND user_id = ? AND platform = 'facebook' AND status = 'connected' ORDER BY id", [$wid, $uid]);
        $active = $this->db->query("SELECT external_location_id FROM platform_connections WHERE website_id = ? AND platform = 'messenger' AND status = 'connected'", [$wid]);
        $activeIds = array_column($active, 'external_location_id');
        $ig = [];
        foreach ($this->db->query("SELECT external_location_id, external_location_name, access_token FROM platform_connections WHERE website_id = ? AND user_id = ? AND platform = 'instagram' AND status = 'connected'", [$wid, $uid]) as $r) $ig[$r['access_token']] = $r['external_location_name'];
        $tokens = [];
        foreach ($this->db->query("SELECT id, access_token FROM platform_connections WHERE website_id = ? AND user_id = ? AND platform = 'facebook' AND status = 'connected'", [$wid, $uid]) as $r) $tokens[(int) $r['id']] = $r['access_token'];
        $missing = [];
        $out = [];
        foreach ($pages as $p) {
            $meta = json_decode((string) ($p['meta_json'] ?? ''), true) ?: [];
            if (!empty($meta['missing']['messaging'])) $missing = $meta['missing']['messaging'];
            $out[] = ['id' => (int) $p['id'], 'name' => (string) $p['name'], 'instagram' => $ig[$tokens[(int) $p['id']] ?? ''] ?? null, 'messaging' => in_array($p['page_id'], $activeIds, true)];
        }
        return [
            'meta_ready' => (new MetaOAuthClient())->isConfigured(),
            'pages' => $out,
            'missing_permissions' => (bool) $missing,
            'connect_url' => '/ads/connect/meta?for=chat&website_id=' . $wid,
        ];
    }

    /** تشغيل/إيقاف رد الشات بوت على صفحة. صفحة واحدة بس لكل موقع. */
    public function setMessaging(int $uid, int $wid, int $connectionId, bool $enabled): array
    {
        $page = $this->db->query("SELECT * FROM platform_connections WHERE id = ? AND website_id = ? AND user_id = ? AND platform = 'facebook' AND status = 'connected' LIMIT 1", [$connectionId, $wid, $uid])[0] ?? null;
        if (!$page) throw new InvalidArgumentException('Page not found. Sign in with Facebook again.');
        $token = (new Encryption())->decrypt((string) $page['access_token']);
        if ($token === '') throw new InvalidArgumentException('Page access expired. Sign in with Facebook again.');
        $api = new MetaSocialAPI($token);
        if (!$enabled) {
            $api->unsubscribeMessaging((string) $page['external_location_id'], $token);
            $this->db->query("UPDATE platform_connections SET status = 'disconnected', updated_at = NOW() WHERE website_id = ? AND platform = 'messenger' AND external_location_id = ?", [$wid, $page['external_location_id']]);
            return $this->overview($uid, $wid);
        }
        $sub = $api->subscribeMessaging((string) $page['external_location_id'], $token);
        if (empty($sub['success'])) {
            Logger::warning('Meta chat subscribe failed', ['website_id' => $wid, 'error' => mb_substr((string) ($sub['error'] ?? ''), 0, 200)]);
            throw new RuntimeException('Facebook did not allow message access for this page. Sign in with Facebook again and approve "Manage and access Page conversations".');
        }
        // صفحة واحدة بترد على رسايل الموقع: نوقف أي صفحة تانية كانت شغالة
        $this->db->query("UPDATE platform_connections SET status = 'disconnected', updated_at = NOW() WHERE website_id = ? AND platform = 'messenger' AND external_location_id <> ?", [$wid, $page['external_location_id']]);
        $existing = $this->db->query("SELECT id FROM platform_connections WHERE website_id = ? AND platform = 'messenger' AND external_location_id = ? LIMIT 1", [$wid, $page['external_location_id']]);
        if ($existing) {
            $this->db->query("UPDATE platform_connections SET status = 'connected', access_token = ?, external_location_name = ?, user_id = ?, last_error = NULL, updated_at = NOW() WHERE id = ?", [$page['access_token'], $page['external_location_name'], $uid, (int) $existing[0]['id']]);
        } else {
            $this->db->query("INSERT INTO platform_connections (website_id, user_id, platform, access_token, external_account_id, external_location_id, external_location_name, status, connected_at, updated_at) VALUES (?, ?, 'messenger', ?, ?, ?, ?, 'connected', NOW(), NOW())", [$wid, $uid, $page['access_token'], $page['external_location_id'], $page['external_location_id'], $page['external_location_name']]);
        }
        return $this->overview($uid, $wid);
    }
}

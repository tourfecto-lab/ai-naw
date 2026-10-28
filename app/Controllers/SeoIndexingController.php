<?php

/**
 * Tourfecto - IndexNow Instant Indexing Controller
 * @version 1.0.0
 *
 * فهرسة فورية عند Bing/Yandex/Seznam/Naver عبر بروتوكول IndexNow.
 * بيحوّل التنفيذ التلقائي من "تحسين الكود" لـ "تحسين + إبلاغ محركات
 * البحث فورًا" - بدل انتظار الزحف الطبيعي اللي بياخد أسابيع.
 */
class SeoIndexingController extends Controller
{
    /** @var IndexNowService */
    private $indexnow;

    public function __construct()
    {
        parent::__construct();
        $this->indexnow = new IndexNowService();
    }

    /** POST /api/indexnow/generate-key  { website_id } */
    public function generateKey(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $key = $this->indexnow->generateKey();
        $this->db->exec(
            "UPDATE websites SET indexnow_key = ?, indexnow_enabled = 1 WHERE id = ?",
            [$key, $websiteId]
        );

        $site = $this->db->query("SELECT main_url, embed_token FROM websites WHERE id = ? LIMIT 1", [$websiteId]);
        $host = parse_url($site[0]['main_url'] ?? '', PHP_URL_HOST) ?? '';

        $this->log('IndexNow Key Generated', ['website_id' => $websiteId]);

        return $this->success([
            'indexnow_key' => $key,
            'key_file_url' => "https://{$host}/{$key}.txt",
            'key_file_served_by' => '/s/' . ($site[0]['embed_token'] ?? '') . '/' . $key . '.txt',
        ], 'تم توليد مفتاح IndexNow - الفهرسة الفورية اتشغلت');
    }

    /** POST /api/indexnow/toggle  { website_id, enabled } */
    public function toggle(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        $enabled = (int) $this->get('enabled', 0);
        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $this->db->exec("UPDATE websites SET indexnow_enabled = ? WHERE id = ?", [$enabled ? 1 : 0, $websiteId]);
        return $this->success(['website_id' => $websiteId, 'indexnow_enabled' => $enabled ? 1 : 0]);
    }

    /** GET /api/indexnow/status?website_id=X */
    public function status(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $site = $this->db->query(
            "SELECT indexnow_key, indexnow_enabled FROM websites WHERE id = ? LIMIT 1",
            [$websiteId]
        );
        return $this->success([
            'indexnow_enabled' => (bool) ($site[0]['indexnow_enabled'] ?? false),
            'has_key' => !empty($site[0]['indexnow_key']),
        ]);
    }

    /** POST /api/indexnow/submit  { website_id, urls[] } */
    public function submit(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        $urls = (array) $this->get('urls', []);

        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }
        if (empty($urls)) {
            return $this->error('مفيش روابط للإرسال', 422);
        }

        $site = $this->db->query("SELECT main_url, indexnow_key, indexnow_enabled FROM websites WHERE id = ? LIMIT 1", [$websiteId]);
        if (empty($site[0]['indexnow_key'])) {
            return $this->error('ولّد مفتاح IndexNow الأول', 422);
        }

        $host = parse_url($site[0]['main_url'] ?? '', PHP_URL_HOST) ?? '';
        $result = $this->indexnow->submitUrls($host, (string) $site[0]['indexnow_key'], $urls);

        $this->log('IndexNow Submit', ['website_id' => $websiteId, 'count' => count($urls), 'success' => $result['success']]);

        if (!$result['success']) {
            return $this->error($result['error'] ?? 'فشل الإرسال لـ IndexNow', 502);
        }

        return $this->success($result, 'تم إرسال الروابط للفهرسة الفورية');
    }

    /** POST /api/google-indexing/toggle  { website_id, enabled } - تفعيل Google Indexing API (G3) */
    public function googleToggle(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id');
        $enabled = (int) $this->get('enabled', 0);
        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $service = new GoogleIndexingService();
        if ($enabled && !$service->isConfigured()) {
            return $this->error('Google Indexing API غير مهيأ - اضبط GOOGLE_SERVICE_ACCOUNT_JSON في .env الأول', 422);
        }

        $this->db->exec("UPDATE websites SET google_indexing_enabled = ? WHERE id = ?", [$enabled ? 1 : 0, $websiteId]);
        $this->log('Google Indexing Toggle', ['website_id' => $websiteId, 'enabled' => $enabled]);

        return $this->success([
            'website_id' => $websiteId,
            'google_indexing_enabled' => $enabled ? 1 : 0,
            'configured' => $service->isConfigured(),
        ]);
    }

    /** POST /api/google-indexing/submit  { website_id, urls[] } - إبلاغ Google بفهرسة (G3) */
    public function googleSubmit(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id');
        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $extraUrls = (array) $this->get('urls', []);
        $service = new GoogleIndexingService();
        $result = $service->submitSite($this->db, $websiteId, (int) $this->user['id'], $extraUrls);

        $this->log('Google Indexing Submit', ['website_id' => $websiteId, 'available' => $result['available'], 'success' => $result['success']]);

        if (!$result['available']) {
            return $this->error($result['error'] ?? 'Google Indexing غير متاح', 422);
        }
        if (!$result['success']) {
            return $this->error($result['error'] ?? 'فشل إبلاغ Google', 502);
        }

        return $this->success($result, 'تم إبلاغ Google بالفهرسة');
    }

    /** GET /api/google-indexing/status?website_id=X - حالة تهيئة المصدر + تفعيل الموقع (G3) */
    public function googleStatus(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id');
        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $service = new GoogleIndexingService();
        $site = $this->db->query(
            "SELECT google_indexing_enabled, last_google_indexed_at FROM websites WHERE id = ? LIMIT 1",
            [$websiteId]
        );

        return $this->success([
            'configured' => $service->isConfigured(),
            'reason' => $service->configReason(),
            'enabled' => (bool) ($site[0]['google_indexing_enabled'] ?? false),
            'last_submitted_at' => $site[0]['last_google_indexed_at'] ?? null,
        ]);
    }

    private function ownsWebsite(int $websiteId): bool
    {
        $rows = $this->db->query(
            "SELECT id FROM websites WHERE id = ? AND user_id = ? LIMIT 1",
            [$websiteId, $this->user['id']]
        );
        return !empty($rows);
    }
}

<?php

/**
 * Baidu Indexing Controller — mirrors SeoIndexingController pattern.
 */
class BaiduIndexingController extends Controller
{
    /** @var BaiduIndexingService */
    private $baidu;

    public function __construct()
    {
        parent::__construct();
        $this->baidu = new BaiduIndexingService();
    }

    /** POST /api/baidu/submit { website_id, urls[] } */
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

        $site = $this->db->query(
            "SELECT main_url, baidu_token, target_languages FROM websites WHERE id = ? LIMIT 1",
            [$websiteId]
        );
        if (empty($site)) {
            return $this->error('الموقع غير موجود', 404);
        }

        if (!BaiduIndexingService::isChinaTarget($site[0]['target_languages'] ?? null)) {
            return $this->error('Baidu غير مفعّل لهذا الموقع (أضف zh لـ target_languages)', 422);
        }

        $token = (string) ($site[0]['baidu_token'] ?? '');
        if ($token === '') {
            return $this->error('أضف Baidu Token في إعدادات الموقع', 422);
        }

        $mainUrl = rtrim((string) $site[0]['main_url'], '/');
        $result = $this->baidu->submitUrls($mainUrl, $token, $urls);

        $this->log('Baidu Submit', [
            'website_id' => $websiteId,
            'count' => count($urls),
            'success' => $result['success'],
        ]);

        if (!$result['success']) {
            return $this->error($result['message'] ?? 'فشل الإرسال لـ Baidu', 502);
        }

        return $this->success($result, 'تم إرسال الروابط لفهرسة Baidu');
    }

    /** POST /api/baidu/token { website_id, token } */
    public function setToken(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        $token = (string) $this->get('token', '');

        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $this->db->exec(
            "UPDATE websites SET baidu_token = ? WHERE id = ?",
            [$token, $websiteId]
        );

        return $this->success(['website_id' => $websiteId], 'تم حفظ Baidu Token');
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

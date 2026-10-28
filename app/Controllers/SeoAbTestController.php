<?php

/**
 * Tourfecto - SEO A/B Testing Controller
 * @version 1.0.0
 *
 * تجارب A/B على عناصر الـ SEO (عنوان/وصف/Canonical/JSON-LD...) بنفس فكرة
 * SearchPilot: بنخدم نسخ مختلفة من نفس الصفحة لمحركات البحث بشكل حتمي،
 * وبعد فترة بنحدد الفائز (عبر Google Search Console) ونرقّيه تلقائيًا.
 */
class SeoAbTestController extends Controller
{
    /** @var SeoAbTestService */
    private $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new SeoAbTestService($this->db);
    }

    /** POST /api/seo-ab-tests  { website_id, name, target_field, target_path? } */
    public function create(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        $name = trim((string) $this->get('name'));
        $targetField = (string) $this->get('target_field');
        $targetPath = $this->get('target_path') ? (string) $this->get('target_path') : null;

        if (!$websiteId || $name === '' || $targetField === '') {
            return $this->error('بيانات غير صالحة', 422);
        }
        if (!in_array($targetField, ['seo_title', 'seo_description', 'canonical_url', 'json_ld', 'faq_schema', 'speakable'], true)) {
            return $this->error('حقل مستهدف غير مدعوم للتجربة', 422);
        }
        if (!$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        $result = $this->service->createTest((int) $this->user['id'], $websiteId, $name, $targetField, $targetPath);
        $this->log('SEO A/B Test Created', ['website_id' => $websiteId, 'test_id' => $result['test_id']]);

        return $this->success($result, 'تم إنشاء التجربة');
    }

    /** POST /api/seo-ab-tests/{id}/variants  { name, value, is_control, weight } */
    public function addVariant(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $testId = (int) ($params['id'] ?? 0);
        $name = trim((string) $this->get('name'));
        $value = (string) $this->get('value');
        $isControl = (bool) $this->get('is_control', false);
        $weight = (int) $this->get('weight', 50);

        if (!$testId || $name === '' || $value === '') {
            return $this->error('بيانات غير صالحة', 422);
        }
        if (!$this->ownsTest($testId)) {
            return $this->error('التجربة غير موجودة', 404);
        }

        $result = $this->service->addVariant($testId, $name, $value, $isControl, $weight);
        return $this->success($result, 'تمت إضافة النسخة');
    }

    /** POST /api/seo-ab-tests/{id}/start */
    public function start(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $testId = (int) ($params['id'] ?? 0);
        if (!$testId || !$this->ownsTest($testId)) {
            return $this->error('التجربة غير موجودة', 404);
        }

        $result = $this->service->startTest($testId);
        return $this->success($result, 'تم بدء التجربة');
    }

    /** POST /api/seo-ab-tests/{id}/complete  { winner_variant_id } */
    public function complete(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $testId = (int) ($params['id'] ?? 0);
        $winnerId = (int) $this->get('winner_variant_id');
        if (!$testId || !$winnerId || !$this->ownsTest($testId)) {
            return $this->error('التجربة أو النسخة الفائزة غير صالحة', 404);
        }

        $result = $this->service->completeTest($testId, $winnerId);
        return $this->success($result, 'تم إنهاء التجربة وتحديد الفائز');
    }

    /** GET /api/seo-ab-tests?website_id=X */
    public function list(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId || !$this->ownsWebsite($websiteId)) {
            return $this->error('الموقع غير موجود', 404);
        }

        return $this->success(['tests' => $this->service->listTests($websiteId)]);
    }

    /**
     * GET /api/seo-ab-tests/{id}/results
     * قياس النتائج الفعلية لكل نسخة عبر Google Search Console (CTR/انطباعات/ترتيب)،
     * واقتراح الفائز تلقائيًا. ده الحل الحقيقي بدل الاعتماد على served_count.
     */
    public function results(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $testId = (int) ($params['id'] ?? 0);
        if (!$testId || !$this->ownsTest($testId)) {
            return $this->error('التجربة غير موجودة', 404);
        }

        $tests = $this->db->query(
            "SELECT website_id, status, started_at FROM seo_ab_tests WHERE id = ? LIMIT 1",
            [$testId]
        );
        $test = $tests[0];
        $websiteId = (int) $test['website_id'];

        $connections = (new PlatformConnection())->where([
            'website_id' => $websiteId,
            'platform' => 'google_search_console',
            'status' => 'connected',
        ], [], 1);

        // مفيش GSC مرتبط => نرجع ملخص النسخ (served_count) من غير قياس فعلي
        if (empty($connections)) {
            return $this->success([
                'gsc_connected' => false,
                'variants' => $this->service->variantBreakdown($testId),
                'suggested_winner_variant_id' => null,
                'message' => 'اربط الموقع بـ Search Console لقياس CTR الفعلي لكل نسخة',
            ]);
        }

        $connection = $connections[0];
        $encryption = new Encryption();

        try {
            $accessToken = $encryption->decrypt($connection->getAttribute('access_token'));

            // تجديد التوكن لو قرب ينتهي (نفس منطق SearchConsoleController::stats)
            if ((new PlatformConnection($connection->toArray()))->isTokenExpired() && $connection->getAttribute('refresh_token')) {
                $refreshToken = $encryption->decrypt($connection->getAttribute('refresh_token'));
                $refreshed = (new GoogleOAuthClient(GoogleOAuthClient::SCOPE_SEARCH_CONSOLE))->refreshAccessToken($refreshToken);
                if ($refreshed['success']) {
                    $accessToken = $refreshed['access_token'];
                    $connection->setAttribute('access_token', $encryption->encrypt($accessToken));
                    $connection->setAttribute('token_expires_at', date('Y-m-d H:i:s', time() + (int) $refreshed['expires_in']));
                    $connection->save();
                }
            }

            $api = new GoogleSearchConsoleAPI($accessToken);
            $siteUrl = (string) $connection->getAttribute('external_location_id');

            // فترة القياس: من بداية التجربة (أو آخر 28 يوم) حتى اليوم، مع مراعاة تأخر Google
            $startDate = $test['started_at']
                ? date('Y-m-d', strtotime((string) $test['started_at']))
                : date('Y-m-d', strtotime('-28 days'));
            $endDate = date('Y-m-d', strtotime('-2 days'));

            // كاش مقاييس GSC (SeoPerformanceService) عشان منضربش Google بـ
            // request كامل في كل قياس، مع تجديد تلقائي لو البيانات قديمة.
            $perf = new SeoPerformanceService($this->db);
            $age = $perf->metricsAgeHours($websiteId);
            if ($age === null || $age > 6) {
                $synced = $perf->syncPageMetrics($websiteId, $siteUrl, $accessToken);
                if (!$synced['success']) {
                    return $this->error('تعذر جلب بيانات GSC: ' . ($synced['error'] ?? ''), 502);
                }
            }

            $pageMetrics = $perf->getCachedPageMetrics($websiteId);
            if (empty($pageMetrics)) {
                return $this->success([
                    'gsc_connected' => true,
                    'test_id' => $testId,
                    'variants' => $this->service->variantBreakdown($testId),
                    'suggested_winner_variant_id' => null,
                    'message' => 'مفيش بيانات أداء في Search Console لصفحات التجربة بعد — بيانات Google بتتأخر يوم-يومين، جرّب تاني بعد فترة.',
                ]);
            }

            $metrics = $this->service->aggregateMetrics($testId, $pageMetrics);

            return $this->success(array_merge([
                'gsc_connected' => true,
                'test_id' => $testId,
                'date_range' => ['start' => $startDate, 'end' => $endDate],
                'metrics_cached' => $age !== null && $age <= 6,
            ], $metrics));
        } catch (Exception $e) {
            Logger::error('SEO A/B Results Error', ['test_id' => $testId, 'message' => $e->getMessage()]);
            return $this->error('تعذر قياس النتائج', 500);
        }
    }

    private function ownsWebsite(int $websiteId): bool
    {
        $rows = $this->db->query(
            "SELECT id FROM websites WHERE id = ? AND user_id = ? LIMIT 1",
            [$websiteId, $this->user['id']]
        );
        return !empty($rows);
    }

    private function ownsTest(int $testId): bool
    {
        $rows = $this->db->query(
            "SELECT t.id FROM seo_ab_tests t
              INNER JOIN websites w ON w.id = t.website_id
              WHERE t.id = ? AND w.user_id = ? LIMIT 1",
            [$testId, $this->user['id']]
        );
        return !empty($rows);
    }
}

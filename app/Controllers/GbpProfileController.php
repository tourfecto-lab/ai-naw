<?php

/**
 * Tourfecto - GBP Module Controller (Setup Wizard / Connection Center /
 * Profile / Photos / Insights / Analytics / AI Insights / Recommendations)
 * @version 1.0.0
 * @since 2026-08-09 (GBP Module Upgrade)
 *
 * ملحوظة معمارية: الاتصال الأساسي (OAuth connect/disconnect/callback) وإدارة
 * المراجعات لسه في ReputationController + GoogleReviewSyncService زي ما هم -
 * الكنترولر ده بيوسّع الوظائف الجديدة بس (Setup Wizard/Sync/Profile
 * Editing/Photos/Insights/AI) من غير ما يكرر أو يعيد بناء أي حاجة موجودة.
 * منشورات GBP (Posts) لسه في GoogleBusinessContentController زي ما هي.
 */
class GbpProfileController extends Controller
{
    /** @var GbpSetupStatusService */
    private $setupStatus;
    /** @var GbpSyncService */
    private $syncService;
    /** @var GbpProfileService */
    private $profileService;
    /** @var GbpPhotoService */
    private $photoService;
    /** @var GbpInsightsService */
    private $insightsService;
    /** @var GbpAIInsightsService */
    private $aiInsightsService;
    /** @var GbpReputationAnalyticsService */
    private $analyticsService;
    /** @var GbpReplyRuleService */
    private $replyRuleService;
    /** @var GbpLocalSeoAuditService */
    private $localSeoAuditService;

    public function __construct()
    {
        parent::__construct();
        $this->setupStatus = new GbpSetupStatusService();
        $this->syncService = new GbpSyncService();
        $this->profileService = new GbpProfileService();
        $this->photoService = new GbpPhotoService();
        $this->insightsService = new GbpInsightsService();
        $this->aiInsightsService = new GbpAIInsightsService();
        $this->analyticsService = new GbpReputationAnalyticsService();
        $this->replyRuleService = new GbpReplyRuleService();
        $this->localSeoAuditService = new GbpLocalSeoAuditService();
    }

    // ============================================
    // Setup Wizard + Connection Center
    // ============================================

    /** GET /api/gbp/status - حالة النظام (Maps/OAuth/Permissions) + اتصالات المستخدم */
    public function status(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $system = $this->setupStatus->systemStatus();
            $isAdmin = in_array((string) ($this->user['role'] ?? ''), ['admin', 'super_admin'], true);
            if (!$isAdmin) {
                // 🔧 العميل كان بيشوف أسماء متغيرات السيرفر (GOOGLE_MAPS_API_KEY / .env) - دي حاجة تخص الأدمن.
                // للعميل: لو المنصة جاهزة مايظهرش الكارت ده خالص، ولو لأ رسالة واحدة مفهومة.
                $missing = array_filter($system, fn($i) => in_array($i['status'] ?? '', ['missing', 'error'], true));
                $system = $missing ? ['platform' => [
                    'status' => 'action_required',
                    'label' => 'ربط Google Business',
                    'detail' => 'الخدمة دي لسه بتتجهّز من فريق المنصة. هتتفعّل قريب ومش محتاج تعمل حاجة دلوقتي - لو مستعجل كلّم الدعم.',
                ]] : [];
            }
            $connections = $this->setupStatus->connectionsForUser((int) $this->user['id']);
            if (!$isAdmin) {
                // أخطاء إعداد المنصة (مشروع Google Cloud) مش حاجة العميل يقدر يصلحها
                foreach ($connections as &$c) {
                    if (!empty($c['last_error']) && preg_match('/Google Cloud|Cloud Console|API (?:has not been used|is disabled)|SERVICE_DISABLED|NOT_SUPPORTED/i', (string) $c['last_error'])) {
                        $c['last_error'] = 'حسابك اتربط، والمزامنة مع Google بتتجهز من فريق المنصة. مش محتاج تعمل حاجة - هتشتغل لوحدها أول ما تجهز.';
                    }
                }
                unset($c);
            }
            return $this->success([
                'system' => $system,
                'connections' => $connections,
                'websites' => $this->setupStatus->websitesWithConnectionState((int) $this->user['id']),
            ]);
        } catch (Throwable $e) {
            Logger::error('GBP status error', ['message' => $e->getMessage()]);
            return $this->error('تعذر جلب حالة الإعداد', 500);
        }
    }

    /**
     * GET /api/gbp/health - فحص صحة الموديول (بند AP/AQ بالسبيك)
     * @since 2026-08-14 (Round 8: Professional Finalization)
     */
    public function health(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $service = new GbpHealthCheckService();
            return $this->success($service->check());
        } catch (Throwable $e) {
            Logger::error('GBP health check error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تنفيذ فحص الصحة', 500);
        }
    }

    /**
     * GET /api/gbp/competitors - مقارنة تنافسية مع المنافسين القريبين على
     * Google Maps (تقييم/عدد مراجعات/معدل رد) - بناءً على التحليل التنافسي
     * مع Chatmeter/Birdeye/Semrush Local.
     * @since 2026-08-15
     */
    public function competitors(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) ($params['website_id'] ?? $this->get('website_id', 0));
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        try {
            $service = new GbpCompetitorBenchmarkService();
            return $this->success($service->benchmark($websiteId, (int) $this->user['id']));
        } catch (Throwable $e) {
            Logger::error('GBP competitor benchmark error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تنفيذ المقارنة التنافسية', 500);
        }
    }

    /**
     * GET /api/gbp/analytics - لوحة Reputation Intelligence:
     * KPIs (Response Rate/First Response Time/Review Velocity) + اتجاهات
     * 90 يوم + توزيع التقييمات + مزيج المشاعر. على مستوى Birdeye/Chatmeter.
     * @since 2026-08-15
     */
    public function analytics(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        $days = (int) $this->get('days', 90);
        $result = $this->analyticsService->getAnalytics($websiteId, (int) $this->user['id'], $days);
        if (!$result['success']) {
            return $this->error($result['error'], $this->gbpErrorStatus($result));
        }
        return $this->success($result);
    }

    /**
     * GET /api/gbp/risk-signals - مراقبة المخاطر (PulseAi-style):
     * هبوط تقييم، قفزة مراجعات، قفزة سلبية، نمط مشبوه.
     * @since 2026-08-15
     */
    public function riskSignals(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        $result = $this->analyticsService->getRiskSignals($websiteId, (int) $this->user['id']);
        if (!$result['success']) {
            return $this->error($result['error'], $this->gbpErrorStatus($result));
        }
        return $this->success($result);
    }

    /**
     * GET /api/gbp/share-of-voice - حصة الظهور المحلية مقارنة بالمنافسين
     * في Google Places (review share + ranks).
     * @since 2026-08-15
     */
    public function shareOfVoice(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        $result = $this->analyticsService->getShareOfVoice($websiteId, (int) $this->user['id']);
        if (!$result['success']) {
            return $this->error($result['error'] ?? 'تعذر حساب حصة الظهور', $this->gbpErrorStatus($result));
        }
        return $this->success($result);
    }

    /**
     * GET /api/gbp/local-seo-audit - تدقيق الحضور في البحث المحلي
     * (نفس فكرة Local SEO Audit في Semrush Local/Birdeye): Score حتمي 0-100
     * على 4 محاور (Profile/NAP/Reputation/Visibility) + توصيات مرتبة.
     * @since 2026-08-15 (Reputation Intelligence Tier 3)
     */
    public function localSeoAudit(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        $result = $this->localSeoAuditService->audit($websiteId, (int) $this->user['id']);
        if (!$result['success']) {
            // التدقيق بيقرا ملف النشاط من Google؛ لو مزامنة Google واقفة عند المنصة العميل كان بيشوف "تعذر إتمام العملية" من غير سبب
            if (preg_match('/Google Cloud|Cloud Console|API (?:has not been used|is disabled)|SERVICE_DISABLED|NOT_SUPPORTED|غير مربوط|مش مربوط/iu', (string) ($result['error'] ?? ''))) {
                return $this->error('تدقيق SEO المحلي بيقرا بيانات ملف نشاطك من Google، والمزامنة مع Google لسه متوقفة. هيشتغل لوحده أول ما المزامنة ترجع.', 409);
            }
            return $this->error($result['error'] ?? 'تعذر تنفيذ تدقيق SEO المحلي', $this->gbpErrorStatus($result));
        }
        return $this->success($result);
    }

    /**
     * GET /api/gbp/reply-rules - قواعد الرد التلقائي (BirdAI/Podium-style)
     * @since 2026-08-15
     */
    public function listReplyRules(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }
        $result = $this->replyRuleService->listRules($websiteId, (int) $this->user['id']);
        if (!$result['success']) {
            return $this->error($result['error'], 500);
        }
        return $this->success($result);
    }

    /** POST /api/gbp/reply-rules - إنشاء قاعدة */
    public function createReplyRule(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }
        $result = $this->replyRuleService->createRule($websiteId, (int) $this->user['id'], $this->data);
        if (!$result['success']) {
            return $this->error($result['error'], 422);
        }
        return $this->success($result, 'تم إنشاء القاعدة', 201);
    }

    /** PUT /api/gbp/reply-rules/{id} - تحديث قاعدة */
    public function updateReplyRule(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $ruleId = (int) ($params['id'] ?? 0);
        if (!$ruleId) {
            return $this->error('rule id مطلوب', 422);
        }
        $result = $this->replyRuleService->updateRule($ruleId, (int) $this->user['id'], $this->data);
        if (!$result['success']) {
            return $this->error($result['error'], 422);
        }
        return $this->success($result);
    }

    /** DELETE /api/gbp/reply-rules/{id} - حذف قاعدة */
    public function deleteReplyRule(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $ruleId = (int) ($params['id'] ?? 0);
        if (!$ruleId) {
            return $this->error('rule id مطلوب', 422);
        }
        $result = $this->replyRuleService->deleteRule($ruleId, (int) $this->user['id']);
        if (!$result['success']) {
            return $this->error($result['error'], 500);
        }
        return $this->success($result);
    }

    /**
     * POST /api/gbp/reply-rules/apply/{review_id} - تنفيذ القواعد على مراجعة
     * محددة (تشغيل يدوي، أو تلقائيًا من الكرون بعد المزامنة).
     * @since 2026-08-15
     */
    public function applyReplyRules(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $reviewId = (int) ($params['review_id'] ?? 0);
        if (!$reviewId) {
            return $this->error('review_id مطلوب', 422);
        }
        $result = $this->replyRuleService->applyRulesToReview($reviewId);
        if (!$result['success']) {
            return $this->error($result['error'] ?? 'تعذر تنفيذ القواعد', 422);
        }
        return $this->success($result);
    }

    /** POST /api/gbp/sync/{website_id} - مزامنة يدوية فورية */
    public function sync(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) ($params['website_id'] ?? 0);
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        $result = $this->syncService->syncWebsite($websiteId, (int) $this->user['id']);
        if (!$result['success']) {
            return $this->error($result['error'], $this->gbpErrorStatus($result));
        }

        return $this->success($result, 'تمت المزامنة بنجاح');
    }

    // ============================================
    // Business Profile Management
    // ============================================

    /** GET /api/gbp/profile?website_id= */
    public function getProfile(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        $result = $this->profileService->getProfile($websiteId, (int) $this->user['id']);
        if (!$result['success']) {
            return $this->error($result['error'], $this->gbpErrorStatus($result));
        }

        return $this->success($result);
    }

    /** POST /api/gbp/profile */
    public function updateProfile(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['website_id' => 'required'])) {
            return $this->error('بيانات ناقصة', 422);
        }

        $fields = array_intersect_key($this->all(), array_flip(['description', 'phone', 'website', 'regular_hours']));

        $result = $this->profileService->updateProfile((int) $this->get('website_id'), (int) $this->user['id'], $fields);
        if (!$result['success']) {
            return $this->error($result['error'], $this->gbpErrorStatus($result));
        }

        return $this->success($result, 'تم تحديث البروفايل بنجاح');
    }

    /** GET /api/gbp/attributes?website_id= */
    public function getAttributes(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        $result = $this->profileService->getAttributes($websiteId, (int) $this->user['id']);
        if (!$result['success']) {
            return $this->error($result['error'], $this->gbpErrorStatus($result));
        }

        return $this->success($result);
    }

    /** POST /api/gbp/attributes {website_id, changes: {attribute_id: bool}} */
    public function updateAttributes(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        $changes = (array) $this->get('changes', []);
        if (!$websiteId || empty($changes)) {
            return $this->error('بيانات ناقصة', 422);
        }

        $result = $this->profileService->updateAttributes($websiteId, (int) $this->user['id'], $changes);
        if (!$result['success']) {
            return $this->error($result['error'], $this->gbpErrorStatus($result));
        }

        return $this->success([], 'تم تحديث الخصائص بنجاح');
    }

    // ============================================
    // Photos / Media
    // ============================================

    /** GET /api/gbp/photos?website_id=&page=&limit= */
    public function listPhotos(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        $page = max(1, (int) $this->get('page', 1));
        $limit = min(60, max(1, (int) $this->get('limit', 24)));

        $result = $this->photoService->listPhotos($websiteId, (int) $this->user['id'], $page, $limit);
        if (!$result['success']) {
            return $this->error($result['error'], 500);
        }

        return $this->success($result);
    }

    /** POST /api/gbp/photos (multipart: photo, website_id, category) */
    public function uploadPhoto(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) ($_POST['website_id'] ?? 0);
        $category = (string) ($_POST['category'] ?? 'ADDITIONAL');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }
        $uploader = new GbpMediaUploadHandler();
        $libraryId = (int) ($_POST['media_item_id'] ?? 0);
        if (empty($_FILES['photo']) && $libraryId < 1) {
            return $this->error('لم يتم اختيار أي صورة', 422);
        }
        if (empty($_FILES['photo'])) {
            // صورة من مكتبة الاستوديو الإبداعي (متولدة/مرفوعة/مسحوبة من الموقع)
            $uploadResult = $uploader->uploadFromLibrary($libraryId, (int) $this->user['id']);
        } else {
            $validation = $this->photoService->validateUpload($_FILES['photo']);
            if (!$validation['valid']) {
                return $this->error($validation['error'], 422);
            }
            $uploadResult = $uploader->upload($_FILES['photo'], (int) $this->user['id']);
        }
        if (!$uploadResult['success']) {
            return $this->error($uploadResult['error'], 422);
        }

        // Round 6 (2026-08-11): الرفع لجوجل نفسه بقى Async عبر الطابور -
        // بنرجّع فورًا status='uploading' بدل ما نخلي الـ request يستنى
        // رد Google API.
        $result = $this->photoService->queueUpload($websiteId, (int) $this->user['id'], $uploadResult['public_url'], $category);
        if (!$result['success']) {
            return $this->error($result['error'], $this->gbpErrorStatus($result));
        }

        return $this->success($result, 'جارِ رفع الصورة على Google Business Profile في الخلفية...', 202);
    }

    /** DELETE /api/gbp/photos/{id}?website_id= */
    public function deletePhoto(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $photoId = (int) ($params['id'] ?? 0);
        $websiteId = (int) $this->get('website_id');
        if (!$photoId || !$websiteId) {
            return $this->error('بيانات ناقصة', 422);
        }

        $result = $this->photoService->deletePhoto($websiteId, (int) $this->user['id'], $photoId);
        if (!$result['success']) {
            return $this->error($result['error'], $this->gbpErrorStatus($result));
        }

        return $this->success([], 'تم حذف الصورة');
    }

    /** POST /api/gbp/photos/{id}/primary - "رئيسية" محلي في لوحة Tourfecto فقط، مش تغيير فعلي في Google (موثّق في CHANGELOG) */
    public function setPrimaryPhoto(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $photoId = (int) ($params['id'] ?? 0);
        $websiteId = (int) $this->get('website_id');
        if (!$photoId || !$websiteId) {
            return $this->error('بيانات ناقصة', 422);
        }

        $result = $this->photoService->setPrimary($websiteId, (int) $this->user['id'], $photoId);
        if (!$result['success']) {
            return $this->error($result['error'], 422);
        }

        return $this->success([], 'تم التحديد كصورة رئيسية في لوحة Tourfecto');
    }

    // ============================================
    // Insights / Analytics
    // ============================================

    /** GET /api/gbp/insights?website_id=&days=30 */
    public function insights(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        $days = (int) $this->get('days', 30);
        if (!in_array($days, [7, 30, 90], true)) {
            $days = max(1, min(365, $days)); // مدى مخصص (Custom Range) بحد أقصى سنة
        }
        $dateFrom = $this->get('date_from');
        $dateTo = $this->get('date_to');

        $result = $this->insightsService->getInsights($websiteId, (int) $this->user['id'], $days, true, $dateFrom ?: null, $dateTo ?: null);
        if (!$result['success']) {
            return $this->error($result['error'], $this->gbpErrorStatus($result));
        }

        return $this->success($result);
    }

    /** GET /api/gbp/ai-insights?website_id= */
    public function aiInsights(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        // الفحص والخصم هنا بدل PlanGateMiddleware: الصفحة بتطلب التحليل أوتوماتيك، فالخصم قبل التأكد
        // من ربط Google كان بياخد وحدة AI من العميل على كل زيارة حتى لو الطلب هيفشل.
        $uid = (int) $this->user['id'];
        $lowFunds = 'خلصت أدوات الذكاء الصناعي في باقتك الشهر ده، ورصيد المحفظة مش كفاية. اشحن المحفظة أو رقّي باقتك.';
        $check = PlanUsage::check($uid, 'ai_tool', 'ai_tool', $lowFunds);
        if (empty($check['ok'])) {
            return $this->error((string) ($check['error'] ?? $lowFunds), 402, ['upgrade_url' => '/subscription']);
        }
        $result = $this->aiInsightsService->generateInsights($websiteId, $uid, static function () use ($uid, $lowFunds): ?array {
            return PlanUsage::charge($uid, 'ai_tool', 'ai_tool', 'أداة ذكاء صناعي: تحليل Google Business') === 'failed'
                ? ['error' => $lowFunds, 'payment_required' => true] : null;
        });
        if (!$result['success']) {
            if (!empty($result['payment_required'])) {
                return $this->error($result['error'], 402, ['upgrade_url' => '/subscription']);
            }
            return $this->error($result['error'], $this->gbpErrorStatus($result));
        }

        return $this->success($result);
    }

    /** GET /api/gbp/recommendations?website_id= */
    public function recommendations(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = (int) $this->get('website_id');
        if (!$websiteId) {
            return $this->error('website_id مطلوب', 422);
        }

        $result = $this->aiInsightsService->generateRecommendations($websiteId, (int) $this->user['id']);
        if (!$result['success']) {
            return $this->error($result['error'], $this->gbpErrorStatus($result));
        }

        return $this->success($result);
    }

    /**
     * GET /reputation/intelligence - مركز ذكاء السمعة (واجهة احترافية
     * تجمع Tier 1/2/3): Analytics + Risk + Share of Voice + Competitors
     * + Local SEO Audit + Reply Rules في صفحة واحدة بتابات.
     * @since 2026-08-15
     */
    public function showReputationIntelligence(array $params = []): array
    {
        // كل بيانات الصفحة دي جاية من Google Business: من غير ربط كانت الكروت
        // كلها فاضية ورسائل خطأ بتطلع. بدلها شاشة واحدة واضحة فيها زرار الربط.
        $gate = self::connectGateHtml((int) $this->user['id']);
        if ($gate !== null) {
            header('Content-Type: text/html; charset=utf-8');
            echo $this->renderPanelPage('reputation_intelligence', $this->tr('sidebar.reputation_intelligence'), 'مؤشرات تقييماتك على Google ومخاطرها ومنافسيك', $gate);
            exit;
        }
        $assetCss = asset_v('/assets/css/reputation-intelligence.css');

        $body = <<<HTML
        <div class="ri-shell">
            <div class="ri-tabs" id="riTabs" role="tablist" aria-label="أقسام ذكاء السمعة">
                <button type="button" class="ri-tab active" data-panel="overview" role="tab" aria-selected="true">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 14l4-4 4 3 5-6"/></svg>
                    نظرة عامة
                </button>
                <button type="button" class="ri-tab" data-panel="risk" role="tab" aria-selected="false">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
                    المخاطر
                </button>
                <button type="button" class="ri-tab" data-panel="market" role="tab" aria-selected="false">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a10 10 0 0 0 0 20 10 10 0 0 1 0-20"/></svg>
                    السوق وحصة الظهور
                </button>
                <button type="button" class="ri-tab" data-panel="seo" role="tab" aria-selected="false">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/><path d="M8 11h6"/><path d="M11 8v6"/></svg>
                    تدقيق SEO المحلي
                </button>
                <button type="button" class="ri-tab" data-panel="rules" role="tab" aria-selected="false">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h10"/></svg>
                    قواعد الرد التلقائي
                </button>
            </div>

            <!-- ============ نظرة عامة ============ -->
            <section class="ri-panel active" id="panel-overview" data-panel="overview">
                <div class="ri-kpis" id="riKpis">
                    <div class="ri-kpi blue"><div class="ri-kpi-glow"></div><div class="ri-kpi-label">متوسط التقييم</div><div class="ri-kpi-value" id="kpiAvg">-</div><div class="ri-kpi-sub" id="kpiAvgSub">-</div></div>
                    <div class="ri-kpi green"><div class="ri-kpi-glow"></div><div class="ri-kpi-label">معدل الرد</div><div class="ri-kpi-value" id="kpiResponse">-</div><div class="ri-kpi-sub" id="kpiResponseSub">-</div></div>
                    <div class="ri-kpi amber"><div class="ri-kpi-glow"></div><div class="ri-kpi-label">أول وقت رد (متوسط)</div><div class="ri-kpi-value" id="kpiFrt">-</div><div class="ri-kpi-sub">من لحظة استلام المراجعة</div></div>
                    <div class="ri-kpi purple"><div class="ri-kpi-glow"></div><div class="ri-kpi-label">سرعة المراجعات (30 يوم)</div><div class="ri-kpi-value" id="kpiVelocity">-</div><div class="ri-kpi-sub" id="kpiVelocitySub">-</div></div>
                </div>

                <div class="ri-grid-2" style="margin-top:14px;">
                    <div class="ri-card">
                        <div class="ri-card-head">
                            <div class="ri-card-title">اتجاه متوسط التقييم (آخر 90 يوم)</div>
                            <span class="ri-card-sub">قيم حقيقية من مراجعاتك المتزامنة</span>
                        </div>
                        <div class="ri-chart-box"><canvas id="chRating"></canvas></div>
                    </div>
                    <div class="ri-card">
                        <div class="ri-card-head">
                            <div class="ri-card-title">سرعة المراجعات أسبوعيًا</div>
                            <span class="ri-card-sub">مراجعات جديدة كل أسبوع</span>
                        </div>
                        <div class="ri-chart-box"><canvas id="chVelocity"></canvas></div>
                    </div>
                </div>

                <div class="ri-grid-2" style="margin-top:14px;">
                    <div class="ri-card">
                        <div class="ri-card-head">
                            <div class="ri-card-title">توزيع النجوم</div>
                            <span class="ri-card-sub">كل التقييمات</span>
                        </div>
                        <div class="ri-chart-box"><canvas id="chStars"></canvas></div>
                    </div>
                    <div class="ri-card">
                        <div class="ri-card-head">
                            <div class="ri-card-title">مزيج المشاعر</div>
                            <span class="ri-card-sub" id="sentimentTotal">-</span>
                        </div>
                        <div class="ri-chart-box"><canvas id="chSentiment"></canvas></div>
                    </div>
                </div>
            </section>

            <!-- ============ المخاطر ============ -->
            <section class="ri-panel" id="panel-risk" data-panel="risk">
                <div class="ri-card">
                    <div class="ri-card-head">
                        <div class="ri-card-title">مراقبة المخاطر</div>
                        <span class="ri-card-sub">كشف شذوذ من بياناتك الحقيقية: هبوط تقييم، قفزات، أنماط مشبوهة</span>
                    </div>
                    <div class="ri-risk-hero">
                        <span class="ri-risk-badge low" id="riskBadge">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m8 12 3 3 5-6"/></svg>
                            <span id="riskLevelText">-</span>
                        </span>
                        <div style="font-size:13px;color:var(--panel-text-muted);" id="riskSummary">جارِ الفحص...</div>
                    </div>
                    <div id="riskSignals" style="margin-top:14px;">
                        <div class="ri-skeleton"></div>
                    </div>
                </div>
            </section>

            <!-- ============ السوق ============ -->
            <section class="ri-panel" id="panel-market" data-panel="market">
                <div class="ri-grid-2">
                    <div class="ri-card">
                        <div class="ri-card-head">
                            <div class="ri-card-title">حصة الظهور (Share of Voice)</div>
                            <span class="ri-card-sub" id="sovMarketSize">-</span>
                        </div>
                        <div id="sovBody"><div class="ri-skeleton"></div></div>
                    </div>
                    <div class="ri-card">
                        <div class="ri-card-head">
                            <div class="ri-card-title">مقارنة تنافسية</div>
                            <span class="ri-card-sub">أقرب منافسيك على Google Maps</span>
                        </div>
                        <div id="compBody"><div class="ri-skeleton"></div></div>
                    </div>
                </div>
            </section>

            <!-- ============ تدقيق SEO ============ -->
            <section class="ri-panel" id="panel-seo" data-panel="seo">
                <div class="ri-card">
                    <div class="ri-card-head">
                        <div class="ri-card-title">تدقيق حضورك في البحث المحلي</div>
                        <span class="ri-card-sub" id="seoAvailability">-</span>
                    </div>
                    <div id="seoBody"><div class="ri-skeleton"></div></div>
                </div>
            </section>

            <!-- ============ قواعد الرد ============ -->
            <section class="ri-panel" id="panel-rules" data-panel="rules">
                <div class="ri-card">
                    <div class="ri-card-head">
                        <div class="ri-card-title">قواعد الرد التلقائي</div>
                        <button type="button" class="ri-btn primary sm" onclick="openRuleModal()">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                            قاعدة جديدة
                        </button>
                    </div>
                    <p class="ri-note" style="margin-bottom:14px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px;"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                        القواعد بتشتغل فور استلام مراجعة جديدة (وفي كرون دوري على آخر 7 أيام). الأولوية الأصغر رقمًا بتشتغل الأول.
                    </p>
                    <div class="ri-table-wrap">
                        <table class="ri-table" id="rulesTable">
                            <thead><tr>
                                <th>القاعدة</th><th>الشرط</th><th>الإجراء</th><th>الأولوية</th><th>مفعّلة</th><th style="width:90px;">إجراءات</th>
                            </tr></thead>
                            <tbody><tr><td colspan="6"><div class="ri-empty">جارِ تحميل القواعد...</div></td></tr></tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>

        <!-- Modal: إنشاء/تعديل قاعدة -->
        <div class="p-modal-overlay" id="ruleModal">
            <div class="p-modal">
                <div class="p-modal-head">
                    <h3 id="ruleModalTitle">قاعدة رد جديدة</h3>
                    <button class="p-modal-close" onclick="P.closeModal('ruleModal')" aria-label="إغلاق">×</button>
                </div>
                <div class="p-modal-body">
                    <input type="hidden" id="ruleId">
                    <div class="ri-field">
                        <label for="ruleName">اسم القاعدة</label>
                        <input type="text" id="ruleName" class="ri-input" placeholder="مثال: رد على كل الخماسي">
                    </div>
                    <div class="ri-row">
                        <div class="ri-field">
                            <label for="ruleTrigger">نوع الشرط</label>
                            <select id="ruleTrigger" class="ri-select" onchange="toggleRuleTrigger()">
                                <option value="rating_range">مدى التقييم</option>
                                <option value="sentiment">المشاعر</option>
                            </select>
                        </div>
                        <div class="ri-field">
                            <label for="ruleAction">الإجراء</label>
                            <select id="ruleAction" class="ri-select">
                                <option value="auto_reply">رد تلقائي</option>
                                <option value="notify">إشعار فقط</option>
                                <option value="auto_reply_and_notify">رد + إشعار</option>
                            </select>
                        </div>
                    </div>
                    <div class="ri-row" id="ratingRangeRow">
                        <div class="ri-field">
                            <label for="ruleMin">من (نجوم)</label>
                            <input type="number" id="ruleMin" class="ri-input" min="1" max="5" step="0.5" placeholder="1">
                        </div>
                        <div class="ri-field">
                            <label for="ruleMax">إلى (نجوم)</label>
                            <input type="number" id="ruleMax" class="ri-input" min="1" max="5" step="0.5" placeholder="5">
                        </div>
                    </div>
                    <div class="ri-field" id="sentimentRow" style="display:none;">
                        <label for="ruleSentiment">المشاعر</label>
                        <select id="ruleSentiment" class="ri-select">
                            <option value="positive">إيجابي</option>
                            <option value="neutral">محايد</option>
                            <option value="negative">سلبي</option>
                            <option value="mixed">مختلط</option>
                        </select>
                    </div>
                    <div class="ri-row">
                        <div class="ri-field">
                            <label for="ruleMode">طريقة الرد</label>
                            <select id="ruleMode" class="ri-select" onchange="toggleRuleMode()">
                                <option value="ai">ذكاء اصطناعي (يولّد تلقائيًا)</option>
                                <option value="custom">نص مخصص ثابت</option>
                            </select>
                        </div>
                        <div class="ri-field">
                            <label for="rulePriority">الأولوية</label>
                            <input type="number" id="rulePriority" class="ri-input" min="0" value="100">
                        </div>
                    </div>
                    <div class="ri-field" id="customReplyRow" style="display:none;">
                        <label for="ruleCustom">نص الرد المخصص</label>
                        <textarea id="ruleCustom" class="ri-textarea" placeholder="شكرًا لتقييمك، يسعدنا خدمتك..."></textarea>
                    </div>
                </div>
                <div class="p-modal-foot">
                    <button class="p-btn outline" onclick="P.closeModal('ruleModal')">إلغاء</button>
                    <button class="p-btn primary" onclick="saveRule()">حفظ القاعدة</button>
                </div>
            </div>
        </div>

        <link rel="stylesheet" href="{$assetCss}">
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast, timeAgo = P.timeAgo;
    let charts = {};

    const PANELS = ['overview', 'risk', 'market', 'seo', 'rules'];
    const TRIGGER_LABEL = { rating_range: 'مدى تقييم', sentiment: 'المشاعر' };
    const ACTION_LABEL = { auto_reply: 'رد تلقائي', notify: 'إشعار فقط', auto_reply_and_notify: 'رد + إشعار' };
    const MODE_LABEL = { ai: 'ذكاء اصطناعي', custom: 'نص مخصص' };
    const SENTIMENT_LABEL = { positive: 'إيجابي', neutral: 'محايد', negative: 'سلبي', mixed: 'مختلط' };
    const PRIO_LABEL = { high: 'عالي', medium: 'متوسط', low: 'منخفض' };

    // ---------- Tabs ----------
    const tabs = document.getElementById('riTabs');
    tabs.addEventListener('click', function (e) {
        const btn = e.target.closest('.ri-tab');
        if (!btn) return;
        tabs.querySelectorAll('.ri-tab').forEach(t => { t.classList.remove('active'); t.setAttribute('aria-selected', 'false'); });
        btn.classList.add('active');
        btn.setAttribute('aria-selected', 'true');
        const panel = btn.dataset.panel;
        document.querySelectorAll('.ri-panel').forEach(p => p.classList.remove('active'));
        document.getElementById('panel-' + panel).classList.add('active');
    });

    function websiteId() {
        return P.getCurrentWebsiteId() || '';
    }

    // ---------- Chart helpers ----------
    function destroyChart(key) {
        if (charts[key]) { charts[key].destroy(); delete charts[key]; }
    }
    function baseChartOptions() {
        return {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { labels: { color: '#8996AC', usePointStyle: true, boxWidth: 8, font: { size: 11 } } },
                tooltip: { backgroundColor: '#152238', borderColor: 'rgba(255,255,255,.1)', borderWidth: 1, titleColor: '#F2F4F8', bodyColor: '#8996AC' }
            },
            scales: {
                x: { ticks: { color: '#8996AC', maxTicksLimit: 8, font: { size: 10 } }, grid: { color: 'rgba(255,255,255,.04)' } },
                y: { ticks: { color: '#8996AC', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,.04)' } }
            }
        };
    }

    // ---------- Overview ----------
    async function loadOverview() {
        const res = await fetchJSON('/api/gbp/analytics?website_id=' + websiteId());
        if (!res.success) { toast(res.error || 'تعذر تحميل التحليلات', 'error'); return; }
        const d = res.data || {};
        const k = d.kpis || {};

        document.getElementById('kpiAvg').textContent = k.avg_rating ? k.avg_rating.toFixed(2) : '-';
        document.getElementById('kpiAvgSub').textContent = k.total_reviews + ' مراجعة';
        document.getElementById('kpiResponse').textContent = (k.response_rate || 0) + '%';
        document.getElementById('kpiResponseSub').textContent = k.responded ? k.responded + ' تم الرد' : 'لا توجد ردود بعد';
        document.getElementById('kpiFrt').textContent = k.avg_response_hours !== null && k.avg_response_hours !== undefined ? k.avg_response_hours + ' س' : '-';
        document.getElementById('kpiVelocity').textContent = (k.review_velocity_per_day_30d || 0).toFixed(2);
        document.getElementById('kpiVelocitySub').textContent = k.new_reviews_30d + ' جديدة في آخر 30 يوم';

        const trend = d.trends && d.trends.rating_trend ? d.trends.rating_trend : [];
        const vel = d.trends && d.trends.velocity ? d.trends.velocity : [];
        const stars = d.distribution || [];
        const sent = d.sentiment || {};

        // rating trend line
        destroyChart('rating');
        const ratingCtx = document.getElementById('chRating');
        if (ratingCtx && trend.length) {
            charts.rating = new Chart(ratingCtx, {
                type: 'line',
                data: {
                    labels: trend.map(p => p.date),
                    datasets: [{
                        label: 'متوسط التقييم',
                        data: trend.map(p => p.avg_rating),
                        borderColor: '#EFB05E',
                        backgroundColor: 'rgba(239,176,94,.12)',
                        fill: true, tension: .35, pointRadius: 2, pointHoverRadius: 5
                    }]
                },
                options: Object.assign(baseChartOptions(), { scales: { x: { ...baseChartOptions().scales.x }, y: { ...baseChartOptions().scales.y, min: 0, max: 5 } } })
            });
        } else if (ratingCtx) { ratingCtx.parentElement.innerHTML = '<div class="ri-empty">لا توجد بيانات تقييم بعد</div>'; }

        // velocity bar
        destroyChart('velocity');
        const velCtx = document.getElementById('chVelocity');
        if (velCtx && vel.length) {
            charts.velocity = new Chart(velCtx, {
                type: 'bar',
                data: {
                    labels: vel.map(p => p.week_start),
                    datasets: [{
                        label: 'مراجعات جديدة',
                        data: vel.map(p => p.new_reviews),
                        backgroundColor: 'rgba(78,205,196,.6)', borderColor: '#4ECDC4', borderWidth: 1, borderRadius: 6
                    }]
                },
                options: Object.assign(baseChartOptions(), { scales: { x: { ...baseChartOptions().scales.x, grid: { display: false } }, y: { ...baseChartOptions().scales.y, beginAtZero: true, ticks: { precision: 0 } } } })
            });
        } else if (velCtx) { velCtx.parentElement.innerHTML = '<div class="ri-empty">لا توجد مراجعات في هذه الفترة</div>'; }

        // star distribution horizontal bar
        destroyChart('stars');
        const starsCtx = document.getElementById('chStars');
        if (starsCtx && stars.length) {
            const starColors = { 5: '#4ECDC4', 4: '#69c86f', 3: '#EFB05E', 2: '#e88a4a', 1: '#FF6B5B' };
            charts.stars = new Chart(starsCtx, {
                type: 'bar',
                data: {
                    labels: stars.map(s => s.stars + ' نجوم'),
                    datasets: [{ label: 'العدد', data: stars.map(s => s.count), backgroundColor: stars.map(s => starColors[s.stars] || '#8996AC'), borderRadius: 6 }]
                },
                options: Object.assign(baseChartOptions(), { indexAxis: 'y', scales: { x: { ...baseChartOptions().scales.x, beginAtZero: true, ticks: { precision: 0 } }, y: { ...baseChartOptions().scales.y, grid: { display: false } } } })
            });
        } else if (starsCtx) { starsCtx.parentElement.innerHTML = '<div class="ri-empty">لا توجد تقييمات بعد</div>'; }

        // sentiment donut
        destroyChart('sentiment');
        const sentCtx = document.getElementById('chSentiment');
        const totalAnalyzed = sent.total_analyzed || 0;
        document.getElementById('sentimentTotal').textContent = totalAnalyzed ? totalAnalyzed + ' مراجعة محلّلة' : '-';
        if (sentCtx && sent.labels && totalAnalyzed) {
            const lbl = sent.labels;
            const colors = { positive: '#4ECDC4', neutral: '#8996AC', negative: '#FF6B5B', mixed: '#EFB05E' };
            const data = ['positive', 'neutral', 'negative', 'mixed'].map(k => ({ label: SENTIMENT_LABEL[k], value: lbl[k] ? lbl[k].count : 0 }));
            charts.sentiment = new Chart(sentCtx, {
                type: 'doughnut',
                data: { labels: data.map(x => x.label), datasets: [{ data: data.map(x => x.value), backgroundColor: ['positive','neutral','negative','mixed'].map(k => colors[k]), borderWidth: 0 }] },
                options: Object.assign(baseChartOptions(), { cutout: '62%', plugins: { legend: { position: 'bottom' } } })
            });
        } else if (sentCtx) { sentCtx.parentElement.innerHTML = '<div class="ri-empty">لا توجد مشاعر محلّلة بعد</div>'; }
    }

    // ---------- Risk ----------
    async function loadRisk() {
        const res = await fetchJSON('/api/gbp/risk-signals?website_id=' + websiteId());
        const box = document.getElementById('riskSignals');
        const badge = document.getElementById('riskBadge');
        const summary = document.getElementById('riskSummary');
        if (!res.success) { summary.textContent = res.error || 'تعذر فحص المخاطر'; box.innerHTML = ''; return; }

        const d = res.data || {};
        const level = d.risk_level || 'low';
        const levelAr = { low: 'منخفض', medium: 'متوسط', high: 'مرتفع' };
        badge.className = 'ri-risk-badge ' + level;
        document.getElementById('riskLevelText').textContent = 'مستوى الخطر: ' + (levelAr[level] || level);
        summary.textContent = d.active_signals + ' إشارة نشطة - ' + (level === 'low' ? 'كل شيء تحت السيطرة' : 'فيها حاجة محتاجة متابعة');

        const signals = d.signals || [];
        if (!signals.length) {
            box.innerHTML = '<div class="ri-empty"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m8 12 3 3 5-6"/></svg>لا توجد إشارات خطر نشطة</div>';
            return;
        }
        box.innerHTML = signals.map(s => `
            <div class="ri-signal ${level}">
                <span class="ri-signal-dot"></span>
                <div class="ri-signal-body">${esc(s)}</div>
            </div>`).join('');
    }

    // ---------- Market (SOV + Competitors) ----------
    function sovMetric(v, suffix) {
        return v !== null && v !== undefined && v !== '' ? v + (suffix || '') : '-';
    }
    async function loadMarket() {
        const sovRes = await fetchJSON('/api/gbp/share-of-voice?website_id=' + websiteId());
        const compRes = await fetchJSON('/api/gbp/competitors?website_id=' + websiteId());
        const sovBody = document.getElementById('sovBody');
        const compBody = document.getElementById('compBody');

        if (!sovRes.success) {
            document.getElementById('sovMarketSize').textContent = '';
            sovBody.innerHTML = '<div class="ri-empty">' + esc(sovRes.error || 'حصة الظهور غير متاحة') + '</div>';
        } else {
            const d = sovRes.data || {};
            document.getElementById('sovMarketSize').textContent = d.available ? (d.market_size + ' منافس في السوق المحلي') : '';
            if (!d.available) {
                sovBody.innerHTML = '<div class="ri-note">' + esc(d.error || 'يتطلب مفتاح Google Maps') + '</div>';
            } else {
                const sov = d.share_of_voice || {};
                const total = d.total_market_reviews || 0;
                const own = d.own || {};
                sovBody.innerHTML = `
                    <div class="ri-ranks" style="margin-bottom:14px;">
                        <div class="ri-rank"><div class="ri-rank-value">${sovMetric(sov.review_share_percent, '%')}</div><div class="ri-rank-label">حصة مراجعات السوق</div></div>
                        <div class="ri-rank"><div class="ri-rank-value">${sovMetric(sov.rating_rank)}</div><div class="ri-rank-label">الترتيب حسب التقييم</div></div>
                        <div class="ri-rank"><div class="ri-rank-value">${sovMetric(sov.review_count_rank)}</div><div class="ri-rank-label">الترتيب حسب عدد المراجعات</div></div>
                    </div>
                    <div class="ri-note">${esc(own.name || 'نشاطك')} بيحمل ${esc(own.review_count || 0)} مراجعة من أصل ${esc(total)} في السوق المحلي (${esc(own.avg_rating ? own.avg_rating.toFixed(2) : '-')} نجوم).</div>`;
            }
        }

        if (!compRes.success) {
            compBody.innerHTML = '<div class="ri-empty">' + esc(compRes.error || 'المقارنة التنافسية غير متاحة') + '</div>';
        } else {
            const d = compRes.data || {};
            if (!d.available) {
                compBody.innerHTML = '<div class="ri-note">' + esc(d.error || 'يتطلب مفتاح Google Maps') + '</div>';
            } else {
                const own = d.own || {};
                const sc = d.scorecard || {};
                const comps = d.competitors || [];
                const rows = comps.map((c, i) => {
                    const medal = i === 0 ? '<span class="ri-medal gold">1</span>' : i === 1 ? '<span class="ri-medal silver">2</span>' : i === 2 ? '<span class="ri-medal bronze">3</span>' : '<span class="ri-medal muted">' + (i + 1) + '</span>';
                    return `
                    <div class="ri-comp">
                        <div class="ri-comp-avatar">${esc((c.name || '?').substring(0, 1).toUpperCase())}</div>
                        <div>
                            <div class="ri-comp-name">${esc(c.name || 'غير معروف')}</div>
                            <div class="ri-comp-sub">${c.rating ? c.rating.toFixed(1) + ' نجوم' : 'بدون تقييم'} · ${esc(c.review_count || 0)} مراجعة</div>
                        </div>
                        <div class="ri-comp-metric">${medal}</div>
                    </div>`;
                }).join('');
                compBody.innerHTML = `
                    <div class="ri-note" style="margin-bottom:12px;">
                        ${esc(own.name || 'نشاطك')} · ${esc(own.avg_rating ? own.avg_rating.toFixed(2) : '-')} نجوم · ${esc(own.review_count || 0)} مراجعة
                        ${sc.rating_rank ? ' · ترتيبك في التقييم ' + sc.rating_rank : ''}
                        ${sc.rating_gap_vs_leader ? ' · فجوتك عن المتصدر ' + sc.rating_gap_vs_leader.toFixed(2) : ''}
                    </div>
                    ${rows || '<div class="ri-empty">لم يتم العثور على منافسين قريبين</div>'}`;
            }
        }
    }

    // ---------- SEO Audit ----------
    function renderSeoSection(s) {
        if (!s) return '';
        const pct = s.score || 0;
        const color = pct >= 80 ? 'var(--panel-success)' : pct >= 50 ? 'var(--panel-warning)' : 'var(--panel-danger)';
        return `
        <div class="ri-score-sec">
            <div class="ri-score-sec-head"><span>${esc(s.label || '')}</span><span style="color:${color};font-weight:800;">${pct}%</span></div>
            <div class="ri-bar"><i style="width:${pct}%;background:${color};"></i></div>
        </div>`;
    }
    async function loadSeo() {
        const res = await fetchJSON('/api/gbp/local-seo-audit?website_id=' + websiteId());
        const body = document.getElementById('seoBody');
        const avail = document.getElementById('seoAvailability');
        if (!res.success) { avail.textContent = ''; body.innerHTML = '<div class="ri-empty">' + esc(res.error || 'التدقيق غير متاح') + '</div>'; return; }

        const d = res.data || {};
        avail.textContent = d.available ? 'بيانات حية من Google Places' : 'مفتاح Google Maps غير مضبوط';
        const score = d.score || 0;
        const sections = d.sections || {};
        const recos = d.recommendations || [];

        body.innerHTML = `
        <div class="ri-score-wrap">
            <div class="ri-score-ring" style="--ri-progress:${score};">
                <div>
                    <div class="ri-score-num">${score}</div>
                    <div class="ri-score-cap">من 100</div>
                </div>
            </div>
            <div class="ri-score-sections">
                ${renderSeoSection(sections.profile)}
                ${renderSeoSection(sections.reputation)}
                ${renderSeoSection(sections.visibility)}
                ${renderSeoSection(sections.nap)}
            </div>
        </div>
        ${recos.length ? `
        <div class="ri-card-title" style="margin-top:22px;margin-bottom:10px;">توصيات مرتبة بالأولوية</div>
        ${recos.map(r => `
            <div class="ri-reco">
                <span class="ri-reco-prio ${esc(r.priority || 'low')}">${esc(PRIO_LABEL[r.priority] || r.priority)}</span>
                <div class="ri-reco-body">
                    <div class="ri-reco-title">${esc(r.title || '')}</div>
                    <div class="ri-reco-detail">${esc(r.detail || '')}</div>
                </div>
            </div>`).join('')}
        ` : '<div class="ri-empty" style="margin-top:16px;">لا توجد توصيات حاليًا</div>'}`;
    }

    // ---------- Reply Rules ----------
    async function loadRules() {
        const res = await fetchJSON('/api/gbp/reply-rules?website_id=' + websiteId());
        const tbody = document.querySelector('#rulesTable tbody');
        if (!res.success) { tbody.innerHTML = '<tr><td colspan="6"><div class="ri-empty">' + esc(res.error || 'تعذر تحميل القواعد') + '</div></td></tr>'; return; }

        const rules = res.data.rules || [];
        if (!rules.length) {
            tbody.innerHTML = '<tr><td colspan="6"><div class="ri-empty"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h10"/></svg>لا توجد قواعد بعد — أنشئ أول قاعدة رد تلقائي</div></td></tr>';
            return;
        }

        tbody.innerHTML = rules.map(r => {
            const trigger = r.trigger_type === 'rating_range'
                ? 'تقييم ' + r.rating_min + ' إلى ' + r.rating_max
                : 'مشاعر: ' + (SENTIMENT_LABEL[r.sentiment_label] || r.sentiment_label);
            return `
            <tr>
                <td><div style="font-weight:700;color:var(--panel-text);">${esc(r.name)}</div>
                    <div style="font-size:11px;color:var(--panel-text-muted);margin-top:2px;">${esc(trigger)}</div></td>
                <td><span class="ri-pill ${r.trigger_type === 'rating_range' ? 'amber' : 'blue'}">${esc(TRIGGER_LABEL[r.trigger_type])}</span></td>
                <td><span class="ri-pill teal">${esc(ACTION_LABEL[r.action] || r.action)}</span>
                    <div style="font-size:11px;color:var(--panel-text-muted);margin-top:3px;">${esc(MODE_LABEL[r.reply_mode] || r.reply_mode)}</div></td>
                <td><span class="ri-pill gray">${esc(r.priority)}</span></td>
                <td><button type="button" class="ri-toggle ${r.enabled == 1 ? 'on' : ''}" data-id="${esc(r.id)}" data-enabled="${esc(r.enabled)}" onclick="toggleRule(this)" aria-label="تبديل تفعيل القاعدة"></button></td>
                <td>
                    <button type="button" class="ri-btn ghost sm" onclick="editRule(${esc(r.id)})" aria-label="تعديل">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.8 2.8 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                    </button>
                    <button type="button" class="ri-btn danger sm" onclick="deleteRule(${esc(r.id)})" aria-label="حذف">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                    </button>
                </td>
            </tr>`;
        }).join('');
    }

    window.toggleRule = async function (btn) {
        const id = btn.dataset.id;
        const enabled = btn.dataset.enabled == 1 ? 0 : 1;
        const res = await fetchJSON('/api/gbp/reply-rules/' + id, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ enabled: enabled })
        });
        if (res.success) { toast(enabled ? 'تم تفعيل القاعدة' : 'تم إيقاف القاعدة', 'success'); loadRules(); }
        else { toast(res.error || 'تعذر التحديث', 'error'); }
    };

    window.toggleRuleTrigger = function () {
        const isRating = document.getElementById('ruleTrigger').value === 'rating_range';
        document.getElementById('ratingRangeRow').style.display = isRating ? '' : 'none';
        document.getElementById('sentimentRow').style.display = isRating ? 'none' : '';
    };
    window.toggleRuleMode = function () {
        document.getElementById('customReplyRow').style.display = document.getElementById('ruleMode').value === 'custom' ? '' : 'none';
    };

    function resetRuleForm() {
        document.getElementById('ruleId').value = '';
        document.getElementById('ruleName').value = '';
        document.getElementById('ruleTrigger').value = 'rating_range';
        document.getElementById('ruleMin').value = '4';
        document.getElementById('ruleMax').value = '5';
        document.getElementById('ruleSentiment').value = 'positive';
        document.getElementById('ruleAction').value = 'auto_reply';
        document.getElementById('ruleMode').value = 'ai';
        document.getElementById('ruleCustom').value = '';
        document.getElementById('rulePriority').value = '100';
        toggleRuleTrigger();
        toggleRuleMode();
    }
    window.openRuleModal = function () {
        resetRuleForm();
        document.getElementById('ruleModalTitle').textContent = 'قاعدة رد جديدة';
        P.openModal('ruleModal');
    };
    window.editRule = async function (id) {
        const res = await fetchJSON('/api/gbp/reply-rules?website_id=' + websiteId());
        if (!res.success) return;
        const rule = (res.data.rules || []).find(r => String(r.id) === String(id));
        if (!rule) { toast('القاعدة غير موجودة', 'error'); return; }
        document.getElementById('ruleId').value = rule.id;
        document.getElementById('ruleName').value = rule.name;
        document.getElementById('ruleTrigger').value = rule.trigger_type;
        document.getElementById('ruleMin').value = rule.rating_min || '';
        document.getElementById('ruleMax').value = rule.rating_max || '';
        document.getElementById('ruleSentiment').value = rule.sentiment_label || 'positive';
        document.getElementById('ruleAction').value = rule.action;
        document.getElementById('ruleMode').value = rule.reply_mode;
        document.getElementById('ruleCustom').value = rule.custom_reply || '';
        document.getElementById('rulePriority').value = rule.priority;
        toggleRuleTrigger();
        toggleRuleMode();
        document.getElementById('ruleModalTitle').textContent = 'تعديل القاعدة';
        P.openModal('ruleModal');
    };
    window.saveRule = async function () {
        const id = document.getElementById('ruleId').value;
        const trigger = document.getElementById('ruleTrigger').value;
        const payload = {
            name: document.getElementById('ruleName').value.trim(),
            trigger_type: trigger,
            action: document.getElementById('ruleAction').value,
            reply_mode: document.getElementById('ruleMode').value,
            priority: parseInt(document.getElementById('rulePriority').value, 10) || 100
        };
        if (trigger === 'rating_range') {
            payload.rating_min = parseFloat(document.getElementById('ruleMin').value);
            payload.rating_max = parseFloat(document.getElementById('ruleMax').value);
        } else {
            payload.sentiment_label = document.getElementById('ruleSentiment').value;
        }
        if (payload.reply_mode === 'custom') {
            payload.custom_reply = document.getElementById('ruleCustom').value.trim();
        }

        const url = id ? '/api/gbp/reply-rules/' + id : '/api/gbp/reply-rules?website_id=' + websiteId();
        const res = await fetchJSON(url, {
            method: id ? 'PUT' : 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        if (res.success) { toast(id ? 'تم تحديث القاعدة' : 'تم إنشاء القاعدة', 'success'); P.closeModal('ruleModal'); loadRules(); }
        else { toast(res.error || 'تعذر الحفظ', 'error'); }
    };
    window.deleteRule = async function (id) {
        if (!confirm('متأكد من حذف هذه القاعدة؟')) return;
        const res = await fetchJSON('/api/gbp/reply-rules/' + id, { method: 'DELETE' });
        if (res.success) { toast('تم حذف القاعدة', 'success'); loadRules(); }
        else { toast(res.error || 'تعذر الحذف', 'error'); }
    };

    // ---------- Loader ----------
    function loadAll() {
        loadOverview();
        loadRisk();
        loadMarket();
        loadSeo();
        loadRules();
    }

    // إعادة تحميل عند تغيير الموقع المحدد من الشريط العلوي
    window.addEventListener('tourfecto:website-changed', function () {
        Object.keys(charts).forEach(k => destroyChart(k));
        loadAll();
    });

    loadAll();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage(
            'reputation_intelligence',
            $this->tr('sidebar.reputation_intelligence'),
            'مركز تحليلات السمعة: مؤشرات، مخاطر، سوق، تدقيق SEO، وقواعد رد تلقائي',
            $body,
            $script
        );
        exit;
    }

    /**
     * شاشة "اربط Google Business" لو مفيش ولا موقع مربوط (null = مربوط).
     */
    public static function connectGateHtml(int $userId): ?string
    {
        try {
            $db = Database::getInstance();
            $linked = $db->query("SELECT 1 FROM platform_connections WHERE user_id = ? AND platform = 'google_business' AND status = 'connected' LIMIT 1", [$userId]);
            if ($linked) {
                return null;
            }
            // اتربط بس المزامنة واقفة بسبب إعداد من ناحية المنصة (مش حاجة العميل يعملها)
            $pending = $db->query("SELECT last_error FROM platform_connections WHERE user_id = ? AND platform = 'google_business' AND status = 'error' ORDER BY id DESC LIMIT 1", [$userId]);
            if ($pending && preg_match('/Google Cloud|Cloud Console|SERVICE_DISABLED|NOT_SUPPORTED|has not been used|is disabled/i', (string) $pending[0]['last_error'])) {
                return '<div class="p-card" style="max-width:720px;margin:20px auto;text-align:center;padding:36px 24px">'
                    . '<div style="font-size:42px;margin-bottom:8px">⏳</div>'
                    . '<h2 style="margin:0 0 10px">حسابك على Google Business اتربط</h2>'
                    . '<p class="p-cell-muted" style="line-height:1.9;margin:0 auto;max-width:520px">المزامنة مع Google بتتجهز من فريق المنصة. مش محتاج تعمل حاجة - أول ما تجهز التقييمات والمؤشرات هتظهر هنا لوحدها.</p>'
                    . '</div>';
            }
            $sites = $db->query('SELECT id, main_url FROM websites WHERE user_id = ? ORDER BY id DESC LIMIT 10', [$userId]);
        } catch (Throwable $e) {
            return null;
        }
        $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $buttons = '';
        foreach ($sites as $site) {
            $host = preg_replace('#^https?://(www\.)?#i', '', rtrim((string) $site['main_url'], '/'));
            $buttons .= '<a class="p-btn primary" href="/reputation/connect/google/' . (int) $site['id'] . '">ربط Google Business لـ ' . $e($host) . '</a> ';
        }
        if ($buttons === '') {
            $buttons = '<a class="p-btn primary" href="/websites">أضف موقعك الأول</a>';
        }
        return '<div class="p-card" style="max-width:720px;margin:20px auto;text-align:center;padding:36px 24px">'
            . '<div style="font-size:42px;margin-bottom:8px">📍</div>'
            . '<h2 style="margin:0 0 10px">اربط حساب Google Business</h2>'
            . '<p class="p-cell-muted" style="line-height:1.9;margin:0 auto 18px;max-width:520px">بعد الربط المنصة هتسحب تقييماتك من Google وترد عليها، وتوريك متوسط التقييم وسرعة الرد والمخاطر ومقارنتك بالمنافسين - كله لوحده.</p>'
            . '<div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">' . $buttons . '</div>'
            . '<p class="p-cell-muted" style="font-size:12px;margin-top:16px">هتختار حساب Google اللي عليه نشاطك وتوافق - مش محتاجين كلمة السر.</p>'
            . '</div>';
    }

    /**
     * تصحيح: "مش مربوط" مش عطل سيرفر - كان بيرجع 502 فالمتصفح يعتبره خطأ خادم.
     * 409 = حالة الحساب (لازم يربط الأول)، و502 بس لفشل حقيقي من Google.
     */
    private function gbpErrorStatus($result): int
    {
        $err = is_array($result) ? (string) ($result['error'] ?? '') : '';
        if (!empty($result['not_connected']) || stripos($err, 'not connected') !== false || mb_strpos($err, 'اربط') !== false) {
            return 409;
        }
        return 502;
    }
}

<?php

/**
 * Tourfecto - Onboarding Controller
 * Phase 16 + Phase 18 + Phase 19.
 *
 * Phase 16: الـWizard بيلخد كل بيانات الخطوات السبعة في نداء واحد وبيربطهم
 * فعليًا بكل الـAgents الموجودة (مش بس بيحفظ بيانات فاضية):
 *
 *   بيانات الأعمال → إنشاء/تحديث Website → إضافة المنافسين فعليًا لنظام
 *   Competitor Intelligence (Phase 7) → تشغيل تدقيق SEO حقيقي تلقائيًا
 *   (Website Optimizer - Phase 5) → محاولة توليد خطة نمو 90 يوم
 *   (SEO Strategy Agent - Phase 14).
 *
 * Phase 18: واجهة الـWizard الكاملة (7 خطوات + شاشة "بنجهّز حسابك" + شاشة
 * النتائج) بنفس الـTheme البصري لصفحات auth.
 *
 * Phase 19: تحويل الشغل الثقيلي (Audit + خطة النمو) إلى طابور خلفية
 * (OnboardingAuditJob) عشان الـWizard يرجّع فورًا والواجهة بتعمل polling
 * على /api/onboarding/status - مع fallback تلقائي للتنفيذ المتزامن لو
 * الطابور مش متاح. كمان: منع تكرار المواقع (نفس الـURL بيحدّث الموقع
 * القديم بدل ما يعمل نسخة تانية)، تطبيع الروابط، وتوسيع /api/onboarding/status
 * بحالة الـAudit وخطة النمو والمنافسين.
 *
 * Phase 20 (Competitive): تحسينات تنافسية مقارنة بأفضل منصات الإعداد
 * العالمية (Ubersuggest/SEMrush/Ahrefs - Site Audit، Wix/Shopify - Smart
 * Detection، Userflow/Appcues - أونبوردينج UX):
 *  - /api/onboarding/preview: كشف تلقائي لاسم النشاط من الموقع نفسه عند
 *    كتابة الـURL (SSRF-protected عبر WebsiteSnapshotFetcher) - "لحظة
 *    الـwow" زي ما بيحصل في Shopify/Wix.
 *  - Industry Benchmark: مقارنة درجة الـSEO بمتوسط نفس النشاط (لو فيه
 *    بيانات كفاية، والا نقع على baseline 55) بدل رقم ثابت - زي Ubersuggest.
 *  - Quick Wins: أهم 3 ملاحظات فاشلة بترتيب الخطورة يتعرضوا فورًا بعد
 *    التدقيق كإجراءات أولوية قابلة للتنفيذ - زي prioritized fixes في
 *    SEMrush/Ahrefs.
 *  - Competitor Snapshots: لقطة حقيقية للصفحة الرئيسية لكل منافس
 *    (العنوان + الوصف + نوع الـCMS) تُعرض فورًا بعد الإعداد.
 *
 * @version 2.0.0
 */
class OnboardingController extends Controller
{
    /**
     * أقصى طول لكل حقل نصي (بالأحرف مش بالبايت). الحدود مطابقة لأعمدة
     * قاعدة البيانات: company_name VARCHAR(255)، main_url ≤ 500، والمحتوى
     * النصي الطويل TEXT. مشتركة بين complete() و saveDraft() عشان المسودة
     * متخالفش مع الـAPI النهائي.
     */
    private const FIELD_MAX_LENGTHS = [
        'business_name' => 255,
        'main_url' => 500,
        'target_customers' => 2000,
        'main_services' => 2000,
    ];

    /**
     * POST /api/onboarding/complete
     * { business_name, main_url, industry, target_country, target_language?,
     *   target_customers, main_services, competitors: [{name, domain}, ...] (حتى 3) }
     *
     * بيرجّع:
     *   - processing = true: الشغل بيشتغل في الخلفية (طابور) - الواجهة
     *     بتعمل polling على /api/onboarding/status.
     *   - processing = false: كل حاجة اتخلصت في نفس الـ request (fallback
     *     لما الطابور مش متاح) - الواجهة بتعرض النتيجة مباشرة.
     */
    public function complete(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $userId = (int) $this->user['id'];

        $this->recordEvent('onboarding.submitted', ['industry' => $this->get('industry'), 'competitors' => count((array) $this->get('competitors', []))]);

        if (!$this->validate(['main_url' => 'required', 'business_name' => 'required'])) {
            return $this->error($this->tr('onboarding.api.invalid_data'), 422, $this->getErrors());
        }

        // طول أقصى لكل حقل نصي (بالأحرف مش بالبايت - عربي UTF-8 = 3 بايت لكل
        // حرف). الإرسال الأطول من حدود أعمدة قاعدة البيانات هيكسر الـDB أو
        // يخزّن بيانات ضخمة بلا داعي.
        foreach (self::FIELD_MAX_LENGTHS as $f => $max) {
            $v = $this->get($f);
            if (is_string($v) && mb_strlen($v) > $max) {
                return $this->error($this->tr('onboarding.api.invalid_data'), 422);
            }
        }

        $mainUrl = $this->canonicalizeUrl((string) $this->get('main_url'));
        if ($mainUrl === null) {
            return $this->error($this->tr('onboarding.api.invalid_url'), 422);
        }

        // Phase 20 - SSRF: الرابط ده هيعمل عليه السيرفر HTTP requests (Audit)
        // فعشان كده لازم يتأكد إنه بيرجع لـ Host عام مش Internal/Private
        // (169.254.169.254، 192.168.x.x، localhost...) - نفس الحماية الموجودة
        // في أضافة المنافسين. المستخدم العادي ميعرفش يفرّق، فالخطأ المعروض
        // ليه هو نفس "invalid_url".
        if (class_exists('SsrfGuard') && !SsrfGuard::isSafe($mainUrl)) {
            return $this->error($this->tr('onboarding.api.invalid_url'), 422);
        }

        $competitorsInput = $this->get('competitors', []);
        if (!is_array($competitorsInput)) {
            $competitorsInput = [];
        }
        $competitorsInput = array_slice($competitorsInput, 0, 3);

        $competitors = [];
        foreach ($competitorsInput as $c) {
            if (!is_array($c) || empty($c['domain'])) {
                continue;
            }
            $domain = $this->canonicalizeUrl((string) $c['domain']);
            $domain = $domain ?? trim((string) $c['domain']);
            // SSRF: نفس منطق الحماية - نستبعد المنافسين اللي بيشاوروا على
            // عناوين داخلية بدل ما نكسّر كل الـWizard على منافس واحد.
            if (class_exists('SsrfGuard') && !SsrfGuard::isSafe($domain)) {
                continue;
            }
            $competitors[] = [
                'name' => trim((string) ($c['name'] ?? '')),
                'domain' => $domain,
            ];
        }

        try {
            $websiteId = $this->findOrCreateWebsite($userId, $mainUrl, $competitors);
            if (!$websiteId) {
                return $this->error($this->tr('onboarding.api.create_failed'), 500);
            }

            // بعد نجاح التسجيل نمسح مسودة السيرفر (استئناف تاني هيبدأ من جديد).
            try {
                $this->db->exec("DELETE FROM onboarding_drafts WHERE user_id = ?", [$userId]);
            } catch (Throwable $e) {
                // جدول المسودات لسه مش موجود - تجاهل بصمت
            }

            // Phase 20 - Rate limiting: لو في job شغال/معلّق أصلًا لنفس
            // الموقع، ما ندفعش تاني (الواجهة بتحط polling) بدل ما نكبّس
            // تدقيقات مكررة على نفس الموقع.
            $activeJobId = $this->activeOnboardingJobId($websiteId);

            // ============ Async أولًا (طابور الخلفية) ============
            $queue = new QueueManager();
            if ($queue->isReady()) {
                $jobId = $activeJobId ?: $queue->push(OnboardingAuditJob::class, [
                    'user_id' => $userId,
                    'website_id' => $websiteId,
                    'competitors' => $competitors,
                ]);

                if ($jobId) {
                    $this->log('Onboarding Started (background)', ['website_id' => $websiteId, 'job_id' => $jobId, 'reused' => (bool) $activeJobId]);

                    return $this->success([
                        'website_id' => $websiteId,
                        'processing' => true,
                        'website' => (new Website())->find($websiteId)->toArray(),
                    ], $this->tr('onboarding.api.started'), 202);
                }
            }

            // ============ Fallback: تنفيذ متزامن ============
            // Rate limiting: لو فيه تدقيق اكتمل للسايت ده خلال آخر 10 دقايق
            // نعيد النتيجة دي بدل ما نشغّل تدقيق ثقيل تاني في نفس الـrequest.
            $recentAudit = $this->recentCompletedAudit($websiteId, 10);
            if ($recentAudit !== null) {
                $this->log('Onboarding Reused Recent Audit', ['website_id' => $websiteId, 'audit_id' => $recentAudit['id']]);
                return $this->success([
                    'website_id' => $websiteId,
                    'processing' => false,
                    'website' => (new Website())->find($websiteId)->toArray(),
                    'audit_reused' => true,
                    'audit' => $recentAudit['audit_data'],
                    'competitors_count' => $this->websiteCompetitorsCount($websiteId),
                    'growth_plan_tasks' => $this->websiteGrowthPlan($websiteId)['tasks'],
                    'ready' => true,
                ], $this->tr('onboarding.result.title'));
            }

            $result = $this->runSetup([
                'user_id' => $userId,
                'website_id' => $websiteId,
                'competitors' => $competitors,
            ]);

            $this->log('Onboarding Completed', ['website_id' => $websiteId, 'competitors_added' => count($result['competitors_added'] ?? [])]);

            return $this->success(array_merge([
                'website_id' => $websiteId,
                'processing' => false,
                'website' => (new Website())->find($websiteId)->toArray(),
            ], $result), $this->tr('onboarding.result.title'));

        } catch (Exception $e) {
            Logger::error('Onboarding Complete Error', ['message' => $e->getMessage()]);
            $debugMsg = (defined('APP_DEBUG') && APP_DEBUG) ? $this->tr('onboarding.api.complete_failed') . ': ' . $e->getMessage() : $this->tr('onboarding.api.complete_failed');
            return $this->error($debugMsg, 500);
        }
    }

    /**
     * Pipeline مشترك بين المسار المتزامن (fallback) وطابور الخلفية
     * (OnboardingAuditJob): المنافسين → Audit → خطة النمو.
     * بيستقبل بيانات الموقع والمنافسين ويعيد نتيجة موحّدة للواجهة.
     *
     * @param array $payload ['user_id','website_id','competitors']
     * @return array
     */
    public function runSetup(array $payload = []): array
    {
        $userId = (int) ($payload['user_id'] ?? 0);
        $websiteId = (int) ($payload['website_id'] ?? 0);
        $competitors = (array) ($payload['competitors'] ?? []);

        // ميزانية زمنية للإعداد كله: الخطوات الاختيارية (الخطة بالذكاء الاصطناعي، لقطات المنافسين) بتتخطّى لو الوقت خلص
        // بدل ما المستخدم يفضل مستني، والنتيجة الأساسية (التدقيق) بتظهر في كل الأحوال.
        @set_time_limit(300);
        $setupStartedAt = microtime(true);

        // ============ خطوة 7: المنافسين - تسجيل حقيقي (بدون تكرار) ============
        $addedCompetitors = [];
        if (class_exists('CompetitorAnalysisService')) {
            $competitorService = new CompetitorAnalysisService();
            foreach ($competitors as $c) {
                if (empty($c['domain'])) {
                    continue;
                }
                try {
                    $exists = $this->db->query(
                        "SELECT id FROM competitors WHERE user_id = ? AND website_id = ? AND competitor_domain = ? LIMIT 1",
                        [$userId, $websiteId, (string) $c['domain']]
                    );
                    if (!empty($exists)) {
                        $addedCompetitors[] = ['id' => (int) $exists[0]['id'], 'competitor_domain' => (string) $c['domain'], 'duplicate' => true];
                        continue;
                    }
                    $comp = $competitorService->addCompetitor($userId, $websiteId, (string) ($c['name'] ?: $c['domain']), (string) $c['domain']);
                    $addedCompetitors[] = $comp->toArray();
                } catch (Exception $e) {
                    // منافس واحد فشل مايوقفش باقي الـWizard
                }
            }
        }

        // ============ "ابدأ AI Audit" - تدقيق SEO حقيقي تلقائيًا (Phase 5) ============
        $auditResult = null;
        if (class_exists('WebsiteOptimizerController')) {
            $originalGet = $_GET;
            $_GET = ['website_id' => $websiteId];
            try {
                $optimizer = new WebsiteOptimizerController();
                $auditResult = $optimizer->runAudit([]);
            } catch (Exception $e) {
                Logger::error('Onboarding Auto-Audit Error', ['message' => $e->getMessage()]);
            } finally {
                $_GET = $originalGet;
            }
        }

        // ============ "خطتك جاهزة" - محاولة توليد خطة 90 يوم (Phase 14، اختياري) ============
        $strategyResult = null;
        if ((microtime(true) - $setupStartedAt) > 80) {
            Logger::warning('Onboarding: growth plan skipped (time budget)', ['website_id' => $websiteId, 'elapsed' => round(microtime(true) - $setupStartedAt)]);
        } elseif (($auditResult['success'] ?? false) && class_exists('SeoStrategyController')) {
            $originalGet2 = $_GET;
            $_GET = ['website_id' => $websiteId];
            try {
                $strategyController = new SeoStrategyController();
                $strategyGenResult = $strategyController->generate([]);
                if ($strategyGenResult['success'] ?? false) {
                    $strategyResult = $strategyGenResult['data'];
                }
            } catch (Exception $e) {
                // خطة النمو "بونص" - فشلها (مثلاً رصيد AI منتهي) ميعطلش اكتمال الـOnboarding نفسه
                Logger::error('Onboarding Auto-Strategy Error', ['message' => $e->getMessage()]);
            } finally {
                $_GET = $originalGet2;
            }
        }

        $auditData = $auditResult['data'] ?? null;

        // ============ لقطات المنافسين الفورية (Phase 20) ============
        // جلب حقيقي SSRF-protected للصفحة الرئيسية لكل منافس، عشان الواجهة
        // تعرض "ماذا وجدنا عن منافسيك" مباشرة بعد الإعداد. أي فشل في جلب
        // منافس واحد مايوقفش باقي الـSetup.
        if ((microtime(true) - $setupStartedAt) <= 150) {
            $this->saveCompetitorSnapshots($userId, $websiteId, $addedCompetitors);
        } else {
            Logger::warning('Onboarding: competitor snapshots skipped (time budget)', ['website_id' => $websiteId]);
        }

        // ============ Quick Wins: أهم الملاحظات الفاشلة (Phase 20) ============
        // من نتائج الـAudit نفسها - أولوية عالية قابلة للتنفيذ، زي
        // prioritized fixes في Ubersuggest/SEMrush/Ahrefs.
        $quickWins = $this->deriveQuickWins($auditResult);

        return [
            'competitors_added' => $addedCompetitors,
            'audit' => $auditResult['success'] ?? false ? $auditData : null,
            'audit_error' => $auditResult['success'] ?? false ? null : ($auditResult['error'] ?? null),
            'growth_plan' => $strategyResult,
            'industry_benchmark' => $this->industryBenchmark($websiteId),
            'quick_wins' => $quickWins,
            'competitors' => $this->competitorsWithSnapshots($websiteId),
            'ready' => true,
        ];
    }

    /**
     * Phase 20 - Whitelist الـ industry: أي قيمة خارج القائمة المعروفة
     * بتتحول لـ 'other' بدل ما تحفظ أي حاجة من الـClient في الداتابيز.
     */
    private function sanitizeIndustry(string $industry): string
    {
        $allowed = ['tourism', 'tours', 'hotel', 'travel_agency', 'other'];
        return in_array($industry, $allowed, true) ? $industry : 'other';
    }

    /**
     * GET /api/onboarding/preview?url=https://example.com
     * كشف تلقائي (Phase 20): جلب حقيقي آمن للصفحة العامة للموقع واستخراج
     * اسم النشاط من الـ<title> + وصف الـmeta + نوع الـCMS. الواجهة بتستخدم
     * ده عشان تقترح اسم الشركة/تعبّيه تلقائيًا فور ما العميل يكتب الـURL -
     * نفس "لحظة الـwow" في Shopify/Wix.
     */
    public function preview(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        // Rate limiting (Phase 20.1): حد 30 استعلام كشف في الدقيقة لكل مستخدم
        // - الحماية من إساءة استخدام النقطة (كل استعلام = طلب HTTP للسيرفر
        // المستهدف من عندنا)، مش منع لاستخدام الشرعي أثناء كتابة الـURL.
        try {
            if (class_exists('RateLimiter')) {
                $rl = new RateLimiter();
                if (!$rl->check('user:' . (int) ($this->user['id'] ?? 0), 'onboarding_preview', 30, 60)) {
                    return $this->error($this->tr('onboarding.api.rate_limited'), 429);
                }
            }
        } catch (Throwable $e) {
            // فشل الـRateLimiter نفسه مايقفلش الميزة - نكمل بحد أقصى بسيط
        }

        $url = $this->canonicalizeUrl((string) $this->get('url'));
        if ($url === null) {
            return $this->error($this->tr('onboarding.api.invalid_url'), 422);
        }

        if (!class_exists('WebsiteSnapshotFetcher')) {
            return $this->success(['detected' => false]);
        }

        try {
            $fetcher = new WebsiteSnapshotFetcher();
            $result = $fetcher->fetch($url);

            if (!$result['success']) {
                return $this->success([
                    'detected' => false,
                    'error' => $result['error'] ?? 'fetch_failed',
                ]);
            }

            $title = $result['title'] ?? null;

            return $this->success([
                'detected' => true,
                'name' => $this->detectBusinessName($title),
                'title' => $title,
                'description' => $result['meta_description'] ?? null,
                'cms' => isset($result['tech_signals']['cms_hint']) ? $result['tech_signals']['cms_hint'] : null,
                'http_status' => $result['http_status'] ?? null,
            ]);
        } catch (Exception $e) {
            Logger::error('Onboarding Preview Error', ['message' => $e->getMessage()]);
            return $this->success(['detected' => false]);
        }
    }

    /**
     * GET /api/onboarding/draft
     * استئناف المسودة المحفوظة على السيرفر (عبر الأجهزة). بترجع الـdraft
     * + أقصى خطوة وصلها المستخدم. لو مفيش مسودة => draft: null.
     */
    public function getDraft(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $userId = (int) $this->user['id'];

        try {
            $rows = $this->db->query(
                "SELECT draft, step, updated_at FROM onboarding_drafts WHERE user_id = ? LIMIT 1",
                [$userId]
            );
            if (empty($rows)) {
                return $this->success(['draft' => null, 'step' => 1]);
            }

            $raw = $rows[0]['draft'] ?? null;
            $draft = $raw ? json_decode((string) $raw, true) : null;
            if (!is_array($draft)) {
                $draft = [];
            }

            return $this->success([
                'draft' => $draft,
                'step' => (int) ($rows[0]['step'] ?? 1),
                'updated_at' => (string) ($rows[0]['updated_at'] ?? ''),
            ]);
        } catch (Throwable $e) {
            // الجدول لسه مش متعمل على السيرفر - الميزة اختيارية مش حاسمة
            return $this->success(['draft' => null, 'step' => 1]);
        }
    }

    /**
     * PUT /api/onboarding/draft
     * حفظ المسودة على السيرفر (upsert). بيحفظ كمان أقصى خطوة وصلها
     * المستخدم - وهي الأساس اللي لوحة الفونيل الإدارية بتحسب عليه
     * معدل التسرب (drop-off) لكل خطوة.
     */
    public function saveDraft(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $userId = (int) $this->user['id'];

        $rawDraft = $this->get('draft', []);
        if (!is_array($rawDraft)) {
            $rawDraft = [];
        }
        // منع ضخ حقول غير معروفة - نأخذ بس الحقول اللي الـWizard بيبعتها
        $allowed = ['business_name', 'main_url', 'industry', 'target_country', 'target_customers', 'main_services', 'competitors'];
        $draft = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $rawDraft)) {
                $draft[$key] = $rawDraft[$key];
            }
        }
        // طول أقصى لكل حقل نصي - نفس حدود complete() عشان المسودة متخالفش
        // مع الـAPI النهائي (وإلا المستخدم يحفظ مسودة صالحة ويفشل الإرسال).
        foreach (self::FIELD_MAX_LENGTHS as $f => $max) {
            if (isset($draft[$f]) && is_string($draft[$f]) && mb_strlen($draft[$f]) > $max) {
                return $this->error($this->tr('onboarding.api.invalid_data'), 422);
            }
        }
        // طول أقصى للحد من تخزين بيانات ضخمة
        $draftJson = json_encode($draft, JSON_UNESCAPED_UNICODE);
        if (strlen($draftJson) > 12000) {
            return $this->error($this->tr('onboarding.api.invalid_data'), 422);
        }

        $step = (int) $this->get('step', 1);
        $step = max(1, min(7, $step));

        try {
            $this->db->exec(
                "INSERT INTO onboarding_drafts (user_id, draft, step, updated_at)
                 VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE draft = VALUES(draft), step = GREATEST(step, VALUES(step)), updated_at = NOW()",
                [$userId, $draftJson, $step]
            );
            return $this->success(['saved' => true, 'step' => $step]);
        } catch (Throwable $e) {
            return $this->error($this->tr('onboarding.api.invalid_data'), 500);
        }
    }

    /**
     * DELETE /api/onboarding/draft
     * مسح المسودة بعد اكتمال الـOnboarding (أو إلغاء صريح).
     */
    public function clearDraft(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $userId = (int) $this->user['id'];

        try {
            $this->db->exec("DELETE FROM onboarding_drafts WHERE user_id = ?", [$userId]);
            return $this->success(['cleared' => true]);
        } catch (Throwable $e) {
            return $this->success(['cleared' => false]);
        }
    }

    /**
     * POST /api/onboarding/step
     * بيكّن المستخدم وصل لأقصى خطوة N (بيتخزن في مسودته). الـواجهة بتبعت
     * الأحداث دي بشكل متحفظ (أقصى خطوة بس، مش كل تنقّل) عشان لوحة
     * الفونيل الإدارية تعرض drop-off حقيقي لكل خطوة من غير إزعاج للـDB.
     */
    public function recordStep(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $userId = (int) $this->user['id'];

        $step = (int) $this->get('step', 1);
        $step = max(1, min(7, $step));

        try {
            $this->db->exec(
                "INSERT INTO onboarding_drafts (user_id, draft, step, updated_at)
                 VALUES (?, NULL, ?, NOW())
                 ON DUPLICATE KEY UPDATE step = GREATEST(step, VALUES(step)), updated_at = NOW()",
                [$userId, $step]
            );
            return $this->success(['ok' => true]);
        } catch (Throwable $e) {
            return $this->success(['ok' => false]);
        }
    }

    /**
     * بيانات الثقة (Social Proof): عدد النشاطات اللي خلصوا الـOnboarding
     * فعليًا على المنصة. بتتقرأ مباشرة من جدول websites - لو الجدول/العمود
     * لسه مش متعمل على السيرفر بنقع على رقم افتراضي متحفظ (بدل صفر).
     */
    private function trustStats(): array
    {
        try {
            $row = $this->db->query(
                "SELECT COUNT(*) AS c FROM websites WHERE onboarding_completed_at IS NOT NULL"
            );
            $completed = (int) ($row[0]['c'] ?? 0);

            $row2 = $this->db->query("SELECT COUNT(*) AS c FROM users");
            $users = (int) ($row2[0]['c'] ?? 0);

            return ['completed' => $completed, 'users' => $users];
        } catch (Throwable $e) {
            return ['completed' => 0, 'users' => 0];
        }
    }

    /**
     * تطبيع الرابط: بيضيف https:// لو مفيش scheme، بيخلي الـhost lowercase،
     * وبيشيل أي trailing slash. بيرجع null لو الرابط مش صالح خالص.
     */
    private function canonicalizeUrl(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (!preg_match('#^https?://#i', $raw)) {
            $raw = 'https://' . $raw;
        }

        $parts = parse_url($raw);
        if (!$parts || empty($parts['host'])) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = rtrim($parts['path'] ?? '', '/');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $scheme = strtolower($parts['scheme']) === 'http' && $port === ':80' ? 'http' : strtolower((string) $parts['scheme']);

        // ملاحظة مهمة (إصلاح 2026-08-15): من غير `?: '/'` - كان بيديلنا
        // رابط للـroot بـ trailing slash ('https://x.com/')، فكان منع
        // التكرار (Phase 19) بيفشل لو الموقع اتحفظ قبل كده من غير slash
        // (بيتنسج نسخة جديدة كل مرة). دلوقتي الرابط الطبيعي دايمًا من غير
        // trailing slash، والتطبيع في findOrCreateWebsite بيطابق الاتنين.
        if (!filter_var($scheme . '://' . $host . $port . $path, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $scheme . '://' . $host . $port . $path;
    }

    /**
     * إيجاد موقع بنفس الرابط لنفس المستخدم أو إنشاؤه. بيحدّث الموقع الموجود
     * بدل ما يعمل نسخة مكررة كل مرة العميل يعيد تشغيل الـWizard.
     */
    private function findOrCreateWebsite(int $userId, string $mainUrl, array $competitors): int
    {
        // IMPORTANT: The live production database can contain both the legacy
        // columns (main_url/company_name/industry/target_country/is_verified)
        // and the newer Business Control Center columns
        // (domain/brand_name/industry_niche/target_countries/is_active).
        // We must populate EVERY required column that exists instead of
        // mapping one logical field to only one physical column.
        // This also avoids INFORMATION_SCHEMA, which may be denied on shared hosting.
        $columns = [];
        try {
            $rows = $this->db->query("SHOW COLUMNS FROM `websites`");
            foreach ((array) $rows as $row) {
                $name = $row['Field'] ?? $row['COLUMN_NAME'] ?? null;
                if ($name) {
                    // أعمدة محسوبة (GENERATED ... VIRTUAL/STORED/PERSISTENT) ما ينفعش نكتب فيها:
                    // MariaDB بترفض بالخطأ 1906 وده كان بيوقّف "ابدأ التحليل" بعد آخر خطوة.
                    if (stripos((string) ($row['Extra'] ?? $row['EXTRA'] ?? ''), 'GENERATED') !== false
                        || stripos((string) ($row['Extra'] ?? $row['EXTRA'] ?? ''), 'VIRTUAL') !== false
                        || stripos((string) ($row['Extra'] ?? $row['EXTRA'] ?? ''), 'PERSISTENT') !== false) {
                        continue;
                    }
                    $columns[strtolower((string) $name)] = [
                        'name' => (string) $name,
                        'type' => strtolower((string) ($row['Type'] ?? '')),
                        'null' => strtoupper((string) ($row['Null'] ?? 'YES')) === 'YES',
                        'default' => $row['Default'] ?? null,
                    ];
                }
            }
        } catch (Throwable $e) {
            Logger::error('Onboarding websites schema inspection failed', ['message' => $e->getMessage()]);
            throw new Exception('Unable to inspect websites table schema');
        }

        if (empty($columns)) {
            throw new Exception('websites table schema is empty or unavailable');
        }

        $normUrl = rtrim($mainUrl, '/');
        $urlHost = (string) (parse_url($normUrl, PHP_URL_HOST) ?? '');
        $urlHost = strtolower($urlHost);
        $businessName = trim((string) $this->get('business_name'));
        $industry = $this->sanitizeIndustry((string) $this->get('industry', 'tourism'));
        $language = (string) $this->get('target_language', current_lang());
        $country = trim((string) $this->get('target_country'));
        $targetCustomers = $this->get('target_customers');
        $mainServices = $this->get('main_services');

        // Find an existing website using whichever URL column is actually present.
        $urlCandidates = ['main_url', 'domain', 'url', 'website_url', 'site_url'];
        $urlCol = null;
        foreach ($urlCandidates as $candidate) {
            if (isset($columns[$candidate])) {
                $urlCol = $columns[$candidate]['name'];
                break;
            }
        }

        $existingId = null;
        if ($urlCol !== null) {
            // The legacy main_url usually stores the full URL; domain usually
            // stores the host. Try both representations when both columns exist.
            if (isset($columns['main_url'])) {
                $rows = $this->db->query(
                    "SELECT id FROM `websites` WHERE user_id = ? AND TRIM(TRAILING '/' FROM `main_url`) = ? ORDER BY id DESC LIMIT 1",
                    [$userId, $normUrl]
                );
                if (!empty($rows)) {
                    $existingId = (int) $rows[0]['id'];
                }
            }
            if (!$existingId && isset($columns['domain']) && $urlHost !== '') {
                $rows = $this->db->query(
                    "SELECT id FROM `websites` WHERE user_id = ? AND `domain` = ? ORDER BY id DESC LIMIT 1",
                    [$userId, $urlHost]
                );
                if (!empty($rows)) {
                    $existingId = (int) $rows[0]['id'];
                }
            }
        }

        // Logical value -> all known physical columns. If a column exists,
        // populate it; this is intentional when legacy + new columns coexist.
        $logical = [
            'user_id' => $userId,
            'main_url' => $normUrl,
            'domain' => $urlHost ?: $normUrl,
            'company_name' => $businessName,
            'brand_name' => $businessName,
            'industry' => $industry,
            'industry_niche' => $industry,
            'target_language' => $language,
            'target_country' => $country,
            'target_customers' => $targetCustomers,
            'main_services' => $mainServices,
            'onboarding_completed_at' => date('Y-m-d H:i:s'),
            'is_verified' => 0,
            'is_active' => 1,
        ];

        // target_countries is JSON on the live Business Control Center schema.
        // IMPORTANT: MariaDB/MySQL SHOW COLUMNS may report a JSON column as
        // LONGTEXT when the server implements JSON through a CHECK(JSON_VALID(...))
        // constraint. Checking only the reported type therefore is not enough:
        // sending the raw value "مصر" triggers SQLSTATE[23000] / error 4025.
        // Always serialize this logical field as a valid JSON array whenever
        // the physical target_countries column exists.
        if (isset($columns['target_countries'])) {
            $targetCountriesValue = $country !== '' ? [$country] : [];
            $logical['target_countries'] = json_encode(
                $targetCountriesValue,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        // Do not send fields that are not present in the live table.
        $attrs = [];
        foreach ($logical as $key => $value) {
            $lk = strtolower($key);
            if (isset($columns[$lk])) {
                $attrs[$columns[$lk]['name']] = $value;
            }
        }

        // Safety net for NOT NULL columns with no default. Known timestamps
        // normally have defaults, but supply them if present.
        if (isset($columns['created_at']) && !$columns['created_at']['null'] && $columns['created_at']['default'] === null) {
            $attrs[$columns['created_at']['name']] = date('Y-m-d H:i:s');
        }
        if (isset($columns['updated_at']) && !$columns['updated_at']['null'] && $columns['updated_at']['default'] === null) {
            $attrs[$columns['updated_at']['name']] = date('Y-m-d H:i:s');
        }

        if ($existingId) {
            $sets = [];
            $values = [];
            foreach ($attrs as $field => $value) {
                if (strtolower($field) === 'id') {
                    continue;
                }
                $sets[] = "`{$field}` = ?";
                $values[] = $value;
            }
            if (empty($sets)) {
                return $existingId;
            }
            $values[] = $existingId;
            $this->db->exec(
                "UPDATE `websites` SET " . implode(', ', $sets) . " WHERE `id` = ?",
                $values
            );
            return $existingId;
        }

        if (empty($attrs)) {
            throw new Exception('No writable columns found in websites table');
        }

        $fields = array_keys($attrs);
        $quotedFields = '`' . implode('`, `', $fields) . '`';
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $id = $this->db->query(
            "INSERT INTO `websites` ({$quotedFields}) VALUES ({$placeholders})",
            array_values($attrs)
        );

        if (!$id) {
            throw new Exception('Failed to create website');
        }

        return (int) $id;
    }

    /** GET /onboarding - صفحة الـWizard */
    public function showWizard(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }
        $this->recordEvent('onboarding.viewed');
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderWizardPage();
        exit;
    }

    /**
     * صفحة الـWizard الكاملة - نفس الـTheme البصري لصفحات auth (خلفية
     * غامقة + لون ذهبي + خطوط IBM Plex Sans Arabic/Fraunces).
     *
     * تحسينات Phase 19:
     *  - صفوف منافسين ديناميكية (إضافة/إزالة حتى 3)
     *  - حفظ مسودة تلقائي في localStorage (مش بتضيع بيانات العميل لو
     *    ساب الصفحة ورجع)
     *  - شاشة "بنجهّز حسابك" بمراحل متحركة + polling على /api/onboarding/status
     *    لما الشغل في الخلفية (processing=true)
     *  - شاشة نتائج غنية: درجة SEO + كسر الفئات + عدد الملاحظات + خطة النمو
     *  - تخطّي (skip) لسهولة الخروج للداشبورد، وتنقّل بالـ Enter
     *  - تعبئة مسبقة من آخر موقع (تحديث البيانات بدل البدء من الصفر)
     */
    private function renderWizardPage(): string
    {
        $appName = defined('APP_NAME') ? APP_NAME : 'Tourfecto';
        $topNavBrandHtml = site_brand_html();
        $faviconHtml = site_favicon_html();
        $lang = current_lang();
        $dir = current_dir();
        $panelJsUrl = asset_v('/assets/js/panel.js');

        $t = fn ($key) => $this->tr($key);

        // تعبئة مسبقة من آخر موقع للعميل (لو راجع يحدّث بياناته)
        $prefill = null;
        try {
            $websites = (new Website())->where(['user_id' => (int) ($this->user['id'] ?? 0)], ['id' => 'DESC'], 1);
            if (!empty($websites)) {
                $w = $websites[0];
                $prefill = [
                    'business_name' => (string) $w->getAttribute('company_name'),
                    'main_url' => (string) $w->getAttribute('main_url'),
                    'industry' => (string) ($w->getAttribute('industry') ?: 'tourism'),
                    'target_country' => (string) $w->getAttribute('target_country'),
                    'target_customers' => (string) $w->getAttribute('target_customers'),
                    'main_services' => (string) $w->getAttribute('main_services'),
                ];
            }
        } catch (Exception $e) {
            // مش حاسم - لو مفيش مواقع لسه فالتعبئة هتبقى فاضية
        }
        $prefillJson = json_encode($prefill, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS);

        // مسودة السيرفر (استئناف عبر الأجهزة) - أولوية على التعبئة المسبقة.
        $serverDraftJson = 'null';
        try {
            $rows = $this->db->query(
                "SELECT draft, step FROM onboarding_drafts WHERE user_id = ? LIMIT 1",
                [(int) ($this->user['id'] ?? 0)]
            );
            if (!empty($rows) && !empty($rows[0]['draft'])) {
                $serverDraft = json_decode((string) $rows[0]['draft'], true);
                if (is_array($serverDraft)) {
                    $serverDraft['__step'] = (int) ($rows[0]['step'] ?? 1);
                    $serverDraftJson = json_encode($serverDraft, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS);
                }
            }
        } catch (Throwable $e) {
            // الجدول لسه مش متعمل - مفيش مسودة سيرفر
        }

        // Social Proof: عدد النشاطات المكتملة فعلًا على المنصة.
        $trust = $this->trustStats();
        $trustCount = max((int) $trust['completed'], (int) $trust['users']);
        $trustNoteHtml = htmlspecialchars(t('onboarding.trust_note'), ENT_QUOTES, 'UTF-8') . '<br><strong>'
            . htmlspecialchars(t('onboarding.trust_count', ['n' => number_format($trustCount)]), ENT_QUOTES, 'UTF-8') . '</strong>';

        $selAr = $lang === 'ar' ? ' selected' : '';
        $selEn = $lang === 'en' ? ' selected' : '';
        $selFr = $lang === 'fr' ? ' selected' : '';
        $selDe = $lang === 'de' ? ' selected' : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}" dir="{$dir}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$t('onboarding.page_title')} | {$appName}</title>
    {$faviconHtml}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <script src="/assets/js/tf-theme.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/style.css">
    <script src="{$panelJsUrl}"></script>
    <script>window.I18N = {$this->i18nJson()};</script>
    <style>
        :root { --ob-bg:#F4F5F8; --ob-card:#FFFFFF; --ob-line:#E3E6EC; --ob-text:#0F1B2D; --ob-muted:#5D687C; --ob-gold:#1F4FFF; --ob-green:#0E9F6E; --ob-red:#D92D20; --ob-soft:#EEF1F6; color-scheme:light; }
        html[data-theme="dark"] { --ob-bg:#080D18; --ob-card:#0F1729; --ob-line:#1B2438; --ob-text:#E8ECF5; --ob-muted:#93A0B8; --ob-gold:#6C8CFF; --ob-green:#34D399; --ob-red:#FF7A70; --ob-soft:#1B2438; color-scheme:dark; }
        body { background: var(--ob-bg); color: var(--ob-text); font-family: 'Readex Pro', 'Segoe UI', Tahoma, sans-serif; margin:0; }
        .ob-nav { padding:18px 24px; border-bottom:1px solid var(--ob-line); display:flex; align-items:center; justify-content:space-between; gap:16px; }
        .ob-nav-brand { font-weight:700; font-size:18px; }
        .ob-nav-skip { color: var(--ob-muted); font-size:13px; text-decoration:none; border:1px solid var(--ob-line); border-radius:20px; padding:6px 16px; transition: color .2s, border-color .2s; }
        .ob-nav-skip:hover { color: var(--ob-text); border-color: var(--ob-muted); }
        .ob-wrap { min-height: calc(100vh - 64px); display:flex; align-items:center; justify-content:center; padding: 32px 16px; }
        .ob-card { width:100%; max-width:640px; background: var(--ob-card); border:1px solid var(--ob-line); border-radius:16px; padding:36px; box-shadow: 0 24px 60px -28px rgba(11,27,51,.35); }
        .ob-progress-wrap { margin-bottom:14px; }
        .ob-progress-bar { height:4px; border-radius:2px; background: var(--ob-soft); overflow:hidden; }
        .ob-progress-fill { height:100%; width:14%; background: linear-gradient(90deg, var(--ob-green), var(--ob-gold)); border-radius:2px; transition: width .35s ease; }
        .ob-steps { display:flex; gap:6px; margin-bottom:28px; }
        .ob-step-dot { flex:1; height:4px; border-radius:2px; background: var(--ob-soft); transition: background .2s; }
        .ob-step-dot.done { background: var(--ob-green); }
        .ob-step-dot.active { background: var(--ob-gold); }
        .ob-h { font-size:22px; font-weight:700; margin:0 0 6px; }
        .ob-sub { color: var(--ob-muted); font-size:13.5px; margin:0 0 22px; }
        .ob-field { margin-bottom:16px; }
        .ob-field label { display:block; font-size:13px; color: var(--ob-muted); margin-bottom:6px; }
        .ob-field input, .ob-field textarea, .ob-field select {
            width:100%; box-sizing:border-box; background: var(--ob-card); border:1px solid var(--ob-line); border-radius:10px;
            padding:11px 14px; color: var(--ob-text); font-family:inherit; font-size:14px;
        }
        .ob-field input:focus, .ob-field textarea:focus { outline:none; border-color: var(--ob-gold); }
        .ob-competitor-row { display:flex; gap:8px; margin-bottom:8px; align-items:center; }
        .ob-competitor-row input { flex:1; }
        .ob-comp-remove { background:none; border:1px solid var(--ob-line); color: var(--ob-muted); border-radius:8px; width:34px; height:34px; font-size:16px; cursor:pointer; flex-shrink:0; }
        .ob-comp-remove:hover { color: var(--ob-red); border-color: var(--ob-red); }
        .ob-comp-add { background:none; border:1px dashed var(--ob-muted); color: var(--ob-muted); border-radius:10px; padding:9px 14px; font-size:13px; cursor:pointer; font-family:inherit; width:100%; }
        .ob-comp-add:hover { color: var(--ob-gold); border-color: var(--ob-gold); }
        .ob-comp-add:disabled { opacity:.4; cursor:not-allowed; }
        .ob-nav-btns { display:flex; justify-content:space-between; margin-top:24px; gap:10px; }
        .ob-btn { border:none; border-radius:10px; padding:11px 22px; font-size:14px; font-weight:600; cursor:pointer; font-family:inherit; }
        .ob-btn.primary { background: var(--ob-gold); color:#1A1200; }
        .ob-btn.primary:hover { filter:brightness(1.08); }
        .ob-btn.primary:disabled { opacity:.5; cursor:not-allowed; }
        .ob-btn.ghost { background:transparent; color: var(--ob-muted); border:1px solid var(--ob-line); }
        .ob-btn.ghost:hover { color: var(--ob-text); }
        .ob-btn.block { width:100%; }
        .ob-step { display:none; }
        .ob-step.active { display:block; }
        .ob-error { color:#ff8080; font-size:13px; margin-top:10px; display:none; }
        .ob-running-stage { display:flex; align-items:center; gap:12px; padding:12px 0; border-bottom:1px solid var(--ob-line); color: var(--ob-muted); font-size:14px; opacity:.4; transition: opacity .3s, color .3s; }
        .ob-running-stage:last-child { border-bottom:none; }
        .ob-running-stage.active { opacity:1; color: var(--ob-text); }
        .ob-running-stage .ob-dot { width:8px; height:8px; border-radius:50%; background: var(--ob-muted); flex-shrink:0; }
        .ob-running-stage.active .ob-dot { background: var(--ob-gold); box-shadow:0 0 0 4px color-mix(in srgb, var(--ob-gold) 18%, transparent); }
        .ob-running-stage.done .ob-dot { background: var(--ob-green); }
        .ob-spinner { width:16px; height:16px; border:2px solid rgba(0,0,0,.2); border-top-color:#1A1200; border-radius:50%; display:inline-block; animation: ob-spin .7s linear infinite; vertical-align:middle; margin-inline-end:6px; }
        .ob-spinner.light { border-color: var(--ob-line); border-top-color: var(--ob-gold); }
        @keyframes ob-spin { to { transform: rotate(360deg); } }
        .ob-result-row { display:flex; align-items:center; gap:10px; padding:10px 0; border-bottom:1px solid var(--ob-line); font-size:13.5px; }
        .ob-result-row:last-child { border-bottom:none; }
        .ob-row-ico { color: var(--ob-gold); display:inline-flex; align-items:center; flex-shrink:0; }
        .ob-score-badge { font-size:28px; font-weight:700; color: var(--ob-gold); }
        .ob-score-badge.muted { color: var(--ob-muted); font-size:20px; }
        .ob-cat-bar-wrap { display:flex; align-items:center; gap:12px; padding:7px 0; }
        .ob-cat-label { width:120px; flex-shrink:0; font-size:12.5px; color: var(--ob-muted); }
        .ob-cat-track { flex:1; height:8px; border-radius:4px; background: var(--ob-soft); overflow:hidden; }
        .ob-cat-fill { height:100%; border-radius:4px; background: linear-gradient(90deg, var(--ob-gold), var(--ob-green)); }
        .ob-cat-fill.low { background: var(--ob-red); }
        .ob-cat-fill.mid { background: var(--ob-gold); }
        .ob-cat-value { width:38px; flex-shrink:0; text-align:end; font-size:12.5px; font-weight:600; color: var(--ob-text); }
        .ob-result-actions { display:flex; flex-direction:column; gap:10px; margin-top:20px; }
        .ob-note { font-size:12.5px; color: var(--ob-muted); margin-top:12px; line-height:1.7; }
        .ob-badge { display:inline-block; font-size:11.5px; font-weight:600; border-radius:20px; padding:4px 12px; }
        .ob-badge.ok { background: rgba(63,167,150,.15); color: var(--ob-green); }
        .ob-badge.warn { background: color-mix(in srgb, var(--ob-gold) 14%, transparent); color: var(--ob-gold); }
        .ob-badge.err { background: rgba(229,115,109,.15); color: var(--ob-red); }
        .ob-trust { font-size:12px; color: var(--ob-muted); margin-top:16px; line-height:1.7; text-align:center; }
        .ob-trust strong { color: var(--ob-text); font-weight:600; }
        .ob-opt-badge { display:inline-block; font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color: var(--ob-muted); border:1px solid var(--ob-line); border-radius:20px; padding:2px 9px; vertical-align:middle; margin-inline-start:8px; }
        .ob-skip-step { background:none; border:none; color: var(--ob-muted); font-size:12.5px; font-family:inherit; text-decoration:underline; text-underline-offset:3px; cursor:pointer; padding:6px 2px; }
        .ob-skip-step:hover { color: var(--ob-gold); }
        .ob-lang-select { background: var(--ob-card); border:1px solid var(--ob-line); color: var(--ob-text); border-radius:8px; padding:5px 8px; font-family:inherit; font-size:12px; cursor:pointer; }
        .ob-hp { position:absolute; width:1px; height:1px; overflow:hidden; opacity:0; clip-path:inset(50%); white-space:nowrap; pointer-events:none; } /* مش left:-9999px: في صفحة RTL ده بيوسّع الصفحة 10000px وبيخليها تبان فاضية */
        .ob-progress-mini { display:flex; align-items:center; gap:6px; }
        .ob-result-whatnext { display:flex; gap:12px; padding:10px 0; border-bottom:1px solid var(--ob-line); }
        .ob-result-whatnext .ob-step-ic { width:26px; height:26px; flex-shrink:0; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:800; background:rgba(239,176,94,.12); color: var(--ob-gold); }
        .ob-result-whatnext.done .ob-step-ic { background: rgba(63,167,150,.15); color: var(--ob-green); }
        .ob-result-whatnext .ob-step-txt { font-size:13px; }
        .ob-result-whatnext .ob-step-txt small { display:block; color: var(--ob-muted); font-size:12px; margin-top:2px; }
        .ob-quickwin-fix { margin-inline-start:auto; flex-shrink:0; background:none; border:1px solid var(--ob-gold); color: var(--ob-gold); border-radius:20px; padding:4px 12px; font-size:11.5px; font-weight:600; cursor:pointer; font-family:inherit; text-decoration:none; transition:.15s; }
        .ob-quickwin-fix:hover { background: var(--ob-gold); color:#1A1200; }
        .ob-confetti { position:fixed; top:0; left:0; width:100%; height:100%; pointer-events:none; z-index:50; }
        .ob-ring-score { font-size:30px; font-weight:700; fill: var(--ob-gold); }
        .ob-ring-label { font-size:10px; fill: var(--ob-muted); letter-spacing:.3px; }
        .ob-draft-saved { font-size:11.5px; color: var(--ob-green); margin-top:6px; min-height:16px; }
        .ob-detect-hint { display:none; font-size:12.5px; color: var(--ob-muted); background: var(--ob-soft); border:1px solid var(--ob-line); border-radius:10px; padding:10px 12px; margin-top:-6px; margin-bottom:14px; line-height:1.7; }
        .ob-detect-hint strong { color: var(--ob-text); }
        .ob-detect-hint .ob-comp-add { margin-top:6px; }
        .ob-bench { font-size:12.5px; }
        .ob-bench-ico { width:16px; text-align:center; flex-shrink:0; }
        .ob-bench-up { color: var(--ob-green); }
        .ob-bench-down { color: var(--ob-gold); }
        @media (max-width:640px) {
            .ob-card { padding:24px 18px; border-radius:12px; }
            .ob-h { font-size:19px; }
            .ob-nav { padding:14px 16px; }
            .ob-field input, .ob-field textarea, .ob-field select { padding:11px 12px; font-size:16px; }
            .ob-btn { padding:12px 18px; }
        }
    </style>
</head>
<body>
    <div class="ob-nav">
        <div class="ob-nav-brand">{$topNavBrandHtml}</div>
        <div class="ob-progress-mini">
            <select id="obLangSelect" class="ob-lang-select" aria-label="{$t('onboarding.lang.label')}" onchange="obChangeLang(this)">
                <option value="ar"{$selAr}>العربية</option>
                <option value="en"{$selEn}>English</option>
                <option value="fr"{$selFr}>Français</option>
                <option value="de"{$selDe}>Deutsch</option>
            </select>
            <a href="/dashboard?skip_onboarding=1" class="ob-nav-skip">{$t('onboarding.nav.skip')}</a>
        </div>
    </div>
    <div class="ob-wrap">
        <div class="ob-card">
            <div class="ob-progress-wrap">
                <div class="ob-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="14" aria-label="{$t('onboarding.page_title')}"><div class="ob-progress-fill" id="obProgressFill"></div></div>
            </div>
            <div class="ob-steps" id="obSteps"></div>

            <!-- Step 1: Business Name -->
            <div class="ob-step active" data-step="1">
                <h2 class="ob-h">{$t('onboarding.step1.title')}</h2>
                <p class="ob-sub">{$t('onboarding.step1.sub')}</p>
                <div class="ob-field">
                    <label for="obBusinessName">{$t('onboarding.field.business_name')}</label>
                    <input type="text" id="obBusinessName" maxlength="255" placeholder="{$t('onboarding.field.business_name_ph')}" autocomplete="organization" aria-required="true">
                </div>
            </div>

            <!-- Step 2: Website -->
            <div class="ob-step" data-step="2">
                <h2 class="ob-h">{$t('onboarding.step2.title')}</h2>
                <p class="ob-sub">{$t('onboarding.step2.sub')}</p>
                <div class="ob-field">
                    <label for="obMainUrl">{$t('onboarding.field.website_url')}</label>
                    <input type="text" id="obMainUrl" inputmode="url" maxlength="500" placeholder="https://example.com" aria-required="true">
                </div>
                <div id="obDetectHint" class="ob-detect-hint"></div>
            </div>

            <!-- Step 3: Business Type -->
            <div class="ob-step" data-step="3">
                <h2 class="ob-h">{$t('onboarding.step3.title')}<span class="ob-opt-badge">{$t('onboarding.optional')}</span></h2>
                <p class="ob-sub">{$t('onboarding.step3.sub')}</p>
                <div class="ob-field">
                    <label for="obIndustry">{$t('onboarding.field.industry')}</label>
                    <select id="obIndustry">
                        <option value="tours">{$t('onboarding.industry.tours')}</option>
                        <option value="hotel">{$t('onboarding.industry.hotel')}</option>
                        <option value="travel_agency">{$t('onboarding.industry.travel_agency')}</option>
                        <option value="other">{$t('onboarding.industry.other')}</option>
                    </select>
                </div>
            </div>

            <!-- Step 4: Country / Market -->
            <div class="ob-step" data-step="4">
                <h2 class="ob-h">{$t('onboarding.step4.title')}<span class="ob-opt-badge">{$t('onboarding.optional')}</span></h2>
                <p class="ob-sub">{$t('onboarding.step4.sub')}</p>
                <div class="ob-field">
                    <label for="obTargetCountry">{$t('onboarding.field.target_country')}</label>
                    <input type="text" id="obTargetCountry" placeholder="{$t('onboarding.field.target_country_ph')}">
                </div>
            </div>

            <!-- Step 5: Target Customers -->
            <div class="ob-step" data-step="5">
                <h2 class="ob-h">{$t('onboarding.step5.title')}<span class="ob-opt-badge">{$t('onboarding.optional')}</span></h2>
                <p class="ob-sub">{$t('onboarding.step5.sub')}</p>
                <div class="ob-field">
                    <label for="obTargetCustomers">{$t('onboarding.field.target_customers')}</label>
                    <textarea id="obTargetCustomers" rows="3" maxlength="2000" placeholder="{$t('onboarding.field.target_customers_ph')}"></textarea>
                </div>
            </div>

            <!-- Step 6: Main Services -->
            <div class="ob-step" data-step="6">
                <h2 class="ob-h">{$t('onboarding.step6.title')}<span class="ob-opt-badge">{$t('onboarding.optional')}</span></h2>
                <p class="ob-sub">{$t('onboarding.step6.sub')}</p>
                <div class="ob-field">
                    <label for="obMainServices">{$t('onboarding.field.main_services')}</label>
                    <textarea id="obMainServices" rows="3" maxlength="2000" placeholder="{$t('onboarding.field.main_services_ph')}"></textarea>
                </div>
            </div>

            <!-- Step 7: Competitors -->
            <div class="ob-step" data-step="7">
                <h2 class="ob-h">{$t('onboarding.step7.title')}<span class="ob-opt-badge">{$t('onboarding.optional')}</span></h2>
                <p class="ob-sub">{$t('onboarding.step7.sub')}</p>
                <div id="obCompetitorRows"></div>
                <button type="button" class="ob-comp-add" id="obAddCompetitor">+ {$t('onboarding.nav.add_competitor')}</button>
                <p class="ob-note">{$t('onboarding.step7.optional_note')}</p>
            </div>

            <!-- Step 8: Running -->
            <div class="ob-step" data-step="8">
                <h2 class="ob-h">{$t('onboarding.running.title')}</h2>
                <div id="obRunningStages">
                    <div class="ob-running-stage" data-stage="1"><span class="ob-dot"></span><span>{$t('onboarding.running.stage.fetch')}</span></div>
                    <div class="ob-running-stage" data-stage="2"><span class="ob-dot"></span><span>{$t('onboarding.running.stage.competitors')}</span></div>
                    <div class="ob-running-stage" data-stage="3"><span class="ob-dot"></span><span>{$t('onboarding.running.stage.audit')}</span></div>
                    <div class="ob-running-stage" data-stage="4"><span class="ob-dot"></span><span>{$t('onboarding.running.stage.strategy')}</span></div>
                    <div class="ob-running-stage" data-stage="5"><span class="ob-dot"></span><span>{$t('onboarding.running.stage.finalize')}</span></div>
                </div>
                <p class="ob-sub" id="obRunningStatus" style="margin-top:18px;">{$t('onboarding.running.sub')}</p>
            </div>

            <!-- Step 9: Result -->
            <div class="ob-step" data-step="9">
                <h2 class="ob-h">{$t('onboarding.result.title')}</h2>
                <div id="obResultBody"></div>
            </div>

            <div id="obError" class="ob-error" role="alert" aria-live="assertive"></div>

            <!-- Honeypot anti-bot: مخفي تمامًا عن البشر - لو اتكتب فيه بنعتبر الطلب روبوت -->
            <div class="ob-hp" aria-hidden="true">
                <label for="obCompanyWebsite">Website</label>
                <input type="text" id="obCompanyWebsite" tabindex="-1" autocomplete="off">
            </div>

            <div class="ob-nav-btns" id="obNavBtns">
                <button class="ob-btn ghost" id="obBackBtn" onclick="obBack()">{$t('onboarding.nav.back')}</button>
                <button class="ob-skip-step" id="obSkipStepBtn" onclick="obSkipStep()" style="display:none;">{$t('onboarding.nav.skip_step')}</button>
                <button class="ob-btn primary" id="obNextBtn" onclick="obNext()">{$t('onboarding.nav.next')}</button>
            </div>
            <p class="ob-draft-saved" id="obDraftStatus"></p>
            <p class="ob-trust" id="obTrustNote">{$trustNoteHtml}</p>
        </div>
    </div>

    <script>
    const esc = P.esc, fetchJSON = P.fetchJSON;
    const OB_TOTAL_STEPS = 7;
    const OB_MAX_COMPETITORS = 3;
    const OB_DRAFT_KEY = 'tf_onboarding_draft';
    const OB_SERVER_DRAFT = {$serverDraftJson};
    const OB_OPTIONAL_STEPS = [3, 4, 5, 6, 7];
    const OB_ICON = {
        eyes: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>',
        rocket: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"></path><path d="M12 15l-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"></path><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"></path><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"></path></svg>',
        info: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>'
    };
    const OB_PREFILL = {$prefillJson};
    let obCurrentStep = 1;
    let obProcessing = false;
    let obServerSaveTimer = null;
    let obWebsiteId = '';

    const obL = function (k) { return (window.I18N && window.I18N[k]) || k; };

    function obRenderDots() {
        const wrap = document.getElementById('obSteps');
        let html = '';
        for (let i = 1; i <= OB_TOTAL_STEPS; i++) {
            const cls = i < obCurrentStep ? 'done' : (i === obCurrentStep ? 'active' : '');
            html += `<div class="ob-step-dot \${cls}"></div>`;
        }
        wrap.innerHTML = html;
        const pct = Math.round((obCurrentStep / OB_TOTAL_STEPS) * 100);
        const fill = document.getElementById('obProgressFill');
        fill.style.width = pct + '%';
        const bar = fill.closest('.ob-progress-bar');
        if (bar) bar.setAttribute('aria-valuenow', String(pct));
    }

    function obShowStep(n) {
        document.querySelectorAll('.ob-step').forEach(el => el.classList.remove('active'));
        const el = document.querySelector(`.ob-step[data-step="\${n}"]`);
        if (el) el.classList.add('active');
        document.getElementById('obError').style.display = 'none';

        document.getElementById('obTrustNote').style.display = (n === OB_TOTAL_STEPS) ? 'block' : 'none';

        const first = el ? el.querySelector('input, select, textarea') : null;
        if (first) setTimeout(function () { first.focus(); }, 60);

        // أزرار اختيارية: "تخطّي الخطوة" بتظهر في الخطوات غير الأساسية
        const skipBtn = document.getElementById('obSkipStepBtn');
        if (skipBtn) {
            skipBtn.style.display = OB_OPTIONAL_STEPS.includes(n) && !obProcessing ? 'inline-block' : 'none';
        }

        const navBtns = document.getElementById('obNavBtns');
        if (n <= OB_TOTAL_STEPS) {
            navBtns.style.display = 'flex';
            document.getElementById('obBackBtn').style.visibility = n === 1 ? 'hidden' : 'visible';
            document.getElementById('obNextBtn').textContent = n === OB_TOTAL_STEPS ? obL('onboarding.nav.finish') : obL('onboarding.nav.next');
            obRenderDots();
        } else {
            navBtns.style.display = 'none';
        }

        // Beacon متحفظ للفونيل: نسجّل أقصى خطوة وصلها المستخدم (مرة واحدة
        // لكل خطوة - GREATEST في الـDB) من غير ما نزعج بالطلبات المتكررة.
        if (!obProcessing) {
            obBeaconStep(n);
        }
    }

    function obBeaconStep(n) {
        try {
            fetchJSON('/api/onboarding/step', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ step: n }),
            });
        } catch (e) { /* الفونيل مش حاسم */ }
    }

    function obShowError(msg) {
        const box = document.getElementById('obError');
        box.textContent = msg;
        box.style.display = 'block';
    }

    function obValidateStep(n) {
        if (n === 1 && !document.getElementById('obBusinessName').value.trim()) {
            obShowError(obL('onboarding.error.business_name')); return false;
        }
        if (n === 2) {
            const url = document.getElementById('obMainUrl').value.trim();
            // سيرفر أقدر - بيقبل دومين من غير scheme (canonicalizeUrl بيضيف https://)
            const urlOk = /^(https?:\/\/)?([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}(\/\S*)?$/i.test(url);
            if (!url || !urlOk) { obShowError(obL('onboarding.error.website_url')); return false; }
        }
        return true;
    }

    async function obDetectWebsite() {
        const urlEl = document.getElementById('obMainUrl');
        const url = urlEl.value.trim();
        const hint = document.getElementById('obDetectHint');
        if (!url || !hint || !/^(https?:\/\/)?([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}(\/\S*)?$/i.test(url)) {
            if (hint) hint.style.display = 'none';
            return;
        }
        hint.style.display = 'block';
        hint.innerHTML = '<span class="ob-spinner light"></span> ' + obL('onboarding.detect.checking');
        try {
            const res = await fetchJSON('/api/onboarding/preview?url=' + encodeURIComponent(url));
            if (res.success && res.data && res.data.detected) {
                const d = res.data;
                const nameEl = document.getElementById('obBusinessName');
                const cms = d.cms ? ' <span class="ob-badge ok">' + esc(d.cms) + '</span>' : '';
                if (d.name && !nameEl.value.trim()) {
                    nameEl.value = d.name;
                    obSaveDraft();
                    hint.innerHTML = obL('onboarding.detect.found') + ' <strong>' + esc(d.name) + '</strong>' + cms +
                        ' <button type="button" class="ob-comp-add" id="obUseDetected" style="width:auto;">' + esc(obL('onboarding.detect.use')) + '</button>';
                } else if (d.name) {
                    hint.innerHTML = obL('onboarding.detect.found') + ' <strong>' + esc(d.name) + '</strong>' + cms +
                        ' <button type="button" class="ob-comp-add" id="obUseDetected" style="width:auto;">' + esc(obL('onboarding.detect.use')) + '</button>';
                } else {
                    hint.style.display = 'none';
                    return;
                }
                const btn = document.getElementById('obUseDetected');
                if (btn) btn.onclick = function () {
                    if (d.name && !nameEl.value.trim()) { nameEl.value = d.name; obSaveDraft(); }
                    hint.style.display = 'none';
                };
            } else {
                hint.style.display = 'none';
            }
        } catch (e) {
            hint.style.display = 'none';
        }
    }

    function obSaveDraft() {
        if (obProcessing) return;
        try {
            const data = {
                business_name: document.getElementById('obBusinessName').value,
                main_url: document.getElementById('obMainUrl').value,
                industry: document.getElementById('obIndustry').value,
                target_country: document.getElementById('obTargetCountry').value,
                target_customers: document.getElementById('obTargetCustomers').value,
                main_services: document.getElementById('obMainServices').value,
                competitors: Array.from(document.querySelectorAll('#obCompetitorRows .ob-competitor-row')).map(row => ({
                    name: row.querySelector('.ob-comp-name').value,
                    domain: row.querySelector('.ob-comp-domain').value,
                }))
            };
            localStorage.setItem(OB_DRAFT_KEY, JSON.stringify(data));
            obSaveDraftToServer(data);
        } catch (e) { /* localStorage غير متاح */ }
    }

    // حفظ متحفّظ على السيرفر (debounce 900ms) عشان الاستئناف عبر الأجهزة.
    function obSaveDraftToServer(data) {
        if (obServerSaveTimer) clearTimeout(obServerSaveTimer);
        obServerSaveTimer = setTimeout(function () {
            try {
                fetchJSON('/api/onboarding/draft', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ draft: data, step: obCurrentStep }),
                });
                const status = document.getElementById('obDraftStatus');
                if (status) {
                    status.textContent = obL('onboarding.draft.saved');
                    setTimeout(function () { if (status) status.textContent = ''; }, 1600);
                }
            } catch (e) { /* الفشل مش حاسم - النسخة المحلية لسه شغالة */ }
        }, 900);
    }

    function obClearServerDraft() {
        try { fetchJSON('/api/onboarding/draft', { method: 'DELETE' }); } catch (e) {}
    }

    function obRestoreDraft() {
        let draft = null;
        try { draft = JSON.parse(localStorage.getItem(OB_DRAFT_KEY) || 'null'); } catch (e) { draft = null; }

        // مسودة السيرفر لها الأولوية (آخر حالة عبر أي جهاز)، بعدين المحلية،
        // بعدين التعبئة المسبقة من آخر موقع.
        if (OB_SERVER_DRAFT && typeof OB_SERVER_DRAFT === 'object') {
            draft = Object.assign({}, draft, OB_SERVER_DRAFT);
            if (OB_SERVER_DRAFT.__step) {
                obCurrentStep = Math.min(7, Math.max(1, OB_SERVER_DRAFT.__step));
            }
        }
        if (!draft) draft = OB_PREFILL;
        if (!draft) return;
        const set = (id, v) => { const el = document.getElementById(id); if (el && v) el.value = v; };
        set('obBusinessName', draft.business_name);
        set('obMainUrl', draft.main_url);
        if (draft.industry) document.getElementById('obIndustry').value = draft.industry;
        set('obTargetCountry', draft.target_country);
        set('obTargetCustomers', draft.target_customers);
        set('obMainServices', draft.main_services);
        if (Array.isArray(draft.competitors)) {
            obRenderCompetitors(draft.competitors);
        }

        const status = document.getElementById('obDraftStatus');
        if (status && (OB_SERVER_DRAFT || draft !== OB_PREFILL)) {
            status.textContent = obL('onboarding.draft.resumed');
        }
    }

    function obAddCompetitor() {
        const rows = document.querySelectorAll('#obCompetitorRows .ob-competitor-row');
        if (rows.length >= OB_MAX_COMPETITORS) return;
        const row = document.createElement('div');
        row.className = 'ob-competitor-row';
        row.innerHTML = `<input type="text" class="ob-comp-name" placeholder="\${obL('onboarding.field.competitor_name_ph')}" aria-label="\${obL('onboarding.field.competitor_name_ph')}"><input type="text" class="ob-comp-domain" placeholder="\${obL('onboarding.field.competitor_domain_ph')}" aria-label="\${obL('onboarding.field.competitor_domain_ph')}"><button type="button" class="ob-comp-remove" onclick="obRemoveCompetitor(this)" aria-label="\${obL('onboarding.nav.remove_competitor')}">×</button>`;
        document.getElementById('obCompetitorRows').appendChild(row);
        document.getElementById('obAddCompetitor').disabled = (rows.length + 1) >= OB_MAX_COMPETITORS;
    }

    function obRemoveCompetitor(btn) {
        const row = btn.closest('.ob-competitor-row');
        if (row) row.remove();
        if (!document.querySelector('#obCompetitorRows .ob-competitor-row')) obAddCompetitor();
        document.getElementById('obAddCompetitor').disabled = document.querySelectorAll('#obCompetitorRows .ob-competitor-row').length >= OB_MAX_COMPETITORS;
    }

    function obRenderCompetitors(prefilled) {
        const wrap = document.getElementById('obCompetitorRows');
        if (!prefilled) {
            prefilled = Array.from(wrap.querySelectorAll('.ob-competitor-row')).map(row => ({
                name: row.querySelector('.ob-comp-name').value,
                domain: row.querySelector('.ob-comp-domain').value,
            })).filter(c => c.name || c.domain);
        }
        wrap.innerHTML = '';
        prefilled.forEach(c => {
            const row = document.createElement('div');
            row.className = 'ob-competitor-row';
            row.innerHTML = `<input type="text" class="ob-comp-name" value="\${esc(c.name || '')}" placeholder="\${obL('onboarding.field.competitor_name_ph')}" aria-label="\${obL('onboarding.field.competitor_name_ph')}"><input type="text" class="ob-comp-domain" value="\${esc(c.domain || '')}" placeholder="\${obL('onboarding.field.competitor_domain_ph')}" aria-label="\${obL('onboarding.field.competitor_domain_ph')}"><button type="button" class="ob-comp-remove" onclick="obRemoveCompetitor(this)" aria-label="\${obL('onboarding.nav.remove_competitor')}">×</button>`;
            wrap.appendChild(row);
        });
        if (prefilled.length === 0) obAddCompetitor();
        document.getElementById('obAddCompetitor').disabled = prefilled.length >= OB_MAX_COMPETITORS;
    }

    function obBack() {
        if (obCurrentStep > 1) { obCurrentStep--; obShowStep(obCurrentStep); }
    }

    // Progressive profiling: تخطّي الخطوات الاختيارية - لو وصل للآخر
    // بيبدأ التحليل على طول (البيانات الأساسية = الاسم + الموقع كفاية).
    function obSkipStep() {
        if (obProcessing) return;
        if (obCurrentStep < OB_TOTAL_STEPS) {
            obCurrentStep++;
            obShowStep(obCurrentStep);
        } else {
            obNext();
        }
    }

    // مفتاح اللغة في شريط الـWizard: بيحدّث تفضيل المستخدم ويعيد تحميل
    // الصفحة بنفس المسودة (المحفوظة محليًا + على السيرفر).
    function obChangeLang(sel) {
        const lang = sel.value;
        try { localStorage.setItem('tf_lang', lang); } catch (e) {}
        window.location.href = '/onboarding?lang=' + encodeURIComponent(lang);
    }

    async function obNext() {
        if (!obValidateStep(obCurrentStep)) return;
        obSaveDraft();
        if (obCurrentStep < OB_TOTAL_STEPS) {
            obCurrentStep++;
            obShowStep(obCurrentStep);
        } else {
            await obSubmit();
        }
    }

    function obStartRunning() {
        obCurrentStep = 8;
        obShowStep(8);
        document.querySelectorAll('.ob-running-stage').forEach(el => el.classList.remove('active', 'done'));
        document.getElementById('obRunningStatus').textContent = obL('onboarding.running.sub');
    }

    function obAdvanceStages() {
        const stages = document.querySelectorAll('.ob-running-stage');
        stages.forEach((el, i) => {
            const activeIdx = Math.floor(Date.now() / 4500) % stages.length;
            el.classList.toggle('done', i < activeIdx);
            el.classList.toggle('active', i === activeIdx);
        });
    }

    // fetch بمهلة: أي طلب بيتعلّق بيتقطع بدل ما الصفحة تفضل ثابتة للأبد
    function obFetch(url, opts, ms) {
        const ctl = new AbortController();
        const t = setTimeout(function () { ctl.abort(); }, ms);
        return fetchJSON(url, Object.assign({}, opts || {}, { signal: ctl.signal })).finally(function () { clearTimeout(t); });
    }

    async function obSubmit() {
        obProcessing = true;

        // Honeypot: لو حقل مخفي اتكتب فيه فده روبوت - نحاكي نجاح ونخرج بصمت
        const hp = document.getElementById('obCompanyWebsite');
        if (hp && hp.value && hp.value.trim() !== '') {
            window.location.href = '/dashboard';
            return;
        }

        const submitBtn = document.getElementById('obNextBtn');
        if (submitBtn) submitBtn.disabled = true;
        obStartRunning();
        const stageTimer = setInterval(obAdvanceStages, 1000);
        obAdvanceStages();

        const competitors = Array.from(document.querySelectorAll('#obCompetitorRows .ob-competitor-row')).map(row => ({
            name: row.querySelector('.ob-comp-name').value.trim(),
            domain: row.querySelector('.ob-comp-domain').value.trim(),
        })).filter(c => c.domain);

        const payload = {
            business_name: document.getElementById('obBusinessName').value.trim(),
            main_url: document.getElementById('obMainUrl').value.trim(),
            industry: document.getElementById('obIndustry').value,
            target_country: document.getElementById('obTargetCountry').value.trim(),
            target_customers: document.getElementById('obTargetCustomers').value.trim(),
            main_services: document.getElementById('obMainServices').value.trim(),
            competitors: competitors,
        };

        try {
            const res = await obFetch('/api/onboarding/complete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            }, 45000);

            if (!res.success) {
                clearInterval(stageTimer);
                obProcessing = false;
                if (submitBtn) submitBtn.disabled = false;
                obCurrentStep = 7;
                obShowStep(7);
                obShowError(res.error || obL('onboarding.error.generic'));
                return;
            }

            try { localStorage.removeItem(OB_DRAFT_KEY); } catch (e) {}
            obClearServerDraft();

            const data = res.data || {};
            obWebsiteId = data.website_id || obWebsiteId;
            if (data.processing) {
                obPollUntilReady(data.website_id, stageTimer);
            } else {
                clearInterval(stageTimer);
                obRenderResult(data);
                obCurrentStep = 9;
                obShowStep(9);
            }
        } catch (e) {
            clearInterval(stageTimer);
            obProcessing = false;
            if (submitBtn) submitBtn.disabled = false;
            obCurrentStep = 7;
            obShowStep(7);
            obShowError(obL('onboarding.error.generic'));
        }
    }

    function obPollUntilReady(websiteId, stageTimer) {
        const statusEl = document.getElementById('obRunningStatus');
        const startedAt = Date.now();
        const maxWait = 150000;
        let lastAuditState = '';
        let kicked = 0;
        obWebsiteId = websiteId;

        const poll = async function () {
            const elapsed = Date.now() - startedAt;
            const stageIdx = Math.floor(elapsed / 4500) % 5;
            statusEl.textContent = obL('onboarding.running.stage.detail_' + (stageIdx + 1));

            if (elapsed >= maxWait) {
                clearInterval(stageTimer);
                obProcessing = false;
                obRenderResult({ timeout: true });
                obCurrentStep = 9;
                obShowStep(9);
                return;
            }

            // لو مهمة التحليل مستنية والـ cron مش شغّال: نشغّلها احتياطيًا بعد 10 ثواني، ونحاول تاني بعد دقيقة
            if ((kicked === 0 && elapsed >= 10000) || (kicked === 1 && elapsed >= 70000)) {
                kicked++;
                obFetch('/api/onboarding/kick', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ website_id: websiteId }),
                }, 10000).catch(function () { /* بنكمّل polling عادي */ });
            }

            try {
                const res = await obFetch('/api/onboarding/status?website_id=' + encodeURIComponent(websiteId), {}, 12000);
                const site = (res && res.data && res.data.websites || []).find(w => String(w.id) === String(websiteId));
                if (site && site.job && site.job.status === 'failed' && site.audit_status !== 'completed') {
                    // المهمة استنفدت المحاولات: نعرض النتيجة بدل الانتظار الفاضي
                    clearInterval(stageTimer);
                    obProcessing = false;
                    obRenderResult(Object.assign({}, site, { audit_status: 'failed' }));
                    obCurrentStep = 9;
                    obShowStep(9);
                    return;
                }
                if (site && site.audit_status && site.audit_status !== lastAuditState) {
                    lastAuditState = site.audit_status;
                    if (site.audit_status === 'completed' || site.audit_status === 'failed') {
                        clearInterval(stageTimer);
                        obProcessing = false;
                        obRenderResult(site);
                        obCurrentStep = 9;
                        obShowStep(9);
                        return;
                    }
                }
            } catch (e) { /* تجاهل أخطاء الـpoll المؤقتة */ }

            setTimeout(poll, 2500);
        };

        setTimeout(poll, 2500);
    }

    function obCatLabel(cat) {
        const k = 'onboarding.result.category.' + cat;
        return (window.I18N && window.I18N[k]) ? window.I18N[k] : cat;
    }

    // حلقة النتيجة المتحركة (نفس أسلوب حلقات الأداء في المنتجات العالمية).
    function obScoreRing(score) {
        const c = 2 * Math.PI * 34;
        return `<svg width="96" height="96" viewBox="0 0 96 96" aria-hidden="true">
            <circle cx="48" cy="48" r="34" fill="none" stroke="rgba(255,255,255,.08)" stroke-width="7"/>
            <circle class="ob-ring-anim" cx="48" cy="48" r="34" fill="none" stroke="var(--ob-gold)" stroke-width="7" stroke-linecap="round"
                stroke-dasharray="\${c.toFixed(1)}" stroke-dashoffset="\${c.toFixed(1)}"
                style="transform:rotate(-90deg);transform-origin:48px 48px;transition:stroke-dashoffset 1.2s cubic-bezier(.3,.7,.3,1) .15s;"/>
            <text x="48" y="46" text-anchor="middle" class="ob-ring-score">\${Math.round(score)}</text>
            <text x="48" y="62" text-anchor="middle" class="ob-ring-label">SEO</text>
        </svg>`;
    }

    // قصاصات احتفال خفيفة (Canvas-less، CSS animations) عند نجاح التحليل.
    function obCelebrate() {
        const colors = ['#EFB05E', '#3FA796', '#7C93C9', '#E5736D', '#C9A0DC'];
        for (let i = 0; i < 42; i++) {
            const el = document.createElement('div');
            el.style.cssText = 'position:fixed;top:-14px;left:' + (Math.random() * 100) + 'vw;'
                + 'width:' + (5 + Math.random() * 5) + 'px;height:' + (5 + Math.random() * 5) + 'px;'
                + 'background:' + colors[i % colors.length] + ';border-radius:2px;z-index:999;pointer-events:none;';
            document.body.appendChild(el);
            const dx = (Math.random() - 0.5) * 140;
            el.animate([
                { transform: 'translateY(0) rotate(0deg)', opacity: .95 },
                { transform: 'translate(' + dx.toFixed(0) + 'px,' + (window.innerHeight + 24) + 'px) rotate(' + (Math.random() * 560 - 280) + 'deg)', opacity: 0 }
            ], { duration: 1600 + Math.random() * 1400, easing: 'cubic-bezier(.2,.6,.4,1)' }).onfinish = function () { el.remove(); };
        }
    }

    function obRenderResult(data) {
        const box = document.getElementById('obResultBody');
        let html = '';
        let showRetry = false;
        let score = null;

        if (data.timeout) {
            html += `<div class="ob-result-row"><span class="ob-score-badge muted">—</span><span>\${obL('onboarding.result.running_note')}</span></div>`;
            showRetry = true;
        } else if (data.audit_status === 'failed' || (data.audit && data.audit_error)) {
            const errMsg = (data.audit && data.audit_error) || obL('onboarding.result.audit_failed');
            html += `<div class="ob-result-row"><span class="ob-score-badge muted">—</span><span>\${esc(errMsg)}</span></div>`;
            showRetry = true;
        } else {
            score = data.audit_score != null ? Math.round(data.audit_score) : (data.audit && data.audit.audit ? Math.round(data.audit.audit.overall_score || 0) : null);
            const findingsCount = data.findings_count != null
                ? data.findings_count
                : ((data.audit && data.audit.findings || []).length);
            const scoreLabel = score != null ? score : '—';
            html += `<div class="ob-result-row" style="justify-content:center;padding:16px 0 6px;border-bottom:none;">\${score != null ? obScoreRing(score) : '<span class="ob-score-badge muted">—</span>'}</div>`;
            html += `<div class="ob-result-row" style="justify-content:center;border-bottom:none;"><span>\${obL('onboarding.result.seo_score')}: <strong>\${scoreLabel}</strong> · \${findingsCount || 0} \${obL('onboarding.result.findings_found')}</span></div>`;

            if (score != null) {
                // Industry Benchmark (Phase 20): مقارنة بمتوسط نفس النشاط من
                // بيانات المنصة الفعلية، والا baseline ثابت 55.
                const bench = data.industry_benchmark != null ? Math.round(data.industry_benchmark) : 55;
                const diff = score - bench;
                const dir = diff >= 0 ? 'ob-bench-up' : 'ob-bench-down';
                const txt = diff >= 0
                    ? obL('onboarding.result.benchmark_above') + ' ' + Math.abs(diff) + ' ' + obL('onboarding.result.benchmark_vs')
                    : obL('onboarding.result.benchmark_below') + ' ' + Math.abs(diff) + ' ' + obL('onboarding.result.benchmark_vs');
                html += `<div class="ob-result-row ob-bench \${dir}"><span class="ob-bench-ico">\${diff >= 0 ? '▲' : '▼'}</span><span>\${txt}</span></div>`;
            }

            // Quick Wins (Phase 20): أهم 3 ملاحظات بترتيب الخطورة، من نتائج
            // التدقيق الفعلية - زي prioritized fixes في Ubersuggest/SEMrush.
            const wins = data.quick_wins || [];
            if (wins.length) {
                html += `<p class="ob-sub" style="margin:16px 0 6px;">\${obL('onboarding.result.quick_wins_title')}</p>`;
                wins.slice(0, 3).forEach(function (w) {
                    const sev = w.severity || 'medium';
                    const cls = (sev === 'critical' || sev === 'high') ? 'err' : (sev === 'medium' ? 'warn' : 'ok');
                    const fixHref = '/website-optimizer?website_id=' + encodeURIComponent(obWebsiteId || '') + '&category=' + encodeURIComponent(w.category || '');
                    html += `<div class="ob-result-row"><span class="ob-badge \${cls}">\${esc(obCatLabel(w.category))}</span><span>\${esc(w.title)}</span><a class="ob-quickwin-fix" href="\${fixHref}">\${obL('onboarding.result.fix_now')}</a></div>`;
                });
            }

            const cats = data.category_scores || (data.audit && data.audit.category_scores) || null;
            if (cats && Array.isArray(cats) && cats.length) {
                html += `<p class="ob-sub" style="margin:16px 0 6px;">\${obL('onboarding.result.categories_title')}</p>`;
                cats.slice(0, 4).forEach(function (c) {
                    const val = Math.round(c.score || 0);
                    const cls = val < 50 ? 'low' : (val < 75 ? 'mid' : '');
                    html += `<div class="ob-cat-bar-wrap"><span class="ob-cat-label">\${esc(obCatLabel(c.category))}</span><span class="ob-cat-track"><span class="ob-cat-fill \${cls}" style="width:\${val}%"></span></span><span class="ob-cat-value">\${val}</span></div>`;
                });
            }

            // Competitor snapshots (Phase 20): ماذا وجدنا عن منافسيك فورًا.
            const compSnaps = data.competitors || [];
            if (compSnaps.length) {
                html += `<p class="ob-sub" style="margin:16px 0 6px;">\${obL('onboarding.result.competitors_title')}</p>`;
                compSnaps.slice(0, 3).forEach(function (c) {
                    const cms = c.cms ? ' <span class="ob-badge ok">' + esc(c.cms) + '</span>' : '';
                    const unreachable = c.error
                        ? ' <span class="ob-badge warn">' + esc(obL('onboarding.result.competitor_unreachable')) + '</span>'
                        : '';
                    html += `<div class="ob-result-row ob-bench"><span class="ob-bench-ico">🕵️</span><span><strong>\${esc(c.title || c.domain)}</strong><span class="ob-note" style="display:block;margin-top:2px;">\${esc(c.domain)}\${cms}</span></span>\${unreachable}</div>`;
                });
            }

            // "What happens next" (Phase 20.1): مسار واضح للمستخدم بعد أول
            // دقيقة - زي أول اتصال إيميل ومنتج عالمي، مش مجرد شاشة "تمام".
            html += `<p class="ob-sub" style="margin:16px 0 6px;">\${obL('onboarding.result.whatnext_title')}</p>`;
            const auditReadyNow = score != null;
            html += `<div class="ob-result-whatnext \${auditReadyNow ? 'done' : ''}"><span class="ob-step-ic">1</span><span class="ob-step-txt">\${obL('onboarding.result.whatnext_audit')}<small>\${obL('onboarding.result.whatnext_audit_sub')}</small></span></div>`;
            html += `<div class="ob-result-whatnext"><span class="ob-step-ic">2</span><span class="ob-step-txt">\${obL('onboarding.result.whatnext_email')}<small>\${obL('onboarding.result.whatnext_email_sub')}</small></span></div>`;
            html += `<div class="ob-result-whatnext"><span class="ob-step-ic">3</span><span class="ob-step-txt">\${obL('onboarding.result.whatnext_dashboard')}<small>\${obL('onboarding.result.whatnext_dashboard_sub')}</small></span></div>`;
        }

        const compsCount = data.competitors_count != null ? data.competitors_count : (data.competitors_added ? data.competitors_added.length : 0);
        if (compsCount) {
            html += `<div class="ob-result-row"><span class="ob-row-ico">\${OB_ICON.eyes}</span><span>\${compsCount} \${obL('onboarding.result.competitors_added')}</span></div>`;
        }

        const tasksCount = data.growth_plan_tasks != null
            ? data.growth_plan_tasks
            : (data.growth_plan && data.growth_plan.tasks ? data.growth_plan.tasks.length : 0);
        if (tasksCount) {
            html += `<div class="ob-result-row"><span class="ob-row-ico">\${OB_ICON.rocket}</span><span>\${obL('onboarding.result.growth_plan_ready')} (\${tasksCount} \${obL('onboarding.result.tasks')})</span></div>`;
        } else {
            html += `<div class="ob-result-row"><span class="ob-row-ico">\${OB_ICON.info}</span><span>\${obL('onboarding.result.growth_plan_pending')}</span></div>`;
        }

        box.innerHTML = html;

        // تحريك حلقة النتيجة + قصاصات الاحتفال عند نجاح حقيقي
        requestAnimationFrame(function () {
            const ring = box.querySelector('.ob-ring-anim');
            if (ring) {
                const pct = Math.max(0, Math.min(100, score != null ? Math.round(score) : 0));
                const c = 2 * Math.PI * 34;
                ring.style.strokeDashoffset = String(c * (1 - pct / 100));
            }
        });
        if (score != null && score >= 50) obCelebrate();

        const retryBtn = showRetry
            ? `<button class="ob-btn primary block" style="text-align:center;" onclick="obRetry()">\${obL('onboarding.result.retry')}</button>`
            : '';
        box.insertAdjacentHTML('beforeend', `
            <div class="ob-result-actions">
                \${retryBtn}
                <a href="/dashboard/growth" class="ob-btn primary block" style="text-decoration:none;text-align:center;">\${obL('onboarding.result.cta_growth')}</a>
                <a href="/website-optimizer" class="ob-btn ghost block" style="text-decoration:none;text-align:center;">\${obL('onboarding.result.view_audit')}</a>
            </div>`);
    }

    function obRetry() {
        if (obProcessing) return;
        document.getElementById('obResultBody').innerHTML = '';
        obCurrentStep = 7;
        obShowStep(7);
        obSubmit();
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target && e.target.tagName !== 'TEXTAREA' && obCurrentStep >= 1 && obCurrentStep <= OB_TOTAL_STEPS) {
            const tag = e.target.tagName;
            if (tag === 'INPUT' || tag === 'SELECT') {
                e.preventDefault();
                obNext();
            }
        }
    });

    document.getElementById('obAddCompetitor').addEventListener('click', obAddCompetitor);
    ['obBusinessName', 'obMainUrl', 'obTargetCountry', 'obTargetCustomers', 'obMainServices'].forEach(function (id) {
        document.getElementById(id).addEventListener('input', obSaveDraft);
    });
    // الكشف بيبدأ بعد ما العميل يبطّل كتابة (مش عند blur): الـ blur بيحصل لحظة الضغط
    // على "التالي"، والرسالة اللي بتظهر بتزق الزرار لتحت فالضغطة الأولى بتضيع.
    let obDetectTimer = null;
    document.getElementById('obMainUrl').addEventListener('input', function () {
        clearTimeout(obDetectTimer);
        obDetectTimer = setTimeout(obDetectWebsite, 900);
    });
    document.getElementById('obIndustry').addEventListener('change', obSaveDraft);
    document.getElementById('obCompetitorRows').addEventListener('input', obSaveDraft);

    obRestoreDraft();
    if (!document.querySelector('#obCompetitorRows .ob-competitor-row')) obAddCompetitor();
    obShowStep(obCurrentStep);
    </script>
</body>
</html>
HTML;
    }

    /** GET /api/onboarding/status?website_id=X (اختياري - بدونه بيرجع حالة كل مواقع الحساب) */
    public function status(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $websiteId = $this->get('website_id') ? (int) $this->get('website_id') : null;
        $userId = (int) $this->user['id'];

        try {
            $websites = (new Website())->where(['user_id' => $userId], ['id' => 'DESC']);
            $out = [];
            foreach ($websites as $w) {
                $id = (int) $w->getAttribute('id');
                if ($websiteId && $id !== $websiteId) {
                    continue;
                }

                $audit = $this->websiteAuditStatus($id);
                $growth = $this->websiteGrowthPlan($id);
                $competitorsCount = $this->websiteCompetitorsCount($id);

                $out[] = [
                    'id' => $id,
                    'main_url' => (string) $w->getAttribute('main_url'),
                    'company_name' => (string) $w->getAttribute('company_name'),
                    'onboarding_completed' => (bool) ($w->getAttribute('onboarding_completed_at') !== null),
                    'audit_status' => $audit['status'],
                    'audit_score' => $audit['score'],
                    'audit_completed_at' => $audit['completed_at'],
                    'findings_count' => $audit['findings_count'],
                    'category_scores' => $audit['category_scores'],
                    'industry_benchmark' => $audit['benchmark'],
                    'quick_wins' => $audit['quick_wins'],
                    'competitors' => $this->competitorsWithSnapshots($id),
                    'growth_plan_ready' => $growth['ready'],
                    'growth_plan_tasks' => $growth['tasks'],
                    'growth_plan_summary' => $growth['summary'],
                    'competitors_count' => $competitorsCount,
                    // حالة مهمة التحليل في الطابور: الواجهة بتستخدمها تعرف لو المهمة فشلت/اتأخرت
                    'job' => $this->onboardingJobInfo($id),
                ];
            }

            return $this->success([
                'websites' => $out,
            ]);
        } catch (Exception $e) {
            Logger::error('Onboarding Status Error', ['message' => $e->getMessage()]);
            return $this->error($this->tr('onboarding.api.status_failed'), 500);
        }
    }

    /** حالة أحدث تدقيق SEO للموقع (wo_audits). */
    private function websiteAuditStatus(int $websiteId): array
    {
        $empty = ['status' => 'none', 'score' => null, 'completed_at' => null, 'findings_count' => 0, 'category_scores' => [], 'benchmark' => null, 'quick_wins' => []];
        try {
            // تدقيق "running" بقاله أكتر من 10 دقايق = العملية اتقطعت: نعلّمه failed عشان الواجهة ما تفضلش مستنية
            try {
                $this->db->query(
                    "UPDATE wo_audits SET status = 'failed', completed_at = NOW() WHERE website_id = ? AND status = 'running' AND started_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)",
                    [$websiteId]
                );
            } catch (Throwable $ignored) {
            }
            $rows = $this->db->query(
                "SELECT id, status, overall_score, completed_at FROM wo_audits WHERE website_id = ? ORDER BY id DESC LIMIT 1",
                [$websiteId]
            );
            if (empty($rows)) {
                return $empty;
            }

            $auditId = (int) $rows[0]['id'];
            $findingsCount = (int) ($this->db->query(
                "SELECT COUNT(*) AS c FROM wo_audit_findings WHERE audit_id = ?",
                [$auditId]
            )[0]['c'] ?? 0);

            $catRows = $this->db->query(
                "SELECT category, COUNT(*) AS total, SUM(status = 'pass') AS passed FROM wo_audit_findings WHERE audit_id = ? GROUP BY category ORDER BY total DESC",
                [$auditId]
            );
            $categoryScores = [];
            foreach ($catRows as $cr) {
                $total = (int) ($cr['total'] ?? 0);
                if ($total <= 0) {
                    continue;
                }
                $passed = (int) ($cr['passed'] ?? 0);
                $categoryScores[] = [
                    'category' => (string) $cr['category'],
                    'score' => (int) round(($passed / $total) * 100),
                ];
            }

            return [
                'status' => (string) ($rows[0]['status'] ?? 'none'),
                'score' => $rows[0]['overall_score'] !== null ? (int) round((float) $rows[0]['overall_score']) : null,
                'completed_at' => $rows[0]['completed_at'] ?? null,
                'findings_count' => $findingsCount,
                'category_scores' => $categoryScores,
                'benchmark' => $this->industryBenchmark($websiteId),
                'quick_wins' => $this->quickWins($websiteId),
            ];
        } catch (Exception $e) {
            return $empty;
        }
    }

    /** حالة خطة النمو (seo_strategy_plans) للموقع. */
    private function websiteGrowthPlan(int $websiteId): array
    {
        $empty = ['ready' => false, 'tasks' => 0, 'summary' => null];
        try {
            $planRows = $this->db->query(
                "SELECT id, summary FROM seo_strategy_plans WHERE website_id = ? ORDER BY id DESC LIMIT 1",
                [$websiteId]
            );
            if (empty($planRows)) {
                return $empty;
            }

            $tasksCount = (int) ($this->db->query(
                "SELECT COUNT(*) AS c FROM seo_strategy_tasks WHERE plan_id = ?",
                [(int) $planRows[0]['id']]
            )[0]['c'] ?? 0);

            return [
                'ready' => true,
                'tasks' => $tasksCount,
                'summary' => (string) ($planRows[0]['summary'] ?? ''),
            ];
        } catch (Exception $e) {
            return $empty;
        }
    }

    /** عدد المنافسين المسجلين للموقع. */
    private function websiteCompetitorsCount(int $websiteId): int
    {
        try {
            return (int) ($this->db->query(
                "SELECT COUNT(*) AS c FROM competitors WHERE website_id = ?",
                [$websiteId]
            )[0]['c'] ?? 0);
        } catch (Exception $e) {
            return 0;
        }
    }

    /** آخر مهمة تحليل لموقع معيّن (أي حالة). null لو مفيش. */
    private function latestOnboardingJob(int $websiteId): ?array
    {
        try {
            $rows = $this->db->query(
                "SELECT id, status, attempts, available_at, reserved_at, created_at,
                        TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_sec,
                        TIMESTAMPDIFF(SECOND, reserved_at, NOW()) AS reserved_age_sec,
                        (available_at > NOW()) AS in_backoff
                   FROM jobs WHERE job_class = ? AND payload REGEXP ? ORDER BY id DESC LIMIT 1",
                ['OnboardingAuditJob', '"website_id":' . (int) $websiteId . '([^0-9]|$)']
            );
            return !empty($rows) ? $rows[0] : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** معلومات آمنة للعرض في الواجهة (من غير نص الخطأ الداخلي). */
    private function onboardingJobInfo(int $websiteId): ?array
    {
        $j = $this->latestOnboardingJob($websiteId);
        if ($j === null) {
            return null;
        }
        return ['status' => (string) $j['status'], 'attempts' => (int) $j['attempts'], 'age_sec' => (int) $j['age_sec']];
    }

    /**
     * قرار الـ worker الاحتياطي (من غير أي تنفيذ): هل نشغّل مهمة التحليل دلوقتي؟
     * بيتستخدم لما الـ cron مش شغّال أو متأخر، عشان الـ onboarding ما يفضلش عالق.
     *
     * @return array ['action' => 'run'|'wait'|'done'|'failed'|'none'|'forbidden', 'job_id' => ?int]
     */
    public function kickDecision(int $websiteId, int $userId, int $minAgeSeconds = 12): array
    {
        $own = $this->db->query('SELECT id FROM websites WHERE id = ? AND user_id = ? LIMIT 1', [$websiteId, $userId]);
        if (empty($own)) {
            return ['action' => 'forbidden', 'job_id' => null];
        }
        $j = $this->latestOnboardingJob($websiteId);
        if ($j === null) {
            return ['action' => 'none', 'job_id' => null];
        }
        $id = (int) $j['id'];
        switch ($j['status']) {
            case 'completed':
                return ['action' => 'done', 'job_id' => $id];
            case 'failed':
                return ['action' => 'failed', 'job_id' => $id];
            case 'processing':
                // مهمة شغّالة فعلاً: نستنى. لو عالقة أكتر من 5 دقايق (العامل وقع) نشغّلها تاني.
                return ['action' => ((int) $j['reserved_age_sec'] > 300) ? 'run' : 'wait', 'job_id' => $id];
            default: // pending
                if ((int) $j['in_backoff'] === 1) {
                    return ['action' => 'wait', 'job_id' => $id];
                }
                return ['action' => ((int) $j['age_sec'] >= $minAgeSeconds) ? 'run' : 'wait', 'job_id' => $id];
        }
    }

    /**
     * POST /api/onboarding/kick {website_id}
     * Worker احتياطي (Lazy Cron): لو مهمة التحليل مستنية أكتر من 12 ثانية يبقى الـ cron مش شغّال أو متأخر،
     * فنرد على المتصفح فورًا ونكمل تنفيذ المهمة في نفس العملية بعد الرد. الحجز ذرّي (UPDATE ... WHERE status='pending')
     * فمستحيل المهمة تتنفّذ مرتين لو الـ cron اشتغل في نفس اللحظة.
     */
    public function kick(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $websiteId = (int) $this->get('website_id', 0);
        $userId = (int) $this->user['id'];
        if ($websiteId <= 0) {
            return $this->error($this->tr('onboarding.api.invalid_data'), 422);
        }

        $d = $this->kickDecision($websiteId, $userId);
        if ($d['action'] === 'forbidden') {
            return $this->error('Forbidden', 403);
        }
        if ($d['action'] !== 'run') {
            return $this->success(['action' => $d['action'], 'job_id' => $d['job_id']]);
        }

        // رد فوري للمتصفح، وبعدها نكمل التنفيذ
        $body = json_encode(['success' => true, 'data' => ['action' => 'run', 'job_id' => $d['job_id']]], JSON_UNESCAPED_UNICODE);
        ignore_user_abort(true);
        @set_time_limit(240);
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close(); // ما نقفلش طلبات المستخدم التانية (status) طول التنفيذ
        }
        if (!headers_sent()) {
            http_response_code(202);
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Length: ' . strlen($body));
            header('Connection: close');
        }
        echo $body;
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }

        try {
            $res = (new QueueManager())->processJob((int) $d['job_id']);
            $this->log('Onboarding lazy worker', ['website_id' => $websiteId, 'job_id' => $d['job_id'], 'result' => $res['status'] ?? '']);
        } catch (Throwable $e) {
            Logger::error('Onboarding lazy worker failed', ['job_id' => $d['job_id'], 'message' => $e->getMessage()]);
        }
        exit;
    }

    /**
     * Phase 20 - Rate limiting: هل فيه OnboardingAuditJob شغال/معلّق لنفس
     * الموقع؟ بيرجّع الـ job_id لو موجود (عشان الواجهة تحط polling عليه)
     * أو null لو مفيش. أي خطأ DB بيرجّع null (مش نحظر الـOnboarding).
     */
    private function activeOnboardingJobId(int $websiteId): ?int
    {
        try {
            // REGEXP بحدود: يطابق `"website_id":5` بس مش `"website_id":50`
            $rows = $this->db->query(
                "SELECT id FROM jobs WHERE job_class = ? AND status IN ('pending','processing') AND payload REGEXP ? ORDER BY id DESC LIMIT 1",
                ['OnboardingAuditJob', '"website_id":' . (int) $websiteId . '([^0-9]|$)']
            );
            return !empty($rows) ? (int) $rows[0]['id'] : null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Phase 20 - Rate limiting: أحدث تدقيق مكتمل للموقع خلال آخر N دقيقة.
     * بيرجّع ['id','audit_data'] أو null. audit_data بنفس شكل اللي
     * websiteAuditStatus بترجّعه عشان الواجهة تعرضه مباشرة.
     */
    private function recentCompletedAudit(int $websiteId, int $withinMinutes): ?array
    {
        try {
            $rows = $this->db->query(
                "SELECT id, overall_score, completed_at FROM wo_audits WHERE website_id = ? AND status = 'completed' AND completed_at >= NOW() - INTERVAL ? MINUTE ORDER BY id DESC LIMIT 1",
                [$websiteId, $withinMinutes]
            );
            if (empty($rows)) {
                return null;
            }

            $auditId = (int) $rows[0]['id'];
            $findingsCount = (int) ($this->db->query(
                "SELECT COUNT(*) AS c FROM wo_audit_findings WHERE audit_id = ?",
                [$auditId]
            )[0]['c'] ?? 0);

            $catRows = $this->db->query(
                "SELECT category, COUNT(*) AS total, SUM(status = 'pass') AS passed FROM wo_audit_findings WHERE audit_id = ? GROUP BY category ORDER BY total DESC",
                [$auditId]
            );
            $categoryScores = [];
            foreach ($catRows as $cr) {
                $total = (int) ($cr['total'] ?? 0);
                if ($total <= 0) {
                    continue;
                }
                $passed = (int) ($cr['passed'] ?? 0);
                $categoryScores[] = [
                    'category' => (string) $cr['category'],
                    'score' => (int) round(($passed / $total) * 100),
                ];
            }

            return [
                'id' => $auditId,
                'audit_data' => [
                    'audit_score' => $rows[0]['overall_score'] !== null ? (int) round((float) $rows[0]['overall_score']) : null,
                    'findings_count' => $findingsCount,
                    'category_scores' => $categoryScores,
                ],
            ];
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Industry Benchmark (Phase 20): متوسط درجة الـSEO لكل التدقيقات المكتملة
     * على المواقع اللي في نفس النشاط. لو البيانات مش كفاية (أقل من 5
     * عيّنات) نرجّع null والواجهة بتقع على baseline ثابت (55). كده المقارنة
     * بتاعة العميل معقولة ومبنية على بيانات حقيقية من المنصة نفسها، مش رقم
     * من برة - نفس منطق benchmarks في Ubersuggest/SEMrush.
     */
    private function industryBenchmark(int $websiteId): ?int
    {
        try {
            $row = $this->db->query(
                "SELECT industry_niche AS industry FROM websites WHERE id = ? LIMIT 1",
                [$websiteId]
            );
            if (empty($row) || empty($row[0]['industry'])) {
                return null;
            }

            // 'tourism' و 'tours' هما نفس الفئة عمليًا (الـWizard بيكتب
            // 'tours' والـdefault القديم كان 'tourism') - نجمّعهم سوا.
            $industry = $row[0]['industry'];
            $norm = in_array($industry, ['tourism', 'tours'], true) ? 'tourism_group' : (string) $industry;

            $agg = $this->db->query(
                "SELECT AVG(a.overall_score) AS avg_score, COUNT(*) AS n
                 FROM wo_audits a
                 INNER JOIN websites w ON w.id = a.website_id
                 WHERE a.status = 'completed' AND a.overall_score IS NOT NULL
                   AND (
                     w.industry_niche = ?
                     OR (w.industry_niche IN ('tourism','tours') AND ? = 'tourism_group')
                   )",
                [$industry, $norm]
            );

            $n = (int) ($agg[0]['n'] ?? 0);
            $avg = $agg[0]['avg_score'] ?? null;
            if ($n >= 5 && $avg !== null) {
                return (int) round((float) $avg);
            }
        } catch (Exception $e) {
            // مفيش بيانات/جدول - نسقط على الـbaseline
        }

        return null;
    }

    /**
     * Quick Wins (Phase 20): أهم 3 ملاحظات فاشلة في آخر تدقيق، بترتيب
     * الخطورة (critical → high → medium → low). بتتشتق من wo_audit_findings
     * الفعلية فالنتيجة دقيقة ومبنية على ما وجده التدقيق فعلًا، مش تخمين.
     */
    private function quickWins(int $websiteId): array
    {
        try {
            $rows = $this->db->query(
                "SELECT f.category, f.title, f.severity
                 FROM wo_audits a
                 INNER JOIN wo_audit_findings f ON f.audit_id = a.id
                 WHERE a.website_id = ? AND a.status = 'completed' AND f.status = 'fail'
                 ORDER BY a.id DESC,
                   CASE f.severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END ASC,
                   f.id ASC
                 LIMIT 3",
                [$websiteId]
            );

            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'category' => (string) ($r['category'] ?? ''),
                    'title' => (string) ($r['title'] ?? ''),
                    'severity' => (string) ($r['severity'] ?? 'medium'),
                ];
            }
            return $out;
        } catch (Exception $e) {
            return [];
        }
    }

    /** اشتقاق الـQuick Wins من نتيجة الـAudit مباشرة (المسار المتزامن). */
    private function deriveQuickWins(array $auditResult): array
    {
        if (!($auditResult['success'] ?? false)) {
            return [];
        }

        $findings = (array) ($auditResult['data']['findings'] ?? []);
        $fails = array_filter($findings, function ($f) {
            return is_array($f) && (($f['status'] ?? '') === 'fail') && !empty($f['title']);
        });

        if (empty($fails)) {
            return [];
        }

        $severityRank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($fails, function ($a, $b) use ($severityRank) {
            $ra = $severityRank[$a['severity'] ?? 'medium'] ?? 3;
            $rb = $severityRank[$b['severity'] ?? 'medium'] ?? 3;
            return $ra <=> $rb;
        });

        $out = [];
        foreach (array_slice($fails, 0, 3) as $f) {
            $out[] = [
                'category' => (string) ($f['category'] ?? ''),
                'title' => (string) $f['title'],
                'severity' => (string) ($f['severity'] ?? 'medium'),
            ];
        }
        return $out;
    }

    /**
     * جلب وحفظ لقطة حقيقية (SSRF-protected) للصفحة الرئيسية لكل منافس
     * مُضاف. اللقطات بتتخزن في onboarding_competitor_snapshots لعرض فوري،
     * وبتتكتب من جديد مع كل Onboarding (مش سجل تاريخي). لو جدول الـ
     * snapshots لسه مش متعمل على السيرفر بنتجاهل بهدوء - الفشل مش حاسم.
     */
    private function saveCompetitorSnapshots(int $userId, int $websiteId, array $competitors): void
    {
        if (empty($competitors) || !class_exists('WebsiteSnapshotFetcher')) {
            return;
        }

        try {
            $this->db->exec(
                "DELETE FROM onboarding_competitor_snapshots WHERE website_id = ?",
                [$websiteId]
            );
        } catch (Exception $e) {
            return; // الجدول لسه مش موجود - نتجاهل
        }

        $fetcher = new WebsiteSnapshotFetcher();
        foreach ($competitors as $c) {
            $domain = trim((string) ($c['competitor_domain'] ?? $c['domain'] ?? ''));
            if ($domain === '') {
                continue;
            }

            $fetchUrl = preg_match('#^https?://#i', $domain) ? $domain : 'https://' . $domain;
            $result = $fetcher->fetch($fetchUrl);

            try {
                $this->db->exec(
                    "INSERT INTO onboarding_competitor_snapshots
                     (website_id, user_id, competitor_id, domain, title, meta_description, tech_signals, http_status, error)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $websiteId,
                        $userId,
                        isset($c['id']) ? (int) $c['id'] : null,
                        $domain,
                        $result['title'] ?? null,
                        $result['meta_description'] ?? null,
                        !empty($result['tech_signals']) ? json_encode($result['tech_signals']) : null,
                        $result['http_status'] ?? null,
                        $result['success'] ? null : substr((string) ($result['error'] ?? ''), 0, 250),
                    ]
                );
            } catch (Exception $e) {
                // منافس واحد فشل تخزين لقطته مايوقفش الباقي
            }
        }
    }

    /** لقطات المنافسين المسجلة للموقع (لسهلة العرض فورًا في الواجهة). */
    private function competitorsWithSnapshots(int $websiteId): array
    {
        try {
            $rows = $this->db->query(
                "SELECT domain, title, meta_description, tech_signals, http_status, error, fetched_at
                 FROM onboarding_competitor_snapshots
                 WHERE website_id = ?
                 ORDER BY id ASC",
                [$websiteId]
            );

            $out = [];
            foreach ($rows as $r) {
                $signals = $r['tech_signals'] ? json_decode((string) $r['tech_signals'], true) : null;
                $out[] = [
                    'domain' => (string) ($r['domain'] ?? ''),
                    'title' => (string) ($r['title'] ?? ''),
                    'meta_description' => (string) ($r['meta_description'] ?? ''),
                    'http_status' => $r['http_status'] !== null ? (int) $r['http_status'] : null,
                    'cms' => is_array($signals) && !empty($signals['cms_hint']) ? (string) $signals['cms_hint'] : null,
                    'error' => $r['error'] !== null ? (string) $r['error'] : null,
                    'fetched_at' => (string) ($r['fetched_at'] ?? ''),
                ];
            }
            return $out;
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Phase 20 - أحداث الفونيل (Analytics): الهدف منها قياس نسبة التسرب في
     * الـOnboarding (viewed → submitted → completed). بنستخدم ActivityLog
     * الموحّد لو متاح، وبنكتفي بـLogger لو مش متحمّل (class_exists) - وأي
     * فشل تسجيل ميكسّرش الطلب أبدًا.
     */
    private function recordEvent(string $action, array $meta = []): void
    {
        try {
            if (class_exists('ActivityLog')) {
                ActivityLog::record('onboarding', $action, [
                    'user_id' => (int) ($this->user['id'] ?? 0),
                    'meta' => $meta,
                ]);
            } else {
                $this->log('Onboarding Event: ' . $action, $meta);
            }
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('Onboarding recordEvent failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * استخراج اسم النشاط التقريبي من عنوان الموقع: بياخد الجزء قبل أول
     * فاصل (|, -, —, ·, •, 🙂...) ويشيل أي مسافات زايدة. بيرجع null لو
     * الاسم الناتج صغير/كبير بشكل مش منطقي - في الحالة دي الواجهة
     * مش بتقترح حاجة ومستخبية إن الموقع ملوش <title> واضح.
     */
    private function detectBusinessName(?string $title): ?string
    {
        if ($title === null || trim($title) === '') {
            return null;
        }

        $parts = preg_split('/\s*(?:\||[\-–—]|·|•|🙂)\s*/u', trim($title), 2);
        $name = trim((string) ($parts[0] ?? $title));
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        $length = mb_strlen($name);
        if ($length < 2 || $length > 60) {
            return null;
        }

        return $name;
    }
}

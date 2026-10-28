<?php

/**
 * Tourfecto - AI Revenue Intelligence Controller
 * @version 1.0.0
 *
 * موديول مستقل (TOURFECTO AI REVENUE INTELLIGENCE) - لا يعيد بناء CRM/
 * Analytics/Ads/Billing. يقرأ بيانات حقيقية عبر RevenueDataGateway
 * ويعرضها/يحللها فقط. الصفحة القديمة /revenue (RevenueController) تبقى
 * كما هي بدون أي تعديل - هذه صفحة/API إضافية منفصلة تمامًا.
 *
 * Tenant Isolation: كل استعلام في كل Service مقيّد بـ $this->user['id']
 * (نفس نمط باقي الـ Controllers في المشروع - AuthMiddleware يضمن وجود
 * جلسة صالحة قبل الوصول لأي Action هنا).
 */
class RevenueIntelligenceController extends Controller
{
    private RevenueOverviewService $overviewService;
    private RevenueForecastService $forecastService;
    private RevenueInsightService $insightService;
    private CustomerRevenueService $customerService;
    private PipelineRevenueService $pipelineService;
    private RevenueAnomalyService $anomalyService;
    private RevenueAssistantService $assistantService;
    private RevenueActionService $actionService;
    private RevenueActionExecutor $actionExecutor;
    private ExecutiveSummaryService $executiveSummaryService;
    private RevenueCacheService $cacheService;
    private RevenueQuotaService $quotaService;

    public function __construct()
    {
        parent::__construct();
        $this->overviewService = new RevenueOverviewService();
        $this->forecastService = new RevenueForecastService();
        $this->insightService = new RevenueInsightService();
        $this->customerService = new CustomerRevenueService();
        $this->pipelineService = new PipelineRevenueService();
        $this->anomalyService = new RevenueAnomalyService();
        $this->assistantService = new RevenueAssistantService();
        $this->actionService = new RevenueActionService();
        $this->actionExecutor = new RevenueActionExecutor();
        $this->executiveSummaryService = new ExecutiveSummaryService();
        $this->cacheService = new RevenueCacheService();
        $this->quotaService = new RevenueQuotaService();
    }

    /** GET /revenue/intelligence - صفحة واحدة بتابات (Tabs) على الـ Client-side. */
    public function index(array $params = []): array
    {
        $tabs = [
            'executive' => $this->tr('revai.tab.executive'),
            'season' => current_lang() === 'ar' ? '🔥 رحلات الموسم' : '🔥 Season trips',
            'overview' => $this->tr('revai.tab.overview'),
            'forecast' => $this->tr('revai.tab.forecast'),
            'opportunities' => $this->tr('revai.tab.opportunities'),
            'risks' => $this->tr('revai.tab.risks'),
            'customers' => $this->tr('revai.tab.customers'),
            'pipeline' => $this->tr('revai.tab.pipeline'),
            'sources' => $this->tr('revai.tab.sources'),
            'anomalies' => $this->tr('revai.tab.anomalies'),
            'retention' => $this->tr('revai.tab.retention'),
            'subscriptions' => $this->tr('revai.tab.subscriptions'),
            'attribution' => $this->tr('revai.tab.attribution'),
            'benchmarks' => $this->tr('revai.tab.benchmarks'),
            'quotas' => $this->tr('revai.tab.quotas'),
            'churn' => $this->tr('revai.tab.churn'),
            'assistant' => $this->tr('revai.tab.assistant'),
            'reports' => $this->tr('revai.tab.reports'),
        ];

        $tabsHtml = '<div class="p-tabs" id="revaiTabs" style="margin-bottom:18px;flex-wrap:wrap;">';
        $first = true;
        foreach ($tabs as $key => $label) {
            $activeClass = $first ? ' active' : '';
            $tabsHtml .= "<a href=\"#\" data-tab=\"{$key}\" class=\"p-tab{$activeClass}\" style=\"text-decoration:none;\">{$label}</a>";
            $first = false;
        }
        $tabsHtml .= '</div>';

        $periodSelect = <<<HTML
        <select id="revaiPeriod" class="p-select" style="max-width:180px;">
            <option value="daily">{$this->tr('revai.period.daily')}</option>
            <option value="weekly">{$this->tr('revai.period.weekly')}</option>
            <option value="monthly" selected>{$this->tr('revai.period.monthly')}</option>
            <option value="quarterly">{$this->tr('revai.period.quarterly')}</option>
            <option value="yearly">{$this->tr('revai.period.yearly')}</option>
        </select>
HTML;

        $body = <<<HTML
        {$tabsHtml}
        <div style="display:flex;justify-content:flex-end;margin-bottom:14px;">{$periodSelect}</div>
        <div id="revaiPanel"><div class="p-empty">{$this->tr('common.loading')}</div></div>
HTML;
        $sjs = htmlspecialchars(asset_v('/assets/js/season-posts.js'), ENT_QUOTES, 'UTF-8');
        $scss = htmlspecialchars(asset_v('/assets/css/season-posts.css'), ENT_QUOTES, 'UTF-8');
        $icons = htmlspecialchars(asset_v('/assets/js/sp-icons.js'), ENT_QUOTES, 'UTF-8');
        $body .= '<link rel="stylesheet" href="' . $scss . '"><script defer src="' . $icons . '"></script><script defer src="' . $sjs . '"></script>';

        $script = $this->pageScript();

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('revenue', $this->tr('revai.page.title'), $this->tr('revai.page.subtitle'), $body, $script);
        exit;
    }

    // ============================================================
    // API - كل Endpoint بيرجع JSON من خدمة واحدة فقط (Separation of concerns)
    // ============================================================

    /** GET /api/revenue-intelligence/overview */
    public function apiOverview(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $period = $this->validPeriod($this->get('period', 'monthly'));
        $userId = (int) $this->user['id'];
        try {
            $data = $this->cacheService->rememberOverview($userId, $period, function () use ($userId, $period) {
                return $this->overviewService->getOverview($userId, $period);
            });
            return $this->success($data);
        } catch (Throwable $e) {
            return $this->serverError('overview', $e);
        }
    }

    /** GET /api/revenue-intelligence/sources */
    public function apiSources(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $period = $this->validPeriod($this->get('period', 'monthly'));
        try {
            return $this->success($this->overviewService->getRevenueBySourceWithGrowth((int) $this->user['id'], $period));
        } catch (Throwable $e) {
            return $this->serverError('sources', $e);
        }
    }

    /** GET /api/revenue-intelligence/products - قسم 8: يفصح بصدق أن لا بيانات كافية */
    public function apiProducts(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        return $this->success($this->overviewService->getRevenueByProduct((int) $this->user['id']));
    }

    /** GET /api/revenue-intelligence/forecast */
    public function apiForecast(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $period = $this->validPeriod($this->get('period', 'monthly'));
        $userId = (int) $this->user['id'];
        try {
            $result = $this->cacheService->rememberForecast($userId, $period, function () use ($userId, $period) {
                return $this->forecastService->forecast($userId, $period, true);
            });
            return $this->success($result);
        } catch (Throwable $e) {
            return $this->serverError('forecast', $e);
        }
    }

    /** GET /api/revenue-intelligence/forecast/accuracy - يقارن توقعات سابقة بالإيراد الحقيقي اللي حصل فعلاً */
    public function apiForecastAccuracy(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success($this->forecastService->getAccuracyHistory((int) $this->user['id']));
        } catch (Throwable $e) {
            return $this->serverError('forecast-accuracy', $e);
        }
    }

    /** GET /api/revenue-intelligence/opportunities */
    /**
     * GET /api/revenue-intelligence/opportunities
     * Section 17/18: بدون الكاش هنا كنا بنسجّل نفس الـ Opportunities في
     * revai_insights (Audit Log) في كل مرة المستخدم يفتح التاب - يعني
     * الجدول كان هيتملي بآلاف الصفوف المكررة لأتفه سبب (تحديث الصفحة).
     * دلوقتي: التسجيل بيحصل مرة واحدة فعلية لكل نافذة كاش (نفس مدة
     * Overview/Forecast)، والـ response نفسه بيتحسب لحظيًا برضه.
     */
    public function apiOpportunities(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $userId = (int) $this->user['id'];
        try {
            $opportunities = $this->insightService->getOpportunities($userId);
            $this->cacheService->rememberOncePerWindow('opportunities', $userId, function () use ($userId, $opportunities) {
                RevenueInsightPersister::persist($userId, $opportunities);
                return true;
            });
            return $this->success(['has_data' => !empty($opportunities), 'opportunities' => $opportunities]);
        } catch (Throwable $e) {
            return $this->serverError('opportunities', $e);
        }
    }

    /** GET /api/revenue-intelligence/risks */
    /** GET /api/revenue-intelligence/risks - نفس منطق تفادي التكرار المستخدم في apiOpportunities. */
    public function apiRisks(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $userId = (int) $this->user['id'];
        try {
            $risks = $this->insightService->getRisks($userId);
            $this->cacheService->rememberOncePerWindow('risks', $userId, function () use ($userId, $risks) {
                RevenueInsightPersister::persist($userId, $risks);
                return true;
            });
            return $this->success(['has_data' => !empty($risks), 'risks' => $risks]);
        } catch (Throwable $e) {
            return $this->serverError('risks', $e);
        }
    }

    /** GET /api/revenue-intelligence/anomalies */
    /** GET /api/revenue-intelligence/anomalies - نفس منطق تفادي التكرار. */
    public function apiAnomalies(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $userId = (int) $this->user['id'];
        try {
            $result = $this->anomalyService->detect($userId);
            if (!empty($result['anomalies'])) {
                $this->cacheService->rememberOncePerWindow('anomalies', $userId, function () use ($userId, $result) {
                    RevenueInsightPersister::persist(
                        $userId,
                        array_map([RevenueInsightPersister::class, 'anomalyToInsight'], $result['anomalies'])
                    );
                    return true;
                });
            }
            return $this->success($result);
        } catch (Throwable $e) {
            return $this->serverError('anomalies', $e);
        }
    }

    /**
     * GET /api/revenue-intelligence/customers?page=1&per_page=25
     * Section 18 (Pagination): customer count has no natural cap (unlike
     * Opportunities/Risks which are already bounded to ~15-20 items each
     * by design). Segmentation/percentile ranking needs the *full* dataset
     * to be computed first (percentile rank is only meaningful relative to
     * everyone), so pagination is applied to the response, not the query -
     * this keeps the payload small without breaking the ranking math. A
     * future optimization could push this down to a paginated SQL query if
     * a tenant's contact base grows very large.
     */
    public function apiCustomers(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $result = $this->customerService->getCustomerRevenueIntelligence((int) $this->user['id']);
            if (!empty($result['has_data'])) {
                $result = $this->paginate($result, 'customers');
            }
            return $this->success($result);
        } catch (Throwable $e) {
            return $this->serverError('customers', $e);
        }
    }

    /** GET /api/revenue-intelligence/segments */
    public function apiSegments(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success($this->customerService->getSegments((int) $this->user['id']));
        } catch (Throwable $e) {
            return $this->serverError('segments', $e);
        }
    }

    /** GET /api/revenue-intelligence/pipeline */
    public function apiPipeline(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            return $this->success($this->pipelineService->getPipelineIntelligence((int) $this->user['id']));
        } catch (Throwable $e) {
            return $this->serverError('pipeline', $e);
        }
    }

    /** GET /api/revenue-intelligence/actions */
    public function apiActions(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $actions = $this->actionService->getNextBestActions((int) $this->user['id']);
            return $this->success(['has_data' => !empty($actions), 'actions' => $actions]);
        } catch (Throwable $e) {
            return $this->serverError('actions', $e);
        }
    }

    /**
     * POST /api/revenue-intelligence/actions/execute - طبقة التنفيذ.
     * بتحوّل التوصيات لإجراءات فعلية (مهمة CRM + إشعار للأعلى خطورة) مع
     * منع التكرار. استخدم dry_run=true للاستعراض من غير كتابة.
     */
    public function apiActionsExecute(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $userId = (int) $this->user['id'];
        $boolOpt = function (string $key, bool $default) {
            $v = $this->get($key, null);
            if ($v === null) {
                return $default;
            }
            return in_array(strtolower((string) $v), ['1', 'true', 'on', 'yes'], true);
        };
        $opts = [
            'create_tasks' => $boolOpt('create_tasks', true),
            'notify' => $boolOpt('notify', true),
            'dry_run' => $boolOpt('dry_run', false),
            'window_days' => (int) $this->get('window_days', 7),
        ];
        try {
            $actions = $this->actionService->getNextBestActions($userId, 10);
            if ($opts['dry_run']) {
                return $this->success([
                    'dry_run' => true,
                    'summary' => $this->actionExecutor->executeActions($userId, $actions, $opts),
                ], 'استعراض التنفيذ المتوقع');
            }
            $summary = $this->actionExecutor->executeActions($userId, $actions, $opts);
            ActivityLog::record('revenue_intelligence', 'actions.executed', [
                'user_id' => $userId,
                'meta' => ['planned' => $summary['planned'], 'executed' => $summary['executed'], 'tasks_created' => $summary['tasks_created'], 'skipped' => $summary['skipped']],
            ]);
            return $this->success(['summary' => $summary], 'تم تنفيذ إجراءات الإيرادات');
        } catch (Throwable $e) {
            return $this->serverError('actions-execute', $e);
        }
    }

    /** GET /api/revenue-intelligence/actions/history - سجل عمليات التنفيذ. */
    public function apiActionsHistory(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $userId = (int) $this->user['id'];
        try {
            $history = $this->actionExecutor->history($userId, (int) $this->get('limit', 20));
            return $this->success(['has_data' => !empty($history), 'history' => $history]);
        } catch (Throwable $e) {
            return $this->serverError('actions-history', $e);
        }
    }

    /** GET /api/revenue-intelligence/executive-summary */
    public function apiExecutiveSummary(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $userId = (int) $this->user['id'];
        try {
            $summary = $this->cacheService->rememberExecutiveSummary($userId, function () use ($userId) {
                return $this->executiveSummaryService->getSummary($userId);
            });
            ActivityLog::record('revenue_intelligence', 'executive_summary.viewed', ['user_id' => $userId]);
            return $this->success($summary);
        } catch (Throwable $e) {
            return $this->serverError('executive-summary', $e);
        }
    }

    /** POST /api/revenue-intelligence/assistant/ask { question } */
    public function apiAssistantAsk(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $question = trim((string) $this->get('question', ''));
        if ($question === '') {
            return $this->error($this->tr('revai.assistant.empty_question'), 422);
        }
        if (mb_strlen($question) > 500) {
            return $this->error('Question too long (max 500 characters)', 422);
        }
        try {
            $lang = (string) $this->get('lang', 'ar');
            $lang = in_array($lang, ['ar', 'en'], true) ? $lang : 'ar';
            $answer = $this->assistantService->askWithCopilot((int) $this->user['id'], $question, true, $lang);
            return $this->success($answer);
        } catch (Throwable $e) {
            return $this->serverError('assistant', $e);
        }
    }

    /**
     * GET /api/revenue-intelligence/retention
     * NRR/GRR-style retention analytics مبنية على بيانات حقيقية
     * (cohort retention من crm_deals + repeat purchase + recurring stability).
     */
    public function apiRetention(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $retention = (new RevenueRetentionService())->getRetentionAnalytics((int) $this->user['id']);
            return $this->success($retention);
        } catch (Throwable $e) {
            return $this->serverError('retention', $e);
        }
    }

    /**
     * GET /api/revenue-intelligence/subscriptions
     * v1.5.0: MRR/ARR/NRR/GRR حرفية من جدول biz_subscriptions + events.
     */
    public function apiSubscriptionMetrics(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $metrics = (new BizSubscriptionService())->getSubscriptionMetrics((int) $this->user['id']);
            ActivityLog::record('revenue_intelligence', 'subscriptions.metrics_viewed', ['user_id' => (int) $this->user['id']]);
            return $this->success($metrics);
        } catch (Throwable $e) {
            return $this->serverError('subscriptions', $e);
        }
    }

    /**
     * GET /api/revenue-intelligence/forecast/deals
     * v1.5.0: Deal-level forecast (this month/quarter/later/undated).
     */
    public function apiDealForecast(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $deals = (new RevenueDataGateway())->getDealsWithRep((int) $this->user['id']);
            $forecast = DealLevelForecastService::groupOpenDealsByCloseWindow($deals);
            return $this->success($forecast);
        } catch (Throwable $e) {
            return $this->serverError('forecast/deals', $e);
        }
    }

    /**
     * GET /api/revenue-intelligence/attribution
     * v1.5.0: Sales attribution بالمناديب والفرق.
     */
    public function apiSalesAttribution(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $deals = (new RevenueDataGateway())->getDealsWithRep((int) $this->user['id']);
            $attribution = [
                'by_rep' => DealLevelForecastService::aggregateByRep($deals),
                'by_team' => DealLevelForecastService::aggregateByTeam($deals),
            ];
            return $this->success($attribution);
        } catch (Throwable $e) {
            return $this->serverError('attribution', $e);
        }
    }

    /**
     * GET /api/revenue-intelligence/benchmarks
     * v1.5.0: Benchmarks منصية حقيقية (أو يدوية مسجلة). لا أرقام مخترعة.
     */
    public function apiBenchmarks(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $benchmarks = (new RevenueBenchmarkService())->getBenchmarks((int) $this->user['id']);
            return $this->success($benchmarks);
        } catch (Throwable $e) {
            return $this->serverError('benchmarks', $e);
        }
    }

    /**
     * GET /api/revenue-intelligence/quotas?period=YYYY-MM
     * G7: أهداف/حصص المبيعات مع الإنجاز والتنبؤ (من crm_sales_goals).
     */
    public function apiQuotas(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $period = (string) $this->get('period', '');
            if ($period !== '' && !preg_match('/^\d{4}-\d{2}$/', $period)) {
                return $this->error('صيغة الشهر غير صالحة (YYYY-MM)', 422);
            }
            $quotas = $this->quotaService->getQuotas((int) $this->user['id'], $period !== '' ? $period : null);
            return $this->success($quotas);
        } catch (Throwable $e) {
            return $this->serverError('quotas', $e);
        }
    }

    /**
     * GET /api/revenue-intelligence/churn
     * v1.5.0: Churn analytics + أسباب التوقف من بيانات حقيقية فقط.
     */
    public function apiChurnAnalytics(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $churn = (new RevenueChurnService())->getChurnAnalytics((int) $this->user['id']);
            return $this->success($churn);
        } catch (Throwable $e) {
            return $this->serverError('churn', $e);
        }
    }

    /**
     * GET /api/revenue-intelligence/dashboard-prefs
     * v1.6.0: قراءة تخصيص الداشبورد الحالي (أو الافتراضي).
     */
    public function apiDashboardPrefsGet(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $prefs = (new RevenueDashboardService())->getLayout((int) $this->user['id']);
            return $this->success($prefs);
        } catch (Throwable $e) {
            return $this->serverError('dashboard-prefs', $e);
        }
    }

    /**
     * POST /api/revenue-intelligence/dashboard-prefs { widgets: [...] }
     * v1.6.0: حفظ تخصيص الداشبورد (يُطبَّع ضد القائمة المعروفة فقط).
     */
    public function apiDashboardPrefsSave(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $layout = (array) $this->get('layout', $this->get('widgets', []));
            $saved = (new RevenueDashboardService())->saveLayout((int) $this->user['id'], $layout);
            return $this->success($saved);
        } catch (Throwable $e) {
            return $this->serverError('dashboard-prefs', $e);
        }
    }

    /**
     * POST /api/revenue-intelligence/dashboard-prefs/reset
     * v1.6.0: إعادة التخصيص للوضع الافتراضي.
     */
    public function apiDashboardPrefsReset(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $default = (new RevenueDashboardService())->resetLayout((int) $this->user['id']);
            return $this->success($default);
        } catch (Throwable $e) {
            return $this->serverError('dashboard-prefs', $e);
        }
    }

    /**
     * GET /api/revenue-intelligence/stripe/settings
     * v1.6.0: قراءة حالة اتصال Stripe (السر لا يُعاد أبدًا - فقط مؤشر مفعّل/لا).
     */
    public function apiStripeSettingsGet(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $settings = (new RevenueDataGateway())->getStripeSettings((int) $this->user['id']);
            return $this->success([
                'has_settings' => $settings !== null,
                'is_enabled' => !empty($settings['is_enabled']),
                'mode' => (string) ($settings['mode'] ?? 'test'),
                'connected_account_id' => $settings['connected_account_id'] ?? null,
                'has_webhook_secret' => !empty($settings['webhook_secret_enc']),
                'last_event_at' => $settings['last_event_at'] ?? null,
                'last_event_type' => $settings['last_event_type'] ?? null,
                'webhook_url' => $this->buildStripeWebhookUrl((int) $this->user['id']),
            ]);
        } catch (Throwable $e) {
            return $this->serverError('stripe-settings', $e);
        }
    }

    /**
     * POST /api/revenue-intelligence/stripe/settings
     * { webhook_secret (نص صريح من المستخدم - يُشفَّر عند الحفظ),
     *   connected_account_id?, mode? }
     * v1.6.0: حفظ إعدادات Stripe. السر يُشفَّر عبر Encryption قبل التخزين.
     */
    public function apiStripeSettingsSave(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $secret = trim((string) $this->get('webhook_secret', ''));
            $account = trim((string) $this->get('connected_account_id', ''));
            $mode = (string) $this->get('mode', 'test');
            $enabled = (bool) $this->get('is_enabled', $secret !== '');

            $enc = $secret !== '' ? (new Encryption())->encrypt($secret, 'revai_stripe_' . $this->user['id']) : '';
            (new RevenueDataGateway())->upsertStripeSettings((int) $this->user['id'], [
                'webhook_secret_enc' => $enc,
                'connected_account_id' => $account,
                'mode' => $mode,
                'is_enabled' => $enabled,
            ]);
            return $this->success(['saved' => true, 'has_webhook_secret' => $secret !== '', 'mode' => $mode, 'is_enabled' => $enabled]);
        } catch (Throwable $e) {
            return $this->serverError('stripe-settings', $e);
        }
    }

    /**
     * POST /api/revenue-intelligence/stripe/webhook/{user_id}
     * v1.6.0: Webhook حقيقي من Stripe (public - بدون AuthMiddleware).
     * التحقق: توقيع Stripe-Signature ضد السر المشفر للمستخدم المحدد.
     * الفشل = 401؛ التكرار = duplicate (idempotent)؛ النجاح = processed.
     */
    public function apiStripeWebhook(array $params = []): array
    {
        try {
            $targetUserId = (int) ($params['user_id'] ?? 0);
            if ($targetUserId <= 0) {
                return $this->error('Missing user_id', 400);
            }
            $settings = (new RevenueDataGateway())->getStripeSettings($targetUserId);
            if ($settings === null || empty($settings['webhook_secret_enc']) || empty($settings['is_enabled'])) {
                return $this->error('Stripe integration not configured', 403);
            }

            $secret = (new Encryption())->decrypt((string) $settings['webhook_secret_enc'], 'revai_stripe_' . $targetUserId);
            if ($secret === '') {
                return $this->error('Stripe integration misconfigured', 403);
            }

            $rawBody = file_get_contents('php://input');
            $signatureHeader = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
            if (!StripeWebhookService::verifySignature((string) $rawBody, $signatureHeader, $secret)) {
                return $this->error('Invalid webhook signature', 401);
            }

            $payload = json_decode((string) $rawBody, true);
            if (!is_array($payload) || empty($payload['type'])) {
                return $this->error('Invalid Stripe payload', 422);
            }

            $result = (new StripeWebhookService())->handleEvent($targetUserId, $payload, $settings);
            ActivityLog::record('revenue_intelligence', 'stripe.webhook.' . $result['status'], ['user_id' => $targetUserId, 'type' => $payload['type'] ?? '']);
            return $this->success($result);
        } catch (Throwable $e) {
            return $this->serverError('stripe-webhook', $e);
        }
    }

    /** بناء رابط الـ webhook الكامل (يُعرض للمستخدم ليلصقه في لوحة Stripe). */
    private function buildStripeWebhookUrl(int $userId): string
    {
        $base = env('APP_URL', '');
        if ($base === '') {
            $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
        return rtrim($base, '/') . '/api/revenue-intelligence/stripe/webhook/' . $userId;
    }

    /**
     * GET /api/revenue-intelligence/reports/export?type=overview|opportunities|risks|customers&format=csv
     * Section 13: REVENUE REPORTS (export). Section 17: يُسجَّل كـ Audit Log.
     * يدعم format=json (افتراضي) أو format=csv (ملف حقيقي قابل للتنزيل)،
     * وDate Range اختياري (from/to بصيغة Y-m-d) لتقرير overview.
     */
    public function apiExportReport(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $type = (string) $this->get('type', 'overview');
        $format = (string) $this->get('format', 'json');
        $userId = (int) $this->user['id'];

        $rows = null;
        $columns = [];
        switch ($type) {
            case 'overview':
                $columns = ['date', 'revenue'];
                $range = $this->resolveExportDateRange();
                if ($range !== null) {
                    $gateway = new RevenueDataGateway();
                    $series = $gateway->getDailyRevenueSeries($userId, $range['from'] . ' 00:00:00', $range['to'] . ' 23:59:59');
                    $rows = array_map(static function ($t) {
                        return ['date' => $t['date'], 'revenue' => $t['revenue']];
                    }, $series);
                } else {
                    $overview = $this->overviewService->getOverview($userId, $this->validPeriod($this->get('period', 'monthly')));
                    $rows = array_map(static function ($t) {
                        return ['date' => $t['date'], 'revenue' => $t['revenue']];
                    }, $overview['daily_trend']);
                }
                break;
            case 'opportunities':
                $columns = ['title', 'confidence', 'estimated_impact', 'recommended_action'];
                $rows = array_map(static function ($o) {
                    return ['title' => $o['title'], 'confidence' => $o['confidence'], 'estimated_impact' => $o['estimated_impact'], 'recommended_action' => $o['recommended_action']];
                }, $this->insightService->getOpportunities($userId));
                break;
            case 'risks':
                $columns = ['title', 'severity', 'confidence', 'recommended_action'];
                $rows = array_map(static function ($r) {
                    return ['title' => $r['title'], 'severity' => $r['severity'] ?? '', 'confidence' => $r['confidence'], 'recommended_action' => $r['recommended_action']];
                }, $this->insightService->getRisks($userId));
                break;
            case 'customers':
                $columns = ['name', 'customer_revenue', 'segment', 'last_purchase'];
                $intel = $this->customerService->getCustomerRevenueIntelligence($userId);
                $rows = $intel['has_data'] ? array_map(static function ($c) {
                    return ['name' => $c['name'], 'customer_revenue' => $c['customer_revenue'], 'segment' => $c['value_segment'], 'last_purchase' => $c['last_purchase']];
                }, $intel['customers']) : [];
                break;
            case 'pipeline_forecast':
                $columns = ['title', 'value', 'probability', 'expected_close_date', 'stage'];
                $pipeline = $this->pipelineService->getPipelineIntelligence($userId);
                $rows = ($pipeline['has_data'] ?? false)
                    ? array_merge($pipeline['pipeline']['likely_wins'], $pipeline['pipeline']['at_risk_deals'])
                    : [];
                break;
            default:
                return $this->error('Unknown report type', 422);
        }

        ActivityLog::record('revenue_intelligence', 'report.exported', ['user_id' => $userId, 'meta' => ['type' => $type, 'format' => $format]]);

        if ($format === 'csv') {
            $this->streamCsv($type, $columns, $rows ?? []);
            exit; // streamCsv() already sent headers + content - لازم نوقف هنا عشان الـ Router متطلعش JSON فوق الملف
        }

        if (empty($rows)) {
            return $this->success(['rows' => [], 'message' => 'Not enough data']);
        }
        return $this->success(['rows' => $rows]);
    }

    /** يقرأ ?from=Y-m-d&to=Y-m-d من الطلب لو الاتنين موجودين وصالحين، وإلا يرجّع null (نرجع لسلوك period الافتراضي). */
    private function resolveExportDateRange(): ?array
    {
        $from = (string) $this->get('from', '');
        $to = (string) $this->get('to', '');
        if ($from === '' || $to === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return null;
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return ['from' => $from, 'to' => $to];
    }

    /** يبني ملف CSV حقيقي (UTF-8 BOM عشان يفتح صح في Excel) ويبعته كـ Attachment، بدل رد JSON. */
    private function streamCsv(string $type, array $columns, array $rows): void
    {
        $filename = 'revenue-' . preg_replace('/[^a-z0-9_-]/i', '-', $type) . '-' . date('Y-m-d') . '.csv';

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
        }

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM

        if (!empty($rows)) {
            $header = $columns ?: array_keys($rows[0]);
            fputcsv($out, $header);
            foreach ($rows as $row) {
                $line = [];
                foreach ($header as $col) {
                    $line[] = $row[$col] ?? '';
                }
                fputcsv($out, $line);
            }
        } else {
            fputcsv($out, ['message']);
            fputcsv($out, ['Not enough data']);
        }

        fclose($out);
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function validPeriod(string $period): string
    {
        return in_array($period, ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'], true) ? $period : 'monthly';
    }

    /** Section 18 (Pagination): يقطّع أي مصفوفة داخل $result[$key] ويضيف meta.pagination. */
    private function paginate(array $result, string $key): array
    {
        $page = max(1, (int) $this->get('page', 1));
        $perPage = min(100, max(1, (int) $this->get('per_page', 25)));

        $items = $result[$key] ?? [];
        $total = count($items);
        $offset = ($page - 1) * $perPage;

        $result[$key] = array_slice($items, $offset, $perPage);
        $result['pagination'] = [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $total > 0 ? (int) ceil($total / $perPage) : 1,
        ];
        return $result;
    }

    private function serverError(string $context, Throwable $e): array
    {
        if (class_exists('Logger')) {
            Logger::error("RevenueIntelligence[{$context}] error", ['message' => $e->getMessage()]);
        }
        return $this->error($this->tr('revai.error.generic'), 500);
    }

    /** JS الخاص بالصفحة - Tabs + Fetch لكل قسم. */
    // ============================================================
    // رحلات الموسم ← منشورات سوشيال
    // ============================================================

    private function seasonService(): SeasonTripPosts
    {
        require_once __DIR__ . '/../Services/RevenueIntelligence/SeasonTripPosts.php';
        require_once __DIR__ . '/../Services/SocialMedia/SocialMonthlyPlanService.php';
        require_once __DIR__ . '/../Jobs/GenerateSeasonPostsJob.php';
        SeasonTripPosts::ensureSchema();
        return new SeasonTripPosts();
    }

    /** GET /api/revenue-intelligence/season - الرحلات مرتبة حسب الطلب في الموسم + آخر المنشورات */
    public function apiSeason(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $svc = $this->seasonService();
            $uid = (int) $this->user['id'];
            $d = $svc->demand($uid);
            $q = class_exists('PlanUsage') ? PlanUsage::describe($uid, 'media_image') : null;
            return $this->success($d + ['posts' => $svc->list($uid), 'image_cost' => $q ? $q['text'] : null, 'max_trips' => SeasonTripPosts::MAX_TRIPS, 'installed' => SeasonTripPosts::ready(), 'csrf_token' => Csrf::token()]);
        } catch (Throwable $e) {
            return $this->serverError('season', $e);
        }
    }

    /** POST /api/revenue-intelligence/season/sync - بيقرا رحلات موقع العميل (sitemap) ويحفظها، وبيرجّع الموسم من جديد */
    public function apiSeasonSync(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($e = $this->verifyCsrf()) || ($e = $this->rateLimitGuard('user', 'season_sync', 4, 600))) {
            return $e;
        }
        @set_time_limit(120);
        $uid = (int) $this->user['id'];
        try {
            $r = (new SeasonSiteTrips($this->db))->sync($uid);
            $svc = $this->seasonService();
            return $this->success(['sync' => $r] + $svc->demand($uid) + ['posts' => $svc->list($uid)]);
        } catch (Throwable $e) {
            return $this->serverError('season_sync', $e);
        }
    }

    /** POST /api/revenue-intelligence/season/volumes - حجم البحث الحقيقي لكل رحلة بالاسم (DataForSEO)، مخزّن 30 يوم */
    public function apiSeasonVolumes(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($e = $this->verifyCsrf()) || ($e = $this->rateLimitGuard('user', 'season_volumes', 4, 600))) {
            return $e;
        }
        @set_time_limit(120);
        $uid = (int) $this->user['id'];
        try {
            $svc = $this->seasonService();
            $r = (new SeasonTripVolumes($this->db))->measure($uid, $svc->demand($uid)['trips']);
            return $this->success(['volumes' => $r] + $svc->demand($uid) + ['posts' => $svc->list($uid)]);
        } catch (Throwable $e) {
            return $this->serverError('season_volumes', $e);
        }
    }

    /** POST /api/revenue-intelligence/season/posts  body: {product_ids[], language, request_key} */
    public function apiSeasonGenerate(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($e = $this->verifyCsrf()) || ($e = $this->rateLimitGuard('user', 'season_posts', 6, 600))) {
            return $e;
        }
        $uid = (int) $this->user['id'];
        try {
            $svc = $this->seasonService();
            $check = PlanUsage::check($uid, 'marketing_tool', 'marketing_tool', 'رصيد المحفظة مش كفاية لتجهيز المنشورات. اشحن المحفظة أو رقّي باقتك.');
            if (empty($check['ok'])) {
                return $this->error((string) ($check['error'] ?? 'خلصت حصتك'), 402, ['upgrade_url' => '/subscription']);
            }
            $ids = $this->get('trip_keys', $this->get('product_ids', []));
            $r = $svc->start($uid, is_array($ids) ? $ids : [], (string) $this->get('language', 'ar'), (string) $this->get('request_key', ''));
            if (empty($r['already'])) {
                // استخدام واحد من أدوات التسويق للدفعة كلها (الصور المولّدة بتتحسب لوحدها زي استوديو المحتوى)
                PlanUsage::charge($uid, 'marketing_tool', 'marketing_tool', 'منشورات رحلات الموسم');
                $job = Container::getInstance()->make(QueueManager::class)->push(GenerateSeasonPostsJob::class, ['user_id' => $uid, 'batch' => $r['batch']], 'social');
                if (!$job) {
                    $svc->process($uid, $r['batch'], 1);
                }
            }
            return $this->success($r + ['posts' => $svc->list($uid, $r['batch'])], 'بنجهّز المنشورات…', 201);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->serverError('season_generate', $e);
        }
    }

    /** GET /api/revenue-intelligence/season/posts?batch= - متابعة التجهيز */
    public function apiSeasonPosts(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $svc = $this->seasonService();
            $uid = (int) $this->user['id'];
            $batch = (string) $this->get('batch', '');
            $batch = preg_match('/^sp_\d+_[a-f0-9]{16}$/', $batch) ? $batch : null;
            // لو مهام الخلفية مش شغالة على الاستضافة: نجهّز منشور واحد مع كل متابعة عشان ما يفضلش مستني
            if ($batch && !(class_exists('SocialMonthlyPlanService') ? SocialMonthlyPlanService::worker()['ready'] : false)) {
                $svc->process($uid, $batch, 1);
            }
            return $this->success(['posts' => $svc->list($uid, $batch)]);
        } catch (Throwable $e) {
            return $this->serverError('season_posts', $e);
        }
    }

    /** GET /api/revenue-intelligence/season/posts/{id} - للمحرر في السوشيال */
    public function apiSeasonPost(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $d = $this->seasonService()->get((int) $this->user['id'], (int) ($params['id'] ?? 0));
        return $d ? $this->success(['post' => $d]) : $this->error('المنشور مش موجود', 404);
    }

    /** POST /api/revenue-intelligence/season/posts/{id}/state  body: {state: used|dismissed, social_post_id?} */
    public function apiSeasonPostState(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        $svc = $this->seasonService();
        $uid = (int) $this->user['id'];
        $id = (int) ($params['id'] ?? 0);
        if ($this->get('state') === 'dismissed') {
            $svc->dismiss($uid, $id);
        } else {
            $pid = (int) $this->get('social_post_id', 0);
            $svc->markUsed($uid, $id, $pid > 0 ? $pid : null);
        }
        return $this->success(['ok' => true]);
    }

    private function pageScript(): string
    {
        return <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON, toast = P.toast;
    const panel = document.getElementById('revaiPanel');
    const periodSelect = document.getElementById('revaiPeriod');
    let activeTab = 'executive';
    const charts = {};
    function destroyChart(key) { if (charts[key]) { charts[key].destroy(); delete charts[key]; } }
    function lineChart(canvasId, labels, datasets) {
        const ctx = document.getElementById(canvasId);
        if (!ctx || typeof Chart === 'undefined') return;
        destroyChart(canvasId);
        charts[canvasId] = new Chart(ctx, {
            type: 'line',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { family: 'Tajawal' } } } },
                scales: { y: { beginAtZero: true } },
            },
        });
    }
    function barChart(canvasId, labels, datasets) {
        const ctx = document.getElementById(canvasId);
        if (!ctx || typeof Chart === 'undefined') return;
        destroyChart(canvasId);
        charts[canvasId] = new Chart(ctx, {
            type: 'bar',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: datasets.length > 1, position: 'bottom', labels: { boxWidth: 10, font: { family: 'Tajawal' } } } },
                scales: { y: { beginAtZero: true } },
            },
        });
    }

    function fmt(n) {
        if (n === null || n === undefined) return '-';
        return window.Panel && window.Panel.money ? window.Panel.money(n) : '$' + (parseFloat(n) || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });
    }
    function pct(n) { return (n === null || n === undefined) ? '-' : (n > 0 ? '+' : '') + n + '%'; }
    function loadingHtml() { return `<div class="p-empty">${I18N['common.loading']}</div>`; }
    function emptyHtml(msg) { return `<div class="p-card"><div class="p-empty">${esc(msg || I18N['common.no_records_yet'])}</div></div>`; }

    function badge(text, color) {
        return `<span style="display:inline-block;padding:2px 10px;border-radius:20px;font-size:12px;background:${color}20;color:${color};font-weight:600;">${esc(text)}</span>`;
    }
    const confColor = { high: '#22C55E', medium: '#F59E0B', low: '#EF4444' };
    const sevColor = { high: '#EF4444', medium: '#F59E0B', low: '#6366F1' };

    function insightCard(item, kind) {
        const color = kind === 'risk' ? (sevColor[item.severity] || '#6366F1') : (confColor[item.confidence] || '#6366F1');
        return `<div class="p-card" style="margin-bottom:12px;border-inline-start:4px solid ${color};">
            <div style="display:flex;justify-content:space-between;align-items:start;gap:10px;flex-wrap:wrap;">
                <h4 style="margin:0;">${esc(item.title)}</h4>
                <div style="display:flex;gap:6px;">${item.severity ? badge(item.severity, sevColor[item.severity] || '#6366F1') : ''}${badge(item.confidence, confColor[item.confidence] || '#6366F1')}</div>
            </div>
            <p style="margin:8px 0;color:var(--text-muted,#666);">${esc(item.finding)}</p>
            ${item.reasoning_summary ? `<p style="margin:4px 0;font-size:13px;opacity:.8;">${esc(item.reasoning_summary)}</p>` : ''}
            ${item.estimated_impact ? `<p style="margin:4px 0;font-size:13px;"><b>${I18N['revai.estimated_impact']}:</b> ${fmt(item.estimated_impact)}</p>` : ''}
            <p style="margin:8px 0 0;font-size:13px;"><b>${I18N['revai.recommended_action']}:</b> ${esc(item.recommended_action || '-')}</p>
        </div>`;
    }

    const execWidgetOrder = ['current_revenue', 'growth_percent', 'forecast', 'top_opportunity', 'top_risk', 'top_customer_segment', 'top_revenue_source', 'recommended_actions'];

    function execWidgetHtml(key, d) {
        const labels = {
            current_revenue: I18N['revai.exec.current_revenue'],
            growth_percent: I18N['revai.exec.growth'],
            forecast: I18N['revai.exec.forecast'],
            top_opportunity: I18N['revai.exec.top_opportunity'],
            top_risk: I18N['revai.exec.top_risk'],
            top_customer_segment: I18N['revai.exec.top_segment'],
            top_revenue_source: I18N['revai.exec.top_source'],
            recommended_actions: I18N['revai.exec.recommended_actions'],
        };
        switch (key) {
            case 'current_revenue':
                return `<div class="p-card stat-tile"><div class="stat-icon green">💰</div><div class="stat-info"><div class="stat-value">${fmt(d.current_revenue)}</div><div class="stat-label">${labels[key]}</div></div></div>`;
            case 'growth_percent':
                return `<div class="p-card stat-tile"><div class="stat-icon purple">📈</div><div class="stat-info"><div class="stat-value">${pct(d.growth_percent)}</div><div class="stat-label">${labels[key]}</div></div></div>`;
            case 'forecast':
                return `<div class="p-card stat-tile"><div class="stat-icon blue">🔮</div><div class="stat-info"><div class="stat-value">${d.forecast ? fmt(d.forecast.expected_revenue) : '-'}</div><div class="stat-label">${labels[key]}</div></div></div>`;
            case 'top_opportunity':
                return `<div class="p-card"><h4>${labels[key]}</h4><p>${d.top_opportunity ? esc(d.top_opportunity.title) + ' — ' + esc(d.top_opportunity.recommended_action) : I18N['common.no_records_yet']}</p></div>`;
            case 'top_risk':
                return `<div class="p-card"><h4>${labels[key]}</h4><p>${d.top_risk ? esc(d.top_risk.title) + ' — ' + esc(d.top_risk.recommended_action) : I18N['common.no_records_yet']}</p></div>`;
            case 'top_customer_segment':
                return `<div class="p-card"><h4>${labels[key]}</h4><p>${d.top_customer_segment ? esc(d.top_customer_segment.segment) + ' (' + d.top_customer_segment.customer_count + ')' : I18N['common.no_records_yet']}</p></div>`;
            case 'top_revenue_source':
                return `<div class="p-card"><h4>${labels[key]}</h4><p>${d.top_revenue_source ? esc(d.top_revenue_source.source) + ' — ' + fmt(d.top_revenue_source.revenue) : I18N['common.no_records_yet']}</p></div>`;
            case 'recommended_actions':
                return `<div class="p-card">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                        <h4 style="margin:0;">${labels[key]}</h4>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                            <button class="p-btn xs" id="revaiExecPreviewBtn">${I18N['revai.exec.preview']}</button>
                            <button class="p-btn primary xs" id="revaiExecBtn">⚡ ${I18N['revai.exec.execute']}</button>
                        </div>
                    </div>
                    ${(d.recommended_actions || []).map(a => `<div style="padding:8px 0;border-bottom:1px solid var(--border,#eee);"><div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;"><b>${esc(a.action)}</b>${a.severity ? badge(a.severity, sevColor[a.severity] || '#888') : ''}</div><div style="font-size:12.5px;opacity:.85;margin-top:2px;">${esc(a.reason)}</div></div>`).join('') || `<div class="p-empty">${I18N['common.no_records_yet']}</div>`}
                    <div id="revaiExecResult" style="margin-top:10px;font-size:12.5px;"></div>
                    <div id="revaiExecHistory" style="margin-top:8px;"></div>
                </div>`;
        }
        return '';
    }

    async function renderExecutive() {
        panel.innerHTML = loadingHtml();
        const [res, prefsRes] = await Promise.all([
            fetchJSON('/api/revenue-intelligence/executive-summary'),
            fetchJSON('/api/revenue-intelligence/dashboard-prefs')
        ]);
        if (!res.success) { panel.innerHTML = emptyHtml(res.error); return; }
        const d = res.data;
        let layout = prefsRes.success ? (prefsRes.data.widgets || []) : null;
        let visible = execWidgetOrder;
        if (layout && layout.length) {
            visible = layout.filter(w => w.visible).map(w => w.key).filter(k => execWidgetOrder.indexOf(k) >= 0);
        }
        const statKeys = visible.filter(k => ['current_revenue', 'growth_percent', 'forecast'].indexOf(k) >= 0);
        const cardKeys = visible.filter(k => ['top_opportunity', 'top_risk', 'top_customer_segment', 'top_revenue_source'].indexOf(k) >= 0);
        const actionsShown = visible.indexOf('recommended_actions') >= 0;
        const statGrid = statKeys.length > 1 ? `p-grid cols-${Math.min(4, statKeys.length)}` : 'p-grid';
        const cardGrid = cardKeys.length > 1 ? 'p-grid cols-2' : 'p-grid';

        const customizePanel = `
            <div class="p-card" style="margin-bottom:18px;border:1px dashed var(--border,#ccc);">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                    <div><b>${I18N['revai.prefs.title']}</b><p style="margin:4px 0 0;font-size:12px;opacity:.75;">${I18N['revai.prefs.hint']}</p></div>
                    <button class="p-btn" id="revaiPrefsToggle">${I18N['revai.prefs.customize']}</button>
                </div>
                <div id="revaiPrefsEditor" style="display:none;margin-top:12px;">
                    ${execWidgetOrder.map((k, i) => `
                        <div style="display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid var(--border,#eee);">
                            <span style="opacity:.5;cursor:grab;">☰</span>
                            <input type="checkbox" data-prefs-key="${k}" ${(() => { const w = layout && layout.find(x => x.key === k); return (!w || w.visible) ? 'checked' : ''; })()}>
                            <label style="flex:1;margin:0;">${I18N['revai.prefs.w.' + k] || k}</label>
                            <select data-prefs-order="${k}" style="width:60px;">
                                ${execWidgetOrder.map((_, j) => `<option value="${j}" ${j === i ? 'selected' : ''}>${j + 1}</option>`).join('')}
                            </select>
                        </div>`).join('')}
                    <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
                        <button class="p-btn primary" id="revaiPrefsSave">${I18N['revai.prefs.save']}</button>
                        <button class="p-btn" id="revaiPrefsReset">${I18N['revai.prefs.reset']}</button>
                    </div>
                </div>
            </div>`;

        panel.innerHTML = customizePanel + `
            <div class="${statGrid}" style="margin-bottom:18px;">
                ${statKeys.map(k => execWidgetHtml(k, d)).join('')}
            </div>
            <div class="${cardGrid}">
                ${cardKeys.map(k => execWidgetHtml(k, d)).join('')}
            </div>
            ${actionsShown ? `<div class="p-card" style="margin-top:18px;">${execWidgetHtml('recommended_actions', d)}</div>` : ''}`;

        const toggleBtn = document.getElementById('revaiPrefsToggle');
        const editor = document.getElementById('revaiPrefsEditor');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => { editor.style.display = editor.style.display === 'none' ? 'block' : 'none'; });
        }
        const saveBtn = document.getElementById('revaiPrefsSave');
        if (saveBtn) {
            saveBtn.addEventListener('click', async () => {
                const widgets = execWidgetOrder.map((k, i) => ({
                    key: k,
                    visible: document.querySelector(`[data-prefs-key="${k}"]`).checked,
                    order: parseInt(document.querySelector(`[data-prefs-order="${k}"]`).value, 10) || i
                }));
                const saved = await fetchJSON('/api/revenue-intelligence/dashboard-prefs', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ layout: { widgets } })
                });
                if (saved.success) { toast(I18N['revai.prefs.saved'], 'success'); renderExecutive(); } else { toast(saved.error, 'error'); }
            });
        }
        const resetBtn = document.getElementById('revaiPrefsReset');
        if (resetBtn) {
            resetBtn.addEventListener('click', async () => {
                const done = await fetchJSON('/api/revenue-intelligence/dashboard-prefs/reset', { method: 'POST' });
                if (done.success) { toast(I18N['revai.prefs.reset_done'], 'success'); renderExecutive(); } else { toast(done.error, 'error'); }
            });
        }
        const execBtn = document.getElementById('revaiExecBtn');
        if (execBtn) execBtn.addEventListener('click', revaiExecute);
        const previewBtn = document.getElementById('revaiExecPreviewBtn');
        if (previewBtn) previewBtn.addEventListener('click', revaiExecPreview);
        if (d.recommended_actions && d.recommended_actions.length) revaiLoadExecHistory();
    }

    async function revaiExecPreview() {
        const box = document.getElementById('revaiExecResult');
        if (!box) return;
        box.innerHTML = '<span class="p-cell-muted">' + esc(I18N['common.loading']) + '</span>';
        const res = await fetchJSON('/api/revenue-intelligence/actions/execute?dry_run=1', { method: 'POST' });
        if (!res.success) { box.innerHTML = '<span style="color:#EF4444;">' + esc(res.error) + '</span>'; return; }
        const s = res.data.summary;
        box.innerHTML = `<span style="color:var(--panel-text-muted);">${I18N['revai.exec.preview_done'].replace('{n}', s.executed).replace('{t}', s.tasks_created).replace('{s}', s.skipped)}</span>`;
    }

    async function revaiExecute() {
        const btn = document.getElementById('revaiExecBtn');
        const box = document.getElementById('revaiExecResult');
        if (!btn || !box) return;
        btn.disabled = true;
        box.innerHTML = '<span class="p-cell-muted">' + esc(I18N['common.loading']) + '</span>';
        const res = await fetchJSON('/api/revenue-intelligence/actions/execute', { method: 'POST' });
        btn.disabled = false;
        if (!res.success) { box.innerHTML = '<span style="color:#EF4444;">' + esc(res.error) + '</span>'; toast(res.error, 'error'); return; }
        const s = res.data.summary;
        box.innerHTML = `<span style="color:#22C55E;">${I18N['revai.exec.done'].replace('{n}', s.executed).replace('{t}', s.tasks_created).replace('{p}', s.notifications_sent).replace('{s}', s.skipped)}</span>`;
        toast(I18N['revai.exec.done_toast'], 'success');
        revaiLoadExecHistory();
    }

    async function revaiLoadExecHistory() {
        const box = document.getElementById('revaiExecHistory');
        if (!box) return;
        const res = await fetchJSON('/api/revenue-intelligence/actions/history?limit=5');
        if (!res.success || !res.data.history.length) return;
        box.innerHTML = `<div style="font-size:11px;color:var(--panel-text-muted);margin-bottom:4px;">${I18N['revai.exec.history']}:</div>` + res.data.history.map(h =>
            `<div style="font-size:12px;padding:3px 0;border-bottom:1px dashed var(--border,#eee);"><span style="opacity:.7;">${esc(h.action_key)}</span> — <span style="opacity:.85;">${esc((h.actions_taken || '').replace(/[\[\]"]/g, ''))}</span></div>`
        ).join('');
    }

    async function renderOverview() {
        panel.innerHTML = loadingHtml();
        const res = await fetchJSON('/api/revenue-intelligence/overview?period=' + periodSelect.value);
        if (!res.success) { panel.innerHTML = emptyHtml(res.error); return; }
        const d = res.data;
        if (!d.has_data) { panel.innerHTML = emptyHtml(I18N['revai.no_revenue_data']); return; }
        const warningHtml = d.mixed_currency_warning
            ? `<div class="p-card" style="border-inline-start:4px solid #EF4444;margin-bottom:14px;"><b>⚠️ ${I18N['revai.currency_warning_title']}</b><p style="margin:6px 0 0;font-size:13px;">${esc(d.mixed_currency_warning)}</p></div>`
            : '';
        panel.innerHTML = warningHtml + `
            <div class="p-grid cols-4" style="margin-bottom:18px;">
                <div class="p-card stat-tile"><div class="stat-icon green">💰</div><div class="stat-info"><div class="stat-value">${fmt(d.total_revenue)}</div><div class="stat-label">${I18N['revai.overview.total']}</div></div></div>
                <div class="p-card stat-tile"><div class="stat-icon purple">📈</div><div class="stat-info"><div class="stat-value">${pct(d.growth_percent)}</div><div class="stat-label">${I18N['revai.overview.growth']}</div></div></div>
                <div class="p-card stat-tile"><div class="stat-icon blue">🔁</div><div class="stat-info"><div class="stat-value">${d.recurring_revenue.available ? fmt(d.recurring_revenue.monthly_recurring_revenue) : '-'}</div><div class="stat-label">${I18N['revai.overview.recurring']}</div></div></div>
                <div class="p-card stat-tile"><div class="stat-icon orange">🎯</div><div class="stat-info"><div class="stat-value">${d.roas ? d.roas + 'x' : '-'}</div><div class="stat-label">${I18N['revai.overview.roas']}</div></div></div>
            </div>
            <div class="p-card" style="margin-bottom:18px;">
                <h3 style="margin:0 0 12px;">${I18N['revai.chart.daily_trend']}</h3>
                <div style="height:260px;"><canvas id="revaiDailyTrendChart"></canvas></div>
            </div>
            <div class="p-grid cols-2" style="margin-bottom:18px;align-items:start;">
                <div class="p-card"><h3 style="margin:0 0 12px;">${I18N['revai.overview.by_source']}</h3><div style="height:240px;"><canvas id="revaiSourceChart"></canvas></div></div>
                <div class="p-card no-pad"><div class="p-card-head" style="padding:16px 20px 0;"><h4>${I18N['revai.overview.by_source']}</h4></div>
                    <div class="p-table-scroll"><table class="p-table"><thead><tr><th>${I18N['revai.col.source']}</th><th>${I18N['revai.col.revenue']}</th><th>${I18N['revai.col.count']}</th></tr></thead>
                    <tbody>${d.revenue_by_source.map(s => `<tr><td>${esc(s.source)}</td><td>${fmt(s.total)}</td><td>${s.count}</td></tr>`).join('') || `<tr><td colspan="3" class="p-cell-muted">${I18N['common.no_records_yet']}</td></tr>`}</tbody></table></div>
                </div>
            </div>`;
        lineChart('revaiDailyTrendChart', d.daily_trend.map(t => t.date), [
            { label: I18N['revai.col.revenue'], data: d.daily_trend.map(t => t.revenue), borderColor: '#EFB05E', backgroundColor: 'rgba(239,176,94,0.12)', tension: 0.35, fill: true, pointRadius: 0 },
        ]);
        barChart('revaiSourceChart', d.revenue_by_source.map(s => s.source), [
            { label: I18N['revai.col.revenue'], data: d.revenue_by_source.map(s => s.total), backgroundColor: '#6366F1' },
        ]);
    }

    async function renderForecast() {
        panel.innerHTML = loadingHtml();
        const res = await fetchJSON('/api/revenue-intelligence/forecast?period=' + periodSelect.value);
        if (!res.success) { panel.innerHTML = emptyHtml(res.error); return; }
        const d = res.data;
        if (d.insufficient_data) { panel.innerHTML = emptyHtml(d.message); return; }
        panel.innerHTML = `
            <div class="p-grid cols-3" style="margin-bottom:18px;">
                <div class="p-card stat-tile"><div class="stat-icon blue">🔮</div><div class="stat-info"><div class="stat-value">${fmt(d.expected_revenue)}</div><div class="stat-label">${I18N['revai.forecast.expected']}</div></div></div>
                <div class="p-card stat-tile"><div class="stat-icon purple">📊</div><div class="stat-info"><div class="stat-value">${fmt(d.forecast_range.low)} - ${fmt(d.forecast_range.high)}</div><div class="stat-label">${I18N['revai.forecast.range']}</div></div></div>
                <div class="p-card stat-tile"><div class="stat-icon green">✅</div><div class="stat-info"><div class="stat-value">${esc(d.confidence)}</div><div class="stat-label">${I18N['revai.forecast.confidence']}</div></div></div>
            </div>
            <div class="p-card" style="margin-bottom:18px;">
                <h3 style="margin:0 0 4px;">${I18N['revai.chart.forecast_basis']}</h3>
                <p style="margin:0 0 12px;font-size:12px;opacity:.7;">${I18N['revai.chart.forecast_basis_hint']}</p>
                <div style="height:240px;"><canvas id="revaiForecastHistoryChart"></canvas></div>
            </div>
            <div class="p-card"><p>${esc(d.note || '')}</p><p style="font-size:13px;opacity:.7;">${I18N['revai.forecast.data_points']}: ${d.data_points_used} · ${I18N['revai.forecast.trend']}: ${esc(d.growth_trend)}</p></div>
            <div class="p-card" style="margin-top:18px;"><h3 style="margin:0 0 4px;">${I18N['revai.forecast.accuracy_title']}</h3>
                <p style="margin:0 0 12px;font-size:12px;opacity:.7;">${I18N['revai.forecast.accuracy_hint']}</p>
                <div id="revaiAccuracyBox">${loadingHtml()}</div>
            </div>`;
        const series = d.historical_series || [];
        lineChart('revaiForecastHistoryChart', series.map(p => p.date), [
            { label: I18N['revai.col.revenue'], data: series.map(p => p.revenue), borderColor: '#6366F1', backgroundColor: 'rgba(99,102,241,0.1)', tension: 0.3, fill: true, pointRadius: 0 },
        ]);

        const accBox = document.getElementById('revaiAccuracyBox');
        const accRes = await fetchJSON('/api/revenue-intelligence/forecast/accuracy');
        if (!accRes.success || !accRes.data.has_data) { accBox.innerHTML = `<div class="p-empty">${I18N['revai.forecast.no_accuracy_yet']}</div>`; return; }
        const hist = accRes.data.history;
        const avg = accRes.data.average_accuracy_percent;
        accBox.innerHTML = `
            ${avg !== null ? `<p style="margin:0 0 12px;font-weight:600;">${I18N['revai.forecast.average_accuracy']}: ${avg}%</p>` : ''}
            <div class="p-table-scroll"><table class="p-table">
                <thead><tr><th>${I18N['revai.forecast.period']}</th><th>${I18N['revai.forecast.expected']}</th><th>${I18N['revai.forecast.actual']}</th><th>${I18N['revai.forecast.accuracy']}</th><th>${I18N['revai.forecast.within_range']}</th></tr></thead>
                <tbody>${hist.map(h => `<tr>
                    <td>${h.period_start.substring(0,10)} → ${h.period_end.substring(0,10)}</td>
                    <td>${fmt(h.expected_revenue)}</td>
                    <td>${h.actual_revenue !== null ? fmt(h.actual_revenue) : '-'}</td>
                    <td>${h.accuracy_percent !== null ? h.accuracy_percent + '%' : '-'}</td>
                    <td>${h.within_range === null ? '-' : (h.within_range ? '✅' : '❌')}</td>
                </tr>`).join('')}</tbody>
            </table></div>`;
    }

    async function renderOpportunities() {
        panel.innerHTML = loadingHtml();
        const res = await fetchJSON('/api/revenue-intelligence/opportunities');
        if (!res.success) { panel.innerHTML = emptyHtml(res.error); return; }
        const items = res.data.opportunities || [];
        panel.innerHTML = items.length ? items.map(i => insightCard(i, 'opportunity')).join('') : emptyHtml(I18N['revai.no_opportunities']);
    }

    async function renderRisks() {
        panel.innerHTML = loadingHtml();
        const res = await fetchJSON('/api/revenue-intelligence/risks');
        if (!res.success) { panel.innerHTML = emptyHtml(res.error); return; }
        const items = res.data.risks || [];
        panel.innerHTML = items.length ? items.map(i => insightCard(i, 'risk')).join('') : emptyHtml(I18N['revai.no_risks']);
    }

    async function renderAnomalies() {
        panel.innerHTML = loadingHtml();
        const res = await fetchJSON('/api/revenue-intelligence/anomalies');
        if (!res.success) { panel.innerHTML = emptyHtml(res.error); return; }
        if (!res.data.has_data) { panel.innerHTML = emptyHtml(res.data.message); return; }
        const items = res.data.anomalies || [];
        panel.innerHTML = items.length ? items.map(a => insightCard({
            title: (a.type === 'sudden_drop' ? I18N['revai.anomaly.drop'] : I18N['revai.anomaly.increase']) + ' — ' + a.period,
            finding: `${I18N['revai.col.revenue']}: ${fmt(a.value)} (${I18N['revai.forecast.range']}: ${fmt(a.expected_range.low)} - ${fmt(a.expected_range.high)})`,
            reasoning_summary: a.reason,
            confidence: a.severity === 'high' ? 'high' : 'medium',
            severity: a.severity,
            estimated_impact: null,
            recommended_action: a.recommended_investigation,
        }, 'risk')).join('') : emptyHtml(I18N['revai.no_anomalies']);
    }

    let customersPage = 1;
    async function renderCustomers() {
        panel.innerHTML = loadingHtml();
        const res = await fetchJSON('/api/revenue-intelligence/customers?page=' + customersPage + '&per_page=25');
        if (!res.success) { panel.innerHTML = emptyHtml(res.error); return; }
        if (!res.data.has_data) { panel.innerHTML = emptyHtml(res.data.message); return; }
        const rows = res.data.customers || [];
        const pg = res.data.pagination || { page: 1, total_pages: 1, total: rows.length };
        panel.innerHTML = `<div class="p-card no-pad"><div class="p-table-scroll"><table class="p-table">
            <thead><tr><th>${I18N['revai.col.customer']}</th><th>${I18N['revai.col.revenue']}</th><th>${I18N['revai.col.aov']}</th><th>${I18N['revai.col.frequency']}</th><th>${I18N['revai.col.last_purchase']}</th><th>${I18N['revai.col.segment']}</th></tr></thead>
            <tbody>${rows.map(c => `<tr><td>${esc(c.name)}</td><td>${fmt(c.customer_revenue)}</td><td>${fmt(c.average_order_value)}</td><td>${c.purchase_frequency}</td><td>${c.last_purchase ? c.last_purchase.substring(0,10) : '-'}</td><td>${badge(c.value_segment, confColor.high)}</td></tr>`).join('')}</tbody>
        </table></div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;">
            <button class="p-btn" id="revaiCustPrev" ${pg.page <= 1 ? 'disabled' : ''}>&laquo;</button>
            <span style="font-size:13px;opacity:.8;">${I18N['revai.pagination.page']} ${pg.page} / ${pg.total_pages} (${pg.total})</span>
            <button class="p-btn" id="revaiCustNext" ${pg.page >= pg.total_pages ? 'disabled' : ''}>&raquo;</button>
        </div></div>`;
        const prevBtn = document.getElementById('revaiCustPrev');
        const nextBtn = document.getElementById('revaiCustNext');
        if (prevBtn) prevBtn.addEventListener('click', () => { customersPage = Math.max(1, customersPage - 1); renderCustomers(); });
        if (nextBtn) nextBtn.addEventListener('click', () => { customersPage = Math.min(pg.total_pages, customersPage + 1); renderCustomers(); });
    }

    async function renderPipeline() {
        panel.innerHTML = loadingHtml();
        const res = await fetchJSON('/api/revenue-intelligence/pipeline');
        if (!res.success) { panel.innerHTML = emptyHtml(res.error); return; }
        if (!res.data.has_data) { panel.innerHTML = emptyHtml(I18N['common.no_records_yet']); return; }
        const p = res.data.pipeline;
        panel.innerHTML = `
            <div class="p-grid cols-4" style="margin-bottom:18px;">
                <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">${fmt(p.pipeline_value)}</div><div class="stat-label">${I18N['revai.pipeline.value']}</div></div></div>
                <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">${fmt(p.weighted_pipeline)}</div><div class="stat-label">${I18N['revai.pipeline.weighted']}</div></div></div>
                <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">${p.open_deals_count}</div><div class="stat-label">${I18N['revai.pipeline.open_deals']}</div></div></div>
                <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">${p.pipeline_coverage !== null ? p.pipeline_coverage + 'x' : '-'}</div><div class="stat-label">${I18N['revai.pipeline.coverage']}</div></div></div>
            </div>
            <div class="p-grid cols-2">
                <div class="p-card"><h4>${I18N['revai.pipeline.likely_wins']}</h4>${p.likely_wins.length ? p.likely_wins.map(d => `<div style="padding:6px 0;border-bottom:1px solid var(--border,#eee);">${esc(d.title)} — ${fmt(d.value)} (${d.probability}%)</div>`).join('') : `<div class="p-empty">${I18N['common.no_records_yet']}</div>`}</div>
                <div class="p-card"><h4>${I18N['revai.pipeline.at_risk']}</h4>${p.at_risk_deals.length ? p.at_risk_deals.map(d => `<div style="padding:6px 0;border-bottom:1px solid var(--border,#eee);">${esc(d.title)} — ${fmt(d.value)} (${d.days_overdue}d ${I18N['revai.pipeline.overdue']})</div>`).join('') : `<div class="p-empty">${I18N['common.no_records_yet']}</div>`}</div>
            </div>`;
    }

    async function renderSources() {
        panel.innerHTML = loadingHtml();
        const res = await fetchJSON('/api/revenue-intelligence/sources?period=' + periodSelect.value);
        if (!res.success) { panel.innerHTML = emptyHtml(res.error); return; }
        if (!res.data.has_data) { panel.innerHTML = emptyHtml(I18N['revai.no_revenue_data']); return; }
        const rows = res.data.sources || [];
        panel.innerHTML = `
            <div class="p-card" style="margin-bottom:18px;"><h3 style="margin:0 0 12px;">${I18N['revai.overview.by_source']}</h3><div style="height:260px;"><canvas id="revaiSourcesGrowthChart"></canvas></div></div>
            <div class="p-card no-pad"><div class="p-table-scroll"><table class="p-table">
            <thead><tr><th>${I18N['revai.col.source']}</th><th>${I18N['revai.col.revenue']}</th><th>${I18N['revai.col.growth']}</th></tr></thead>
            <tbody>${rows.map(s => `<tr><td>${esc(s.source)}</td><td>${fmt(s.revenue)}</td><td>${pct(s.revenue_growth_percent)}</td></tr>`).join('')}</tbody>
        </table></div></div>`;
        barChart('revaiSourcesGrowthChart', rows.map(s => s.source), [
            { label: I18N['revai.col.revenue'], data: rows.map(s => s.revenue), backgroundColor: '#6366F1' },
        ]);
    }

    async function renderRetention() {
        panel.innerHTML = loadingHtml();
        const res = await fetchJSON('/api/revenue-intelligence/retention');
        if (!res.success) { panel.innerHTML = emptyHtml(res.error); return; }
        const d = res.data;
        if (!d.has_data) { panel.innerHTML = emptyHtml(I18N['revai.no_revenue_data']); return; }
        const rp = d.repeat_purchase_rate || {};
        const rs = d.recurring_stability || {};
        panel.innerHTML = `
            <div class="p-grid cols-3" style="margin-bottom:18px;">
                <div class="p-card stat-tile"><div class="stat-icon purple">🔁</div><div class="stat-info"><div class="stat-value">${rp.has_data ? rp.repeat_purchase_rate_percent + '%' : '-'}</div><div class="stat-label">${I18N['revai.retention.repeat_purchase']}</div></div></div>
                <div class="p-card stat-tile"><div class="stat-icon green">🔄</div><div class="stat-info"><div class="stat-value">${rs.has_data ? fmt(rs.average_monthly_recurring) : '-'}</div><div class="stat-label">${I18N['revai.retention.avg_recurring']}</div></div></div>
                <div class="p-card stat-tile"><div class="stat-icon orange">📉</div><div class="stat-info"><div class="stat-value">${rs.has_data ? rs.monthly_gaps_detected : '-'}</div><div class="stat-label">${I18N['revai.retention.monthly_gaps']}</div></div></div>
            </div>
            <div class="p-card" style="margin-bottom:18px;">
                <h4>${I18N['revai.retention.cohort_retention']}</h4>
                <p style="font-size:13px;opacity:.8;">${I18N['revai.retention.cohort_hint']}</p>
                <div class="p-table-scroll"><table class="p-table"><thead><tr><th>${I18N['revai.retention.cohort_month']}</th><th>${I18N['revai.retention.customers']}</th>
                ${Array.from({length:6}, (_,i)=>`<th>+${i+1}${I18N['revai.retention.month']}</th>`).join('')}
                </tr></thead><tbody>
                ${(d.cohort_retention.cohorts || []).map(c => `<tr><td>${esc(c.cohort_month)}</td><td>${c.customers}</td>${Array.from({length:6},(_,i)=>`<td>${c.retention_rates[i+1] !== undefined ? c.retention_rates[i+1] + '%' : '-'}</td>`).join('')}</tr>`).join('') || `<tr><td colspan="8" class="p-cell-muted">${I18N['common.no_records_yet']}</td></tr>`}
                </tbody></table></div>
            </div>
            <div class="p-card"><h4>${I18N['revai.retention.recurring_stability']}</h4>
                ${(rs.months || []).map(m => `<span style="display:inline-block;margin:4px;padding:4px 10px;border-radius:16px;background:#F3F4F6;font-size:12px;">${esc(m.month)} — ${fmt(m.total)}</span>`).join('') || `<div class="p-empty">${I18N['common.no_records_yet']}</div>`}
                ${rs.note ? `<p style="margin:10px 0 0;font-size:13px;opacity:.8;">${esc(rs.note)}</p>` : ''}
            </div>
            <div class="p-card" style="margin-top:14px;font-size:13px;opacity:.85;">${esc(d.mrr_grr_note || '')}</div>`;
    }

    async function renderSubscriptions() {
        panel.innerHTML = loadingHtml();
        const [res, stripeRes] = await Promise.all([
            fetchJSON('/api/revenue-intelligence/subscriptions'),
            fetchJSON('/api/revenue-intelligence/stripe/settings')
        ]);
        const d = res.success ? res.data : { has_data: false, reason: res.error };
        const s = stripeRes.success ? stripeRes.data : {};
        const nrr = d.nrr || {}, grr = d.grr || {}, brk = d.breakdown || {};
        panel.innerHTML = `
            <div class="p-card" style="margin-bottom:18px;border:1px dashed var(--border,#ccc);">
                <h4 style="margin:0 0 8px;">${I18N['revai.stripe.title']}</h4>
                <p style="font-size:13px;opacity:.8;margin:0 0 12px;">${I18N['revai.stripe.hint']}</p>
                ${s.has_settings && s.has_webhook_secret ? `<p style="font-size:13px;margin:0 0 10px;">${s.is_enabled ? badge('● ' + I18N['revai.stripe.enable'], '#22C55E') : badge(I18N['revai.stripe.mode_test'] + '/' + I18N['revai.stripe.mode_live'], '#F59E0B')} <span style="opacity:.7;">(${esc(s.mode)})</span>${s.last_event_at ? ` · ${I18N['revai.stripe.last_event']}: ${esc(s.last_event_type || '')} @ ${esc(s.last_event_at)}` : ''}</p>` : ''}
                <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:end;">
                    <div style="flex:2;min-width:220px;"><label class="form-label">${I18N['revai.stripe.webhook_secret']}</label>
                        <input type="password" id="revaiStripeSecret" class="p-input" style="width:100%;" placeholder="whsec_...">
                    </div>
                    <div style="flex:1;min-width:140px;"><label class="form-label">${I18N['revai.stripe.account_id']}</label>
                        <input type="text" id="revaiStripeAccount" class="p-input" style="width:100%;" value="${esc(s.connected_account_id || '')}">
                    </div>
                    <div><label class="form-label">${I18N['revai.stripe.mode']}</label>
                        <select id="revaiStripeMode" class="p-select">
                            <option value="test" ${(s.mode || 'test') === 'test' ? 'selected' : ''}>${I18N['revai.stripe.mode_test']}</option>
                            <option value="live" ${s.mode === 'live' ? 'selected' : ''}>${I18N['revai.stripe.mode_live']}</option>
                        </select>
                    </div>
                    <div><label class="form-label">&nbsp;</label><button class="p-btn primary" id="revaiStripeSave" style="width:100%;">${I18N['revai.stripe.connect']}</button></div>
                </div>
                ${s.webhook_url ? `<div style="margin-top:12px;"><label class="form-label">${I18N['revai.stripe.webhook_url']}</label>
                    <code style="display:block;padding:8px 10px;background:#F3F4F6;border-radius:8px;font-size:12px;word-break:break-all;direction:ltr;text-align:left;">${esc(s.webhook_url)}</code></div>` : ''}
                <p style="font-size:12px;opacity:.65;margin-top:10px;">${I18N['revai.stripe.disclosure']}</p>
            </div>
            ${d.has_data ? `
            <div class="p-grid cols-4" style="margin-bottom:18px;">
                <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">${fmt(d.mrr)}</div><div class="stat-label">MRR</div></div></div>
                <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">${fmt(d.arr)}</div><div class="stat-label">ARR</div></div></div>
                <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">${d.active_subscriptions}</div><div class="stat-label">${I18N['revai.subscriptions.active']}</div></div></div>
                <div class="p-card stat-tile"><div class="stat-info"><div class="stat-value">${nrr.has_data ? nrr.nrr_percent + '%' : '-'}</div><div class="stat-label">NRR</div></div></div>
            </div>
            <div class="p-grid cols-2">
                <div class="p-card"><h4>${I18N['revai.subscriptions.grr']}</h4>
                    ${grr.has_data ? `<p style="font-size:26px;font-weight:700;">${grr.grr_percent}%</p><p style="font-size:13px;opacity:.8;">${esc(grr.note || '')}</p>` : `<div class="p-empty">${I18N['common.no_records_yet']}</div>`}
                </div>
                <div class="p-card"><h4>${I18N['revai.subscriptions.mrr_breakdown']}</h4>
                    ${brk.has_data ? `<p>${I18N['revai.subscriptions.new']}: ${fmt(brk.new)}</p><p>${I18N['revai.subscriptions.expansion']}: ${fmt(brk.expansion)}</p><p>${I18N['revai.subscriptions.contraction']}: ${fmt(brk.contraction)}</p><p>${I18N['revai.subscriptions.churn']}: ${fmt(brk.churn)}</p><p style="font-weight:600;margin-top:8px;">${I18N['revai.subscriptions.net']}: ${fmt(brk.net)}</p>` : `<div class="p-empty">${I18N['common.no_records_yet']}</div>`}
                </div>
            </div>
            ${d.by_cycle && d.by_cycle.has_data ? `<div class="p-card" style="margin-top:14px;"><h4>${I18N['revai.subscriptions.mrr_by_cycle']}</h4>
                ${Object.entries(d.by_cycle.mrr_by_cycle || {}).map(([k,v]) => `<span style="display:inline-block;margin:4px;padding:4px 10px;border-radius:16px;background:#F3F4F6;font-size:12px;">${esc(k)} — ${fmt(v)}</span>`).join('')}</div>` : ''}
            ` : `<div class="p-card"><div class="p-empty">${I18N['revai.stripe.not_configured']} ${esc(d.reason || '')}</div></div>`}`;

        const saveBtn = document.getElementById('revaiStripeSave');
        if (saveBtn) {
            saveBtn.addEventListener('click', async () => {
                const body = {
                    webhook_secret: document.getElementById('revaiStripeSecret').value.trim(),
                    connected_account_id: document.getElementById('revaiStripeAccount').value.trim(),
                    mode: document.getElementById('revaiStripeMode').value,
                    is_enabled: true
                };
                const saved = await fetchJSON('/api/revenue-intelligence/stripe/settings', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
                });
                if (saved.success) { toast(I18N['revai.stripe.saved'], 'success'); renderSubscriptions(); } else { toast(saved.error, 'error'); }
            });
        }
    }

    async function renderAttribution() {
        panel.innerHTML = loadingHtml();
        const res = await fetchJSON('/api/revenue-intelligence/attribution');
        if (!res.success) { panel.innerHTML = emptyHtml(res.error); return; }
        const d = res.data;
        if (!d.by_rep || !d.by_rep.has_data) { panel.innerHTML = emptyHtml(I18N['revai.no_revenue_data']); return; }
        panel.innerHTML = `
            <div class="p-card" style="margin-bottom:18px;"><h4>${I18N['revai.attribution.by_rep']}</h4>
            <div class="p-table-scroll"><table class="p-table"><thead><tr><th>${I18N['revai.attribution.rep']}</th><th>${I18N['revai.attribution.team']}</th><th>${I18N['revai.attribution.open_weighted']}</th><th>${I18N['revai.attribution.won']}</th><th>#</th></tr></thead>
            <tbody>${(d.by_rep.reps || []).map(r => `<tr><td>${esc(r.rep_name)}</td><td>${esc(r.team_name || '-')}</td><td>${fmt(r.open_weighted)}</td><td>${fmt(r.won_value)}</td><td>${r.open_count + r.won_count}</td></tr>`).join('')}</tbody></table></div></div>
            <div class="p-card"><h4>${I18N['revai.attribution.by_team']}</h4>
            <div class="p-table-scroll"><table class="p-table"><thead><tr><th>${I18N['revai.attribution.team']}</th><th>${I18N['revai.attribution.reps']}</th><th>${I18N['revai.attribution.open_weighted']}</th><th>${I18N['revai.attribution.won']}</th></tr></thead>
            <tbody>${(d.by_team.teams || []).map(t => `<tr><td>${esc(t.team_name)}</td><td>${t.reps}</td><td>${fmt(t.open_weighted)}</td><td>${fmt(t.won_value)}</td></tr>`).join('')}</tbody></table></div></div>`;
    }

    async function renderBenchmarks() {
        panel.innerHTML = loadingHtml();
        const res = await fetchJSON('/api/revenue-intelligence/benchmarks');
        if (!res.success) { panel.innerHTML = emptyHtml(res.error); return; }
        const d = res.data;
        if (!d.has_data) { panel.innerHTML = emptyHtml(d.reason || I18N['revai.no_revenue_data']); return; }
        panel.innerHTML = `
            <div class="p-card"><h4>${I18N['revai.benchmarks.title']}</h4>
            <p style="font-size:13px;opacity:.8;">${I18N['revai.benchmarks.hint']} (${esc(d.source)}${d.rows && d.rows[0] ? ' · ' + esc(d.rows[0].as_of_date) : ''})</p>
            <div class="p-table-scroll"><table class="p-table"><thead><tr><th>${I18N['revai.benchmarks.metric']}</th><th>P25</th><th>P50</th><th>P75</th><th>n</th></tr></thead>
            <tbody>${(d.rows || []).map(r => `<tr><td>${esc(r.metric_label)}</td><td>${r.p25 !== null ? r.p25 : '-'}</td><td>${r.p50 !== null ? r.p50 : '-'}</td><td>${r.p75 !== null ? r.p75 : '-'}</td><td>${r.sample_size}</td></tr>`).join('')}</tbody></table></div></div>`;
    }

    async function renderChurn() {
        panel.innerHTML = loadingHtml();
        const res = await fetchJSON('/api/revenue-intelligence/churn');
        if (!res.success) { panel.innerHTML = emptyHtml(res.error); return; }
        const d = res.data;
        if (!d.has_data) { panel.innerHTML = emptyHtml(d.reason || I18N['revai.no_revenue_data']); return; }
        panel.innerHTML = `
            <div class="p-card" style="margin-bottom:18px;"><h4>${I18N['revai.churn.title']} (${d.total_churned})</h4>
            ${d.top_reason ? `<p style="font-size:14px;"><b>${I18N['revai.churn.top_reason']}:</b> ${esc(d.top_reason)}</p>` : ''}
            <div class="p-table-scroll"><table class="p-table"><thead><tr><th>${I18N['revai.churn.reason']}</th><th>${I18N['revai.churn.count']}</th><th>${I18N['revai.churn.confidence']}</th></tr></thead>
            <tbody>${(d.by_reason || []).map(r => `<tr><td>${esc(r.label)}</td><td>${r.count}</td><td>${esc(r.confidence)}</td></tr>`).join('')}</tbody></table></div>
            ${d.note ? `<p style="margin-top:12px;font-size:13px;opacity:.8;">${esc(d.note)}</p>` : ''}</div>`;
    }

    async function renderQuotas() {
        panel.innerHTML = loadingHtml();
        const res = await fetchJSON('/api/revenue-intelligence/quotas');
        if (!res.success) { panel.innerHTML = emptyHtml(res.error); return; }
        const d = res.data;
        if (!d.has_data) { panel.innerHTML = emptyHtml(d.message || d.reason || I18N['revai.no_revenue_data']); return; }
        const statusColor = { ahead: '#22C55E', on_track: '#3FA796', at_risk: '#F59E0B', behind: '#EF4444' };
        panel.innerHTML = `
            <div class="p-card"><h4>${I18N['revai.quota.title']}</h4>
            <p style="font-size:13px;opacity:.8;">${I18N['revai.quota.hint']}</p>
            <div class="p-table-scroll"><table class="p-table"><thead><tr>
                <th>${I18N['revai.quota.period']}</th><th>${I18N['revai.quota.target']}</th>
                <th>${I18N['revai.quota.achieved']}</th><th>${I18N['revai.quota.progress']}</th>
                <th>${I18N['revai.quota.forecast']}</th><th>${I18N['revai.quota.projected']}</th>
                <th>${I18N['revai.quota.gap']}</th><th>${I18N['revai.quota.status']}</th>
            </tr></thead><tbody>
            ${(d.quotas || []).map(q => `
                <tr>
                    <td><b>${esc(q.period)}</b></td>
                    <td>${fmt(q.target_value)}</td>
                    <td>${fmt(q.achieved_value)}</td>
                    <td>${q.progress_percent !== null ? pct(q.progress_percent) : '-'}</td>
                    <td>${fmt(q.forecast_value)} <span style="opacity:.6;font-size:11px;">(${q.open_deal_count})</span></td>
                    <td>${q.projected_progress_percent !== null ? pct(q.projected_progress_percent) : '-'}</td>
                    <td>${q.gap_to_target < 0 ? fmt(0) : fmt(q.gap_to_target)}</td>
                    <td>${badge(q.status, statusColor[q.status] || '#888')}</td>
                </tr>`).join('') || `<tr><td colspan="8" class="p-cell-muted">${I18N['common.no_records_yet']}</td></tr>`}
            </tbody></table></div>
            ${d.note ? `<p style="margin-top:10px;font-size:12px;opacity:.7;">${esc(d.note)}</p>` : ''}</div>`;
    }

    async function renderAssistant() {
        panel.innerHTML = `
            <div class="p-card">
                <div style="display:flex;gap:8px;">
                    <input type="text" id="revaiQuestion" class="p-input" placeholder="${I18N['revai.assistant.placeholder']}" style="flex:1;">
                    <button class="p-btn primary" id="revaiAskBtn">${I18N['revai.assistant.ask']}</button>
                </div>
                <div id="revaiAnswers" style="margin-top:16px;"></div>
            </div>`;
        const input = document.getElementById('revaiQuestion');
        const btn = document.getElementById('revaiAskBtn');
        const answers = document.getElementById('revaiAnswers');

        async function ask() {
            const q = input.value.trim();
            if (!q) return;
            btn.disabled = true;
            const res = await fetchJSON('/api/revenue-intelligence/assistant/ask', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ question: q })
            });
            btn.disabled = false;
            if (!res.success) { toast(res.error || I18N['common.load_failed'], 'error'); return; }
            const d = res.data;
            const block = document.createElement('div');
            block.className = 'p-card';
            block.style.marginBottom = '10px';
            let followUpsHtml = '';
            if (d.follow_up_questions && d.follow_up_questions.length) {
                followUpsHtml = '<div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:6px;">' + d.follow_up_questions.map(function (fq) {
                    return '<button type="button" class="p-btn" data-followup="' + esc(fq) + '" style="font-size:12px;padding:4px 10px;">' + esc(fq) + '</button>';
                }).join('') + '</div>';
            }
            block.innerHTML = `<p style="font-weight:600;">${esc(q)}</p><p>${esc(d.finding)}</p>${d.recommended_action ? `<p style="font-size:13px;opacity:.8;"><b>${I18N['revai.recommended_action']}:</b> ${esc(d.recommended_action)}</p>` : ''}${followUpsHtml}`;
            answers.prepend(block);
            block.querySelectorAll('[data-followup]').forEach(function (btn) {
                btn.addEventListener('click', function () { input.value = btn.getAttribute('data-followup'); ask(); });
            });
            input.value = '';
        }
        btn.addEventListener('click', ask);
        input.addEventListener('keydown', e => { if (e.key === 'Enter') ask(); });
    }

    function renderReports() {
        panel.innerHTML = `
            <div class="p-card">
                <div class="p-grid cols-4" style="align-items:end;">
                    <div><label class="form-label">${I18N['revai.reports.type']}</label>
                        <select id="revaiReportType" class="p-select">
                            <option value="overview">${I18N['revai.tab.overview']}</option>
                            <option value="opportunities">${I18N['revai.tab.opportunities']}</option>
                            <option value="risks">${I18N['revai.tab.risks']}</option>
                            <option value="customers">${I18N['revai.tab.customers']}</option>
                            <option value="pipeline_forecast">${I18N['revai.tab.pipeline']}</option>
                        </select>
                    </div>
                    <div><label class="form-label">${I18N['revai.reports.from']}</label><input type="date" id="revaiReportFrom" class="p-input"></div>
                    <div><label class="form-label">${I18N['revai.reports.to']}</label><input type="date" id="revaiReportTo" class="p-input"></div>
                    <div><button class="p-btn primary" id="revaiExportCsvBtn" style="width:100%;">${I18N['revai.reports.download_csv']}</button></div>
                </div>
                <p style="font-size:12px;opacity:.7;margin-top:8px;">${I18N['revai.reports.date_range_hint']}</p>
                <div id="revaiReportPreview" style="margin-top:16px;"></div>
            </div>`;

        const typeSel = document.getElementById('revaiReportType');
        const fromInput = document.getElementById('revaiReportFrom');
        const toInput = document.getElementById('revaiReportTo');
        const preview = document.getElementById('revaiReportPreview');

        function buildQuery(extra) {
            const q = new URLSearchParams({ type: typeSel.value, period: periodSelect.value, ...extra });
            if (fromInput.value) q.set('from', fromInput.value);
            if (toInput.value) q.set('to', toInput.value);
            return q.toString();
        }

        async function loadPreview() {
            preview.innerHTML = loadingHtml();
            const res = await fetchJSON('/api/revenue-intelligence/reports/export?' + buildQuery({ format: 'json' }));
            if (!res.success) { preview.innerHTML = emptyHtml(res.error); return; }
            const rows = res.data.rows || [];
            if (!rows.length) { preview.innerHTML = emptyHtml(res.data.message || I18N['common.no_records_yet']); return; }
            const cols = Object.keys(rows[0]);
            preview.innerHTML = `<div class="p-table-scroll"><table class="p-table"><thead><tr>${cols.map(c => `<th>${esc(c)}</th>`).join('')}</tr></thead>
                <tbody>${rows.slice(0, 50).map(r => `<tr>${cols.map(c => `<td>${esc(String(r[c] ?? ''))}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
        }

        typeSel.addEventListener('change', loadPreview);
        fromInput.addEventListener('change', loadPreview);
        toInput.addEventListener('change', loadPreview);
        document.getElementById('revaiExportCsvBtn').addEventListener('click', () => {
            window.location.href = '/api/revenue-intelligence/reports/export?' + buildQuery({ format: 'csv' });
        });

        loadPreview();
    }

    const renderers = {
        executive: renderExecutive, overview: renderOverview, forecast: renderForecast,
        opportunities: renderOpportunities, risks: renderRisks, customers: renderCustomers,
        pipeline: renderPipeline, sources: renderSources, anomalies: renderAnomalies, assistant: renderAssistant,
        retention: renderRetention,
        subscriptions: renderSubscriptions,
        attribution: renderAttribution,
        benchmarks: renderBenchmarks,
        quotas: renderQuotas,
        churn: renderChurn,
        reports: renderReports,
        season: function seasonTab(tries) {
            // سكريبت رحلات الموسم بيتحمّل defer: نستناه شوية لو التبويب اتفتح على طول
            if (window.TFSeasonPosts) return window.TFSeasonPosts.render(panel);
            panel.innerHTML = '<div class="p-empty">…</div>';
            if ((tries || 0) < 50) setTimeout(() => { if (activeTab === 'season') seasonTab((tries || 0) + 1); }, 100);
        },
    };

    function activate(tab) {
        activeTab = tab;
        document.querySelectorAll('#revaiTabs .p-tab').forEach(el => el.classList.toggle('active', el.dataset.tab === tab));
        (renderers[tab] || renderOverview)();
    }

    document.querySelectorAll('#revaiTabs .p-tab').forEach(el => {
        el.addEventListener('click', e => { e.preventDefault(); activate(el.dataset.tab); });
    });
    periodSelect.addEventListener('change', () => activate(activeTab));

    activate(location.hash === '#season' ? 'season' : 'executive');
})();
JS;
    }
}
